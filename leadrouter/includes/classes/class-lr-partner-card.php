<?php
/**
 * LR_Partner_Card — прив'язка/перев'язка платіжної картки партнером через Stripe Checkout
 * у режимі setup (off_session). Автосписання НЕ змінюємо: воно лишається off-session
 * по балансу (поріг min_balance, крон) і використовує збережені customer/pm.
 *
 * Флоу:
 *   1) Кнопка в кабінеті → handle_setup(): створює (за потреби) Stripe Customer,
 *      Checkout Session mode=setup і редіректить партнера на хостед-сторінку Stripe.
 *   2) Партнер вводить картку на Stripe і повертається на success_url → handle_return():
 *      ретрив сесії, перевірка власника (metadata.partner_id == поточний партнер),
 *      збереження customer/pm/brand/last4 у білінг-рядок.
 *   3) Підстраховка — вебхук checkout.session.completed / setup_intent.succeeded
 *      (LR_Stripe_Webhook), що викликає save_from_object().
 *
 * Безпека: partner_id — лише із сесії (LR_Partner_Auth::current_partner_id());
 * номери карток у наш код/БД не потрапляють — лише brand/last4 для показу.
 * Stripe customer_id / payment_method_id у кабінеті НЕ показуємо.
 *
 * Час — EST.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Partner_Card')) {

    class LR_Partner_Card
    {
        /** Nonce-action кнопки прив'язки/зміни картки */
        const NONCE_SETUP = 'lr_partner_card_setup';

        /** Часова зона проекту */
        private const TZ = 'America/New_York';

        public static function register(): void
        {
            add_action('admin_post_lr_partner_card_setup',  [__CLASS__, 'handle_setup']);
            add_action('admin_post_lr_partner_card_return', [__CLASS__, 'handle_return']);
        }

        /* ============================================================
         * 1) Старт прив'язки: створення Checkout Session і редірект на Stripe
         * ============================================================ */
        public static function handle_setup(): void
        {
            check_admin_referer(self::NONCE_SETUP);

            $partner_id = LR_Partner_Auth::current_partner_id();
            if ($partner_id <= 0) {
                self::redirect_card('badlogin');
            }

            // Customer: наявний або новий
            $customer_id = self::ensure_customer($partner_id);
            if ($customer_id === '') {
                self::redirect_card('card_error');
            }

            // success_url веде на наш return-ендпоінт; {CHECKOUT_SESSION_ID} підставляє Stripe
            $success_url = add_query_arg('action', 'lr_partner_card_return', admin_url('admin-post.php'))
                         . '&session_id={CHECKOUT_SESSION_ID}';
            $cancel_url  = add_query_arg(['section' => 'card', 'lr_card' => 'cancel'], LR_Partner_Auth::cabinet_url());

            $res = LR_Stripe::create_setup_checkout_session($partner_id, $customer_id, $success_url, $cancel_url);
            if (!empty($res['url'])) {
                // Зовнішній редірект на Stripe (не wp_safe_redirect — інший хост)
                wp_redirect($res['url']);
                exit;
            }

            self::redirect_card('card_error');
        }

        /* ============================================================
         * 2) Повернення зі Stripe: ретрив сесії + збереження картки
         * ============================================================ */
        public static function handle_return(): void
        {
            $partner_id = LR_Partner_Auth::current_partner_id();
            if ($partner_id <= 0) {
                self::redirect_card('badlogin');
            }

            $session_id = isset($_GET['session_id']) ? sanitize_text_field(wp_unslash($_GET['session_id'])) : '';
            if ($session_id === '') {
                self::redirect_card('card_error');
            }

            $session = LR_Stripe::retrieve_checkout_session($session_id);
            if (empty($session) || empty($session['id'])) {
                self::redirect_card('card_error');
            }

            // ВЛАСНІСТЬ: сесія має належати саме поточному партнеру
            $sess_pid = (int) ($session['metadata']['partner_id'] ?? 0);
            if ($sess_pid !== $partner_id) {
                LR_Billing::log_error($partner_id, 'CARD_SESSION_OWNER_MISMATCH', 'warning',
                    'Checkout session не належить поточному партнеру',
                    ['session' => $session_id, 'session_partner' => $sess_pid, 'source' => 'partner_card']);
                self::redirect_card('card_error');
            }

            $saved = self::save_from_object($partner_id, $session);
            self::redirect_card($saved ? 'linked' : 'card_error');
        }

        /* ============================================================
         * Спільне збереження картки з об'єкта (Checkout Session або SetupIntent)
         * ============================================================ */
        /**
         * Дістає customer + payment_method (+ card.brand/last4) з об'єкта Stripe і
         * зберігає у білінг-рядок партнера. Викликається і з handle_return, і з вебхука.
         *
         * @param array $obj checkout.session (з розгорнутим setup_intent.payment_method)
         *                   або setup_intent (можливо з payment_method)
         * @return bool
         */
        public static function save_from_object(int $partner_id, array $obj): bool
        {
            if ($partner_id <= 0) {
                return false;
            }

            // customer
            $customer_id = self::scalar_id($obj['customer'] ?? '');

            // setup_intent: у checkout.session це обʼєкт/рядок; інакше сам $obj — setup_intent
            $si = $obj['setup_intent'] ?? null;
            if (!is_array($si)) {
                $si = (($obj['object'] ?? '') === 'setup_intent') ? $obj : [];
            }
            if ($customer_id === '' && is_array($si)) {
                $customer_id = self::scalar_id($si['customer'] ?? '');
            }

            // payment_method (обʼєкт або id)
            $pm = $si['payment_method'] ?? ($obj['payment_method'] ?? null);
            $pm_id = self::scalar_id($pm);

            $brand = '';
            $last4 = '';
            if (is_array($pm) && !empty($pm['card'])) {
                $brand = (string) ($pm['card']['brand'] ?? '');
                $last4 = (string) ($pm['card']['last4'] ?? '');
            }

            if ($pm_id === '') {
                return false;
            }

            // brand/last4 не прийшли в обʼєкті — дотягуємо за pm id
            if ($brand === '' || $last4 === '') {
                $card = LR_Stripe::get_payment_method($pm_id);
                if ($card) {
                    $brand = $brand !== '' ? $brand : (string) ($card['brand'] ?? '');
                    $last4 = $last4 !== '' ? $last4 : (string) ($card['last4'] ?? '');
                }
            }

            return self::save_card($partner_id, $customer_id, $pm_id, $brand, $last4);
        }

        /**
         * Записати картку у білінг-рядок. Старий pm (якщо інший) — best-effort detach.
         */
        public static function save_card(int $partner_id, string $customer_id, string $pm_id, string $brand, string $last4): bool
        {
            global $wpdb;
            $t = $wpdb->prefix . 'leadrouter_partner_billing';

            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT id, stripe_payment_method_id FROM {$t} WHERE partner_id = %d LIMIT 1", $partner_id),
                ARRAY_A
            );
            if (!$row) {
                LR_Billing::log_error($partner_id, 'CARD_NO_BILLING_PROFILE', 'error',
                    'Збереження картки для партнера без білінг-профілю', ['source' => 'partner_card']);
                return false;
            }

            $old_pm = trim((string) ($row['stripe_payment_method_id'] ?? ''));

            $data    = ['stripe_payment_method_id' => $pm_id, 'updated_at' => self::now()];
            $formats = ['%s', '%s'];
            if ($customer_id !== '') { $data['stripe_customer_id'] = $customer_id; $formats[] = '%s'; }
            if ($brand !== '')       { $data['stripe_card_brand']  = substr($brand, 0, 20); $formats[] = '%s'; }
            if ($last4 !== '')       { $data['stripe_card_last4']  = substr($last4, 0, 4);  $formats[] = '%s'; }

            $ok = $wpdb->update($t, $data, ['id' => (int) $row['id']], $formats, ['%d']);

            if ($ok === false) {
                LR_Billing::log_error($partner_id, 'CARD_SAVE_FAILED', 'error',
                    'Не вдалося зберегти картку партнера', ['db_error' => $wpdb->last_error, 'source' => 'partner_card']);
                return false;
            }

            // Перев'язка: старий pm більше не потрібен → відв'язуємо (не критично, ігноруємо невдачу)
            if ($old_pm !== '' && $old_pm !== $pm_id) {
                LR_Stripe::detach_payment_method($old_pm);
            }

            LR_Billing::log_audit([
                'partner_id'   => $partner_id,
                'actor_type'   => 'partner',
                'actor_id'     => get_current_user_id(),
                'action'       => 'card_linked',
                'entity_type'  => 'partner_billing',
                'entity_id'    => (int) $row['id'],
                // У контекст пишемо лише brand/last4 — без повних id/номерів
                'context_json' => ['brand' => $brand, 'last4' => $last4, 'changed' => ($old_pm !== '' && $old_pm !== $pm_id)],
            ]);

            return true;
        }

        /* ============================================================
         * Lazy-fetch brand/last4 для наявних карток (без збережених деталей)
         * ============================================================ */
        /**
         * Якщо у партнера є pm_id, але brand/last4 ще не збережені — підтягнути зі Stripe
         * і закешувати в БД. Повертає ['brand'=>..,'last4'=>..] (можливо порожні).
         */
        public static function ensure_card_details(int $partner_id, array $row): array
        {
            $pm_id = trim((string) ($row['stripe_payment_method_id'] ?? ''));
            $brand = (string) ($row['stripe_card_brand'] ?? '');
            $last4 = (string) ($row['stripe_card_last4'] ?? '');

            if ($pm_id === '' || ($brand !== '' && $last4 !== '')) {
                return ['brand' => $brand, 'last4' => $last4];
            }

            // Анти-хаммер: якщо нещодавно вже пробували й не вийшло — не бити Stripe
            // на кожному рендері (напр. pm видалено/недійсний). Кеш на 10 хв.
            $guard = 'lr_card_fetch_' . $partner_id;
            if (get_transient($guard)) {
                return ['brand' => $brand, 'last4' => $last4];
            }

            $card = LR_Stripe::get_payment_method($pm_id);
            if ($card && (!empty($card['brand']) || !empty($card['last4']))) {
                global $wpdb;
                $t = $wpdb->prefix . 'leadrouter_partner_billing';
                $wpdb->update(
                    $t,
                    ['stripe_card_brand' => substr((string) $card['brand'], 0, 20),
                     'stripe_card_last4' => substr((string) $card['last4'], 0, 4),
                     'updated_at' => self::now()],
                    ['partner_id' => $partner_id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                return ['brand' => (string) $card['brand'], 'last4' => (string) $card['last4']];
            }

            // Не вийшло — притримуємо повторні спроби на 10 хв
            set_transient($guard, 1, 600);
            return ['brand' => $brand, 'last4' => $last4];
        }

        /* ============================================================
         * Хелпери
         * ============================================================ */

        /** Customer партнера: наявний або новий (зберігається в білінг-рядок). */
        private static function ensure_customer(int $partner_id): string
        {
            global $wpdb;
            $t = $wpdb->prefix . 'leadrouter_partner_billing';

            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT id, stripe_customer_id, email FROM {$t} WHERE partner_id = %d LIMIT 1", $partner_id),
                ARRAY_A
            );

            $existing = $row ? trim((string) ($row['stripe_customer_id'] ?? '')) : '';
            if ($existing !== '') {
                return $existing;
            }

            // email для customer: білінг-email або контактний email партнера
            $email = $row ? trim((string) ($row['email'] ?? '')) : '';
            if ($email === '') {
                $email = LR_Partner_Auth::partner_contact_email($partner_id);
            }

            $res = LR_Stripe::create_customer($partner_id, $email);
            if (empty($res['id'])) {
                return '';
            }
            $customer_id = (string) $res['id'];

            // Зберігаємо одразу (створюємо рядок, якщо його ще не було)
            if ($row) {
                $wpdb->update($t, ['stripe_customer_id' => $customer_id, 'updated_at' => self::now()],
                    ['id' => (int) $row['id']], ['%s', '%s'], ['%d']);
            } else {
                $wpdb->insert($t, [
                    'partner_id' => $partner_id, 'balance' => 0.00, 'currency' => 'USD',
                    'status' => 'active', 'stripe_customer_id' => $customer_id, 'created_at' => self::now(),
                ], ['%d', '%f', '%s', '%s', '%s', '%s']);
            }

            return $customer_id;
        }

        /** Витягти скалярний id з рядка або обʼєкта Stripe ({id:...}). */
        private static function scalar_id($v): string
        {
            if (is_string($v)) {
                return trim($v);
            }
            if (is_array($v) && !empty($v['id']) && is_string($v['id'])) {
                return trim($v['id']);
            }
            return '';
        }

        /** Редірект у секцію картки кабінету з кодом повідомлення */
        private static function redirect_card(string $code): void
        {
            $url = add_query_arg(['section' => 'card', 'lr_card' => $code], LR_Partner_Auth::cabinet_url());
            wp_safe_redirect($url . '#lr-tab-card');
            exit;
        }

        /** Поточний час у EST */
        private static function now(): string
        {
            return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d H:i:s');
        }
    }
}
