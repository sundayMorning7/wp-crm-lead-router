<?php
/**
 * LR_Partner_Auth — звʼязок «партнер (CPT) ↔ WP-user» і керування доступом до кабінету.
 *
 * Відповідає за:
 *   - реєстрацію ролі `partner` (мінімальні права: лише `read`);
 *   - автостворення WP-user при збереженні партнера з контактним email;
 *   - двосторонній звʼязок: user_meta['leadrouter_partner_id'] ↔ post_meta['_leadrouter_linked_user_id'];
 *   - генерацію НОВОГО системного паролю + відправку листом (пароль ніде не зберігається у відкритому вигляді);
 *   - current_partner_id() — розвʼязання партнера ВИКЛЮЧНО із серверної сесії (для фронт-кабінету).
 *
 * Блок wp-admin/wp-login.php і фронтові форми — у класі LR_Partner_Portal (Фаза 2),
 * цей клас лише надає примітиви.
 *
 * Безпека: partner_id для кабінету береться лише з user_meta поточного користувача,
 * НІКОЛИ з GET/POST. Пароль зберігається лише як стандартний WP-хеш.
 *
 * Час — EST (America/New_York), як і решта LeadRouter.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Partner_Auth')) {

    class LR_Partner_Auth
    {
        /** Назва ролі партнера */
        const ROLE = 'partner';

        /** user_meta: id партнера-CPT, привʼязаного до користувача */
        const USER_META_PARTNER_ID = 'leadrouter_partner_id';

        /** post_meta: id WP-user, привʼязаного до партнера (дзеркало) */
        const POST_META_USER_ID = '_leadrouter_linked_user_id';

        /** post_meta: службові позначки статусу доступу */
        const POST_META_CREATED_AT = '_leadrouter_cabinet_user_created_at';
        const POST_META_PASSWORD_SENT_AT = '_leadrouter_cabinet_password_sent_at';

        /** option: id WP-сторінки кабінету (slug /partner) */
        const OPTION_PAGE_ID = 'lr_partner_cabinet_page_id';

        /** Часова зона проекту */
        private const TZ = 'America/New_York';

        /* ============================================================
         * Реєстрація хуків
         * ============================================================ */
        public static function register(): void
        {
            // Автостворення/звʼязок user при збереженні партнера.
            // Пріоритет 30 — після того, як Carbon Fields збереже мета (email).
            add_action('save_post_leadrouter_partner', [__CLASS__, 'maybe_provision_on_save'], 30, 1);
        }

        /* ============================================================
         * Роль `partner` (мінімальні права)
         * ============================================================ */
        /** Створити роль `partner`, якщо її ще немає. Викликається на активації/апґрейді. */
        public static function install_role(): void
        {
            if (!get_role(self::ROLE)) {
                add_role(self::ROLE, __('Partner', 'leadrouter'), ['read' => true]);
            }
        }

        /* ============================================================
         * Провізіонінг WP-user при збереженні партнера
         * ============================================================ */
        /**
         * Тригер: збереження партнера (CPT) із заповненим контактним email.
         * Якщо лінкованого user ще немає — створюємо WP-user (роль partner,
         * випадковий пароль; пароль НЕ доставляється тут — лише через кнопку в метабоксі).
         */
        public static function maybe_provision_on_save($post_id): void
        {
            $post_id = (int) $post_id;

            if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
                return;
            }
            if (get_post_type($post_id) !== 'leadrouter_partner') {
                return;
            }
            // Працюємо лише з опублікованими партнерами (чернетки/кошик — ні)
            if (get_post_status($post_id) !== 'publish') {
                return;
            }
            // Уже привʼязаний — нічого не робимо (зміну паролю робить окрема кнопка)
            if (self::get_user_id_for_partner($post_id) > 0) {
                return;
            }

            $email = self::partner_contact_email($post_id);
            if ($email === '' || !is_email($email)) {
                return;
            }

            self::provision_user($post_id, $email);
        }

        /**
         * Створити WP-user для партнера та звʼязати їх.
         * Пароль випадковий (зберігається лише хеш); доставка — окремою дією.
         *
         * @return int user_id або 0 при помилці
         */
        public static function provision_user(int $partner_id, string $email): int
        {
            // Якщо користувач із таким email вже існує — не плодимо дубль,
            // лише привʼязуємо (WP однаково забороняє дубль email).
            $existing = get_user_by('email', $email);
            if ($existing instanceof WP_User) {
                self::link($existing->ID, $partner_id);
                self::touch_created($partner_id);
                return (int) $existing->ID;
            }

            $login = self::unique_login_from_email($email);
            $password = wp_generate_password(20, true, true);

            // display_name: спершу білінговий partner_display_name, потім заголовок
            // поста, потім логін (узгоджено з привітанням у кабінеті та листами).
            $display_name = self::partner_display_name($partner_id);
            if ($display_name === '') {
                $display_name = get_the_title($partner_id) ?: $login;
            }

            $user_id = wp_insert_user([
                'user_login' => $login,
                'user_email' => $email,
                'user_pass'  => $password, // wp_insert_user сам хешує
                'role'       => self::ROLE,
                'display_name' => $display_name,
            ]);

            if (is_wp_error($user_id)) {
                if (class_exists('LR_Billing')) {
                    LR_Billing::log_error($partner_id, 'CABINET_USER_CREATE_FAILED', 'error',
                        'Не вдалося створити WP-user для партнера: ' . $user_id->get_error_message(),
                        ['email' => $email, 'source' => 'partner_auth']);
                }
                return 0;
            }

            self::link((int) $user_id, $partner_id);
            self::touch_created($partner_id);

            if (class_exists('LR_Billing')) {
                LR_Billing::log_audit([
                    'partner_id'   => $partner_id,
                    'actor_type'   => 'admin',
                    'actor_id'     => get_current_user_id(),
                    'action'       => 'cabinet_user_created',
                    'entity_type'  => 'partner_billing',
                    'context_json' => ['user_id' => (int) $user_id, 'login' => $login],
                ]);
            }

            return (int) $user_id;
        }

        /* ============================================================
         * Генерація нового паролю + лист (єдиний канал доставки)
         * ============================================================ */
        /**
         * Згенерувати новий системний пароль, оновити WP-user і надіслати листом.
         * Пароль ніде не зберігається у відкритому вигляді й не повертається назовні.
         *
         * Використовується і адмін-кнопкою, і фронтовим запитом партнера (Фаза 2).
         *
         * @param int    $partner_id
         * @param string $actor 'admin'|'partner' — лише для audit
         * @return true|WP_Error
         */
        public static function generate_and_send_password(int $partner_id, string $actor = 'admin')
        {
            $user_id = self::get_user_id_for_partner($partner_id);

            // Користувача ще немає — пробуємо створити, якщо є email
            if ($user_id <= 0) {
                $email = self::partner_contact_email($partner_id);
                if ($email === '' || !is_email($email)) {
                    return new WP_Error('no_email', __('Partner has no valid contact email.', 'leadrouter'));
                }
                $user_id = self::provision_user($partner_id, $email);
                if ($user_id <= 0) {
                    return new WP_Error('user_create_failed', __('Could not create cabinet user.', 'leadrouter'));
                }
            }

            $user = get_userdata($user_id);
            if (!$user) {
                return new WP_Error('no_user', __('Linked user not found.', 'leadrouter'));
            }

            // Новий системний пароль; wp_set_password оновлює хеш і розлогінює всі сесії
            $password = wp_generate_password(20, true, true);
            wp_set_password($password, $user_id);

            // Доставка — виключно листом
            $sent = false;
            if (class_exists('LR_Billing_Mailer')) {
                $sent = LR_Billing_Mailer::account_access(
                    $partner_id,
                    (string) $user->user_email,
                    (string) $user->user_login,
                    $password
                );
            }

            // Пароль більше не потрібен у памʼяті
            unset($password);

            if (!$sent) {
                return new WP_Error('mail_failed', __('Password was reset but the email could not be sent.', 'leadrouter'));
            }

            update_post_meta($partner_id, self::POST_META_PASSWORD_SENT_AT, self::now());

            if (class_exists('LR_Billing')) {
                LR_Billing::log_audit([
                    'partner_id'   => $partner_id,
                    'actor_type'   => $actor === 'partner' ? 'partner' : 'admin',
                    'actor_id'     => get_current_user_id(),
                    'action'       => 'cabinet_password_reset',
                    'entity_type'  => 'partner_billing',
                    'context_json' => ['user_id' => $user_id, 'channel' => 'email'],
                ]);
            }

            return true;
        }

        /* ============================================================
         * Звʼязок user ↔ partner
         * ============================================================ */
        /** Встановити двосторонній звʼязок */
        public static function link(int $user_id, int $partner_id): void
        {
            update_user_meta($user_id, self::USER_META_PARTNER_ID, $partner_id);
            update_post_meta($partner_id, self::POST_META_USER_ID, $user_id);
        }

        /** partner_id, привʼязаний до користувача (0 якщо немає) */
        public static function get_partner_id_for_user(int $user_id): int
        {
            $pid = (int) get_user_meta($user_id, self::USER_META_PARTNER_ID, true);
            return $pid > 0 ? $pid : 0;
        }

        /** user_id, привʼязаний до партнера (0 якщо немає). З фолбеком на пошук по user_meta. */
        public static function get_user_id_for_partner(int $partner_id): int
        {
            $uid = (int) get_post_meta($partner_id, self::POST_META_USER_ID, true);
            if ($uid > 0 && get_userdata($uid)) {
                return $uid;
            }

            // Дзеркало могло загубитись — шукаємо по user_meta
            $users = get_users([
                'meta_key'   => self::USER_META_PARTNER_ID,
                'meta_value' => $partner_id,
                'number'     => 1,
                'fields'     => 'ID',
            ]);
            return $users ? (int) $users[0] : 0;
        }

        /**
         * partner_id поточного користувача — ЄДИНЕ джерело правди для фронт-кабінету.
         * Ніколи не читаємо partner_id з GET/POST.
         *
         * Повертає id лише якщо: користувач залогінений, має валідний leadrouter_partner_id,
         * і цей партнер — опублікований CPT leadrouter_partner. Інакше 0.
         *
         * Примітка: деактивований білінгом партнер усе одно заходить (пост лишається publish;
         * deactivated_by_billing — окремий прапорець, не статус поста).
         */
        public static function current_partner_id(): int
        {
            if (!is_user_logged_in()) {
                return 0;
            }
            $pid = self::get_partner_id_for_user(get_current_user_id());
            if ($pid <= 0) {
                return 0;
            }
            if (get_post_type($pid) !== 'leadrouter_partner') {
                return 0;
            }
            if (get_post_status($pid) !== 'publish') {
                return 0;
            }
            return $pid;
        }

        /* ============================================================
         * Хелпери
         * ============================================================ */

        /** URL сторінки кабінету (за збереженим page_id; фолбек на /partner/) */
        public static function cabinet_url(): string
        {
            $page_id = (int) get_option(self::OPTION_PAGE_ID);
            if ($page_id > 0) {
                $url = get_permalink($page_id);
                if ($url) {
                    return $url;
                }
            }
            return home_url('/partner/');
        }

        /**
         * Контактний email партнера для акаунта кабінету.
         *
         * Основне джерело — БІЛІНГОВИЙ email (колонка `email` у
         * {prefix}leadrouter_partner_billing, редагується на вкладці
         * Billing → Partner email). Fallback — Carbon-поле leadrouter_partner_email
         * («Тех інфо → Email»), лише якщо білінговий порожній/невалідний,
         * щоб не зламати вже привʼязані акаунти.
         */
        public static function partner_contact_email(int $partner_id): string
        {
            global $wpdb;

            // 1) Білінговий email (пріоритет)
            $billing_email = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT email FROM {$wpdb->prefix}leadrouter_partner_billing WHERE partner_id = %d",
                    $partner_id
                )
            );
            $billing_email = trim($billing_email);
            if ($billing_email !== '' && is_email($billing_email)) {
                return $billing_email;
            }

            // 2) Fallback: контактний email із «Тех інфо» (Carbon leadrouter_partner_email)
            $email = '';
            if (function_exists('carbon_get_post_meta')) {
                $email = (string) carbon_get_post_meta($partner_id, 'leadrouter_partner_email');
            }
            if ($email === '') {
                $email = (string) get_post_meta($partner_id, '_leadrouter_partner_email', true);
            }
            return trim($email);
        }

        /**
         * Ім'я партнера з білінг-профілю (partner_display_name).
         * Порожній рядок, якщо не задано — caller вирішує фолбек.
         */
        public static function partner_display_name(int $partner_id): string
        {
            global $wpdb;
            $name = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT partner_display_name FROM {$wpdb->prefix}leadrouter_partner_billing WHERE partner_id = %d",
                    $partner_id
                )
            );
            return trim($name);
        }

        /** Дата створення user (для статусу в метабоксі) */
        public static function created_at(int $partner_id): string
        {
            return (string) get_post_meta($partner_id, self::POST_META_CREATED_AT, true);
        }

        /** Дата останньої відправки паролю (для статусу в метабоксі) */
        public static function password_sent_at(int $partner_id): string
        {
            return (string) get_post_meta($partner_id, self::POST_META_PASSWORD_SENT_AT, true);
        }

        /** Зафіксувати момент створення user (якщо ще не зафіксовано) */
        private static function touch_created(int $partner_id): void
        {
            if (self::created_at($partner_id) === '') {
                update_post_meta($partner_id, self::POST_META_CREATED_AT, self::now());
            }
        }

        /** Унікальний логін на основі email (email або email-2, email-3, ...) */
        private static function unique_login_from_email(string $email): string
        {
            $base  = $email;
            $login = $base;
            $i     = 2;
            while (username_exists($login)) {
                $login = $base . '-' . $i;
                $i++;
            }
            return $login;
        }

        /** Поточний час у EST у форматі MySQL */
        private static function now(): string
        {
            return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d H:i:s');
        }
    }
}
