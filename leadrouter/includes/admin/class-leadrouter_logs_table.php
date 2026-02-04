<?php

class LeadRouter_Logs_Table extends WP_List_Table
{

    protected $lead_map = [];



    public function __construct()

    {
        parent::__construct([
            'singular' => 'leadrouter_log',
            'plural' => 'leadrouter_logs',
            'ajax' => false
        ]);
    }

    public function get_columns()
    {
        $cols = [
            'id' => 'ID',
            'lead_id' => __('Lead ID', 'leadrouter'),
            'partner_id' => __('Partner', 'leadrouter'),
            'group_id' => __('Group', 'leadrouter'),
            'assigned_at' => __('Date', 'leadrouter'),
            'status' => __('Status', 'leadrouter'),
        ];
        /**
         * Дай можливість додавати/знімати колонки.
         * Приклад: add_filter( 'leadrouter_logs_columns', fn($c) => array_merge($c, ['extra'=>'Extra']) );
         */
        return apply_filters('leadrouter_logs_columns', $cols);
    }

    protected function get_sortable_columns()
    {
        // лише по цих полях дозволяємо ORDER BY
        return [
            'id' => ['id', false],
            'assigned_at' => ['assigned_at', true],
            'status' => ['status', false],
            'lead_id' => ['lead_id', false],
            'partner_id' => ['partner_id', false],
            'group_id' => ['group_id', false],
        ];
    }

    /**
     * Безпечний вибір поля для ORDER BY з білого списку
     */
    protected function whitelist_orderby($requested)
    {
        $sortable = $this->get_sortable_columns();
        $allowed = array_map(fn($v) => $v[0], $sortable); // ['id', 'assigned_at', ...]
        return in_array($requested, $allowed, true) ? $requested : 'id';
    }

    /**
     * Головне: готуємо items і даємо хуки для трансформацій
     */
    public function prepare_items()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_logs';

        $per_page = 20;
        $current_page = $this->get_pagenum();
        $offset = ($current_page - 1) * $per_page;

        $orderby_req = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'id';
        $orderby = $this->whitelist_orderby($orderby_req);

        $order_req = isset($_GET['order']) ? strtolower($_GET['order']) : 'desc';
        $order = in_array($order_req, ['asc', 'desc'], true) ? strtoupper($order_req) : 'DESC';

