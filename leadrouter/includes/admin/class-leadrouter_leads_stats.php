<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LeadRouter_Leads_Stats {

    /** Точка входу: виводить увесь блок статистики */
    public static function render() {
        $args = self::read_filters_from_request();
        $stats = self::fetch($args);


        self::render_group_weights_by_weekday();


        echo '<div class="leadrouter-stats">';
        echo '<h2>' . esc_html__( 'Щоденна статистика', 'leadrouter' ) . '</h2>';

        // A: Нові ліди за день
        self::render_new_leads_by_day($stats['by_day_new_leads']);

        // B: Загальні призначення + статуси за день
        self::render_totals_by_day($stats['by_day_totals']);

        // C: За день по групах (ПІВOТ: рядки=дні, колонки=групи)
        self::render_pivot_by_day(
            $stats['pivot_groups']['days'],
            $stats['pivot_groups']['columns'],      // [group_id] => title
            $stats['pivot_groups']['matrix'],       // [day][group_id] => count
            __( 'За день по групах', 'leadrouter' ),
            __( 'Дата', 'leadrouter' )
        );

        // D: За день по партнерах (ПІВOT: рядки=дні, колонки=партнери)
        self::render_pivot_by_day(
            $stats['pivot_partners']['days'],
            $stats['pivot_partners']['columns'],    // [partner_id] => title
            $stats['pivot_partners']['matrix'],     // [day][partner_id] => count
            __( 'За день по партнерах', 'leadrouter' ),
            __( 'Дата', 'leadrouter' )
        );

        echo '</div>';
    }

    /** Зняти фільтри з $_GET */
    protected static function read_filters_from_request(): array {
        return [
            'date_from'  => isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from']) : '',
            'date_to'    => isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])   : '',
            'status'     => isset($_GET['lr_status'])  ? sanitize_text_field($_GET['lr_status']) : '',
            'group_id'   => isset($_GET['lr_group'])   ? absint($_GET['lr_group'])               : 0,
            'partner_id' => isset($_GET['lr_partner']) ? absint($_GET['lr_partner'])             : 0,
            'search'     => isset($_GET['s'])          ? trim(wp_unslash($_GET['s']))            : '',
        ];
    }

    /** Основний фетч статистики */
    protected static function fetch(array $args): array {
        global $wpdb;
        $logs_table   = $wpdb->prefix . 'leadrouter_logs';
        $groups_table = $wpdb->prefix . 'leadrouter_groups'; // у тебе: w4pMd_leadrouter_groups

        $date_from  = $args['date_from'];
        $date_to    = $args['date_to'];
        $status     = $args['status'];
        $group_id   = $args['group_id'];
        $partner_id = $args['partner_id'];
        $search     = $args['search'];

        // WHERE для логів
        $where = [];
        $params = [];

        if ( $date_from ) { $where[] = 'l.assigned_at >= %s'; $params[] = $date_from . ' 00:00:00'; }
        if ( $date_to )   { $where[] = 'l.assigned_at <= %s'; $params[] = $date_to   . ' 23:59:59'; }
        if ( $status !== '' ) { $where[] = 'l.status = %s'; $params[] = $status; }
        if ( $group_id > 0 )   { $where[] = 'l.group_id = %d'; $params[] = $group_id; }
        if ( $partner_id > 0 ) { $where[] = 'l.partner_id = %d'; $params[] = $partner_id; }

        $join_posts = false;
        $where_sql = ''; // доповнимо після пошуку

        // Блок пошуку
        if ( $search !== '' ) {
            if ( ctype_digit($search) ) {
                $where[] = 'l.lead_id = %d';
                $params[] = (int)$search;
            } else {
                // Для текстового пошуку назв партнера/групи/статусу — приєднуємо wp_posts
                $join_posts = true;
            }
        }
        $where_sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        // 1) Нові ліди за день (мінімальна дата появи lead_id)
        $firsts_base = "SELECT lead_id, MIN(assigned_at) AS first_at FROM {$logs_table}";
        $firsts_where = [];
        $firsts_params = [];
        if ( $date_from ) { $firsts_where[] = 'assigned_at >= %s'; $firsts_params[] = $date_from . ' 00:00:00'; }
        if ( $date_to )   { $firsts_where[] = 'assigned_at <= %s'; $firsts_params[] = $date_to   . ' 23:59:59'; }
        if ( $search !== '' && ctype_digit($search) ) {
            $firsts_where[] = 'lead_id = %d'; $firsts_params[] = (int)$search;
        }
        $firsts_where_sql = $firsts_where ? (' WHERE ' . implode(' AND ', $firsts_where)) : '';
        $firsts_sql = "{$firsts_base} {$firsts_where_sql} GROUP BY lead_id";

        $by_day_new_leads = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(f.first_at) AS day, COUNT(*) AS new_leads
                 FROM ({$firsts_sql}) f
                 GROUP BY DATE(f.first_at)
                 ORDER BY day DESC",
                $firsts_params
            ),
            ARRAY_A
        ) ?: [];

        // 2) Загальні призначення/статуси/унікальні по днях
        $select_logs = "SELECT DATE(l.assigned_at) AS day,
                               COUNT(*) AS assignments,
                               SUM(CASE WHEN l.status='assigned' THEN 1 ELSE 0 END) AS assigned_cnt,
                               SUM(CASE WHEN l.status='sent'     THEN 1 ELSE 0 END) AS sent_cnt,
                               SUM(CASE WHEN l.status='failed'   THEN 1 ELSE 0 END) AS failed_cnt,
                               COUNT(DISTINCT l.lead_id) AS unique_leads
                        FROM {$logs_table} l";
        $params_tot = $params;

        if ( $join_posts ) {
            $select_logs .= " LEFT JOIN {$wpdb->posts} p ON p.ID = l.partner_id
                              LEFT JOIN {$wpdb->posts} g ON g.ID = l.group_id"; // NB: тут group_id = post_id лише якщо ти так зберігаєш; у нас нижче інакше для назв
            // текстовий пошук по назві партнера, групи (як CPT) та статусу
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_sql .= ( $where_sql ? ' AND ' : ' WHERE ' ) . '(p.post_title LIKE %s OR g.post_title LIKE %s OR l.status LIKE %s)';
            array_push($params_tot, $like, $like, $like);
        }

        $by_day_totals = $wpdb->get_results(
            $wpdb->prepare(
                "{$select_logs} {$where_sql} GROUP BY DATE(l.assigned_at) ORDER BY day DESC",
                $params_tot
            ),
            ARRAY_A
        ) ?: [];

        // 3) За день по групах (для півоту)
        $sql_groups = "SELECT DATE(l.assigned_at) AS day, l.group_id, COUNT(*) AS assignments
                       FROM {$logs_table} l";
        $params_g = $params;

        if ( $join_posts ) {
            // Пошук по тексту тут не потрібен для підрахунку — він вже у $where_sql для totals,
            // але для консистентності можна також додати join — проте ми не фільтруємо тут по title.
            // Оскільки в нас WHERE вже сформований, потрібно дублювати пошуковий фрагмент, якщо треба.
            // Простіше: якщо був текстовий пошук — повторимо той самий блок:
            $sql_groups .= " LEFT JOIN {$wpdb->posts} p ON p.ID = l.partner_id
                             LEFT JOIN {$wpdb->posts} g ON g.ID = l.group_id";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_sql_groups = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $where_sql_groups .= ( $where_sql_groups ? ' AND ' : ' WHERE ' ) . '(p.post_title LIKE %s OR g.post_title LIKE %s OR l.status LIKE %s)';
            array_push($params_g, $like, $like, $like);
        } else {
            $where_sql_groups = $where_sql;
        }

        $by_day_groups_rows = $wpdb->get_results(
            $wpdb->prepare(
                "{$sql_groups} {$where_sql_groups} GROUP BY DATE(l.assigned_at), l.group_id ORDER BY day DESC",
                $params_g
            ),
            ARRAY_A
        ) ?: [];

        // 4) За день по партнерах (для півоту) — з урахуванням того, що partner_id може бути JSON/рядком
        $sql_partners = "SELECT DATE(l.assigned_at) AS day, l.partner_id AS partner_raw, COUNT(*) AS assignments
                 FROM {$logs_table} l";
        $params_p = $params;

        if ( $join_posts ) {
            $sql_partners .= " LEFT JOIN {$wpdb->posts} p ON p.ID = l.partner_id
                       LEFT JOIN {$wpdb->posts} g ON g.ID = l.group_id";
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where_sql_partners = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
            $where_sql_partners .= ( $where_sql_partners ? ' AND ' : ' WHERE ' ) . '(p.post_title LIKE %s OR g.post_title LIKE %s OR l.status LIKE %s)';
            array_push($params_p, $like, $like, $like);
        } else {
            $where_sql_partners = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
        }

        $by_day_partners_raw = $wpdb->get_results(
            $wpdb->prepare(
                "{$sql_partners} {$where_sql_partners} GROUP BY DATE(l.assigned_at), l.partner_id ORDER BY day DESC",
                $params_p
            ),
            ARRAY_A
        ) ?: [];

