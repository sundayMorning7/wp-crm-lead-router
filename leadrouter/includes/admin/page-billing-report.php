<?php
/**
 * LR_Billing_Report — read-only адмін-сторінка «Звіт» з агрегованим білінгом
 * за поточний місяць (рядки з 1-го числа до сьогодні в EST).
 *
 * Структура:
 *   - Date | Ad Spend | CPL | [Leads <Група> / Sales <Група>]* | Revenue | Fb leads
 *   - рядок «Разом» (суми по числових колонках; CPL = Σ ad_spend / Σ fb_leads).
 *
 * Джерело лідів/доходу — транзакції type='spend' (НЕ send_log):
 *   COUNT(DISTINCT lead_id) на рівні групи (дедуплікація лідів, що пішли кільком
 *   партнерам однієї групи) + SUM(-amount) як дохід.
 *
 * Група транзакції — t.group_id, зафіксована В МОМЕНТ списання (з 1.9.1);
 * переміщення партнера між групами не переписує історію. Старі рядки без
 * групи читаються через fallback на поточну мету партнера.
 *
 * Колонки-групи генеруються динамічно: лише групи, що мають відправлені ліди
 * (spend) за поточний місяць. Групи з 0 лідів і групу «для помилкових статусів»
 * (налаштування leadrouter_error_group_id) у звіт не виводимо.
 *
 * Денні FB-показники (Ad Spend / Fb leads) вводяться вручну і зберігаються в
 * {prefix}leadrouter_daily_adstats.
 *
 * Час — EST (America/New_York), як і решта білінгу. Префікс — лише $wpdb->prefix.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Billing_Report')) {

    class LR_Billing_Report
    {
        /** Slug сторінки в меню LeadRouter */
        const PAGE_SLUG = 'leadrouter-billing-report';

        /** Часова зона проекту */
        const TZ = 'America/New_York';

        /**
         * Реальний meta_key зв'язку партнер→група (Carbon Fields, з префіксом «_»).
         * Підтверджено запитом до БД: у postmeta існує лише цей варіант.
         */
        const GROUP_META_KEY = '_leadrouter_partner_group';

        /* ============================================================
         * Реєстрація: меню / ассети
         * ============================================================ */

        /** Викликається з LeadRouter_Admin::register_menus() (на admin_menu) */
        public static function register_menu()
        {
            add_submenu_page(
                'leadrouter',
                __('Billing Report', 'leadrouter'),
                __('Billing Report', 'leadrouter'),
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render']
            );
        }

        /** Викликається з LeadRouter_Admin::register_ajax() (на init) */
        public static function register()
        {
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
            // Upsert денних FB-показників (не AJAX — admin-post + redirect)
            add_action('admin_post_lr_billing_report_save_adstats', [__CLASS__, 'handle_save_adstats']);
            // CSV-експорт (не AJAX — стрімимо файл через admin-post)
            add_action('admin_post_lr_billing_report_export_csv', [__CLASS__, 'handle_export_csv']);
        }

        /** Інлайн-CSS лише на сторінці звіту */
        public static function enqueue_assets($hook)
        {
            if (empty($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
                return;
            }
            wp_register_style('lr-billing-report', false, [], LEADROUTER_VERSION);
            wp_enqueue_style('lr-billing-report');
            wp_add_inline_style('lr-billing-report', self::inline_css());
        }

        /* ============================================================
         * Хелпери часу (EST)
         * ============================================================ */

        private static function tz(): DateTimeZone
        {
            return new DateTimeZone(self::TZ);
        }

        /** Поточний місяць в EST у форматі YYYY-MM. */
        private static function current_ym(): string
        {
            return (new DateTimeImmutable('now', self::tz()))->format('Y-m');
        }

        /**
         * Санітизація/валідація значення місяця (YYYY-MM).
         * Невалідне/порожнє/майбутнє → поточний місяць (EST).
         */
        private static function sanitize_ym(string $raw): string
        {
            $current = self::current_ym();
            $raw = sanitize_text_field($raw);
            if (!preg_match('/^\d{4}-\d{2}$/', $raw)) {
                return $current;
            }
            $mm = (int)substr($raw, 5, 2);
            if ($mm < 1 || $mm > 12) {
                return $current; // некоректний номер місяця
            }
            // Порівняння рядків YYYY-MM лексикографічно коректне: не в майбутньому
            if ($raw > $current) {
                return $current;
            }
            return $raw;
        }

        /**
         * Обраний місяць із запиту (POST має пріоритет — для CSV-експорту через
         * admin-post; інакше GET — для рендера сторінки). Завжди валідований.
         */
        private static function requested_ym(): string
        {
            $raw = '';
            if (isset($_POST['ym'])) {
                $raw = (string)wp_unslash($_POST['ym']);
            } elseif (isset($_GET['ym'])) {
                $raw = (string)wp_unslash($_GET['ym']);
            }
            return self::sanitize_ym($raw);
        }

        /**
         * Діапазон звіту для обраного місяця $ym (YYYY-MM) в EST:
         *   - $monthStart = перше число обраного місяця 00:00;
         *   - $endDay     = якщо місяць == поточний → СЬОГОДНІ; інакше →
         *                   ОСТАННІЙ день обраного місяця (минулі місяці — повністю).
         *
         * @param string $ym вже валідований (див. sanitize_ym)
         * @return array{0:DateTimeImmutable,1:DateTimeImmutable}
         */
        private static function resolve_range(string $ym): array
        {
            $today      = new DateTimeImmutable('now', self::tz());
            $monthStart = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $ym . '-01 00:00:00',
                self::tz()
            );
            if ($monthStart === false) {
                // Страховка (не має статись — $ym провалідований): поточний місяць
                $monthStart = $today->modify('first day of this month');
                $ym         = $today->format('Y-m');
            }

            if ($ym === $today->format('Y-m')) {
                $endDay = $today; // поточний місяць — до сьогодні включно
            } else {
                $endDay = $monthStart->modify('last day of this month');
            }

            return [$monthStart, $endDay];
        }

        /**
         * Список доступних місяців (YYYY-MM) для селектора: від найранішого місяця,
         * де є spend-транзакція АБО рядок daily_adstats, до поточного місяця (EST),
         * у спадному порядку (найновіший зверху).
         *
         * @return array<int,string>
         */
        private static function available_months(): array
        {
            global $wpdb;
            $t_tx      = $wpdb->prefix . 'leadrouter_billing_transactions';
            $t_adstats = $wpdb->prefix . 'leadrouter_daily_adstats';

            $current = self::current_ym();

            // Найраніша дата з обох джерел (created_at і stat_date зберігаються в EST)
            $min_tx = $wpdb->get_var("SELECT MIN(DATE(created_at)) FROM {$t_tx} WHERE type = 'spend'");
            $min_ad = $wpdb->get_var("SELECT MIN(stat_date) FROM {$t_adstats}");

            $candidates = [];
            foreach ([$min_tx, $min_ad] as $d) {
                if (!empty($d)) {
                    $candidates[] = substr((string)$d, 0, 7); // YYYY-MM
                }
            }
            // Немає жодних даних → показуємо лише поточний місяць
            $earliest = $candidates ? min($candidates) : $current;
            if ($earliest > $current) {
                $earliest = $current;
            }

            $cur = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $earliest . '-01 00:00:00', self::tz());
            $end = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $current . '-01 00:00:00', self::tz());
            if ($cur === false || $end === false) {
                return [$current];
            }

            $months = [];
            while ($cur <= $end) {
                $months[] = $cur->format('Y-m');
                $cur = $cur->modify('+1 month');
            }

            return array_reverse($months); // спадання
        }

        /* ============================================================
         * Рендер сторінки (read-only таблиця)
         * ============================================================ */
        public static function render()
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Access denied', 'leadrouter'));
            }

            $ym = self::requested_ym();
            [$monthStart, $endDay] = self::resolve_range($ym);
            $data   = self::build_report_data($monthStart, $endDay);
            $months = self::available_months();

            // Дефолтна дата форми вводу — «сьогодні» (незалежно від переглянутого місяця)
            $today = new DateTimeImmutable('now', self::tz());

            echo '<div class="wrap lr-report-wrap">';
            echo '<h1>' . esc_html__('Billing Report', 'leadrouter') . '</h1>';

            self::render_notice();
            self::render_month_selector($ym, $months);

            echo '<p class="lr-report-period">'
                . esc_html(sprintf(
                    /* translators: 1: перша дата місяця, 2: остання дата діапазону */
                    __('Period: %1$s — %2$s (EST)', 'leadrouter'),
                    $monthStart->format('Y-m-d'),
                    $endDay->format('Y-m-d')
                )) . '</p>';

            self::render_form($today, $ym);
            self::render_table($data);
            self::render_export_button($ym);

            echo '</div>';
        }

        /** Admin-notice про результат збереження денних показників. */
        private static function render_notice()
        {
            if (!empty($_GET['lr_adstats_saved'])) {
                $d = isset($_GET['lr_adstats_date']) ? sanitize_text_field(wp_unslash($_GET['lr_adstats_date'])) : '';
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . esc_html(sprintf(
                        /* translators: %s — дата */
                        __('Daily stats saved for %s.', 'leadrouter'),
                        $d
                    )) . '</p></div>';
            } elseif (!empty($_GET['lr_adstats_error'])) {
                echo '<div class="notice notice-error is-dismissible"><p>'
                    . esc_html__('Could not save daily stats: invalid date.', 'leadrouter')
                    . '</p></div>';
            }
        }

        /**
         * Селектор місяців у шапці: <select> зі списком доступних місяців (спадання),
         * onchange → перезавантаження сторінки з ?ym=... (form method=get + noscript-fallback).
         *
         * @param string            $selected обраний місяць YYYY-MM
         * @param array<int,string> $months   доступні місяці (спадання)
         */
        private static function render_month_selector(string $selected, array $months)
        {
            echo '<form method="get" class="lr-report-monthbar">';
            echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
            echo '<label>' . esc_html__('Month', 'leadrouter') . ' ';
            echo '<select name="ym" onchange="this.form.submit()">';
            foreach ($months as $m) {
                echo '<option value="' . esc_attr($m) . '"' . selected($m, $selected, false) . '>'
                    . esc_html($m) . '</option>';
            }
            echo '</select></label>';
            // Без JS — кнопка сабміту
            echo '<noscript><button class="button">' . esc_html__('Show', 'leadrouter') . '</button></noscript>';
            echo '</form>';
        }

        /**
         * Форма вводу денних FB-показників (єдина редагована частина сторінки).
         * Сабміт → admin-post.php (action lr_billing_report_save_adstats) + nonce.
         * Upsert іде по обраній у формі даті незалежно від переглянутого місяця;
         * $ym передаємо лише щоб після збереження повернутись на той самий місяць.
         */
        private static function render_form(DateTimeImmutable $today, string $ym)
        {
            $today_str = $today->format('Y-m-d');

            echo '<div class="lr-report-form-card">';
            echo '<h2>' . esc_html__('Daily FB stats', 'leadrouter') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="lr-report-form">';
            echo '<input type="hidden" name="action" value="lr_billing_report_save_adstats">';
            echo '<input type="hidden" name="ym" value="' . esc_attr($ym) . '">';
            wp_nonce_field('lr_billing_report_adstats');

            echo '<label>' . esc_html__('Date', 'leadrouter')
                . ' <input type="date" name="stat_date" value="' . esc_attr($today_str) . '" required></label>';
            echo '<label>' . esc_html__('Ad Spend', 'leadrouter')
                . ' <input type="number" name="ad_spend" step="0.01" min="0" value="0" required></label>';
            echo '<label>' . esc_html__('Fb leads', 'leadrouter')
                . ' <input type="number" name="fb_leads" step="1" min="0" value="0" required></label>';
            echo '<button class="button button-primary">' . esc_html__('Save', 'leadrouter') . '</button>';

            echo '</form>';
            echo '<p class="description">'
                . esc_html__('Re-saving the same date updates its values (one row per day).', 'leadrouter')
                . '</p>';
            echo '</div>';
        }

        /**
         * Upsert денних показників у {prefix}leadrouter_daily_adstats.
         * INSERT ... ON DUPLICATE KEY UPDATE по stat_date; updated_at = now(EST).
         */
        public static function handle_save_adstats()
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Access denied', 'leadrouter'));
            }
            check_admin_referer('lr_billing_report_adstats');

            global $wpdb;
            $t_adstats = $wpdb->prefix . 'leadrouter_daily_adstats';

            // Обраний місяць — лише щоб повернутись на нього після збереження
            $ym = self::requested_ym();

            // Валідація дати (Y-m-d). Невалідна → redirect з прапорцем помилки.
            $stat_date = isset($_POST['stat_date']) ? sanitize_text_field(wp_unslash($_POST['stat_date'])) : '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $stat_date)) {
                wp_safe_redirect(add_query_arg(
                    ['page' => self::PAGE_SLUG, 'ym' => $ym, 'lr_adstats_error' => '1'],
                    admin_url('admin.php')
                ));
                exit;
            }

            $ad_spend = isset($_POST['ad_spend']) ? (float)$_POST['ad_spend'] : 0.0;
            if ($ad_spend < 0) {
                $ad_spend = 0.0;
            }
            $fb_leads = isset($_POST['fb_leads']) ? absint($_POST['fb_leads']) : 0;

            $now_est = (new DateTimeImmutable('now', self::tz()))->format('Y-m-d H:i:s');

            // Upsert: один рядок на день (PRIMARY KEY stat_date)
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$t_adstats} (stat_date, ad_spend, fb_leads, updated_at)
                          VALUES (%s, %f, %d, %s)
                     ON DUPLICATE KEY UPDATE
                          ad_spend = VALUES(ad_spend),
                          fb_leads = VALUES(fb_leads),
                          updated_at = VALUES(updated_at)",
                    $stat_date,
                    $ad_spend,
                    $fb_leads,
                    $now_est
                )
            );

            wp_safe_redirect(add_query_arg(
                ['page' => self::PAGE_SLUG, 'ym' => $ym, 'lr_adstats_saved' => '1', 'lr_adstats_date' => $stat_date],
                admin_url('admin.php')
            ));
            exit;
        }

        /** Read-only таблиця: динамічні колонки по групах + рядок «Разом». */
        private static function render_table(array $data)
        {
            $groups = $data['groups'];
            $rows   = $data['rows'];
            $totals = $data['totals'];

            // Обгортка зі скролом: при великій к-сті колонок зʼявляється горизонтальна прокрутка
            echo '<div class="lr-report-table-wrap">';
            echo '<table class="widefat striped lr-report-table"><thead><tr>';
            // Заголовки — зі спільного методу (той самий набір, що в CSV → збіг 1:1)
            foreach (self::column_headers($groups) as $h) {
                echo '<th>' . esc_html($h) . '</th>';
            }
            echo '</tr></thead><tbody>';

            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row['date']) . '</td>';
                echo '<td>' . esc_html(number_format((float)$row['ad_spend'], 2)) . '</td>';
                echo '<td>' . esc_html((string)(int)$row['fb_leads']) . '</td>';
                echo '<td>' . esc_html(number_format((float)$row['cpl'], 2)) . '</td>';
                foreach ($groups as $gid => $title) {
                    $g = $row['groups'][$gid] ?? ['leads' => 0, 'sales' => 0.0];
                    echo '<td>' . esc_html((string)(int)$g['leads']) . '</td>';
                    echo '<td>' . esc_html(number_format((float)$g['sales'], 2)) . '</td>';
                }
                echo '<td class="' . esc_attr(self::neg_class((float)$row['revenue'])) . '">'
                    . esc_html(number_format((float)$row['revenue'], 2)) . '</td>';
                echo '</tr>';
            }
            echo '</tbody>';

            // Підсумок «Разом»
            echo '<tfoot><tr class="lr-report-total">';
            echo '<th>' . esc_html__('Total', 'leadrouter') . '</th>';
            echo '<th>' . esc_html(number_format((float)$totals['ad_spend'], 2)) . '</th>';
            echo '<th>' . esc_html((string)(int)$totals['fb_leads']) . '</th>';
            echo '<th>' . esc_html(number_format((float)$totals['cpl'], 2)) . '</th>';
            foreach ($groups as $gid => $title) {
                $g = $totals['groups'][$gid] ?? ['leads' => 0, 'sales' => 0.0];
                echo '<th>' . esc_html((string)(int)$g['leads']) . '</th>';
                echo '<th>' . esc_html(number_format((float)$g['sales'], 2)) . '</th>';
            }
            echo '<th class="' . esc_attr(self::neg_class((float)$totals['revenue'])) . '">'
                . esc_html(number_format((float)$totals['revenue'], 2)) . '</th>';
            echo '</tr></tfoot>';

            echo '</table>';
            echo '</div>';
        }

        /** CSS-клас для від'ємного Revenue (підсвітка червоним). */
        private static function neg_class(float $val): string
        {
            return $val < 0 ? 'lr-neg' : '';
        }

        /**
         * Спільний набір заголовків колонок (для HTML-таблиці і CSV).
         * Динамічні колонки груп — у тому самому порядку, що й дані.
         *
         * @param array<int,string> $groups gid => назва
         * @return array<int,string>
         */
        private static function column_headers(array $groups): array
        {
            $headers = [
                __('Date', 'leadrouter'),
                __('Ad Spend ($)', 'leadrouter'),
                __('Fb leads', 'leadrouter'),
                __('CPL', 'leadrouter'),
            ];
            foreach ($groups as $gid => $title) {
                /* translators: %s — назва групи */
                $headers[] = sprintf(__('Leads %s', 'leadrouter'), $title);
                /* translators: %s — назва групи */
                $headers[] = sprintf(__('Sales %s', 'leadrouter'), $title);
            }
            $headers[] = __('Revenue', 'leadrouter');
            return $headers;
        }

        /** Кнопка експорту CSV (POST → admin-post.php з nonce). */
        private static function render_export_button(string $ym)
        {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="lr-report-export">';
            echo '<input type="hidden" name="action" value="lr_billing_report_export_csv">';
            echo '<input type="hidden" name="ym" value="' . esc_attr($ym) . '">';
            wp_nonce_field('lr_billing_report_export');
            echo '<button class="button button-primary">' . esc_html__('Export CSV', 'leadrouter') . '</button>';
            echo '</form>';
        }

        /**
         * Стрімимо CSV за той самий період і ту саму логіку, що й HTML-таблиця
         * (спільний build_report_data() + column_headers() → збіг 1:1), і виходимо.
         */
        public static function handle_export_csv()
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Access denied', 'leadrouter'));
            }
            check_admin_referer('lr_billing_report_export');

            $ym = self::requested_ym();
            [$monthStart, $endDay] = self::resolve_range($ym);
            $data   = self::build_report_data($monthStart, $endDay);
            $groups = $data['groups'];

            $filename = 'billing-report-' . $ym . '.csv';

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            // BOM для коректної кирилиці в Excel
            fwrite($out, "\xEF\xBB\xBF");

            // Заголовки — динамічні під ті самі групи (ідентичні HTML)
            fputcsv($out, self::column_headers($groups));

            // Рядки днів — числа без роздільника тисяч (parseable), значення = HTML
            foreach ($data['rows'] as $row) {
                $line = [
                    $row['date'],
                    number_format((float)$row['ad_spend'], 2, '.', ''),
                    (int)$row['fb_leads'],
                    number_format((float)$row['cpl'], 2, '.', ''),
                ];
                foreach ($groups as $gid => $title) {
                    $g = $row['groups'][$gid] ?? ['leads' => 0, 'sales' => 0.0];
                    $line[] = (int)$g['leads'];
                    $line[] = number_format((float)$g['sales'], 2, '.', '');
                }
                $line[] = number_format((float)$row['revenue'], 2, '.', '');
                fputcsv($out, $line);
            }

            // Рядок «Разом» (як у HTML-tfoot)
            $t    = $data['totals'];
            $line = [
                __('Total', 'leadrouter'),
                number_format((float)$t['ad_spend'], 2, '.', ''),
                (int)$t['fb_leads'],
                number_format((float)$t['cpl'], 2, '.', ''),
            ];
            foreach ($groups as $gid => $title) {
                $g = $t['groups'][$gid] ?? ['leads' => 0, 'sales' => 0.0];
                $line[] = (int)$g['leads'];
                $line[] = number_format((float)$g['sales'], 2, '.', '');
            }
            $line[] = number_format((float)$t['revenue'], 2, '.', '');
            fputcsv($out, $line);

            fclose($out);
            exit;
        }

        /** Інлайн-CSS у стилі дашборда білінгу. */
        private static function inline_css(): string
        {
            return '
            .lr-report-wrap .lr-report-monthbar { margin:12px 0 4px; }
            .lr-report-wrap .lr-report-monthbar label { font-weight:600; }
            .lr-report-wrap .lr-report-monthbar select { margin-left:6px; }
            .lr-report-wrap .lr-report-period { color:#646970; margin:8px 0 16px; }
            /* Скрол-контейнер: таблиця не розтягується на весь екран, при потребі — горизонтальна прокрутка */
            .lr-report-wrap .lr-report-table-wrap { margin-top:6px; max-width:100%; overflow-x:auto; }
            /* Ширина по контенту, а не 100% (перебиваємо .widefat) */
            .lr-report-wrap .lr-report-table { width:auto; min-width:0; margin:0; }
            .lr-report-wrap .lr-report-table th,
            .lr-report-wrap .lr-report-table td { white-space:nowrap; text-align:right; padding:4px 10px; font-size:12px; }
            .lr-report-wrap .lr-report-table th:first-child,
            .lr-report-wrap .lr-report-table td:first-child { text-align:left; }
            .lr-report-wrap .lr-report-table thead th { text-align:right; }
            .lr-report-wrap .lr-report-table thead th:first-child { text-align:left; }
            .lr-report-wrap .lr-report-total th { font-weight:700; background:#f6f7f7; border-top:2px solid #c3c4c7; }
            .lr-report-wrap .lr-neg { color:#d63638; font-weight:600; }
            .lr-report-wrap .lr-report-form-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px 18px; margin:0 0 16px; }
            .lr-report-wrap .lr-report-form-card h2 { margin-top:6px; }
            .lr-report-wrap .lr-report-form { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; }
            .lr-report-wrap .lr-report-form label { display:flex; flex-direction:column; gap:4px; font-weight:600; }
            .lr-report-wrap .lr-report-export { margin-top:16px; }
            ';
        }

        /* ============================================================
         * Модель даних — спільна збірка для HTML і CSV
         * ============================================================ */

        /**
         * Зібрати дані звіту за діапазон [перше число місяця; кінцевий день] (EST).
         * Кінцевий день = сьогодні для поточного місяця або останній день місяця
         * для минулих (див. resolve_range()).
         *
         * @param DateTimeImmutable $monthStart перше число місяця (EST)
         * @param DateTimeImmutable $today      останній день діапазону (EST)
         * @return array{
         *   groups: array<int,string>,                       // gid => назва (сортовано за назвою)
         *   rows: array<int,array>,                           // по одному рядку на день
         *   totals: array                                     // підсумки «Разом»
         * }
         */
        public static function build_report_data(DateTimeImmutable $monthStart, DateTimeImmutable $today): array
        {
            global $wpdb;

            $t_tx      = $wpdb->prefix . 'leadrouter_billing_transactions';
            $t_adstats = $wpdb->prefix . 'leadrouter_daily_adstats';

            // Межі діапазону в EST: [перше число 00:00:00 ; завтра 00:00:00)
            $range_start = $monthStart->setTime(0, 0, 0);
            $range_end   = $today->setTime(0, 0, 0)->modify('+1 day');

            $start_sql = $range_start->format('Y-m-d H:i:s');
            $end_sql   = $range_end->format('Y-m-d H:i:s');

            $start_date = $range_start->format('Y-m-d');
            $end_date   = $today->format('Y-m-d');

            // 1) Основна агрегація: ліди/дохід по днях і групах (type='spend').
            //    Група — насамперед t.group_id, ЗАФІКСОВАНА в момент списання
            //    (міграція 1.9.1): переміщення партнера між групами більше не
            //    переписує історію заднім числом. Для рядків без групи (старі
            //    транзакції, які не вдалося backfill-нути) — fallback на поточну
            //    мету партнера, як було раніше.
            //    COUNT(DISTINCT lead_id) на рівні групи коректно дедуплікує лід,
            //    що пішов кільком партнерам однієї групи.
            $agg_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DATE(t.created_at)        AS d,
                            COALESCE(NULLIF(t.group_id, 0), pm.meta_value, 0) AS group_id,
                            COUNT(DISTINCT t.lead_id) AS leads,
                            SUM(-t.amount)            AS sales
                       FROM {$t_tx} t
                       LEFT JOIN {$wpdb->postmeta} pm
                         ON pm.post_id = t.partner_id
                        AND pm.meta_key = %s
                      WHERE t.type = 'spend'
                        AND t.created_at >= %s
                        AND t.created_at <  %s
                      GROUP BY d, group_id",
                    self::GROUP_META_KEY,
                    $start_sql,
                    $end_sql
                ),
                ARRAY_A
            );

            // Індексуємо агрегати: [date][group_id] => [leads, sales]
            $agg     = [];
            $tx_gids = [];
            foreach ((array)$agg_rows as $r) {
                $d   = (string)$r['d'];
                $gid = (int)$r['group_id'];
                $tx_gids[$gid] = true;
                $agg[$d][$gid] = [
                    'leads' => (int)$r['leads'],
                    'sales' => (float)$r['sales'],
                ];
            }

            // 2) Список груп-колонок: лише групи з лідами за місяць (мінус група помилок)
            $groups = self::build_group_list(array_keys($tx_gids));

            // 3) Денні FB-показники за той самий діапазон (окремий запит, мердж по даті)
            $ad_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT stat_date, ad_spend, fb_leads
                       FROM {$t_adstats}
                      WHERE stat_date >= %s AND stat_date <= %s",
                    $start_date,
                    $end_date
                ),
                ARRAY_A
            );
            $adstats = [];
            foreach ((array)$ad_rows as $r) {
                $adstats[(string)$r['stat_date']] = [
                    'ad_spend' => (float)$r['ad_spend'],
                    'fb_leads' => (int)$r['fb_leads'],
                ];
            }

            // 4) Збірка рядків: кожен день від 1-го до сьогодні (EST). Дні без даних → нулі.
            $rows = [];
            $tot  = [
                'ad_spend'  => 0.0,
                'fb_leads'  => 0,
                'sales_sum' => 0.0,
                'groups'    => [], // gid => [leads, sales]
            ];
            foreach (array_keys($groups) as $gid) {
                $tot['groups'][$gid] = ['leads' => 0, 'sales' => 0.0];
            }

            $cursor = $range_start;
            while ($cursor < $range_end) {
                $d = $cursor->format('Y-m-d');

                $ad_spend = $adstats[$d]['ad_spend'] ?? 0.0;
                $fb_leads = $adstats[$d]['fb_leads'] ?? 0;
                $cpl      = $fb_leads > 0 ? round($ad_spend / $fb_leads, 2) : 0.0;

                $row_groups = [];
                $sales_sum  = 0.0;
                foreach (array_keys($groups) as $gid) {
                    $leads = $agg[$d][$gid]['leads'] ?? 0;
                    $sales = $agg[$d][$gid]['sales'] ?? 0.0;
                    $row_groups[$gid] = ['leads' => $leads, 'sales' => $sales];
                    $sales_sum += $sales;

                    $tot['groups'][$gid]['leads'] += $leads;
                    $tot['groups'][$gid]['sales'] += $sales;
                }

                // Revenue = (Σ Sales по всіх групах) − Ad Spend
                $revenue = round($sales_sum - $ad_spend, 2);

                $rows[] = [
                    'date'     => $d,
                    'ad_spend' => round($ad_spend, 2),
                    'cpl'      => $cpl,
                    'groups'   => $row_groups,
                    'revenue'  => $revenue,
                    'fb_leads' => $fb_leads,
                ];

                $tot['ad_spend']  += $ad_spend;
                $tot['fb_leads']  += $fb_leads;
                $tot['sales_sum'] += $sales_sum;

                $cursor = $cursor->modify('+1 day');
            }

            // 5) Підсумок «Разом» (суми по числових колонках; CPL/Revenue із захистом).
            $tot_cpl     = $tot['fb_leads'] > 0 ? round($tot['ad_spend'] / $tot['fb_leads'], 2) : 0.0;
            $tot_revenue = round($tot['sales_sum'] - $tot['ad_spend'], 2);

            $totals = [
                'ad_spend' => round($tot['ad_spend'], 2),
                'cpl'      => $tot_cpl,
                'groups'   => $tot['groups'],
                'revenue'  => $tot_revenue,
                'fb_leads' => $tot['fb_leads'],
            ];

            return [
                'groups' => $groups,
                'rows'   => $rows,
                'totals' => $totals,
            ];
        }

        /**
         * Список груп-колонок: лише групи, що мають відправлені ліди (spend) за
         * поточний місяць. Групи з 0 лідів не виводимо. Групу «для помилкових
         * статусів» (налаштування leadrouter_error_group_id) виключаємо завжди.
         *
         * Назву беремо через get_the_title() (працює і для не-publish постів) з
         * фолбеком «#<id>», тож історія груп, вимкнених усередині місяця, не губиться.
         *
         * @param array<int,int> $tx_group_ids group_id, що зустрілись у транзакціях
         * @return array<int,string> gid => назва (сортовано за назвою)
         */
        private static function build_group_list(array $tx_group_ids): array
        {
            $error_gid = self::error_group_id();

            $list = []; // gid => title
            foreach ($tx_group_ids as $gid) {
                $gid = (int)$gid;
                if ($gid <= 0) {
                    continue; // партнер без коректної групи — не колонка
                }
                if ($error_gid > 0 && $gid === $error_gid) {
                    continue; // група помилкових статусів — у звіт не виводимо
                }
                $title = get_the_title($gid);
                $list[$gid] = ($title !== '') ? $title : ('#' . $gid);
            }

            // Сортування за назвою (натуральне, нечутливе до регістру)
            uasort($list, static function ($a, $b) {
                return strnatcasecmp((string)$a, (string)$b);
            });

            return $list;
        }

        /**
         * ID групи «для помилкових статусів» з налаштувань плагіна
         * (Carbon theme-option leadrouter_error_group_id, у БД — з префіксом «_»).
         */
        private static function error_group_id(): int
        {
            if (function_exists('carbon_get_theme_option')) {
                $v = carbon_get_theme_option('leadrouter_error_group_id');
                if ($v !== null && $v !== '') {
                    return (int)$v;
                }
            }
            return (int)get_option('_leadrouter_error_group_id', 0);
        }
    }
}
