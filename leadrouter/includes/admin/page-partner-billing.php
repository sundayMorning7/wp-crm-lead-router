<?php
/**
 * LR_Partner_Billing_Page — білінг партнера, вбудований у сторінку партнера.
 *
 * Рендериться НЕ окремою сторінкою, а двома вкладками Carbon Fields на екрані
 * редагування партнера (post.php?post=ID&action=edit):
 *   - «Білінг»         — налаштування + ручні операції (2 колонки поряд);
 *   - «Білінг history» — Transaction History / Audit Log / Billing Errors.
 *
 * Особливості реалізації:
 *   - Сторінка партнера вже є <form>, тому ВСІ дії — через AJAX (жодних вкладених
 *     <form>; використовуємо <div> + кнопки).
 *   - Carbon Fields рендерить html-поля через React (innerHTML), тож inline <script>
 *     не виконується — JS і CSS підключаємо окремо через admin_enqueue_scripts,
 *     а обробники навішуємо делеговано на document.
 *
 * Мутації балансу йдуть через LR_Billing (race-safe). Час — EST.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Partner_Billing_Page')) {

    class LR_Partner_Billing_Page
    {
        /** Nonce-action для AJAX */
        const NONCE = 'lr_billing_ajax';

        /** Рядків на сторінку в таблицях історії */
        const PER_PAGE = 20;

        /* ============================================================
         * Реєстрація AJAX + ассетів (меню НЕ реєструємо — вбудовано в партнера)
         * ============================================================ */
        public static function register()
        {
            add_action('wp_ajax_lr_manual_debit',          [__CLASS__, 'ajax_manual_debit']);
            add_action('wp_ajax_lr_manual_topup',          [__CLASS__, 'ajax_manual_topup']);
            add_action('wp_ajax_lr_save_billing_settings', [__CLASS__, 'ajax_save_settings']);
            add_action('wp_ajax_lr_resolve_billing_error', [__CLASS__, 'ajax_resolve_error']);
            add_action('wp_ajax_lr_load_billing_history',  [__CLASS__, 'ajax_load_history']);
            add_action('wp_ajax_lr_reactivate_partner',    [__CLASS__, 'ajax_reactivate_partner']);

            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        }

        /** JS + CSS лише на екрані редагування партнера */
        public static function enqueue_assets($hook)
        {
            if ($hook !== 'post.php' && $hook !== 'post-new.php') {
                return;
            }
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'leadrouter_partner') {
                return;
            }

            // Порожні «контейнерні» хендли — лише для inline CSS/JS
            wp_register_style('lr-billing-admin', false, [], LEADROUTER_VERSION);
            wp_enqueue_style('lr-billing-admin');
            wp_add_inline_style('lr-billing-admin', self::inline_css());

            wp_register_script('lr-billing-admin', '', ['jquery'], LEADROUTER_VERSION, true);
            wp_enqueue_script('lr-billing-admin');
            wp_localize_script('lr-billing-admin', 'LRBilling', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE),
            ]);
            wp_add_inline_script('lr-billing-admin', self::inline_js());
        }

        /* ============================================================
         * Вкладка «Білінг»: налаштування + ручні операції (2 колонки)
         * ============================================================ */
        public static function tab_billing_main_html(int $partner_id): string
        {
            if ($partner_id <= 0 || get_post_type($partner_id) !== 'leadrouter_partner') {
                return '<div class="notice notice-error inline"><p>'
                    . esc_html__('Invalid partner.', 'leadrouter') . '</p></div>';
            }

            $row = self::get_or_create_billing_row($partner_id);

            $html  = '<div class="lr-billing-wrap">';
            $html .= '<div id="lr-status-line" class="lr-status-line">' . self::status_line_html($partner_id, $row) . '</div>';

            // 2 колонки: ліворуч — Settings, праворуч — Manual operations
            $html .= '<div class="lr-bil-2col">';
            $html .= '<div class="lr-bil-card">' . self::settings_box_html($partner_id, $row) . '</div>';
            $html .= '<div class="lr-bil-card">' . self::manual_box_html($partner_id, $row) . '</div>';
            $html .= '</div>';

            $html .= '</div>'; // .lr-billing-wrap
            return $html;
        }

        /** Рядок статусу: Active / ⚠️ Low balance / 🔴 Deactivated */
        public static function status_line_html(int $partner_id, array $row = null): string
        {
            if ($row === null) {
                $row = self::get_or_create_billing_row($partner_id);
            }

            $balance     = (float)$row['balance'];
            $min_balance = (float)$row['min_balance'];
            $deactivated = (int)($row['deactivated_by_billing'] ?? 0) === 1;

            if ($deactivated) {
                $badge = '<span class="lr-badge lr-badge--deactivated">🔴 ' . esc_html__('Deactivated', 'leadrouter') . '</span>';
                // Кнопка ручної реактивації — показуємо лише в зупиненому стані;
                // після успіху AJAX повертає свіжий status_html уже без неї.
                $badge .= ' <button type="button" class="button button-small lr-reactivate" data-partner="' . (int)$partner_id . '">'
                        . esc_html__('Reactivate', 'leadrouter') . '</button>'
                        . ' <span class="lr-op-msg lr-reactivate-msg"></span>';
            } elseif ($balance < $min_balance) {
                $badge = '<span class="lr-badge lr-badge--low">⚠️ ' . esc_html__('Low balance', 'leadrouter') . '</span>';
            } else {
                $badge = '<span class="lr-badge lr-badge--active">✅ ' . esc_html__('Active', 'leadrouter') . '</span>';
            }

            // Роль leadrouter_manager має manage_options лише в контексті LeadRouter, але
            // екшен імперсонації їй не дозволений — тож кнопку їй не показуємо (інакше 403).
            $can_impersonate = current_user_can('manage_options')
                && !(class_exists('LeadRouter_Restricted_Access') && LeadRouter_Restricted_Access::is_manager());

            $view_cabinet = '';
            if ($can_impersonate && class_exists('LR_Partner_Impersonate')
                && class_exists('LR_Partner_Auth') && LR_Partner_Auth::get_user_id_for_partner($partner_id) > 0
            ) {
                $view_cabinet = ' <a class="button button-small" href="'
                    . esc_url(LR_Partner_Impersonate::view_url($partner_id)) . '">'
                    . esc_html__('View cabinet', 'leadrouter') . '</a>';
            }

            return '<strong>' . esc_html__('Status:', 'leadrouter') . '</strong> ' . $badge . $view_cabinet;
        }

        /** Бокс налаштувань (без <form> — кнопка через AJAX) */
        private static function settings_box_html(int $partner_id, array $row): string
        {
            $currency = (string)$row['currency'];
            $balance  = (float)$row['balance'];
            $min      = (float)$row['min_balance'];
            $low_cls  = $balance < $min ? ' lr-balance-low' : '';

            ob_start();
            ?>
            <h2><?php echo esc_html__('Billing Settings', 'leadrouter'); ?></h2>
            <div id="lr-settings-box" data-partner="<?php echo (int)$partner_id; ?>">
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Lead price Mon–Fri', 'leadrouter'); ?></th>
                        <td><input type="number" step="0.01" min="0" name="lr_bil_lead_price"
                                   value="<?php echo esc_attr(number_format((float)$row['lead_price'], 2, '.', '')); ?>"> <?php echo esc_html($currency); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Lead price Saturday', 'leadrouter'); ?></th>
                        <td><input type="number" step="0.01" min="0" name="lr_bil_lead_price_saturday"
                                   value="<?php echo ($row['lead_price_saturday'] ?? null) !== null ? esc_attr(number_format((float)$row['lead_price_saturday'], 2, '.', '')) : ''; ?>"
                                   placeholder="<?php echo esc_attr__('Leave empty to use Mon–Fri price', 'leadrouter'); ?>"> <?php echo esc_html($currency); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Lead price Sunday', 'leadrouter'); ?></th>
                        <td><input type="number" step="0.01" min="0" name="lr_bil_lead_price_sunday"
                                   value="<?php echo ($row['lead_price_sunday'] ?? null) !== null ? esc_attr(number_format((float)$row['lead_price_sunday'], 2, '.', '')) : ''; ?>"
                                   placeholder="<?php echo esc_attr__('Leave empty to use Mon–Fri price', 'leadrouter'); ?>"> <?php echo esc_html($currency); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Current balance', 'leadrouter'); ?></th>
                        <td><strong id="lr-current-balance" class="lr-balance<?php echo esc_attr($low_cls); ?>"><?php
                            echo esc_html(number_format($balance, 2) . ' ' . $currency); ?></strong>
                            <span class="description"><?php echo esc_html__('(read only)', 'leadrouter'); ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Min balance threshold', 'leadrouter'); ?></th>
                        <td><input type="number" step="0.01" name="lr_bil_min_balance"
                                   value="<?php echo esc_attr(number_format($min, 2, '.', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Auto top-up amount', 'leadrouter'); ?></th>
                        <td><input type="number" step="0.01" min="0" name="lr_bil_auto_charge_amount"
                                   value="<?php echo esc_attr(number_format((float)$row['auto_charge_amount'], 2, '.', '')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Auto-charge enabled', 'leadrouter'); ?></th>
                        <td><label><input type="checkbox" name="lr_bil_auto_charge_enabled" value="1" <?php
                            checked((int)$row['auto_charge_enabled'], 1); ?>> <?php
                            echo esc_html__('Allow Stripe auto-charge', 'leadrouter'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Stripe Customer ID', 'leadrouter'); ?></th>
                        <td><input type="text" class="regular-text" name="lr_bil_stripe_customer_id"
                                   value="<?php echo esc_attr((string)$row['stripe_customer_id']); ?>" placeholder="cus_..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Stripe Payment Method ID', 'leadrouter'); ?></th>
                        <td><input type="text" class="regular-text" name="lr_bil_stripe_payment_method_id"
                                   value="<?php echo esc_attr((string)$row['stripe_payment_method_id']); ?>" placeholder="pm_..."></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Partner Name', 'leadrouter'); ?></th>
                        <td><input type="text" class="regular-text" name="lr_bil_partner_display_name"
                                   value="<?php echo esc_attr((string)($row['partner_display_name'] ?? '')); ?>"
                                   placeholder="<?php echo esc_attr__('Displayed in email notifications', 'leadrouter'); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Partner email', 'leadrouter'); ?></th>
                        <td><input type="email" class="regular-text" name="lr_bil_email"
                                   value="<?php echo esc_attr((string)($row['email'] ?? '')); ?>"
                                   placeholder="partner@example.com">
                            <span class="description"><?php echo esc_html__('for billing notifications', 'leadrouter'); ?></span></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Allow negative balance', 'leadrouter'); ?></th>
                        <td><label><input type="checkbox" name="lr_bil_allow_negative" value="1" <?php
                            checked((int)($row['allow_negative_balance'] ?? 0), 1); ?>> <?php
                            echo esc_html__('Do not stop the partner on negative balance', 'leadrouter'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Disable low-balance email', 'leadrouter'); ?></th>
                        <td><label><input type="checkbox" name="lr_bil_disable_low_email" value="1" <?php
                            checked((int)($row['disable_low_balance_email'] ?? 0), 1); ?>> <?php
                            echo esc_html__('Do not send low-balance email', 'leadrouter'); ?></label></td>
                    </tr>
                </tbody></table>
                <p>
                    <button type="button" class="button button-primary lr-save-settings"><?php
                        echo esc_html__('Save Settings', 'leadrouter'); ?></button>
                    <span class="lr-op-msg lr-settings-msg"></span>
                </p>
            </div>
            <?php
            return ob_get_clean();
        }

        /** Бокс ручних операцій — у стовпчик (Debit зверху, Top-up знизу) */
        private static function manual_box_html(int $partner_id, array $row): string
        {
            $currency = esc_html((string)$row['currency']);

            ob_start();
            ?>
            <h2><?php echo esc_html__('Manual operations', 'leadrouter'); ?></h2>
            <div class="lr-manual-box" data-partner="<?php echo (int)$partner_id; ?>">

                <div class="lr-manual-item" data-op="debit">
                    <h3><?php echo esc_html__('Manual Debit', 'leadrouter'); ?></h3>
                    <p><label><?php echo esc_html__('Amount', 'leadrouter'); ?><br>
                        <input type="number" step="0.01" min="0.01" name="lr_bil_amount"> <?php echo $currency; ?></label></p>
                    <p><label><?php echo esc_html__('Reason', 'leadrouter'); ?><br>
                        <input type="text" class="regular-text" name="lr_bil_reason"></label></p>
                    <p><button type="button" class="button lr-do-debit"><?php echo esc_html__('Debit', 'leadrouter'); ?></button>
                        <span class="lr-op-msg"></span></p>
                </div>

                <hr>

                <div class="lr-manual-item" data-op="topup">
                    <h3><?php echo esc_html__('Manual Top-up', 'leadrouter'); ?></h3>
                    <p><label><?php echo esc_html__('Amount', 'leadrouter'); ?><br>
                        <input type="number" step="0.01" min="0.01" name="lr_bil_amount"> <?php echo $currency; ?></label></p>
                    <p><label><?php echo esc_html__('Note', 'leadrouter'); ?><br>
                        <input type="text" class="regular-text" name="lr_bil_note"></label></p>
                    <p><button type="button" class="button lr-do-topup"><?php echo esc_html__('Top up', 'leadrouter'); ?></button>
                        <span class="lr-op-msg"></span></p>
                </div>

            </div>
            <?php
            return ob_get_clean();
        }

        /* ============================================================
         * Вкладка «Білінг history»: 3 таблиці (кожна з AJAX-пагінацією)
         * ============================================================ */
        public static function tab_billing_history_html(int $partner_id): string
        {
            if ($partner_id <= 0 || get_post_type($partner_id) !== 'leadrouter_partner') {
                return '<div class="notice notice-error inline"><p>'
                    . esc_html__('Invalid partner.', 'leadrouter') . '</p></div>';
            }

            $html  = '<div class="lr-billing-wrap" data-partner="' . (int)$partner_id . '">';

            $html .= '<div class="lr-bil-card"><h2>' . esc_html__('Transaction History', 'leadrouter') . '</h2>';
            $html .= '<div id="lr-hist-tx">' . self::render_tx_section($partner_id, 1, '') . '</div></div>';

            $html .= '<div class="lr-bil-card"><h2>' . esc_html__('Audit Log', 'leadrouter') . '</h2>';
            $html .= '<div id="lr-hist-audit">' . self::render_audit_section($partner_id, 1) . '</div></div>';

            $html .= '<div class="lr-bil-card"><h2>' . esc_html__('Billing Errors', 'leadrouter') . '</h2>';
            $html .= '<div id="lr-hist-errors">' . self::render_errors_section($partner_id, 1) . '</div></div>';

            $html .= '</div>';
            return $html;
        }

        /* ---------- Transaction History ---------- */
        private static function render_tx_section(int $partner_id, int $paged, string $type_filter): string
        {
            global $wpdb;
            $t_tx   = $wpdb->prefix . 'leadrouter_billing_transactions';
            $paged  = max(1, $paged);
            $offset = ($paged - 1) * self::PER_PAGE;

            $where  = 'WHERE partner_id = %d';
            $params = [$partner_id];
            if ($type_filter !== '') {
                $where   .= ' AND type = %s';
                $params[] = $type_filter;
            }

            $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t_tx} {$where}", $params));
            $rows  = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT created_at, type, lead_id, amount, balance_after, currency, description
                       FROM {$t_tx} {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                    array_merge($params, [self::PER_PAGE, $offset])
                ),
                ARRAY_A
            );

            ob_start();

            // Фільтр по type
            $types = ['' => __('All', 'leadrouter'), 'spend' => 'spend', 'topup' => 'topup',
                'auto_charge' => 'auto_charge', 'manual_debit' => 'manual_debit',
                'credit' => 'credit', 'refund' => 'refund', 'adjustment' => 'adjustment'];
            echo '<p class="lr-filter-line"><label>' . esc_html__('Type:', 'leadrouter')
                . ' <select class="lr-hist-filter" data-section="tx">';
            foreach ($types as $val => $label) {
                printf('<option value="%s" %s>%s</option>',
                    esc_attr($val), selected($type_filter, $val, false), esc_html($label));
            }
            echo '</select></label></p>';

            echo '<table class="widefat striped lr-bil-table"><thead><tr>';
            echo '<th>' . esc_html__('Date', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Type', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Leads', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Amount', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Balance after', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Description', 'leadrouter') . '</th>';
            echo '</tr></thead><tbody id="lr-tx-tbody">';
            if (empty($rows)) {
                echo '<tr><td colspan="6">' . esc_html__('No transactions', 'leadrouter') . '</td></tr>';
            } else {
                foreach ($rows as $r) {
                    echo self::tx_row_html($r);
                }
            }
            echo '</tbody></table>';

            echo self::pagination_html($total, $paged, 'tx', $type_filter);

            return ob_get_clean();
        }

        /** Рядок таблиці транзакцій (також для AJAX prepend) */
        public static function tx_row_html(array $r): string
        {
            $type     = (string)($r['type'] ?? '');
            $amount   = (float)($r['amount'] ?? 0);
            $currency = (string)($r['currency'] ?? 'USD');
            $lead_id  = (int)($r['lead_id'] ?? 0);

            $amount_str = ($amount > 0 ? '+' : '') . number_format($amount, 2) . ' ' . $currency;

            // Для списань за лід показуємо згенерований англ. лейбл (а не сирий
            // description), щоб і старі укр-записи в БД виглядали коректно.
            $descr = ($type === 'spend' && $lead_id > 0)
                ? sprintf(__('Lead charge #%d', 'leadrouter'), $lead_id)
                : (string)($r['description'] ?? '');

            ob_start();
            ?>
            <tr>
                <td><?php echo esc_html((string)($r['created_at'] ?? '')); ?></td>
                <td><span class="lr-tx-type <?php echo esc_attr(self::tx_type_class($type)); ?>"><?php echo esc_html($type); ?></span></td>
                <td><?php echo $lead_id > 0 ? '#' . (int)$lead_id : '—'; ?></td>
                <td><?php echo esc_html($amount_str); ?></td>
                <td><?php echo esc_html(number_format((float)($r['balance_after'] ?? 0), 2)); ?></td>
                <td><?php echo esc_html($descr); ?></td>
            </tr>
            <?php
            return ob_get_clean();
        }

        /** CSS-клас кольору по типу транзакції */
        private static function tx_type_class(string $type): string
        {
            switch ($type) {
                case 'spend':         return 'lr-tx--spend';   // червоний
                case 'topup':
                case 'auto_charge':
                case 'credit':
                case 'refund':        return 'lr-tx--topup';   // зелений
                case 'manual_debit':  return 'lr-tx--manual';  // помаранчевий
                default:              return 'lr-tx--adjust';  // сірий (adjustment та ін.)
            }
        }

        /* ---------- Audit Log ---------- */
        private static function render_audit_section(int $partner_id, int $paged): string
        {
            global $wpdb;
            $t_audit = $wpdb->prefix . 'leadrouter_billing_audit_log';
            $paged   = max(1, $paged);
            $offset  = ($paged - 1) * self::PER_PAGE;

            $total = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$t_audit} WHERE partner_id = %d", $partner_id)
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, created_at, action, actor_type, actor_id, old_value, new_value, context_json, transaction_id
                       FROM {$t_audit} WHERE partner_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                    $partner_id, self::PER_PAGE, $offset
                ),
                ARRAY_A
            );

            ob_start();
            echo '<table class="widefat striped lr-bil-table"><thead><tr>';
            echo '<th>' . esc_html__('Date', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Action', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Actor', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Amount before', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Amount after', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Delta', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Status', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Details', 'leadrouter') . '</th>';
            echo '</tr></thead><tbody>';

            if (empty($rows)) {
                echo '<tr><td colspan="8">' . esc_html__('No records', 'leadrouter') . '</td></tr>';
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
                    echo '<td><code>' . esc_html((string)$r['action']) . '</code></td>';
                    echo '<td>' . esc_html($actor) . '</td>';
                    echo '<td>' . ($before === null ? '—' : esc_html(number_format($before, 2))) . '</td>';
                    echo '<td>' . ($after === null ? '—' : esc_html(number_format($after, 2))) . '</td>';
                    echo '<td>' . ($delta === null ? '—'
                            : '<span class="' . ($delta >= 0 ? 'lr-tx--topup' : 'lr-tx--spend') . '">'
                                . esc_html(($delta > 0 ? '+' : '') . number_format($delta, 2)) . '</span>') . '</td>';
                    echo '<td><span class="lr-badge ' . esc_attr($st_class) . '">' . esc_html($st_text) . '</span></td>';
                    echo '<td><button type="button" class="button-link lr-audit-toggle" aria-expanded="false">▶</button></td>';
                    echo '</tr>';

                    echo '<tr class="lr-audit-details" style="display:none;"><td colspan="8">';
                    echo '<pre class="lr-json-pre">' . esc_html($details_json) . '</pre>';
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table>';

            echo self::pagination_html($total, $paged, 'audit', '');
            return ob_get_clean();
        }

        /* ---------- Billing Errors ---------- */
        private static function render_errors_section(int $partner_id, int $paged): string
        {
            global $wpdb;
            $t_err  = $wpdb->prefix . 'leadrouter_billing_errors';
            $paged  = max(1, $paged);
            $offset = ($paged - 1) * self::PER_PAGE;

            $total = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$t_err} WHERE partner_id = %d", $partner_id)
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, created_at, error_code, severity, error_message, is_resolved, resolved_at
                       FROM {$t_err} WHERE partner_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                    $partner_id, self::PER_PAGE, $offset
                ),
                ARRAY_A
            );

            ob_start();
            echo '<table class="widefat striped lr-bil-table"><thead><tr>';
            echo '<th>' . esc_html__('Date', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Error type', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Severity', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Message', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Resolved', 'leadrouter') . '</th>';
            echo '<th>' . esc_html__('Actions', 'leadrouter') . '</th>';
            echo '</tr></thead><tbody>';

            if (empty($rows)) {
                echo '<tr><td colspan="6">' . esc_html__('No errors', 'leadrouter') . '</td></tr>';
            } else {
                foreach ($rows as $r) {
                    $sev      = (string)($r['severity'] ?? 'error');
                    $resolved = (int)($r['is_resolved'] ?? 0) === 1;

                    echo '<tr data-error-id="' . (int)$r['id'] . '">';
                    echo '<td>' . esc_html((string)$r['created_at']) . '</td>';
                    echo '<td><code>' . esc_html((string)$r['error_code']) . '</code></td>';
                    echo '<td><span class="lr-sev ' . esc_attr(self::severity_class($sev)) . '">' . esc_html($sev) . '</span></td>';
                    echo '<td>' . esc_html((string)$r['error_message']) . '</td>';
                    echo '<td class="lr-err-resolved">' . ($resolved
                            ? '✅ ' . esc_html((string)($r['resolved_at'] ?? '')) : '—') . '</td>';
                    echo '<td>';
                    if ($resolved) {
                        echo '—';
                    } else {
                        echo '<button type="button" class="button lr-resolve-btn" data-error-id="' . (int)$r['id']
                            . '">' . esc_html__('Resolve', 'leadrouter') . '</button> <span class="lr-op-msg"></span>';
                    }
                    echo '</td></tr>';
                }
            }
            echo '</tbody></table>';

            echo self::pagination_html($total, $paged, 'errors', '');
            return ob_get_clean();
        }

        /** CSS-клас кольору по severity */
        private static function severity_class(string $severity): string
        {
            switch ($severity) {
                case 'warning':  return 'lr-sev--warning';   // жовтий
                case 'critical': return 'lr-sev--critical';  // червоний
                case 'error':
                default:         return 'lr-sev--error';     // помаранчевий
            }
        }

        /** Пагінація (AJAX-кнопки, без перезавантаження сторінки) */
        private static function pagination_html(int $total, int $paged, string $section, string $type_filter): string
        {
            $pages = (int)ceil($total / self::PER_PAGE);
            if ($pages <= 1) {
                return '';
            }
            $out = '<div class="tablenav"><div class="tablenav-pages">';
            $out .= '<span class="displaying-num">' . esc_html(number_format_i18n($total)) . '</span> ';
            for ($i = 1; $i <= $pages; $i++) {
                if ($i === $paged) {
                    $out .= '<span class="button button-primary" style="margin:0 2px;">' . $i . '</span>';
                } else {
                    $out .= '<button type="button" class="button lr-hist-page" style="margin:0 2px;"'
                        . ' data-section="' . esc_attr($section) . '"'
                        . ' data-paged="' . (int)$i . '"'
                        . ' data-type="' . esc_attr($type_filter) . '">' . $i . '</button>';
                }
            }
            $out .= '</div></div>';
            return $out;
        }

        /* ============================================================
         * AJAX-хендлери
         * ============================================================ */
        public static function ajax_manual_debit()
        {
            self::ajax_guard();
            $partner_id = isset($_POST['partner_id']) ? absint($_POST['partner_id']) : 0;
            $amount     = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
            $reason     = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

            if ($partner_id <= 0 || $amount <= 0) {
                wp_send_json_error(['message' => __('Invalid data', 'leadrouter')], 400);
            }

            $res = LR_Billing::manual_debit($partner_id, $amount, $reason, get_current_user_id());
            if (is_wp_error($res)) {
                wp_send_json_error(['message' => $res->get_error_message()], 400);
            }

            wp_send_json_success([
                'balance_after' => (float)$res['balance_after'],
                'status_html'   => self::status_line_html($partner_id),
                'row_html'      => self::tx_row_html([
                    'created_at'    => self::now_est(),
                    'type'          => 'manual_debit',
                    'lead_id'       => 0,
                    'amount'        => -abs($amount),
                    'balance_after' => (float)$res['balance_after'],
                    'currency'      => self::get_currency($partner_id),
                    'description'   => $reason,
                ]),
            ]);
        }

        public static function ajax_manual_topup()
        {
            self::ajax_guard();
            $partner_id = isset($_POST['partner_id']) ? absint($_POST['partner_id']) : 0;
            $amount     = isset($_POST['amount']) ? (float)$_POST['amount'] : 0.0;
            $note       = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';

            if ($partner_id <= 0 || $amount <= 0) {
                wp_send_json_error(['message' => __('Invalid data', 'leadrouter')], 400);
            }

            $desc = $note !== '' ? $note : __('Manual top-up', 'leadrouter');
            $res  = LR_Billing::topup($partner_id, $amount, 'topup', $desc);
            if (is_wp_error($res)) {
                wp_send_json_error(['message' => $res->get_error_message()], 400);
            }

            wp_send_json_success([
                'balance_after' => (float)$res['balance_after'],
                'status_html'   => self::status_line_html($partner_id),
                'row_html'      => self::tx_row_html([
                    'created_at'    => self::now_est(),
                    'type'          => 'topup',
                    'lead_id'       => 0,
                    'amount'        => abs($amount),
                    'balance_after' => (float)$res['balance_after'],
                    'currency'      => self::get_currency($partner_id),
                    'description'   => $desc,
                ]),
            ]);
        }

        public static function ajax_save_settings()
        {
            global $wpdb;
            self::ajax_guard();

            $partner_id = isset($_POST['partner_id']) ? absint($_POST['partner_id']) : 0;
            if ($partner_id <= 0) {
                wp_send_json_error(['message' => __('Invalid partner ID', 'leadrouter')], 400);
            }

            $before = self::get_or_create_billing_row($partner_id);

            // Ціни вихідних: порожнє або 0 → NULL (не задано), інакше має бути > 0.
            // NULL означає fallback на weekday-ціну, тому 0 НЕ зберігаємо як «безкоштовно».
            $sat = self::parse_weekend_price($_POST['lead_price_saturday'] ?? '');
            $sun = self::parse_weekend_price($_POST['lead_price_sunday'] ?? '');
            if ($sat === false || $sun === false) {
                wp_send_json_error(['message' => __('Weekend lead price must be greater than 0 (or empty)', 'leadrouter')], 400);
            }

            $data = [
                'lead_price'               => isset($_POST['lead_price']) ? round((float)$_POST['lead_price'], 2) : 0.0,
                'lead_price_saturday'      => $sat, // float|null
                'lead_price_sunday'        => $sun, // float|null
                'min_balance'              => isset($_POST['min_balance']) ? round((float)$_POST['min_balance'], 2) : 0.0,
                'auto_charge_amount'       => isset($_POST['auto_charge_amount']) ? round((float)$_POST['auto_charge_amount'], 2) : 0.0,
                'auto_charge_enabled'      => !empty($_POST['auto_charge_enabled']) ? 1 : 0,
                'stripe_customer_id'       => isset($_POST['stripe_customer_id']) ? sanitize_text_field(wp_unslash($_POST['stripe_customer_id'])) : '',
                'stripe_payment_method_id' => isset($_POST['stripe_payment_method_id']) ? sanitize_text_field(wp_unslash($_POST['stripe_payment_method_id'])) : '',
                // Ім'я партнера для відображення в листах ({partner_name})
                'partner_display_name'     => isset($_POST['partner_display_name']) ? sanitize_text_field(wp_unslash($_POST['partner_display_name'])) : '',
                'email'                    => isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '',
                'allow_negative_balance'   => !empty($_POST['allow_negative_balance']) ? 1 : 0,
                'disable_low_balance_email' => !empty($_POST['disable_low_balance_email']) ? 1 : 0,
                'updated_at'               => self::now_est(),
            ];

            $wpdb->update(
                $wpdb->prefix . 'leadrouter_partner_billing',
                $data,
                ['partner_id' => $partner_id],
                // lead_price, lead_price_saturday, lead_price_sunday — %f (NULL зберігається як NULL)
                ['%f', '%f', '%f', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s'],
                ['%d']
            );

            LR_Billing::log_audit([
                'partner_id'  => $partner_id,
                'actor_type'  => 'admin',
                'actor_id'    => get_current_user_id(),
                'action'      => 'settings_updated',
                'entity_type' => 'partner_billing',
                'entity_id'   => (int)$before['id'],
                'old_value'   => [
                    'lead_price'                => (float)$before['lead_price'],
                    'lead_price_saturday'       => ($before['lead_price_saturday'] ?? null) !== null ? (float)$before['lead_price_saturday'] : null,
                    'lead_price_sunday'         => ($before['lead_price_sunday'] ?? null) !== null ? (float)$before['lead_price_sunday'] : null,
                    'min_balance'               => (float)$before['min_balance'],
                    'auto_charge_amount'        => (float)$before['auto_charge_amount'],
                    'auto_charge_enabled'       => (int)$before['auto_charge_enabled'],
                    'partner_display_name'      => (string)($before['partner_display_name'] ?? ''),
                    'email'                     => (string)($before['email'] ?? ''),
                    'allow_negative_balance'    => (int)($before['allow_negative_balance'] ?? 0),
                    'disable_low_balance_email' => (int)($before['disable_low_balance_email'] ?? 0),
                ],
                'new_value'   => [
                    'lead_price'                => $data['lead_price'],
                    'lead_price_saturday'       => $data['lead_price_saturday'],
                    'lead_price_sunday'         => $data['lead_price_sunday'],
                    'min_balance'               => $data['min_balance'],
                    'auto_charge_amount'        => $data['auto_charge_amount'],
                    'auto_charge_enabled'       => $data['auto_charge_enabled'],
                    'partner_display_name'      => $data['partner_display_name'],
                    'email'                     => $data['email'],
                    'allow_negative_balance'    => $data['allow_negative_balance'],
                    'disable_low_balance_email' => $data['disable_low_balance_email'],
                ],
            ]);

            // Білінговий email — основне джерело акаунта кабінету, а він зберігається
            // саме тут (AJAX), а не через save_post. Тож після збереження пробуємо
            // ідемпотентно провізіонінг: якщо email зʼявився/змінився і user ще не
            // привʼязаний — акаунт створиться. Дубль не плодимо (метод сам це перевіряє).
            if (class_exists('LR_Partner_Auth')) {
                LR_Partner_Auth::maybe_provision_on_save($partner_id);
            }

            wp_send_json_success([
                'message'     => __('Settings saved', 'leadrouter'),
                'status_html' => self::status_line_html($partner_id),
            ]);
        }

        public static function ajax_resolve_error()
        {
            global $wpdb;
            self::ajax_guard();

            $error_id = isset($_POST['error_id']) ? absint($_POST['error_id']) : 0;
            if ($error_id <= 0) {
                wp_send_json_error(['message' => __('Invalid error ID', 'leadrouter')], 400);
            }

            $resolved_at = self::now_est();
            $ok = $wpdb->update(
                $wpdb->prefix . 'leadrouter_billing_errors',
                ['is_resolved' => 1, 'resolved_at' => $resolved_at],
                ['id' => $error_id],
                ['%d', '%s'],
                ['%d']
            );

            if ($ok === false) {
                wp_send_json_error(['message' => __('Failed to update record', 'leadrouter')], 500);
            }
            wp_send_json_success(['resolved_at' => $resolved_at]);
        }

        /** Ручна реактивація партнера адміном */
        public static function ajax_reactivate_partner()
        {
            self::ajax_guard();
            $partner_id = isset($_POST['partner_id']) ? absint($_POST['partner_id']) : 0;
            if ($partner_id <= 0) {
                wp_send_json_error(['message' => __('Invalid partner ID', 'leadrouter')], 400);
            }

            // Уже активний — нічого не робимо, лише повертаємо свіжий статус
            if (!LR_Billing::is_deactivated_by_billing($partner_id)) {
                wp_send_json_success([
                    'message'     => __('Partner is already active', 'leadrouter'),
                    'status_html' => self::status_line_html($partner_id),
                ]);
            }

            // reactivate_partner ставить active=1, deactivated_by_billing=0
            // і скидає notified_charge_failed.
            $ok = LR_Billing::reactivate_partner($partner_id, 'manual_admin');
            if (!$ok) {
                wp_send_json_error(['message' => __('Reactivation failed', 'leadrouter')], 500);
            }

            // Дзеркало авто-реактивації крона: скидаємо РЕШТУ прапорців сповіщень,
            // щоб цикл листів (low_balance / stopped / admin_negative) міг початися заново.
            LR_Billing::reset_notification_flag($partner_id, 'notified_low_balance');
            LR_Billing::reset_notification_flag($partner_id, 'notified_stopped');
            LR_Billing::reset_notification_flag($partner_id, 'notified_admin_negative');

            // Окремий audit з реальним actor=admin (reactivate_partner пише actor_type=system)
            LR_Billing::log_audit([
                'partner_id'   => $partner_id,
                'actor_type'   => 'admin',
                'actor_id'     => get_current_user_id(),
                'action'       => 'partner_reactivated_manual',
                'entity_type'  => 'partner_billing',
                'context_json' => ['source' => 'admin_button'],
            ]);

            wp_send_json_success([
                'message'     => __('Partner reactivated', 'leadrouter'),
                'status_html' => self::status_line_html($partner_id),
            ]);
        }

        /** Перезавантаження однієї секції історії (пагінація/фільтр) */
        public static function ajax_load_history()
        {
            self::ajax_guard();
            $partner_id = isset($_POST['partner_id']) ? absint($_POST['partner_id']) : 0;
            $section    = isset($_POST['section']) ? sanitize_key($_POST['section']) : '';
            $paged      = isset($_POST['paged']) ? max(1, absint($_POST['paged'])) : 1;
            $type       = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';

            if ($partner_id <= 0) {
                wp_send_json_error(['message' => __('Invalid partner ID', 'leadrouter')], 400);
            }

            switch ($section) {
                case 'tx':
                    $html = self::render_tx_section($partner_id, $paged, $type);
                    break;
                case 'audit':
                    $html = self::render_audit_section($partner_id, $paged);
                    break;
                case 'errors':
                    $html = self::render_errors_section($partner_id, $paged);
                    break;
                default:
                    wp_send_json_error(['message' => __('Unknown section', 'leadrouter')], 400);
            }

            wp_send_json_success(['html' => $html]);
        }

        /* ============================================================
         * Допоміжні методи
         * ============================================================ */
        private static function ajax_guard()
        {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }
            check_ajax_referer(self::NONCE, 'nonce');
        }

        /** Отримати білінг-рядок партнера; створити дефолтний, якщо немає */
        private static function get_or_create_billing_row(int $partner_id): array
        {
            global $wpdb;
            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';

            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$t_billing} WHERE partner_id = %d LIMIT 1", $partner_id),
                ARRAY_A
            );
            if ($row) {
                return $row;
            }

            $wpdb->insert(
                $t_billing,
                ['partner_id' => $partner_id, 'balance' => 0.00, 'currency' => 'USD',
                 'status' => 'active', 'created_at' => self::now_est()],
                ['%d', '%f', '%s', '%s', '%s']
            );

            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$t_billing} WHERE partner_id = %d LIMIT 1", $partner_id),
                ARRAY_A
            ) ?: [];
        }

        /**
         * Парсинг ціни вихідного дня з POST.
         *   - порожнє або 0 → null (не задано, fallback на weekday-ціну);
         *   - > 0           → float (округлено до 2 знаків);
         *   - < 0           → false (помилка валідації).
         *
         * @return float|null|false
         */
        private static function parse_weekend_price($raw)
        {
            $raw = is_string($raw) ? trim(wp_unslash($raw)) : $raw;
            if ($raw === '' || $raw === null) {
                return null;
            }
            $val = round((float)$raw, 2);
            if ($val < 0) {
                return false; // від'ємне — невалідне
            }
            if ($val == 0.0) {
                return null;  // 0 = не задано (НЕ «безкоштовно»)
            }
            return $val;
        }

        private static function get_currency(int $partner_id): string
        {
            global $wpdb;
            $cur = $wpdb->get_var($wpdb->prepare(
                "SELECT currency FROM {$wpdb->prefix}leadrouter_partner_billing WHERE partner_id = %d LIMIT 1",
                $partner_id
            ));
            return $cur ?: 'USD';
        }

        private static function now_est(): string
        {
            return (new DateTimeImmutable('now', new DateTimeZone('America/New_York')))->format('Y-m-d H:i:s');
        }

        /* ============================================================
         * Inline CSS / JS
         * ============================================================ */
        private static function inline_css(): string
        {
            return '
            .lr-billing-wrap .lr-bil-card { background:#fff; border:1px solid #ccd0d4; border-radius:4px; padding:12px 18px; margin:0 0 16px; }
            .lr-billing-wrap .lr-bil-2col { display:grid; grid-template-columns:1fr 1fr; gap:20px; align-items:start; }
            @media (max-width:1100px){ .lr-billing-wrap .lr-bil-2col { grid-template-columns:1fr; } }
            .lr-billing-wrap .lr-bil-2col .lr-bil-card { margin:0; }
            .lr-billing-wrap .lr-status-line { margin:0 0 14px; font-size:13px; }
            .lr-billing-wrap .lr-manual-item { margin:0; }
            .lr-billing-wrap .lr-manual-box hr { margin:16px 0; }
            .lr-billing-wrap .lr-balance { font-size:18px; }
            .lr-billing-wrap .lr-balance-low { color:#d63638; }
            .lr-billing-wrap .lr-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:12px; font-weight:600; color:#fff; }
            .lr-billing-wrap .lr-badge--active { background:#0b8a31; }
            .lr-billing-wrap .lr-badge--low { background:#dfc30d; color:#3a3a00; }
            .lr-billing-wrap .lr-badge--deactivated { background:#d63638; }
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
            .lr-billing-wrap .lr-op-msg { margin-left:8px; font-weight:600; }
            .lr-billing-wrap .lr-op-msg.ok { color:#0b8a31; }
            .lr-billing-wrap .lr-op-msg.err { color:#d63638; }
            .lr-billing-wrap .lr-filter-line { margin:0 0 10px; }
            ';
        }

        private static function inline_js(): string
        {
            return <<<'JS'
(function ($) {
    function post(data, done, fail) {
        data.nonce = LRBilling.nonce;
        $.post(LRBilling.ajaxurl, data).done(done).fail(fail || function () {});
    }
    function setMsg($el, cls, text) { $el.removeClass('ok err').addClass(cls).text(text); }

    $(function () {
        var $doc = $(document);

        // Збереження налаштувань
        $doc.on('click', '.lr-save-settings', function () {
            var $box = $('#lr-settings-box'), $msg = $box.find('.lr-settings-msg');
            setMsg($msg, '', '…');
            post({
                action: 'lr_save_billing_settings',
                partner_id: $box.data('partner'),
                lead_price: $box.find('[name=lr_bil_lead_price]').val(),
                lead_price_saturday: $box.find('[name=lr_bil_lead_price_saturday]').val(),
                lead_price_sunday: $box.find('[name=lr_bil_lead_price_sunday]').val(),
                min_balance: $box.find('[name=lr_bil_min_balance]').val(),
                auto_charge_amount: $box.find('[name=lr_bil_auto_charge_amount]').val(),
                auto_charge_enabled: $box.find('[name=lr_bil_auto_charge_enabled]').is(':checked') ? 1 : 0,
                stripe_customer_id: $box.find('[name=lr_bil_stripe_customer_id]').val(),
                stripe_payment_method_id: $box.find('[name=lr_bil_stripe_payment_method_id]').val(),
                partner_display_name: $box.find('[name=lr_bil_partner_display_name]').val(),
                email: $box.find('[name=lr_bil_email]').val(),
                allow_negative_balance: $box.find('[name=lr_bil_allow_negative]').is(':checked') ? 1 : 0,
                disable_low_balance_email: $box.find('[name=lr_bil_disable_low_email]').is(':checked') ? 1 : 0
            }, function (r) {
                if (r && r.success) {
                    setMsg($msg, 'ok', r.data.message || 'OK');
                    if (r.data.status_html) { $('#lr-status-line').html(r.data.status_html); }
                } else { setMsg($msg, 'err', (r && r.data && r.data.message) || 'Error'); }
            }, function () { setMsg($msg, 'err', 'Network error'); });
        });

        // Manual debit / topup
        $doc.on('click', '.lr-do-debit, .lr-do-topup', function () {
            var isDebit = $(this).hasClass('lr-do-debit');
            var $item = $(this).closest('.lr-manual-item');
            var $msg = $item.find('.lr-op-msg');
            var data = {
                action: isDebit ? 'lr_manual_debit' : 'lr_manual_topup',
                partner_id: $item.closest('.lr-manual-box').data('partner'),
                amount: $item.find('[name=lr_bil_amount]').val()
            };
            if (isDebit) { data.reason = $item.find('[name=lr_bil_reason]').val(); }
            else { data.note = $item.find('[name=lr_bil_note]').val(); }

            setMsg($msg, '', '…');
            post(data, function (r) {
                if (r && r.success) {
                    setMsg($msg, 'ok', 'OK');
                    var bal = parseFloat(r.data.balance_after);
                    var $bal = $('#lr-current-balance');
                    var cur = ($bal.text().split(' ')[1] || '');
                    $bal.text(bal.toFixed(2) + ' ' + cur);
                    if (r.data.status_html) { $('#lr-status-line').html(r.data.status_html); }
                    if (r.data.row_html) { $('#lr-tx-tbody').prepend(r.data.row_html); }
                    $item.find('[name=lr_bil_amount], [name=lr_bil_reason], [name=lr_bil_note]').val('');
                } else { setMsg($msg, 'err', (r && r.data && r.data.message) || 'Error'); }
            }, function () { setMsg($msg, 'err', 'Network error'); });
        });

        // Ручна реактивація партнера
        $doc.on('click', '.lr-reactivate', function () {
            var $btn = $(this), $msg = $('.lr-reactivate-msg');
            if (!window.confirm('Reactivate this partner now?')) { return; }
            setMsg($msg, '', '…');
            post({ action: 'lr_reactivate_partner', partner_id: $btn.data('partner') }, function (r) {
                if (r && r.success) {
                    if (r.data.status_html) { $('#lr-status-line').html(r.data.status_html); }
                } else { setMsg($msg, 'err', (r && r.data && r.data.message) || 'Error'); }
            }, function () { setMsg($msg, 'err', 'Network error'); });
        });

        // Розкриття JSON-деталей audit
        $doc.on('click', '.lr-audit-toggle', function () {
            var $btn = $(this), $d = $btn.closest('tr').next('.lr-audit-details');
            var open = $d.is(':visible');
            $d.toggle(!open);
            $btn.attr('aria-expanded', !open).text(open ? '▶' : '▼');
        });

        // Resolve помилки
        $doc.on('click', '.lr-resolve-btn', function () {
            var $btn = $(this), $msg = $btn.siblings('.lr-op-msg');
            setMsg($msg, '', '…');
            post({ action: 'lr_resolve_billing_error', error_id: $btn.data('error-id') }, function (r) {
                if (r && r.success) {
                    var $tr = $btn.closest('tr');
                    $tr.find('.lr-err-resolved').text('✅ ' + (r.data.resolved_at || ''));
                    $btn.replaceWith('—');
                } else { setMsg($msg, 'err', (r && r.data && r.data.message) || 'Error'); }
            }, function () { setMsg($msg, 'err', 'Network error'); });
        });

        // Пагінація історії
        $doc.on('click', '.lr-hist-page', function () {
            var $btn = $(this);
            loadHistory($btn.data('section'), $btn.data('paged'), $btn.data('type') || '');
        });

        // Фільтр транзакцій по типу
        $doc.on('change', '.lr-hist-filter', function () {
            loadHistory($(this).data('section'), 1, $(this).val());
        });

        function loadHistory(section, paged, type) {
            var $container = $('#lr-hist-' + section);
            if (!$container.length) { return; }
            $container.css('opacity', 0.5);
            // partner_id беремо з data-partner обгортки вкладки історії
            var pid = $container.closest('.lr-billing-wrap').data('partner');
            post({
                action: 'lr_load_billing_history',
                partner_id: pid,
                section: section,
                paged: paged,
                type: type
            }, function (r) {
                $container.css('opacity', 1);
                if (r && r.success) { $container.html(r.data.html); }
            }, function () { $container.css('opacity', 1); });
        }
    });
})(jQuery);
JS;
        }
    }
}
