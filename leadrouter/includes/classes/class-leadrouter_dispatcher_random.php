<?php

/**
 * RANDOM (weighted) з урахуванням ДЕННОГО ліміту та часової зони EST.
 * Якщо група заповнила денний ліміт — робимо репік, доки знайдемо доступну.
 */
class LeadRouter_Dispatcher_Random
{

    /**
     * Призначити групу (рандом/ваги) з урахуванням лімітів на СЬОГОДНІ (EST).
     */
    public static function assign_group_for_lead_random(int $lead_id, array $opts = [])
    {
        global $wpdb;

        $dry = !empty($opts['dry_run']); // <-- якщо true — нічого не пишемо у БД

        $table_leads = $wpdb->prefix . 'leadrouter_leads';
        $table_groups = $wpdb->prefix . 'leadrouter_groups';
        $table_logs = $wpdb->prefix . 'leadrouter_logs';

        if (!$dry) {
            $lead = $wpdb->get_row(
                $wpdb->prepare("SELECT id FROM {$table_leads} WHERE id = %d", $lead_id),
                ARRAY_A
            );
            if (!$lead) {
                return new WP_Error('lead_not_found', 'Lead not found');
            }
        }

        $now = isset($opts['datetime']) && $opts['datetime'] instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($opts['datetime'])->setTimezone(self::tz_est())
            : self::est_now();

        $dowN = 1; //(int)$now->format('N');
        [$day_start, $day_end] = self::today_window_mysql_est($now);

        $groups = $wpdb->get_results("
            SELECT id, post_id, name, weight,
                   weight_1, weight_2, weight_3, weight_4, weight_5, weight_6, weight_7
            FROM {$table_groups}
            WHERE active = 1
        ", ARRAY_A);
        if (empty($groups)) return new WP_Error('no_active_groups', 'No active groups available');

        $items = [];
        $totalW = 0;
        foreach ($groups as $g) {
            $w = self::compute_effective_weight($g, $dowN);
            if ($w > 0) {
                $items[] = [
                    'group_id' => (int)$g['id'],
                    'group_post_id' => (int)$g['post_id'],
                    'name' => (string)$g['name'],
                    'weight' => (int)$w,
                ];
                $totalW += (int)$w;
            }
        }



        if ($totalW <= 0) return new WP_Error('weight_zero', 'All group weights are zero for today (EST).');

        $assigned = self::fetch_today_assignments($table_logs, $day_start, $day_end);

        $max_attempts = max(1, count($items));
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $pick = self::weighted_pick($items, $totalW);
            if (!$pick) break;

            $used = (int)($assigned[$pick['group_id']] ?? 0);
            if ($used < $pick['weight']) {
                if ($dry) {
                    // лише повертаємо підбір без змін у БД
                    return $pick;
                }

                $assigned_at = self::est_now()->format('Y-m-d H:i:s');

                $wpdb->update(
                    $table_leads,
                    [
                        'sent_at' => $assigned_at,
                        'response_status' => 'group_assigned',
                    ],
                    ['id' => $lead_id],
                    ['%s', '%s'],
                    ['%d']
                );

                $wpdb->insert(
                    $table_logs,
                    [
                        'lead_id' => $lead_id,
                        'partner_id' => 0,
                        'group_id' => $pick['group_id'],
                        'assigned_at' => $assigned_at,
                        'status' => 'group_assigned',
                    ],
                    ['%d', '%d', '%d', '%s', '%s']
                );

                return $pick;
            }

            foreach ($items as $idx => $it) {
                if ($it['group_id'] === $pick['group_id']) {
                    $totalW -= $it['weight'];
                    unset($items[$idx]);
                    $items = array_values($items);
                    break;
                }
            }
            if ($totalW <= 0 || empty($items)) break;
        }

        return new WP_Error('no_capacity_today', 'All groups reached today’s capacity (EST).');
    }

    // ===== ЛОГІКА ВІДПРАВОК ПАРТНЕРАМ: ЛОГУВАННЯ СПРОБ =====

    /**
     * Залогувати спробу відправки ліда конкретному партнеру (у EST).
     *
     * @param int $lead_id
     * @param int $group_id
     * @param int $partner_id
     * @param string $status queued|sent|accepted|rejected|failed|timeout|error
     * @param array $args ['attempt_no'=>1,'dispatch_method'=>'script','request'=>[],'response'=>[],'http_code'=>200,'error_message'=>'...']
     */
    public static function log_partner_attempt(int $lead_id, int $group_id, int $partner_id, string $status, array $args = []): void
    {
        global $wpdb;
        $table_partner_logs = $wpdb->prefix . 'leadrouter_partner_logs';

        $attempt_no = isset($args['attempt_no']) ? (int)$args['attempt_no'] : 1;
        $dispatch_method = isset($args['dispatch_method']) ? (string)$args['dispatch_method'] : 'script';
        $http_code = isset($args['http_code']) ? (int)$args['http_code'] : null;
        $error_message = isset($args['error_message']) ? (string)$args['error_message'] : null;

        $request_json = isset($args['request']) ? wp_json_encode($args['request'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $response_json = isset($args['response']) ? wp_json_encode($args['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $attempted_at = self::est_now()->format('Y-m-d H:i:s'); // EST

        $wpdb->insert(
            $table_partner_logs,
            [
                'lead_id' => $lead_id,
                'group_id' => $group_id,
                'partner_id' => $partner_id,
                'attempt_no' => $attempt_no,
                'attempted_at' => $attempted_at,
                'dispatch_method' => $dispatch_method,
                'request_json' => $request_json,
                'response_json' => $response_json,
                'http_code' => $http_code,
                'status' => $status,
                'error_message' => $error_message,
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    // ===== ДОПОМІЖНЕ =====

    /** TZ EST з урахуванням DST */
    private static function tz_est(): DateTimeZone
    {
        return new DateTimeZone('America/New_York');
    }

    /** Поточний час у EST */
    private static function est_now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::tz_est());
    }

    /** Вікно «сьогодні» у EST для BETWEEN */
    private static function today_window_mysql_est(DateTimeInterface $now): array
    {
        $tz = self::tz_est();
        $s = (new DateTimeImmutable($now->format('Y-m-d 00:00:00'), $tz));
        $e = (new DateTimeImmutable($now->format('Y-m-d 23:59:59'), $tz));
        return [$s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')];
    }

    /** Ефективна вага на день тижня */
    private static function compute_effective_weight(array $row, int $dow): int
    {
        $base = isset($row['weight']) ? (int)$row['weight'] : 0;
        $key = 'weight_' . $dow;
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return max(0, (int)$row[$key]);
        }
        return max(0, $base);
    }

    /** Зважений випадковий вибір */
    private static function weighted_pick(array $items, int $totalW): ?array
    {
        $r = wp_rand(1, max(1, (int)$totalW));
        $c = 0;
        foreach ($items as $it) {
            $c += (int)$it['weight'];
            if ($r <= $c) {
                return $it;
            }
        }
        return end($items) ?: null;
    }

    /** Підрахунок сьогоднішніх призначень у логах (EST-день) */
    private static function fetch_today_assignments(string $table_logs, string $day_start, string $day_end): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare("
                SELECT group_id, COUNT(*) AS cnt
                FROM {$table_logs}
                WHERE status = 'group_assigned'
                  AND assigned_at BETWEEN %s AND %s
                GROUP BY group_id
            ", $day_start, $day_end),
            ARRAY_A
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['group_id']] = (int)$r['cnt'];
        }
        return $map;
    }
}
