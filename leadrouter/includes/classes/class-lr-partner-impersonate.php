<?php
/**
 * LR_Partner_Impersonate — перегляд кабінету партнера з адмінки («Login as partner»).
 *
 * Флоу:
 *   1) Кнопка «View cabinet» на екрані партнера (лише manage_options) →
 *      handle_start(): перевіряє права+nonce, знаходить WP-user, привʼязаного
 *      до партнера (LR_Partner_Auth::get_user_id_for_partner), запамʼятовує
 *      поточного адміна в короткоживучому transient (ключ — випадковий токен
 *      у HttpOnly-кукі), підмінює auth-кукі на партнерського user і редіректить
 *      у кабінет.
 *   2) У кабінеті показується банер «Ви переглядаєте кабінет партнера X»
 *      (wp_footer) з посиланням «Повернутися в адмінку».
 *   3) handle_return(): читає токен із кукі, дістає transient з admin_id,
 *      повертає auth-кукі адміну, підчищає слід.
 *
 * Безпека:
 *   - Ініціювати може лише manage_options (перевіряється ДО підміни сесії).
 *   - «Хто був адміном» зберігається СЕРВЕРНО (transient), у кукі — лише
 *     випадковий токен-покажчик; підробити токен = вгадати 32-символьний
 *     випадковий рядок.
 *   - TTL сесії імперсонації — 2 години (transient), потім банер/повернення
 *     просто не спрацюють (кукі можна прибрати вручну).
 *   - Партнер, який зайшов сам (без імперсонації), кукі lr_impersonate_token
 *     не має — банер для нього ніколи не рендериться.
 *   - Кожен старт/кінець імперсонації — LR_Billing::log_audit().
 *
 * Час — EST (America/New_York), як і решта LeadRouter.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Partner_Impersonate')) {

    class LR_Partner_Impersonate
    {
        /** Nonce-action кнопки «View cabinet» */
        const NONCE_START = 'lr_admin_view_partner';

        /** Кукі з токеном-покажчиком на transient повернення */
        const COOKIE_NAME = 'lr_impersonate_token';

        /** Префікс ключа transient (значення — ['admin_id','user_id','partner_id']) */
        const TRANSIENT_PREFIX = 'lr_imp_';

        /** TTL сесії імперсонації, сек (2 години) */
        const TTL = 2 * HOUR_IN_SECONDS;

        /** Часова зона проекту */
        private const TZ = 'America/New_York';

        public static function register(): void
        {
            add_action('admin_post_lr_admin_view_partner',       [__CLASS__, 'handle_start']);
            add_action('admin_post_lr_admin_return_from_partner', [__CLASS__, 'handle_return']);
            // Банер показуємо і на фронті (кабінет partner-теми), і про всяк випадок в адмінці.
            add_action('wp_footer',    [__CLASS__, 'render_banner']);
            add_action('admin_footer', [__CLASS__, 'render_banner']);
        }

        /* ============================================================
         * Старт: адмін → «стає» партнером
         * ============================================================ */
        public static function handle_start(): void
        {
            check_admin_referer(self::NONCE_START);

            // Права перевіряємо ДО будь-якої підміни користувача.
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to do this.', 'leadrouter'), 403);
            }

            $partner_id = isset($_GET['partner_id']) ? absint($_GET['partner_id']) : 0;
            if ($partner_id <= 0
                || get_post_type($partner_id) !== 'leadrouter_partner'
                || get_post_status($partner_id) !== 'publish'
            ) {
                wp_die(esc_html__('Invalid partner.', 'leadrouter'), 404);
            }

            $target_user_id = class_exists('LR_Partner_Auth')
                ? LR_Partner_Auth::get_user_id_for_partner($partner_id)
                : 0;
            if ($target_user_id <= 0 || !get_userdata($target_user_id)) {
                wp_die(esc_html__('This partner has no linked cabinet account yet.', 'leadrouter'), 404);
            }

            $admin_id = get_current_user_id();

            // Токен-покажчик: сам admin_id у кукі НЕ зберігаємо, лише випадковий токен.
            $token = wp_generate_password(32, false, false);
            set_transient(self::TRANSIENT_PREFIX . $token, [
                'admin_id'   => $admin_id,
                'user_id'    => $target_user_id,
                'partner_id' => $partner_id,
            ], self::TTL);

            self::set_cookie($token, time() + self::TTL);

            // Підміна сесії на партнерського user.
            wp_clear_auth_cookie();
            wp_set_current_user($target_user_id);
            wp_set_auth_cookie($target_user_id, false);

            if (class_exists('LR_Billing')) {
                LR_Billing::log_audit([
                    'partner_id'   => $partner_id,
                    'actor_type'   => 'admin',
                    'actor_id'     => $admin_id,
                    'action'       => 'impersonate_start',
                    'entity_type'  => 'partner_billing',
                    'context_json' => ['target_user_id' => $target_user_id],
                ]);
            }

            $url = class_exists('LR_Partner_Auth') ? LR_Partner_Auth::cabinet_url() : home_url('/');
            wp_safe_redirect($url);
            exit;
        }

        /* ============================================================
         * Повернення: партнерська сесія → назад в адміна
         * ============================================================ */
        public static function handle_return(): void
        {
            $token = isset($_COOKIE[self::COOKIE_NAME]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME])) : '';
            $data  = $token !== '' ? get_transient(self::TRANSIENT_PREFIX . $token) : false;

            // Кукі прибираємо в будь-якому випадку — вона одноразова.
            self::set_cookie('', time() - HOUR_IN_SECONDS);

            if (!is_array($data) || empty($data['admin_id']) || empty($data['user_id'])) {
                wp_safe_redirect(admin_url());
                exit;
            }

            // Захист від чужого/протермінованого токена: поточний user має збігатись із збереженим.
            if (get_current_user_id() !== (int) $data['user_id']) {
                delete_transient(self::TRANSIENT_PREFIX . $token);
                wp_safe_redirect(admin_url());
                exit;
            }

            $admin_id   = (int) $data['admin_id'];
            $partner_id = (int) ($data['partner_id'] ?? 0);

            wp_clear_auth_cookie();
            wp_set_current_user($admin_id);
            wp_set_auth_cookie($admin_id, false);

            delete_transient(self::TRANSIENT_PREFIX . $token);

            if (class_exists('LR_Billing')) {
                LR_Billing::log_audit([
                    'partner_id'   => $partner_id,
                    'actor_type'   => 'admin',
                    'actor_id'     => $admin_id,
                    'action'       => 'impersonate_end',
                    'entity_type'  => 'partner_billing',
                    'context_json' => ['returned_from_user_id' => (int) $data['user_id']],
                ]);
            }

            $redirect = $partner_id > 0
                ? admin_url('post.php?post=' . $partner_id . '&action=edit')
                : admin_url('admin.php?page=leadrouter');
            wp_safe_redirect($redirect);
            exit;
        }

        /* ============================================================
         * Банер «Ви переглядаєте кабінет партнера X»
         * ============================================================ */
        public static function render_banner(): void
        {
            $token = isset($_COOKIE[self::COOKIE_NAME]) ? sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME])) : '';
            if ($token === '') {
                return;
            }
            $data = get_transient(self::TRANSIENT_PREFIX . $token);
            if (!is_array($data) || empty($data['user_id'])) {
                return;
            }
            // Банер показуємо лише поки реально залогінені під тим самим partner-user.
            if (get_current_user_id() !== (int) $data['user_id']) {
                return;
            }

            $partner_id   = (int) ($data['partner_id'] ?? 0);
            $partner_name = $partner_id > 0 ? get_the_title($partner_id) : '';
            $return_url   = wp_nonce_url(
                admin_url('admin-post.php?action=lr_admin_return_from_partner'),
                'lr_admin_return_from_partner'
            );

            echo '<div style="position:fixed;left:0;right:0;bottom:0;z-index:999999;background:#1d1f22;color:#fff;'
                . 'padding:10px 16px;text-align:center;font:14px/1.4 -apple-system,Segoe UI,Roboto,sans-serif;">'
                . esc_html(sprintf(
                    /* translators: %s: partner name */
                    __('You are viewing the cabinet as partner: %s.', 'leadrouter'),
                    $partner_name !== '' ? $partner_name : ('#' . $partner_id)
                ))
                . ' <a href="' . esc_url($return_url) . '" style="color:#fff;text-decoration:underline;font-weight:600;">'
                . esc_html__('Return to admin', 'leadrouter') . '</a>'
                . '</div>';
        }

        /* ============================================================
         * Хелпери
         * ============================================================ */

        /** Побудувати URL кнопки «View cabinet» для партнера (лише для manage_options). */
        public static function view_url(int $partner_id): string
        {
            return wp_nonce_url(
                admin_url('admin-post.php?action=lr_admin_view_partner&partner_id=' . $partner_id),
                self::NONCE_START
            );
        }

        /** HttpOnly-кукі з токеном (порожній $token + минулий $expire = видалення). */
        private static function set_cookie(string $token, int $expire): void
        {
            $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
            setcookie(self::COOKIE_NAME, $token, $expire, $path, $domain, is_ssl(), true);
            if ($token === '') {
                unset($_COOKIE[self::COOKIE_NAME]);
            } else {
                $_COOKIE[self::COOKIE_NAME] = $token;
            }
        }
    }
}
