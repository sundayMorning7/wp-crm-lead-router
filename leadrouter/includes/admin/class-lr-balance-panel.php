<?php
/**
 * LR_Balance_Panel — панель балансування на головній сторінці LeadRouter.
 *
 * Показує «хто як добирає сьогодні»: факт проти рівномірного плану на поточну
 * годину (без прогнозів), з inline-редагуванням коефіцієнтів.
 *
 * Верстка — дашборд-картки по групах із прогрес-барами партнерів; зверху —
 * шапка зі зведеними метриками дня і рядком «інших» статусів лідів за весь
 * час. Чистий CSS, без зовнішніх бібліотек.
 *
 * Дані — агрегати з leadrouter_lead_assignments (+ used_today з partner_logs
 * для звірки). Час — EST. Автооновлення AJAX раз на 60 с.
 *
 * Семантика «активний партнер» — як у прод-звіті Daily Breakdown:
 * опублікований і з _leadrouter_partner_active = 1, незалежно від того, чи
 * отримав він щось сьогодні. Неактивний партнер, що встиг отримати копії
 * сьогодні, лишається в списку з бейджем «неактивний».
 */

defined('ABSPATH') || exit;

class LR_Balance_Panel
{
    const NONCE   = 'lr_balance_panel';
    const REFRESH = 60; // секунд

    /**
     * Історичний список резервних груп за назвою. Використовується ЛИШЕ під час
     * одноразової міграції (див. maybe_migrate_reserve_groups): щоб панель не
     * втратила резерв на інсталяціях, де налаштування жодного разу не зберігали.
     * Після міграції джерело правди — поле «Резервні групи» в налаштуваннях, а
     * назви тут більше ні на що не впливають.
     */
    const LEGACY_RESERVE_NAMES = [
        'Main Pride | Group',
        'Pride Shared | Group',
    ];

    /** Ключ поля Carbon Fields на вкладці «Резервні групи» */
    const RESERVE_FIELD = 'lr_reserve_groups';

    public static function register(): void
    {
        add_action('wp_ajax_lr_balance_data', [__CLASS__, 'ajax_data']);
        add_action('wp_ajax_lr_balance_set_coef', [__CLASS__, 'ajax_set_coef']);

        // init — уже після boot Carbon Fields (after_setup_theme, пріоритет 11)
        add_action('init', [__CLASS__, 'maybe_migrate_reserve_groups'], 20);
    }

    /**
     * ID груп (post_id), які показуємо завжди. Джерело правди — поле
     * «Резервні групи» на вкладці налаштувань (Carbon Fields).
     * Фільтр лишається для програмних винятків.
     *
     * @return int[]
     */
    public static function reserve_group_ids(): array
    {
        $ids = function_exists('carbon_get_theme_option')
            ? (array) carbon_get_theme_option(self::RESERVE_FIELD)
            : [];

        $out = [];
        foreach ((array)apply_filters('leadrouter_balance_reserve_groups', $ids) as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    /**
     * Одноразове перенесення резервних груп у поле налаштувань.
     *
     * Раніше список жив в окремій сторінці (опція lr_reserve_groups), а якщо її
     * не зберігали — діяв історичний список за назвами. Переносимо те, що діяло
     * фактично, щоб після оновлення панель показувала рівно те саме. Далі
     * адмін вільно редагує список у вкладці, включно з «зняти все».
     */
    public static function maybe_migrate_reserve_groups(): void
    {
        if (get_option('lr_reserve_groups_migrated') === 'done') {
            return;
        }

        if (!function_exists('carbon_set_theme_option')) {
            return; // Carbon ще не піднявся — спробуємо наступного разу
        }

        $old = get_option('lr_reserve_groups', null);
        $ids = is_array($old) ? $old : self::legacy_reserve_ids();

        $ids = array_values(array_filter(array_map('intval', (array) $ids)));

        // set-поле Carbon зберігає значення рядками
        carbon_set_theme_option(self::RESERVE_FIELD, array_map('strval', $ids));

        update_option('lr_reserve_groups_migrated', 'done');
    }

    /** Резервні за старим списком назв — лише для одноразової міграції */
    private static function legacy_reserve_ids(): array
    {
        global $wpdb;

        $names = self::LEGACY_RESERVE_NAMES;
        if (empty($names)) {
            return [];
        }

        $in  = implode(',', array_fill(0, count($names), '%s'));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->prefix}leadrouter_groups WHERE name IN ({$in})",
            $names
        )) ?: [];

