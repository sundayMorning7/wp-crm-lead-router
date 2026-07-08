<?php
/**
 * Адмін-інтерфейс заявок-скарг (LeadRouter → Complaints).
 *
 * Інбокс-список (WP_List_Table за зразком class-leadrouter_leads_table.php):
 * дата (EST), партнер, lead_id, тема, повідомлення (excerpt+розкриття), статус.
 * Фільтри: статус (new/read), партнер, період (EST). Пагінація.
 * Позначення прочитаним — рядкова дія «Mark as read» (nonce) і bulk-дія.
 *
 * Лише перегляд/статус: ані резолюцій, ані білінг-дій. cap — manage_options.
 * Префікс — $wpdb->prefix; SQL — prepare; вивід — esc_*; час — EST.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (is_admin() && !class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Таблиця-список скарг.
 */
class LR_Complaints_Table extends WP_List_Table
{
    /**
     * Тимчасово сховати панель фільтрів + масові дії (і колонку-чекбокс).
     * Поставити true, коли знадобляться. Рядкова дія «Mark as read» лишається.
     */
    const SHOW_CONTROLS = false;

    public function __construct()
    {
        parent::__construct([
            'singular' => 'lr_complaint',
            'plural'   => 'lr_complaints',
            'ajax'     => false,
            'screen'   => get_current_screen(),
        ]);
    }

    public function get_columns()
    {
        $cols = [];
        // Колонка-чекбокс має сенс лише разом із масовими діями
        if (self::SHOW_CONTROLS) {
            $cols['cb'] = '<input type="checkbox" />';
        }
        $cols += [
            'created_at' => __('Date (EST)', 'leadrouter'),
            'partner'    => __('Partner', 'leadrouter'),
            'lead_id'    => __('Lead', 'leadrouter'),
            'lead_info'  => __('Lead details', 'leadrouter'),
            'topic'      => __('Topic', 'leadrouter'),
            'message'    => __('Message', 'leadrouter'),
            'status'     => __('Status', 'leadrouter'),
        ];
        return $cols;
    }

    protected function get_sortable_columns()
    {
        return [
            'created_at' => ['created_at', true],
            'status'     => ['status', false],
        ];
    }

    public function get_bulk_actions()
    {
        if (!self::SHOW_CONTROLS) {
            return [];
        }
        return [
            'mark_read' => __('Mark as read', 'leadrouter'),
        ];
    }

