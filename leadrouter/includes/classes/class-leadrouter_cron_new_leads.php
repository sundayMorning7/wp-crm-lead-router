<?php
if (!class_exists('LeadRouter_Cron_New_Leads')) {

    class LeadRouter_Cron_New_Leads
    {

        const CRON_HOOK = 'leadrouter_cron_dispatch_new_lead';
        const LOCK_KEY = 'leadrouter_cron_new_lead_lock';
        const STATUS_NEW = 'new';        // що беремо
        const STATUS_BUSY = 'processing_newcron'; // проміжний
        const STATUS_OK = 'sent';       // після успішної відправки
        const STATUS_FAIL = 'error';      // якщо впало
        const STATUS_START_ID    = 1;

        /** Вікно пошуку дублів за замовчуванням, у добах (EST) */
        const DUPLICATE_WINDOW_DAYS = 30;

        public static function init()
        {
            add_filter('cron_schedules', [__CLASS__, 'add_every_minute_schedule']);
            add_action('wp', [__CLASS__, 'schedule_event']);
            add_action(self::CRON_HOOK, [__CLASS__, 'run']);
        }

        public static function add_every_minute_schedule($schedules)
        {
            if (!isset($schedules['every_minute'])) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display' => __('Every Minute (LeadRouter)', 'leadrouter'),
                ];
            }
            return $schedules;
        }

        public static function schedule_event()
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, 'every_minute', self::CRON_HOOK);
            }
        }

        public static function run()
        {
            global $wpdb;

            // простий лок, щоб крони не накладались
            if (get_transient(self::LOCK_KEY)) {
                return;
            }
            set_transient(self::LOCK_KEY, 1, 55);

            $table = $wpdb->prefix . 'leadrouter_leads';

            // 1) беремо рівно один lead зі статусом new
            $lead = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
                     WHERE status = %s
                       AND id > %d
                     ORDER BY created_at ASC
                     LIMIT 1",
                    self::STATUS_NEW,
                    self::STATUS_START_ID
                ),
                ARRAY_A
            );


            if (!$lead) {
                delete_transient(self::LOCK_KEY);
                return;
            }

            $lead_id = (int)$lead['id'];

            // Проверка: если имя начинается с 'test', не отправлять лид партнёрам
            $lead_name = isset($lead['name']) ? trim(strtolower($lead['name'])) : '';
            if (strpos($lead_name, 'test') === 0) {
                // Просто помечаем как 'sent', не отправляем
                $wpdb->update(
                    $table,
                    [ 'status' => self::STATUS_OK ],
                    [ 'id' => $lead_id ],
                    [ '%s' ],
                    [ '%d' ]
                );

                $log_file = WP_CONTENT_DIR . '/leadrouter-cron.log';
                $log_payload = [
                    'timestamp' => current_time('mysql'),
                    'lead_id'   => $lead_id,
                    'result'    => 'Skipped test lead',
                ];
                file_put_contents(
                    $log_file,
                    json_encode($log_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                    FILE_APPEND
                );

                delete_transient(self::LOCK_KEY);
                return;
            }

            // 1.1) Анти-дубль по телефону/email. Вимкнено за замовчуванням —
            // при вимкненому чекбоксі жодного запиту в БД не робимо.
            if (self::duplicate_check_enabled()) {
                $dup = self::find_duplicate_origin($lead);
                if ($dup !== null) {
                    self::mark_as_duplicate($lead_id, $dup);
                    // далі лід сам підхопить крон помилкових лідів і відвезе
                    // його у «Групу для помилкових статусів»
                    delete_transient(self::LOCK_KEY);
                    return;
                }
            }

           // 2) відмічаємо, що його вже обробляємо


            $wpdb->update(
                $table,
                ['status' => self::STATUS_BUSY],
                ['id' => $lead_id],
                ['%s'],
                ['%d']
            );

            // 3) Відправка через Flow
            $result = LeadRouter_Flow::dispatch_broadcast( $lead_id, [
                'group_meta_key'   => '_leadrouter_partner_group',
                'statuses'         => ['queued', 'sent', 'accepted'],
                'initial_status'   => 'sent',
                'dispatch_method'  => 'auto_cron_new_lead',
                'queue_if_closed'  => true,
            ]);



            delete_transient(self::LOCK_KEY);
        }

        /* ===================== АНТИ-ДУБЛЬ ===================== */

        /**
         * Публічна перевірка дубля для ручної відправки: НЕ змінює статус ліда,
         * лише повертає оригінал, щоб UI спитав підтвердження.
         * null — не дубль, перевірку вимкнено або це test*-лід (як у крона).
         *
         * @return array{lead_id:int, matched_by:string}|null
         */
        public static function duplicate_probe(int $lead_id): ?array
        {
            global $wpdb;

            if ($lead_id <= 0 || !self::duplicate_check_enabled()) {
                return null;
            }

            $lead = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}leadrouter_leads WHERE id = %d",
                $lead_id
            ), ARRAY_A);
            if (!$lead) {
                return null;
            }

            $name = trim(strtolower((string)($lead['name'] ?? '')));
            if (strpos($name, 'test') === 0) {
                return null;
            }

            return self::find_duplicate_origin($lead);
        }

        /** Чи увімкнена перевірка дублів (Налаштування → Dispatch) */
        private static function duplicate_check_enabled(): bool
        {
            if (!function_exists('carbon_get_theme_option')) {
                return false;
            }

            return (bool) carbon_get_theme_option('leadrouter_duplicate_check_enabled');
        }

        /** Вікно пошуку дублів у добах (EST) */
        private static function duplicate_window_days(): int
        {
            $days = function_exists('carbon_get_theme_option')
                ? (int) carbon_get_theme_option('leadrouter_duplicate_window_days')
                : 0;

            return $days > 0 ? $days : self::DUPLICATE_WINDOW_DAYS;
        }

        /**
         * Шукає лід-оригінал за останні N діб: збіг по phone_norm АБО email_norm.
         *
         * Два окремі запити замість одного з OR — щоб кожен гарантовано йшов
         * по своєму індексу (idx_phone_norm / idx_email_norm), без index_merge.
         *
         * Оригіналом вважаємо СТАРІШИЙ лід (id менший): інакше два однакові
         * ліди, що прийшли поспіль, позначили б дублем один одного.
         *
         * @return array{lead_id:int, matched_by:string}|null
         */
        private static function find_duplicate_origin(array $lead): ?array
        {
            global $wpdb;

            $table   = $wpdb->prefix . 'leadrouter_leads';
            $lead_id = (int)$lead['id'];

            $phone_norm = leadrouter_phone_norm_value($lead['phone'] ?? '');
            $email_norm = leadrouter_email_norm_value($lead['email'] ?? '');

            // Обидва поля порожні — перевіряти нічого
            if ($phone_norm === null && $email_norm === null) {
                return null;
            }

            // Лід міг бути створений до апгрейду (або в обхід create_lead_simple) —
            // дозаповнюємо norm-колонки, щоб він теж брав участь у майбутніх перевірках
            if ((string)($lead['phone_norm'] ?? '') !== (string)$phone_norm
                || (string)($lead['email_norm'] ?? '') !== (string)$email_norm) {
                $wpdb->update(
                    $table,
                    ['phone_norm' => $phone_norm, 'email_norm' => $email_norm],
                    ['id' => $lead_id],
                    ['%s', '%s'],
                    ['%d']
                );
            }

            $since = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))
                ->modify('-' . self::duplicate_window_days() . ' days')
                ->format('Y-m-d H:i:s');

            $checks = [
                ['col' => 'phone_norm', 'val' => $phone_norm, 'by' => 'phone'],
                ['col' => 'email_norm', 'val' => $email_norm, 'by' => 'email'],
            ];

            foreach ($checks as $check) {
                if ($check['val'] === null || $check['val'] === '') {
                    continue;
                }

                $found = (int)$wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$table}
                          WHERE {$check['col']} = %s
                            AND id < %d
                            AND created_at >= %s
                          ORDER BY id DESC
                          LIMIT 1",
                        $check['val'],
                        $lead_id,
                        $since
                    )
                );

                if ($found > 0) {
                    return ['lead_id' => $found, 'matched_by' => $check['by']];
                }
            }

            return null;
        }

        /**
         * Позначає лід дублем: статус error (його підбере крон помилкових лідів
         * і force-відправить у «сміттєву» групу) + подія в leadrouter_logs.
         *
         * @param array{lead_id:int, matched_by:string} $dup
         */
        private static function mark_as_duplicate(int $lead_id, array $dup): void
        {
            global $wpdb;

            $table = $wpdb->prefix . 'leadrouter_leads';
            $now   = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');

            $wpdb->update(
                $table,
                [
                    'status'          => self::STATUS_FAIL,
                    'response_status' => 'duplicate',
                    'last_error_code' => 'duplicate_lead',
                    'last_error_at'   => $now,
                ],
                ['id' => $lead_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );

            if (class_exists('LeadRouter_Flow')) {
                LeadRouter_Flow::log_event($lead_id, 'duplicate_detected', [
                    'duplicate_of_lead_id' => (int)$dup['lead_id'],
                    'matched_by'           => (string)$dup['matched_by'],
                    'window_days'          => self::duplicate_window_days(),
                ]);
            }
        }
    }
}

// Підключити десь після завантаження плагіна:

