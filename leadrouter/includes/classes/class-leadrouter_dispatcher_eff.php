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
        $table_send_log = $wpdb->prefix . 'leadrouter_send_log';

        // Час у EST
        $now = isset($opts['datetime']) && $opts['datetime'] instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($opts['datetime'])->setTimezone(self::tz_est())
            : new DateTimeImmutable('now', self::tz_est());

        $dow = (int)$now->format('N'); // 1..7
        $assigned_at = $now->format('Y-m-d H:i:s');

        // Примусові режими: група призначається навіть без вільної квоти/партнерів.
        // Ключ необов'язковий у $opts — тоді працює звичайний режим.
        $dispatch_method = (string)($opts['dispatch_method'] ?? '');
        $force_mode = in_array($dispatch_method, ['manual_bulk', 'auto_cron_error_lead'], true);

        [$day_start, $day_end] = self::today_window_mysql_est($now);

        // === КРОК 0. Перевірка чи настав новий день ===
        self::reset_eff_if_new_day($now);

        // Витягнути всі активні групи
        $groups = $wpdb->get_results("
        SELECT id, post_id, name,
               eff, coef, mode, overflow_on, overflow_cap,
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
        /*$rows = $wpdb->get_results(
            $wpdb->prepare("
            SELECT group_id, COUNT(*) AS cnt
            FROM {$table_logs}
            WHERE status = 'group_assigned'
              AND assigned_at BETWEEN %s AND %s
            GROUP BY group_id
        ", $day_start, $day_end),
            ARRAY_A
        ) ?: [];
        */


        $rows = $wpdb->get_results(
            $wpdb->prepare("
        SELECT group_id, COUNT(DISTINCT lead_id) AS cnt
        FROM {$table_send_log}
        WHERE status = 'success'
          AND attempted_at BETWEEN %s AND %s
        GROUP BY group_id
    ", $day_start, $day_end),
            ARRAY_A
        ) ?: [];


        $assigned_today = [];
        foreach ($rows as $r) {
            $assigned_today[(int)$r['group_id']] = (int)$r['cnt'];
        }

        // Для shared-груп джерело квоти інше: скільки НОВИХ лідів група
        // прийняла сьогодні. Рахуємо лише лідів, чиє ПЕРШЕ призначення в цій
        // групі потрапляє в сьогоднішнє вікно: досилка копій по вчорашніх
        // лідах створює нові рядки assignments, але свою квоту такий лід уже
        // заплатив у день входу в групу — вдруге її не тарифікуємо (dispatch
        // для topup свідомо оминає WRR саме з цієї причини). Раніше рахувались
        // УСІ рядки вікна: 09.08 ранкові досилки 18 суботніх лідів з'їли 18 із
        // 50 слотів квоти, і група закрилась о 17:09 з напівпорожніми лімітами.
        $table_assignments = $wpdb->prefix . 'leadrouter_lead_assignments';
        $shared_rows = $wpdb->get_results(
            $wpdb->prepare("
        SELECT a.group_id, COUNT(DISTINCT a.lead_id) AS cnt
        FROM {$table_assignments} a
        INNER JOIN {$table_groups} g ON g.id = a.group_id AND g.mode = 'shared'
        WHERE a.created_at BETWEEN %s AND %s
          AND NOT EXISTS (
              SELECT 1
                FROM {$table_assignments} b
               WHERE b.group_id   = a.group_id
                 AND b.lead_id    = a.lead_id
                 AND b.created_at < %s
          )
        GROUP BY a.group_id
    ", $day_start, $day_end, $day_start),
            ARRAY_A
        ) ?: [];

        foreach ($shared_rows as $r) {
            $assigned_today[(int)$r['group_id']] = (int)$r['cnt'];
        }



        // КРОК 2. Обчислення eff_tmp
        //
        // ВАЖЛИВО про коефіцієнт групи (план §1 п.7): він зсуває ЧЕРГУ, а не
        // денну норму. Тому:
        //   - у WRR-арифметику (eff_tmp, sumW) іде вага З коефіцієнтом;
        //   - денна квота (cnt < w) перевіряється по ЧИСТІЙ вазі weight_today.
        // Тобто група з коеф 1.5 добирає свою норму раніше протягом дня, але
        // за день усе одно не отримає більше, ніж weight_today.
        $sumW = 0;
        $eligible = [];
        foreach ($groups as $g) {
            $w   = self::effective_weight_today($g, $dow);
            $cnt = $assigned_today[(int)$g['id']] ?? 0;

            if ($w > 0 && $cnt < $w) {
                $coef  = self::group_coef($g);
                $w_eff = max(0, (int)round($w * $coef));

                $g['weight_today']     = (int)$w;      // чиста вага = денна норма
                $g['weight_today_eff'] = $w_eff;       // вага у черзі (з коефом)
                $g['eff_tmp']          = (int)$g['eff'] + $w_eff;
                $eligible[] = $g;
                $sumW += $w_eff;
            }
        }


        if (empty($eligible) && !$force_mode) {
            // М'яка квота: перш ніж здатися, пробуємо shared-групи з overflow
            $ov = self::try_overflow_dispatch($groups, $assigned_today, $dow, $lead_id, $assigned_at, $opts, $table_logs);
            if ($ov !== null) {
                return $ov;
            }

            return new WP_Error('no_capacity_today', 'All groups reached today’s capacity (EST).');
        }
        if ($sumW <= 0 && !$force_mode) {
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



        if (!$picked && !$force_mode) {
            // Квота десь ще є, але партнерів у eligible-групах немає —
            // overflow-групи можуть мати вільних партнерів понад свою квоту
            $ov = self::try_overflow_dispatch($groups, $assigned_today, $dow, $lead_id, $assigned_at, $opts, $table_logs);
            if ($ov !== null) {
                return $ov;
            }

            return new WP_Error('no_partners_in_all_groups', 'No available partners found in any eligible group right now.');
        }

        // FOR Manual bulk
        if (!$picked) {
            $picked = [
                'id'      => (int)$groups[0]['id'],
                'post_id' => (int)$groups[0]['post_id'],
                'name'          => (string)$groups[0]['name'],
                'weight_today'        => 0,
            ];
        }




        // КРОК 5. Визначення виняткових штатів AK/HI
        $from_state = strtoupper(trim((string)($opts['lead_from_state'] ?? '')));
        $to_state   = strtoupper(trim((string)($opts['lead_to_state'] ?? '')));
        $excluded   = ['AK', 'HI'];
        $isExcludedState = in_array($from_state, $excluded, true) || in_array($to_state, $excluded, true);

        // КРОК 6. Оновлення eff — тільки якщо НЕ AK/HI
        // Smooth Weighted Round Robin: усі eligible-групи накопичують eff += weight_today
        // (це вже обчислений eff_tmp), і лише вибрана додатково платить -sumW.
        // Раніше eff зберігався тільки для picked, через що розподіл був непропорційним
        // при попиті нижче сумарної квоти (малі групи перевантажувались, великі недобирали).
        if (!$isExcludedState) {
            $picked_id          = (int)$picked['id'];
            $picked_in_eligible = false;

            foreach ($eligible as $g) {
                $gid = (int)$g['id'];
                if ($gid === $picked_id) {
                    $picked_in_eligible = true;
                }
                $newEff = (int)$g['eff_tmp'] - ($gid === $picked_id ? (int)$sumW : 0);
                $wpdb->update(
                    $table_groups,
                    [
                        'eff'        => $newEff,
                        'updated_at' => $assigned_at,
                    ],
                    ['id' => $gid],
                    ['%d','%s'],
                    ['%d']
                );
            }

            // Fallback (manual_bulk / auto_cron_error_lead): picked не входив у eligible —
            // зберігаємо стару поведінку саме для примусово вибраної групи.
            if (!$picked_in_eligible) {
                $newEff = (int)($picked['eff_tmp'] ?? 0) - (int)$sumW;
                $wpdb->update(
                    $table_groups,
                    [
                        'eff'        => $newEff,
                        'updated_at' => $assigned_at,
                    ],
                    ['id' => $picked_id],
                    ['%d','%s'],
                    ['%d']
                );
            }
        }

        // КРОК 7. Логування — статус залежить від винятку, але partner_id знову чистий список
        $wpdb->insert(
            $table_logs,
            [
                'lead_id'     => (int)$lead_id,
                'partner_id'  => wp_json_encode(array_column($partners_for_pick, 'post_id')),
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




    /**
     * М'яка квота (overflow) для shared-груп — fallback, коли звичайний WRR
     * не знайшов групу (квоти вичерпані або в eligible-групах немає партнерів).
     *
     * Кандидат: активна shared-група з overflow_on=1 і вагою дня > 0, чия
     * квота ВЖЕ вичерпана (cnt >= w; інакше вона була в eligible і її партнерів
     * щойно перевіряли) і чий переліміт ще під стелею (overflow_cap, 0 = без
     * стелі). Тверда межа — ємність партнерів: available_in_group з усіма
     * перевірками годин/лімітів.
     *
     * Свідомо НЕ чіпаємо eff: overflow — добір поверх плану, а не участь у
     * WRR, тож плановий баланс груп не перекошується ні сьогодні, ні завтра.
     * У лог пишемо статус group_assigned_overflow (для AK/HI лишається
     * group_assigned_excluded_state — там eff і так не бере участі).
     *
     * @return array|null null — overflow не спрацював, віддаємо звичайну помилку
     */
    private static function try_overflow_dispatch(
        array $groups,
        array $assigned_today,
        int $dow,
        int $lead_id,
        string $assigned_at,
        array $opts,
        string $table_logs
    ): ?array {
        global $wpdb;

        $candidates = [];
        foreach ($groups as $g) {
            if (($g['mode'] ?? 'classic') !== 'shared' || empty($g['overflow_on'])) {
                continue;
            }

            $w = self::effective_weight_today($g, $dow);
            if ($w <= 0) {
                continue;
            }

            $cnt = $assigned_today[(int)$g['id']] ?? 0;
            if ($cnt < $w) {
                continue; // квота ще не вибрана — група і так була в eligible
            }

            $cap = max(0, (int)($g['overflow_cap'] ?? 0));
            if ($cap > 0 && ($cnt - $w) >= $cap) {
                continue; // стеля overflow досягнута
            }

            $g['weight_today'] = $w;
            $g['over_by']      = $cnt - $w;
            $candidates[] = $g;
        }

        if (empty($candidates)) {
            return null;
        }

        // Найменш перевантажена першою; при рівності — стабільний порядок за id
        usort($candidates, static function ($a, $b) {
            if ((int)$a['over_by'] !== (int)$b['over_by']) {
                return (int)$a['over_by'] <=> (int)$b['over_by'];
            }
            return (int)$a['id'] <=> (int)$b['id'];
        });

        foreach ($candidates as $cand) {
            $partners = LeadRouter_Partners::available_in_group(
                (int)$cand['post_id'],
                [
                    'group_meta_key' => '_leadrouter_partner_group',
                    'statuses'       => ['queued', 'sent', 'accepted'],
                ]
            );

            if (empty($partners)) {
                continue;
            }

            $from_state = strtoupper(trim((string)($opts['lead_from_state'] ?? '')));
            $to_state   = strtoupper(trim((string)($opts['lead_to_state'] ?? '')));
            $excluded   = ['AK', 'HI'];
            $isExcludedState = in_array($from_state, $excluded, true) || in_array($to_state, $excluded, true);

            $wpdb->insert(
                $table_logs,
                [
                    'lead_id'     => (int)$lead_id,
                    'partner_id'  => wp_json_encode(array_column($partners, 'post_id')),
                    'group_id'    => (int)$cand['id'],
                    'assigned_at' => $assigned_at,
                    'status'      => $isExcludedState ? 'group_assigned_excluded_state' : 'group_assigned_overflow',
                ],
                ['%d','%s','%d','%s','%s']
            );

            return [
                'group_id'      => (int)$cand['id'],
                'group_post_id' => (int)$cand['post_id'],
                'name'          => (string)$cand['name'],
                'weight'        => (int)$cand['weight_today'],
            ];
        }

        return null;
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

                // Межа доби EST — тут же застосовуємо відкладені налаштування
                // розподілу (mode/N/L) з меты груп. Коефіцієнти синкаються
                // окремо і негайно при збереженні.
                if (class_exists('LR_Shared_Sync')) {
                    LR_Shared_Sync::sync_all_active_groups();
                }
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

    /** Коефіцієнт групи з робочої таблиці; порожній/некоректний → 1.0 (нейтрально) */
    private static function group_coef(array $row): float
    {
        $coef = isset($row['coef']) ? (float)$row['coef'] : 1.0;

        return $coef > 0 ? $coef : 1.0;
    }

}
