<?php
if (!defined('ABSPATH')) { exit; }

/**
 * LeadRouter_LogViewer
 *
 * - Підменю "LeadRouter → Логи"
 * - Режими: DB (за замовчуванням) і FILE (?mode=file)
 * - Фільтри: дати, error_code, lead_id, partner_id, group_id, рівень (info/debug/error), статус
 * - Таблиця з пагінацією
 * - Експорт у CSV (через admin-ajax)
 * - Безпечне очищення логів (nonce): TRUNCATE таблиць + очистка файл-логів
 */
class LeadRouter_LogViewer
{
    // Шлях до файлу логів (має збігатися з тим, що у LeadRouter_Flow)
    const LOG_DIR_REL  = 'leadrouter/logs';
    const LOG_FILE_BASENAME = 'leadrouter.log';

    // Параметри пагінації
    const PER_PAGE = 50;

    public static function init()
    {
        // Меню
       // add_action('admin_menu', [self::class, 'register_menu']);

        // Експорт
        add_action('wp_ajax_leadrouter_export_logs', [self::class, 'ajax_export']);

        // Очищення
        add_action('admin_post_leadrouter_clear_logs', [self::class, 'handle_clear_logs']);

        // Ассети
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function register_menu()
    {

        add_submenu_page(
            'leadrouter',
            __('Логи LeadRouter', 'leadrouter'),
            __('Логи', 'leadrouter'),
            'manage_options',
            'leadrouter-logviewer',
            [self::class, 'render_page']
        );

    }

    public static function enqueue_assets($hook)
    {
        if (empty($_GET['page']) || $_GET['page'] !== 'leadrouter-logviewer') {
            return;
        }
        wp_enqueue_style('leadrouter-logviewer', plugins_url('../../assets/css/logviewer.css', __FILE__), [], LEADROUTER_VERSION);
        wp_enqueue_script('leadrouter-logviewer', plugins_url('../../assets/js/logviewer.js', __FILE__), ['jquery'], LEADROUTER_VERSION, true);
        wp_localize_script('leadrouter-logviewer', 'LeadRouterLogViewer', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('leadrouter_export_logs'),
        ]);
    }