        $total_items = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$table}");

        // NB: $orderby вже з білого списку — можна підставляти напряму
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, lead_id, partner_id, group_id, assigned_at, status
             FROM {$table}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
                $per_page, $offset
            ),
            ARRAY_A
        ) ?: [];

        // Дозволяємо масово мапити/збагачувати рядки перед показом
        $rows = array_map(function ($row) {
            /**
             * Трансформація рядка перед відображенням.
             * Приклад (functions.php):
             * add_filter('leadrouter_logs_map_row', function($r){
             *     $r['status'] = strtoupper($r['status']);
             *     return $r;
             * });
             */


            return apply_filters('leadrouter_logs_map_row', $row);
        }, $rows);

        // Після отримання $rows — збираємо усі lead_id та підтягуємо їх дані одним запитом
        $this->items = $rows ?? [];

        $lead_ids = array_filter(array_map('intval', array_column($this->items, 'lead_id')));
        $lead_ids = array_values(array_unique($lead_ids));

        if ( ! empty($lead_ids) ) {
            global $wpdb;
            $leads_table = $wpdb->prefix . 'leadrouter_leads';
            // Отримаємо name, phone, email
            $in = implode(',', array_fill(0, count($lead_ids), '%d'));
            $sql = "SELECT id, name, phone, email FROM {$leads_table} WHERE id IN ($in)";

            // Підготуємо параметри під %d
            $prepared = $wpdb->prepare($sql, $lead_ids);
            $rows_leads = $wpdb->get_results($prepared, ARRAY_A) ?: [];

            foreach ($rows_leads as $lr) {
                $id = (int) $lr['id'];
                $this->lead_map[$id] = [
                    'name'  => (string) ($lr['name']  ?? ''),
                    'phone' => (string) ($lr['phone'] ?? ''),
                    'email' => (string) ($lr['email'] ?? ''),
                ];
            }
        }

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'id'];
        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page' => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ]);
    }


    /**
     * За замовчуванням усі комірки проходять через універсальний фільтр:
     * leadrouter_logs_column_{column}
     */
    public function column_default($item, $column_name)
    {
        $value = $item[$column_name] ?? '';

        // Спеціальний рендер для деяких колонок (приклад нижче)
        if (method_exists($this, "render_{$column_name}")) {
            $value = $this->{"render_{$column_name}"}($item);
        }

        /**
         * Точкове форматування конкретної колонки.
         * Приклад:
         * add_filter('leadrouter_logs_column_status', function($val, $row){
         *     return $val === 'assigned' ? ' assigned' : $val;
         * }, 10, 2);
         */
        $value = apply_filters("leadrouter_logs_column_{$column_name}", $value, $item);

        // Можеш замінити esc_html на wp_kses_post, якщо в комірці потрібен HTML
        return wp_kses_post( (string) $value );
    }

    /** -------- Приклади спец-рендерів (можеш прибрати, якщо не треба) -------- */

    /** Partner як назва з лінком на редагування */
    protected function render_partner_id($item)
    {
        $id = (int)($item['partner_id'] ?? 0);
        if ($id <= 0) return $item['partner_id'] ?? '';
        $title = get_the_title($id);
        if (!$title) return $id;
        $link = get_edit_post_link($id);
        $html = $link ? sprintf('<a href="%s">%s</a>', esc_url($link), esc_html($title)) : esc_html($title);
        return apply_filters('leadrouter_logs_column_partner_link', $html, $item);
    }

    /** Group як назва з лінком */
    protected function render_group_id($item)
    {
        $id = (int)($item['group_id'] ?? 0);
        if ($id <= 0) return $item['group_id'] ?? '';
        $title = get_the_title($id);
        if (!$title) return $id;
        $link = get_edit_post_link($id);
        $html = $link ? sprintf('<a href="%s">%s</a>', esc_url($link), esc_html($title)) : esc_html($title);
        return apply_filters('leadrouter_logs_column_group_link', $html, $item);
    }


    /** Побудова URL перегляду ліда в адмінці.
     * Якщо маєш окрему сторінку перегляду, заміни на свій slug.
     * Фолбек — пошук на сторінці Лідів за ID.
     */
    protected function get_lead_admin_url( int $lead_id ): string {
        // Варіант А: власна сторінка перегляду (зробиш хендлер пізніше)
        // return admin_url( 'admin.php?page=leadrouter-lead-view&lead_id=' . $lead_id );

        // Варіант B (фолбек): відкрити сторінку "Ліди" з пошуком по ID
        return add_query_arg(
            ['page' => 'leadrouter-leads', 's' => $lead_id],
            admin_url('admin.php')
        );
    }

    /** Рендер вмісту колонки lead_id у форматі "Name — Phone — Email" з лінком */
    protected function render_lead_id( array $item ) {
        $id = (int) ($item['lead_id'] ?? 0);
        if ( $id <= 0 ) {
            return '—';
        }

        $name  = $this->lead_map[$id]['name']  ?? '';
        $phone = $this->lead_map[$id]['phone'] ?? '';
        $email = $this->lead_map[$id]['email'] ?? '';

        // Тексти з фолбеками
        $parts = array_filter([
            $name !== ''  ? $name  : null,
            $phone !== '' ? $phone : null,
            $email !== '' ? $email : null,
        ]);
        $label = $parts ? implode('<br/>', array_map('esc_html', $parts)) : ('Lead #' . $id);

        $url = $this->get_lead_admin_url($id);
        return sprintf('<a href="%s">%s</a>', esc_url($url), $label);
    }



}

// У WP_List_Table::column_default ОБОВ'ЯЗКОВО:
// return wp_kses_post( (string) $value );

