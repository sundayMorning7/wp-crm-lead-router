<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( is_admin() && ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LeadRouter_Leads_Table extends WP_List_Table {

    protected static $groups_cache = null;

    public function __construct() {
        parent::__construct([
            'singular' => 'leadrouter_lead',
            'plural'   => 'leadrouter_leads',
            'ajax'     => false,
            'screen'   => get_current_screen(), // важливо
        ]);
    }

    public function get_hidden_columns() {
        $screen = get_current_screen();
        return get_hidden_columns( $screen );
    }

    protected function get_groups_for_select() {
        if (self::$groups_cache !== null) {
            return self::$groups_cache;
        }

        $groups = get_posts([
            'post_type'      => 'leadrouter_group',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        self::$groups_cache = is_array($groups) ? $groups : [];
        return self::$groups_cache;
    }

    /* -------- Колонки (груповані) -------- */
    public function get_columns() {
        $cols = [
            'cb'          => '<input type="checkbox" />',
            'id'            => 'ID',
            'contact'       => __( 'Contact', 'leadrouter' ),
            'route'         => __( 'Route (From → To)', 'leadrouter' ),
            'vehicle'       => __( 'Vehicle', 'leadrouter' ),
            'est_ship_date' => __( 'Est. Ship', 'leadrouter' ),
            'created_at'    => __( 'Created', 'leadrouter' ),
            'dispatch'      => __( 'Dispatch', 'leadrouter' ),
            'partner'       => __( 'Partner', 'leadrouter' ),
            'delivery'      => __( 'Sent to', 'leadrouter' ),
            'status'        => __('Статус', 'leadrouter'),
            'response_status'        => __('Статус (олд)', 'leadrouter'),
            'next_attempt_at'  => __('Наст. спроба', 'leadrouter'),
            'attempts_total' => 'Спроби',
            'actions'          => __('Дії', 'leadrouter'),

        ];
        return apply_filters( 'leadrouter_leads_columns', $cols );
    }



    protected function get_sortable_columns() {
        // map -> SQL (див. whitelist нижче)
        return [
            'id'            => [ 'id', true ],
            'est_ship_date' => [ 'est_ship_date', false ],
            'created_at'    => [ 'created_at', true ],
            'status'        => [ 'status', false ],
        ];
    }

    protected function whitelist_orderby( $requested ) {
        // дозволені поля сортування -> відповідні SQL колонки
        $map = [
            'id'            => 'l.id',
            'est_ship_date' => 'l.est_ship_date',
            'created_at'    => 'l.created_at',
            'status'        => 'l.status',
        ];
        return $map[ $requested ] ?? 'l.created_at';
    }

    public function get_bulk_actions() { return []; }

    /* -------- Рендер фільтрів (дата/статус/партнер) -------- */
    public function extra_tablenav( $which ) {
        if ( 'top' !== $which ) return;

        $date_from  = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to    = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $status     = isset($_GET['lr_status']) ? sanitize_text_field($_GET['lr_status']) : '';
        $partner_id = isset($_GET['lr_partner']) ? absint($_GET['lr_partner']) : 0;

        $partners = get_posts([
            'post_type'      => 'leadrouter_partner',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ]);

        ?>
        <div class="alignleft actions">
            <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" />
            <input type="date" name="date_to"   value="<?php echo esc_attr($date_to); ?>" />

            <select name="lr_status">
                <option value=""><?php esc_html_e('All statuses','leadrouter'); ?></option>
                <?php foreach ( ['new','assigned','sent','failed'] as $st ) : ?>
                    <option value="<?php echo esc_attr($st); ?>" <?php selected($status,$st); ?>>
                        <?php echo esc_html($st); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="lr_partner">
                <option value="0"><?php esc_html_e('All partners','leadrouter'); ?></option>
                <?php foreach ( $partners as $pid ) : ?>
                    <option value="<?php echo (int)$pid; ?>" <?php selected($partner_id,$pid); ?>>
                        <?php echo esc_html( get_the_title($pid) ?: $pid ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php submit_button( __( 'Filter' ), '', 'filter_action', false ); ?>
        </div>
        <?php
    }

    /* -------- Форматування клітинок -------- */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'contact':       return wp_kses_post( $this->render_contact( $item ) );
            case 'route':         return wp_kses_post( $this->render_route( $item ) );
            case 'vehicle':       return wp_kses_post( $this->render_vehicle( $item ) );
            case 'dispatch':      return wp_kses_post( $this->render_dispatch( $item ) );
            case 'partner':       return wp_kses_post( $this->render_partner( $item['partner_id'] ) );
            case 'delivery':      return wp_kses_post( $this->render_delivery( $item ) );
            case 'est_ship_date': return esc_html( $item['est_ship_date'] ?: '—' );
            case 'created_at':    return esc_html( $item['created_at'] );
            case 'status':        return esc_html( $item['status'] );
            case 'response_status':        return esc_html( $item['response_status'] );
            case 'actions':        return $this->render_button($item);
            case 'id':            return (int) $item['id'];
            case 'next_attempt_at':
                $val = $item['next_attempt_at'] ?? null;
                if (empty($val) || $val === '0000-00-00 00:00:00') {
                    return '—';
                }
                $ts = strtotime($val);
                return $ts ? esc_html( date_i18n('Y-m-d H:i:s', $ts) ) : esc_html($val);
            default:
                // Для додаткових/кастомних колонок
                $val = $item[ $column_name ] ?? '';
                return is_scalar($val) ? esc_html( (string) $val ) : '';
        }
    }

    protected function render_delivery( $item ) {
        $raw = $item['sent_summary_json'] ?? '';
        if (!$raw) {
            return '—';
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['partners']) || !is_array($data['partners'])) {
            return '—';
        }

        $lines = [];

        foreach ($data['partners'] as $row) {
            $pid = isset($row['partner_id']) ? (int)$row['partner_id'] : 0;
            if (!$pid) continue;

            $gid = isset($row['group_id']) ? (int)$row['group_id'] : 0;

            $p_title = get_the_title($pid) ?: ('Partner #' . $pid);
            $p_link  = get_edit_post_link($pid);

            $label = $p_title;

            if ($gid) {
                $g_title = get_the_title($gid) ?: ('Group #' . $gid);
                $label   = $g_title . ' → ' . $p_title;
            }

            $html = $p_link
                ? sprintf('<a href="%s">%s</a>', esc_url($p_link), esc_html($label))
                : esc_html($label);

            $st = isset($row['status']) ? trim((string)$row['status']) : '';
            if ($st !== '') {
                $html .= ' <span class="lr-delivery-status">[' . esc_html($st) . ']</span>';
            }

            $lines[] = $html;
        }

        return $lines ? implode('<br>', $lines) : '—';
    }


    protected function render_button( $item ) {
        $lead_id = (int) $item['id'];
        $groups  = $this->get_groups_for_select();

        $out  = '<div class="lr-broadcast-inline">';
        $out .= '<select class="lr-group-select" data-lead-id="' . $lead_id . '">';
        $out .= '<option value="0">' . esc_html__('Авто (з ліда)', 'leadrouter') . '</option>';

        foreach ($groups as $g) {
            $gid   = (int) $g->ID;
            $title = $g->post_title ?: ('Group #' . $gid);
            $out  .= '<option value="' . $gid . '">' . esc_html($title) . '</option>';
        }

        $out .= '</select> ';
        $out .= '<button type="button" class="lr-broadcast-btn" data-id="' . $lead_id . '">📣 ' . esc_html__('Send', 'leadrouter') . '</button>';
        $out .= '</div>';

        return $out;
    }



    protected function render_contact( $r ) {
        $name  = $r['name'];
        $email = $r['email'];
        $phone = $r['phone'];
        $lines = [];
        if ( $name )  { $lines[] = '<strong>'.esc_html($name).'</strong>'; }
        if ( $email ) { $lines[] = sprintf('<a href="mailto:%1$s">%1$s</a>', esc_attr($email)); }
        if ( $phone ) { $lines[] = sprintf('<a href="tel:%1$s">%1$s</a>', esc_html($phone)); }
        return implode('<br>', $lines) ?: '—';
    }

    protected function render_route( $r ) {
        $fmt = function($city,$state,$zip) {
            $parts = array_filter([ $city, $state, $zip ], fn($v)=> (string)$v !== '' && $v !== null);
            return $parts ? esc_html(implode(', ', $parts)) : '—';
        };
        $from = $fmt( $r['from_city'], $r['from_state'], $r['from_zip'] );
        $to   = $fmt( $r['to_city'],   $r['to_state'],   $r['to_zip'] );
        return $from . ' &rarr; ' . $to;
    }

    // Уся технічна інфо "в одну колонку", крім created_at (окремо)
    protected function render_vehicle( $r ) {
        $year = $r['vehicle_year'] ? (int)$r['vehicle_year'] : null;
        $brand = $r['vehicle_brand'];
        $model = $r['vehicle_model'];
        $cond  = $r['vehicle_condition'];

        $top = array_filter([
            $year ? (string)$year : null,
            $brand ?: null,
            $model ?: null,
        ]);
        $top_s = $top ? esc_html( implode(' • ', $top) ) : '—';

        if ( $cond ) {
            $top_s .= '<br><span class="description">'. esc_html( $cond ) .'</span>';
        }
        return $top_s;
    }

    protected function render_dispatch( $r ) {
        $parts = [];
        if ( $r['dispatch_method'] ) {
            $parts[] = esc_html( $r['dispatch_method'] );
        }
        if ( $r['sent_at'] ) {
            $parts[] = esc_html( $r['sent_at'] );
        }
        return $parts ? implode('<br>', $parts) : '—';
    }

    protected function render_partner( $partner_id ) {
        $pid = (int) $partner_id;
        if ( ! $pid ) return '—';
        $title = get_the_title( $pid );
        if ( ! $title ) return (string) $pid;
        $link = get_edit_post_link( $pid );
        return $link ? sprintf('<a href="%s">%s</a>', esc_url($link), esc_html($title)) : esc_html($title);
    }




    public function prepare_items() {
        global $wpdb;

        $table = $wpdb->prefix . 'leadrouter_leads';

        $per_page     = 40;
        $current_page = $this->get_pagenum();
        $offset       = ( $current_page - 1 ) * $per_page;

        $orderby_req = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'created_at';
        $orderby     = $this->whitelist_orderby( $orderby_req );
        $order_req   = isset($_GET['order']) ? strtolower($_GET['order']) : 'desc';
        $order       = in_array($order_req, ['asc','desc'], true) ? strtoupper($order_req) : 'DESC';

        // Фільтри
        $date_from  = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to    = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        $status     = isset($_GET['lr_status']) ? sanitize_text_field($_GET['lr_status']) : '';
        $partner_id = isset($_GET['lr_partner']) ? absint($_GET['lr_partner']) : 0;
        $search     = isset($_GET['s']) ? trim(wp_unslash($_GET['s'])) : '';

        $where  = [];
        $params = [];

        if ( $date_from ) {
            $where[]  = 'l.created_at >= %s';
            $params[] = $date_from . ' 00:00:00';
        }

        if ( $date_to ) {
            $where[]  = 'l.created_at <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        if ( $status !== '' ) {
            // поки що фільтруємо по response_status, як у тебе було
            $where[]  = 'l.response_status = %s';
            $params[] = $status;
        }

        if ( $partner_id > 0 ) {
            $where[]  = 'l.partner_id = %d';
            $params[] = $partner_id;
        }

        if ( $search !== '' ) {
            if ( ctype_digit( $search ) ) {
                // ID або рік авто
                $where[]  = '(l.id = %d OR l.vehicle_year = %d)';
                $params[] = (int) $search;
                $params[] = (int) $search;
            } else {
                $like = '%' . $wpdb->esc_like( $search ) . '%';
                // Пошук по name/email/phone/містах/штатах/бренду/моделі/статусу
                $where[] = '(l.name LIKE %s OR l.email LIKE %s OR l.phone LIKE %s
                         OR l.from_city LIKE %s OR l.from_state LIKE %s OR l.to_city LIKE %s OR l.to_state LIKE %s
                         OR l.vehicle_brand LIKE %s OR l.vehicle_model LIKE %s OR l.response_status LIKE %s)';
                array_push(
                    $params,
                    $like, $like, $like,
                    $like, $like, $like, $like,
                    $like, $like, $like
                );
            }
        }

        $where_sql = $where ? ( ' WHERE ' . implode( ' AND ', $where ) ) : '';

        // Підрахунок загальної кількості
        $count_sql = "SELECT COUNT(*) FROM {$table} AS l {$where_sql}";
        if ( $params ) {
            $total_items = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
        } else {
            $total_items = (int) $wpdb->get_var( $count_sql );
        }

        // Основний SELECT
        $select = "
        SELECT
            l.id, l.name, l.email, l.phone,
            l.est_ship_date,
            l.vehicle_year, l.vehicle_brand, l.vehicle_model, l.vehicle_condition,
            l.from_city, l.from_state, l.from_zip,
            l.to_city, l.to_state, l.to_zip,
            l.created_at, l.sent_at,
            l.dispatch_method, l.crm_response_json,
            l.response_status, l.partner_id,
            l.status,
            l.attempts_total,
            l.next_attempt_at,
            l.sent_summary_json
    ";

        $query_sql = "{$select}
        FROM {$table} AS l
        {$where_sql}
        ORDER BY {$orderby} {$order}
        LIMIT %d OFFSET %d
    ";

        $query_params = array_merge( $params, [ $per_page, $offset ] );

        $rows = $wpdb->get_results(
            $wpdb->prepare( $query_sql, $query_params ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        // Можливість пост-обробки рядка
        $rows = array_map(
            function ( $r ) {
                return apply_filters( 'leadrouter_leads_map_row', $r );
            },
            $rows
        );

        $this->items = $rows;

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
            'id',
        ];

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => max( 1, ceil( $total_items / $per_page ) ),
        ] );
    }

}
