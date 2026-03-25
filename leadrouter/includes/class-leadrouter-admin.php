<?php
if (!defined('ABSPATH')) {
    exit;
}

if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}


class LeadRouter_Admin
{

    public function __construct()
    {


    }

    public static function register_ajax()
    {
        add_action('wp_ajax_leadrouter_get_lead_send_logs', [__CLASS__, 'ajax_get_lead_send_logs']);
        add_action('wp_ajax_leadrouter_get_report', [__CLASS__, 'ajax_get_report']);
        add_action('wp_ajax_leadrouter_manual_broadcast', [__CLASS__, 'ajax_leadrouter_manual_broadcast']);
        add_action('wp_ajax_leadrouter_manual_broadcast_bulk', [__CLASS__, 'ajax_leadrouter_manual_broadcast_bulk']);
        add_action('wp_ajax_leadrouter_delete_leads_cascade', [__CLASS__, 'ajax_delete_leads_cascade']);

    }


    public static function add_scripts()
    {


        add_action('admin_enqueue_scripts', function ($hook) {
            // Підключаємо лише на екрані редагування CPT партнера
            $screen = get_current_screen();
            if (!$screen) return;

            // Підкоригуй slug CPT, якщо інший:
            if ($screen->post_type !== 'leadrouter_partner') return;

            wp_enqueue_script(
                'lr-partner-map-autofill',
                plugins_url('../assets/js/lr-partner-map-autofill.js', __FILE__), // підлаштуй шлях
                ['jquery'],
                LEADROUTER_VERSION,
                true
            );

            wp_localize_script('lr-partner-map-autofill', 'LRPartnerMap', [
                'defaults' => lr_partner_default_map(),
                'i18n' => [
                    'confirm_reset' => __('Перезаписати існуючі відповідності мапінгу?', 'leadrouter'),
                    'done' => __('Автозаповнення виконано', 'leadrouter'),
                ],
                // назва complex-поля (щоб JS знав, що чіпати)
                'field_name' => 'leadrouter_partner_map',
            ]);
        });


        add_action('admin_enqueue_scripts', function ($hook) {


            if (empty($_GET['page']) || $_GET['page'] !== 'leadrouter-leads') {
                return;
            }


            wp_enqueue_style('md_datepicker_css', plugins_url('/assets/js/datepicker/jquery-ui.css', dirname(__FILE__)), array(), LEADROUTER_VERSION);
            wp_enqueue_style('md_datepicker_theme', plugins_url('/assets/js/datepicker/jquery-ui.theme.min.css', dirname(__FILE__)), array(), LEADROUTER_VERSION);

            wp_enqueue_style('md_json_viewer_css', plugins_url('/assets/css/jquery.json-viewer.css', dirname(__FILE__)), array(), LEADROUTER_VERSION);
            wp_enqueue_style('md_admin_css3', plugins_url('/assets/css/md_admin.css', dirname(__FILE__)), array(), '2.0.1');


            wp_enqueue_script('md_datepicker_lib', plugins_url('/assets/js/datepicker/jquery-ui.min.js', dirname(__FILE__)), ['jquery'], LEADROUTER_VERSION);
            wp_enqueue_script('md_excellentexport', plugins_url('/assets/js/excellentexport.js', dirname(__FILE__)), [], LEADROUTER_VERSION);

            wp_enqueue_script('md_json_viewer', plugins_url('/assets/js/jquery.json-viewer.js', dirname(__FILE__)), [], LEADROUTER_VERSION);


            wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
            wp_enqueue_script('leadrouter-admin-js', plugins_url('/assets/js/admin.js', dirname(__FILE__)), ['jquery'], LEADROUTER_VERSION);


            /*
                        wp_localize_script(
                            'md_admin_js',
                            'md_admin_js',
                            array(
                                'url' => admin_url('admin-ajax.php'),
                                'nonce' => wp_create_nonce('ship_ajax'),
                            )
                        );*/

            wp_localize_script('leadrouter-admin-js', 'LeadRouterLogViewer', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('leadrouter-admin-js'),
                'getLeadLogsAction' => 'leadrouter_get_lead_send_logs',
                'manualBroadcastAction' => 'leadrouter_manual_broadcast',
                'manualBroadcastBulkAction' => 'leadrouter_manual_broadcast_bulk',
                'deleteLeadsCascadeAction' => 'leadrouter_delete_leads_cascade',
            ]);

            wp_add_inline_style('md_admin_css3', '
                .lr-utm-stats-panel { background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin: 10px 0 15px; border-radius: 4px; }
                .lr-utm-stats-header { display: flex; justify-content: space-between; align-items: center; cursor: pointer; user-select: none; margin-bottom: 15px; }
                .lr-utm-stats-header h3 { margin: 0; font-size: 14px; }
                .lr-utm-stats-body.collapsed { display: none; }
                .lr-utm-stats-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
                .lr-utm-stat-card { background: #f6f7f7; padding: 12px; border-radius: 4px; }
                .lr-utm-stat-card strong { display: block; font-size: 24px; color: #2271b1; margin-bottom: 5px; }
                .lr-utm-stat-card span { font-size: 12px; color: #646970; }
                .lr-utm-chart-container { max-width: 800px; margin: 0 auto; }
            ');

            wp_add_inline_script('jquery', '
                jQuery(document).ready(function($) {
                    var isCollapsed = localStorage.getItem("lr_utm_stats_collapsed") === "true";
                    if (isCollapsed) {
                        $(".lr-utm-stats-body").addClass("collapsed");
                        $(".lr-utm-stats-toggle").text("▶");
                    }

                    $(".lr-utm-stats-header").on("click", function() {
                        $(".lr-utm-stats-body").toggleClass("collapsed");
                        var collapsed = $(".lr-utm-stats-body").hasClass("collapsed");
                        $(".lr-utm-stats-toggle").text(collapsed ? "▶" : "▼");
                        localStorage.setItem("lr_utm_stats_collapsed", collapsed);
                    });
                });
            ');


        });


        /*
        add_action('wp_ajax_leadrouter_manual_broadcast', function () {

            $lead_id = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
            $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;

            if (!$lead_id) {
                wp_send_json_error('lead_id missing');
            }

            if (!class_exists('LeadRouter_Flow')) {
                wp_send_json_error('LeadRouter_Flow not loaded');
            }

            $opts = [
                'group_meta_key' => '_leadrouter_partner_group',
                'statuses' => ['queued', 'sent', 'accepted'],
                'initial_status' => 'sent',
                'dispatch_method' => 'manual_bulk', // лишив, як у тебе
                'queue_if_closed' => true,
            ];

            if ($group_id > 0) {
                $opts['force_group_post_id'] = $group_id;
            }

            $result = LeadRouter_Flow::dispatch_broadcast($lead_id, $opts);

            wp_send_json_success($result);
        });
        */

    }


    public static function register_menus()
    {
        $cap = 'manage_options';
        $menu_slug = 'leadrouter';

        add_menu_page(
            __('LeadRouter', 'leadrouter'),
            'LeadRouter',
            $cap,
            $menu_slug,
            [__CLASS__, 'render_dashboard'],
            'dashicons-randomize',
            56
        );

        add_submenu_page(
            'leadrouter',
            __('Ліди', 'leadrouter'),
            __('Ліди', 'leadrouter'),
            'manage_options',
            'leadrouter-leads',
            ['LeadRouter_Admin', 'render_leads']
        );


        // Submenus
        add_submenu_page($menu_slug, __('Групи', 'leadrouter'), __('Групи', 'leadrouter'), $cap, 'edit.php?post_type=leadrouter_group');
        add_submenu_page($menu_slug, __('Партнери', 'leadrouter'), __('Партнери', 'leadrouter'), $cap, 'edit.php?post_type=leadrouter_partner');
        //add_submenu_page($menu_slug, __('Налаштування', 'leadrouter'), __('Налаштування', 'leadrouter'), $cap, 'leadrouter-settings', [__CLASS__, 'render_settings']);


        add_submenu_page(
            'leadrouter',
            __('Логи LeadRouter', 'leadrouter'),
            __('Логи', 'leadrouter'),
            'manage_options',
            'leadrouter-logviewer',
            ['LeadRouter_LogViewer', 'render_page']
        );

    }

    public static function render_dashboard()
    {
        echo '<div class="wrap"><h1>LeadRouter</h1>';


        LeadRouter_Leads_Stats::render();


        echo '</div>';
    }

    public static function render_logs()
    {
        echo '<div class="wrap"><h1>' . esc_html__('Логи розподілу', 'leadrouter') . '</h1>';
        $table = new LeadRouter_Logs_Table();
        $table->prepare_items();

        echo '<form method="get">';
        foreach (['page', 'orderby', 'order'] as $key) {
            if (isset($_GET[$key])) {
                printf('<input type="hidden" name="%s" value="%s" />', esc_attr($key), esc_attr($_GET[$key]));
            }
        }
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    public static function render_settings()
    {
        if (isset($_POST['leadrouter_save_settings']) && check_admin_referer('leadrouter_save_settings')) {
            $opts = [
                'default_group' => isset($_POST['default_group']) ? intval($_POST['default_group']) : 0,
            ];
            update_option('leadrouter_settings', $opts);
            echo '<div class="updated notice"><p>' . esc_html__('Налаштування збережено.', 'leadrouter') . '</p></div>';
        }
        $opts = get_option('leadrouter_settings', ['default_group' => 0]);
        $groups = get_posts([
            'post_type' => 'leadrouter_group',
            'posts_per_page' => -1,
            'post_status' => 'any',
        ]);

        echo '<div class="wrap"><h1>' . esc_html__('Налаштування', 'leadrouter') . '</h1>';
        echo '<form method="post">';
        wp_nonce_field('leadrouter_save_settings');
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="default_group">'
            . esc_html__('Група за замовчуванням', 'leadrouter') . '</label></th><td>';
        echo '<select name="default_group" id="default_group">';
        echo '<option value="0">—</option>';
        foreach ($groups as $g) {
            printf('<option value="%d" %s>%s</option>',
                $g->ID,
                selected($opts['default_group'], $g->ID, false),
                esc_html($g->post_title)
            );
        }
        echo '</select>';
        echo '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Зберегти', 'leadrouter'));
        echo '</form></div>';
    }

    public static function render_leads()
    {
        $table = new LeadRouter_Leads_Table();
        $table->prepare_items();


        $status_lead_count = self::count_leads_by_status();
        $sent_lead_count = self::count_sent_leads_today();

        $sent_lead_by_group = self::count_sent_leads_today_by_groups();


        $status_html = '';
        foreach ($status_lead_count as $status => $count) {

            $filtered = ['sent'];
            if (in_array($status, $filtered)) {
                continue;
            }

            $status_tmp = $status !== ''
                ? mb_strtoupper(mb_substr($status, 0, 1)) . mb_substr($status, 1)
                : '';
            $status_html .= $status_tmp . ': <b class="lead-status-count-' . $status . '">' . $count . '</b>';
        }


        global $wpdb;

        $sql = "
    SELECT p.ID, p.post_title
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm 
        ON p.ID = pm.post_id
    WHERE p.post_type = 'leadrouter_partner'
        AND p.post_status = 'publish'
        AND pm.meta_key = '_leadrouter_partner_active'
        AND pm.meta_value = '1'
";

        $partners = $wpdb->get_results($sql, ARRAY_A);

        $partners_options_html = '';
        foreach ($partners as $partner) {
            $partners_options_html .= '<option value="' . $partner['ID'] . '">' . $partner['post_title'] . '</option>';
        }

        $html_today_sent = '';
        foreach ($sent_lead_by_group as $key => $item) {
            $html_today_sent .= get_the_title($key) . ': <b class="mod-green">' . $item . '</b> ';
        }


        echo '<div class="wrap">
            <div class="md_page_header">
            <div class="md_flex" style="align-items: baseline">
            <h1>' . esc_html__('Leads', 'leadrouter') . '</h1>
            <button style="display: none" class="js-show-today-limit-panel page-title-action">Show today limits</button>
            <button class="js-show-report-panel page-title-action">Show report panel</button>
            <div class="count_title_est_today_sent">' . $html_today_sent . '</div>
            <div class="count_title_buy_status"><b>Status count:</b>' . $status_html . '</div>
            
            </div>
            <hr />
            </div>';

        echo '<div class="md-report-panel"
             style="display:none; padding: 10px 15px; margin-bottom: 20px;">
            <div class="md-report-panel_head">
                <h2>Report panel</h2>
                <div class="md-report-panel_head_range">
                    <label><b>From:</b></label>
                    <input readonly name="md_range_from" type="text" id="md_range_from" size="10">
                    <label><b>To:</b></label>
                    <input readonly name="md_range_to" type="text" id="md_range_to" size="10">
                </div>
            </div>

            <div class="md_flex">
                <div class="md_range_datepicker"></div>
            </div>

            <div class="md-report-panel_bottom">


                <button class="button js-create-aggregate-report">Create aggregate report</button>
                <select class="select js-choose-broker-id">
                    <option value="">Select partner</option>
                    ' . $partners_options_html . '
                    <!--<option value="all">All</option>-->
                </select>
                <button class="button js-create-aggregate-invoice">Create invoice</button>
            </div>

            <div class="md-report-panel_result">

            </div>

        </div>';


        $table->views();

        echo '<form method="get">';
        foreach (['page', 'orderby', 'order'] as $key) {
            if (isset($_GET[$key])) {
                printf(
                    '<input type="hidden" name="%s" value="%s" />',
                    esc_attr($key),
                    esc_attr($_GET[$key])
                );
            }
        }

        $s = isset($_GET['s']) ? esc_attr($_GET['s']) : '';
        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="leadrouter-search-input">' . esc_html__('Search Leads', 'leadrouter') . '</label>';
        echo '<input type="search" id="leadrouter-search-input" name="s" value="' . $s . '" />';
        submit_button(__('Search'), 'secondary', '', false);
        echo '</p>';

        $table->display();

        echo '</form>

        <div id="lr-logs-modal" class="lr-modal" style="display:none;">
            <div class="lr-modal-backdrop"></div>
            <div class="lr-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="lr-logs-modal-title">
                <div class="lr-modal-header">
                    <h2 id="lr-logs-modal-title" style="margin:0;">' . esc_html__('Lead Logs', 'leadrouter') . '</h2>
                    <button type="button" class="button lr-modal-close">✕</button>
                </div>
                <div class="lr-modal-body">
                    <div class="lr-modal-loading">' . esc_html__('Loading...', 'leadrouter') . '</div>
                    <div class="lr-modal-content" style="display:none;"></div>
                    <div class="lr-modal-json" style="display:none;"></div>
                </div>
            </div>
        </div>

      
</div>';
    }

    /**
     * Count leads grouped by status.
     *
     * @return array  Example: ['new' => 120, 'sent' => 55, '' => 3]
     */
    protected static function count_leads_by_status(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'leadrouter_leads';

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt
         FROM {$table}
         GROUP BY status",
            ARRAY_A
        );

        $out = [];
        foreach ((array)$rows as $r) {

            $status = (string)($r['status'] ?? '');
            $out[$status] = (int)($r['cnt'] ?? 0);

            /*
            $status_raw = (string)($r['status'] ?? '');


            $status = $status_raw !== ''
                ? mb_strtoupper(mb_substr($status_raw, 0, 1)) . mb_substr($status_raw, 1)
                : '';

            $out[$status] = (int)($r['cnt'] ?? 0);
            */
        }

        return $out;
    }

    protected static function count_sent_leads_today(): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'leadrouter_leads';

        // Межі сьогоднішньої доби в таймзоні WP
        $tz = wp_timezone();
        $start = new DateTime('today', $tz);
        $end = (clone $start)->modify('+1 day');

        $dt_from = $start->format('Y-m-d H:i:s');
        $dt_to = $end->format('Y-m-d H:i:s');

        $sql = "
        SELECT COUNT(*)
        FROM {$table}
        WHERE status = %s
          AND sent_at >= %s
          AND sent_at < %s
    ";

        return (int)$wpdb->get_var(
            $wpdb->prepare($sql, 'sent', $dt_from, $dt_to)
        );
    }

    protected static function count_sent_leads_today_by_groups(): array
    {
        global $wpdb;

        $send_table = $wpdb->prefix . 'leadrouter_send_log';

        // межі сьогоднішнього дня
        $tz = wp_timezone();

        $start = new DateTime('today', $tz);
        $end = (clone $start)->modify('+1 day');

        $dt_from = $start->format('Y-m-d H:i:s');
        $dt_to = $end->format('Y-m-d H:i:s');

        $sql = "
        SELECT
            partner_id,
            COUNT(DISTINCT lead_id) AS cnt
        FROM {$send_table}
        WHERE status = %s
          AND attempted_at >= %s
          AND attempted_at < %s
        GROUP BY partner_id
    ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, 'success', $dt_from, $dt_to),
            ARRAY_A
        );