        return array_map('intval', $ids);
    }

    /* ===================== РЕНДЕР ===================== */

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $data = self::collect();

        echo '<div class="lr-balance-panel" data-nonce="' . esc_attr(wp_create_nonce(self::NONCE)) . '">';
        self::print_styles();

        echo '<div class="lr-balance-body">';
        echo self::render_body($data);
        echo '</div>';

        self::print_assets();
        echo '</div>';
    }

    /** Тіло панелі (те саме повертає AJAX) */
    public static function render_body(array $data): string
    {
        // Резервні йдуть не картками, а одним рядком над сіткою: вони потрібні
        // для контролю («що ми туди відправляємо»), але місця в сітці з їхніми
        // нулями не заслуговують.
        $reserve = array_values(array_filter($data['groups'], function ($g) {
            return !empty($g['is_reserve']);
        }));

        // групи без жодного видимого партнера (немає партнерів, самі
        // неактивні без доставок або всі з нульовим лімітом) не показуємо
        $groups = array_values(array_filter($data['groups'], function ($g) {
            return empty($g['is_reserve']) && !empty($g['partners']);
        }));

        // групи з активністю — вгору, далі за назвою
        usort($groups, function ($a, $b) {
            $cmp = ($b['received'] > 0) <=> ($a['received'] > 0);
            return $cmp !== 0 ? $cmp : strnatcasecmp($a['name'], $b['name']);
        });

        usort($reserve, function ($a, $b) {
            return strnatcasecmp($a['name'], $b['name']);
        });

        // ── Зведення дня (по всіх видимих групах, разом із резервними:
        //    ліди туди теж реальні й мають потрапляти в підсумок дня) ──
        $total_leads    = 0;
        $total_copies   = 0;
        $groups_active  = 0;
        $active_partner_ids = [];
        foreach (array_merge($groups, $reserve) as $g) {
            $total_leads  += $g['received'];
            $total_copies += $g['copies'];
            if ($g['received'] > 0) {
                $groups_active++;
            }
            foreach ($g['partners'] as $p) {
                if ($p['is_active']) {
                    $active_partner_ids[$p['id']] = true;
                }
            }
        }

        // ── Шапка: заголовок ліворуч, метрики праворуч ──
        $html = '<div class="lr-bp-head">';

        $html .= '<div class="lr-bp-head-titles">';
        $html .= '<div class="lr-bp-overline">' . esc_html__('Огляд розподілу за день', 'leadrouter') . '</div>';
        $html .= '<h2 class="lr-bp-title">' . esc_html__('Балансування сьогодні (EST)', 'leadrouter') . '</h2>';
        $html .= '<div class="lr-bp-sub">'
            . sprintf(
                esc_html__('EST %1$s · минуло доби %2$s%% · оновлення раз на %3$d с', 'leadrouter'),
                esc_html($data['now']),
                esc_html(number_format($data['day_fraction'] * 100, 1)),
                self::REFRESH
            )
            . '</div>';
        $html .= '</div>';

        $html .= '<div class="lr-bp-summary">';
        foreach ([
            [__('Унікальні ліди', 'leadrouter'),     (string)$total_leads],
            [__('Копії партнерам', 'leadrouter'),    (string)$total_copies],
            [__('Групи з активністю', 'leadrouter'),  $groups_active . ' / ' . (count($groups) + count($reserve))],
            [__('Активні партнери', 'leadrouter'),   (string)count($active_partner_ids)],
        ] as $m) {
            $html .= '<div class="lr-bp-metric"><span>' . esc_html($m[0]) . '</span><strong>' . esc_html($m[1]) . '</strong></div>';
        }

        // Перемикач вигляду таблиці лідів — останнім у ряду метрик.
        // Тільки на сторінці лідів: на дашборді таблиці немає, і тумблер там
        // не мав би до чого стосуватись.
        $html .= self::render_view_toggle($data);

        $html .= '</div>';

        $html .= '</div>';

        // ── Інші статуси лідів за весь час ──
        $html .= self::render_status_line($data['status_counts']);

        // ── Резервні групи: один рядок над сіткою ──
        $html .= self::render_reserve_line($reserve);

        if (empty($groups)) {
            return $html . '<p class="lr-bp-none"><em>' . esc_html__('Немає груп з активними партнерами на сьогодні', 'leadrouter') . '</em></p>';
        }

        // ── Картки груп ──
        $html .= '<div class="lr-bp-grid">';
        foreach ($groups as $g) {
            $html .= self::render_group_card($g);
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Резервні групи одним рядком над сіткою, з позначкою «*».
     * Показуємо головне: скільки туди пішло сьогодні і — якщо нічого — чому.
     */
    private static function render_reserve_line(array $groups): string
    {
        if (empty($groups)) {
            return '';
        }

        $items = [];
        foreach ($groups as $g) {
            $edit_url = get_edit_post_link((int)$g['post_id']);

            $item = '<span class="lr-bp-rsv-item">';
            $item .= '<span class="lr-bp-rsv-name">'
                . ($edit_url ? '<a href="' . esc_url($edit_url) . '">' . esc_html($g['name']) . '</a>' : esc_html($g['name']))
                . '</span>';

            $item .= '<span class="lr-bp-rsv-nums">'
                . sprintf(
                    esc_html__('%1$d лідів · %2$d копій', 'leadrouter'),
                    (int)$g['received'],
                    (int)$g['copies']
                )
                . '</span>';

            $item .= '<span class="lr-bp-coef-wrap" title="' . esc_attr__('Коефіцієнт групи', 'leadrouter') . '">'
                . self::coef_input('group', (int)$g['post_id'], (float)$g['coef'], (string)$g['name'])
                . '</span>';

            $reason = self::reserve_reason($g);
            if ($reason !== '') {
                $item .= '<span class="lr-bp-rsv-why">' . esc_html($reason) . '</span>';
            }

            $item .= '</span>';
            $items[] = $item;
        }

        return '<div class="lr-bp-rsv">'
            . '<span class="lr-bp-rsv-star">*</span>'
            . '<span class="lr-bp-rsv-label">' . esc_html__('Резервні групи', 'leadrouter') . '</span>'
            . implode('', $items)
            . '</div>';
    }

    /**
     * Чому резерв нічого не приймає. Порожній рядок = усе гаразд, група
     * робоча. Кажемо прямо, бо нуль у резерві сам по собі виглядає як «тихо».
     */
    private static function reserve_reason(array $g): string
    {
        $active     = 0;
        $sum_limits = 0;
        foreach ($g['partners'] as $p) {
            if (!empty($p['is_active'])) {
                $active++;
                $sum_limits += (int)$p['limit'];
            }
        }

        $reason = '';
        if (empty($g['partners'])) {
            $reason = __('партнерів немає', 'leadrouter');
        } elseif ($active === 0) {
            $reason = __('усі партнери вимкнені', 'leadrouter');
        } elseif ($sum_limits === 0) {
            $reason = __('ліміти на сьогодні = 0', 'leadrouter');
        }

        if (!empty($g['is_off'])) {
            $reason = trim(__('група вимкнена', 'leadrouter') . ($reason !== '' ? ', ' . $reason : ''));
        }

        return $reason;
    }

    /**
     * Тумблер «Вид»: класичний ⇄ компактний вигляд таблиці лідів.
     * Без підписів станів — підпис лише над ним, як у решти метрик.
     */
    private static function render_view_toggle(array $data): string
    {
        if (!class_exists('LeadRouter_Leads_Table')) {
            return '';
        }

        // при звичайному рендері беремо сторінку з URL, при автооновленні —
        // з контексту, який передав JS
        $page = isset($data['ctx_page']) && $data['ctx_page'] !== ''
            ? $data['ctx_page']
            : (isset($_GET['page']) ? sanitize_key((string)$_GET['page']) : '');

        if ($page !== 'leadrouter-leads') {
            return '';
        }

        $compact = LeadRouter_Leads_Table::is_compact();
        $next    = $compact ? 'classic' : 'compact';

        $url = add_query_arg(
            [
                'page'     => 'leadrouter-leads',
                'lr_view'  => $next,
                '_lrview'  => wp_create_nonce(LeadRouter_Leads_Table::VIEW_NONCE),
            ],
            admin_url('admin.php')
        );

        $title = $compact
            ? __('Компактний вигляд — перемкнути на класичний', 'leadrouter')
            : __('Класичний вигляд — перемкнути на компактний', 'leadrouter');

        return '<div class="lr-bp-metric lr-bp-metric-view">'
            . '<span>' . esc_html__('Вид', 'leadrouter') . '</span>'
            . '<a class="lr-bp-toggle' . ($compact ? ' is-on' : '') . '" href="' . esc_url($url) . '"'
            . ' role="switch" aria-checked="' . ($compact ? 'true' : 'false') . '"'
            . ' title="' . esc_attr($title) . '" aria-label="' . esc_attr($title) . '">'
            . '<span class="lr-bp-toggle-knob"></span>'
            . '</a></div>'

            . '<div class="lr-bp-metric lr-bp-metric-view">'
            . '<span>' . esc_html__('К-ть', 'leadrouter') . '</span>'
            . LeadRouter_Leads_Table::per_page_select_html()
            . '</div>';
    }

    /** Компактний рядок лічильників не-sent статусів лідів */
    private static function render_status_line(array $counts): string
    {
        if (empty($counts)) {
            return '';
        }

        $html = '<div class="lr-bp-statuses">';
        $html .= '<span class="lr-bp-statuses-label">' . esc_html__('Інші статуси · за весь час', 'leadrouter') . '</span>';
        foreach ($counts as $status => $count) {
            $label = ucwords(trim(str_replace(['_', '-'], ' ', (string)$status)));
            $html .= '<span class="lr-bp-status-pill">' . esc_html($label) . ' <strong>' . (int)$count . '</strong></span>';
        }
        $html .= '</div>';

        return $html;
    }

    private static function render_group_card(array $g): string
    {
        $edit_url = get_edit_post_link($g['post_id'], 'raw');

        $active = 0;
        foreach ($g['partners'] as $p) {
            if ($p['is_active']) {
                $active++;
            }
        }

        $html = '<div class="lr-bp-card">';

        // Шапка: назва + режим + коеф групи
        $html .= '<div class="lr-bp-card-head">';
        $html .= '<span class="lr-bp-gname">'
            . ($edit_url ? '<a href="' . esc_url($edit_url) . '">' . esc_html($g['name']) . '</a>' : esc_html($g['name']))
            . ($g['mode'] === 'shared'
                ? ' <span class="lr-bp-badge-shared">shared × ' . (int)$g['share_n'] . '</span>'
                : '')
            . (!empty($g['overflow_on'])
                ? ' <span class="lr-bp-badge-ovf-on" title="'
                    . esc_attr($g['overflow_cap'] > 0
                        ? sprintf(__('М’яка квота увімкнена, стеля %d лідів понад ціль', 'leadrouter'), (int)$g['overflow_cap'])
                        : __('М’яка квота увімкнена, без стелі (до вичерпання лімітів партнерів)', 'leadrouter'))
                    . '">overflow'
                    . ($g['overflow_cap'] > 0 ? ' ≤' . (int)$g['overflow_cap'] : '')
                    . '</span>'
                : '')
            . '</span>';
        $html .= '<span class="lr-bp-coef-wrap" title="' . esc_attr__('Коефіцієнт групи', 'leadrouter') . '">'
            . self::coef_input('group', (int)$g['post_id'], (float)$g['coef'], (string)$g['name'])
            . '</span>';
        $html .= '</div>';

        // Метрики групи
        $html .= '<div class="lr-bp-gstats">';
        foreach ([
            [__('Ліди', 'leadrouter'),    (string)$g['received']],
            [__('Копії', 'leadrouter'),   (string)$g['copies']],
            [__('Активні', 'leadrouter'), (string)$active],
        ] as $m) {
            $html .= '<div><span>' . esc_html($m[0]) . '</span><strong>' . esc_html($m[1]) . '</strong></div>';
        }
        $html .= '</div>';

        // План: ціль дня (+ overflow, якщо був), план на зараз, відхилення
        $html .= '<div class="lr-bp-plan">'
            . sprintf(
                esc_html__('Ціль дня: %1$d · план на зараз: %2$s', 'leadrouter'),
                (int)$g['target'],
                esc_html(number_format($g['plan_now'], 1))
            )
            . ((int)($g['overflow_cnt'] ?? 0) > 0
                ? ' <span class="lr-bp-badge-ovf-cnt" title="'
                    . esc_attr__('Лідів прийнято понад денну ціль (м’яка квота)', 'leadrouter')
                    . '">+' . (int)$g['overflow_cnt'] . ' overflow</span>'
                : '')
            . ' ' . self::deviation_badge($g['received'] - $g['plan_now'])
            . '</div>';

        // Партнери
        $html .= '<div class="lr-bp-plist">';
        foreach ($g['partners'] as $p) {
            $html .= self::render_partner_row($p);
        }
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    private static function render_partner_row(array $p): string
    {
        $limit = max(1, (int)$p['limit']);
        $pct   = min(100, (int)round((int)$p['received'] / $limit * 100));
        $over  = (int)$p['received'] > (int)$p['limit'];

        $html = '<div class="lr-bp-p' . ($p['is_active'] ? '' : ' is-inactive') . '">';

        // Ім'я — посилання на екран редагування партнера. get_edit_post_link()
        // поверне null, якщо прав немає, — тоді лишаємо звичайний текст.
        $edit_url = get_edit_post_link((int)$p['id'], 'raw');
        $name_html = $edit_url
            ? '<a href="' . esc_url($edit_url) . '">' . esc_html($p['label']) . '</a>'
            : esc_html($p['label']);

        $html .= '<div class="lr-bp-p-top">';
        $html .= '<span class="lr-bp-p-name">' . $name_html
            . ($p['owner'] !== '' ? ' <span class="lr-bp-owner">· ' . esc_html($p['owner']) . '</span>' : '')
            . ($p['is_active'] ? '' : ' <span class="lr-bp-badge-inactive">' . esc_html__('неактивний', 'leadrouter') . '</span>')
            . '</span>';
        $html .= '<span class="lr-bp-p-nums"><strong>' . (int)$p['received'] . '</strong>'
            . ((int)$p['limit'] > 0 ? '<em>/ ' . (int)$p['limit'] . '</em>' : '')
            . ((int)$p['received'] !== (int)$p['used_logs']
                ? ' <span class="lr-bp-warn" title="' . esc_attr__('Розбіжність із partner_logs', 'leadrouter') . '">≠' . (int)$p['used_logs'] . '</span>'
                : '')
            . '</span>';
        $html .= '<span class="lr-bp-coef-wrap" title="' . esc_attr__('Коефіцієнт партнера', 'leadrouter') . '">'
            . self::coef_input('partner', (int)$p['id'], (float)$p['coef'], (string)$p['label'])
            . '</span>';
        $html .= '</div>';

        if ((int)$p['limit'] > 0) {
            $html .= '<div class="lr-bp-bar">'
                . '<div class="lr-bp-bar-fill' . ($over ? ' is-over' : '') . '" style="width:' . $pct . '%"></div>'
                . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /* ===================== ДАНІ ===================== */

    public static function collect(): array
    {
        global $wpdb;

        $t_groups = $wpdb->prefix . 'leadrouter_groups';
        $t_assign = $wpdb->prefix . 'leadrouter_lead_assignments';
        $t_plogs  = $wpdb->prefix . 'leadrouter_partner_logs';
        $t_leads  = $wpdb->prefix . 'leadrouter_leads';

        $tz  = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('now', $tz);
        $day_start = $now->format('Y-m-d 00:00:00');
        $day_end   = $now->format('Y-m-d 23:59:59');
        $dow       = (int)$now->format('N');

        static $slug_map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $day_slug = $slug_map[$dow] ?? 'mon';

        // частка доби, що минула (рівномірна крива, без прогнозів)
        $elapsed  = (int)$now->format('H') * 3600 + (int)$now->format('i') * 60 + (int)$now->format('s');
        $fraction = min(1.0, max(0.0, $elapsed / 86400));

        // Активні групи + резервні (їх показуємо, навіть якщо група вимкнена)
        $select = "SELECT id, post_id, name, mode, share_n, daily_volume, coef, active,
                          overflow_on, overflow_cap, weight_{$dow} AS weight_today
                     FROM {$t_groups} ";

        $reserve_ids = self::reserve_group_ids();
        if (!empty($reserve_ids)) {
            $in   = implode(',', array_fill(0, count($reserve_ids), '%d'));
            $rows = $wpdb->get_results(
                $wpdb->prepare($select . "WHERE active = 1 OR post_id IN ({$in}) ORDER BY name", $reserve_ids),
                ARRAY_A
            ) ?: [];
        } else {
            $rows = $wpdb->get_results($select . 'WHERE active = 1 ORDER BY name', ARRAY_A) ?: [];
        }

        // отримано сьогодні: ліди (DISTINCT) і копії — з assignments, по групах.
        // Лише status = 'sent', як і в лічильниках партнерів нижче: failed-спроби
        // і незавершені броні 'sending' не є «отриманим» і плутали б картку групи
        // (група показувала б копії, яких немає в жодного партнера).
        $agg = $wpdb->get_results($wpdb->prepare(
            "SELECT group_id, COUNT(DISTINCT lead_id) AS leads, COUNT(*) AS copies
               FROM {$t_assign} WHERE status = 'sent' AND created_at BETWEEN %s AND %s GROUP BY group_id",
            $day_start,
            $day_end
        ), ARRAY_A) ?: [];

        $by_group = [];
        foreach ($agg as $a) {
            $by_group[(int)$a['group_id']] = ['leads' => (int)$a['leads'], 'copies' => (int)$a['copies']];
        }

        // Ліди, що зайшли сьогодні ПОНАД квоту (м'яка квота): рахуємо по лозі
        // призначень — диспетчер маркує їх статусом group_assigned_overflow
        $t_logs = $wpdb->prefix . 'leadrouter_logs';
        $overflow_map = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT group_id, COUNT(DISTINCT lead_id) AS c FROM {$t_logs}
              WHERE status = 'group_assigned_overflow' AND assigned_at BETWEEN %s AND %s
              GROUP BY group_id",
            $day_start,
            $day_end
        ), ARRAY_A) ?: [] as $r) {
            $overflow_map[(int)$r['group_id']] = (int)$r['c'];
        }

        // отримано сьогодні по партнерах: одна агрегація замість запиту на партнера
        $recv_map = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT partner_id, COUNT(*) AS c FROM {$t_assign}
              WHERE status = 'sent' AND created_at BETWEEN %s AND %s GROUP BY partner_id",
            $day_start,
            $day_end
        ), ARRAY_A) ?: [] as $r) {
            $recv_map[(int)$r['partner_id']] = (int)$r['c'];
        }

        $logs_map = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT partner_id, COUNT(*) AS c FROM {$t_plogs}
              WHERE status IN ('sent','accepted','queued') AND attempted_at BETWEEN %s AND %s
              GROUP BY partner_id",
            $day_start,
            $day_end
        ), ARRAY_A) ?: [] as $r) {
            $logs_map[(int)$r['partner_id']] = (int)$r['c'];
        }

        // конфігурація всіх партнерів одним запитом: група, активність,
        // ліміт на сьогодні, власник. Неактивні теж потрібні (пункт «отримав,
        // але вже вимкнений»)
        $cfg_by_group = [];
        foreach ($wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS id, p.post_title AS label, p.post_status,
                    MAX(gm.meta_value) AS group_id,
                    MAX(am.meta_value) AS active_meta,
                    MAX(lm.meta_value) AS day_limit,
                    MAX(om.meta_value) AS owner
               FROM {$wpdb->posts} p
               LEFT JOIN {$wpdb->postmeta} gm ON gm.post_id = p.ID AND gm.meta_key = '_leadrouter_partner_group'
               LEFT JOIN {$wpdb->postmeta} am ON am.post_id = p.ID AND am.meta_key = '_leadrouter_partner_active'
               LEFT JOIN {$wpdb->postmeta} lm ON lm.post_id = p.ID AND lm.meta_key = %s
               LEFT JOIN {$wpdb->postmeta} om ON om.post_id = p.ID AND om.meta_key = '_lr_partner_owner'
              WHERE p.post_type = 'leadrouter_partner' AND p.post_status <> 'trash'
              GROUP BY p.ID, p.post_title, p.post_status",
            "_leadrouter_partner_{$day_slug}_limit"
        ), ARRAY_A) ?: [] as $r) {
            $gid = (int)$r['group_id'];
            $cfg_by_group[$gid][] = [
                'id'        => (int)$r['id'],
                'label'     => trim((string)$r['label']) !== '' ? trim((string)$r['label']) : ('Partner #' . (int)$r['id']),
                'owner'     => class_exists('LR_Shared_Sync')
                    ? LR_Shared_Sync::normalize_owner($r['owner'])
                    : trim((string)$r['owner']),
                'limit'     => max(0, (int)$r['day_limit']),
                'is_active' => $r['post_status'] === 'publish' && (string)$r['active_meta'] === '1',
            ];
        }

        $groups = [];
        foreach ($rows as $r) {
            $is_shared  = $r['mode'] === 'shared';
            $target     = $is_shared ? (int)$r['daily_volume'] : (int)$r['weight_today'];
            $post_id    = (int)$r['post_id'];
            $is_reserve = in_array($post_id, $reserve_ids, true);

            $partners = [];
            foreach ($cfg_by_group[$post_id] ?? [] as $cfg) {
                $pid      = $cfg['id'];
                $limit    = $cfg['limit'];
                $received = $recv_map[$pid] ?? 0;

                // активний — якщо є ліміт на сьогодні або вже щось отримав;
                // неактивний — лише якщо встиг щось отримати сьогодні.
                // У резервній групі показуємо всіх: саме нульові ліміти й
                // вимкнені партнери й пояснюють, чому резерв нічого не приймає.
                $visible = $is_reserve || ($cfg['is_active']
                    ? ($limit > 0 || $received > 0)
                    : ($received > 0));
                if (!$visible) {
                    continue;
                }

                $partners[] = [
                    'id'        => $pid,
                    'label'     => $cfg['label'],
                    'owner'     => $cfg['owner'],
                    'is_active' => $cfg['is_active'],
                    'limit'     => $limit,
                    'received'  => $received,
                    'used_logs' => $logs_map[$pid] ?? 0,
                    'left'      => max(0, $limit - $received),
                    'plan_now'  => $limit * $fraction,
                    'coef'      => class_exists('LR_Shared_Sync') ? LR_Shared_Sync::get_partner_coef($pid) : 1.0,
                ];
            }

            // за денним лімітом спадаючо (хто більший у групі — вище),
            // далі за отриманим, далі за назвою
            usort($partners, function ($a, $b) {
                if ((int)$a['limit'] !== (int)$b['limit']) {
                    return (int)$b['limit'] <=> (int)$a['limit'];
                }
                if ((int)$a['received'] !== (int)$b['received']) {
                    return (int)$b['received'] <=> (int)$a['received'];
                }
                return strnatcasecmp($a['label'], $b['label']);
            });

            $groups[] = [
                'row_id'       => (int)$r['id'],
                'post_id'      => $post_id,
                'name'         => (string)$r['name'],
                'mode'         => (string)$r['mode'],
                'share_n'      => (int)$r['share_n'],
                'coef'         => (float)$r['coef'],
                'target'       => $target,
                'received'     => (int)($by_group[(int)$r['id']]['leads'] ?? 0),
                'copies'       => (int)($by_group[(int)$r['id']]['copies'] ?? 0),
                'plan_now'     => $target * $fraction,
                'partners'     => $partners,
                'is_reserve'   => $is_reserve,
                'is_off'       => (int)$r['active'] !== 1,
                'overflow_on'  => !empty($r['overflow_on']),
                'overflow_cap' => max(0, (int)$r['overflow_cap']),
                'overflow_cnt' => (int)($overflow_map[(int)$r['id']] ?? 0),
            ];
        }

        // статуси лідів за весь час, крім sent (порожні теж пропускаємо)
        $status_counts = [];
        foreach ($wpdb->get_results(
            "SELECT status, COUNT(*) AS c FROM {$t_leads} GROUP BY status",
            ARRAY_A
        ) ?: [] as $r) {
            $status = trim((string)$r['status']);
            if ($status === '' || $status === 'sent' || (int)$r['c'] === 0) {
                continue;
            }
            $status_counts[$status] = (int)$r['c'];
        }

        return [
            'now'           => $now->format('Y-m-d H:i:s'),
            'day_fraction'  => $fraction,
            'groups'        => $groups,
            'status_counts' => $status_counts,
        ];
    }

    /* ===================== AJAX ===================== */

    public static function ajax_data(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав', 'leadrouter')], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');

        $data = self::collect();

        // JS передає сторінку, з якої прийшло автооновлення: без цього тумблер
        // «Вид» зникав би з панелі після першого ж рефрешу (в AJAX-запиті
        // немає $_GET['page'])
        $data['ctx_page'] = isset($_POST['ctx_page']) ? sanitize_key((string)$_POST['ctx_page']) : '';

        wp_send_json_success([
            'html' => self::render_body($data),
            'now'  => $data['now'],
        ]);
    }

    public static function ajax_set_coef(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Недостатньо прав', 'leadrouter')], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');

        $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $val  = isset($_POST['value']) ? (float)str_replace(',', '.', (string)$_POST['value']) : 0.0;

        if ($id <= 0 || $val <= 0 || !in_array($type, ['group', 'partner'], true)) {
            wp_send_json_error(['message' => __('Некоректні дані', 'leadrouter')], 400);
        }

        $val = round($val, 2);

        // Мета — джерело правди; LR_Shared_Sync сам синкне групу і запише журнал
        if ($type === 'group') {
            if (get_post_type($id) !== 'leadrouter_group') {
                wp_send_json_error(['message' => __('Групу не знайдено', 'leadrouter')], 404);
            }
            update_post_meta($id, LR_Shared_Sync::META_GROUP_COEF, (string)$val);
        } else {
            if (get_post_type($id) !== 'leadrouter_partner') {
                wp_send_json_error(['message' => __('Партнера не знайдено', 'leadrouter')], 404);
            }
            update_post_meta($id, LR_Shared_Sync::META_PARTNER_COEF, (string)$val);
        }

        wp_send_json_success(['value' => $val]);
    }

    /* ===================== ЕЛЕМЕНТИ ===================== */

    /**
     * Поле коефіцієнта. `data-prev` і `data-label` потрібні для підтвердження:
     * зміна коефіцієнта одразу переписує мету й впливає на розподіл, тож JS
     * перепитує «з X на Y?» і, якщо відмовили, повертає попереднє значення.
     */
    private static function coef_input(string $type, int $id, float $value, string $label = ''): string
    {
        $formatted = number_format($value, 2, '.', '');

        // коефіцієнт, відмінний від 1 — це ручне втручання в розподіл, тож
        // підсвічуємо його, щоб не шукати очима серед десятків полів
        $class = abs($value - 1.0) > 0.001 ? 'lr-coef is-custom' : 'lr-coef';

        return sprintf(
            '<input type="number" class="%s" step="0.1" min="0.1" value="%s" data-prev="%s" data-type="%s" data-id="%d" data-label="%s">',
            esc_attr($class),
            esc_attr($formatted),
            esc_attr($formatted),
            esc_attr($type),
            $id,
            esc_attr($label)
        );
    }

    private static function deviation_badge(float $delta): string
    {
        $rounded = round($delta, 1);

        if (abs($rounded) < 0.05) {
            $class = 'is-ok';
            $text  = '0';
        } elseif ($rounded > 0) {
            $class = 'is-ok';
            $text  = '+' . number_format($rounded, 1);
        } else {
            $class = abs($rounded) >= 3 ? 'is-bad' : 'is-warn';
            $text  = number_format($rounded, 1);
        }

        return '<span class="lr-bp-badge ' . $class . '">' . esc_html($text) . '</span>';
    }

    /* ===================== CSS / JS ===================== */

    private static function print_styles(): void
    {
        ?>
        <style>
        .lr-balance-panel{--lrb-border:#e5e7eb;--lrb-text:#111827;--lrb-muted:#6b7280;--lrb-faint:#9ca3af;--lrb-green:#1e8a52;--lrb-green-soft:#16a34a;--lrb-red:#dc2626;--lrb-amber:#d97706;--lrb-track:#e5e7eb;
            width:100%;color:var(--lrb-text);font-size:13px;line-height:1.45}
        .lr-balance-panel *,.lr-balance-panel *::before,.lr-balance-panel *::after{box-sizing:border-box}

        /* ── Шапка ── */
        .lr-bp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;flex-wrap:wrap;
            background:#fff;border:1px solid var(--lrb-border);border-radius:12px;padding:20px 24px;margin:8px 0 12px}
        .lr-bp-overline{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--lrb-green-soft);margin-bottom:2px}
        .lr-bp-title{margin:0;padding:0;font-size:22px;font-weight:700;color:var(--lrb-text);line-height:1.25}
        .lr-bp-sub{margin-top:4px;font-size:12px;color:var(--lrb-muted)}
        .lr-bp-summary{display:flex;align-items:stretch}
        .lr-bp-metric{padding:2px 22px;border-left:1px solid var(--lrb-border)}
        .lr-bp-metric:first-child{border-left:0}
        .lr-bp-metric span{display:block;font-size:12px;color:var(--lrb-muted);white-space:nowrap}
        .lr-bp-metric strong{display:block;margin-top:2px;font-size:20px;font-weight:700;line-height:1.2}

        /* ── Тумблер «Вид» (класичний ⇄ компактний вигляд таблиці лідів) ── */
        .lr-bp-metric-view{display:flex;flex-direction:column;justify-content:flex-start}
        .lr-bp-toggle{display:inline-block;position:relative;width:40px;height:22px;margin-top:6px;
            border-radius:999px;background:#d1d5db;transition:background .18s;text-decoration:none;flex:0 0 auto}
        .lr-bp-toggle:hover{background:#bcc0c6}
        .lr-bp-toggle.is-on{background:var(--lrb-green-soft)}
        .lr-bp-toggle.is-on:hover{background:var(--lrb-green)}
        .lr-bp-toggle:focus{outline:2px solid var(--lrb-green-soft);outline-offset:2px}
        .lr-bp-toggle-knob{position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;
            background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25);transition:transform .18s}
        .lr-bp-toggle.is-on .lr-bp-toggle-knob{transform:translateX(18px)}
        /* селектор довгий навмисно: .wp-core-ui select із forms.css має вищу
           специфічність і інакше розтягує поле до 40px */
        .lr-balance-panel select.lr-bp-perpage{margin-top:6px;width:72px;height:26px;min-height:26px;line-height:24px;
            padding:0 24px 0 8px;font-size:12px;border:1px solid var(--lrb-border);border-radius:6px;
            color:var(--lrb-text);background-color:#fff;vertical-align:top}
        .lr-balance-panel select.lr-bp-perpage:focus{border-color:var(--lrb-green-soft);
            box-shadow:0 0 0 1px var(--lrb-green-soft)}

        /* ── Рядок статусів ── */
        .lr-bp-statuses{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 16px;padding:0 4px}
        .lr-bp-statuses-label{font-size:12px;color:var(--lrb-muted)}
        .lr-bp-status-pill{display:inline-block;padding:2px 10px;border:1px solid var(--lrb-border);border-radius:999px;background:#fff;font-size:12px;color:var(--lrb-muted)}
        .lr-bp-status-pill strong{color:var(--lrb-text);font-weight:700;margin-left:2px}

        /* ── Сітка карток ── */
        .lr-bp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;align-items:start}
        @media (max-width:1200px){.lr-bp-grid{grid-template-columns:repeat(2,1fr)}}
        @media (max-width:782px){.lr-bp-grid{grid-template-columns:1fr}}
        .lr-bp-card{background:#fff;border:1px solid var(--lrb-border);border-radius:12px;padding:18px 20px}
        .lr-bp-card-head{display:flex;align-items:center;justify-content:space-between;gap:10px}
        .lr-bp-gname{font-size:15px;font-weight:700;min-width:0}
        .lr-bp-gname a{text-decoration:none;color:var(--lrb-text)}
        .lr-bp-gname a:hover{color:var(--lrb-green)}
        .lr-bp-badge-shared{color:#7c3aed;font-weight:500;font-size:11px;white-space:nowrap}
        .lr-bp-badge-ovf-on{color:#b45309;font-weight:500;font-size:11px;white-space:nowrap;border:1px solid #fcd34d;border-radius:9px;padding:0 6px;background:#fffbeb}
        .lr-bp-badge-ovf-cnt{color:#92400e;font-weight:600;font-size:11px;white-space:nowrap;border-radius:9px;padding:1px 7px;background:#fef3c7}
        /* ── Резервні групи: рядок над сіткою ── */
        .lr-bp-rsv{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 16px;padding:10px 14px;
            background:#fff;border:1px solid var(--lrb-border);border-radius:12px}
        .lr-bp-rsv-star{color:#4338ca;font-weight:700;line-height:1}
        .lr-bp-rsv-label{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--lrb-faint)}
        .lr-bp-rsv-item{display:inline-flex;align-items:center;gap:8px;padding:2px 12px;border-left:1px solid var(--lrb-border)}
        .lr-bp-rsv-item:first-of-type{border-left:0}
        .lr-bp-rsv-name{font-weight:600}
        .lr-bp-rsv-name a{text-decoration:none;color:var(--lrb-text)}
        .lr-bp-rsv-name a:hover{color:var(--lrb-green)}
        .lr-bp-rsv-nums{color:var(--lrb-muted);white-space:nowrap}
        .lr-bp-rsv-why{padding:1px 8px;border-radius:999px;background:#fffbeb;border:1px solid #fde68a;color:#78350f;font-size:11px;white-space:nowrap}

        .lr-bp-gstats{display:flex;gap:36px;margin:12px 0 10px}
        .lr-bp-gstats span{display:block;font-size:11px;color:var(--lrb-muted)}
        .lr-bp-gstats strong{display:block;margin-top:1px;font-size:17px;font-weight:700}

        .lr-bp-plan{font-size:12px;color:var(--lrb-muted);padding-bottom:12px;margin-bottom:6px;border-bottom:1px solid var(--lrb-border)}
        .lr-bp-badge{display:inline-block;padding:1px 8px;border-radius:999px;color:#fff;font-size:11px;font-weight:600;vertical-align:1px}
        .lr-bp-badge.is-ok{background:var(--lrb-green-soft)}
        .lr-bp-badge.is-warn{background:var(--lrb-amber)}
        .lr-bp-badge.is-bad{background:var(--lrb-red)}

        /* ── Партнери ── */
        .lr-bp-p{padding:9px 0 3px;border-top:1px solid #f3f4f6}
        .lr-bp-p:first-child{border-top:0}
        .lr-bp-p.is-inactive .lr-bp-p-name,.lr-bp-p.is-inactive .lr-bp-p-nums{opacity:.6}
        .lr-bp-p-top{display:flex;align-items:center;gap:10px;margin-bottom:6px}
        .lr-bp-p-name{flex:1;min-width:0;font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        /* Ім'я — посилання на редагування партнера, але вигляд панелі не змінюємо:
           колір успадковується, підкреслення лише при наведенні. */
        .lr-bp-p-name a{color:inherit;text-decoration:none}
        .lr-bp-p-name a:hover,.lr-bp-p-name a:focus{color:#2271b1;text-decoration:underline}
        .lr-bp-owner{color:var(--lrb-faint);font-size:11px;font-weight:400}
        .lr-bp-badge-inactive{display:inline-block;padding:1px 7px;border-radius:999px;background:#f3f4f6;color:var(--lrb-muted);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;vertical-align:1px}
        .lr-bp-p-nums{white-space:nowrap;font-size:13px}
        .lr-bp-p-nums strong{font-weight:700}
        .lr-bp-p-nums em{font-style:normal;color:var(--lrb-faint);margin-left:3px;font-size:12px}
        .lr-bp-warn{color:var(--lrb-red);font-size:11px}

        .lr-bp-bar{position:relative;height:6px;background:var(--lrb-track);border-radius:999px;margin-bottom:6px}
        .lr-bp-bar-fill{position:absolute;left:0;top:0;bottom:0;background:var(--lrb-green);border-radius:999px;transition:width .3s}
        .lr-bp-bar-fill.is-over{background:var(--lrb-red)}

        /* ── Коефіцієнти ── */
        .lr-bp-coef-wrap .lr-coef{width:56px;height:26px;min-height:26px;padding:0 2px 0 8px;font-size:12px;
            border:1px solid var(--lrb-border);border-radius:6px;color:var(--lrb-muted);background:#fff}
        .lr-bp-coef-wrap .lr-coef:focus{border-color:var(--lrb-green-soft);box-shadow:0 0 0 1px var(--lrb-green-soft);color:var(--lrb-text)}
        /* ≠ 1.00 — ручне втручання в розподіл. Правила нижче за :focus,
           бо специфічність однакова і виграє порядок у файлі */
        .lr-bp-coef-wrap .lr-coef.is-custom{border-color:var(--lrb-amber);color:var(--lrb-amber);
            font-weight:700;background:#fffbeb}
        .lr-bp-coef-wrap .lr-coef.is-custom:focus{border-color:var(--lrb-amber);
            box-shadow:0 0 0 1px var(--lrb-amber);color:var(--lrb-amber)}

        .lr-bp-none{margin:12px 2px;color:var(--lrb-muted)}
        </style>
        <?php
    }

    private static function print_assets(): void
    {
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <script>
        (function () {
            const root = document.querySelector('.lr-balance-panel');
            if (!root) { return; }

            const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            const nonce   = root.dataset.nonce;

            // сторінка, на якій живе панель: потрібна автооновленню, щоб
            // тумблер «Вид» не зник із рядка метрик після рефрешу
            const ctxPage = new URLSearchParams(location.search).get('page') || '';

            function post(action, extra) {
                const body = new URLSearchParams(Object.assign({action: action, nonce: nonce, ctx_page: ctxPage}, extra || {}));
                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body
                }).then(r => r.json());
            }

            function refresh(force) {
                // не перемальовуємо під руками: автооновлення інакше затерло б
                // недоредаговане значення ще до підтвердження. Після збереження
                // (force) оновлюємо попри фокус — там значення вже узгоджене.
                if (!force && root.querySelector('.lr-coef:focus')) { return; }

                post('lr_balance_data').then(function (res) {
                    if (res && res.success && res.data && res.data.html) {
                        root.querySelector('.lr-balance-body').innerHTML = res.data.html;
                    }
                }).catch(function () { /* мовчки: наступне оновлення спробує ще раз */ });
            }

            // Коефіцієнт пишеться в мету одразу і одразу міняє розподіл лідів,
            // тож без підтвердження не зберігаємо: випадковий скрол колесом над
            // полем або промах по стрілці не має тихо перекроїти розподіл.
            // «К-ть» — значення опцій уже є готовими URL зі збереженням вибору
            root.addEventListener('change', function (e) {
                const sel = e.target.closest('.lr-bp-perpage');
                if (sel && sel.value) { window.location.href = sel.value; }
            });

            // підсвітка коефіцієнта ≠ 1 одразу після правки, не чекаючи ререндер
            function markCustom(input) {
                input.classList.toggle('is-custom', Math.abs(Number(input.value) - 1) > 0.001);
            }

            root.addEventListener('change', function (e) {
                const input = e.target.closest('.lr-coef');
                if (!input) { return; }

                const prev = input.dataset.prev || '';
                const next = input.value;

                if (next === prev) { return; }

                const what = input.dataset.type === 'group'
                    ? <?php echo wp_json_encode(__('Змінити коефіцієнт групи', 'leadrouter')); ?>
                    : <?php echo wp_json_encode(__('Змінити коефіцієнт партнера', 'leadrouter')); ?>;

                const question = what + ' «' + (input.dataset.label || '') + '» '
                    + <?php echo wp_json_encode(__('з', 'leadrouter')); ?> + ' ' + prev + ' '
                    + <?php echo wp_json_encode(__('на', 'leadrouter')); ?> + ' ' + next + '?\n\n'
                    + <?php echo wp_json_encode(__('Це одразу вплине на розподіл лідів.', 'leadrouter')); ?>;

                if (!window.confirm(question)) {
                    input.value = prev; // відмовились — повертаємо як було
                    markCustom(input);
                    return;
                }

                input.disabled = true;
                post('lr_balance_set_coef', {
                    type: input.dataset.type,
                    id: input.dataset.id,
                    value: next
                }).then(function (res) {
                    input.disabled = false;
                    const ok = !!(res && res.success);
                    input.style.outline = ok ? '2px solid #16a34a' : '2px solid #dc2626';
                    setTimeout(function () { input.style.outline = ''; }, 1200);

                    if (ok) {
                        // сервер міг обрізати значення (normalize_coef) — беремо його
                        if (res.data && res.data.value !== undefined) {
                            input.value = Number(res.data.value).toFixed(2);
                        }
                        input.dataset.prev = input.value;
                        markCustom(input);
                        refresh(true);
                    } else {
                        input.value = prev;
                        markCustom(input);
                    }
                }).catch(function () {
                    input.disabled = false;
                    input.value = prev;
                    markCustom(input);
                    input.style.outline = '2px solid #dc2626';
                });
            });

            setInterval(function () { refresh(); }, <?php echo (int)self::REFRESH * 1000; ?>);
        })();
        </script>
        <?php
    }
}
