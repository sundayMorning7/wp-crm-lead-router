<?php

/**
 * LeadRouter_Partners — фільтрація партнерів за часом (per-day start/end) та денними лімітами (per-day limit) у EST.
 *
 * Години роботи (per-day):
 *   _leadrouter_partner_mon_start / _leadrouter_partner_mon_end
 *   _leadrouter_partner_tue_start / _leadrouter_partner_tue_end
 *   ...
 *   _leadrouter_partner_sun_start / _leadrouter_partner_sun_end
 *
 * Ліміти (per-day):
 *   _leadrouter_partner_mon_limit, _leadrouter_partner_tue_limit, ..., _leadrouter_partner_sun_limit
 *
 * Примітка: якщо для дня відсутні start/end — вважаємо «відкрито завжди».
 * Якщо для дня відсутній limit — вважаємо 0 (сьогодні не приймає).
 */
class LeadRouter_Partners
{
    /** ===== ПУБЛІЧНИЙ API ===== */

    /**
     * Доступні партнери (відкриті зараз + мають денний ліміт > used_today).
     * @param array $opts {
     *   @type DateTimeInterface now
     *   @type array statuses   Статуси для підрахунку used_today. За замовчуванням: ['sent','accepted'].
     *   @type int[] partner_ids
     *   @type string lead_from_state  // опційно: EST дволітерний код штату ліда (from)
     *   @type string lead_to_state    // опційно: EST дволітерний код штату ліда (to)
     * }
     */
    public static function available(array $opts = []): array
    {
        $now = (isset($opts['now']) && $opts['now'] instanceof DateTimeInterface)
            ? DateTimeImmutable::createFromInterface($opts['now'])->setTimezone(self::tz())
            : self::now();

        $dow = (int)$now->format('N'); // 1..7 (Mon..Sun)
        [$day_start, $day_end] = self::today_window_mysql_est($now);

        // За замовчуванням без 'queued'
        $statuses = !empty($opts['statuses']) && is_array($opts['statuses'])
            ? array_values(array_filter(array_map('strval', $opts['statuses'])))
            : ['sent', 'accepted'];

        $partner_ids = !empty($opts['partner_ids'])
            ? array_values(array_filter(array_map('intval', $opts['partner_ids'])))
            : self::fetch_active_partner_ids();

        if (empty($partner_ids)) {
            return [];
        }

        $lead_from_state = strtoupper(trim((string)($opts['lead_from_state'] ?? '')));
        $lead_to_state   = strtoupper(trim((string)($opts['lead_to_state'] ?? '')));

        $partners = [];

        foreach ($partner_ids as $pid) {
            // Активність та базові умови
            if (!self::is_active($pid)) {
                continue;
            }

            $limit = self::limit_for_day($pid, $dow);
            if ($limit <= 0) {
                continue;
            }

            if (!self::is_open_now_per_day($pid, $now, $dow)) {
                continue;
            }

            $partners[$pid] = [
                'partner_id'       => (int)$pid,
                'post_id'          => (int)$pid,
                'name'             => get_the_title($pid) ?: ('Partner #' . $pid),
                'open_now'         => true,
                'limit_today'      => (int)$limit,
                'used_today'       => 0,
                'limit_left'       => 0,
                // 🔹 Прокидуємо обидва штати ліда (для фільтрації у Flow::filter_partner)
                'lead_from_state'  => $lead_from_state,
                'lead_to_state'    => $lead_to_state,
            ];
        }

        if (empty($partners)) {
            return [];
        }

        $used_map = self::fetch_used_today_map(array_keys($partners), $statuses, $day_start, $day_end);

        foreach ($partners as $pid => &$row) {
            $row['used_today'] = (int)($used_map[$pid] ?? 0);
            $row['limit_left'] = max(0, $row['limit_today'] - $row['used_today']);
            if ($row['limit_left'] <= 0) {
                unset($partners[$pid]);
            }
        }
        unset($row);

        return array_values($partners);
    }

