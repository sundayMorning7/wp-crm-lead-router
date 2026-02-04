<?php
/**
 * Weighted Round Robin dispatcher через eff + денні ліміти.
 * - Виключає групи, що вже вичерпали денний ліміт (weight_N).
 * - Оновлює eff тільки для вибраної групи.
 * - Пише лог у leadrouter_logs.
 * - Обнуляє eff, якщо настав новий день у EST.
 *   алгоритм Weighted Round Robin (WRR)
 */
class LeadRouter_Dispatcher_Eff
{
    /**
     * Призначити групу для ліда
     *
     * @param int $lead_id
     * @param array $opts ['datetime'=>DateTimeInterface]
     * @return array{group_id:int, group_post_id:int, name:string, weight:int}|WP_Error
     */
    public static function assign_group_for_lead(int $lead_id, array $opts = [])
    {
        global $wpdb;



        $table_groups = $wpdb->prefix . 'leadrouter_groups';
        $table_logs   = $wpdb->prefix . 'leadrouter_logs';

        // Час у EST
        $now = isset($opts['datetime']) && $opts['datetime'] instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($opts['datetime'])->setTimezone(self::tz_est())
            : new DateTimeImmutable('now', self::tz_est());

        $dow = (int)$now->format('N'); // 1..7
        $assigned_at = $now->format('Y-m-d H:i:s');

        [$day_start, $day_end] = self::today_window_mysql_est($now);

        // === КРОК 0. Перевірка чи настав новий день ===
        self::reset_eff_if_new_day($now);

        // Витягнути всі активні групи
        $groups = $wpdb->get_results("
        SELECT id, post_id, name,
               eff,
               weight_1, weight_2, weight_3, weight_4, weight_5, weight_6, weight_7
        FROM {$table_groups}
        WHERE active = 1
    ", ARRAY_A);

        // Якщо явно вказана група — обмежуємося тільки нею
        $force_group_post_id = isset($opts['force_group_post_id']) ? (int) $opts['force_group_post_id'] : 0;
        if ($force_group_post_id > 0) {
            $groups = array_values(array_filter($groups, function ($g) use ($force_group_post_id) {
                return (int) $g['post_id'] === $force_group_post_id;
            }));

            if (empty($groups)) {
                return new WP_Error(
                    'forced_group_not_found',
                    'Requested group is not active or does not exist',
                    ['force_group_post_id' => $force_group_post_id]
                );
            }
        }

        if (empty($groups)) {
            return new WP_Error('no_active_groups', 'No active groups available');
        }

        // === КРОК 1. Денна квота ===
        // Рахуємо тільки статус group_assigned (без AK/HI).
        $rows = $wpdb->get_results(
            $wpdb->prepare("
            SELECT group_id, COUNT(*) AS cnt
            FROM {$table_logs}
            WHERE status = 'group_assigned'
              AND assigned_at BETWEEN %s AND %s
            GROUP BY group_id
        ", $day_start, $day_end),
            ARRAY_A
        ) ?: [];

        $assigned_today = [];
        foreach ($rows as $r) {
            $assigned_today[(int)$r['group_id']] = (int)$r['cnt'];
        }

        // КРОК 2. Обчислення eff_tmp
        $sumW = 0;
        $eligible = [];
        foreach ($groups as $g) {
            $w   = self::effective_weight_today($g, $dow);
            $cnt = $assigned_today[(int)$g['id']] ?? 0;

            if ($w > 0 && $cnt < $w) {
                $g['weight_today'] = (int)$w;
                $g['eff_tmp']      = (int)$g['eff'] + (int)$w;
                $eligible[] = $g;
                $sumW += (int)$w;
            }
        }

        if (empty($eligible)) {
            return new WP_Error('no_capacity_today', 'All groups reached today’s capacity (EST).');
        }
        if ($sumW <= 0) {
            return new WP_Error('weight_zero', 'All effective weights are zero for today (EST).');
        }

        // КРОК 3. Сортування за eff_tmp
        usort($eligible, fn($a, $b) => $b['eff_tmp'] <=> $a['eff_tmp']);

        // КРОК 4. Вибір групи з доступними партнерами
        $picked = null;
        $partners_for_pick = [];

        foreach ($eligible as $cand) {
            $partners = LeadRouter_Partners::available_in_group(
                (int)$cand['post_id'],
                [
                    'group_meta_key' => '_leadrouter_partner_group',
                    'statuses'       => ['queued', 'sent', 'accepted'],
                ]
            );

            if (!empty($partners)) {
                $picked = $cand;
                $partners_for_pick = $partners;
                break;
            }
        }

        if (!$picked) {
            return new WP_Error('no_partners_in_all_groups', 'No available partners found in any eligible group right now.');
        }

        // КРОК 5. Визначення виняткових штатів AK/HI
        $from_state = strtoupper(trim((string)($opts['lead_from_state'] ?? '')));
        $to_state   = strtoupper(trim((string)($opts['lead_to_state'] ?? '')));
        $excluded   = ['AK', 'HI'];
        $isExcludedState = in_array($from_state, $excluded, true) || in_array($to_state, $excluded, true);

        // КРОК 6. Оновлення eff — тільки якщо НЕ AK/HI
        if (!$isExcludedState) {
            $newEff = (int)$picked['eff_tmp'] - (int)$sumW;
            $wpdb->update(
                $table_groups,
                [
                    'eff'        => $newEff,
                    'updated_at' => $assigned_at,
                ],
                ['id' => (int)$picked['id']],
                ['%d','%s'],
                ['%d']
            );
        }

        // КРОК 7. Логування — статус залежить від винятку, але partner_id знову чистий список
        $wpdb->insert(
            $table_logs,
            [
                'lead_id'     => (int)$lead_id,
                'partner_id'  => wp_json_encode(array_column($partners_for_pick, 'post_id')), // 👈 як було раніше
                'group_id'    => (int)$picked['id'],
                'assigned_at' => $assigned_at,
                'status'      => $isExcludedState ? 'group_assigned_excluded_state' : 'group_assigned',
            ],
            ['%d','%s','%d','%s','%s']
        );

        // КРОК 8. Повернення результату
        return [
            'group_id'      => (int)$picked['id'],
            'group_post_id' => (int)$picked['post_id'],
            'name'          => (string)$picked['name'],
            'weight'        => (int)$picked['weight_today'],
        ];
    }




    // ===== NEW: Reset eff if new day =====
    private static function reset_eff_if_new_day(DateTimeImmutable $now): void
    {
        global $wpdb;
        $table_groups = $wpdb->prefix . 'leadrouter_groups';

        $today = $now->format('Y-m-d');

        // Вибираємо одну групу щоб подивитися дату
        $last = $wpdb->get_var("SELECT MAX(updated_at) FROM {$table_groups}");
        if ($last) {
            $lastDate = (new DateTimeImmutable($last, self::tz_est()))->format('Y-m-d');
            if ($lastDate !== $today) {
                // Скинути eff у всіх
                $wpdb->query("UPDATE {$table_groups} SET eff = 0");
            }
        }
    }

    // ===== helpers =====
    private static function tz_est(): DateTimeZone
    {
        return new DateTimeZone('America/New_York');
    }

    private static function today_window_mysql_est(DateTimeInterface $now): array
    {
        $tz = self::tz_est();
        $s  = new DateTimeImmutable($now->format('Y-m-d 00:00:00'), $tz);
        $e  = new DateTimeImmutable($now->format('Y-m-d 23:59:59'), $tz);
        return [$s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')];
    }

    private static function effective_weight_today(array $row, int $dow): int
    {
        $key = 'weight_' . $dow;
        return isset($row[$key]) ? max(0, (int)$row[$key]) : 0;
    }

}
