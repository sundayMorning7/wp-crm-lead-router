<?php
if ( ! class_exists( 'LeadRouter_Cron_Error_Leads' ) ) {

    class LeadRouter_Cron_Error_Leads {

        const CRON_HOOK       = 'leadrouter_cron_dispatch_error_lead';
        const LOCK_KEY        = 'leadrouter_cron_error_lock';
        const STATUS_ERRORS = ['error', 'state_error', 'hard_fail', 'retryable_fail'];
        const STATUS_START_ID = 1;

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

            // лок щоб не було накладень
            if ( get_transient( self::LOCK_KEY ) ) {
                return;
            }

            set_transient( self::LOCK_KEY, 1, 55 );

            $force_group_post_id = (int) carbon_get_theme_option( 'leadrouter_error_group_id' );

            if ( $force_group_post_id <= 0 ) {
                delete_transient( self::LOCK_KEY );
                return;
            }

            $table = $wpdb->prefix . 'leadrouter_leads';

            $statuses = self::STATUS_ERRORS;


            $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

            $sql = "
                SELECT * FROM {$table}
                WHERE status IN ($placeholders)
                AND id > %d
                ORDER BY created_at ASC
                LIMIT 1
            ";

            $params = array_merge($statuses, [ self::STATUS_START_ID ]);

            $lead = $wpdb->get_row(
                $wpdb->prepare($sql, ...$params),
                ARRAY_A
            );


            if ( ! $lead ) {
                delete_transient( self::LOCK_KEY );
                return;
            }

            $lead_id = (int) $lead['id'];

            $result = LeadRouter_Flow::dispatch_broadcast( $lead_id, [
                'group_meta_key'      => '_leadrouter_partner_group',
                'statuses'            => [ 'queued', 'sent', 'accepted' ],
                'initial_status'      => 'sent',
                'dispatch_method'     => 'auto_cron_error_lead',
                'queue_if_closed'     => true,
                'force_group_post_id' => $force_group_post_id,
            ] );

            $lead_status = $result['summary']['lead_status'] ?? 'error';

// якщо партнер не прийняв lead / endpoint лежить / dispatch зламався
            if ( in_array( $lead_status, [ 'error', 'skipped' ], true ) ) {

                // 1. Явно залишаємо lead в error і ставимо причину
                $wpdb->update(
                    $table,
                    [
                        'status'          => 'partner_error',
                        'response_status' => 'partner_down',
                    ],
                    [ 'id' => $lead_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );

                // 2. Додаємо запис у логи
                $logs_table = $wpdb->prefix . 'leadrouter_logs';

                $wpdb->insert(
                    $logs_table,
                    [
                        'lead_id'     => $lead_id,
                        'partner_id'  => 0,
                        'group_id'    => $force_group_post_id,
                        'assigned_at' => current_time( 'mysql' ),
                        'status'      => 'partner_down',
                    ],
                    [ '%d', '%d', '%d', '%s', '%s' ]
                );
            }

            delete_transient( self::LOCK_KEY );
        }
    }
}