    /** Чекбокс рядка */
    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="complaint_id[]" value="%d" />', (int) ($item['id'] ?? 0));
    }

    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'created_at':
                return esc_html((string) ($item['created_at'] ?? ''));

            case 'partner':
                return $this->render_partner($item);

            case 'lead_id':
                return $this->render_lead($item);

            case 'lead_info':
                return $this->render_lead_info($item);

            case 'topic':
                return esc_html((string) ($item['topic'] ?? ''));

            case 'message':
                return $this->render_message($item);

            case 'status':
                return $this->render_status($item);

            default:
                return '';
        }
    }

    /** Партнер: назва + посилання на редагування CPT */
    protected function render_partner($item)
    {
        $pid = (int) ($item['partner_id'] ?? 0);
        if ($pid <= 0) {
            return '—';
        }
        $title = get_the_title($pid);
        $title = $title !== '' ? $title : ('#' . $pid);
        $link  = get_edit_post_link($pid);
        if ($link) {
            return '<a href="' . esc_url($link) . '">' . esc_html($title) . '</a> <span class="description">#' . $pid . '</span>';
        }
        return esc_html($title) . ' <span class="description">#' . $pid . '</span>';
    }

    /** Лід: #id + посилання на список лідів із пошуком по id */
    protected function render_lead($item)
    {
        $lid = (int) ($item['lead_id'] ?? 0);
        if ($lid <= 0) {
            return '—';
        }
        $url = add_query_arg(
            ['page' => 'leadrouter-leads', 's' => $lid],
            admin_url('admin.php')
        );
        return '<a href="' . esc_url($url) . '">#' . $lid . '</a>';
    }

    /**
     * Деталі ліда (з JOIN у prepare_items): клієнт, контакт, авто, маршрут.
     * Дані беруться з leadrouter_leads (аліаси lead_name/lead_email/lead_phone + поля авто/маршруту).
     */
    protected function render_lead_info($item)
    {
        $name  = trim((string) ($item['lead_name'] ?? ''));
        $email = trim((string) ($item['lead_email'] ?? ''));
        $phone = trim((string) ($item['lead_phone'] ?? ''));

        $vehicle = trim(sprintf(
            '%s %s %s',
            (string) ($item['vehicle_year'] ?? ''),
            (string) ($item['vehicle_brand'] ?? ''),
            (string) ($item['vehicle_model'] ?? '')
        ));

        $from = trim(trim((string) ($item['from_city'] ?? '')) . ' ' . (string) ($item['from_state'] ?? ''));
        $to   = trim(trim((string) ($item['to_city'] ?? '')) . ' ' . (string) ($item['to_state'] ?? ''));
        $route = ($from !== '' || $to !== '') ? trim($from . ' → ' . $to, ' →') : '';

        $lines = [];
        if ($name !== '') {
            $lines[] = '<strong>' . esc_html($name) . '</strong>';
        }
        if ($phone !== '') {
            $lines[] = '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>';
        }
        if ($email !== '') {
            $lines[] = '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        }
        if ($vehicle !== '') {
            $lines[] = '<span class="description">' . esc_html__('Vehicle:', 'leadrouter') . '</span> ' . esc_html($vehicle);
        }
        if ($route !== '') {
            $lines[] = '<span class="description">' . esc_html__('Route:', 'leadrouter') . '</span> ' . esc_html($route);
        }

        return $lines ? implode('<br>', $lines) : '<span class="description">' . esc_html__('(lead not found)', 'leadrouter') . '</span>';
    }

    /** Повідомлення: короткий excerpt + розкриття повного тексту (<details>) */
    protected function render_message($item)
    {
        $msg = (string) ($item['message'] ?? '');
        if (trim($msg) === '') {
            return '—';
        }
        $excerpt = mb_substr($msg, 0, 80);
        $is_long = mb_strlen($msg) > 80;

        if (!$is_long) {
            return esc_html($msg);
        }

        return '<details>'
            . '<summary style="cursor:pointer;">' . esc_html($excerpt) . '…</summary>'
            . '<div style="margin-top:6px; white-space:pre-wrap;">' . esc_html($msg) . '</div>'
            . '</details>';
    }

    /** Статус: бейдж + рядкова дія «Mark as read» для new */
    protected function render_status($item)
    {
        $id     = (int) ($item['id'] ?? 0);
        $status = (string) ($item['status'] ?? '');

        if ($status === 'read') {
            return '<span class="lr-complaint-badge" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#e5e5e5;color:#555;">'
                . esc_html__('Read', 'leadrouter') . '</span>';
        }

        $badge = '<span class="lr-complaint-badge" style="display:inline-block;padding:2px 8px;border-radius:10px;background:#d63638;color:#fff;">'
            . esc_html__('New', 'leadrouter') . '</span>';

        // Рядкова дія: позначити прочитаним (GET + nonce, обробка — на load-{page} хуку)
        $nonce = wp_create_nonce('lr_complaint_mark_read_' . $id);
        $url   = add_query_arg(
            [
                'page'         => 'leadrouter-complaints',
                'action'       => 'lr_complaint_mark_read',
                'complaint_id' => $id,
                '_wpnonce'     => $nonce,
            ],
            admin_url('admin.php')
        );
        $badge .= '<div class="row-actions"><span class="mark-read"><a href="' . esc_url($url) . '">'
            . esc_html__('Mark as read', 'leadrouter') . '</a></span></div>';

        return $badge;
    }

    public function prepare_items()
    {
        global $wpdb;
        $t = $wpdb->prefix . 'leadrouter_lead_complaints';
        $l = $wpdb->prefix . 'leadrouter_leads';

        // Дії (single + bulk «Mark as read») обробляє LR_Complaints_Admin::handle_actions()
        // на хуку load-{page} — до виводу адмін-шапки, щоб редірект не падав.

        // --- фільтри (GET) ---
        $status     = isset($_GET['cstatus'])    ? sanitize_key(wp_unslash($_GET['cstatus'])) : '';
        $partner_id = isset($_GET['cpartner'])    ? absint($_GET['cpartner']) : 0;
        $from       = isset($_GET['cfrom'])       ? sanitize_text_field(wp_unslash($_GET['cfrom'])) : '';
        $to         = isset($_GET['cto'])         ? sanitize_text_field(wp_unslash($_GET['cto']))   : '';

        // --- сортування ---
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'created_at';
        $order   = isset($_GET['order'])   ? strtoupper(sanitize_text_field(wp_unslash($_GET['order']))) : 'DESC';
        // Кваліфікуємо c.* — у JOIN leads теж є колонки status/created_at (інакше ambiguous)
        $allowed_orderby = ['created_at' => 'c.created_at', 'status' => 'c.status'];
        $orderby_sql = $allowed_orderby[$orderby] ?? 'c.created_at';
        $order_sql   = in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';

        // --- WHERE (усі фільтри — по колонках скарг, аліас c) ---
        $where  = 'WHERE 1=1';
        $params = [];

        if ($status === 'new' || $status === 'read') {
            $where .= ' AND c.status = %s';
            $params[] = $status;
        }
        if ($partner_id > 0) {
            $where .= ' AND c.partner_id = %d';
            $params[] = $partner_id;
        }
        // Період EST: from 00:00:00 .. (to+1 день) 00:00:00 (to включно)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where .= ' AND c.created_at >= %s';
            $params[] = $from . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to_next = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $where .= ' AND c.created_at < %s';
            $params[] = $to_next . ' 00:00:00';
        }

        // --- пагінація ---
        $per_page = 20;
        $page     = max(1, (int) $this->get_pagenum());
        $offset   = ($page - 1) * $per_page;

        // COUNT — лише по таблиці скарг (фільтри тільки на c.*)
        $sql_total = "SELECT COUNT(*) FROM {$t} c {$where}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($sql_total, $params))
            : $wpdb->get_var($sql_total));

        // ROWS — LEFT JOIN на ліда заради деталей у колонці «Lead details»
        $sql_rows = "SELECT c.*,
                            l.name  AS lead_name, l.email AS lead_email, l.phone AS lead_phone,
                            l.vehicle_year, l.vehicle_brand, l.vehicle_model,
                            l.from_city, l.from_state, l.to_city, l.to_state
                       FROM {$t} c
                       LEFT JOIN {$l} l ON l.id = c.lead_id
                       {$where}
                       ORDER BY {$orderby_sql} {$order_sql}
                       LIMIT %d OFFSET %d";
        $rows_params = array_merge($params, [$per_page, $offset]);
        $this->items = $wpdb->get_results($wpdb->prepare($sql_rows, $rows_params), ARRAY_A);

        $this->set_pagination_args([
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ]);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'created_at'];
    }

    /** Панель фільтрів над таблицею */
    public function extra_tablenav($which)
    {
        if (!self::SHOW_CONTROLS || $which !== 'top') {
            return;
        }

        $status     = isset($_GET['cstatus'])  ? sanitize_key(wp_unslash($_GET['cstatus'])) : '';
        $partner_id = isset($_GET['cpartner']) ? absint($_GET['cpartner']) : 0;
        $from       = isset($_GET['cfrom'])    ? sanitize_text_field(wp_unslash($_GET['cfrom'])) : '';
        $to         = isset($_GET['cto'])      ? sanitize_text_field(wp_unslash($_GET['cto']))   : '';

        echo '<div class="alignleft actions">';

        // Статус
        $statuses = ['' => __('All statuses', 'leadrouter'), 'new' => __('New', 'leadrouter'), 'read' => __('Read', 'leadrouter')];
        echo '<select name="cstatus">';
        foreach ($statuses as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($status, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> ';

        // Партнер (лише ті, що мають скарги)
        echo '<select name="cpartner" style="margin-left:6px;">';
        echo '<option value="0">' . esc_html__('All partners', 'leadrouter') . '</option>';
        foreach ($this->partners_with_complaints() as $pid => $title) {
            echo '<option value="' . esc_attr($pid) . '" ' . selected($partner_id, $pid, false) . '>' . esc_html($title) . '</option>';
        }
        echo '</select> ';

        // Період (EST)
        echo '<label style="margin-left:6px;">' . esc_html__('From (EST)', 'leadrouter') . ' </label>';
        echo '<input type="date" name="cfrom" value="' . esc_attr($from) . '" />';
        echo '<label style="margin-left:6px;">' . esc_html__('To (EST)', 'leadrouter') . ' </label>';
        echo '<input type="date" name="cto" value="' . esc_attr($to) . '" />';

        submit_button(__('Filter', 'leadrouter'), '', 'filter_action', false, ['style' => 'margin-left:8px;']);

        $reset = remove_query_arg(['cstatus', 'cpartner', 'cfrom', 'cto', 'paged']);
        echo ' <a href="' . esc_url($reset) . '" class="button">' . esc_html__('Reset', 'leadrouter') . '</a>';

        echo '</div>';
    }

    /** Партнери, які мають хоча б одну скаргу (для фільтра) */
    protected function partners_with_complaints(): array
    {
        global $wpdb;
        $t = $wpdb->prefix . 'leadrouter_lead_complaints';
        $ids = $wpdb->get_col("SELECT DISTINCT partner_id FROM {$t} ORDER BY partner_id ASC");
        $out = [];
        foreach ((array) $ids as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0) {
                continue;
            }
            $title = get_the_title($pid);
            $out[$pid] = $title !== '' ? $title : ('#' . $pid);
        }
        return $out;
    }
}