    /**
     * Перевірити конкретного партнера по ID з урахуванням годин/лімітів на сьогодні.
     * Повертає row або null, якщо не підходить.
     */
    public static function check_partner(int $partner_id, array $opts = []): ?array
    {
        if ($partner_id <= 0) return null;

        $now = (isset($opts['now']) && $opts['now'] instanceof DateTimeInterface)
            ? DateTimeImmutable::createFromInterface($opts['now'])->setTimezone(self::tz())
            : self::now();

        $require_open  = array_key_exists('require_open', $opts)  ? (bool)$opts['require_open']  : true;
        $require_limit = array_key_exists('require_limit', $opts) ? (bool)$opts['require_limit'] : true;

        $dow = (int)$now->format('N'); // 1..7
        [$day_start, $day_end] = self::today_window_mysql_est($now);

        if (!self::is_active($partner_id)) return null;

        $limit = self::limit_for_day($partner_id, $dow);
        if ($limit <= 0 && $require_limit) return null;

        $open_now = self::is_open_now_per_day($partner_id, $now, $dow);
        if (!$open_now && $require_open) return null;

        // За замовчуванням без 'queued'
        $statuses = !empty($opts['statuses']) && is_array($opts['statuses'])
            ? array_values(array_filter(array_map('strval', $opts['statuses'])))
            : ['sent', 'accepted'];

        $used_map = self::fetch_used_today_map([$partner_id], $statuses, $day_start, $day_end);
        $used = (int)($used_map[$partner_id] ?? 0);

        $row = [
            'partner_id'  => (int)$partner_id,
            'post_id'     => (int)$partner_id,
            'name'        => get_the_title($partner_id) ?: ('Partner #' . $partner_id),
            'open_now'    => (bool)$open_now,
            'limit_today' => (int)$limit,
            'used_today'  => (int)$used,
            'limit_left'  => max(0, (int)$limit - (int)$used),
        ];

        if ($require_limit && $row['limit_left'] <= 0) return null;

        // Прокидаємо стейти, якщо передані в opts
        if (!empty($opts['lead_from_state'])) {
            $row['lead_from_state'] = strtoupper(trim((string)$opts['lead_from_state']));
        }
        if (!empty($opts['lead_to_state'])) {
            $row['lead_to_state'] = strtoupper(trim((string)$opts['lead_to_state']));
        }

        return $row;
    }

    /**
     * Доступні партнери конкретної групи (meta-зв’язок) з урахуванням годин/лімітів.
     * Прокидує lead_from_state/to_state через $opts далі в available().
     */
    public static function available_in_group(int $group_id, array $opts = []): array
    {
        if ($group_id <= 0) return [];

        $meta_key = !empty($opts['group_meta_key']) ? (string)$opts['group_meta_key'] : 'group_id';

        // Вибираємо ID партнерів групи + опубліковані + активні (єдиний ключ активності)
        $q = new WP_Query([
            'post_type'      => 'leadrouter_partner',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => $meta_key,
                    'value' => $group_id,
                ],
                [
                    'key'     => '_leadrouter_partner_active',
                    'value'   => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        $ids = $q->posts ?: [];
        if (empty($ids)) return [];

        $opts2 = $opts;
        $opts2['partner_ids'] = $ids;

        // lead_from_state/to_state якщо були в $opts — дістануться у available() і впишуться в кожен row.
        return self::available($opts2);
    }

    /** ===== HELPERS ===== */

    /** Публікація + _leadrouter_partner_active=1 */
    private static function is_active(int $partner_post_id): bool
    {
        $status = get_post_status($partner_post_id);
        if ($status !== 'publish') return false;
        $active = get_post_meta($partner_post_id, '_leadrouter_partner_active', true);
        return ($active === '' || $active === null) ? true : ($active == '1');
    }

    /** Усі опубліковані + активні партнери за єдиним ключем */
    private static function fetch_active_partner_ids(): array
    {
        $q = new WP_Query([
            'post_type'      => 'leadrouter_partner',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => '_leadrouter_partner_active', 'value' => '1'],
                ['key' => '_leadrouter_partner_active', 'compare' => 'NOT EXISTS'],
            ],
        ]);
        return $q->posts ?: [];
    }

