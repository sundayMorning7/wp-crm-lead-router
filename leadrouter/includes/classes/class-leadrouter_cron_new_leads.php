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
                    705
                ),
                ARRAY_A
            );


            if (!$lead) {
                delete_transient(self::LOCK_KEY);
                return;
            }

            $lead_id = (int)$lead['id'];

            // 2) відмічаємо, що його вже обробляємо
            //
            /*
            $wpdb->update(
                $table,
                ['status' => self::STATUS_BUSY],
                ['id' => $lead_id],
                ['%s'],
                ['%d']
            );*/

            // 3) Відправка через Flow
            $result = LeadRouter_Flow::dispatch_broadcast( $lead_id, [
                'group_meta_key'   => '_leadrouter_partner_group',
                'statuses'         => ['queued', 'sent', 'accepted'],
                'initial_status'   => 'sent',
                'dispatch_method'  => 'auto_cron_new_lead',
                'queue_if_closed'  => true,
            ]);

            $log_file = WP_CONTENT_DIR . '/leadrouter-cron.log';
            file_put_contents(
                $log_file,
                json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );


            $lead_status = $result['summary']['lead_status'] ?? 'error';

// оновлюємо саме поле status ліда
            $wpdb->update(
                $table,
                [ 'status' => $lead_status ],
                [ 'id' => $lead_id ],
                [ '%s' ],
                [ '%d' ]
            );


            // формуємо JSON
            $log_payload = [
                'timestamp' => current_time('mysql'),
                'lead_id'   => $lead_id,
                'result'    => $result,   // весь масив
            ];



            file_put_contents(
                $log_file,
                json_encode($log_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );



            delete_transient(self::LOCK_KEY);
        }
    }
}

// Підключити десь після завантаження плагіна:

