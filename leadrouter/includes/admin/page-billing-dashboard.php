<?php
/**
 * LR_Billing_Dashboard — загальний дашборд білінгу (LeadRouter → Billing).
 *
 * Складається з 4 секцій:
 *   1) Overview cards — 4 картки: загальний баланс, активні, на паузі, spend за місяць.
 *   2) Partners balances — таблиця балансів усіх партнерів (сорт за балансом).
 *   3) Tabs (vanilla JS) — Recent Transactions / Audit Log / Errors (N).
 *   4) Quick actions — експорт транзакцій у CSV за період.
 *
 * Деталі:
 *   - Resolve помилки використовує вже наявний AJAX-ендпоінт lr_resolve_billing_error
 *     (зареєстрований LR_Partner_Billing_Page) з тим самим nonce 'lr_billing_ajax'.
 *   - Перехід «на партнера» веде на екран редагування партнера, де живе вкладка «Білінг».
 *   - CSV-експорт — через admin-post.php (стрімимо файл і виходимо).
 *
 * Час — EST (America/New_York), як і решта білінгу.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Billing_Dashboard')) {

    class LR_Billing_Dashboard
    {
        /** Slug сторінки в меню LeadRouter */
        const PAGE_SLUG = 'leadrouter-billing';

        /** Спільний nonce білінгу (той самий, що в LR_Partner_Billing_Page) */
        const NONCE = 'lr_billing_ajax';

        /** Рядків на сторінку в таблицях вкладок */
        const PER_PAGE = 25;

        /* ============================================================
         * Реєстрація: меню / AJAX+ассети
         * ============================================================ */

        /** Викликається з LeadRouter_Admin::register_menus() (на admin_menu) */
        public static function register_menu()
        {
            add_submenu_page(
                'leadrouter',
                __('Billing', 'leadrouter'),
                __('Billing', 'leadrouter'),
                'manage_options',
                self::PAGE_SLUG,
                [__CLASS__, 'render']
            );
        }

        /** Викликається з LeadRouter_Admin::register_ajax() (на init) */
        public static function register()
        {
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
            // CSV-експорт (не AJAX — стрімимо файл через admin-post)
            add_action('admin_post_lr_billing_export_csv', [__CLASS__, 'handle_export_csv']);
        }

        /** JS + CSS лише на сторінці дашборда */
        public static function enqueue_assets($hook)
        {
            if (empty($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
                return;
            }

            wp_register_style('lr-billing-dashboard', false, [], LEADROUTER_VERSION);
            wp_enqueue_style('lr-billing-dashboard');
            wp_add_inline_style('lr-billing-dashboard', self::inline_css());

            wp_register_script('lr-billing-dashboard', '', [], LEADROUTER_VERSION, true);
            wp_enqueue_script('lr-billing-dashboard');
            wp_localize_script('lr-billing-dashboard', 'LRBD', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE),
            ]);
            wp_add_inline_script('lr-billing-dashboard', self::inline_js());
        }

        /* ============================================================
         * Головний рендер
         * ============================================================ */
        public static function render()
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Access denied', 'leadrouter'));
            }

            echo '<div class="wrap lr-billing-wrap"><h1>' . esc_html__('Billing', 'leadrouter') . '</h1>';

            self::render_overview_cards();
            self::render_partners_table();
            self::render_tabs();
            self::render_quick_actions();

            echo '</div>';
        }

        /* ============================================================
         * СЕКЦІЯ 1 — Overview cards
         * ============================================================ */
        private static function render_overview_cards()
        {
            global $wpdb;
            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';
            $t_tx      = $wpdb->prefix . 'leadrouter_billing_transactions';

            $total_balance = (float)$wpdb->get_var("SELECT COALESCE(SUM(balance),0) FROM {$t_billing}");

            // Активні/вимкнені рахуємо за РЕАЛЬНОЮ активацією партнера
            // (_leadrouter_partner_active + publish), а не лише за прапором білінгу:
            // інакше admin-вимкнені (deactivated_by_billing=0, meta='0') хибно йшли в «активні».
            $status_rows = $wpdb->get_col("SELECT partner_id FROM {$t_billing}");
            $active_cnt = 0;
            $deactivated_cnt = 0;
            foreach ((array)$status_rows as $sp_id) {
                if (self::partner_is_active((int)$sp_id)) {
                    $active_cnt++;
                } else {
                    $deactivated_cnt++;
                }
            }

            // Spend за поточний місяць (EST). amount у 'spend' від'ємний — беремо abs.
            $month_start = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('America/New_York')))
                ->format('Y-m-d H:i:s');
            $month_spend = (float)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COALESCE(SUM(amount),0) FROM {$t_tx} WHERE type = 'spend' AND created_at >= %s",
                    $month_start
                )
            );
            $month_spend = abs($month_spend);

            echo '<div class="lr-cards">';
            echo self::card(__('Total balance', 'leadrouter'), number_format($total_balance, 2) . ' USD', 'lr-card--blue');
            echo self::card(__('Active partners', 'leadrouter'), (string)$active_cnt, 'lr-card--green');
            echo self::card(__('Deactivated partners', 'leadrouter'), (string)$deactivated_cnt, 'lr-card--red');
            echo self::card(__('Spend this month', 'leadrouter'), number_format($month_spend, 2) . ' USD', 'lr-card--orange');
            echo '</div>';
        }

        private static function card(string $label, string $value, string $cls): string
        {
            return '<div class="lr-card ' . esc_attr($cls) . '">'
                . '<span class="lr-card-val">' . esc_html($value) . '</span>'
                . '<span class="lr-card-label">' . esc_html($label) . '</span></div>';
        }

        /* ============================================================
         * СЕКЦІЯ 2 — Partners balances
         * ============================================================ */
        private static function render_partners_table()
        {
            global $wpdb;
            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';
            $t_tx      = $wpdb->prefix . 'leadrouter_billing_transactions';

            // Сортування таблиці партнерів. За замовчуванням — за СТАТУСОМ:
            // ввімкнені на початку, вимкнені в кінці. Доступне й сортування за балансом.
            $psort = (isset($_GET['psort']) && $_GET['psort'] === 'balance') ? 'balance' : 'status';
            $pdir  = (isset($_GET['pdir']) && strtolower((string)$_GET['pdir']) === 'desc') ? 'desc' : 'asc';

            $rows = $wpdb->get_results(
                "SELECT partner_id, balance, min_balance, lead_price, lead_price_saturday, lead_price_sunday, currency, deactivated_by_billing
                   FROM {$t_billing}",
                ARRAY_A
            );

            // Дата останньої транзакції по кожному партнеру (одним запитом)
            $last_tx = [];
            $last_rows = $wpdb->get_results(
                "SELECT partner_id, MAX(created_at) AS last_at FROM {$t_tx} GROUP BY partner_id",
                ARRAY_A
            );
            foreach ((array)$last_rows as $lr) {
                $last_tx[(int)$lr['partner_id']] = (string)$lr['last_at'];
            }

            // Збагачуємо рядки РЕАЛЬНИМ статусом активації (_leadrouter_partner_active),
            // а не лише прапором білінгу — щоб admin-вимкнені теж відображались коректно.
            foreach ($rows as &$row_ref) {
                $pid_ref = (int)$row_ref['partner_id'];
                $row_ref['_active'] = self::partner_is_active($pid_ref);
                $row_ref['_rank']   = self::status_rank(
                    (float)$row_ref['balance'],
                    (float)$row_ref['min_balance'],
                    (int)$row_ref['deactivated_by_billing'] === 1,
                    $row_ref['_active']
                );
            }
            unset($row_ref);

            // Сортуємо у PHP (таблиця без пагінації — беремо всіх партнерів)
            usort($rows, static function ($a, $b) use ($psort, $pdir) {
                if ($psort === 'balance') {
                    $cmp = ((float)$a['balance']) <=> ((float)$b['balance']);
                } else {
                    // за рангом статусу; рівні — добиваємо за балансом (зростання)
                    $cmp = ((int)$a['_rank']) <=> ((int)$b['_rank']);
                    if ($cmp === 0) {
                        $cmp = ((float)$a['balance']) <=> ((float)$b['balance']);
                    }
                }
                return $pdir === 'desc' ? -$cmp : $cmp;
            });

            echo '<div class="lr-bil-card"><h2>' . esc_html__('Partners balances', 'leadrouter') . '</h2>';
            echo '<table class="widefat striped lr-bil-table"><thead><tr>';
            echo '<th>' . esc_html__('Partner name', 'leadrouter') . '</th>';
            echo '<th>' . self::sort_header(__('Balance', 'leadrouter'), 'balance', $psort, $pdir) . '</th>';
            echo '<th>' . esc_html__('Min threshold', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Lead price', 'leadrouter') . '</th>';
            echo '<th>' . self::sort_header(__('Status', 'leadrouter'), 'status', $psort, $pdir) . '</th>';
            echo '<th>' . esc_html__('Last transaction', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Actions', 'leadrouter') . '</th>';
            echo '</tr></thead><tbody>';

            if (empty($rows)) {
                echo '<tr><td colspan="7">' . esc_html__('No partners with billing', 'leadrouter') . '</td></tr>';
            } else {
                foreach ($rows as $r) {
                    $pid      = (int)$r['partner_id'];
                    $balance  = (float)$r['balance'];
                    $min      = (float)$r['min_balance'];
                    $currency = (string)$r['currency'];
                    $deactivated = (int)$r['deactivated_by_billing'] === 1;
                    $active      = !empty($r['_active']);

                    $bal_cls = self::balance_color_class($balance, $min);
                    $edit_url = self::partner_url($pid);

                    echo '<tr>';
                    echo '<td>' . self::partner_link($pid) . '</td>';
                    echo '<td><strong class="' . esc_attr($bal_cls) . '">'
                        . esc_html(number_format($balance, 2) . ' ' . $currency) . '</strong></td>';
                    echo '<td>' . esc_html(number_format($min, 2)) . '</td>';
                    echo '<td title="' . esc_attr__('Mon–Fri / Saturday / Sunday', 'leadrouter') . '">'
                        . esc_html(self::lead_price_display($r)) . '</td>';
                    echo '<td>' . self::status_badge($balance, $min, $deactivated, $active) . '</td>';
                    echo '<td>' . (isset($last_tx[$pid]) ? esc_html($last_tx[$pid]) : '—') . '</td>';
                    echo '<td>' . ($edit_url
                            ? '<a class="button button-small" href="' . esc_url($edit_url) . '">'
                                . esc_html__('Billing →', 'leadrouter') . '</a>'
                            : '—') . '</td>';
                    echo '</tr>';
                }
            }

            echo '</tbody></table></div>';
        }

        /**
         * Відображення трьох цін ліда: "$10.00 / $15.00 / $20.00".
         * Друга/третя позиція = "=", якщо ціна вихідного NULL або дорівнює weekday-ціні.
         * Приклад (усі однакові): "$10.00 / = / =".
         */
        private static function lead_price_display(array $r): string
        {
            $weekday = (float)$r['lead_price'];

            $fmt_weekend = static function ($val) use ($weekday) {
                // NULL = не задано → "=" (fallback на weekday)
                if ($val === null) {
                    return '=';
                }
                $val = (float)$val;
                // Збігається з weekday → теж "="
                if (abs($val - $weekday) < 0.005) {
                    return '=';
                }
                return '$' . number_format($val, 2);
            };

            return '$' . number_format($weekday, 2)
                . ' / ' . $fmt_weekend($r['lead_price_saturday'] ?? null)
                . ' / ' . $fmt_weekend($r['lead_price_sunday'] ?? null);
        }

        /** Колір балансу: зелений / жовтий (близько) / червоний (нижче порогу) */
        private static function balance_color_class(float $balance, float $min): string
        {
            if ($balance < $min) {
                return 'lr-bal--red';
            }
            if ($min > 0 && $balance < $min * 1.5) {
                return 'lr-bal--yellow';
            }
            return 'lr-bal--green';
        }

        /**
         * Бейдж статусу — за РЕАЛЬНОЮ активацією партнера ($active = publish + meta),
         * з розрізненням причини вимкнення (білінг vs адмін).
         */
        private static function status_badge(float $balance, float $min, bool $deactivated_billing, bool $active): string
        {
            if (!$active) {
                if ($deactivated_billing) {
                    return '<span class="lr-badge lr-badge--deactivated">🔴 ' . esc_html__('Deactivated (billing)', 'leadrouter') . '</span>';
                }
                return '<span class="lr-badge lr-badge--disabled">⛔ ' . esc_html__('Disabled', 'leadrouter') . '</span>';
            }
            if ($balance < $min) {
                return '<span class="lr-badge lr-badge--low">⚠️ ' . esc_html__('Low balance', 'leadrouter') . '</span>';
            }
            return '<span class="lr-badge lr-badge--active">✅ ' . esc_html__('Active', 'leadrouter') . '</span>';
        }

        /**
         * Ранг статусу для сортування (зростання = ввімкнені спершу, вимкнені в кінці):
         *   0 — активний, баланс ок;  1 — активний, низький баланс;  2 — вимкнений.
         */
        private static function status_rank(float $balance, float $min, bool $deactivated_billing, bool $active): int
        {
            if (!$active) {
                return 2;
            }
            if ($balance < $min) {
                return 1;
            }
            return 0;
        }

        /**
         * Реальна активація партнера — та сама логіка, що в LeadRouter_Partners::is_active:
         * publish + (_leadrouter_partner_active порожнє/null/'1'). Явне '0' = вимкнено.
         */
        private static function partner_is_active(int $pid): bool
        {
            if (get_post_status($pid) !== 'publish') {
                return false;
            }
            $active = get_post_meta($pid, '_leadrouter_partner_active', true);
            return ($active === '' || $active === null) ? true : ($active == '1');
        }

        /** Сортувальний заголовок колонки (керує psort/pdir у GET) */
        private static function sort_header(string $label, string $col, string $psort, string $pdir): string
        {
            $is_current = ($psort === $col);
            $next_dir   = ($is_current && $pdir === 'asc') ? 'desc' : 'asc';
            $arrow      = $is_current ? ($pdir === 'asc' ? ' ▲' : ' ▼') : '';
            $url = add_query_arg(
                ['page' => self::PAGE_SLUG, 'psort' => $col, 'pdir' => $next_dir],
                admin_url('admin.php')
            );
            return '<a href="' . esc_url($url) . '">' . esc_html($label) . $arrow . '</a>';
        }

        /* ============================================================
         * СЕКЦІЯ 3 — Tabs
         * ============================================================ */
        private static function render_tabs()
        {
            global $wpdb;
            $t_err = $wpdb->prefix . 'leadrouter_billing_errors';
            $err_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t_err} WHERE is_resolved = 0");

            echo '<div class="lr-bil-card lr-tabs-card">';

            // Кнопки вкладок
            echo '<div class="lr-tabs-nav">';
            echo '<button type="button" class="button lr-tab-btn" data-tab="transactions">'
                . esc_html__('Recent Transactions', 'leadrouter') . '</button>';
            echo '<button type="button" class="button lr-tab-btn" data-tab="audit">'
                . esc_html__('Audit Log', 'leadrouter') . '</button>';
            echo '<button type="button" class="button lr-tab-btn" data-tab="errors">'
                . esc_html__('Errors', 'leadrouter') . ' (<span id="lr-err-count">' . $err_count . '</span>)</button>';
            echo '</div>';

            // Панелі
            echo '<div class="lr-tab-panel" data-tab="transactions" style="display:none;">';
            self::render_transactions_tab();
            echo '</div>';

            echo '<div class="lr-tab-panel" data-tab="audit" style="display:none;">';
            self::render_audit_tab();
            echo '</div>';

            echo '<div class="lr-tab-panel" data-tab="errors" style="display:none;">';
            self::render_errors_tab();
            echo '</div>';

            echo '</div>';
        }

        /* ---------- Вкладка Recent Transactions ---------- */
        private static function render_transactions_tab()
        {
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';

            $f_partner = isset($_GET['f_tx_partner']) ? absint($_GET['f_tx_partner']) : 0;
            $f_type    = isset($_GET['f_tx_type']) ? sanitize_text_field(wp_unslash($_GET['f_tx_type'])) : '';
            $f_from    = self::clean_date($_GET['f_tx_from'] ?? '');
            $f_to      = self::clean_date($_GET['f_tx_to'] ?? '');
            $paged     = isset($_GET['tx_paged']) ? max(1, absint($_GET['tx_paged'])) : 1;
            $offset    = ($paged - 1) * self::PER_PAGE;

            [$where, $params] = self::build_where($f_partner, $f_type, '', $f_from, $f_to, 'type');

            $total = (int)$wpdb->get_var(
                $params ? $wpdb->prepare("SELECT COUNT(*) FROM {$t_tx} {$where}", $params)
                        : "SELECT COUNT(*) FROM {$t_tx} {$where}"
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$t_tx} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                    array_merge($params, [self::PER_PAGE, $offset])
                ),
                ARRAY_A
            );

            // Фільтр
            echo '<form method="get" class="lr-filter-bar">';
            echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
            echo '<input type="hidden" name="bt" value="transactions">';
            echo self::partner_select('f_tx_partner', $f_partner);
            echo self::type_select('f_tx_type', $f_type);
            echo '<input type="date" name="f_tx_from" value="' . esc_attr($f_from) . '">';
            echo '<input type="date" name="f_tx_to" value="' . esc_attr($f_to) . '">';
            echo '<button class="button">' . esc_html__('Filter', 'leadrouter') . '</button>';
            echo '</form>';

            echo '<table class="widefat striped lr-bil-table"><thead><tr>';
            foreach (['Date', 'Partner', 'Type', 'Leads', 'Amount', 'Balance after', 'Description'] as $h) {
                echo '<th>' . esc_html__($h, 'leadrouter') . '</th>';
            }
            echo '</tr></thead><tbody>';

            if (empty($rows)) {
                echo '<tr><td colspan="7">' . esc_html__('No transactions', 'leadrouter') . '</td></tr>';
            } else {
                foreach ($rows as $r) {
                    $amount = (float)$r['amount'];
                    $amount_str = ($amount > 0 ? '+' : '') . number_format($amount, 2) . ' ' . (string)$r['currency'];
                    $lead_id = (int)$r['lead_id'];
                    echo '<tr>';
                    echo '<td>' . esc_html((string)$r['created_at']) . '</td>';
                    echo '<td>' . self::partner_link((int)$r['partner_id']) . '</td>';
                    echo '<td><span class="' . esc_attr(self::tx_type_class((string)$r['type'])) . '">'
                        . esc_html((string)$r['type']) . '</span></td>';
                    echo '<td>' . ($lead_id > 0 ? '#' . $lead_id : '—') . '</td>';
                    echo '<td>' . esc_html($amount_str) . '</td>';
                    echo '<td>' . esc_html(number_format((float)$r['balance_after'], 2)) . '</td>';
                    echo '<td>' . esc_html((string)$r['description']) . '</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table>';

            self::pagination($total, $paged, 'tx_paged', [
                'bt' => 'transactions', 'f_tx_partner' => $f_partner, 'f_tx_type' => $f_type,
                'f_tx_from' => $f_from, 'f_tx_to' => $f_to,
            ]);
        }

        /* ---------- Вкладка Audit Log ---------- */
        private static function render_audit_tab()
        {
            global $wpdb;
            $t_audit = $wpdb->prefix . 'leadrouter_billing_audit_log';

            $f_partner = isset($_GET['f_au_partner']) ? absint($_GET['f_au_partner']) : 0;
            $f_action  = isset($_GET['f_au_action']) ? sanitize_text_field(wp_unslash($_GET['f_au_action'])) : '';
            $f_from    = self::clean_date($_GET['f_au_from'] ?? '');
            $f_to      = self::clean_date($_GET['f_au_to'] ?? '');
            $paged     = isset($_GET['au_paged']) ? max(1, absint($_GET['au_paged'])) : 1;
            $offset    = ($paged - 1) * self::PER_PAGE;

            [$where, $params] = self::build_where($f_partner, '', $f_action, $f_from, $f_to, 'action');

            $total = (int)$wpdb->get_var(
                $params ? $wpdb->prepare("SELECT COUNT(*) FROM {$t_audit} {$where}", $params)
                        : "SELECT COUNT(*) FROM {$t_audit} {$where}"
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$t_audit} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                    array_merge($params, [self::PER_PAGE, $offset])
                ),
                ARRAY_A
            );

            // Фільтр
            echo '<form method="get" class="lr-filter-bar">';
            echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
            echo '<input type="hidden" name="bt" value="audit">';
            echo self::partner_select('f_au_partner', $f_partner);
            echo self::action_select('f_au_action', $f_action);
            echo '<input type="date" name="f_au_from" value="' . esc_attr($f_from) . '">';
            echo '<input type="date" name="f_au_to" value="' . esc_attr($f_to) . '">';
            echo '<button class="button">' . esc_html__('Filter', 'leadrouter') . '</button>';
            echo '</form>';

            echo '<table class="widefat striped lr-bil-table"><thead><tr>';
            foreach (['Date', 'Partner', 'Action', 'Actor', 'Delta', 'Status', 'Details'] as $h) {
                echo '<th>' . esc_html__($h, 'leadrouter') . '</th>';
            }
            echo '</tr></thead><tbody>';

            if (empty($rows)) {
                echo '<tr><td colspan="7">' . esc_html__('No records', 'leadrouter') . '</td></tr>';
            } else {
                foreach ($rows as $r) {
                    $old = json_decode((string)($r['old_value'] ?? ''), true);
                    $new = json_decode((string)($r['new_value'] ?? ''), true);
                    $before = is_array($old) && isset($old['balance']) ? (float)$old['balance'] : null;
                    $after  = is_array($new) && isset($new['balance']) ? (float)$new['balance'] : null;
                    $delta  = ($before !== null && $after !== null) ? round($after - $before, 2) : null;

                    if ($delta === null || abs($delta) < 0.005) {
                        $st_class = 'lr-badge--info';  $st_text = 'info';
                    } elseif ($delta > 0) {
                        $st_class = 'lr-badge--active'; $st_text = 'credit';
                    } else {
                        $st_class = 'lr-badge--deactivated'; $st_text = 'debit';
                    }

                    $actor = (string)($r['actor_type'] ?? 'system');
                    if (!empty($r['actor_id'])) {
                        $user = get_userdata((int)$r['actor_id']);
                        $actor .= $user ? ' (' . $user->user_login . ')' : ' #' . (int)$r['actor_id'];
                    }

                    $details = [
                        'old_value'      => $old,
                        'new_value'      => $new,
                        'context'        => json_decode((string)($r['context_json'] ?? ''), true),
                        'transaction_id' => (int)($r['transaction_id'] ?? 0),
                    ];
                    $details_json = wp_json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

                    echo '<tr>';
                    echo '<td>' . esc_html((string)$r['created_at']) . '</td>';
                    echo '<td>' . self::partner_link((int)$r['partner_id']) . '</td>';
                    echo '<td><code>' . esc_html((string)$r['action']) . '</code></td>';
                    echo '<td>' . esc_html($actor) . '</td>';
                    echo '<td>' . ($delta === null ? '—'
                            : '<span class="' . ($delta >= 0 ? 'lr-tx--topup' : 'lr-tx--spend') . '">'
                                . esc_html(($delta > 0 ? '+' : '') . number_format($delta, 2)) . '</span>') . '</td>';
                    echo '<td><span class="lr-badge ' . esc_attr($st_class) . '">' . esc_html($st_text) . '</span></td>';
                    echo '<td><button type="button" class="button-link lr-audit-toggle" aria-expanded="false">▶</button></td>';
                    echo '</tr>';

                    echo '<tr class="lr-audit-details" style="display:none;"><td colspan="7">';
                    echo '<pre class="lr-json-pre">' . esc_html($details_json) . '</pre>';
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table>';

            self::pagination($total, $paged, 'au_paged', [
                'bt' => 'audit', 'f_au_partner' => $f_partner, 'f_au_action' => $f_action,
                'f_au_from' => $f_from, 'f_au_to' => $f_to,
            ]);
        }

        /* ---------- Вкладка Errors (тільки unresolved) ---------- */
        private static function render_errors_tab()
        {
            global $wpdb;
            $t_err = $wpdb->prefix . 'leadrouter_billing_errors';

            $paged  = isset($_GET['err_paged']) ? max(1, absint($_GET['err_paged'])) : 1;
            $offset = ($paged - 1) * self::PER_PAGE;

            $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t_err} WHERE is_resolved = 0");
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, created_at, partner_id, error_code, severity, error_message
                       FROM {$t_err} WHERE is_resolved = 0 ORDER BY id DESC LIMIT %d OFFSET %d",
                    self::PER_PAGE, $offset
                ),
                ARRAY_A
            );

            echo '<table class="widefat striped lr-bil-table"><thead><tr>';
            foreach (['Date', 'Partner', 'Error type', 'Severity', 'Message', 'Actions'] as $h) {
                echo '<th>' . esc_html__($h, 'leadrouter') . '</th>';
            }
            echo '</tr></thead><tbody>';

            if (empty($rows)) {
                echo '<tr><td colspan="6">' . esc_html__('No unresolved errors', 'leadrouter') . '</td></tr>';
            } else {
                foreach ($rows as $r) {
                    $sev = (string)$r['severity'];
                    echo '<tr data-error-id="' . (int)$r['id'] . '">';
                    echo '<td>' . esc_html((string)$r['created_at']) . '</td>';
                    echo '<td>' . self::partner_link((int)$r['partner_id']) . '</td>';
                    echo '<td><code>' . esc_html((string)$r['error_code']) . '</code></td>';
                    echo '<td><span class="lr-sev ' . esc_attr(self::severity_class($sev)) . '">' . esc_html($sev) . '</span></td>';
                    echo '<td>' . esc_html((string)$r['error_message']) . '</td>';
                    echo '<td><button type="button" class="button lr-resolve-btn" data-error-id="' . (int)$r['id'] . '">'
                        . esc_html__('Resolve', 'leadrouter') . '</button> <span class="lr-op-msg"></span></td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table>';

            self::pagination($total, $paged, 'err_paged', ['bt' => 'errors']);
        }

        /* ============================================================
         * СЕКЦІЯ 4 — Quick actions (CSV-експорт)
         * ============================================================ */
        private static function render_quick_actions()
        {
            $today = (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
            $month_start = (new DateTimeImmutable('first day of this month', new DateTimeZone('America/New_York')))->format('Y-m-d');

            echo '<div class="lr-bil-card"><h2>' . esc_html__('Quick actions', 'leadrouter') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="lr-filter-bar">';
            echo '<input type="hidden" name="action" value="lr_billing_export_csv">';
            wp_nonce_field('lr_billing_export');
            echo '<label>' . esc_html__('From', 'leadrouter') . ' <input type="date" name="date_from" value="' . esc_attr($month_start) . '"></label>';
            echo '<label>' . esc_html__('To', 'leadrouter') . ' <input type="date" name="date_to" value="' . esc_attr($today) . '"></label>';
            echo '<button class="button button-primary">' . esc_html__('Export transactions CSV', 'leadrouter') . '</button>';
            echo '</form></div>';
        }

        /** Стрімимо CSV транзакцій за період і виходимо */
        public static function handle_export_csv()
        {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('Access denied', 'leadrouter'));
            }
            check_admin_referer('lr_billing_export');

            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';

            $from = self::clean_date($_POST['date_from'] ?? '');
            $to   = self::clean_date($_POST['date_to'] ?? '');

            $where  = 'WHERE 1=1';
            $params = [];
            if ($from !== '') { $where .= ' AND created_at >= %s'; $params[] = $from . ' 00:00:00'; }
            if ($to !== '')   { $where .= ' AND created_at <= %s'; $params[] = $to . ' 23:59:59'; }

            $rows = $wpdb->get_results(
                $params
                    ? $wpdb->prepare("SELECT * FROM {$t_tx} {$where} ORDER BY id ASC", $params)
                    : "SELECT * FROM {$t_tx} {$where} ORDER BY id ASC",
                ARRAY_A
            );

            $filename = 'billing-transactions-' . ($from ?: 'all') . '_' . ($to ?: 'all') . '.csv';

            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $out = fopen('php://output', 'w');
            // BOM для коректної кирилиці в Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['id', 'created_at', 'partner_id', 'partner_name', 'type', 'lead_id',
                'amount', 'balance_after', 'currency', 'description']);

            foreach ((array)$rows as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['created_at'],
                    $r['partner_id'],
                    get_the_title((int)$r['partner_id']),
                    $r['type'],
                    $r['lead_id'],
                    $r['amount'],
                    $r['balance_after'],
                    $r['currency'],
                    $r['description'],
                ]);
            }
            fclose($out);
            exit;
        }

        /* ============================================================
         * Хелпери: фільтри, селекти, пагінація, кольори
         * ============================================================ */

        /** Будуємо WHERE для транзакцій/audit (partner + type|action + дати) */
        private static function build_where(int $partner, string $type, string $action, string $from, string $to, string $cat_col): array
        {
            $where  = [];
            $params = [];
            if ($partner > 0) { $where[] = 'partner_id = %d'; $params[] = $partner; }
            if ($type !== '')   { $where[] = "{$cat_col} = %s"; $params[] = $type; }
            if ($action !== '') { $where[] = "{$cat_col} = %s"; $params[] = $action; }
            if ($from !== '')   { $where[] = 'created_at >= %s'; $params[] = $from . ' 00:00:00'; }
            if ($to !== '')     { $where[] = 'created_at <= %s'; $params[] = $to . ' 23:59:59'; }

            $sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            return [$sql, $params];
        }

        /** Дропдаун партнерів (із білінг-таблиці) */
        private static function partner_select(string $name, int $selected): string
        {
            global $wpdb;
            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';
            $ids = $wpdb->get_col("SELECT partner_id FROM {$t_billing} ORDER BY partner_id ASC");

            $out = '<select name="' . esc_attr($name) . '"><option value="0">'
                . esc_html__('All partners', 'leadrouter') . '</option>';
            foreach ((array)$ids as $pid) {
                $pid = (int)$pid;
                $title = get_the_title($pid) ?: ('#' . $pid);
                $out .= '<option value="' . $pid . '" ' . selected($selected, $pid, false) . '>'
                    . esc_html($title) . '</option>';
            }
            return $out . '</select>';
        }

        /** Дропдаун типів транзакцій */
        private static function type_select(string $name, string $selected): string
        {
            $types = ['' => __('All types', 'leadrouter'), 'spend' => 'spend', 'topup' => 'topup',
                'auto_charge' => 'auto_charge', 'manual_debit' => 'manual_debit',
                'credit' => 'credit', 'refund' => 'refund', 'adjustment' => 'adjustment'];
            $out = '<select name="' . esc_attr($name) . '">';
            foreach ($types as $val => $label) {
                $out .= '<option value="' . esc_attr($val) . '" ' . selected($selected, $val, false) . '>'
                    . esc_html($label) . '</option>';
            }
            return $out . '</select>';
        }

        /** Дропдаун дій (із audit-таблиці) */
        private static function action_select(string $name, string $selected): string
        {
            global $wpdb;
            $t_audit = $wpdb->prefix . 'leadrouter_billing_audit_log';
            $actions = $wpdb->get_col("SELECT DISTINCT action FROM {$t_audit} ORDER BY action ASC LIMIT 200");

            $out = '<select name="' . esc_attr($name) . '"><option value="">'
                . esc_html__('All actions', 'leadrouter') . '</option>';
            foreach ((array)$actions as $act) {
                $out .= '<option value="' . esc_attr($act) . '" ' . selected($selected, $act, false) . '>'
                    . esc_html($act) . '</option>';
            }
            return $out . '</select>';
        }

        /** Пагінація (GET-посилання, що зберігають фільтри і активну вкладку) */
        private static function pagination(int $total, int $paged, string $page_param, array $base)
        {
            $pages = (int)ceil($total / self::PER_PAGE);
            if ($pages <= 1) {
                return;
            }
            $base['page'] = self::PAGE_SLUG;

            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo '<span class="displaying-num">' . esc_html(number_format_i18n($total)) . '</span> ';
            for ($i = 1; $i <= $pages; $i++) {
                $url = add_query_arg(array_merge($base, [$page_param => $i]), admin_url('admin.php'));
                if ($i === $paged) {
                    echo '<span class="button button-primary" style="margin:0 2px;">' . $i . '</span>';
                } else {
                    echo '<a class="button" style="margin:0 2px;" href="' . esc_url($url) . '">' . $i . '</a>';
                }
            }
            echo '</div></div>';
        }

        /** Посилання на партнера (екран редагування з вкладкою «Білінг») */
        private static function partner_link(int $pid): string
        {
            $title = get_the_title($pid) ?: ('#' . $pid);
            $url   = self::partner_url($pid);
            return $url ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>' : esc_html($title);
        }

        private static function partner_url(int $pid): string
        {
            $url = get_edit_post_link($pid, 'raw');
            return $url ?: '';
        }

        /** Нормалізація дати Y-m-d (або порожній рядок) */
        private static function clean_date($val): string
        {
            $val = is_string($val) ? trim($val) : '';
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : '';
        }

        /** CSS-клас кольору по типу транзакції (як на сторінці партнера) */
        private static function tx_type_class(string $type): string
        {
            switch ($type) {
                case 'spend':         return 'lr-tx--spend';
                case 'topup':
                case 'auto_charge':
                case 'credit':
                case 'refund':        return 'lr-tx--topup';
                case 'manual_debit':  return 'lr-tx--manual';
                default:              return 'lr-tx--adjust';
            }
        }

        /** CSS-клас кольору по severity */
        private static function severity_class(string $severity): string
        {
            switch ($severity) {
                case 'warning':  return 'lr-sev--warning';
                case 'critical': return 'lr-sev--critical';
                case 'error':
                default:         return 'lr-sev--error';
            }
        }

        /* ============================================================
         * Inline CSS / JS
         * ============================================================ */
        private static function inline_css(): string
        {
            return '
            .lr-billing-wrap .lr-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin:16px 0; }
            @media (max-width:1100px){ .lr-billing-wrap .lr-cards { grid-template-columns:repeat(2,1fr); } }
            .lr-billing-wrap .lr-card { background:#fff; border:1px solid #ccd0d4; border-left-width:4px; border-radius:4px; padding:16px 18px; display:flex; flex-direction:column; }
            .lr-billing-wrap .lr-card-val { font-size:26px; font-weight:700; line-height:1.1; }
            .lr-billing-wrap .lr-card-label { color:#646970; font-size:12px; margin-top:6px; text-transform:uppercase; letter-spacing:.03em; }
            .lr-billing-wrap .lr-card--blue { border-left-color:#2271b1; }
            .lr-billing-wrap .lr-card--green { border-left-color:#0b8a31; }
            .lr-billing-wrap .lr-card--red { border-left-color:#d63638; }
            .lr-billing-wrap .lr-card--orange { border-left-color:#e08000; }
            .lr-billing-wrap .lr-bil-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px 18px; margin:0 0 16px; }
            .lr-billing-wrap .lr-bal--green { color:#0b8a31; }
            .lr-billing-wrap .lr-bal--yellow { color:#b8860b; }
            .lr-billing-wrap .lr-bal--red { color:#d63638; }
            .lr-billing-wrap .lr-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; color:#fff; }
            .lr-billing-wrap .lr-badge--active { background:#0b8a31; }
            .lr-billing-wrap .lr-badge--low { background:#dfc30d; color:#3a3a00; }
            .lr-billing-wrap .lr-badge--deactivated { background:#d63638; }
            .lr-billing-wrap .lr-badge--disabled { background:#50575e; }
            .lr-billing-wrap .lr-badge--info { background:#787c82; }
            .lr-billing-wrap .lr-tx--spend { color:#d63638; font-weight:600; }
            .lr-billing-wrap .lr-tx--topup { color:#0b8a31; font-weight:600; }
            .lr-billing-wrap .lr-tx--manual { color:#e08000; font-weight:600; }
            .lr-billing-wrap .lr-tx--adjust { color:#787c82; font-weight:600; }
            .lr-billing-wrap .lr-sev { padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; }
            .lr-billing-wrap .lr-sev--warning { background:#fff3cd; color:#8a6d00; }
            .lr-billing-wrap .lr-sev--error { background:#ffe5cc; color:#aa4400; }
            .lr-billing-wrap .lr-sev--critical { background:#f8d7da; color:#a00; }
            .lr-billing-wrap .lr-json-pre { background:#f6f7f7; padding:10px; margin:0; overflow:auto; max-height:320px; white-space:pre-wrap; word-break:break-word; }
            .lr-billing-wrap .lr-op-msg { margin-left:6px; font-weight:600; }
            .lr-billing-wrap .lr-op-msg.err { color:#d63638; }
            .lr-billing-wrap .lr-tabs-nav { display:flex; gap:6px; margin-bottom:12px; border-bottom:1px solid #ccd0d4; padding-bottom:10px; }
            .lr-billing-wrap .lr-tab-btn.lr-tab-active { background:#2271b1; color:#fff; border-color:#2271b1; }
            .lr-billing-wrap .lr-filter-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:0 0 12px; }
            ';
        }

        private static function inline_js(): string
        {
            return <<<'JS'
(function () {
    function qsa(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
    function qs(s, c) { return (c || document).querySelector(s); }

    document.addEventListener('DOMContentLoaded', function () {
        // --- Перемикання вкладок (vanilla) ---
        function activate(tab) {
            qsa('.lr-tab-btn').forEach(function (b) {
                b.classList.toggle('lr-tab-active', b.getAttribute('data-tab') === tab);
            });
            qsa('.lr-tab-panel').forEach(function (p) {
                p.style.display = (p.getAttribute('data-tab') === tab) ? '' : 'none';
            });
            try { var u = new URL(window.location); u.searchParams.set('bt', tab); history.replaceState({}, '', u); } catch (e) {}
        }
        qsa('.lr-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () { activate(btn.getAttribute('data-tab')); });
        });

        // Початкова вкладка з ?bt=
        var params = new URLSearchParams(window.location.search);
        activate(params.get('bt') || 'transactions');

        // --- Розкриття JSON-деталей audit ---
        qsa('.lr-audit-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tr = btn.closest('tr');
                var det = tr.nextElementSibling;
                if (!det || !det.classList.contains('lr-audit-details')) { return; }
                var open = det.style.display !== 'none';
                det.style.display = open ? 'none' : '';
                btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                btn.textContent = open ? '▶' : '▼';
            });
        });

        // --- Resolve помилки (AJAX, fetch) ---
        qsa('.lr-resolve-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-error-id');
                var msg = btn.parentNode.querySelector('.lr-op-msg');
                if (msg) { msg.textContent = '…'; msg.className = 'lr-op-msg'; }

                var fd = new FormData();
                fd.append('action', 'lr_resolve_billing_error');
                fd.append('nonce', LRBD.nonce);
                fd.append('error_id', id);

                fetch(LRBD.ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (r) {
                        if (r && r.success) {
                            var tr = btn.closest('tr');
                            if (tr && tr.parentNode) { tr.parentNode.removeChild(tr); }
                            var c = qs('#lr-err-count');
                            if (c) { var n = parseInt(c.textContent, 10) || 0; c.textContent = Math.max(0, n - 1); }
                        } else if (msg) {
                            msg.textContent = (r && r.data && r.data.message) || 'Error';
                            msg.className = 'lr-op-msg err';
                        }
                    })
                    .catch(function () { if (msg) { msg.textContent = 'Network error'; msg.className = 'lr-op-msg err'; } });
            });
        });
    });
})();
JS;
        }
    }
}
