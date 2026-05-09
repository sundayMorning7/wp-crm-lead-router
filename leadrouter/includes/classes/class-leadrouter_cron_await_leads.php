<?php
if ( ! class_exists( 'LeadRouter_Cron_Await_Leads' ) ) {

    class LeadRouter_Cron_Await_Leads {

        const CRON_HOOK       = 'leadrouter_cron_dispatch_await_lead';
        const LOCK_KEY        = 'leadrouter_cron_await_lock';
        const OPTION_NEXT_TS  = 'leadrouter_cron_await_next_ts';
        const STATUS_AWAIT    = 'await';
        const STATUS_START_ID    = 1;
        const STATUS_OK = 'sent';       // після успішної відправки

        public static function init() {
            add_filter( 'cron_schedules', [ __CLASS__, 'add_every_minute_schedule' ] );
            add_action( 'wp', [ __CLASS__, 'schedule_event' ] );
            add_action( self::CRON_HOOK, [ __CLASS__, 'run' ] );
        }

        public static function add_every_minute_schedule( $schedules ) {
            if ( ! isset( $schedules['every_minute'] ) ) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display'  => __( 'Every Minute (LeadRouter)', 'leadrouter' ),
                ];
            }
            return $schedules;
        }

        public static function schedule_event() {
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + 60, 'every_minute', self::CRON_HOOK );
            }
        }

        public static function run() {
            global $wpdb;

/*
            $log_file = WP_CONTENT_DIR . '/leadrouter-cronawait.log';

            file_put_contents(
                $log_file,
                'First start \n',
                FILE_APPEND
            );*/


            // простий лок, щоб не було накладень
            if ( get_transient( self::LOCK_KEY ) ) {
                return;
            }
            set_transient( self::LOCK_KEY, 1, 55 );

            $now     = time();
            $next_ts = (int) get_option( self::OPTION_NEXT_TS, 0 );

            $min = (int) carbon_get_theme_option('leadrouter_pause_min');
            $max = (int) carbon_get_theme_option('leadrouter_pause_max');

            if ($min <= 0) {
                $min = 10;
            }

            if ($max <= 0) {
                $max = 20;
            }

            if ($min > $max) {
                $tmp = $min;
                $min = $max;
                $max = $tmp;
            }


            // якщо немає запланованого часу — ставимо новий інтервал і виходимо
            if ( ! $next_ts ) {
                $delay_min = rand( $min, $max );
                $next_ts   = $now + $delay_min * 60;
                update_option( self::OPTION_NEXT_TS, $next_ts );
                delete_transient( self::LOCK_KEY );
                return;
            }


            // ще не настав час — просто чекаємо
            if ( $now < $next_ts ) {
                delete_transient( self::LOCK_KEY );
                return;
            }

/*
            file_put_contents(
                $log_file,
                'before db'. json_encode(array(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );*/

            $table = $wpdb->prefix . 'leadrouter_leads';

            // 1) беремо один лід зі статусом await
            $lead = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table}
         WHERE status = %s AND id > %d
         ORDER BY created_at ASC
         LIMIT 1",
                    self::STATUS_AWAIT,
                    self::STATUS_START_ID
                ),
                ARRAY_A
            );

/*
            file_put_contents(
                'test3',
                'after db'. json_encode($lead, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );
*/

            if ( ! $lead ) {
                // немає лідів в await → обнуляємо таймер, щоб при появі нових все починалось з нуля
                update_option( self::OPTION_NEXT_TS, 0 );
                delete_transient( self::LOCK_KEY );
                return;
            }

            $lead_id = (int) $lead['id'];

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

            // 2) відправка через Flow
            $result = LeadRouter_Flow::dispatch_broadcast( $lead_id, [
                'group_meta_key'   => '_leadrouter_partner_group',
                'statuses'         => [ 'queued', 'sent', 'accepted' ],
                'initial_status'   => 'sent',
                'dispatch_method'  => 'auto_cron_await_lead',
                'queue_if_closed'  => true,
            ] );



            $lead_status = $result['summary']['lead_status'] ?? 'error';


/*
            file_put_contents(
                $log_file,
                'after broadcast'. json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND
            );

            // 3) оновлюємо поле status ліда згідно summary
            $wpdb->update(
                $table,
                [ 'status' => $lead_status ],
                [ 'id' => $lead_id ],
                [ '%s' ],
                [ '%d' ]
            );*/

            // 4) інтервал:
            // - для skipped / error — "обнуляємо" (на наступній хвилині призначиться новий інтервал)
            // - для sent / await — ставимо новий рандом 15–30 хв
            if ( in_array( $lead_status, [ 'skipped', 'error' ], true ) ) {
                update_option( self::OPTION_NEXT_TS, 0 );
            } else {
                $delay_min = rand( $min, $max );
                $next_ts   = $now + $delay_min * 60;
                update_option( self::OPTION_NEXT_TS, $next_ts );
            }

            delete_transient( self::LOCK_KEY );
        }
    }
}

// десь у bootstrap плагіну:
