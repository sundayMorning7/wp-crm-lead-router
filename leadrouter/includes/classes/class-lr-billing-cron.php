<?php
/**
 * LR_Billing_Cron — фоновий воркер білінгу партнерів.
 *
 * Запускається кожні 2 хвилини власним WP-Cron розкладом 'every_two_minutes'.
 * Призначення:
 *   - пройтись по всіх партнерах, що мають білінг-профіль (status='active');
 *   - реактивувати партнера, якщо баланс відновився (deactivated_by_billing → 0);
 *   - запустити Stripe auto-charge, коли баланс впав нижче min_balance;
 *   - деактивувати партнерів, яким не вистачає на лід (deactivated_by_billing → 1),
 *     якщо не ввімкнено allow_negative_balance;
 *   - надсилати email-сповіщення один раз (прапорці notified_*).
 *
 * Патерн — як у LeadRouter_Cron_New_Leads:
 *   add_filter('cron_schedules') → реєстрація інтервалу,
 *   add_action('wp')             → постановка події в розклад,
 *   add_action(CRON_HOOK)        → сам воркер.
 *
 * Захист від паралельного запуску — транзієнт LOCK_KEY.
 * Захист від дублів charge на партнера — транзієнт-лок на partner_id.
 *
 * Stripe-логіка винесена в LR_Stripe::charge() (клас додамо пізніше — поки заглушка).
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Billing_Cron')) {

    class LR_Billing_Cron
    {
        /** Хук cron-події */
        const CRON_HOOK = 'leadrouter_billing_cron_tick';

        /** Назва розкладу (кожні 2 хв) */
        const SCHEDULE = 'every_two_minutes';

        /** Глобальний лок воркера (щоб тіки не накладались) */
        const LOCK_KEY = 'lr_billing_cron_lock';

        /** Префікс пер-партнерського локу на charge */
        const CHARGE_LOCK_PREFIX = 'lr_billing_charge_';

        /** Інтервал воркера, сек */
        const INTERVAL = 120;

        /** TTL глобального локу (трохи менше за інтервал) */
        const LOCK_TTL = 110;

        /** TTL локу на charge партнера, сек (5 хв) */
        const CHARGE_LOCK_TTL = 300;

        /* ============================================================
         * Реєстрація хуків і розкладу
         * ============================================================ */
        public static function register()
        {
            add_filter('cron_schedules', [__CLASS__, 'add_two_minutes_schedule']);
            add_action('wp', [__CLASS__, 'schedule_event']);
            add_action(self::CRON_HOOK, [__CLASS__, 'run']);
        }

        /** Додаємо інтервал 'every_two_minutes', якщо його ще немає */
        public static function add_two_minutes_schedule($schedules)
        {
            if (!isset($schedules[self::SCHEDULE])) {
                $schedules[self::SCHEDULE] = [
                    'interval' => self::INTERVAL,
                    'display'  => __('Every 2 Minutes (LeadRouter Billing)', 'leadrouter'),
                ];
            }
            return $schedules;
        }

        /** Ставимо подію в розклад, якщо ще не заплановано */
        public static function schedule_event()
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + self::INTERVAL, self::SCHEDULE, self::CRON_HOOK);
            }
        }

        /* ============================================================
         * Головний цикл
         * ============================================================ */
        public static function run()
        {
            global $wpdb;

            // 🔒 Захист від паралельного запуску воркера
            if (get_transient(self::LOCK_KEY)) {
                return;
            }
            set_transient(self::LOCK_KEY, 1, self::LOCK_TTL);

            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';

            // Беремо всіх активних партнерів з білінг-профілем.
            // 'active' — робочий статус білінг-таблиці; suspended|blocked не чіпаємо.
            //
            // Фільтр «адмінськи-вимкнених» (active=0 AND deactivated_by_billing=0) НЕ робимо тут:
            // _leadrouter_partner_active живе в post_meta, а deactivated_by_billing — у білінг-таблиці,
            // тож коректний SQL-фільтр потребував би JOIN з postmeta + відтворення NULL-логіки is_active()
            // (порожнє/відсутнє мета = активний). Це крихко. Натомість фільтрацію робить
            // check_partner_balance через ранній return (Рівень 1): для admin_disabled вона нічого не
            // виконує. Партнери з deactivated_by_billing=1 (вимкнені білінгом) свідомо лишаються
            // у вибірці — щоб працювала реактивація при відновленні балансу.
            $partner_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT partner_id FROM {$t_billing} WHERE status = %s ORDER BY partner_id ASC",
                    'active'
                )
            );

            if (empty($partner_ids)) {
                delete_transient(self::LOCK_KEY);
                return;
            }

            foreach ($partner_ids as $pid) {
                self::check_partner_balance((int)$pid);
            }

            delete_transient(self::LOCK_KEY);
        }

        /* ============================================================
         * Перевірка одного партнера
         * ============================================================ */
        public static function check_partner_balance(int $partner_id)
        {
            global $wpdb;

            if ($partner_id <= 0) {
                return;
            }

            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT balance, min_balance, lead_price, currency,
                            auto_charge_enabled, allow_negative_balance, deactivated_by_billing,
                            notified_low_balance, notified_stopped, notified_admin_negative,
                            notified_charge_failed,
                            email, disable_low_balance_email,
                            stripe_customer_id, stripe_payment_method_id
                       FROM {$t_billing}
                      WHERE partner_id = %d
                      LIMIT 1",
                    $partner_id
                ),
                ARRAY_A
            );

            // Профілю немає — нічого перевіряти
            if (!$row) {
                return;
            }

            // 🛑 Партнера вимкнув АДМІН вручну (active=0), а не білінг?
            // Розрізняємо за deactivated_by_billing: білінг при зупинці ставить його =1,
            // ручне вимкнення лишає =0. is_active() трактує порожнє/null як активного,
            // тож «вимкнено адміном» — лише ЯВНЕ '0'.
            $partner_active_meta = get_post_meta($partner_id, '_leadrouter_partner_active', true);
            $admin_disabled = ($partner_active_meta === '0') && ((int)$row['deactivated_by_billing'] === 0);

            if ($admin_disabled) {
                // Адмін свідомо вимкнув партнера — білінг узагалі не втручається:
                // ні auto-charge, ні листів про низький баланс, ні деактивації.
                return;
            }

            $balance        = (float)$row['balance'];
            $min_balance    = (float)$row['min_balance'];
            $lead_price     = (float)$row['lead_price'];
            $auto_charge_on = (int)$row['auto_charge_enabled'] === 1;
            $allow_negative = (int)$row['allow_negative_balance'] === 1;
            $deactivated    = (int)$row['deactivated_by_billing'] === 1;

            // Чи вистачає балансу хоча б на один лід
            $can_afford = ($lead_price > 0) ? ($balance >= $lead_price) : ($balance >= 0);

            // 1) Партнер зупинений білінгом → перевіряємо, чи можна реактивувати
            if ($deactivated) {
                if ($allow_negative || $can_afford) {
                    LR_Billing::reactivate_partner($partner_id, 'balance_restored');
                    // Скидаємо прапорці, щоб цикл сповіщень міг початися заново
                    self::reset_all_notifications($partner_id);
                }
                return; // поки деактивований — нічого більше не робимо
            }

            // 1.5) Відновлення балансу: щойно баланс піднявся назад над порогом —
            // переозброюємо прапорці сповіщень, щоб наступне падіння знову тригернуло листи.
            // Критично для партнерів з allow_negative_balance=1: їх не деактивують, а отже
            // reset через реактивацію (reset_all_notifications) для них ніколи не спрацьовує,
            // і без цього блоку кожен лист пішов би лише раз за весь час життя профілю.
            if ($balance >= $min_balance && (int)$row['notified_low_balance'] === 1) {
                LR_Billing::reset_notification_flag($partner_id, 'notified_low_balance');
                $row['notified_low_balance'] = 0;
            }
            if ($balance >= 0 && (int)$row['notified_admin_negative'] === 1) {
                LR_Billing::reset_notification_flag($partner_id, 'notified_admin_negative');
                $row['notified_admin_negative'] = 0;
            }
            // Баланс піднявся назад над порогом auto-charge → знімаємо «замок» невдалого
            // charge, щоб наступне падіння балансу знову дозволило спробу Stripe.
            if ($balance >= $min_balance && (int)$row['notified_charge_failed'] === 1) {
                LR_Billing::reset_notification_flag($partner_id, 'notified_charge_failed');
                $row['notified_charge_failed'] = 0;
            }

            // Чи налаштований у партнера збережений спосіб оплати Stripe
            $has_stripe_method =
                trim((string)($row['stripe_customer_id'] ?? '')) !== '' &&
                trim((string)($row['stripe_payment_method_id'] ?? '')) !== '';

            // 2) Проактивний auto-charge: баланс нижче порогу, дозволено auto-charge
            //    І є чим списувати (customer + payment_method). Без методу — не смикаємо Stripe.
            //    !$admin_disabled — другий рубіж (ранній return вище вже відсік адмінських,
            //    але лишаємо умову явно, щоб ніяке майбутнє рефакторення не зарядило їх випадково).
            //    !notified_charge_failed — анти-спам: одна спроба charge на епізод падіння балансу;
            //    прапор скидається при успіху, реактивації або відновленні балансу (Блок 1.5).
            if ($auto_charge_on && $balance < $min_balance && $has_stripe_method
                && !$admin_disabled && (int)$row['notified_charge_failed'] === 0) {

                $charge_status = self::trigger_auto_charge($partner_id);

                if ($charge_status === 'succeeded') {
                    return; // успішно поповнили — партнер у нормі, прапорці вже скинуто
                }

                // Карту відхилено / потрібне 3DS → це невдала спроба епізоду.
                // Ставимо замок, щоб не смикати Stripe щотіка. НЕ деактивуємо тут —
                // рішення про зупинку приймає Блок 4 окремо, по lead_price.
                if ($charge_status === 'declined' || $charge_status === 'requires_action') {
                    LR_Billing::set_notification_flag($partner_id, 'notified_charge_failed');
                    $row['notified_charge_failed'] = 1;
                }
                // duplicate / error / '' (skip) — прапор не ставимо: це не «вина картки»,
                // спробу можна повторити наступного тіка.
            }

            // 3) Лист-попередження про низький баланс (один раз)
            if ($balance < $min_balance && (int)$row['notified_low_balance'] === 0) {
                LR_Billing_Mailer::low_balance($partner_id, $row);
                LR_Billing::set_notification_flag($partner_id, 'notified_low_balance');
            }

            // 4) Деактивація — СУВОРО по lead_price, не по min_balance.
            //    $can_afford = (lead_price>0) ? balance >= lead_price : balance >= 0,
            //    тож сюди заходимо лише коли балансу не вистачає навіть на один лід.
            //    Партнер між lead_price і min_balance лишається АКТИВНИМ: він уже отримав
            //    low_balance-лист (Блок 3) і, якщо charge впав, notified_charge_failed=1,
            //    але продовжує приймати ліди, доки балансу вистачає бодай на один.
            if (!$can_afford) {
                if ($allow_negative) {
                    // allow_negative=1 → партнера не зупиняємо; нехай іде в мінус.
                    // Сповіщення адміна про від'ємний баланс робить Блок 5 нижче.
                } else {
                    LR_Billing::deactivate_partner($partner_id, 'insufficient_funds');

                    // Сповіщення партнера про зупинку (один раз)
                    if ((int)$row['notified_stopped'] === 0) {
                        LR_Billing_Mailer::stopped($partner_id, $row);
                        LR_Billing::set_notification_flag($partner_id, 'notified_stopped');
                    }
                }
            }

            // 5) Сповіщення адміна про від'ємний баланс (один раз) — НЕЗАЛЕЖНО від деактивації.
            // Винесено з гілки зупинки вище: інакше партнери з allow_negative_balance=1
            // (які в ту гілку не заходять) могли йти у мінус без жодного сповіщення адміну.
            if ($balance < 0 && (int)$row['notified_admin_negative'] === 0) {
                LR_Billing_Mailer::admin_negative($partner_id, $row);
                LR_Billing::set_notification_flag($partner_id, 'notified_admin_negative');
            }
        }

        /* ============================================================
         * Запуск Stripe auto-charge для партнера
         * ============================================================ */
        /**
         * Реальний Stripe auto-charge для партнера.
         *
         * Повертає СТАТУС операції (string), щоб викликач (check_partner_balance) сам
         * вирішував, чи ставити анти-спам прапорець notified_charge_failed:
         *   'succeeded'       → баланс поповнено, прапорці скинуто;
         *   'declined'        → карту відхилено (НЕ деактивуємо тут — рішення за Блоком 4);
         *   'requires_action' → потрібне 3DS (НЕ деактивуємо тут);
         *   'duplicate'       → уже списували в цьому вікні;
         *   'error'           → наша помилка конфігурації/інтеграції;
         *   ''                → спробу не виконували (поганий id / лок зайнято).
         *
         * Деактивацію по declined/requires_action прибрано навмисно: партнер може мати
         * кошти на ще кілька лідів (balance >= lead_price), і зупиняти його зарано.
         * Зупинку по lead_price робить check_partner_balance (Блок 4).
         *
         * Формат відповіді LR_Stripe::charge():
         *   succeeded       → ['status','charge_id','amount','raw']
         *   declined        → ['status','reason','message']
         *   requires_action → ['status','charge_id','reason']
         *   duplicate       → ['status','charge_id']
         *   error           → ['status','reason','message']  (no_stripe_method/stripe_not_configured/...)
         */
        public static function trigger_auto_charge(int $partner_id): string
        {
            if ($partner_id <= 0) {
                return '';
            }

            $lock_key = self::CHARGE_LOCK_PREFIX . $partner_id;

            // 🛡️ Захист від дублів/паралельних charge для цього партнера
            if (get_transient($lock_key)) {
                return '';
            }
            // Лок на 5 хв — поки Stripe-операція в роботі
            set_transient($lock_key, 1, self::CHARGE_LOCK_TTL);

            // finally гарантує зняття локу навіть при винятку
            try {
                // 2) Фіксуємо намір
                LR_Billing::log_audit([
                    'partner_id'   => $partner_id,
                    'actor_type'   => 'system',
                    'action'       => 'stripe_charge_initiated',
                    'entity_type'  => 'partner_billing',
                    'context_json' => ['source' => 'billing_cron'],
                ]);

                // Stripe має бути доступний (підключений у плагіні)
                if (!class_exists('LR_Stripe') || !method_exists('LR_Stripe', 'charge')) {
                    LR_Billing::log_error($partner_id, 'STRIPE_NOT_AVAILABLE', 'error',
                        'LR_Stripe недоступний — auto-charge неможливий', ['source' => 'billing_cron']);
                    return 'error';
                }

                // 3) Реальний charge
                $result = LR_Stripe::charge($partner_id);
                $status = is_array($result) ? (string)($result['status'] ?? '') : '';

                // 4) Розбір за статусом
                switch ($status) {

                    case 'succeeded':
                        $amount    = (float)($result['amount'] ?? 0);
                        $charge_id = (string)($result['charge_id'] ?? '');

                        // Поповнення балансу. topup() сам реактивує партнера, якщо був зупинений.
                        LR_Billing::topup(
                            $partner_id,
                            $amount,
                            'auto_charge',
                            'Stripe auto-charge',
                            $charge_id !== '' ? $charge_id : null
                        );

                        // Лист-чек партнеру
                        LR_Billing_Mailer::stripe_charge_success($partner_id, [
                            'amount'    => $amount,
                            'charge_id' => $charge_id,
                        ]);

                        LR_Billing::log_audit([
                            'partner_id'   => $partner_id,
                            'actor_type'   => 'system',
                            'action'       => 'stripe_charge_success',
                            'entity_type'  => 'partner_billing',
                            'context_json' => ['amount' => $amount, 'charge_id' => $charge_id],
                        ]);

                        // Баланс відновлено → переозброюємо прапорці сповіщень
                        self::reset_all_notifications($partner_id);

                        return 'succeeded';

                    case 'declined':
                        $reason  = (string)($result['reason'] ?? 'card_declined');
                        $message = (string)($result['message'] ?? '');

                        // Карту відхилено. НЕ деактивуємо тут: у партнера ще можуть бути
                        // кошти на ліди. Зупинку по lead_price вирішує Блок 4.
                        // Лишаємо лише сповіщення + лог; анти-спам прапорець ставить викликач.
                        LR_Billing_Mailer::stripe_charge_declined($partner_id, [
                            'reason'  => $reason,
                            'message' => $message,
                        ]);
                        LR_Billing_Mailer::stripe_admin_failed($partner_id, [
                            'reason'  => $reason,
                            'message' => $message,
                        ]);

                        LR_Billing::log_audit([
                            'partner_id'   => $partner_id,
                            'actor_type'   => 'system',
                            'action'       => 'stripe_charge_failed',
                            'entity_type'  => 'partner_billing',
                            'context_json' => ['status' => 'declined', 'reason' => $reason],
                        ]);
                        LR_Billing::log_error($partner_id, 'STRIPE_CHARGE_DECLINED', 'error',
                            'Stripe charge відхилено: ' . $reason . ($message !== '' ? ' — ' . $message : ''),
                            ['source' => 'billing_cron']);

                        return 'declined';

                    case 'requires_action':
                        $reason = (string)($result['reason'] ?? 'authentication_required');

                        // Потрібне 3DS-підтвердження. НЕ деактивуємо тут (як і для declined):
                        // партнер ще може мати кошти на ліди; зупинку вирішує Блок 4.
                        LR_Billing_Mailer::stripe_charge_action($partner_id, [
                            'reason'    => $reason,
                            'charge_id' => (string)($result['charge_id'] ?? ''),
                        ]);
                        LR_Billing_Mailer::stripe_admin_failed($partner_id, [
                            'reason' => $reason,
                        ]);

                        LR_Billing::log_audit([
                            'partner_id'   => $partner_id,
                            'actor_type'   => 'system',
                            'action'       => 'stripe_charge_requires_action',
                            'entity_type'  => 'partner_billing',
                            'context_json' => ['status' => 'requires_action', 'reason' => $reason],
                        ]);
                        LR_Billing::log_error($partner_id, 'STRIPE_REQUIRES_ACTION', 'warning',
                            'Stripe charge потребує 3DS-підтвердження', ['source' => 'billing_cron']);

                        return 'requires_action';

                    case 'duplicate':
                        // Уже списували в цьому вікні — тихо, без листів/деактивації
                        LR_Billing::log_audit([
                            'partner_id'   => $partner_id,
                            'actor_type'   => 'system',
                            'action'       => 'stripe_charge_duplicate',
                            'entity_type'  => 'partner_billing',
                            'context_json' => ['charge_id' => (string)($result['charge_id'] ?? '')],
                        ]);
                        return 'duplicate';

                    case 'error':
                    default:
                        // Наша помилка конфігурації/інтеграції (no_stripe_method, stripe_not_configured,
                        // invalid_amount, http_error...). НЕ деактивуємо — це не вина партнера.
                        $reason = is_array($result) ? (string)($result['reason'] ?? 'stripe_error') : 'stripe_error';

                        // Розрізняємо ТИМЧАСОВІ та ПОСТІЙНІ помилки (інверсія: блокуємо все,
                        // крім відомих тимчасових). Тимчасові помилки приходять як Stripe error.type
                        // без code, тож $reason дорівнює саме цим рядкам — їх НЕ блокуємо, наступний
                        // тік крона повторить спробу. Усе інше (будь-який invalid_request-код,
                        // resource_missing, no_stripe_method, конфіг/інтеграція) вважаємо постійним
                        // і ставимо анти-спам замок, щоб не смикати Stripe щотіка.
                        // Прим.: card_error сюди не доходить — він повертається як 'declined' вище.
                        $temporary_errors = ['api_connection_error', 'rate_limit_error', 'api_error', 'http_error', 'lock_timeout'];
                        if (!in_array($reason, $temporary_errors, true)) {
                            LR_Billing::set_notification_flag($partner_id, 'notified_charge_failed');
                        }

                        $err_code = ($reason === 'no_stripe_method' || $reason === 'stripe_not_configured')
                            ? 'STRIPE_NOT_CONFIGURED'
                            : 'STRIPE_ERROR';

                        LR_Billing::log_error($partner_id, $err_code, 'error',
                            'Stripe auto-charge помилка: ' . $reason, ['source' => 'billing_cron']);

                        return 'error';
                }
            } finally {
                // 5) Завжди знімаємо лок
                delete_transient($lock_key);
            }
        }

        /* ============================================================
         * Прапорці сповіщень
         * ============================================================ */

        /** Скинути всі прапорці сповіщень партнера (після реактивації / успішного charge) */
        private static function reset_all_notifications(int $partner_id): void
        {
            LR_Billing::reset_notification_flag($partner_id, 'notified_low_balance');
            LR_Billing::reset_notification_flag($partner_id, 'notified_stopped');
            LR_Billing::reset_notification_flag($partner_id, 'notified_admin_negative');
            LR_Billing::reset_notification_flag($partner_id, 'notified_charge_failed');
        }
    }
}