add_filter('leadrouter_logs_map_row', function ($r) {
    global $wpdb;

    /* ===== 1) partner_id: int | array | JSON ===== */
    if (isset($r['partner_id']) && $r['partner_id'] !== '' && $r['partner_id'] !== null) {
        $partner_ids = [];

        if (is_array($r['partner_id'])) {
            $partner_ids = $r['partner_id'];
        } elseif (ctype_digit((string)$r['partner_id'])) {
            $partner_ids = [ (int) $r['partner_id'] ];
        } elseif (is_string($r['partner_id']) && strlen($r['partner_id']) >= 2 && $r['partner_id'][0] === '[') {
            $decoded = json_decode($r['partner_id'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $partner_ids = $decoded;
            }
        }

        $partner_ids = array_values(array_unique(array_filter(array_map('intval', (array)$partner_ids))));
        if (!empty($partner_ids)) {
            $parts = [];
            foreach ($partner_ids as $pid) {
                $title = get_the_title($pid);
                $label = $title ? $title : ('Partner #' . $pid);
                // якщо хочеш лінк на редагування — розкоментуй наступні 2 рядки
                // $link  = get_edit_post_link($pid);
                // $label = $link ? sprintf('<a href="%s">%s</a>', esc_url($link), esc_html($label)) : esc_html($label);
                $parts[] = esc_html($label) . ' [' . (int)$pid . ']';
            }
            $r['partner_id'] = implode('<br/>', $parts);
        }
    }

    /* ===== 2) group_id: id -> post_id через {$wpdb->prefix}leadrouter_groups ===== */
    if (isset($r['group_id'])) {
        static $group_cache = []; // [group_id => post_id]
        $gid = (int) $r['group_id'];

        if ($gid > 0) {
            if (!array_key_exists($gid, $group_cache)) {
                $groups_table = $wpdb->prefix . 'leadrouter_groups'; // у тебе: w4pMd_leadrouter_groups
                $group_cache[$gid] = (int) $wpdb->get_var(
                    $wpdb->prepare("SELECT post_id FROM {$groups_table} WHERE id = %d", $gid)
                );
            }
            $post_id = $group_cache[$gid];

            if ($post_id > 0) {
                $title = get_the_title($post_id);
                if ($title) {
                    $link = get_edit_post_link($post_id);
                    $html = $link
                        ? sprintf('<a href="%s">%s</a>', esc_url($link), esc_html($title))
                        : esc_html($title);
                    $r['group_id'] = $html;
                } else {
                    $r['group_id'] = (string) $gid; // fallback
                }
            } else {
                $r['group_id'] = (string) $gid;     // якщо зв'язку немає
            }
        } else {
            $r['group_id'] = '—';
        }
    }

    /* ===== 3) (за бажання) lead_id -> "Name — Phone — Email" з лінком на перегляд ===== */
    // Розкоментуй, якщо потрібно прямо тут:
    /*
    if (!empty($r['lead_id'])) {
        static $lead_cache = [];
        $lead_id = (int) $r['lead_id'];
        if ($lead_id > 0) {
            if (!isset($lead_cache[$lead_id])) {
                $leads_table = $wpdb->prefix . 'leadrouter_leads';
                $lead_cache[$lead_id] = $wpdb->get_row(
                    $wpdb->prepare("SELECT name, phone, email FROM {$leads_table} WHERE id = %d", $lead_id),
                    ARRAY_A
                ) ?: [];
            }
            $ld = $lead_cache[$lead_id];
            $parts = array_filter([
                $ld['name']  ?? '',
                $ld['phone'] ?? '',
                $ld['email'] ?? '',
            ]);
            $label = $parts ? implode(' — ', array_map('esc_html', $parts)) : ('Lead #' . $lead_id);
            $url   = add_query_arg(['page'=>'leadrouter-leads','s'=>$lead_id], admin_url('admin.php'));
            $r['lead_id'] = sprintf('<a href="%s">%s</a>', esc_url($url), $label);
        }
    }
    */

    return $r;
});