        $out = [];


        foreach ((array)$rows as $r) {

            $gid = (int)($r['partner_id'] ?? 0);

            $out[$gid] = (int)($r['cnt'] ?? 0);
        }

        return $out;

        // результат:
        // [
        //   12 => 54,
        //   15 => 21,
        //   0  => 4
        // ]
    }

    protected static function count_sent_leads_today_by_group_partner(): array
    {
        global $wpdb;

        $send_table = $wpdb->prefix . 'leadrouter_send_log';

        // межі сьогоднішнього дня в WP timezone
        $tz = wp_timezone();
        $start = new DateTime('today', $tz);
        $end = (clone $start)->modify('+1 day');

        $dt_from = $start->format('Y-m-d H:i:s');
        $dt_to = $end->format('Y-m-d H:i:s');

        // DISTINCT lead_id щоб ретраї не подвоювали лічильники
        $sql = "
        SELECT
            COALESCE(NULLIF(group_id, 0), 0) AS group_id,
            COALESCE(NULLIF(partner_id, 0), 0) AS partner_id,
            COUNT(DISTINCT lead_id) AS cnt
        FROM {$send_table}
        WHERE status = %s
          AND attempted_at >= %s
          AND attempted_at < %s
        GROUP BY
            COALESCE(NULLIF(group_id, 0), 0),
            COALESCE(NULLIF(partner_id, 0), 0)
        ORDER BY group_id ASC, partner_id ASC
    ";

        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, 'sent', $dt_from, $dt_to),
            ARRAY_A
        );

        $out = [];

        foreach ((array)$rows as $r) {

            $gid = (int)($r['group_id'] ?? 0);
            $pid = (int)($r['partner_id'] ?? 0);
            $cnt = (int)($r['cnt'] ?? 0);

            if (!isset($out[$gid])) {
                $out[$gid] = [];
            }

            $out[$gid][$pid] = $cnt;
        }

        return $out;

        // [
        //   12 => [ 5 => 40, 9 => 10 ],
        //   15 => [ 2 => 21 ],
        //   0  => [ 0 => 4 ]
        // ]
    }

    protected static function fetch_leads_stats($args = [])
    {
        global $wpdb;
        $logs = $wpdb->prefix . 'leadrouter_logs';

        $date_from = isset($args['date_from']) ? sanitize_text_field($args['date_from']) : '';
        $date_to = isset($args['date_to']) ? sanitize_text_field($args['date_to']) : '';
        $status = isset($args['status']) ? sanitize_text_field($args['status']) : '';
        $group_id = isset($args['group_id']) ? absint($args['group_id']) : 0;
        $partner_id = isset($args['partner_id']) ? absint($args['partner_id']) : 0;
        $search = isset($args['search']) ? trim(wp_unslash($args['search'])) : '';

        // WHERE для основних запитів (по логах)
        $where = [];
        $params = [];
        if ($date_from) {
            $where[] = 'l.assigned_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $where[] = 'l.assigned_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }
        if ($status !== '') {
            $where[] = 'l.status = %s';
            $params[] = $status;
        }
        if ($group_id > 0) {
            $where[] = 'l.group_id = %d';
            $params[] = $group_id;
        }
        if ($partner_id > 0) {
            $where[] = 'l.partner_id = %d';
            $params[] = $partner_id;
        }

        $join_posts = false;
        if ($search !== '' && !ctype_digit($search)) {
            // Для текстового пошуку приєднаємо назви партнера/групи
            $join_posts = true;
        }

        $where_sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        // 1) Нові ліди за день (перший запис лога по кожному lead_id)
        // Беремо мінімальну дату first_at для кожного lead_id, тоді групуємо по DATE(first_at)
        $firsts_base = "SELECT lead_id, MIN(assigned_at) AS first_at FROM {$logs}";
        $firsts_where = [];
        $firsts_params = [];
        if ($date_from) {
            $firsts_where[] = 'assigned_at >= %s';
            $firsts_params[] = $date_from . ' 00:00:00';
        }
        if ($date_to) {
            $firsts_where[] = 'assigned_at <= %s';
            $firsts_params[] = $date_to . ' 23:59:59';
        }
        // Якщо задано числовий пошук — тільки цей lead_id
        if ($search !== '' && ctype_digit($search)) {
            $firsts_where[] = 'lead_id = %d';
            $firsts_params[] = (int)$search;
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

        // 2) Призначення за день по статусах (і загальна кількість рядків як assignments)
        $select_logs = "SELECT DATE(l.assigned_at) AS day,
                           COUNT(*) AS assignments,
                           SUM(CASE WHEN l.status='assigned' THEN 1 ELSE 0 END) AS assigned_cnt,
                           SUM(CASE WHEN l.status='sent'     THEN 1 ELSE 0 END) AS sent_cnt,
                           SUM(CASE WHEN l.status='failed'   THEN 1 ELSE 0 END) AS failed_cnt,
                           COUNT(DISTINCT l.lead_id) AS unique_leads
                    FROM {$logs} l";
        if ($join_posts) {
            $select_logs .= " LEFT JOIN {$wpdb->posts} p ON p.ID = l.partner_id
                          LEFT JOIN {$wpdb->posts} g ON g.ID = l.group_id";
            if ($search !== '' && !ctype_digit($search)) {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where_sql .= ($where_sql ? ' AND ' : ' WHERE ') . '(p.post_title LIKE %s OR g.post_title LIKE %s OR l.status LIKE %s)';
                array_push($params, $like, $like, $like);
            }
        } else {
            if ($search !== '' && ctype_digit($search)) {
                $where_sql .= ($where_sql ? ' AND ' : ' WHERE ') . 'l.lead_id = %d';
                $params[] = (int)$search;
            }
        }
        $by_day_totals = $wpdb->get_results(
            $wpdb->prepare(
                "{$select_logs} {$where_sql} GROUP BY DATE(l.assigned_at) ORDER BY day DESC",
                $params
            ),
            ARRAY_A
        ) ?: [];

        // 3) Призначення за день по групах
        $params_g = $params;
        $by_day_groups = $wpdb->get_results(
            $wpdb->prepare(
                "{$select_logs} {$where_sql} GROUP BY DATE(l.assigned_at), l.group_id ORDER BY day DESC",
                $params_g
            ),
            ARRAY_A
        ) ?: [];

        // 4) Призначення за день по партнерах
        $params_p = $params;
        $by_day_partners = $wpdb->get_results(
            $wpdb->prepare(
                "{$select_logs} {$where_sql} GROUP BY DATE(l.assigned_at), l.partner_id ORDER BY day DESC",
                $params_p
            ),
            ARRAY_A
        ) ?: [];

        // Індексуємо назви груп/партнерів (щоб не робити JOIN у великих агрегаціях)
        $group_titles = [];
        $partner_titles = [];

        if ($by_day_groups) {
            $gids = array_unique(array_map(fn($r) => (int)($r['l.group_id'] ?? $r['group_id'] ?? 0), $by_day_groups));
            foreach ($gids as $gid) {
                if ($gid) $group_titles[$gid] = get_the_title($gid) ?: $gid;
            }
        }
        if ($by_day_partners) {
            $pids = array_unique(array_map(fn($r) => (int)($r['l.partner_id'] ?? $r['partner_id'] ?? 0), $by_day_partners));
            foreach ($pids as $pid) {
                if ($pid) $partner_titles[$pid] = get_the_title($pid) ?: $pid;
            }
        }

        return [
            'by_day_new_leads' => $by_day_new_leads,     // [day, new_leads]
            'by_day_totals' => $by_day_totals,        // [day, assignments, assigned_cnt, sent_cnt, failed_cnt, unique_leads]
            'by_day_groups' => $by_day_groups,        // [day, group_id, assignments, ...]
            'by_day_partners' => $by_day_partners,      // [day, partner_id, assignments, ...]
            'group_titles' => $group_titles,
            'partner_titles' => $partner_titles,
        ];
    }


    protected static function render_leads_stats_block()
    {
        // Знімаємо ті ж вхідні фільтри, що й таблиця
        $args = [
            'date_from' => isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '',
            'date_to' => isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '',
            'status' => isset($_GET['lr_status']) ? sanitize_text_field($_GET['lr_status']) : '',
            'group_id' => isset($_GET['lr_group']) ? absint($_GET['lr_group']) : 0,
            'partner_id' => isset($_GET['lr_partner']) ? absint($_GET['lr_partner']) : 0,
            'search' => isset($_GET['s']) ? trim(wp_unslash($_GET['s'])) : '',
        ];
        $stats = self::fetch_leads_stats($args);

        echo '<div class="leadrouter-stats">';
        echo '<h2>' . esc_html__('Щоденна статистика', 'leadrouter') . '</h2>';

        // A) Нові ліди за день
        echo '<h3>' . esc_html__('Нові ліди за день', 'leadrouter') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Дата', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Нових лідів', 'leadrouter') . '</th>';
        echo '</tr></thead><tbody>';
        if (!empty($stats['by_day_new_leads'])) {
            foreach ($stats['by_day_new_leads'] as $r) {
                printf(
                    '<tr><td>%s</td><td>%d</td></tr>',
                    esc_html($r['day']),
                    (int)$r['new_leads']
                );
            }
        } else {
            echo '<tr><td colspan="2">' . esc_html__('Немає даних', 'leadrouter') . '</td></tr>';
        }
        echo '</tbody></table>';

        // B) Загальні призначення/статуси за день
        echo '<h3 style="margin-top:1.5em;">' . esc_html__('Призначення за день (з розбиттям по статусах)', 'leadrouter') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Дата', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Призначень', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Assigned', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Sent', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Failed', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Унікальних лідів', 'leadrouter') . '</th>';
        echo '</tr></thead><tbody>';
        if (!empty($stats['by_day_totals'])) {
            foreach ($stats['by_day_totals'] as $r) {
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
            echo '<tr><td colspan="6">' . esc_html__('Немає даних', 'leadrouter') . '</td></tr>';
        }
        echo '</tbody></table>';

        // C) По групах за день
        echo '<h3 style="margin-top:1.5em;">' . esc_html__('За день по групах', 'leadrouter') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Дата', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Група', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Призначень', 'leadrouter') . '</th>';
        echo '</tr></thead><tbody>';
        if (!empty($stats['by_day_groups'])) {
            foreach ($stats['by_day_groups'] as $r) {
                $gid = (int)($r['l.group_id'] ?? $r['group_id'] ?? 0);
                $title = $stats['group_titles'][$gid] ?? $gid;
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%d</td></tr>',
                    esc_html($r['day']),
                    esc_html($title),
                    (int)$r['assignments']
                );
            }
        } else {
            echo '<tr><td colspan="3">' . esc_html__('Немає даних', 'leadrouter') . '</td></tr>';
        }
        echo '</tbody></table>';

        // D) По партнерах за день
        echo '<h3 style="margin-top:1.5em;">' . esc_html__('За день по партнерах', 'leadrouter') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Дата', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Партнер', 'leadrouter') . '</th>';
        echo '<th>' . esc_html__('Призначень', 'leadrouter') . '</th>';
        echo '</tr></thead><tbody>';
        if (!empty($stats['by_day_partners'])) {
            foreach ($stats['by_day_partners'] as $r) {
                $pid = (int)($r['l.partner_id'] ?? $r['partner_id'] ?? 0);
                $title = $stats['partner_titles'][$pid] ?? $pid;
                printf(
                    '<tr><td>%s</td><td>%s</td><td>%d</td></tr>',
                    esc_html($r['day']),
                    esc_html($title),
                    (int)$r['assignments']
                );
            }
        } else {
            echo '<tr><td colspan="3">' . esc_html__('Немає даних', 'leadrouter') . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '</div>';
    }


    public static function ajax_get_lead_send_logs()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('leadrouter-admin-js', 'nonce');

        $lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
        if ($lead_id <= 0) {
            wp_send_json_error(['message' => 'bad_lead_id'], 400);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_send_log';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
				attempt_no, attempted_at, group_id, partner_id,
				dispatch_method, status, http_code, response_excerpt, request_json
			FROM {$table}
			WHERE lead_id = %d
			ORDER BY attempted_at DESC
			LIMIT 300",
                $lead_id
            ),
            ARRAY_A
        );

        if (!is_array($rows)) $rows = [];

        ob_start();
        ?>
        <div style="margin-bottom:10px;">
            <strong><?php echo esc_html__('Lead ID:', 'leadrouter'); ?></strong> <?php echo (int)$lead_id; ?>
        </div>

        <table class="widefat striped">
            <thead>
            <tr>
                <th><?php echo esc_html__('Attempted At', 'leadrouter'); ?></th>
                <!--<th><?php echo esc_html__('Group', 'leadrouter'); ?></th>-->
                <th><?php echo esc_html__('Partner', 'leadrouter'); ?></th>
                <th><?php echo esc_html__('Attempt', 'leadrouter'); ?></th>
                <th><?php echo esc_html__('Method', 'leadrouter'); ?></th>
                <th><?php echo esc_html__('Status', 'leadrouter'); ?></th>
                <th><?php echo esc_html__('HTTP', 'leadrouter'); ?></th>
                <th><?php echo esc_html__('Request', 'leadrouter'); ?></th>
                <th><?php echo esc_html__('Response', 'leadrouter'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="8"><?php echo esc_html__('No logs for this lead.', 'leadrouter'); ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                    $gid = (int)($r['group_id'] ?? 0);
                    $pid = (int)($r['partner_id'] ?? 0);
                    $gtitle = $gid ? get_the_title($gid) : '';
                    $ptitle = $pid ? get_the_title($pid) : '';

                    /*
                    $err = trim((string)($r['error_code'] ?? ''));
                    $msg = trim((string)($r['error_message'] ?? ''));
                    $err_cell = $err;
                    if ($msg !== '') {
                        $err_cell = $err_cell ? ($err_cell . ': ' . $msg) : $msg;
                    }*/


                    $request_json = (string)($r['request_json'] ?? '');

                    $request_preview = mb_substr($request_json, 0, 60);
                    if (mb_strlen($request_json) > 60) {
                        $request_preview .= '...';
                    }

                    $response_json = (string)($r['response_excerpt'] ?? '');

                    $response_preview = mb_substr($response_json, 0, 60);
                    if (mb_strlen($response_json) > 60) {
                        $response_preview .= '...';
                    }


                    ?>
                    <tr>
                        <td><?php echo esc_html($r['attempted_at'] ?? ''); ?></td>
                        <!--<td><?php echo esc_html($gtitle ?: ('#' . $gid)); ?></td>-->
                        <td><?php echo esc_html($ptitle ?: ('#' . $pid)); ?></td>
                        <td><?php echo esc_html((string)($r['attempt_no'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($r['dispatch_method'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($r['status'] ?? '')); ?></td>
                        <td><?php echo esc_html((string)($r['http_code'] ?? '')); ?></td>
                        <td class="lr-json-cell">
                            <code class="lr-json-preview">
                                <?php echo esc_html($request_preview); ?>
                            </code>

                            <?php if ($request_json) : ?>
                                <button
                                        type="button"
                                        class="button-link lr-json-view"
                                        data-json-raw="<?php echo esc_attr($request_json); ?>">
                                    View
                                </button>
                            <?php endif; ?>

                        </td>
                        <td class="lr-json-cell">
                            <code class="lr-json-preview">
                                <?php echo esc_html($response_preview); ?>
                            </code>

                            <?php if ($response_json) : ?>
                                <button
                                        type="button"
                                        class="button-link lr-json-view"
                                        data-json-raw="<?php echo esc_attr($response_json); ?>">
                                    View
                                </button>
                            <?php endif; ?>

                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }


    public static function ajax_get_report()
    {

        // Права
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        // nonce
        check_ajax_referer('leadrouter-admin-js', 'nonce');

        $date_from = isset($_POST['date_from']) ? trim((string)wp_unslash($_POST['date_from'])) : '';
        $date_to = isset($_POST['date_to']) ? trim((string)wp_unslash($_POST['date_to'])) : '';
        $date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_to) ? $date_to : $date_from;


        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            wp_send_json_error(['message' => 'bad_date'], 400);
        }


        // params (for invoice mode)
        $partner_id = isset($_POST['partner_id']) ? (int)$_POST['partner_id'] : 0;
        $mon_sat_rate = isset($_POST['mon_sat_rate']) ? (float)$_POST['mon_sat_rate'] : 0.0;
        $sun_rate = isset($_POST['sun_rate']) ? (float)$_POST['sun_rate'] : 0.0;

        // Викликаємо метод з LeadRouter_Leads_Table
        if (!class_exists('LeadRouter_Leads_Table')) {
            wp_send_json_error(['message' => 'missing_class'], 500);
        }

        $table = new LeadRouter_Leads_Table();

        // Якщо partner_id > 0 — функція має повернути invoice-режим,
        // якщо 0 — звичайний aggregate (старий формат)
        $report = $table->build_daily_report_by_attempted_at(
            $date_from,
            $date_to,
            $partner_id,
            $mon_sat_rate,
            $sun_rate
        );

        wp_send_json_success([
            'date_from' => $date_from,
            'date_to' => $date_to,

            // NEW echo back
            'partner_id' => $partner_id,
            'mon_sat_rate' => $mon_sat_rate,
            'sun_rate' => $sun_rate,

            // mode-aware payload
            'mode' => $report['mode'] ?? ($partner_id > 0 ? 'invoice' : 'aggregate'),
            'columns' => $report['columns'] ?? [],
            'rows' => $report['rows'] ?? [],
            'totals' => $report['totals'] ?? [], // буде тільки в invoice (якщо ти так повертаєш)
        ]);
    }


    public static function ajax_leadrouter_manual_broadcast()
    {


        // Security
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied'], 403);
        }
        check_ajax_referer('leadrouter-admin-js', 'nonce');


        $lead_id = isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0;
        $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;

        if ($lead_id <= 0) {
            wp_send_json_error(['message' => 'lead_id missing'], 400);
        }

        if (!class_exists('LeadRouter_Flow')) {
            wp_send_json_error(['message' => 'LeadRouter_Flow not loaded'], 500);
        }

        $opts = [
            'group_meta_key' => '_leadrouter_partner_group',
            'statuses' => ['queued', 'sent', 'accepted'],
            'initial_status' => 'sent',
            'dispatch_method' => 'manual_bulk',
            'queue_if_closed' => true,
        ];

        if ($group_id > 0) {
            $opts['force_group_post_id'] = $group_id;
        }

        $result = LeadRouter_Flow::dispatch_broadcast($lead_id, $opts);

        // If dispatch returns WP_Error
        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code(),
            ], 500);
        }

        // Update lead status + sent_at (per your requirement)
        /*
          global $wpdb;
          $leads_table = $wpdb->prefix . 'leadrouter_leads';

          $now = current_time('mysql'); // WP timezone
          $wpdb->update(
              $leads_table,
              [
                  'status' => 'sent',
                  'sent_at' => $now,
              ],
              ['id' => $lead_id],
              ['%s', '%s'],
              ['%d']
          );

         */

        $table = new LeadRouter_Leads_Table();
        $row_html = $table->render_row_html_by_lead_id($lead_id);

        wp_send_json_success([
            'lead_id' => $lead_id,
            'group_id' => $group_id,
            'sent_at' => $now,
            'result' => $result,
            'row_html' => $row_html,
        ]);


    }


    public static function ajax_leadrouter_manual_broadcast_bulk()
    {


        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Access denied'], 403);
        }
        check_ajax_referer('leadrouter-admin-js', 'nonce');

        $lead_ids = isset($_POST['lead_ids']) ? (array)$_POST['lead_ids'] : [];
        $lead_ids = array_values(array_filter(array_map('intval', $lead_ids)));

        $group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;

        if (empty($lead_ids)) {
            wp_send_json_error(['message' => 'No leads selected'], 400);
        }

        if (!class_exists('LeadRouter_Flow')) {
            wp_send_json_error(['message' => 'LeadRouter_Flow not loaded'], 500);
        }

        global $wpdb;
        $leads_table = $wpdb->prefix . 'leadrouter_leads';
        $now = current_time('mysql');

        $opts = [
            'group_meta_key' => '_leadrouter_partner_group',
            'statuses' => ['queued', 'sent', 'accepted'],
            'initial_status' => 'sent',
            'dispatch_method' => 'manual_bulk',
            'queue_if_closed' => true,
        ];
        if ($group_id > 0) {
            $opts['force_group_post_id'] = $group_id;
        }


        $table = class_exists('LeadRouter_Leads_Table') ? new LeadRouter_Leads_Table() : null;

        $results = [];
        $rows_html = [];

        foreach ($lead_ids as $lead_id) {

            $r = [
                'lead_id' => $lead_id,
                'ok' => false,
                'message' => '',
            ];

            $result = LeadRouter_Flow::dispatch_broadcast($lead_id, $opts);

            if (is_wp_error($result)) {
                $r['message'] = $result->get_error_message();
                $results[$lead_id] = $r;
                continue;
            }


            $r['ok'] = true;
            $r['message'] = 'sent';
            $results[$lead_id] = $r;

            // Re-render row
            if ($table && method_exists($table, 'render_row_html_by_lead_id')) {
                $rows_html[$lead_id] = $table->render_row_html_by_lead_id($lead_id);
            }
        }

        wp_send_json_success([
            'group_id' => $group_id,
            'sent_at' => $now,
            'results' => $results,      // per-lead status
            'rows_html' => $rows_html,    // per-lead <tr>...</tr>
        ]);


    }

    public static function ajax_delete_leads_cascade()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('leadrouter-admin-js', 'nonce');


        $lead_ids = [];

        if (isset($_POST['lead_ids']) && is_array($_POST['lead_ids'])) {
            $lead_ids = array_map('absint', wp_unslash($_POST['lead_ids']));
        } else {
            $lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
            if ($lead_id) {
                $lead_ids = [$lead_id];
            }
        }

        $lead_ids = array_values(array_filter(array_unique($lead_ids)));

        if (!$lead_ids) {
            wp_send_json_error(['message' => 'lead_ids missing'], 400);
        }

        $result = self::delete_leads_cascade($lead_ids);

        if (!empty($result['errors'])) {
            wp_send_json_error([
                'message' => 'delete_failed',
                'errors' => $result['errors']
            ]);
        }

        wp_send_json_success([
            'deleted' => $result['deleted'],
            'counts' => $result['counts'],
        ]);
    }


    private static function delete_leads_cascade(array $lead_ids): array
    {


        global $wpdb;

        $lead_ids = array_values(array_filter(array_unique(array_map('absint', $lead_ids))));

        if (!$lead_ids) {
            return [
                'deleted' => [],
                'counts' => [],
                'errors' => ['empty_lead_ids']
            ];
        }

        $counts = [
            'send_log' => 0,
            'partner_logs' => 0,
            'logs' => 0,
            'leads' => 0,
        ];

        $errors = [];

        $tbl_send = $wpdb->prefix . 'leadrouter_send_log';
        $tbl_plogs = $wpdb->prefix . 'leadrouter_partner_logs';
        $tbl_logs = $wpdb->prefix . 'leadrouter_logs';
        $tbl_leads = $wpdb->prefix . 'leadrouter_leads';

        $placeholders = implode(',', array_fill(0, count($lead_ids), '%d'));

        $sql = "DELETE FROM {$tbl_send} WHERE lead_id IN ($placeholders)";
        $r = $wpdb->query($wpdb->prepare($sql, $lead_ids));
        if ($r === false) $errors[] = 'send_log:' . $wpdb->last_error;
        else $counts['send_log'] = (int)$r;

        $sql = "DELETE FROM {$tbl_plogs} WHERE lead_id IN ($placeholders)";
        $r = $wpdb->query($wpdb->prepare($sql, $lead_ids));
        if ($r === false) $errors[] = 'partner_logs:' . $wpdb->last_error;
        else $counts['partner_logs'] = (int)$r;

        $sql = "DELETE FROM {$tbl_logs} WHERE lead_id IN ($placeholders)";
        $r = $wpdb->query($wpdb->prepare($sql, $lead_ids));
        if ($r === false) $errors[] = 'logs:' . $wpdb->last_error;
        else $counts['logs'] = (int)$r;

        $sql = "DELETE FROM {$tbl_leads} WHERE id IN ($placeholders)";
        $r = $wpdb->query($wpdb->prepare($sql, $lead_ids));
        if ($r === false) $errors[] = 'leads:' . $wpdb->last_error;
        else $counts['leads'] = (int)$r;

        return [
            'deleted' => $lead_ids,
            'counts' => $counts,
            'errors' => $errors
        ];
    }


}