    /** Карта partner_id => COUNT(*) (attempts today у заданих статусах) */
    private static function fetch_used_today_map(array $partner_ids, array $statuses, string $day_start, string $day_end): array
    {
        if (empty($partner_ids)) return [];
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_partner_logs';

        $ids_in = implode(',', array_map('intval', $partner_ids));
        $statuses_in = implode(',', array_map(static fn($s) => "'" . esc_sql($s) . "'", $statuses));

        // Лічимо лише ті статуси, що вважаються «використаним слотом» (sent/accepted)
        $rows = $wpdb->get_results("
            SELECT partner_id, COUNT(*) AS cnt
            FROM {$table}
            WHERE attempted_at BETWEEN '{$day_start}' AND '{$day_end}'
              AND status IN ({$statuses_in})
              AND partner_id IN ({$ids_in})
            GROUP BY partner_id
        ", ARRAY_A);

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['partner_id']] = (int)$r['cnt'];
        }
        return $map;
    }

    /** === Перетворення дня тижня у суфікс meta === */
    private static function day_slug(int $dow): string
    {
        // 1..7 => mon..sun
        static $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        return $map[$dow] ?? 'mon';
    }

    /** Денні ліміти: _leadrouter_partner_{slug}_limit; якщо нема — 0 */
    private static function limit_for_day(int $partner_post_id, int $dow): int
    {
        $slug = self::day_slug($dow);
        $val = get_post_meta($partner_post_id, "_leadrouter_partner_{$slug}_limit", true);
        if ($val === '' || $val === null) return 0;
        return max(0, (int)$val);
    }

    /**
     * Робочі години per-day: _leadrouter_partner_{slug}_{start|end}
     * - формат HH:MM (запасно підтримується HH:MM:SS), EST
     * - якщо не задано start або end — вважаємо «завжди відкрито»
     * - підтримує overnight (напр. 22:00–06:00)
     */
    private static function is_open_now_per_day(int $partner_post_id, DateTimeInterface $now, int $dow): bool
    {
        $slug = self::day_slug($dow);
        $from = get_post_meta($partner_post_id, "_leadrouter_partner_{$slug}_start", true);
        $to   = get_post_meta($partner_post_id, "_leadrouter_partner_{$slug}_end", true);

        if (!$from || !$to) {
            return true; // немає годин — відкрито завжди
        }

        $tz = self::tz();
        $today = $now->format('Y-m-d');

        // Спробуємо H:i, якщо ні — H:i:s
        $from_dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$today} {$from}", $tz);
        if (!$from_dt) {
            $from_dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$today} {$from}", $tz);
        }
        $to_dt   = DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$today} {$to}", $tz);
        if (!$to_dt) {
            $to_dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$today} {$to}", $tz);
        }

        // Якщо не вдалося розпарсити (некоректні значення) — відкрито завжди
        if (!$from_dt || !$to_dt) {
            return true;
        }

        // overnight 22:00–06:00
        if ($to_dt <= $from_dt) {
            if ($now < $from_dt) {
                // Ми у відрізку "після півночі" → старт має бути вчора
                $from_dt = $from_dt->modify('-1 day');
            } else {
                // Ми у відрізку "до півночі" → кінець має бути завтра
                $to_dt = $to_dt->modify('+1 day');
            }
        }

        return ($now >= $from_dt && $now < $to_dt);
    }

    /** TZ EST */
    private static function tz(): DateTimeZone
    {
        return new DateTimeZone('America/New_York');
    }

    /** now in EST */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::tz());
    }

    /** Сьогоднішнє вікно у EST як рядки MySQL */
    private static function today_window_mysql_est(DateTimeInterface $now): array
    {
        $tz = self::tz();
        $s = new DateTimeImmutable($now->format('Y-m-d 00:00:00'), $tz);
        $e = new DateTimeImmutable($now->format('Y-m-d 23:59:59'), $tz);
        return [$s->format('Y-m-d H:i:s'), $e->format('Y-m-d H:i:s')];
    }
}