/**
 * Реєстрація меню + рендер сторінки + обробка рядкової дії «Mark as read».
 */
class LR_Complaints_Admin
{
    /** Підпункт меню LeadRouter → Complaints (з помітним лічильником new) */
    public static function register_menu(): void
    {
        $new = class_exists('LR_Complaints') ? LR_Complaints::count_new() : 0;
        $title = __('Complaints', 'leadrouter');
        $menu_title = $title;
        if ($new > 0) {
            // Самодостатній оранжевий бейдж (не залежить від стилів core awaiting-mod)
            $menu_title .= ' <span class="lr-complaints-count" style="display:inline-block;min-width:18px;'
                . 'height:18px;line-height:18px;padding:0 6px;margin-left:5px;border-radius:9px;'
                . 'background:#f56e28;color:#fff;font-size:11px;font-weight:600;text-align:center;'
                . 'vertical-align:middle;">' . (int) $new . '</span>';
        }

        $hook = add_submenu_page(
            'leadrouter',
            $title,
            $menu_title,
            'manage_options',
            'leadrouter-complaints',
            [__CLASS__, 'render_page']
        );

        // Дії обробляємо на завантаженні сторінки — ДО виводу адмін-шапки,
        // інакше wp_safe_redirect() падає на «headers already sent» (порожня сторінка).
        if ($hook) {
            add_action('load-' . $hook, [__CLASS__, 'handle_actions']);
        }
    }

