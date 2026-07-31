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

            // 1) Вибираємо один лід зі статусом new
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

            // 2) Атомарне захоплення: UPDATE WHERE id=X AND status='new'
            //    Захищає від гонки, якщо два воркери запустились одночасно
            $now_est = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');
            $claimed = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s, status_updated_at = %s WHERE id = %d AND status = %s",
                    self::STATUS_BUSY,
                    $now_est,
                    $lead_id,
                    self::STATUS_NEW
                )
            );

            if (!$claimed) {
                // Лід вже захоплено іншим воркером
                delete_transient(self::LOCK_KEY);
                return;
            }

            // Перевірка: якщо ім'я починається з 'test' — не відправляємо партнерам
            $lead_name = isset($lead['name']) ? trim(strtolower($lead['name'])) : '';
            if (strpos($lead_name, 'test') === 0) {
                $wpdb->update(
                    $table,
                    ['status' => self::STATUS_OK, 'status_updated_at' => $now_est],
                    ['id' => $lead_id],
                    ['%s', '%s'],
                    ['%d']
                );

                $log_file = WP_CONTENT_DIR . '/leadrouter-cron.log';
                $log_payload = [
                    'timestamp' => $now_est,
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

            // 3) Відправка через Flow
            LeadRouter_Flow::dispatch_broadcast($lead_id, [
                'group_meta_key'  => '_leadrouter_partner_group',
                'statuses'        => ['queued', 'sent', 'accepted'],
                'initial_status'  => 'sent',
                'dispatch_method' => 'auto_cron_new_lead',
                'queue_if_closed' => true,
            ]);

            delete_transient(self::LOCK_KEY);
        }
    }
}

// Підключити десь після завантаження плагіна:

