<?php
if (!defined('ABSPATH')) exit;

/**
 * LeadRouter_Cron_Watchdog — вотчдог для «зависших» processing_* лідів.
 *
 * Якщо лід залишається зі статусом processing_* більше STUCK_THRESHOLD_SEC секунд
 * (наприклад, через краш cron-воркера), вотчдог:
 *  1. Знаходить такі ліди.
 *  2. Повертає їх у вихідний статус ('new' для processing_newcron,
 *     'await' для processing_awaitcron), щоб наступний цикл їх підхопив.
 *  3. Записує подію в leadrouter_logs через LeadRouter_Flow::log_event().
 *
 * Запускається щохвилини разом з іншими cron-воркерами.
 */
if (!class_exists('LeadRouter_Cron_Watchdog')) {

    class LeadRouter_Cron_Watchdog
    {
        const CRON_HOOK         = 'leadrouter_cron_watchdog';
        const LOCK_KEY          = 'leadrouter_cron_watchdog_lock';
        const STUCK_THRESHOLD_SEC = 300; // 5 хвилин

        /** Відображення processing_* → статус для відновлення */
        private const RECOVERY_MAP = [
            'processing_newcron'   => 'new',
            'processing_awaitcron' => 'await',
        ];

        public static function init(): void
        {
            add_filter('cron_schedules', [__CLASS__, 'add_every_minute_schedule']);
            add_action('wp', [__CLASS__, 'schedule_event']);
            add_action(self::CRON_HOOK, [__CLASS__, 'run']);
        }

        public static function add_every_minute_schedule(array $schedules): array
        {
            if (!isset($schedules['every_minute'])) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display'  => __('Every Minute (LeadRouter)', 'leadrouter'),
                ];
            }
            return $schedules;
        }

        public static function schedule_event(): void
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, 'every_minute', self::CRON_HOOK);
            }
        }

        public static function run(): void
        {
            if (get_transient(self::LOCK_KEY)) {
                return;
            }
            set_transient(self::LOCK_KEY, 1, 55);

            try {
                self::recover_stuck_leads();
            } finally {
                delete_transient(self::LOCK_KEY);
            }
        }

        private static function recover_stuck_leads(): void
        {
            global $wpdb;
            $table = $wpdb->prefix . 'leadrouter_leads';

            $processing_statuses = array_keys(self::RECOVERY_MAP);
            $placeholders = implode(',', array_fill(0, count($processing_statuses), '%s'));

            $threshold = self::now_est()->modify(sprintf('-%d seconds', self::STUCK_THRESHOLD_SEC));
            $threshold_str = $threshold->format('Y-m-d H:i:s');

            // Знаходимо завислі ліди:
            //  - status LIKE 'processing_%'
            //  - status_updated_at перевищив поріг (або NULL і created_at теж давній)
            $sql = "
                SELECT id, status, status_updated_at, created_at
                FROM {$table}
                WHERE status IN ($placeholders)
                  AND (
                      status_updated_at <= %s
                      OR (status_updated_at IS NULL AND created_at <= %s)
                  )
                ORDER BY id ASC
                LIMIT 20
            ";
            $params = array_merge($processing_statuses, [$threshold_str, $threshold_str]);

            $stuck = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

            if (empty($stuck)) {
                return;
            }

            foreach ($stuck as $lead) {
                $lead_id       = (int)$lead['id'];
                $current_status = (string)$lead['status'];
                $recover_status = self::RECOVERY_MAP[$current_status] ?? 'new';

                // Безпечне відновлення: оновлюємо тільки якщо статус не змінився
                $rows = $wpdb->query(
                    $wpdb->prepare(
                        "UPDATE {$table}
                         SET status = %s, response_status = %s, status_updated_at = %s
                         WHERE id = %d AND status = %s",
                        $recover_status,
                        $recover_status,
                        self::now_est()->format('Y-m-d H:i:s'),
                        $lead_id,
                        $current_status
                    )
                );

                if ($rows > 0 && class_exists('LeadRouter_Flow')) {
                    LeadRouter_Flow::log_event($lead_id, 'watchdog_recovery', [
                        'from_status'    => $current_status,
                        'to_status'      => $recover_status,
                        'stuck_since'    => (string)($lead['status_updated_at'] ?? $lead['created_at']),
                        'threshold_sec'  => self::STUCK_THRESHOLD_SEC,
                    ]);
                    LeadRouter_Flow::log_info('watchdog: recovered stuck lead', [
                        'lead_id'     => $lead_id,
                        'from_status' => $current_status,
                        'to_status'   => $recover_status,
                    ]);
                }
            }
        }

        private static function now_est(): DateTimeImmutable
        {
            return new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
        }
    }
}
