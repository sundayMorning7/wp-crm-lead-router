<?php
// WP-CLI: leadrouter simulate-proportion / simulate-capacity
    if (defined('WP_CLI') && WP_CLI) {

    class LeadRouter_CLI {

        /**
         * Симуляція без обмежень (чиста пропорція ваг сьогоднішнього дня, EST).
         *
         * ## OPTIONS
         * [--n=<n>]
         * : Кількість ітерацій (default 100)
         *
         * ## EXAMPLES
         *   wp leadrouter simulate-proportion --n=500
         */
        public function simulate_proportion( $args, $assoc ) {
            global $wpdb;

            $n = isset($assoc['n']) ? max(1, (int)$assoc['n']) : 100;

            $now  = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            $dowN = (int)$now->format('N'); // 1..7

            $table_groups = $wpdb->prefix . 'leadrouter_groups';
            $groups = $wpdb->get_results("
                SELECT id, name, weight,
                       weight_1, weight_2, weight_3, weight_4, weight_5, weight_6, weight_7,
                       active
                FROM {$table_groups}
                WHERE active = 1
            ", ARRAY_A);

            if (empty($groups)) {
                WP_CLI::error('No active groups.');
                return;
            }

            // Побудова items: weight = ефективна вага на сьогодні (EST)
            $items = [];
            $totalW = 0;
            foreach ($groups as $g) {
                $w = self::effective_weight_today($g, $dowN);
                if ($w > 0) {
                    $items[] = ['group_id' => (int)$g['id'], 'name' => (string)$g['name'], 'weight' => (int)$w];
                    $totalW += (int)$w;
                }
            }
            if ($totalW <= 0) {
                WP_CLI::error('All effective weights are zero for today (EST).');
                return;
            }

            // Симуляція: чистий зважений рандом без квот
            $result = [];
            for ($i=0; $i<$n; $i++) {
                $pick = self::weighted_pick($items, $totalW);
                if (!$pick) continue;
                $gid = $pick['group_id'];
                $result[$gid] = ($result[$gid] ?? 0) + 1;
            }

            if (empty($result)) {
                WP_CLI::line('No picks produced.');
                return;
            }

            ksort($result);
            foreach ($result as $gid => $cnt) {
                WP_CLI::line(sprintf('group_id=%d -> %d', $gid, $cnt));
            }
        }

        /**
         * Симуляція з урахуванням денних квот (ефективна вага як ліміт, EST).
         * Квота зменшується in-memory, старт бере вже використане за сьогодні з leadrouter_logs.
         *
         * ## OPTIONS
         * [--n=<n>]
         * : Кількість ітерацій (default 100)
         *
         * ## EXAMPLES
         *   wp leadrouter simulate-capacity --n=200
         */
        public function simulate_capacity( $args, $assoc ) {
            global $wpdb;

            $n = isset($assoc['n']) ? max(1, (int)$assoc['n']) : 100;

            $now   = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
            $dowN  = (int)$now->format('N'); // 1..7
            $start = $now->format('Y-m-d 00:00:00');
            $end   = $now->format('Y-m-d 23:59:59');

            $table_groups = $wpdb->prefix . 'leadrouter_groups';
            $groups = $wpdb->get_results("
                SELECT id, name, weight,
                       weight_1, weight_2, weight_3, weight_4, weight_5, weight_6, weight_7,
                       active
                FROM {$table_groups}
                WHERE active = 1
            ", ARRAY_A);

            if (empty($groups)) {
                WP_CLI::error('No active groups.');
                return;
            }

            // Побудувати items із квотами на сьогодні
            $items = [];
            foreach ($groups as $g) {
                $w = self::effective_weight_today($g, $dowN); // денний ліміт
                if ($w > 0) {
                    $items[(int)$g['id']] = [
                        'group_id' => (int)$g['id'],
                        'name'     => (string)$g['name'],
                        'quota'    => (int)$w,
                    ];
                }
            }
            if (empty($items)) {
                WP_CLI::error('All effective weights are zero for today (EST).');
                return;
            }

            // Відняти вже використані призначення за сьогодні
            $table_logs = $wpdb->prefix . 'leadrouter_logs';
            $rows = $wpdb->get_results(
                $wpdb->prepare("
                    SELECT group_id, COUNT(*) AS cnt
                    FROM {$table_logs}
                    WHERE status = 'group_assigned'
                      AND assigned_at BETWEEN %s AND %s
                    GROUP BY group_id
                ", $start, $end),
                ARRAY_A
            );
            $used = [];
            foreach ($rows as $r) {
                $gid = (int)$r['group_id'];
                $used[$gid] = (int)$r['cnt'];
            }

            // Симуляція: кожний пік зменшує quota_left у пам'яті
            $result = [];
            for ($i=0; $i<$n; $i++) {
                // Побудувати пул доступних із quota_left
                $pool = [];
                $totalW = 0;
                foreach ($items as $gid => $it) {
                    $left = $it['quota'] - (int)($used[$gid] ?? 0);
                    if ($left > 0) {
                        $pool[] = ['group_id'=>$gid, 'name'=>$it['name'], 'weight'=>$left];
                        $totalW += $left;
                    }
                }
                if ($totalW <= 0 || empty($pool)) {
                    break; // квоти вичерпано
                }

                $pick = self::weighted_pick($pool, $totalW);
                if (!$pick) { $pick = end($pool); }

                $gid = $pick['group_id'];
                $used[$gid] = (int)($used[$gid] ?? 0) + 1;
                $result[$gid] = ($result[$gid] ?? 0) + 1;
            }

            if (empty($result)) {
                WP_CLI::line('No simulated picks (maybe all quotas are already exhausted today).');
                return;
            }

            ksort($result);
            foreach ($result as $gid => $cnt) {
                WP_CLI::line(sprintf('group_id=%d -> %d', $gid, $cnt));
            }
        }

        // ===== helpers =====

        private static function effective_weight_today(array $row, int $dow) : int {
            $base = isset($row['weight']) ? (int)$row['weight'] : 0;
            $key  = 'weight_' . $dow;
            if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
                return max(0, (int)$row[$key]);
            }
            return max(0, $base);
        }

        private static function weighted_pick(array $items, int $totalW) : ?array {
            $r = wp_rand(1, max(1, (int)$totalW));
            $c = 0;
            foreach ($items as $it) {
                $c += (int)$it['weight'];
                if ($r <= $c) return $it;
            }
            return end($items) ?: null;
        }
    }

    WP_CLI::add_command('leadrouter', 'LeadRouter_CLI');
}


