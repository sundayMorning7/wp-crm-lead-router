<?php
/**
 * LR_Partner_Columns — колонка «Група» у списку партнерів і сортування
 * за замовчуванням: спершу активні, далі за назвою групи.
 *
 * Зв'язок партнер→група живе в меті `_leadrouter_partner_group`, тож і
 * колонка, і сортування читають саме її (через JOIN, без N+1).
 *
 * Порожнє/відсутнє значення `_leadrouter_partner_active` вважається
 * активним — та сама логіка, що в LeadRouter_Partners::is_active().
 */

defined('ABSPATH') || exit;

class LR_Partner_Columns
{
    const COLUMN = 'lr_partner_group';

    public static function register(): void
    {
        add_filter('manage_leadrouter_partner_posts_columns', [__CLASS__, 'add_column']);
        add_action('manage_leadrouter_partner_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);
        add_filter('manage_edit-leadrouter_partner_sortable_columns', [__CLASS__, 'sortable']);
        add_filter('posts_clauses', [__CLASS__, 'order_clauses'], 10, 2);
    }

    /* ===================== КОЛОНКА ===================== */

    /** Ставимо «Групу» одразу після «Статусу» (або після назви) */
    public static function add_column(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'lr_partner_status' || ($key === 'title' && !isset($columns['lr_partner_status']))) {
                $new[self::COLUMN] = __('Група', 'leadrouter');
            }
        }

        if (!isset($new[self::COLUMN])) {
            $new[self::COLUMN] = __('Група', 'leadrouter');
        }

        return $new;
    }

    public static function render_column($column, $post_id): void
    {
        if ($column !== self::COLUMN) {
            return;
        }

        $group_id = (int)get_post_meta($post_id, '_leadrouter_partner_group', true);
        if ($group_id <= 0) {
            echo '<span style="color:#d63638">' . esc_html__('без групи', 'leadrouter') . '</span>';
            return;
        }

        $group = self::group_map()[$group_id] ?? null;
        if (!$group) {
            printf('<span style="color:#d63638">%s #%d</span>', esc_html__('групу видалено', 'leadrouter'), $group_id);
            return;
        }

        $url = get_edit_post_link($group_id, 'raw');
        $title = $group['title'] !== '' ? $group['title'] : ('Group #' . $group_id);

        echo $url
            ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>'
            : esc_html($title);

        // режим групи — щоб було видно, куди партнер потрапив
        if ($group['mode'] === 'shared') {
            echo ' <span style="color:#7f54b3;font-size:11px">shared × ' . (int)$group['share_n'] . '</span>';
        }

        if ($group['status'] !== 'publish') {
            echo '<br><span style="color:#d63638;font-size:11px">' . esc_html($group['status']) . '</span>';
        } elseif (!$group['active']) {
            echo '<br><span style="color:#dba617;font-size:11px">' . esc_html__('група вимкнена', 'leadrouter') . '</span>';
        }
    }

    /** Групи одним запитом: id => [title, status, mode, share_n, active] */
    private static function group_map(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        global $wpdb;
        $t_groups = $wpdb->prefix . 'leadrouter_groups';

        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_status, g.mode, g.share_n, g.active
               FROM {$wpdb->posts} p
               LEFT JOIN {$t_groups} g ON g.post_id = p.ID
              WHERE p.post_type = 'leadrouter_group'",
            ARRAY_A
        ) ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['ID']] = [
                'title'   => (string)$r['post_title'],
                'status'  => (string)$r['post_status'],
                'mode'    => (string)($r['mode'] ?? 'classic'),
                'share_n' => (int)($r['share_n'] ?? 1),
                'active'  => $r['active'] === null ? true : (bool)(int)$r['active'],
            ];
        }

        return $map;
    }

    /* ===================== СОРТУВАННЯ ===================== */

    public static function sortable(array $columns): array
    {
        $columns[self::COLUMN] = self::COLUMN;

        return $columns;
    }

    /**
     * Сортування списку партнерів.
     *
     * За замовчуванням (жодного orderby в URL): активні спершу, далі за
     * назвою групи, всередині групи — за назвою партнера; партнери без групи
     * лишаються в кінці. Клік по колонці «Група» сортує лише за групою.
     */
    public static function order_clauses(array $clauses, $query): array
    {
        global $wpdb, $pagenow;

        if (!is_admin() || $pagenow !== 'edit.php' || !$query instanceof WP_Query || !$query->is_main_query()) {
            return $clauses;
        }
        if ($query->get('post_type') !== 'leadrouter_partner') {
            return $clauses;
        }

        $orderby = (string)$query->get('orderby');
        $by_group = ($orderby === self::COLUMN);

        // явно обране інше сортування (назва, дата) не чіпаємо
        if ($orderby !== '' && !$by_group) {
            return $clauses;
        }

        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} lr_act
                ON lr_act.post_id = {$wpdb->posts}.ID AND lr_act.meta_key = '_leadrouter_partner_active'";
        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} lr_grp
                ON lr_grp.post_id = {$wpdb->posts}.ID AND lr_grp.meta_key = '_leadrouter_partner_group'";
        $clauses['join'] .= " LEFT JOIN {$wpdb->posts} lr_gp
                ON lr_gp.ID = lr_grp.meta_value AND lr_gp.post_type = 'leadrouter_group'";

        if (empty($clauses['groupby'])) {
            $clauses['groupby'] = "{$wpdb->posts}.ID";
        }

        // порожня мета активності = активний (як у LeadRouter_Partners::is_active)
        $is_active  = "CASE WHEN lr_act.meta_value IS NULL OR lr_act.meta_value = '' THEN 1
                            WHEN lr_act.meta_value = '1' THEN 1 ELSE 0 END";
        $no_group   = "CASE WHEN lr_gp.ID IS NULL THEN 1 ELSE 0 END";
        $group_name = "lr_gp.post_title";
        $dir        = strtoupper((string)$query->get('order')) === 'ASC' ? 'ASC' : 'DESC';

        $clauses['orderby'] = $by_group
            ? "{$no_group} ASC, {$group_name} {$dir}, {$wpdb->posts}.post_title ASC"
            : "{$is_active} DESC, {$no_group} ASC, {$group_name} ASC, {$wpdb->posts}.post_title ASC";

        return $clauses;
    }
}
