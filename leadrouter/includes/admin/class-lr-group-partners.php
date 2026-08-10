<?php
/**
 * LR_Group_Partners — керування складом групи прямо на сторінці групи.
 *
 * Джерело правди лишається одне — мета партнера `_leadrouter_partner_group`
 * (один партнер = одна група). Цей блок її редагує, а не заводить власне поле
 * групи: інакше було б два списки, які треба звіряти між собою.
 *
 * Кожна дія застосовується одразу (AJAX) і тягне перерахунок ваг СТАРОЇ та
 * НОВОЇ групи — раніше зміна групи партнера взагалі не тригерила
 * leadrouter_recalc_sum_weight(), бо LeadRouter_Hooks стежить лише за
 * денними лімітами.
 *
 * «Вилучити» = очистити групу: партнер лишається активним, але жодна група
 * його не бачить (available_in_group шукає саме за цією метою), тож лідів він
 * не отримує, поки його кудись не додадуть.
 */

defined('ABSPATH') || exit;

class LR_Group_Partners
{
    const NONCE     = 'lr_group_partners';
    const META_GROUP = '_leadrouter_partner_group';

    public static function register(): void
    {
        add_action('wp_ajax_lr_group_partner_assign', [__CLASS__, 'ajax_assign']);
        add_action('wp_ajax_lr_group_partner_remove', [__CLASS__, 'ajax_remove']);

        // JS/CSS — окремими файлами і лише на екрані групи. Інлайнити скрипт
        // у html-поле не можна: Carbon Fields вставляє вміст поля через
        // innerHTML, а вставлені так <script> браузер не виконує.
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /** Підключення ассетів на екрані редагування групи */
    public static function enqueue_assets($hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'leadrouter_group') {
            return;
        }

        $js  = 'assets/js/group-partners.js';
        $css = 'assets/css/lr-group-partners.css';

        // версія = mtime файла, щоб правки самі скидали кеш браузера
        wp_enqueue_style(
            'lr-group-partners',
            LEADROUTER_PLUGIN_URL . $css,
            [],
            self::asset_version($css)
        );

        wp_enqueue_script(
            'lr-group-partners',
            LEADROUTER_PLUGIN_URL . $js,
            [],
            self::asset_version($js),
            true
        );

        wp_localize_script('lr-group-partners', 'LRGroupPartners', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'i18n'    => [
                'confirmRemove' => __('Вилучити «%s» з групи? Партнер лишиться активним, але без групи не отримуватиме лідів.', 'leadrouter'),
                'confirmMove'   => __('Партнер зараз у групі «%s». Перенести сюди?', 'leadrouter'),
                'pickPartner'   => __('Оберіть партнера', 'leadrouter'),
                'error'         => __('Помилка', 'leadrouter'),
                'network'       => __('Помилка мережі', 'leadrouter'),
            ],
        ]);
    }

    /** mtime файла як версія ассета (правка сама скидає кеш браузера) */
    private static function asset_version(string $rel_path): string
    {
        $full = LEADROUTER_PLUGIN_DIR . $rel_path;

        return file_exists($full) ? (string)filemtime($full) : LEADROUTER_VERSION;
    }

    /* ===================== РЕНДЕР ===================== */

    /** Повний блок для сторінки групи (таблиця + додавання + скрипт) */
    public static function render(int $group_id): string
    {
        if ($group_id <= 0) {
            return '';
        }

        $html = '<div class="lr-gp" data-group="' . esc_attr((string)$group_id) . '"'
            . ' data-nonce="' . esc_attr(wp_create_nonce(self::NONCE)) . '">';
        $html .= '<h3 style="margin:18px 0 8px">' . esc_html__('Партнери у цій групі', 'leadrouter') . '</h3>';
        $html .= '<div class="lr-gp-body">' . self::render_body($group_id) . '</div>';
        $html .= '</div>';

        return $html;
    }

    /** Тіло блоку — те саме повертає AJAX після кожної дії */
    public static function render_body(int $group_id): string
    {
        $partners = self::partners_in_group($group_id);

        $html = '<table class="widefat striped lr-gp-table"><thead><tr>'
            . '<th>' . esc_html__('Партнер', 'leadrouter') . '</th>'
            . '<th style="width:110px">' . esc_html__('Ліміт сьогодні', 'leadrouter') . '</th>'
            . '<th style="width:150px">' . esc_html__('Власник', 'leadrouter') . '</th>'
            . '<th style="width:110px">' . esc_html__('Стан', 'leadrouter') . '</th>'
            . '<th style="width:110px"></th>'
            . '</tr></thead><tbody>';

        if (empty($partners)) {
            $html .= '<tr><td colspan="5"><em>' . esc_html__('Партнерів немає — додайте нижче.', 'leadrouter') . '</em></td></tr>';
        }

        $sum = 0;
        foreach ($partners as $p) {
            $sum += $p['limit'];

            $html .= '<tr>';
            $html .= '<td><a href="' . esc_url((string)$p['edit_url']) . '">' . esc_html($p['label']) . '</a></td>';
            $html .= '<td>' . (int)$p['limit'] . '</td>';
            $html .= '<td>' . ($p['owner'] !== '' ? esc_html($p['owner']) : '<span style="color:#a7aaad">—</span>') . '</td>';
            $html .= '<td>' . ($p['is_active']
                    ? '<span style="color:#00a32a">' . esc_html__('активний', 'leadrouter') . '</span>'
                    : '<span style="color:#d63638">' . esc_html__('вимкнений', 'leadrouter') . '</span>') . '</td>';
            $html .= '<td><button type="button" class="button button-small lr-gp-remove" data-partner="' . (int)$p['id'] . '"'
                . ' data-label="' . esc_attr($p['label']) . '">' . esc_html__('Вилучити', 'leadrouter') . '</button></td>';
            $html .= '</tr>';
        }

        if (!empty($partners)) {
            $html .= '<tr><td><strong>' . esc_html__('Разом', 'leadrouter') . '</strong></td>'
                . '<td><strong>' . (int)$sum . '</strong></td><td colspan="3"></td></tr>';
        }

        $html .= '</tbody></table>';

        // ── Додавання ──
        $outside = self::partners_outside($group_id);

        $html .= '<div class="lr-gp-add">';
        if (empty($outside)) {
            $html .= '<em>' . esc_html__('Усі партнери вже в цій групі.', 'leadrouter') . '</em>';
        } else {
            $html .= '<select class="lr-gp-select">';
            $html .= '<option value="0">' . esc_html__('— оберіть партнера —', 'leadrouter') . '</option>';

            $free = array_filter($outside, static fn($p) => $p['group_id'] <= 0);
            $busy = array_filter($outside, static fn($p) => $p['group_id'] > 0);

            if ($free) {
                $html .= '<optgroup label="' . esc_attr__('Без групи', 'leadrouter') . '">';
                foreach ($free as $p) {
                    $html .= '<option value="' . (int)$p['id'] . '">' . esc_html($p['label'])
                        . ($p['is_active'] ? '' : ' · ' . esc_html__('вимкнений', 'leadrouter')) . '</option>';
                }
                $html .= '</optgroup>';
            }

            if ($busy) {
                $html .= '<optgroup label="' . esc_attr__('З іншої групи (перенос)', 'leadrouter') . '">';
                foreach ($busy as $p) {
                    $html .= '<option value="' . (int)$p['id'] . '" data-from="' . esc_attr($p['group_name']) . '">'
                        . esc_html($p['label']) . ' — ' . esc_html($p['group_name'])
                        . ($p['is_active'] ? '' : ' · ' . esc_html__('вимкнений', 'leadrouter')) . '</option>';
                }
                $html .= '</optgroup>';
            }

            $html .= '</select> ';
            $html .= '<button type="button" class="button lr-gp-add-btn">' . esc_html__('Додати в групу', 'leadrouter') . '</button>';
            $html .= ' <span class="lr-gp-msg"></span>';
        }
        $html .= '</div>';

        $html .= '<p class="lr-gp-note">'
            . esc_html__('Зміни застосовуються одразу, зберігати групу не потрібно: оновлюється мета партнера і перераховуються ваги обох груп. «Вилучити» лишає партнера активним, але без групи він не отримує лідів.', 'leadrouter')
            . '</p>';

        return $html;
    }

    /* ===================== ДАНІ ===================== */

    /** Партнери цієї групи з лімітом на сьогодні (EST) */
    private static function partners_in_group(int $group_id): array
    {
        $slug = self::today_slug();

        $ids = get_posts([
            'post_type'      => 'leadrouter_partner',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                ['key' => self::META_GROUP, 'value' => $group_id, 'compare' => '=', 'type' => 'NUMERIC'],
            ],
        ]);

        $out = [];
        foreach ($ids as $pid) {
            $pid = (int)$pid;
            $out[] = [
                'id'        => $pid,
                'label'     => get_the_title($pid) ?: ('Partner #' . $pid),
                'edit_url'  => get_edit_post_link($pid, 'raw'),
                'limit'     => max(0, (int)get_post_meta($pid, "_leadrouter_partner_{$slug}_limit", true)),
                'owner'     => class_exists('LR_Shared_Sync')
                    ? LR_Shared_Sync::normalize_owner(get_post_meta($pid, '_lr_partner_owner', true))
                    : trim((string)get_post_meta($pid, '_lr_partner_owner', true)),
                'is_active' => get_post_status($pid) === 'publish'
                    && (string)get_post_meta($pid, '_leadrouter_partner_active', true) === '1',
            ];
        }

        // Найбільший ліміт на сьогодні — вгорі. За рівного ліміту лишаємо
        // старий порядок за назвою, щоб список не стрибав.
        usort($out, static function ($a, $b) {
            return ($b['limit'] <=> $a['limit']) ?: strnatcasecmp($a['label'], $b['label']);
        });

        return $out;
    }

    /** Партнери поза цією групою: без групи + з інших груп */
    private static function partners_outside(int $group_id): array
    {
        $ids = get_posts([
            'post_type'      => 'leadrouter_partner',
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $out = [];
        foreach ($ids as $pid) {
            $pid = (int)$pid;
            $gid = (int)get_post_meta($pid, self::META_GROUP, true);
            if ($gid === $group_id) {
                continue;
            }

            $out[] = [
                'id'         => $pid,
                'label'      => get_the_title($pid) ?: ('Partner #' . $pid),
                'group_id'   => $gid,
                'group_name' => $gid > 0 ? (get_the_title($gid) ?: ('Group #' . $gid)) : '',
                'is_active'  => get_post_status($pid) === 'publish'
                    && (string)get_post_meta($pid, '_leadrouter_partner_active', true) === '1',
            ];
        }

        // Активні — вгорі, вимкнені/заархівовані — в кінці. Усередині кожної
        // частини лишається старий порядок за назвою. Розбиття на optgroup
        // («Без групи» / «З іншої групи») зберігає цей порядок, бо array_filter
        // не переставляє елементи.
        usort($out, static function ($a, $b) {
            return ($b['is_active'] <=> $a['is_active']) ?: strnatcasecmp($a['label'], $b['label']);
        });

        return $out;
    }

    /* ===================== AJAX ===================== */

    public static function ajax_assign(): void
    {
        [$group_id, $partner_id] = self::guard();

        $from = (int)get_post_meta($partner_id, self::META_GROUP, true);
        if ($from === $group_id) {
            wp_send_json_error(['message' => __('Партнер уже в цій групі', 'leadrouter')], 400);
        }

        update_post_meta($partner_id, self::META_GROUP, (string)$group_id);
        self::recalc_groups([$from, $group_id]);

        wp_send_json_success(array_merge(
            self::refresh_payload($group_id),
            [
                'message' => $from > 0
                    ? sprintf(__('%1$s перенесено з групи «%2$s»', 'leadrouter'), get_the_title($partner_id), get_the_title($from) ?: ('#' . $from))
                    : sprintf(__('%s додано в групу', 'leadrouter'), get_the_title($partner_id)),
            ]
        ));
    }

    public static function ajax_remove(): void
    {
        [$group_id, $partner_id] = self::guard();

        $from = (int)get_post_meta($partner_id, self::META_GROUP, true);
        if ($from !== $group_id) {
            wp_send_json_error(['message' => __('Партнер не належить цій групі', 'leadrouter')], 400);
        }

        // порожня мета = партнер без групи; лідів не отримує, поки не додадуть
        update_post_meta($partner_id, self::META_GROUP, '');
        self::recalc_groups([$group_id]);

        wp_send_json_success(array_merge(
            self::refresh_payload($group_id),
            ['message' => sprintf(__('%s вилучено з групи (лишився без групи)', 'leadrouter'), get_the_title($partner_id))]
        ));
    }

    /** Спільна перевірка прав/nonce/аргументів. Повертає [group_id, partner_id] */
    private static function guard(): array
    {
        check_ajax_referer(self::NONCE, 'nonce');

        $group_id   = isset($_POST['group']) ? (int)$_POST['group'] : 0;
        $partner_id = isset($_POST['partner']) ? (int)$_POST['partner'] : 0;

        if ($group_id <= 0 || $partner_id <= 0
            || get_post_type($group_id) !== 'leadrouter_group'
            || get_post_type($partner_id) !== 'leadrouter_partner') {
            wp_send_json_error(['message' => __('Некоректні дані', 'leadrouter')], 400);
        }

        if (!current_user_can('edit_post', $group_id) || !current_user_can('edit_post', $partner_id)) {
            wp_send_json_error(['message' => __('Недостатньо прав', 'leadrouter')], 403);
        }

        return [$group_id, $partner_id];
    }

    /** Свіжий HTML таблиці і плану слотів після дії */
    private static function refresh_payload(int $group_id): array
    {
        return [
            'body' => self::render_body($group_id),
            'plan' => self::slot_plan_html($group_id),
        ];
    }

    /**
     * План слотів у тому ж вигляді, що й поле lr_group_slot_plan.
     * Порожній рядок — якщо група не shared або показувати нема чого.
     */
    public static function slot_plan_html(int $group_id): string
    {
        if (!class_exists('LeadRouter_Slot_Planner')) {
            return '';
        }
        if ((string)get_post_meta($group_id, '_lr_group_mode', true) !== 'shared') {
            return '';
        }

        $partners = LeadRouter_Slot_Planner::partners_for_group($group_id);
        if (empty($partners)) {
            return '';
        }

        $n = (int)get_post_meta($group_id, '_lr_group_share_n', true);
        $l = (int)get_post_meta($group_id, '_lr_group_daily_volume', true);
        if ($n <= 0) {
            $n = 6;
        }

        return LeadRouter_Slot_Planner::render_html(LeadRouter_Slot_Planner::plan($partners, $n, $l));
    }

    /* ===================== HELPERS ===================== */

    /**
     * Перерахувати ваги груп після переносу. Саме тут закривається давня
     * дірка: LeadRouter_Hooks реагує лише на зміну денних лімітів, тож без
     * цього виклику ваги обох груп лишались би старими.
     */
    private static function recalc_groups(array $group_ids): void
    {
        if (!function_exists('leadrouter_recalc_sum_weight')) {
            return;
        }

        foreach (array_unique(array_filter(array_map('intval', $group_ids))) as $gid) {
            if (get_post_type($gid) === 'leadrouter_group') {
                leadrouter_recalc_sum_weight($gid);
            }
        }
    }

    private static function today_slug(): string
    {
        static $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $dow = (int)(new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('N');

        return $map[$dow] ?? 'mon';
    }


}