    /**
     * Обробка дій сторінки (до виводу): одиночна «Mark as read» (GET+nonce)
     * і масова «Mark as read» (bulk, nonce bulk-{plural}). Після — PRG-редірект.
     */
    public static function handle_actions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Одиночна дія: ?action=lr_complaint_mark_read&complaint_id=N&_wpnonce=...
        if (isset($_GET['action']) && $_GET['action'] === 'lr_complaint_mark_read') {
            $cid   = isset($_GET['complaint_id']) ? absint($_GET['complaint_id']) : 0;
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if ($cid > 0 && wp_verify_nonce($nonce, 'lr_complaint_mark_read_' . $cid)) {
                LR_Complaints::mark_read($cid);
            }
            wp_safe_redirect(remove_query_arg(['action', 'complaint_id', '_wpnonce', '_wp_http_referer']));
            exit;
        }

        // Масова дія: селектор action / action2 = mark_read
        $is_bulk = (isset($_REQUEST['action']) && $_REQUEST['action'] === 'mark_read')
            || (isset($_REQUEST['action2']) && $_REQUEST['action2'] === 'mark_read');
        if ($is_bulk) {
            check_admin_referer('bulk-lr_complaints');
            $ids = isset($_REQUEST['complaint_id']) ? (array) wp_unslash($_REQUEST['complaint_id']) : [];
            $ids = array_values(array_filter(array_map('absint', $ids)));
            foreach ($ids as $cid) {
                LR_Complaints::mark_read($cid);
            }
            wp_safe_redirect(remove_query_arg([
                'action', 'action2', 'complaint_id', '_wpnonce',
                '_wp_http_referer', 'bulk_action', 'filter_action', 'paged',
            ]));
            exit;
        }
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Insufficient permissions.', 'leadrouter'));
        }

        $table = new LR_Complaints_Table();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Complaints', 'leadrouter') . '</h1>';
        echo '<p class="description">' . esc_html__('Partner complaints about delivered leads. View-only inbox: New / Read.', 'leadrouter') . '</p>';

        echo '<form method="get">';
        // Зберігаємо page та сортування у фільтр-формі
        echo '<input type="hidden" name="page" value="leadrouter-complaints" />';
        foreach (['orderby', 'order'] as $key) {
            if (isset($_GET[$key])) {
                printf('<input type="hidden" name="%s" value="%s" />', esc_attr($key), esc_attr(sanitize_text_field(wp_unslash($_GET[$key]))));
            }
        }
        $table->display();
        echo '</form>';

        echo '</div>';
    }
}
