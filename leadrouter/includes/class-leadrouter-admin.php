<?php
if (!defined('ABSPATH')) {
    exit;
}

if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}


class LeadRouter_Admin
{


    public static function add_scripts() {


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

            wp_enqueue_script('leadrouter-admin-js', plugins_url('../assets/js/admin.js', __FILE__), ['jquery'], LEADROUTER_VERSION, true);
            wp_localize_script('leadrouter-admin-js', 'LeadRouterLogViewer', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('leadrouter-admin-js'),
            ]);
        });


        add_action('wp_ajax_leadrouter_manual_broadcast', function(){

            $lead_id  = isset($_POST['lead_id']) ? (int) $_POST['lead_id'] : 0;
            $group_id = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;

            if (!$lead_id) {
                wp_send_json_error('lead_id missing');
            }

            if (!class_exists('LeadRouter_Flow')) {
                wp_send_json_error('LeadRouter_Flow not loaded');
            }

            $opts = [
                'group_meta_key'   => '_leadrouter_partner_group',
                'statuses'         => ['queued', 'sent', 'accepted'],
                'initial_status'   => 'sent',
                'dispatch_method'  => 'manual_bulk', // лишив, як у тебе
                'queue_if_closed'  => true,
            ];

            if ($group_id > 0) {
                $opts['force_group_post_id'] = $group_id;
            }

            $result = LeadRouter_Flow::dispatch_broadcast($lead_id, $opts);

            wp_send_json_success($result);
        });



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
        add_submenu_page($menu_slug, __('Налаштування', 'leadrouter'), __('Налаштування', 'leadrouter'), $cap, 'leadrouter-settings', [__CLASS__, 'render_settings']);


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

        echo '<div class="wrap"><h1>' . esc_html__('Ліди', 'leadrouter') . '</h1>';

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

        echo '</form></div>';
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


}
