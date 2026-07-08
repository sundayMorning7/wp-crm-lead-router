<?php
/**
 * LR_Stripe_Webhook — приймач Stripe-вебхуків через WordPress REST API.
 *
 * Маршрут:  POST /wp-json/leadrouter/v1/stripe-webhook
 *
 * Захист — ВИКЛЮЧНО через перевірку підпису Stripe (LR_Stripe::verify_webhook).
 * НЕ використовуємо WP nonce: Stripe про нього не знає. permission_callback = __return_true,
 * а легітимність запиту підтверджує заголовок Stripe-Signature.
 *
 * Idempotency: кожну подію (evt_...) фіксуємо в leadrouter_stripe_events з
 * UNIQUE(stripe_event_id). Повторний вебхук тієї ж події (Stripe ретраїть до 3 діб)
 * не обробляється вдруге — повертаємо 200 і виходимо.
 *
 * Оброблювані події:
 *   - payment_intent.succeeded      → нарахування балансу (зокрема пізнє 3DS-підтвердження)
 *   - payment_intent.payment_failed → лог + лист адміну
 *   - charge.refunded               → зменшення балансу (рефанд повернув кошти на картку)
 *   - charge.dispute.created        → critical-лог + лист адміну (chargeback)
 *   - інші                          → status='ignored', 200
 *
 * Час — EST (America/New_York), як і решта LeadRouter.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Stripe_Webhook')) {

    class LR_Stripe_Webhook
    {
        /** Namespace + route REST API */
        const REST_NS    = 'leadrouter/v1';
        const REST_ROUTE = '/stripe-webhook';

        /** Скільки символів payload зберігати для дебагу */
        const EXCERPT_LEN = 2000;

        /** Часова зона проекту */
        private const TZ = 'America/New_York';

        /* ============================================================
         * Реєстрація REST-маршруту
         * ============================================================ */
        public static function register(): void
        {
            register_rest_route(self::REST_NS, self::REST_ROUTE, [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'handle'],
                // Публічний ендпоінт: захист — лише підпис Stripe, не nonce/cookie.
                'permission_callback' => '__return_true',
            ]);
        }

        /* ============================================================
         * Обробник вебхука
         * ============================================================ */
        public static function handle(WP_REST_Request $request)
        {
            global $wpdb;

            // 1) RAW body — підпис рахується саме від сирих байтів, тому беремо
            //    тіло ДО будь-якого парсингу JSON.
            $payload = (string)$request->get_body();
            if ($payload === '') {
                $payload = (string)file_get_contents('php://input');
            }

            // 2) Заголовок підпису (WP канонізує 'Stripe-Signature' → 'stripe_signature')
            $sig = (string)$request->get_header('stripe_signature');
            if ($sig === '' && isset($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
                $sig = (string)$_SERVER['HTTP_STRIPE_SIGNATURE'];
            }

            // 3) Верифікація підпису — єдиний рубіж автентичності. Не пройшов → 400, не обробляємо.
            if (!LR_Stripe::verify_webhook($payload, $sig)) {
                LR_Billing::log_error(0, 'STRIPE_WEBHOOK_BAD_SIGNATURE', 'warning',
                    'Webhook: підпис Stripe не пройшов верифікацію',
                    ['source' => 'stripe_webhook']);
                return new WP_REST_Response(['error' => 'invalid signature'], 400);
            }

            // 4) Парсинг JSON
            $event = json_decode($payload, true);
            if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
                return new WP_REST_Response(['error' => 'invalid payload'], 400);
            }

            $event_id   = (string)$event['id'];
            $event_type = (string)$event['type'];

            // 5) Розвʼязуємо партнера (може бути 0 — тоді деякі гілки лише логують)
            $partner_id = self::resolve_partner_id($event);

            // 6) IDEMPOTENCY: пробуємо зафіксувати подію. UNIQUE(stripe_event_id) відсіче дубль.
            $t_events = $wpdb->prefix . 'leadrouter_stripe_events';
            $now      = self::now();

            $prev = $wpdb->suppress_errors(true);
            $inserted = $wpdb->insert(
                $t_events,
                [
                    'stripe_event_id' => $event_id,
                    'event_type'      => $event_type,
                    'partner_id'      => $partner_id > 0 ? $partner_id : null,
                    'received_at'     => $now,
                    'processed_at'    => null,
                    'status'          => 'received',
                    'payload_excerpt' => mb_substr($payload, 0, self::EXCERPT_LEN),
                ],
                ['%s', '%s', '%d', '%s', '%s', '%s', '%s']
            );
            $dup_event = (!$inserted && stripos((string)$wpdb->last_error, 'duplicate') !== false);
            $wpdb->suppress_errors($prev);

            if ($dup_event) {
                // Подію вже бачили й обробили — тихо підтверджуємо, не дублюємо ефекти.
                return self::ok(['received' => true, 'idempotent' => true]);
            }
            $event_row_id = (int)$wpdb->insert_id;

            // 7) Роутинг за типом події
            $final_status = 'processed';
            try {
                switch ($event_type) {
                    case 'payment_intent.succeeded':
                        self::on_payment_succeeded($event, $partner_id);
                        break;

                    case 'payment_intent.payment_failed':
                        self::on_payment_failed($event, $partner_id);
                        break;

                    case 'charge.refunded':
                        self::on_charge_refunded($event, $partner_id);
                        break;

                    case 'charge.dispute.created':
                        self::on_dispute_created($event, $partner_id);
                        break;

                    case 'checkout.session.completed':
                        self::on_checkout_completed($event, $partner_id);
                        break;

                    case 'setup_intent.succeeded':
                        self::on_setup_intent_succeeded($event, $partner_id);
                        break;

                    default:
                        // Підписана, але нецікава нам подія — фіксуємо й ігноруємо.
                        $final_status = 'ignored';
                        break;
                }
            } catch (\Throwable $e) {
                $final_status = 'error';
                LR_Billing::log_error($partner_id, 'STRIPE_WEBHOOK_EXCEPTION', 'error',
                    'Webhook обробка впала: ' . $e->getMessage(),
                    ['event_type' => $event_type, 'event_id' => $event_id, 'source' => 'stripe_webhook']);
            }

            // 8) Фіналізуємо запис події (partner_id міг уточнитися всередині гілок)
            $wpdb->update(
                $t_events,
                [
                    'partner_id'   => $partner_id > 0 ? $partner_id : null,
                    'processed_at' => self::now(),
                    'status'       => $final_status,
                ],
                ['id' => $event_row_id]
            );

            // 9) Завжди 200 для підписаної прийнятої події (інакше Stripe ретраїтиме)
            return self::ok(['received' => true]);
        }

        /* ============================================================
         * Гілки обробки за типом події
         * ============================================================ */

        /**
         * payment_intent.succeeded.
         * Головний кейс — пізнє підтвердження 3DS: синхронний charge() повернув
         * requires_action (без нарахування), а тепер клієнт завершив автентифікацію.
         * Якщо ж ми вже нарахували синхронно (off_session succeeded) — нічого не робимо.
         */
        private static function on_payment_succeeded(array $event, int $partner_id): void
        {
            global $wpdb;

            $obj       = self::obj($event);
            $pi_id     = (string)($obj['id'] ?? '');
            $charge_id = is_string($obj['latest_charge'] ?? null) ? (string)$obj['latest_charge'] : null;
            $amount    = self::amount_dollars($obj, ['amount_received', 'amount']);

            if ($pi_id === '') {
                return;
            }

            $t_pay = $wpdb->prefix . 'leadrouter_stripe_payments';
            $pay   = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status FROM {$t_pay} WHERE stripe_payment_intent_id = %s LIMIT 1",
                    $pi_id
                ),
                ARRAY_A
            );

            // Уже нараховано синхронно — друга обробка не потрібна.
            if ($pay && (string)$pay['status'] === 'succeeded') {
                LR_Billing::log_audit([
                    'partner_id'   => $partner_id,
                    'actor_type'   => 'stripe_webhook',
                    'action'       => 'stripe_webhook_pi_succeeded_dup',
                    'entity_type'  => 'partner_billing',
                    'context_json' => ['payment_intent' => $pi_id],
                ]);
                return;
            }

            if ($partner_id <= 0) {
                LR_Billing::log_error(0, 'STRIPE_WEBHOOK_NO_PARTNER', 'error',
                    'payment_intent.succeeded: не вдалося розвʼязати партнера',
                    ['payment_intent' => $pi_id, 'source' => 'stripe_webhook']);
                return;
            }

            // Нараховуємо тепер. topup ідемпотентний за charge_id (auto_charge:pid:charge_id),
            // тож навіть якщо крон встиг — повторно не нарахує.
            if ($amount > 0) {
                LR_Billing::topup(
                    $partner_id,
                    $amount,
                    'auto_charge',
                    'Stripe webhook: payment_intent.succeeded',
                    $charge_id !== null ? $charge_id : $pi_id
                );
            }

            // topup сам реактивує зупиненого білінгом; підстраховка для явності.
            if (LR_Billing::is_deactivated_by_billing($partner_id)) {
                LR_Billing::reactivate_partner($partner_id, 'stripe_webhook_succeeded');
            }

            LR_Billing_Mailer::stripe_charge_success($partner_id, [
                'amount'    => $amount,
                'charge_id' => $charge_id,
            ]);

            self::upsert_payment_status($pi_id, $partner_id, 'succeeded', [
                'charge_id' => $charge_id,
                'amount'    => $amount,
                'event_id'  => (string)$event['id'],
            ]);

            LR_Billing::log_audit([
                'partner_id'   => $partner_id,
                'actor_type'   => 'stripe_webhook',
                'action'       => 'stripe_charge_success',
                'entity_type'  => 'partner_billing',
                'context_json' => ['payment_intent' => $pi_id, 'amount' => $amount, 'source' => 'webhook'],
            ]);
        }

        /**
         * payment_intent.payment_failed — асинхронна відмова (напр. після requires_action).
         * Лог + лист адміну. Партнера НЕ деактивуємо тут: зупинку по балансу робить крон.
         */
        private static function on_payment_failed(array $event, int $partner_id): void
        {
            $obj    = self::obj($event);
            $pi_id  = (string)($obj['id'] ?? '');
            $err    = isset($obj['last_payment_error']) && is_array($obj['last_payment_error']) ? $obj['last_payment_error'] : [];
            $reason = (string)($err['code'] ?? ($err['type'] ?? 'payment_failed'));
            $msg    = (string)($err['message'] ?? '');

            LR_Billing::log_error($partner_id, 'STRIPE_ASYNC_FAILED', 'error',
                'Асинхронна відмова Stripe (' . $reason . ')' . ($msg !== '' ? ': ' . $msg : ''),
                ['payment_intent' => $pi_id, 'source' => 'stripe_webhook']);

            if ($partner_id > 0) {
                LR_Billing_Mailer::stripe_admin_failed($partner_id, [
                    'reason'  => $reason,
                    'message' => $msg,
                ]);
            }

            if ($pi_id !== '') {
                self::upsert_payment_status($pi_id, $partner_id, 'failed', [
                    'failure_code'    => $reason,
                    'failure_message' => $msg,
                    'event_id'        => (string)$event['id'],
                ]);
            }

            LR_Billing::log_audit([
                'partner_id'   => $partner_id,
                'actor_type'   => 'stripe_webhook',
                'action'       => 'stripe_async_failed',
                'entity_type'  => 'partner_billing',
                'context_json' => ['payment_intent' => $pi_id, 'reason' => $reason],
            ]);
        }

        /**
         * charge.refunded — кошти повернули на картку партнера, тож НАШ баланс зменшуємо.
         * Об'єкт події — Charge (ch_...), з полем payment_intent і списком refunds.
         * Беремо суму останнього рефанду й застосовуємо ідемпотентно за його id.
         */
        private static function on_charge_refunded(array $event, int $partner_id): void
        {
            $obj   = self::obj($event);
            $ch_id = (string)($obj['id'] ?? '');
            $pi_id = is_string($obj['payment_intent'] ?? null) ? (string)$obj['payment_intent'] : '';

            // Останній рефанд у списку (Stripe віддає найновіші першими)
            $refunds   = (isset($obj['refunds']['data']) && is_array($obj['refunds']['data'])) ? $obj['refunds']['data'] : [];
            $latest    = $refunds[0] ?? [];
            $refund_id = (string)($latest['id'] ?? $ch_id);
            $cents     = isset($latest['amount']) ? (int)$latest['amount'] : (int)($obj['amount_refunded'] ?? 0);
            $amount    = $cents / 100;

            if ($partner_id <= 0) {
                LR_Billing::log_error(0, 'STRIPE_WEBHOOK_NO_PARTNER', 'warning',
                    'charge.refunded: не вдалося розвʼязати партнера',
                    ['charge' => $ch_id, 'payment_intent' => $pi_id, 'source' => 'stripe_webhook']);
                return;
            }

            if ($amount > 0) {
                // Зменшує баланс, type='refund', ідемпотентно за refund_id
                LR_Billing::apply_refund($partner_id, $amount, $refund_id, 'Stripe refund ' . $refund_id);
            }

            if ($pi_id !== '') {
                self::upsert_payment_status($pi_id, $partner_id, 'refunded', [
                    'charge_id' => $ch_id,
                    'event_id'  => (string)$event['id'],
                ]);
            }

            LR_Billing::log_audit([
                'partner_id'   => $partner_id,
                'actor_type'   => 'stripe_webhook',
                'action'       => 'stripe_refund',
                'entity_type'  => 'partner_billing',
                'context_json' => ['charge' => $ch_id, 'payment_intent' => $pi_id, 'refund_id' => $refund_id, 'amount' => $amount],
            ]);
        }

        /**
         * charge.dispute.created — чарджбек/спір. Об'єкт — Dispute, з полями charge і
         * (у нових версіях API) payment_intent. Критичний лог + лист адміну.
         */
        private static function on_dispute_created(array $event, int $partner_id): void
        {
            $obj        = self::obj($event);
            $dispute_id = (string)($obj['id'] ?? '');
            $ch_id      = is_string($obj['charge'] ?? null) ? (string)$obj['charge'] : '';
            $pi_id      = is_string($obj['payment_intent'] ?? null) ? (string)$obj['payment_intent'] : '';
            $reason     = (string)($obj['reason'] ?? 'dispute');
            $amount     = isset($obj['amount']) ? ((int)$obj['amount']) / 100 : 0.0;

            LR_Billing::log_error($partner_id, 'STRIPE_DISPUTE', 'critical',
                'Stripe dispute/chargeback (' . $reason . '), dispute=' . $dispute_id,
                ['charge' => $ch_id, 'payment_intent' => $pi_id, 'amount' => $amount, 'source' => 'stripe_webhook']);

            if ($partner_id > 0) {
                // Окремого шаблону немає — використовуємо адмінський лист про невдалий charge,
                // позначивши причину як CHARGEBACK, щоб адмін одразу бачив суть.
                LR_Billing_Mailer::stripe_admin_failed($partner_id, [
                    'reason'  => 'CHARGEBACK / dispute: ' . $reason,
                    'amount'  => $amount,
                    'message' => 'Dispute ' . $dispute_id . ' (charge ' . $ch_id . ')',
                ]);
            }

            if ($pi_id !== '') {
                self::upsert_payment_status($pi_id, $partner_id, 'disputed', [
                    'charge_id' => $ch_id,
                    'event_id'  => (string)$event['id'],
                ]);
            }

            LR_Billing::log_audit([
                'partner_id'   => $partner_id,
                'actor_type'   => 'stripe_webhook',
                'action'       => 'stripe_dispute',
                'entity_type'  => 'partner_billing',
                'context_json' => ['dispute' => $dispute_id, 'charge' => $ch_id, 'reason' => $reason, 'amount' => $amount],
            ]);
        }

        /**
         * checkout.session.completed (mode=setup) — підстраховка для прив'язки картки.
         * Payload не розгорнутий, тож ретривимо сесію (expand setup_intent.payment_method)
         * і зберігаємо картку. Ідемпотентно: повторне збереження тих самих даних безпечне.
         */
        private static function on_checkout_completed(array $event, int $partner_id): void
        {
            if ($partner_id <= 0 || !class_exists('LR_Partner_Card')) {
                return;
            }
            $obj = self::obj($event);
            // Лише setup-сесії стосуються прив'язки картки
            if (($obj['mode'] ?? '') !== 'setup') {
                return;
            }
            $sid = (string) ($obj['id'] ?? '');
            if ($sid === '') {
                return;
            }
            $full = LR_Stripe::retrieve_checkout_session($sid);
            if (!empty($full)) {
                LR_Partner_Card::save_from_object($partner_id, $full);
            }
        }

        /**
         * setup_intent.succeeded — підстраховка прив'язки картки.
         * Обʼєкт несе customer + payment_method (id) + metadata.partner_id.
         */
        private static function on_setup_intent_succeeded(array $event, int $partner_id): void
        {
            if ($partner_id <= 0 || !class_exists('LR_Partner_Card')) {
                return;
            }
            $obj = self::obj($event);
            LR_Partner_Card::save_from_object($partner_id, $obj);
        }

        /* ============================================================
         * Розвʼязання partner_id з події (найкрихкіше місце)
         * ============================================================ */
        /**
         * Стратегія (по спаданню надійності):
         *   1) metadata.partner_id на об'єкті події — для payment_intent.* (ми ставимо його
         *      у LR_Stripe::charge при створенні PaymentIntent);
         *   2) пошук у leadrouter_stripe_payments за payment_intent_id;
         *   3) пошук у leadrouter_stripe_payments за charge_id.
         *
         * Для charge.refunded/charge.dispute.created об'єкт — Charge/Dispute, який НЕ несе
         * metadata PI (Stripe її не копіює), тож тут спрацьовує пошук по PI/charge id у БД
         * (succeeded-рядок зберігає обидва ідентифікатори).
         */
        private static function resolve_partner_id(array $event): int
        {
            global $wpdb;

            $obj      = self::obj($event);
            $obj_type = (string)($obj['object'] ?? '');

            $pi_id  = null;
            $ch_id  = null;

            if ($obj_type === 'payment_intent') {
                $pi_id = (string)($obj['id'] ?? '');
                $ch_id = is_string($obj['latest_charge'] ?? null) ? (string)$obj['latest_charge'] : null;
            } elseif ($obj_type === 'charge') {
                $ch_id = (string)($obj['id'] ?? '');
                $pi_id = is_string($obj['payment_intent'] ?? null) ? (string)$obj['payment_intent'] : null;
            } elseif ($obj_type === 'dispute') {
                $ch_id = is_string($obj['charge'] ?? null) ? (string)$obj['charge'] : null;
                $pi_id = is_string($obj['payment_intent'] ?? null) ? (string)$obj['payment_intent'] : null;
            }

            // 1) metadata.partner_id (найнадійніше для payment_intent.*)
            if (!empty($obj['metadata']['partner_id'])) {
                $meta_pid = (int)$obj['metadata']['partner_id'];
                if ($meta_pid > 0) {
                    return $meta_pid;
                }
            }

            $t_pay = $wpdb->prefix . 'leadrouter_stripe_payments';

            // 2) за payment_intent_id
            if ($pi_id !== null && $pi_id !== '') {
                $pid = (int)$wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT partner_id FROM {$t_pay} WHERE stripe_payment_intent_id = %s LIMIT 1",
                        $pi_id
                    )
                );
                if ($pid > 0) {
                    return $pid;
                }
            }

            // 3) за charge_id
            if ($ch_id !== null && $ch_id !== '') {
                $pid = (int)$wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT partner_id FROM {$t_pay} WHERE stripe_charge_id = %s LIMIT 1",
                        $ch_id
                    )
                );
                if ($pid > 0) {
                    return $pid;
                }
            }

            return 0;
        }

        /* ============================================================
         * Внутрішні хелпери
         * ============================================================ */

        /** Об'єкт події: event.data.object */
        private static function obj(array $event): array
        {
            return (isset($event['data']['object']) && is_array($event['data']['object']))
                ? $event['data']['object']
                : [];
        }

        /** Перша наявна сума (в центах) з переліку ключів → у доларах */
        private static function amount_dollars(array $obj, array $keys): float
        {
            foreach ($keys as $k) {
                if (isset($obj[$k])) {
                    return ((int)$obj[$k]) / 100;
                }
            }
            return 0.0;
        }

        /**
         * Оновити (або створити) рядок leadrouter_stripe_payments за payment_intent_id.
         * Якщо рядка немає (рідко — вебхук без попереднього синхронного charge),
         * створюємо мінімальний запис із унікальним ключем wh_<event_id>.
         */
        private static function upsert_payment_status(string $pi_id, int $partner_id, string $status, array $extra = []): void
        {
            global $wpdb;
            $t   = $wpdb->prefix . 'leadrouter_stripe_payments';
            $now = self::now();

            $existing_id = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$t} WHERE stripe_payment_intent_id = %s LIMIT 1", $pi_id)
            );

            $fields = ['status' => $status, 'updated_at' => $now];
            if (!empty($extra['charge_id']))       { $fields['stripe_charge_id'] = (string)$extra['charge_id']; }
            if (isset($extra['failure_code']))     { $fields['failure_code']     = (string)$extra['failure_code']; }
            if (isset($extra['failure_message']))  { $fields['failure_message']  = mb_substr((string)$extra['failure_message'], 0, 255); }

            if ($existing_id > 0) {
                $wpdb->update($t, $fields, ['id' => $existing_id]);
                return;
            }

            // Рядка немає — створюємо мінімальний для трасування.
            $idem = 'wh_' . (string)($extra['event_id'] ?? $pi_id);
            $wpdb->insert($t, array_merge($fields, [
                'partner_id'               => $partner_id > 0 ? $partner_id : 0,
                'stripe_payment_intent_id' => $pi_id,
                'idempotency_key'          => $idem,
                'amount'                   => (float)($extra['amount'] ?? 0),
                'currency'                 => 'USD',
                'created_at'               => $now,
            ]));
        }

        /** Відповідь 200 з тілом */
        private static function ok(array $data): WP_REST_Response
        {
            return new WP_REST_Response($data, 200);
        }

        /** Поточний час у EST у форматі MySQL */
        private static function now(): string
        {
            return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d H:i:s');
        }
    }
}
