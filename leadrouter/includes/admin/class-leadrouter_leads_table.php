<?php
if (!defined('ABSPATH')) {
    exit;
}

if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LeadRouter_Leads_Table extends WP_List_Table
{

    protected static $groups_cache = null;
    protected static $partners_cache = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'leadrouter_lead',
            'plural' => 'leadrouter_leads',
            'ajax' => false,
            'screen' => get_current_screen(),
        ]);


    }

    /**
     * -------- Columns (Stage 1 minimal table) --------
     */
    public function get_columns()
    {
        return [
            'id' => __('ID', 'leadrouter'),
            'contact' => __('Contact', 'leadrouter'),
            'route' => __('Route + Vehicle', 'leadrouter'),
            /*'vehicle_ship' => __('Vehicle + Est. Ship', 'leadrouter'),*/
            'utm' => __('Utm source', 'leadrouter'),
            'created_at' => __('Created', 'leadrouter'),
            'sent_at' => __('Sent', 'leadrouter'),
            'group_with_partner' => __('Group with Partner', 'leadrouter'),
            'status' => __('Status', 'leadrouter'),
            'send' => __('Send to', 'leadrouter'),
            'actions' => __('Actions', 'leadrouter'),
        ];
    }

    protected function get_sortable_columns()
    {
        return [
            'created_at' => ['created_at', true],  // default DESC
            'sent_at' => ['sent_at', false],
            'status' => ['status', false],
        ];
    }

    public function get_bulk_actions()
    {
        // Stage 1: no bulk actions
        return [];
    }

    public function extra_tablenav($which)
    {

        if ($which === 'top') {

            // UTM Stats Panel
            $this->render_utm_stats_panel();

            // ... твої існуючі фільтри

            $groups = $this->get_groups_for_select();

            echo '<div class="alignleft actions lr-bulk-send">';

            echo '<select class="lr-bulk-group-select" style="max-width:220px;">';
            echo '<option value="0">' . esc_html__('In flow', 'leadrouter') . '</option>';
            foreach ($groups as $g) {
                $gid = (int)($g->ID ?? 0);
                if ($gid <= 0) continue;
                $title = !empty($g->post_title) ? (string)$g->post_title : ('Group #' . $gid);
                echo '<option value="' . esc_attr($gid) . '">' . esc_html($title) . '</option>';
            }
            echo '</select>';

            echo ' <button type="button" class="button lr-bulk-send-btn">📣 ' . esc_html__('Send selected', 'leadrouter') . '</button>';
            echo ' <button type="button" class="button lr-bulk-delete-btn" style="margin-left:6px;">🗑 Delete selected</button>';
            echo ' <span class="lr-bulk-status" aria-live="polite" style="margin-left:8px;"></span>';

            echo '</div>';


            if ($which !== 'top') {
                return;
            }

            $created_from = isset($_GET['created_from']) ? sanitize_text_field(wp_unslash($_GET['created_from'])) : '';
            $created_to   = isset($_GET['created_to'])   ? sanitize_text_field(wp_unslash($_GET['created_to']))   : '';
            $utm_source   = isset($_GET['utm_source'])   ? sanitize_text_field(wp_unslash($_GET['utm_source']))   : '';
            $status       = isset($_GET['lr_status'])    ? sanitize_text_field(wp_unslash($_GET['lr_status']))    : '';
            $group_id     = isset($_GET['group_id'])     ? absint($_GET['group_id']) : 0;

            $utm_opts   = $this->get_utm_sources_options();
            $group_opts = $this->get_groups_options();
            $status_opts = $this->get_status_options();

            echo '<div class="alignleft actions">';

            // created_at from/to
            echo '<label style="margin-right:6px;">Created:</label>';
            echo '<input type="date" name="created_from" value="' . esc_attr($created_from) . '" />';
            echo '<span style="padding:0 6px;">—</span>';
            echo '<input type="date" name="created_to" value="' . esc_attr($created_to) . '" />';

            // utm_source
            echo '<select name="utm_source" style="margin-left:8px;">';
            foreach ($utm_opts as $val => $label) {
                echo '<option value="' . esc_attr((string)$val) . '" ' . selected($utm_source, (string)$val, false) . '>' . esc_html((string)$label) . '</option>';
            }
            echo '</select>';

            // status
            echo '<select name="lr_status" style="margin-left:8px;">';
            foreach ($status_opts as $val => $label) {
                echo '<option value="' . esc_attr((string)$val) . '" ' . selected($status, (string)$val, false) . '>' . esc_html((string)$label) . '</option>';
            }
            echo '</select>';

            // group_id
            echo '<select name="group_id" style="margin-left:8px;">';
            foreach ($group_opts as $val => $label) {
                $v = (string)$val;
                echo '<option value="' . esc_attr($v) . '" ' . selected((string)$group_id, $v, false) . '>' . esc_html((string)$label) . '</option>';
            }
            echo '</select>';

            submit_button(
                __('Filter'),
                '',
                'filter_action',
                false,
                ['style' => 'margin-left:8px;']
            );

            $reset_url = remove_query_arg([
                'created_from',
                'created_to',
                'utm_source',
                'lr_status',
                'group_id',
                's',
                'paged'
            ]);

            echo '<a href="' . esc_url($reset_url) . '" class="button" style="margin-left:6px;">Reset</a>';

            echo '</div>';
        }

    }


    public function search_box($text, $input_id)
    {
        // Stage 1: no search box
    }

    /**
     * Render UTM Statistics Panel with Chart
     */
    protected function render_utm_stats_panel()
    {
        global $wpdb;
        $leads_table = $wpdb->prefix . 'leadrouter_leads';

        // Get current filters
        $created_from = isset($_GET['created_from']) ? sanitize_text_field($_GET['created_from']) : '';
        $created_to   = isset($_GET['created_to'])   ? sanitize_text_field($_GET['created_to'])   : '';
        $utm_source   = isset($_GET['utm_source'])   ? sanitize_text_field($_GET['utm_source'])   : '';
        $status       = isset($_GET['lr_status'])    ? sanitize_text_field($_GET['lr_status'])    : '';

        // Build WHERE clause
        $where = [];
        $params = [];

        if ($created_from) {
            $where[] = 'created_at >= %s';
            $params[] = $created_from . ' 00:00:00';
        }
        if ($created_to) {
            $where[] = 'created_at <= %s';
            $params[] = $created_to . ' 23:59:59';
        }
        if ($utm_source) {
            $where[] = 'utm_source = %s';
            $params[] = $utm_source;
        }
        if ($status) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $where_sql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

        // Total leads
        $total_leads = (int)$wpdb->get_var(
            $params ? $wpdb->prepare("SELECT COUNT(*) FROM {$leads_table} {$where_sql}", $params) : "SELECT COUNT(*) FROM {$leads_table} {$where_sql}"
        );

        // Leads without UTM
        $no_utm_where = $where;
        $no_utm_params = $params;
        $no_utm_where[] = "(utm_source IS NULL OR utm_source = '')";
        $no_utm_where_sql = ' WHERE ' . implode(' AND ', $no_utm_where);
        $missing_utm = (int)$wpdb->get_var(
            $no_utm_params ? $wpdb->prepare("SELECT COUNT(*) FROM {$leads_table} {$no_utm_where_sql}", $no_utm_params) : "SELECT COUNT(*) FROM {$leads_table} {$no_utm_where_sql}"
        );

        // UTM sources stats
        $utm_where = $where;
        $utm_params = $params;
        $utm_where[] = 'utm_source IS NOT NULL AND utm_source <> %s';
        $utm_params[] = '';
        $utm_where_sql = ' WHERE ' . implode(' AND ', $utm_where);
        
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT utm_source, COUNT(*) AS total FROM {$leads_table} {$utm_where_sql} GROUP BY utm_source ORDER BY total DESC",
                $utm_params
            ),
            ARRAY_A
        ) ?: [];

        $chart_labels = [];
        $chart_data = [];
        foreach ($rows as $row) {
            $chart_labels[] = $row['utm_source'];
            $chart_data[] = (int)$row['total'];
        }

        // Render panel
        echo '<div class="lr-utm-stats-panel">';
        echo '<div class="lr-utm-stats-header">';
        echo '<h3>UTM статистика лидов</h3>';
        echo '<span class="lr-utm-stats-toggle">▼</span>';
        echo '</div>';
        echo '<div class="lr-utm-stats-body">';

        echo '<div class="lr-utm-stats-summary">';
        echo '<div class="lr-utm-stat-card"><strong>' . $total_leads . '</strong><span>Всего лидов</span></div>';
        echo '<div class="lr-utm-stat-card"><strong>' . count($rows) . '</strong><span>Источников UTM</span></div>';
        echo '<div class="lr-utm-stat-card"><strong>' . $missing_utm . '</strong><span>Без UTM</span></div>';
        echo '</div>';

        if (!empty($rows)) {
            echo '<div class="lr-utm-chart-container"><canvas id="lrUtmChart"></canvas></div>';
            echo '<script>';
            echo 'document.addEventListener("DOMContentLoaded", function() {';
            echo '  var ctx = document.getElementById("lrUtmChart");';
            echo '  if (ctx && typeof Chart !== "undefined") {';
            echo '    new Chart(ctx, {';
            echo '      type: "bar",';
            echo '      data: {';
            echo '        labels: ' . wp_json_encode($chart_labels) . ',';
            echo '        datasets: [{ label: "Лиды", data: ' . wp_json_encode($chart_data) . ', backgroundColor: "rgba(34, 113, 177, 0.7)" }]';
            echo '      },';
            echo '      options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { display: false } } }';
            echo '    });';
            echo '  }';
            echo '});';
            echo '</script>';
        }

        echo '</div></div>';
    }

    /**
     * Make Contact the primary column (for row actions style, etc).
     */
    protected function get_default_primary_column_name()
    {
        return 'contact';
    }

    /**
     * -------- Row rendering --------
     */
    public function column_default($item, $column_name)
    {

        switch ($column_name) {
            case 'id':
                return $this->render_id($item);
            case 'contact':
                return wp_kses_post($this->render_contact($item));

            case 'route':
                return wp_kses_post($this->render_route_full($item)) . '<strong>VEHICLE:</strong><br/>' . wp_kses_post($this->render_vehicle_ship($item));

           /* case 'vehicle_ship':
                return wp_kses_post($this->render_vehicle_ship($item));*/
            case 'utm':
                return $this->render_utm($item);
            case 'created_at':
                return esc_html($this->fmt_dt($item['created_at'] ?? ''));

            case 'sent_at':
                $val = $item['sent_at'] ?? '';
                return $val ? esc_html($this->fmt_dt($val)) : '—';

            case 'group_with_partner':
                return wp_kses_post($this->render_group_with_partner($item));

            case 'status':
                return wp_kses_post($this->render_status($item));

            case 'send':
                return $this->render_send_control($item);

            case 'actions':
                return wp_kses_post($this->render_actions($item));

            default:
                return '';
        }
    }

    public function single_row($item)
    {
        $id = (int)($item['id'] ?? 0);
        echo '<tr id="leadrow-' . esc_attr($id) . '">';
        $this->single_row_columns($item);
        echo '</tr>';
    }


    /**
     * REnder ID: id
     */
    protected function render_id($r)
    {
        $id = $r['id'] ?? '';


        if ($id !== '') {
            $lines[] = '<input type="checkbox" class="lr-lead-cb" name="lead_id[]" value="' . esc_attr($id) . '" /> <strong>' . esc_html($id) . '</strong>';
        }

        return $lines ? implode('<br>', $lines) : '—';
    }

    protected function render_utm($r)
    {
        $fields = [
            'utm_source',
            //'utm_medium',
            //'utm_campaign',
            //'utm_content',
            //'utm_term',
        ];

        $rows = [];

        foreach ($fields as $key) {

            $val = isset($r[$key]) ? trim((string)$r[$key]) : '';

            if ($val === '') {
                continue;
            }

            $rows[] =
                '<div class="lr-utm-row">' .
                //'<span class="lr-utm-key">' . esc_html($key) . ':</span> ' .
                '<span class="lr-utm-val">' . esc_html($val) . '</span>' .
                '</div>';
        }

        $html = implode('', $rows);

        return $html ? $html : '—';
    }

    protected function render_status($r)
    {
        $id = $r['id'] ?? '';
        $status = $r['status'] ?? '';

        $html = '<div class="md_flex">';
        switch ($status) {
            case 'new':
                $html .= '<i class="md_check_new"><span>New</span></i>';
                break;
            case 'sent':
                $html .= '<i class="md_check_success"><span>Success</span></i>';
                break;
            case 'processed':
                $html .= '<i class="md_check_success"><span>Success</span></i>';
                break;
            case 'await':
                $html .= '<i class="md_check_await"><span>Await</span></i>';
                break;
            case 'error':
                $html .= '<i class="md_check_error"><span>Error</span></i>';
                break;
            case 'state_error':
                $html .= '<i class="md_check_overlimit"><span>State Error (AK.HI)</span></i>';
                break;
        }
        $html .= '</div>';


        return $html ? $html : '—';
    }

    /**
     * Contact: name / phone / email
     */
    protected function render_contact($r)
    {
        $name = $r['name'] ?? '';
        $email = $r['email'] ?? '';
        $phone = $r['phone'] ?? '';

        $lines = [];
        if ($name !== '') {
            $lines[] = '<strong>' . esc_html($name) . '</strong>';
        }
        if ($phone !== '') {
            $lines[] = sprintf('<a href="tel:%1$s">%2$s</a>', esc_attr($phone), esc_html($phone));
        }
        if ($email !== '') {
            $lines[] = sprintf('<a href="mailto:%1$s">%1$s</a>', esc_attr($email));
        }

        return $lines ? implode('<br>', $lines) : '—';
    }

    /**
     * Route (FULL): FROM/TO city, state, zip, each on its own block.
     */
    protected function render_route_full($r)
    {
        $from = $this->fmt_place(
            $r['from_city'] ?? '',
            $r['from_state'] ?? '',
            $r['from_zip'] ?? ''
        );

        $to = $this->fmt_place(
            $r['to_city'] ?? '',
            $r['to_state'] ?? '',
            $r['to_zip'] ?? ''
        );

        $out = '<div class="lr-route">';
        $out .= '<div><strong>' . esc_html__('FROM:', 'leadrouter') . '</strong><br>' . $from . '</div>';
        $out .= '<div style="margin-top:6px;"><strong>' . esc_html__('TO:', 'leadrouter') . '</strong><br>' . $to . '</div>';
        $out .= '</div>';

        return $out;
    }

    protected function fmt_place($city, $state, $zip)
    {
        $city = trim((string)$city);
        $state = trim((string)$state);
        $zip = trim((string)$zip);

        $parts = array_values(array_filter([$city, $state, $zip], function ($v) {
            return $v !== '';
        }));

        return $parts ? esc_html(implode(', ', $parts)) : '—';
    }

    /**
     * Vehicle + Est. Ship in one column.
     */
    protected function render_vehicle_ship($r)
    {
        $brand = trim((string)($r['vehicle_brand'] ?? ''));
        $model = trim((string)($r['vehicle_model'] ?? ''));
        $year = (string)($r['vehicle_year'] ?? '');
        $ship = (string)($r['est_ship_date'] ?? '');

        $line1_parts = array_values(array_filter([$brand, $model, $year], function ($v) {
            return trim((string)$v) !== '';
        }));
        $line1 = $line1_parts ? esc_html(implode(' ', $line1_parts)) : '—';

        $line2 = $ship ? esc_html__('Ship:', 'leadrouter') . ' ' . esc_html($ship) : esc_html__('Ship:', 'leadrouter') . ' —';

        return $line1 . '<br>' . $line2;
    }

    /**
     * Group with Partner:
     * - ONLY from send_log
     * - Variant B: only last status per partner within group
     * - Variant 2: groups ordered by last activity (MAX attempted_at) DESC
     * - Partners inside group ordered by last attempted_at DESC
     *
     * prepare_items() precomputes $item['_lr_send_agg'] structure.
     */
    protected function render_group_with_partner($item)
    {
        $agg = $item['_lr_send_agg'] ?? null;
        if (!is_array($agg) || empty($agg['groups'])) {
            return '—';
        }

        $out = '<div class="lr-gwp">';

        foreach ($agg['groups'] as $g) {
            $gid = (int)($g['group_id'] ?? 0);
            $gtitle = $gid ? $this->get_group_title($gid) : esc_html__('Group #0', 'leadrouter');

            $out .= '<div class="lr-gwp-group" style="margin-bottom:8px;">';
            $out .= '<div><strong>' . esc_html($gtitle) . '</strong></div>';

            if (!empty($g['partners']) && is_array($g['partners'])) {
                $out .= '<ul style="margin:4px 0 0 18px;">';
                foreach ($g['partners'] as $p) {
                    $pid = (int)($p['partner_id'] ?? 0);
                    $ptitle = $pid ? $this->get_partner_title($pid) : ('Partner #' . $pid);

                    $status = (string)($p['status'] ?? '');
                    $label = $status !== '' ? ($ptitle . ' (' . $status . ')') : $ptitle;

                    $out .= '<li>' . esc_html($label) . '</li>';
                }
                $out .= '</ul>';
            }

            $out .= '</div>';
        }

        $out .= '</div>';
        return $out;
    }

    protected function get_group_title($group_id)
    {
        $group_id = (int)$group_id;
        if ($group_id <= 0) return 'Group #' . $group_id;

        // Cache CPT list (lightweight)
        if (self::$groups_cache === null) {
            $groups = get_posts([
                'post_type' => 'leadrouter_group',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'orderby' => 'title',
                'order' => 'ASC',
                'fields' => 'ids',
            ]);
            self::$groups_cache = is_array($groups) ? $groups : [];
        }

        $title = get_the_title($group_id);
        return $title ? $title : ('Group #' . $group_id);
    }

    protected function get_partner_title($partner_id)
    {
        $partner_id = (int)$partner_id;
        if ($partner_id <= 0) return 'Partner #' . $partner_id;

        if (isset(self::$partners_cache[$partner_id])) {
            return self::$partners_cache[$partner_id];
        }

        $title = get_the_title($partner_id);
        if (!$title) $title = 'Partner #' . $partner_id;

        self::$partners_cache[$partner_id] = $title;
        return $title;
    }

    /**
     * Send control: select group + Send button (existing JS hook)
     */
    /**
     * Column "Send to": group select + send button + inline status area
     */
    protected function render_send_control($item)
    {
        $lead_id = (int)($item['id'] ?? 0);
        if ($lead_id <= 0) return '';

        $groups = $this->get_groups_for_select();

        $out = '<div class="lr-broadcast-inline" data-lead-id="' . esc_attr($lead_id) . '">';

        // Select
        $out .= '<div class="md_flex"><select class="lr-group-select" data-lead-id="' . esc_attr($lead_id) . '">';
        $out .= '<option value="0">' . esc_html__('In flow', 'leadrouter') . '</option>';

        foreach ($groups as $g) {
            $gid = (int)($g->ID ?? 0);
            if ($gid <= 0) continue;

            $title = !empty($g->post_title) ? (string)$g->post_title : ('Group #' . $gid);
            $out .= '<option value="' . esc_attr($gid) . '">' . esc_html($title) . '</option>';
        }

        $out .= '</select>';

        // Button
        $out .= ' <button type="button" class="button button-small lr-broadcast-btn" data-id="' . esc_attr($lead_id) . '">'
            . esc_html__('Send', 'leadrouter')
            . '</button></div>';

        // Inline status placeholder (JS writes here)
        $out .= '<div class="md_flex"><span class="lr-inline-status" aria-live="polite" style="margin-left:8px;"></span></div>';

        $out .= '</div>';

        return $out;
    }


    protected function get_groups_for_select()
    {
        if (self::$groups_cache !== null && is_array(self::$groups_cache) && self::$groups_cache && is_object(self::$groups_cache[0])) {
            return self::$groups_cache;
        }

        $groups = get_posts([
            'post_type' => 'leadrouter_group',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        self::$groups_cache = is_array($groups) ? $groups : [];
        return self::$groups_cache;
    }

    /**
     * Actions: Delete / Logs / Edit (placeholder)
     */
    protected function render_actions($item)
    {
        $lead_id = (int)($item['id'] ?? 0);
        if ($lead_id <= 0) return '';

        // Delete URL (handled by process_bulk_action)
        $nonce = wp_create_nonce('lr_delete_lead_' . $lead_id);
        $del_url = add_query_arg([
            'page' => 'leadrouter-leads',
            'action' => 'lr_delete_lead',
            'lead_id' => $lead_id,
            '_wpnonce' => $nonce,
        ], admin_url('admin.php'));


        $logs_btn = '<button type="button" class="button-link lr-view-logs" data-lead-id="' . $lead_id . '" ><i class="md_check_logs"><span>Show logs</span></i></button>';


        // Edit placeholder (future)
        //$edit_html = '<span style="opacity:.45; cursor:not-allowed;" title="' . esc_attr__('Edit (soon)', 'leadrouter') . '">✏</span>';

        $out = '<div class="lr-actions" style="display:flex; gap:10px; align-items:center;">';
        $out .= $logs_btn;
        $out .= '<button type="button" class="button-link lr-lead-delete" data-lead-id="' . $lead_id . '"><i class="md_check_delete"><span>Delete lead</span></i></button>';
        //$out .= $edit_html;
        $out .= '</div>';

        return $out;
    }

    protected function fmt_dt($val)
    {
        $val = trim((string)$val);
        if ($val === '' || $val === '0000-00-00 00:00:00') return '—';

        $ts = strtotime($val);
        return $ts ? date_i18n('Y-m-d H:i:s', $ts) : $val;
    }

    /**
     * Handle delete action (single-row delete).
     */
    public function process_bulk_action()
    {
        if ($this->current_action() !== 'lr_delete_lead') {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'leadrouter'));
        }

        $lead_id = isset($_GET['lead_id']) ? absint($_GET['lead_id']) : 0;
        if ($lead_id <= 0) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
        if (!wp_verify_nonce($nonce, 'lr_delete_lead_' . $lead_id)) {
            wp_die(esc_html__('Invalid nonce.', 'leadrouter'));
        }

        global $wpdb;
        $leads_table = $wpdb->prefix . 'leadrouter_leads';
        $send_table = $wpdb->prefix . 'leadrouter_send_log';

        // delete logs first, then lead
        $wpdb->delete($send_table, ['lead_id' => $lead_id], ['%d']);
        $wpdb->delete($leads_table, ['id' => $lead_id], ['%d']);

        // redirect back without action params
        $redirect = remove_query_arg(['action', 'lead_id', '_wpnonce'], wp_get_referer() ?: admin_url('admin.php?page=leadrouter-leads'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * -------- Data loading (Stage 1) --------
     */

    public function prepare_items()
    {
        global $wpdb;

        $table      = $wpdb->prefix . 'leadrouter_leads';
        $send_table = $wpdb->prefix . 'leadrouter_send_log';

        $per_page     = $this->get_items_per_page('leadrouter_leads_per_page', 20);
        $current_page = max(1, (int)$this->get_pagenum());
        $offset       = ($current_page - 1) * $per_page;

        // --- filters (GET) ---
        $created_from = isset($_GET['created_from']) ? sanitize_text_field(wp_unslash($_GET['created_from'])) : '';
        $created_to   = isset($_GET['created_to'])   ? sanitize_text_field(wp_unslash($_GET['created_to']))   : '';
        $utm_source   = isset($_GET['utm_source'])   ? sanitize_text_field(wp_unslash($_GET['utm_source']))   : '';
        $status       = isset($_GET['lr_status'])    ? sanitize_text_field(wp_unslash($_GET['lr_status']))    : '';
        $group_id     = isset($_GET['group_id'])     ? absint($_GET['group_id']) : 0;

        // --- search ---
        $search = isset($_REQUEST['s']) ? trim((string)wp_unslash($_REQUEST['s'])) : '';

        // --- sorting ---
        $orderby = isset($_GET['orderby']) ? sanitize_text_field(wp_unslash($_GET['orderby'])) : 'created_at';
        $order   = isset($_GET['order'])   ? strtoupper(sanitize_text_field(wp_unslash($_GET['order']))) : 'DESC';

        $allowed_orderby = [
            'id'         => 'l.id',
            'created_at' => 'l.created_at',
            'status'     => 'l.status',
            'sent_at'    => 'l.sent_at',
        ];
        $orderby_sql = $allowed_orderby[$orderby] ?? 'l.created_at';
        $order_sql   = in_array($order, ['ASC','DESC'], true) ? $order : 'DESC';

        // --- WHERE builder ---
        $where  = "WHERE 1=1";
        $params = [];

        // created_at (inclusive by day) — IMPORTANT: use same TZ as stored in DB (EST in your project)
        if ($created_from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_from)) {

            $tz = new DateTimeZone('America/New_York'); // якщо у вас інша “DB timezone” — заміни тут

            $fromDT = (new DateTimeImmutable($created_from . ' 00:00:00', $tz))->format('Y-m-d H:i:s');

            $to_base = ($created_to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $created_to))
                ? $created_to
                : $created_from;

            $toDT = (new DateTimeImmutable($to_base . ' 00:00:00', $tz))
                ->modify('+1 day')
                ->format('Y-m-d H:i:s');

            $where .= " AND l.created_at >= %s AND l.created_at < %s";
            $params[] = $fromDT;
            $params[] = $toDT;
        }

        if ($utm_source !== '') {
            $where .= " AND l.utm_source = %s";
            $params[] = $utm_source;
        }

        if ($status !== '') {
            $where .= " AND l.status = %s";
            $params[] = $status;
        }

        // group filter via send_log (бо group береться з send_log, а не з leadrouter_leads)
        if ($group_id > 0) {
            $where .= " AND EXISTS (
            SELECT 1 FROM {$send_table} sfx
            WHERE sfx.lead_id = l.id AND sfx.group_id = %d
        )";
            $params[] = $group_id;
        }

        // search (мінімальний, під себе розшириш)
        if ($search !== '') {

            if (ctype_digit($search)) {
                $where .= " AND l.id = %d";
                $params[] = (int)$search;
            } else {
                $like = '%' . $wpdb->esc_like($search) . '%';
                $where .= " AND (
                l.name  LIKE %s OR
                l.email LIKE %s OR
                l.phone LIKE %s OR
                l.utm_source LIKE %s
            )";
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
        }

        // --- TOTAL (same WHERE) ---
        $sql_total = "SELECT COUNT(*) FROM {$table} l {$where}";
        $total_items = (int)$wpdb->get_var($wpdb->prepare($sql_total, $params));

        // --- ROWS ---
        $sql_rows = "
        SELECT
            l.*,
            (
                SELECT CONCAT(COALESCE(NULLIF(s.group_id,0),0), ':', COALESCE(NULLIF(s.partner_id,0),0))
                FROM {$send_table} s
                WHERE s.lead_id = l.id
                ORDER BY s.attempted_at DESC, s.id DESC
                LIMIT 1
            ) AS group_with_partner
        FROM {$table} l
        {$where}
        ORDER BY {$orderby_sql} {$order_sql}
        LIMIT %d OFFSET %d
    ";



        $rows_params = $params;
        $rows_params[] = $per_page;
        $rows_params[] = $offset;

        $this->items = $wpdb->get_results($wpdb->prepare($sql_rows, $rows_params), ARRAY_A);

        // ==============================
// BATCH: build _lr_send_agg for all leads on the current page
// ==============================
        if (is_array($this->items) && !empty($this->items)) {

            // 1) collect lead IDs from current page
            $lead_ids = [];
            foreach ($this->items as $it) {
                $lid = (int)($it['id'] ?? 0);
                if ($lid > 0) $lead_ids[] = $lid;
            }
            $lead_ids = array_values(array_unique($lead_ids));

            if (!empty($lead_ids)) {

                // 2) fetch send_log rows for these leads in one query
                $placeholders = implode(',', array_fill(0, count($lead_ids), '%d'));

                // IMPORTANT: order so that newest attempts come first per (lead, group, partner)
                $sql_send = "
            SELECT lead_id, group_id, partner_id, attempted_at, status, id
            FROM {$send_table}
            WHERE lead_id IN ({$placeholders})
            ORDER BY lead_id ASC, group_id ASC, partner_id ASC, attempted_at DESC, id DESC
        ";

                $send_rows = $wpdb->get_results($wpdb->prepare($sql_send, $lead_ids), ARRAY_A);

                // 3) build map: send_by_lead[lead_id][group_id][partner_id] = last row (Variant B)
                $send_by_lead = [];

                if (is_array($send_rows)) {
                    foreach ($send_rows as $sr) {

                        $lid = (int)($sr['lead_id'] ?? 0);
                        if ($lid <= 0) continue;

                        $gid = (int)($sr['group_id'] ?? 0);
                        $pid = (int)($sr['partner_id'] ?? 0);

                        // if partner_id can be 0 in logs, we still keep it (your renderer can show Partner #0)
                        $ts = (string)($sr['attempted_at'] ?? '');
                        $st = (string)($sr['status'] ?? '');

                        if (!isset($send_by_lead[$lid])) $send_by_lead[$lid] = [];
                        if (!isset($send_by_lead[$lid][$gid])) $send_by_lead[$lid][$gid] = [];

                        // Because of ORDER BY ... attempted_at DESC, id DESC:
                        // the first row we meet for (lid,gid,pid) is already the "latest" -> Variant B
                        if (!isset($send_by_lead[$lid][$gid][$pid])) {
                            $send_by_lead[$lid][$gid][$pid] = [
                                'partner_id'   => $pid,
                                'attempted_at' => $ts,
                                'status'       => $st,
                            ];
                        }
                    }
                }

                // 4) attach _lr_send_agg into each item
                foreach ($this->items as &$it) {
                    $lid = (int)($it['id'] ?? 0);
                    $by_group = $send_by_lead[$lid] ?? [];
                    $it['_lr_send_agg'] = $this->build_send_agg_for_lead($by_group);
                }
                unset($it);
            }
        }

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => (int)ceil($total_items / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    }

    /**
     * Build final structure for render_group_with_partner:
     * - Groups order: by group_last_ts DESC (Variant 2)
     * - Partners order: by attempted_at DESC
     */
    protected function build_send_agg_for_lead($by_group)
    {
        if (!is_array($by_group) || empty($by_group)) {
            return ['groups' => []];
        }

        $groups = [];

        foreach ($by_group as $gid => $partners_map) {
            $gid = (int)$gid;
            if (!is_array($partners_map) || empty($partners_map)) continue;

            $partners = array_values($partners_map);

            // sort partners by last attempted_at DESC
            usort($partners, function ($a, $b) {
                $ta = strtotime((string)($a['attempted_at'] ?? '')) ?: 0;
                $tb = strtotime((string)($b['attempted_at'] ?? '')) ?: 0;
                return $tb <=> $ta;
            });

            // group last activity = max attempted_at
            $group_last_ts = 0;
            foreach ($partners as $p) {
                $t = strtotime((string)($p['attempted_at'] ?? '')) ?: 0;
                if ($t > $group_last_ts) $group_last_ts = $t;
            }

            $groups[] = [
                'group_id' => $gid,
                'group_last_ts' => $group_last_ts,
                'partners' => $partners,
            ];
        }

        // sort groups by last activity DESC (Variant 2)
        usort($groups, function ($a, $b) {
            return (int)($b['group_last_ts'] ?? 0) <=> (int)($a['group_last_ts'] ?? 0);
        });

        return ['groups' => $groups];
    }


    public function maybe_print_logs_modal()
    {

        ?>

        <?php
    }

    public function build_daily_report_by_attempted_at($date_from, $date_to, $partner_id = 0, $mon_sat_rate = 0.0, $sun_rate = 0.0)
    {
        global $wpdb;

        $leads_table = $wpdb->prefix . 'leadrouter_leads';
        $send_table = $wpdb->prefix . 'leadrouter_send_log';

        // normalize dates (YYYY-MM-DD)
        $date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_from) ? $date_from : date('Y-m-d');
        $date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date_to) ? $date_to : $date_from;

        $dt_from = $date_from . ' 00:00:00';
        $dt_to = $date_to . ' 23:59:59';

        $partner_id = (int)$partner_id;
        $mon_sat_rate = (float)$mon_sat_rate;
        $sun_rate = (float)$sun_rate;

        /**
         * =========================
         * MODE B: INVOICE (partner_id > 0)
         * =========================
         */
        if ($partner_id > 0) {

            // агрегуємо тільки для конкретного партнера
            $base_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT
                    DATE(s.attempted_at) AS day,
                    COUNT(DISTINCT CASE WHEN s.status = 'success' THEN s.lead_id END) AS leads_sent,
                    COUNT(DISTINCT CASE
                        WHEN (
                            s.status IN ('failed','error','hard_fail')
                            OR (s.reason_code IS NOT NULL AND TRIM(s.reason_code) <> '')
                            OR (s.http_code IS NOT NULL AND s.http_code >= 400)
                        )
                        THEN s.lead_id
                    END) AS errors
                 FROM {$send_table} s
                 WHERE s.attempted_at BETWEEN %s AND %s
                   AND s.partner_id = %d
                 GROUP BY DATE(s.attempted_at)
                 ORDER BY day ASC",
                    $dt_from, $dt_to, $partner_id
                ),
                ARRAY_A
            );
            if (!is_array($base_rows)) $base_rows = [];

            $by_day = [];
            foreach ($base_rows as $r) {
                $day = (string)($r['day'] ?? '');
                if ($day === '') continue;

                $by_day[$day] = [
                    'leads_sent' => (int)($r['leads_sent'] ?? 0),
                    'errors' => (int)($r['errors'] ?? 0),
                ];
            }

            $dow = [
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
                7 => 'Sunday',
            ];

            $tz = wp_timezone();
            $cur = new DateTime($date_from, $tz);
            $end = new DateTime($date_to, $tz);
            $end->setTime(0, 0, 0)->modify('+1 day'); // exclusive

            $rows = [];
            $totals = [
                'Day count' => 0,
                'Day defined' => 0,
                'Rate' => ' - ',
                //'Leads Sent' => 0,
                'Accepted' => 0,
                //'Errors'     => 0,
                'Gross' => 0.0,
                //'Deduction'  => 0.0,
                'Total Due' => 0.0,
            ];

            $count_day = 0;
            while ($cur < $end) {
                $count_day++;
                $date = $cur->format('Y-m-d');
                $n = (int)$cur->format('N');
                $day_name = $dow[$n] ?? $cur->format('l');

                $rate = ($n === 7) ? $sun_rate : $mon_sat_rate;

                $leads_sent = $by_day[$date]['leads_sent'] ?? 0;
                $errors = $by_day[$date]['errors'] ?? 0;


                $accepted = $leads_sent;

                $gross = $accepted * $rate;
                $deduction = 0.0;
                $total_due = $gross - $deduction;

                $rows[] = [
                    'Date' => $date,
                    'Day' => $day_name,
                    'Rate' => $rate,
                    'Accepted' => $accepted,
                    'Gross' => $gross,
                    'Total Due' => $total_due,
                    //'Leads Sent'=> $leads_sent,
                    //'Deduction' => $deduction,
                    //'Errors'    => $errors,
                ];

                $totals['Day count'] = $count_day;
                $totals['Day defined'] = count($by_day);
                //$totals['Leads Sent'] += $leads_sent;
                $totals['Accepted'] += $accepted;
                //$totals['Errors']     += $errors;
                $totals['Gross'] += $gross;
                //$totals['Deduction']  += $deduction;
                $totals['Total Due'] += $total_due;

                $cur->modify('+1 day');

            }


            return [
                'mode' => 'invoice',
                'columns' => ['Date', 'Day',/*'Leads Sent',*/ 'Rate', 'Accepted',/*'Errors',*/ 'Gross',/*'Deduction',*/ 'Total Due'],
                'rows' => $rows,
                'totals' => $totals,
                'partner_id' => $partner_id,
                'rates' => [
                    'mon_sat_rate' => $mon_sat_rate,
                    'sun_rate' => $sun_rate,
                ],
            ];
        }

        /**
         * =========================
         * MODE A: AGGREGATE (partner_id пустий)
         * =========================
         * Це твій старий код майже без змін.
         */

        // Reserved columns (so source names don't accidentally overwrite them)
        $reserved = [
            'Date' => true,
            'Total Leads' => true,
            'Success' => true,
            //'Error' => true,
        ];

        $normalize_source_col = function ($src) use ($reserved) {
            $src = trim((string)$src);
            if ($src === '') $src = 'Organic';
            if (isset($reserved[$src])) {
                return 'Source: ' . $src;
            }
            return $src;
        };

        // 1) Sources list
        $raw_sources = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT
                COALESCE(NULLIF(TRIM(l.utm_source), ''), 'Organic') AS src
             FROM {$send_table} s
             INNER JOIN {$leads_table} l ON l.id = s.lead_id
             WHERE s.attempted_at BETWEEN %s AND %s
             ORDER BY src ASC",
                $dt_from, $dt_to
            )
        );
        if (!is_array($raw_sources)) $raw_sources = [];
        if (!in_array('Organic', $raw_sources, true)) $raw_sources[] = 'Organic';

        $source_col_map = [];
        foreach ($raw_sources as $src) {
            $src = (string)$src;
            $col = $normalize_source_col($src);
            $source_col_map[$src] = ucfirst($col);
        }
        asort($source_col_map, SORT_NATURAL | SORT_FLAG_CASE);
        $sources_cols = array_values($source_col_map);

        // 2) Partners list
        $partner_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT partner_id
             FROM {$send_table}
             WHERE attempted_at BETWEEN %s AND %s
             ORDER BY partner_id ASC",
                $dt_from, $dt_to
            )
        );
        if (!is_array($partner_ids)) $partner_ids = [];

        $partners = [];
        foreach ($partner_ids as $pid) {
            $pid = (int)$pid;
            if ($pid <= 0) continue;

            $title = get_the_title($pid);
            $partners[$pid] = $title ? $title : ('Partner #' . $pid);
        }

        $partner_sorted = $partners;
        uasort($partner_sorted, function ($a, $b) {
            return strnatcasecmp((string)$a, (string)$b);
        });
        $partners = $partner_sorted;

        // 3) Base day rows
        $base_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                DATE(s.attempted_at) AS day,
                COUNT(DISTINCT s.lead_id) AS total_leads,
                COUNT(DISTINCT CASE WHEN s.status = 'success' THEN s.lead_id END) AS success_leads,
                SUM(
                    CASE
                        WHEN
                            s.status IN ('failed','error','hard_fail')
                            OR (s.reason_code IS NOT NULL AND TRIM(s.reason_code) <> '')
                            OR (s.http_code IS NOT NULL AND s.http_code >= 400)
                        THEN 1
                        ELSE 0
                    END
                ) AS error_rows
             FROM {$send_table} s
             WHERE s.attempted_at BETWEEN %s AND %s
             GROUP BY DATE(s.attempted_at)
             ORDER BY day ASC",
                $dt_from, $dt_to
            ),
            ARRAY_A
        );
        if (!is_array($base_rows)) $base_rows = [];

        $rows = [];
        foreach ($base_rows as $r) {
            $day = (string)($r['day'] ?? '');
            if ($day === '') continue;

            $row = [
                'Date' => $day,
            ];

            foreach ($sources_cols as $col_name) {
                $row[$col_name] = 0;
            }

            $row['Total Leads'] = (int)($r['total_leads'] ?? 0);
            $row['Success'] = (int)($r['success_leads'] ?? 0);
            $row['Error'] = (int)($r['error_rows'] ?? 0);

            foreach ($partners as $pid => $ptitle) {
                $row['Sent to ' . $ptitle] = 0;
            }

            $rows[$day] = $row;
        }

        // 4) Fill sources
        $src_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                DATE(s.attempted_at) AS day,
                COALESCE(NULLIF(TRIM(l.utm_source), ''), 'Organic') AS src,
                COUNT(DISTINCT s.lead_id) AS cnt
             FROM {$send_table} s
             INNER JOIN {$leads_table} l ON l.id = s.lead_id
             WHERE s.attempted_at BETWEEN %s AND %s
             GROUP BY DATE(s.attempted_at), src",
                $dt_from, $dt_to
            ),
            ARRAY_A
        );

        if (is_array($src_rows)) {
            foreach ($src_rows as $r) {
                $day = (string)($r['day'] ?? '');
                $src = (string)($r['src'] ?? '');
                $cnt = (int)($r['cnt'] ?? 0);

                if ($day === '' || !isset($rows[$day])) continue;

                $src = trim($src);
                if ($src === '') $src = 'Organic';

                if (!isset($source_col_map[$src])) {
                    $col = $normalize_source_col($src);
                    $source_col_map[$src] = $col;

                    foreach ($rows as $d => $row) {
                        if (!array_key_exists($col, $rows[$d])) {
                            $rows[$d][$col] = 0;
                        }
                    }

                    asort($source_col_map, SORT_NATURAL | SORT_FLAG_CASE);
                    $sources_cols = array_values($source_col_map);
                }

                $col_name = $source_col_map[$src];
                if (!array_key_exists($col_name, $rows[$day])) {
                    $rows[$day][$col_name] = 0;
                }

                $rows[$day][$col_name] += $cnt;
            }
        }

        // 5) Fill sent-to-partner (status='success')
        $sent_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                DATE(attempted_at) AS day,
                partner_id,
                COUNT(DISTINCT lead_id) AS cnt
             FROM {$send_table}
             WHERE attempted_at BETWEEN %s AND %s
               AND status = 'success'
             GROUP BY DATE(attempted_at), partner_id",
                $dt_from, $dt_to
            ),
            ARRAY_A
        );

        if (is_array($sent_rows)) {
            foreach ($sent_rows as $r) {
                $day = (string)($r['day'] ?? '');
                $pid = (int)($r['partner_id'] ?? 0);
                $cnt = (int)($r['cnt'] ?? 0);

                if ($day === '' || $pid <= 0 || !isset($rows[$day])) continue;

                if (!isset($partners[$pid])) {
                    $title = get_the_title($pid);
                    $partners[$pid] = $title ? $title : ('Partner #' . $pid);

                    $new_key = 'Sent to ' . $partners[$pid];
                    foreach ($rows as $d => $row) {
                        if (!array_key_exists($new_key, $rows[$d])) {
                            $rows[$d][$new_key] = 0;
                        }
                    }

                    $partner_sorted = $partners;
                    uasort($partner_sorted, function ($a, $b) {
                        return strnatcasecmp((string)$a, (string)$b);
                    });
                    $partners = $partner_sorted;
                }

                $key = 'Sent to ' . $partners[$pid];
                if (!array_key_exists($key, $rows[$day])) {
                    $rows[$day][$key] = 0;
                }
                $rows[$day][$key] += $cnt;
            }
        }

        // 6) Build columns order
        $columns = ['Date'];
        foreach ($sources_cols as $col_name) {
            $columns[] = $col_name;
        }
        $columns[] = 'Total Leads';
        $columns[] = 'Success';
        //$columns[] = 'Error';
        foreach ($partners as $pid => $ptitle) {
            $columns[] = 'Sent to ' . $ptitle;
        }

        $out_rows = [];
        foreach ($rows as $day => $row) {
            $norm = [];
            foreach ($columns as $col) {
                $norm[$col] = array_key_exists($col, $row) ? $row[$col] : 0;
            }
            $out_rows[] = $norm;
        }

        return [
            'mode' => 'aggregate',
            'columns' => $columns,
            'sources' => array_keys($source_col_map),
            'source_col_map' => $source_col_map,
            'partners' => $partners,
            'rows' => $out_rows,
        ];
    }

    /**
     * Render single <tr> HTML for one lead by ID (including _lr_send_agg).
     * Used for AJAX row refresh after manual broadcast.
     */
    public function render_row_html_by_lead_id($lead_id)
    {
        $lead_id = (int)$lead_id;
        if ($lead_id <= 0) return '';

        global $wpdb;
        $leads_table = $wpdb->prefix . 'leadrouter_leads';
        $send_table = $wpdb->prefix . 'leadrouter_send_log';

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$leads_table} WHERE id = %d LIMIT 1", $lead_id),
            ARRAY_A
        );
        if (!$item || !is_array($item)) return '';

        // Load send logs for this lead and build send_by_lead structure (same logic as prepare_items()).
        $send_by_lead = [];

        $send_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT lead_id, group_id, partner_id, attempted_at, status
             FROM {$send_table}
             WHERE lead_id = %d",
                $lead_id
            ),
            ARRAY_A
        );

        if (is_array($send_rows)) {
            foreach ($send_rows as $sr) {
                $lid = (int)($sr['lead_id'] ?? 0);
                if ($lid <= 0) continue;

                $gid = (int)($sr['group_id'] ?? 0);
                $pid = (int)($sr['partner_id'] ?? 0);
                $ts = (string)($sr['attempted_at'] ?? '');
                $st = (string)($sr['status'] ?? '');

                if (!isset($send_by_lead[$lid])) $send_by_lead[$lid] = [];
                if (!isset($send_by_lead[$lid][$gid])) $send_by_lead[$lid][$gid] = [];

                if (!isset($send_by_lead[$lid][$gid][$pid])) {
                    $send_by_lead[$lid][$gid][$pid] = [
                        'partner_id' => $pid,
                        'attempted_at' => $ts,
                        'status' => $st,
                    ];
                    continue;
                }

                // Variant B: keep ONLY last attempted_at per (group_id, partner_id)
                $prev_ts = (string)($send_by_lead[$lid][$gid][$pid]['attempted_at'] ?? '');
                if ($prev_ts === '' || ($ts !== '' && strtotime($ts) >= strtotime($prev_ts))) {
                    $send_by_lead[$lid][$gid][$pid] = [
                        'partner_id' => $pid,
                        'attempted_at' => $ts,
                        'status' => $st,
                    ];
                }
            }
        }

        $item['_lr_send_agg'] = $this->build_send_agg_for_lead($send_by_lead[$lead_id] ?? []);

        // Ensure column headers are set (WP_List_Table needs it for single_row_columns()).
        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            $this->get_default_primary_column_name(),
        ];

        ob_start();
        $this->single_row($item); // prints <tr>...</tr>
        return (string)ob_get_clean();
    }

    protected function get_utm_sources_options(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'leadrouter_leads';

        $rows = $wpdb->get_col("
        SELECT DISTINCT NULLIF(TRIM(utm_source), '') AS src
        FROM {$table}
        WHERE utm_source IS NOT NULL AND TRIM(utm_source) <> ''
        ORDER BY src ASC
    ");

        $out = ['' => 'All sources'];

        foreach ((array)$rows as $src) {
            $src = (string)$src;
            if ($src === '') continue;
            $out[$src] = $src;
        }

        return $out;
    }

    protected function get_groups_options(): array
    {
        $posts = get_posts([
            'post_type'      => 'leadrouter_group',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        $out = ['' => 'All groups'];

        foreach ((array)$posts as $pid) {
            $out[(string)(int)$pid] = get_the_title($pid) ?: ('#' . (int)$pid);
        }

        return $out;
    }

    protected function get_status_options(): array
    {
        // під себе можеш скоригувати/розширити
        return [
            ''         => 'All statuses',
            //'new'      => 'new',
            //'assigned' => 'assigned',
            'sent'     => 'sent',
            'failed'   => 'failed',
            'error'    => 'error',
            'await'    => 'await',
        ];
    }

}