// Нормалізуємо результати: розкладаємо JSON/рядки на окремі partner_id і сумуємо
        $by_day_partners_rows = self::expand_partner_rows($by_day_partners_raw); // -> масив з ключами day, partner_id, assignments


        // ---- Побудова назв для колонок ----

        // (а) GROUPS: group_id у логах — це ВНУТРІШНІЙ id з leadrouter_groups → треба мапа на post_id → title
        $group_ids = array_unique(array_filter(array_map(
            fn($r) => (int)($r['group_id'] ?? 0),
            $by_day_groups_rows
        )));
        $group_id_to_post_id = [];
        if ( $group_ids ) {
            $placeholders = implode(',', array_fill(0, count($group_ids), '%d'));
            $sql = "SELECT id, post_id FROM {$groups_table} WHERE id IN ($placeholders)";
            $rows = $wpdb->get_results( $wpdb->prepare($sql, $group_ids), ARRAY_A ) ?: [];
            foreach ($rows as $row) {
                $group_id_to_post_id[(int)$row['id']] = (int)$row['post_id'];
            }
        }
        $group_columns = []; // [group_id => title]
        foreach ($group_ids as $gid) {
            $post_id = $group_id_to_post_id[$gid] ?? 0;
            $title   = $post_id ? ( get_the_title($post_id) ?: (string)$gid ) : (string)$gid;
            $group_columns[$gid] = $title;
        }
        // Відсортуємо колонки груп за алфавітом
        asort($group_columns, SORT_NATURAL | SORT_FLAG_CASE);

        // (б) PARTNERS: partner_id — це CPT ID → назвa напряму
        $partner_ids = array_unique(array_filter(array_map(
            fn($r) => (int)($r['partner_id'] ?? 0),
            $by_day_partners_rows
        )));
        $partner_columns = []; // [partner_id => title]
        foreach ($partner_ids as $pid) {
            $partner_columns[$pid] = get_the_title($pid) ?: (string)$pid;
        }
        asort($partner_columns, SORT_NATURAL | SORT_FLAG_CASE);

        // ---- Побудова півот-матриць ----
        $pivot_groups   = self::build_pivot_matrix($by_day_groups_rows, 'group_id', $group_columns);
        $pivot_partners = self::build_pivot_matrix($by_day_partners_rows, 'partner_id', $partner_columns);

        return [
            'by_day_new_leads' => $by_day_new_leads,
            'by_day_totals'    => $by_day_totals,
            'pivot_groups'     => $pivot_groups,     // ['days'=>[], 'columns'=>[id=>title], 'matrix'=>[day][id]=>int]
            'pivot_partners'   => $pivot_partners,   // ['days'=>[], 'columns'=>[id=>title], 'matrix'=>[day][id]=>int]
        ];
    }

    /** Побудова півот-матриці: rows = дні, cols = entity_id (group/partner), values = assignments */
    protected static function build_pivot_matrix(array $rows, string $key, array $columns): array {
        $days = [];
        $matrix = []; // [day][col] => count

        foreach ($rows as $r) {
            $day = (string) ($r['day'] ?? '');
            if ( $day === '' ) { continue; }
            $id  = (int) ($r[$key] ?? 0);
            if ( $id <= 0 ) { continue; }
            $cnt = (int) ($r['assignments'] ?? 0);

            $days[$day] = true;
            if ( ! isset($matrix[$day]) ) {
                $matrix[$day] = [];
            }
            $matrix[$day][$id] = ($matrix[$day][$id] ?? 0) + $cnt;
        }

        $days = array_keys($days);
        // Сортуємо дні від новіших до старіших (як у totals/new_leads)
        rsort($days, SORT_STRING);

        return [
            'days'    => $days,
            'columns' => $columns, // [id => title] (вже відсортовано по title)
            'matrix'  => $matrix,
        ];
    }

    /** Рендер таблиці “Нові ліди за день” */
    protected static function render_new_leads_by_day(array $rows) {
        echo '<h3>' . esc_html__( 'Нові ліди за день', 'leadrouter' ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Дата', 'leadrouter' ) . '</th>';
        echo '<th>' . esc_html__( 'Нових лідів', 'leadrouter' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( $rows ) {
            foreach ($rows as $r) {
                printf(
                    '<tr><td>%s</td><td>%d</td></tr>',
                    esc_html($r['day']),
                    (int)$r['new_leads']
                );
            }
        } else {
            echo '<tr><td colspan="2">' . esc_html__( 'Немає даних', 'leadrouter' ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** Рендер таблиці “Призначення за день (статуси)” */
    protected static function render_totals_by_day(array $rows) {
        echo '<h3 style="margin-top:1.5em;">' . esc_html__( 'Призначення за день (з розбиттям по статусах)', 'leadrouter' ) . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Дата', 'leadrouter' ) . '</th>';
        echo '<th>' . esc_html__( 'Призначень', 'leadrouter' ) . '</th>';
        echo '<th>' . esc_html__( 'Assigned', 'leadrouter' ) . '</th>';
        echo '<th>' . esc_html__( 'Sent', 'leadrouter' ) . '</th>';
        echo '<th>' . esc_html__( 'Failed', 'leadrouter' ) . '</th>';
        echo '<th>' . esc_html__( 'Унікальних лідів', 'leadrouter' ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( $rows ) {
            foreach ($rows as $r) {
                printf(
                    '<tr><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td></tr>',
                    esc_html($r['day']),
                    (int)$r['assignments'],
                    (int)$r['assigned_cnt'],
                    (int)$r['sent_cnt'],
                    (int)$r['failed_cnt'],
                    (int)$r['unique_leads']
                );
            }
        } else {
            echo '<tr><td colspan="6">' . esc_html__( 'Немає даних', 'leadrouter' ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Узагальнений рендер півот-таблиці:
     * @param array $days      Список дат (рядки)
     * @param array $columns   [id => title] (колонки)
     * @param array $matrix    [day][id] => count
     * @param string $title    Заголовок таблиці
     * @param string $first_th Текст першої колонки (наприклад, “Дата”)
     */
    protected static function render_pivot_by_day(array $days, array $columns, array $matrix, string $title, string $first_th) {
        echo '<h3 style="margin-top:1.5em;">' . esc_html( $title ) . '</h3>';
        echo '<div class="leadrouter-pivot-scroll" style="overflow:auto;">';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html( $first_th ) . '</th>';
        foreach ($columns as $id => $col_title) {
            echo '<th>' . esc_html( $col_title ) . '</th>';
        }
        echo '<th>' . esc_html__( 'Сума за день', 'leadrouter' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( $days ) {
            foreach ($days as $day) {
                $row = $matrix[$day] ?? [];
                $sum = 0;
                echo '<tr>';
                echo '<td>' . esc_html($day) . '</td>';
                foreach ($columns as $id => $_t) {
                    $cnt = (int) ($row[$id] ?? 0);
                    $sum += $cnt;
                    echo '<td>' . $cnt . '</td>';
                }
                echo '<td>' . (int)$sum . '</td>';
                echo '</tr>';
            }
        } else {
            $colspan = 1 + count($columns) + 1;
            echo '<tr><td colspan="' . (int)$colspan . '">' . esc_html__( 'Немає даних', 'leadrouter' ) . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /** Розпарсити сире значення partner_id (int | JSON | CSV) у масив цілих ID */
    protected static function normalize_partner_ids($raw): array {
        if (is_array($raw)) {
            $ids = $raw;
        } else {
            $str = trim((string)$raw);
            if ($str === '' || strtolower($str) === 'null') return [];

            // JSON-масив?
            if (strlen($str) >= 2 && $str[0] === '[' && $str[-1] === ']') {
                $decoded = json_decode($str, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $ids = $decoded;
                } else {
                    $ids = [$str];
                }
            } else {
                // Спробуємо як одне число або CSV ("1,2,3")
                if (ctype_digit($str)) {
                    $ids = [ (int)$str ];
                } else {
                    // простий CSV/список з роздільниками , ; |
                    $ids = preg_split('/\s*[,;|]\s*/', $str) ?: [];
                }
            }
        }

        // до цілих, унікальні, >0
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
        return array_filter($ids, fn($v) => $v > 0);
    }

    /** Перетворити сирі рядки партнера (day, partner_raw, assignments) на нормалізовані (day, partner_id, assignments) */
    protected static function expand_partner_rows(array $rows): array {
        $out = []; // накопичувач: key = day|partner_id
        foreach ($rows as $r) {
            $day  = (string)($r['day'] ?? '');
            $cnt  = (int)($r['assignments'] ?? 0);
            $pids = self::normalize_partner_ids($r['partner_raw'] ?? '');

            if ($day === '' || $cnt <= 0 || empty($pids)) continue;

            foreach ($pids as $pid) {
                $key = $day . '|' . $pid;
                if (!isset($out[$key])) {
                    $out[$key] = ['day' => $day, 'partner_id' => $pid, 'assignments' => 0];
                }
                $out[$key]['assignments'] += $cnt;
            }
        }
        return array_values($out);
    }


    /**
     * Таблиця: Ваги груп по днях тижня (з leadrouter_groups)
     * Колонки: Група | Пн | Вт | Ср | Чт | Пт | Сб | Нд
     */
    public static function render_group_weights_by_weekday(): void {
        global $wpdb;
        $table_groups = $wpdb->prefix . 'leadrouter_groups';
        $posts        = $wpdb->posts;

        // Мапінг днів (1..7)
        $day_labels = [
            1 => 'Пн',
            2 => 'Вт',
            3 => 'Ср',
            4 => 'Чт',
            5 => 'Пт',
            6 => 'Сб',
            7 => 'Нд',
            'eff' => 'Коеф'
        ];

        // Забираємо групи з назвами (title з post_id)
        $sql = "
        SELECT g.id,
               g.post_id,
               COALESCE(NULLIF(p.post_title, ''), CONCAT('Group #', g.post_id)) AS group_title,
               g.weight_1, g.weight_2, g.weight_3, g.weight_4, g.weight_5, g.weight_6, g.weight_7,
               g.eff, g.active, g.updated_at
        FROM {$table_groups} g
        LEFT JOIN {$posts} p ON p.ID = g.post_id
        ORDER BY g.id ASC, g.active DESC
    ";

        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        echo '<div class="leadrouter-card" style="margin-top:20px">';
        echo '<h2 style="margin:0 0 10px">Ваги груп по днях тижня</h2>';

        if (empty($rows)) {
            echo '<p>Немає даних у таблиці груп.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:100%; table-layout:auto">';
        echo '<thead><tr>';
        echo '<th style="min-width:220px">Група</th>';
        foreach ($day_labels as $i => $lbl) {
            echo '<th style="text-align:center; width:70px">' . esc_html($lbl) . '</th>';
        }
        echo '<th style="text-align:center; width:80px">Активна</th>';
        echo '<th style="min-width:140px">Оновлено</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            echo '<tr>';
            $group_title = esc_html($r['group_title']);
            $post_link   = get_edit_post_link((int)$r['post_id']);
            if ($post_link) {
                $group_title = '<a href="' . esc_url($post_link) . '">' . $group_title . '</a>';
            }
            echo '<td>' . $group_title . '<br/><small>ID: ' . (int)$r['id'] . ', post_id: ' . (int)$r['post_id'] . '</small></td>';

            // Друк ваг 1..7
            for ($i = 1; $i <= 7; $i++) {
                $val = isset($r["weight_{$i}"]) ? (int)$r["weight_{$i}"] : 0;
                echo '<td style="text-align:center">' . $val . '</td>';
            }

            echo '<td style="text-align:center">' . $r['eff'] . '</td>';
            echo '<td style="text-align:center">' . ((int)$r['active'] ? 'Так' : 'Ні') . '</td>';
            echo '<td>' . esc_html($r['updated_at'] ?: '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p style="margin-top:8px"><small>Примітка: weight_1…weight_7 відповідають дням тижня у порядку Пн→Нд.</small></p>';
        echo '</div>';
    }



}