    public static function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Недостатньо прав', 'leadrouter'));
        }

        // Режим: db|file
        $mode = isset($_GET['mode']) && $_GET['mode'] === 'file' ? 'file' : 'db';

        // Фільтри
        $filters = self::read_filters();

        // Дія (виконується окремими endpoint'ами, тут тільки рендер)
        $clear_url = wp_nonce_url(admin_url('admin-post.php?action=leadrouter_clear_logs'), 'leadrouter_clear_logs');

        echo '<div class="wrap leadrouter-logviewer">';
        echo '<h1>LeadRouter — ' . esc_html__('Логи', 'leadrouter') . '</h1>';

        // Tabs (DB / File)
        $base_url = remove_query_arg(['paged']);
        $db_url   = esc_url(add_query_arg(['mode'=>'db'], $base_url));
        $file_url = esc_url(add_query_arg(['mode'=>'file'], $base_url));

        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="'.$db_url.'" class="nav-tab '.($mode==='db'?'nav-tab-active':'').'">База даних</a>';
        echo '<a href="'.$file_url.'" class="nav-tab '.($mode==='file'?'nav-tab-active':'').'">Raw-файл</a>';
        echo '</h2>';

        // Панель дій (експорт / очистка)
        echo '<div class="leadrouter-actions">';
        $export_url = esc_url(
            add_query_arg(array_merge($_GET, [
                'action' => 'leadrouter_export_logs',
                '_wpnonce' => wp_create_nonce('leadrouter_export_logs')
            ]), admin_url('admin-ajax.php'))
        );
        echo '<a href="'.$export_url.'" class="button button-secondary">Експорт CSV</a> ';

        echo '<a href="'.$clear_url.'" class="button button-danger" onclick="return confirm(\'Очистити усі логи? Дію не можна відмінити.\')">Очистити логи</a>';
        echo '</div>';

        // Фільтри (форма GET)
        self::render_filters_form($mode, $filters);

        if ($mode === 'db') {
            self::render_db_table($filters);
        } else {
            self::render_file_viewer($filters);
        }

        echo '</div>';
    }

    /* =========================
     * ФІЛЬТРИ
     * ========================= */
    protected static function read_filters(): array
    {
        return [
            'date_from'   => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to'     => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'level'       => isset($_GET['level']) ? sanitize_text_field($_GET['level']) : '',
            'status'      => isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '',
            'error_code'  => isset($_GET['error_code']) ? sanitize_text_field($_GET['error_code']) : '',
            'lead_id'     => isset($_GET['lead_id']) ? absint($_GET['lead_id']) : 0,
            'partner_id'  => isset($_GET['partner_id']) ? absint($_GET['partner_id']) : 0,
            'group_id'    => isset($_GET['group_id']) ? absint($_GET['group_id']) : 0,
            's'           => isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '',
            'paged'       => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
        ];
    }

    protected static function render_filters_form(string $mode, array $f): void
    {
        $action = remove_query_arg(['paged']);
        echo '<form method="get" class="leadrouter-filters">';
        foreach ($_GET as $k=>$v) {
            if (in_array($k, ['date_from','date_to','level','status','error_code','lead_id','partner_id','group_id','s','paged'])) continue;
            echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr($v).'"/>';
        }
        echo '<input type="hidden" name="mode" value="'.esc_attr($mode).'"/>';

        echo '<div class="fields-row">';
        echo '<label>Від: <input type="date" name="date_from" value="'.esc_attr($f['date_from']).'"></label>';
        echo '<label>До: <input type="date" name="date_to" value="'.esc_attr($f['date_to']).'"></label>';
        echo '<label>Рівень: 
                <select name="level">
                    <option value="">—</option>
                    <option value="error" '.selected($f['level'],'error',false).'>error</option>
                    <option value="info" '.selected($f['level'],'info',false).'>info</option>
                    <option value="debug" '.selected($f['level'],'debug',false).'>debug</option>
                </select>
              </label>';
        echo '<label>Статус: 
                <select name="status">
                    <option value="">—</option>
                    <option value="queued" '.selected($f['status'],'queued',false).'>queued</option>
                    <option value="sent" '.selected($f['status'],'sent',false).'>sent</option>
                    <option value="accepted" '.selected($f['status'],'accepted',false).'>accepted</option>
                    <option value="failed" '.selected($f['status'],'failed',false).'>failed</option>
                    <option value="skipped" '.selected($f['status'],'skipped',false).'>skipped</option>
                    <option value="processed" '.selected($f['status'],'processed',false).'>processed</option>
                    <option value="await" '.selected($f['status'],'await',false).'>await</option>
                    <option value="error" '.selected($f['status'],'error',false).'>error</option>
                </select>
              </label>';
        echo '<label>error_code: <input type="text" name="error_code" value="'.esc_attr($f['error_code']).'"></label>';
        echo '<label>lead_id: <input type="number" name="lead_id" value="'.esc_attr($f['lead_id']).'" min="0"></label>';
        echo '<label>partner_id: <input type="number" name="partner_id" value="'.esc_attr($f['partner_id']).'" min="0"></label>';
        echo '<label>group_id: <input type="number" name="group_id" value="'.esc_attr($f['group_id']).'" min="0"></label>';
        echo '<label>Пошук: <input type="search" name="s" value="'.esc_attr($f['s']).'" placeholder="message / context"></label>';
        echo '<button class="button button-primary" type="submit">Застосувати</button>';
        echo '<a class="button" href="'.esc_url(remove_query_arg(['date_from','date_to','level','status','error_code','lead_id','partner_id','group_id','s','paged'])).'">Скинути</a>';
        echo '</div>';

        echo '</form>';
    }

    /* =========================
     * РЕНДЕР ТАБЛИЦІ З БД
     * ========================= */
    protected static function render_db_table(array $f): void
    {
        global $wpdb;

        $table_partner_logs = $wpdb->prefix . 'leadrouter_partner_logs';
        $table_logs         = $wpdb->prefix . 'leadrouter_logs';

        // Показуємо об’єднаний список із двох джерел:
        // - partner_logs (детальні спроби відправки)
        // - logs (загальні події)
        // Вирівняємо колонки під єдиний формат
        $where = [];
        $args  = [];

        // Дата
        if (!empty($f['date_from'])) {
            $where[] = "dt >= %s";
            $args[]  = $f['date_from'] . ' 00:00:00';
        }
        if (!empty($f['date_to'])) {
            $where[] = "dt <= %s";
            $args[]  = $f['date_to'] . ' 23:59:59';
        }

        // Рівень (є лише в file-логах; у БД опціонально — мапимо: failed/error→error, sent/queued→info, debug немає)
        // Тут фільтр рівня перетворимо на фільтр статусу:
        if (!empty($f['level'])) {
            if ($f['level'] === 'error') {
                $where[] = "(status IN ('failed','error'))";
            } elseif ($f['level'] === 'info') {
                $where[] = "(status IN ('queued','sent','accepted','processed','await'))";
            }
        }

        // Статус
        if (!empty($f['status'])) {
            $where[] = "status = %s";
            $args[]  = $f['status'];
        }

        // error_code (є тільки у partner_logs)
        $where_partner = $where;
        $args_partner  = $args;
        if (!empty($f['error_code'])) {
            $where_partner[] = "error_code = %s";
            $args_partner[]  = $f['error_code'];
        }

        // lead_id, partner_id, group_id
        if (!empty($f['lead_id'])) {
            $where_partner[] = "lead_id = %d";
            $args_partner[]  = (int)$f['lead_id'];
        }
        if (!empty($f['partner_id'])) {
            $where_partner[] = "partner_id = %d";
            $args_partner[]  = (int)$f['partner_id'];
        }
        if (!empty($f['group_id'])) {
            $where_partner[] = "group_id = %d";
            $args_partner[]  = (int)$f['group_id'];
        }

        // full-text like по request_json/response_json/error_message
        if (!empty($f['s'])) {
            $like = '%' . $wpdb->esc_like($f['s']) . '%';
            $where_partner[] = "(request_json LIKE %s OR response_json LIKE %s OR error_message LIKE %s)";
            array_push($args_partner, $like, $like, $like);
        }

        $where_sql_partner = $where_partner ? ('WHERE ' . implode(' AND ', $where_partner)) : '';
        $count_sql_partner = "SELECT COUNT(*) FROM {$table_partner_logs} {$where_sql_partner}";
        $total_partner = (int) $wpdb->get_var($wpdb->prepare($count_sql_partner, $args_partner));

        // Загальні логи (без error_code, is_skipped)
        $where_logs = $where;
        $args_logs  = $args;
        if (!empty($f['lead_id']))  { $where_logs[] = "lead_id = %d";  $args_logs[] = (int)$f['lead_id']; }
        if (!empty($f['group_id'])) { $where_logs[] = "group_id = %d"; $args_logs[] = (int)$f['group_id']; }
        if (!empty($f['s'])) {
            $like = '%' . $wpdb->esc_like($f['s']) . '%';
            $where_logs[] = "(payload LIKE %s)";
            $args_logs[]  = $like;
        }
        $where_sql_logs = $where_logs ? ('WHERE ' . implode(' AND ', $where_logs)) : '';
        $count_sql_logs = "SELECT COUNT(*) FROM {$table_logs} {$where_sql_logs}";
        $total_logs = (int) $wpdb->get_var($wpdb->prepare($count_sql_logs, $args_logs));

        $total = $total_partner + $total_logs;

        // Пагінація
        $paged   = max(1, (int)$f['paged']);
        $perPage = self::PER_PAGE;
        $offset  = ($paged - 1) * $perPage;

        // Щоб спростити — спочатку беремо partner_logs, потім доповнюємо logs, обрізаючи до $perPage
        $rows = [];

        if ($total_partner > 0) {
            $sql_partner = "
                SELECT 
                    attempted_at AS dt,
                    status,
                    lead_id,
                    partner_id,
                    group_id,
                    error_code,
                    error_message,
                    request_json,
                    response_json,
                    is_skipped,
                    state_filter
                FROM {$table_partner_logs}
                {$where_sql_partner}
                ORDER BY attempted_at DESC
                LIMIT %d OFFSET %d
            ";
            // Якщо партнери не перекрили весь page — ми дозбираємо з logs нижче
            $limit_partner  = $perPage;
            $offset_partner = $offset;
            $args_partner_l = array_merge($args_partner, [$limit_partner, $offset_partner]);
            $rows_partner = $wpdb->get_results($wpdb->prepare($sql_partner, $args_partner_l), ARRAY_A) ?: [];
            foreach ($rows_partner as $r) {
                $rows[] = [
                    'dt'         => $r['dt'],
                    'level'      => self::map_level($r['status']),
                    'status'     => $r['status'],
                    'lead_id'    => (int)$r['lead_id'],
                    'partner_id' => (int)$r['partner_id'],
                    'group_id'   => (int)$r['group_id'],
                    'error_code' => $r['error_code'] ?: '',
                    'message'    => $r['error_message'] ?: '',
                    'context'    => self::context_compose($r['request_json'], $r['response_json'], $r['is_skipped'], $r['state_filter'], $r['response_excerpt']),
                    'source'     => 'partner_logs',
                ];
            }
        }

        $need_more = $perPage - count($rows);
        if ($need_more > 0) {
            // Добираємо із загальних логів
            $sql_logs = "
                SELECT 
                    assigned_at AS dt,
                    status,
                    lead_id,
                    group_id,
                    partner_id,
                    payload
                FROM {$table_logs}
                {$where_sql_logs}
                ORDER BY assigned_at DESC
                LIMIT %d OFFSET %d
            ";
            $args_logs_l = array_merge($args_logs, [$need_more, max(0, $offset - $total_partner)]);
            $rows_logs = $wpdb->get_results($wpdb->prepare($sql_logs, $args_logs_l), ARRAY_A) ?: [];
            foreach ($rows_logs as $r) {
                $rows[] = [
                    'dt'         => $r['dt'],
                    'level'      => self::map_level($r['status']),
                    'status'     => $r['status'],
                    'lead_id'    => (int)$r['lead_id'],
                    'partner_id' => is_numeric($r['partner_id']) ? (int)$r['partner_id'] : 0,
                    'group_id'   => (int)$r['group_id'],
                    'error_code' => '',
                    'message'    => '',
                    'context'    => $r['payload'],
                    'source'     => 'logs',
                ];
            }
        }

        // Таблиця
        echo '<table class="widefat striped leadrouter-table">';
        echo '<thead><tr>';
        echo '<th>Дата (EST)</th><th>Level</th><th>Status</th><th>Lead</th><th>Partner</th><th>Group</th><th>error_code</th><th>Message</th><th>Details</th>';
        echo '</tr></thead><tbody>';

        if (empty($rows)) {
            echo '<tr><td colspan="9" style="text-align:center; color:#666;">Немає записів</td></tr>';
        } else {
            foreach ($rows as $row) {
                $level_badge = self::level_badge($row['level']);
                $dt   = esc_html($row['dt']);
                $lead = $row['lead_id'] ? '<a href="'.esc_url(admin_url('admin.php?page=leadrouter&tab=leads&lead_id='.$row['lead_id'])).'">'.$row['lead_id'].'</a>' : '-';
                $partner = $row['partner_id'] ? '<a href="'.esc_url(get_edit_post_link($row['partner_id'])).'">'.$row['partner_id'].'</a>' : '-';
                $group   = $row['group_id'] ? '<a href="'.esc_url(get_edit_post_link($row['group_id'])).'">'.$row['group_id'].'</a>' : '-';

                $message = esc_html($row['message']);
                $ecode   = esc_html($row['error_code']);

                // Кнопка "Деталі"
                $details_btn = $row['context']
                    ? '<button type="button" class="button button-small js-lr-show-json" data-json="'.esc_attr($row['context']).'">Подивитись</button>'
                    : '-';

                echo '<tr>';
                echo '<td>'.$dt.'</td>';
                echo '<td>'.$level_badge.'</td>';
                echo '<td>'.esc_html($row['status']).'</td>';
                echo '<td>'.$lead.'</td>';
                echo '<td>'.$partner.'</td>';
                echo '<td>'.$group.'</td>';
                echo '<td>'.$ecode.'</td>';
                echo '<td>'.$message.'</td>';
                echo '<td>'.$details_btn.'</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        // Пагінація
        $total_pages = (int) ceil($total / $perPage);
        if ($total_pages > 1) {
            $base = remove_query_arg(['paged']);
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($p=1; $p <= $total_pages; $p++) {
                $url = esc_url(add_query_arg(['paged'=>$p], $base));
                echo '<a class="page-numbers '.($p===$f['paged']?'current':'').'" href="'.$url.'">'.$p.'</a> ';
            }
            echo '</div></div>';
        }
    }

    protected static function map_level(string $status): string
    {
        $status = strtolower($status);
        if (in_array($status, ['failed','error'], true)) return 'error';
        if (in_array($status, ['queued','sent','accepted','processed','await'], true)) return 'info';
        return 'debug';
    }

    protected static function level_badge(string $level): string
    {
        $level = strtolower($level);
        $cls = 'lr-badge lr-'.$level;
        return '<span class="'.esc_attr($cls).'">'.esc_html($level).'</span>';
    }

    protected static function context_compose($req_json, $resp_json, $is_skipped, $state_filter): string
    {
        $r = [
            'is_skipped'   => (int)$is_skipped,
            'state_filter' => $state_filter ?: null,
        ];
        if ($req_json)  { $r['request']  = self::decode_json_maybe($req_json); }
        if ($resp_json) { $r['response'] = self::decode_json_maybe($resp_json); }
        return wp_json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    protected static function decode_json_maybe($s)
    {
        $j = json_decode((string)$s, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $j : $s;
    }

    /* =========================
     * RAW FILE VIEWER
     * ========================= */
    protected static function render_file_viewer(array $f): void
    {
        list($file,) = self::log_paths();
        echo '<div class="leadrouter-file-view">';
        echo '<p><strong>Файл:</strong> '.esc_html($file).'</p>';

        if (!file_exists($file)) {
            echo '<p style="color:#b00;">Файл логів не знайдено.</p>';
            echo '</div>';
            return;
        }

        $lines = self::tail_file($file, 500); // останні 500 рядків
        if (empty($lines)) {
            echo '<p>Лог порожній</p></div>';
            return;
        }

        echo '<pre class="leadrouter-log-pre">'.esc_html(implode("\n", $lines)).'</pre>';
        echo '</div>';
    }

    protected static function log_paths(): array
    {
        $up = wp_upload_dir(null, false);
        $dir = trailingslashit($up['basedir']).self::LOG_DIR_REL;
        $file = trailingslashit($dir).self::LOG_FILE_BASENAME;
        return [$file, $dir];
    }

    protected static function tail_file(string $file, int $lines = 200): array
    {
        // простий tail: читаємо кінець файлу порціями
        $f = @fopen($file, 'r');
        if (!$f) return [];
        $buffer   = '';
        $chunk    = 4096;
        $pos      = -1;
        $line_cnt = 0;
        $output   = [];

        fseek($f, 0, SEEK_END);
        $file_size = ftell($f);

        while ($line_cnt < $lines && $file_size + $pos > 0) {
            $pos -= $chunk;
            if (-$pos > $file_size) {
                $pos = -$file_size;
            }
            fseek($f, $pos, SEEK_END);
            $buffer = fread($f, min($chunk, $file_size));
            $output[] = $buffer;
            $line_cnt += substr_count($buffer, "\n");
        }
        fclose($f);
        $text = implode('', array_reverse($output));
        $rows = explode("\n", $text);
        return array_slice($rows, -$lines);
    }

    /* =========================
     * ЕКСПОРТ CSV
     * ========================= */
    public static function ajax_export()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', 403);
        }
        check_admin_referer('leadrouter_export_logs');

        // Візьмемо ті ж фільтри, що на сторінці (режим DB)
        $filters = self::read_filters();

        // Збираємо дані як у render_db_table, але без пагінації — до розумних меж (наприклад 10000 рядків)
        $rows = self::collect_db_rows_for_export($filters, 10000);

        // Виводимо CSV
        $filename = 'leadrouter-logs-'.date('Ymd-His').'.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.$filename);
        $out = fopen('php://output', 'w');
        // Заголовки
        fputcsv($out, ['datetime_est','level','status','lead_id','partner_id','group_id','error_code','message','context'], ';');

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['dt'],
                $r['level'],
                $r['status'],
                $r['lead_id'],
                $r['partner_id'],
                $r['group_id'],
                $r['error_code'],
                $r['message'],
                $r['context'],
            ], ';');
        }
        fclose($out);
        exit;
    }

    protected static function collect_db_rows_for_export(array $f, int $limit = 10000): array
    {
        // Щоб не дублювати багато коду, виконаємо спрощену версію запитів з render_db_table
        global $wpdb;
        $table_partner_logs = $wpdb->prefix . 'leadrouter_partner_logs';
        $table_logs         = $wpdb->prefix . 'leadrouter_logs';

        $rows = [];

        // Побудова where як раніше (ті ж правила)
        $where = []; $args = [];
        if (!empty($f['date_from'])) { $where[] = "dt >= %s"; $args[] = $f['date_from'].' 00:00:00'; }
        if (!empty($f['date_to']))   { $where[] = "dt <= %s"; $args[] = $f['date_to'].' 23:59:59'; }
        if (!empty($f['level'])) {
            if ($f['level'] === 'error') {
                $where[] = "(status IN ('failed','error'))";
            } elseif ($f['level'] === 'info') {
                $where[] = "(status IN ('queued','sent','accepted','processed','await'))";
            }
        }
        if (!empty($f['status'])) { $where[] = "status = %s"; $args[] = $f['status']; }

        // partner_logs
        $where_partner = $where; $args_partner = $args;
        if (!empty($f['error_code'])) { $where_partner[] = "error_code = %s"; $args_partner[] = $f['error_code']; }
        if (!empty($f['lead_id']))    { $where_partner[] = "lead_id = %d";   $args_partner[] = (int)$f['lead_id']; }
        if (!empty($f['partner_id'])) { $where_partner[] = "partner_id = %d";$args_partner[] = (int)$f['partner_id']; }
        if (!empty($f['group_id']))   { $where_partner[] = "group_id = %d";  $args_partner[] = (int)$f['group_id']; }
        if (!empty($f['s'])) {
            $like = '%' . $wpdb->esc_like($f['s']) . '%';
            $where_partner[] = "(request_json LIKE %s OR response_json LIKE %s OR error_message LIKE %s)";
            array_push($args_partner, $like, $like, $like);
        }
        $wpl = $where_partner ? ('WHERE '.implode(' AND ', $where_partner)) : '';
        $sql_partner = "
            SELECT attempted_at AS dt, status, lead_id, partner_id, group_id, error_code, error_message, request_json, response_json, is_skipped, state_filter
            FROM {$table_partner_logs}
            {$wpl}
            ORDER BY attempted_at DESC
            LIMIT %d
        ";
        $args_partner[] = $limit;
        $rows_partner = $wpdb->get_results($wpdb->prepare($sql_partner, $args_partner), ARRAY_A) ?: [];
        foreach ($rows_partner as $r) {
            $rows[] = [
                'dt'         => $r['dt'],
                'level'      => self::map_level($r['status']),
                'status'     => $r['status'],
                'lead_id'    => (int)$r['lead_id'],
                'partner_id' => (int)$r['partner_id'],
                'group_id'   => (int)$r['group_id'],
                'error_code' => $r['error_code'] ?: '',
                'message'    => $r['error_message'] ?: '',
                'context'    => self::context_compose($r['request_json'], $r['response_json'], $r['is_skipped'], $r['state_filter']),
            ];
        }

        // logs
        $where_logs = $where; $args_logs = $args;
        if (!empty($f['lead_id']))  { $where_logs[] = "lead_id = %d";  $args_logs[] = (int)$f['lead_id']; }
        if (!empty($f['group_id'])) { $where_logs[] = "group_id = %d"; $args_logs[] = (int)$f['group_id']; }
        if (!empty($f['s'])) {
            $like = '%' . $wpdb->esc_like($f['s']) . '%';
            $where_logs[] = "(payload LIKE %s)";
            $args_logs[]  = $like;
        }
        $wlg = $where_logs ? ('WHERE '.implode(' AND ', $where_logs)) : '';
        $sql_logs = "
            SELECT assigned_at AS dt, status, lead_id, group_id, partner_id, payload
            FROM {$table_logs}
            {$wlg}
            ORDER BY assigned_at DESC
            LIMIT %d
        ";
        $args_logs[] = $limit;
        $rows_logs = $wpdb->get_results($wpdb->prepare($sql_logs, $args_logs), ARRAY_A) ?: [];
        foreach ($rows_logs as $r) {
            $rows[] = [
                'dt'         => $r['dt'],
                'level'      => self::map_level($r['status']),
                'status'     => $r['status'],
                'lead_id'    => (int)$r['lead_id'],
                'partner_id' => is_numeric($r['partner_id']) ? (int)$r['partner_id'] : 0,
                'group_id'   => (int)$r['group_id'],
                'error_code' => '',
                'message'    => '',
                'context'    => $r['payload'],
            ];
        }

        // Сортуємо все разом за датою DESC і зрізаємо до ліміту
        usort($rows, function($a,$b){ return strcmp($b['dt'],$a['dt']); });
        return array_slice($rows, 0, $limit);
    }

    /* =========================
     * CLEAR LOGS (truncate + clear files)
     * ========================= */
    public static function handle_clear_logs()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', 403);
        }
        check_admin_referer('leadrouter_clear_logs');

        global $wpdb;
        $tables = [
            $wpdb->prefix.'leadrouter_partner_logs',
            $wpdb->prefix.'leadrouter_logs',
        ];

        foreach ($tables as $t) {
            $wpdb->query("TRUNCATE TABLE {$t}");
        }

        // видалимо всі leadrouter*.log у директорії
        list($file, $dir) = self::log_paths();
        if (is_dir($dir)) {
            foreach (glob(trailingslashit($dir).'leadrouter*.log') as $lf) {
                @unlink($lf);
            }
        }

        wp_safe_redirect(add_query_arg(['page'=>'leadrouter-logs','cleared'=>'1'], admin_url('admin.php')));
        exit;
    }
}
