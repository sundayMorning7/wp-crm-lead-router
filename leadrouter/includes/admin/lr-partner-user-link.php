<?php
/**
 * LR_Partner_User_Link — метабокс «Доступ до кабінету» на екрані партнера.
 *
 * Показує логін (email), статус доступу (створено / пароль надіслано) і кнопки:
 *   - «Згенерувати новий пароль і надіслати партнеру» — генерує системний пароль,
 *     оновлює WP-user і шле лист (пароль НІДЕ не показується);
 *   - «Відкрити кабінет» — посилання на фронтову сторінку /partner.
 *
 * Усі дії — через AJAX з nonce і перевіркою manage_options.
 * Логіка провізіонінгу/паролю — у LR_Partner_Auth.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Partner_User_Link')) {

    class LR_Partner_User_Link
    {
        /** Nonce-action для AJAX */
        const NONCE = 'lr_partner_user_link';

        public static function register(): void
        {
            add_action('add_meta_boxes', [__CLASS__, 'add_box']);
            add_action('wp_ajax_lr_partner_gen_password', [__CLASS__, 'ajax_gen_password']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        }

        /** Реєстрація метабокса на екрані партнера (бічна колонка) */
        public static function add_box(): void
        {
            add_meta_box(
                'lr_partner_user_link',
                __('Доступ до кабінету', 'leadrouter'),
                [__CLASS__, 'render_box'],
                'leadrouter_partner',
                'side',
                'high'
            );
        }

        /** JS лише на екрані редагування партнера */
        public static function enqueue_assets($hook): void
        {
            if ($hook !== 'post.php' && $hook !== 'post-new.php') {
                return;
            }
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== 'leadrouter_partner') {
                return;
            }

            wp_register_script('lr-partner-user-link', '', ['jquery'], LEADROUTER_VERSION, true);
            wp_enqueue_script('lr-partner-user-link');
            wp_localize_script('lr-partner-user-link', 'LRPartnerLink', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE),
                'confirm' => __('Generate a new password and email it to the partner now?', 'leadrouter'),
            ]);
            wp_add_inline_script('lr-partner-user-link', self::inline_js());
        }

        /** Тіло метабокса */
        public static function render_box(WP_Post $post): void
        {
            $partner_id = (int) $post->ID;

            // На новому, ще не збереженому партнері — лише підказка
            if (get_post_status($partner_id) !== 'publish') {
                echo '<p class="description">'
                    . esc_html__('Save the partner with a contact email — a cabinet account will be created automatically.', 'leadrouter')
                    . '</p>';
                return;
            }

            $user_id = LR_Partner_Auth::get_user_id_for_partner($partner_id);
            $email   = LR_Partner_Auth::partner_contact_email($partner_id);

            echo '<div id="lr-partner-link" data-partner="' . (int) $partner_id . '">';

            if ($user_id > 0) {
                $user = get_userdata($user_id);
                echo '<p><strong>' . esc_html__('Login:', 'leadrouter') . '</strong><br>'
                    . esc_html($user ? $user->user_login : ('#' . $user_id)) . '</p>';
                echo self::status_html($partner_id);

                echo '<p>'
                    . '<button type="button" class="button button-primary lr-gen-pass">'
                    . esc_html__('Generate new password & send', 'leadrouter') . '</button> '
                    . '<span class="lr-link-msg" style="font-weight:600;"></span></p>';

                // Якщо доступна імперсонація (manage_options) — кнопка одразу логінить
                // адміна під цього партнера (LR_Partner_Impersonate). Інакше — фолбек
                // на звичайне посилання на кабінет (без логіну).
                // Роль leadrouter_manager має manage_options лише в контексті LeadRouter,
                // але екшен імперсонації їй не дозволений — тож віддаємо їй фолбек, а не 403.
                $can_impersonate = class_exists('LR_Partner_Impersonate')
                    && current_user_can('manage_options')
                    && !(class_exists('LeadRouter_Restricted_Access') && LeadRouter_Restricted_Access::is_manager());

                $open_url = $can_impersonate
                    ? LR_Partner_Impersonate::view_url($partner_id)
                    : LR_Partner_Auth::cabinet_url();

                echo '<p><a class="button button-secondary" href="'
                    . esc_url($open_url) . '">'
                    . esc_html__('Open cabinet', 'leadrouter') . '</a></p>';
            } else {
                // Партнер опублікований, але user ще не створено (немає валідного email)
                if ($email === '' || !is_email($email)) {
                    echo '<p class="description">'
                        . esc_html__('Set a valid Billing → Partner email and save — the account will be created.', 'leadrouter')
                        . '</p>';
                } else {
                    echo '<p class="description">'
                        . esc_html__('Account not created yet. Click below to create it and send credentials.', 'leadrouter')
                        . '</p>';
                    echo '<p>'
                        . '<button type="button" class="button button-primary lr-gen-pass">'
                        . esc_html__('Create account & send password', 'leadrouter') . '</button> '
                        . '<span class="lr-link-msg" style="font-weight:600;"></span></p>';
                }
            }

            echo '</div>';
        }

        /** Рядки статусу: коли створено / коли надіслано пароль */
        public static function status_html(int $partner_id): string
        {
            $created = LR_Partner_Auth::created_at($partner_id);
            $sent    = LR_Partner_Auth::password_sent_at($partner_id);

            $out  = '<p class="description lr-link-status">';
            $out .= esc_html__('Status:', 'leadrouter') . ' ';
            if ($created !== '') {
                $out .= '<br>' . esc_html__('Created:', 'leadrouter') . ' ' . esc_html($created);
            }
            if ($sent !== '') {
                $out .= '<br>' . esc_html__('Password sent:', 'leadrouter') . ' ' . esc_html($sent);
            } else {
                $out .= '<br>' . esc_html__('Password not sent yet.', 'leadrouter');
            }
            $out .= '</p>';
            return $out;
        }

        /* ============================================================
         * AJAX: згенерувати новий пароль і надіслати
         * ============================================================ */
        public static function ajax_gen_password(): void
        {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'forbidden'], 403);
            }
            check_ajax_referer(self::NONCE, 'nonce');

            $partner_id = isset($_POST['partner_id']) ? absint($_POST['partner_id']) : 0;
            if ($partner_id <= 0 || get_post_type($partner_id) !== 'leadrouter_partner') {
                wp_send_json_error(['message' => __('Invalid partner', 'leadrouter')], 400);
            }

            $res = LR_Partner_Auth::generate_and_send_password($partner_id, 'admin');
            if (is_wp_error($res)) {
                wp_send_json_error(['message' => $res->get_error_message()], 400);
            }

            wp_send_json_success([
                'message'     => __('Password sent to partner.', 'leadrouter'),
                'status_html' => self::status_html($partner_id),
            ]);
        }

        /* ============================================================
         * Inline JS
         * ============================================================ */
        private static function inline_js(): string
        {
            return <<<'JS'
(function ($) {
    $(function () {
        $(document).on('click', '#lr-partner-link .lr-gen-pass', function () {
            var $btn = $(this);
            var $box = $('#lr-partner-link');
            var $msg = $box.find('.lr-link-msg');
            if (!window.confirm(LRPartnerLink.confirm)) { return; }
            $btn.prop('disabled', true);
            $msg.css('color', '').text('…');
            $.post(LRPartnerLink.ajaxurl, {
                action: 'lr_partner_gen_password',
                partner_id: $box.data('partner'),
                nonce: LRPartnerLink.nonce
            }).done(function (r) {
                $btn.prop('disabled', false);
                if (r && r.success) {
                    $msg.css('color', '#0b8a31').text(r.data.message || 'OK');
                    if (r.data.status_html) { $box.find('.lr-link-status').replaceWith(r.data.status_html); }
                } else {
                    $msg.css('color', '#d63638').text((r && r.data && r.data.message) || 'Error');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $msg.css('color', '#d63638').text('Network error');
            });
        });
    });
})(jQuery);
JS;
        }
    }
}
