<?php
/**
 * LR_Partner_Portal — фронтовий кабінет партнера: shortcode [lr_partner_cabinet],
 * авторизація на фронті (вхід/вихід/зміна паролю) і блокування wp-admin/wp-login.php.
 *
 * Фаза 2 (цей етап) реалізує:
 *   - роль `partner` не має доступу до wp-admin і wp-login.php → редірект на кабінет;
 *   - прихований admin-bar для партнера;
 *   - фронтові форми: вхід (wp_signon), вихід (wp_logout), запит зміни паролю
 *     (авто-генерація нового системного паролю + лист через LR_Partner_Auth);
 *   - автостворення WP-сторінки кабінету (slug /partner) із shortcode.
 *
 * Секції кабінету (баланс/транзакції/ліди/картка) додаються у Фазах 3–6 —
 * тут render_cabinet() поки що показує мінімальну заглушку для залогіненого партнера.
 *
 * Безпека: partner_id береться лише з LR_Partner_Auth::current_partner_id()
 * (серверна сесія), усі дії — з nonce. Час — EST.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Partner_Portal')) {

    class LR_Partner_Portal
    {
        /** Рядків на сторінку в таблицях кабінету */
        const PER_PAGE = 20;

        /** Nonce-actions фронтових форм */
        const NONCE_LOGIN  = 'lr_partner_login';
        const NONCE_LOGOUT = 'lr_partner_logout';
        const NONCE_PASS   = 'lr_partner_change_pass';
        const NONCE_FORGOT = 'lr_partner_forgot';
        const NONCE_LEAD   = 'lr_partner_lead_details';

        /** Стеля рядків у Excel-експорті (запобіжник від OOM на великих історіях) */
        const EXPORT_MAX_ROWS = 20000;

        /** Slug сторінки кабінету */
        const PAGE_SLUG = 'partner';

        /* ============================================================
         * Реєстрація
         * ============================================================ */
        public static function register(): void
        {
            add_shortcode('lr_partner_cabinet', [__CLASS__, 'shortcode']);

            // Обробники форм (admin-post.php). Вхід доступний і незалогіненим.
            add_action('admin_post_nopriv_lr_partner_login', [__CLASS__, 'handle_login']);
            add_action('admin_post_lr_partner_login',        [__CLASS__, 'handle_login']);
            add_action('admin_post_lr_partner_logout',       [__CLASS__, 'handle_logout']);
            add_action('admin_post_lr_partner_change_pass',  [__CLASS__, 'handle_change_pass']);
            // «Забув пароль» — доступний незалогіненим (self-service reset)
            add_action('admin_post_nopriv_lr_partner_forgot', [__CLASS__, 'handle_forgot']);
            add_action('admin_post_lr_partner_forgot',        [__CLASS__, 'handle_forgot']);

            // Блокування wp-admin для партнера + прихований admin-bar
            add_action('admin_init', [__CLASS__, 'block_wp_admin']);
            add_filter('show_admin_bar', [__CLASS__, 'maybe_hide_admin_bar']);

            // Блокування wp-login.php для залогіненого партнера + правильний редірект після входу
            add_action('login_init', [__CLASS__, 'block_wp_login']);
            add_filter('login_redirect', [__CLASS__, 'login_redirect'], 10, 3);

            // Гарантуємо наявність сторінки кабінету (ідемпотентно, лише адмінка)
            add_action('admin_init', [__CLASS__, 'ensure_cabinet_page']);

            // UI-фундамент: окремий шаблон + Tabler лише на сторінці кабінету
            add_filter('template_include', [__CLASS__, 'cabinet_template']);
            // Пріоритет 100 — після enqueue теми (щоб dequeue спрацював)
            add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 100);

            // Excel-експорт відфільтрованих Leads/Transactions (?lr_export=...)
            add_action('template_redirect', [__CLASS__, 'maybe_export']);

            // Деталі ліда для модалки (клік по #id у таблиці транзакцій)
            add_action('wp_ajax_lr_partner_lead_details', [__CLASS__, 'ajax_lead_details']);
        }

        /* ============================================================
         * Чи поточний запит — сторінка кабінету
         * ============================================================ */
        private static function is_cabinet_page(): bool
        {
            $pid = (int) get_option(LR_Partner_Auth::OPTION_PAGE_ID);
            if ($pid <= 0) {
                return false;
            }
            return is_page($pid);
        }

        /* ============================================================
         * Окремий мінімальний шаблон для сторінки кабінету
         * ============================================================ */
        public static function cabinet_template($template)
        {
            if (self::is_cabinet_page()) {
                $tpl = LEADROUTER_PLUGIN_DIR . 'includes/templates/partner-cabinet-template.php';
                if (file_exists($tpl)) {
                    return $tpl;
                }
            }
            return $template;
        }

        /* ============================================================
         * Asset'и Tabler — лише на сторінці кабінету, скоуплено під .lr-cabinet
         * ============================================================ */
        public static function enqueue_assets(): void
        {
            if (!self::is_cabinet_page()) {
                return;
            }

            $base = LEADROUTER_PLUGIN_URL . 'assets/tabler/';
            $ver  = LEADROUTER_VERSION;

            // Tabler core + іконки + бренд-тема (тема — після core, щоб перебивала)
            wp_enqueue_style('lr-tabler', $base . 'tabler.min.css', [], '1.4.0');
            wp_enqueue_style('lr-tabler-icons', $base . 'tabler-icons.min.css', [], '3.34.0');
            wp_enqueue_style('lr-cabinet-theme', $base . 'lr-cabinet-theme.css', ['lr-tabler'], $ver);

            // Tabler JS (без jQuery, у футері) + лоадери кабінету
            wp_enqueue_script('lr-tabler', $base . 'tabler.min.js', [], '1.4.0', true);
            wp_enqueue_script('lr-cabinet', $base . 'lr-cabinet.js', ['lr-tabler'], $ver, true);

            // Деталі ліда: модалка на вкладці транзакцій (клік по #id)
            wp_enqueue_script('lr-cabinet-leaddetails', $base . 'lr-cabinet-leaddetails.js', ['lr-tabler'], $ver, true);
            wp_localize_script('lr-cabinet-leaddetails', 'LRLeadDetails', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action'  => 'lr_partner_lead_details',
                'nonce'   => wp_create_nonce(self::NONCE_LEAD),
                'i18n'    => [
                    'loading' => __('Loading…', 'leadrouter'),
                    'error'   => __('Could not load lead details.', 'leadrouter'),
                ],
            ]);

            // Скарги на лід: модалка + AJAX-сабміт (nonce). partner_id — лише із сесії на сервері.
            wp_enqueue_script('lr-cabinet-complaints', $base . 'lr-cabinet-complaints.js', ['lr-tabler'], $ver, true);
            wp_localize_script('lr-cabinet-complaints', 'LRComplaints', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'action'  => LR_Complaints::AJAX_ACTION,
                'nonce'   => wp_create_nonce(LR_Complaints::NONCE),
                'i18n'    => [
                    'network_error' => __('Network error. Please try again.', 'leadrouter'),
                    'sent_badge'    => __('Complaint sent', 'leadrouter'),
                ],
            ]);

            // Прибираємо фронтові ассети теми (Webflow JS не потрібен у кабінеті;
            // Webflow CSS і так не вантажиться — шаблон не включає header.php теми).
            foreach (['md_webflow_js', 'md_main_js', 'md_datepicker', 'md_choices', 'md_maks', 'md_calc_js'] as $h) {
                wp_dequeue_script($h);
                wp_deregister_script($h);
            }
        }

        /* ============================================================
         * Чи поточний користувач — «чистий» партнер (роль partner, не адмін)
         * ============================================================ */
        private static function is_partner_user(): bool
        {
            if (!is_user_logged_in()) {
                return false;
            }
            $u = wp_get_current_user();
            if (user_can($u, 'manage_options')) {
                return false; // адміни не обмежуються
            }
            return in_array(LR_Partner_Auth::ROLE, (array) $u->roles, true);
        }

        /* ============================================================
         * Блокування wp-admin / wp-login.php / admin-bar
         * ============================================================ */
        /**
         * Партнера не пускаємо у wp-admin — редірект у кабінет.
         *
         * ВАЖЛИВО: admin-post.php і admin-ajax.php теж запускають хук admin_init
         * (admin-post.php: do_action('admin_init') ДО admin_post_{action}). Якщо їх
         * не пропустити, редірект спрацює раніше за наші ж хендлери форм
         * (вихід/зміна паролю) і вони ніколи не виконаються. Тому блокуємо лише
         * справжні екрани wp-admin, а службові ендпоінти лишаємо доступними.
         */
        public static function block_wp_admin(): void
        {
            if (wp_doing_ajax()) {
                return;
            }
            $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';
            if ($pagenow === 'admin-post.php' || $pagenow === 'admin-ajax.php') {
                return; // наші форми/AJAX ідуть саме сюди
            }
            if (self::is_partner_user()) {
                wp_safe_redirect(LR_Partner_Auth::cabinet_url());
                exit;
            }
        }

        /** Сховати admin-bar для партнера */
        public static function maybe_hide_admin_bar($show)
        {
            if (self::is_partner_user()) {
                return false;
            }
            return $show;
        }

        /**
         * wp-login.php: залогінений партнер → у кабінет.
         * Logout/незалогінені дії wp-login лишаємо адмінам без змін.
         */
        public static function block_wp_login(): void
        {
            $action = isset($_REQUEST['action']) ? sanitize_key($_REQUEST['action']) : '';
            if ($action === 'logout') {
                return; // штатний логаут не чіпаємо
            }
            if (self::is_partner_user()) {
                wp_safe_redirect(LR_Partner_Auth::cabinet_url());
                exit;
            }
        }

        /** Після входу через wp-login партнера ведемо в кабінет, а не в wp-admin */
        public static function login_redirect($redirect_to, $requested, $user)
        {
            if ($user instanceof WP_User
                && !user_can($user, 'manage_options')
                && in_array(LR_Partner_Auth::ROLE, (array) $user->roles, true)) {
                return LR_Partner_Auth::cabinet_url();
            }
            return $redirect_to;
        }

        /* ============================================================
         * Обробники фронтових форм
         * ============================================================ */
        /** Вхід: wp_signon за логіном+паролем */
        public static function handle_login(): void
        {
            check_admin_referer(self::NONCE_LOGIN);

            $login = isset($_POST['lr_login']) ? sanitize_text_field(wp_unslash($_POST['lr_login'])) : '';
            $pass  = isset($_POST['lr_pass']) ? (string) wp_unslash($_POST['lr_pass']) : '';

            if ($login === '' || $pass === '') {
                self::redirect_with('badlogin');
            }

            $user = wp_signon([
                'user_login'    => $login,
                'user_password' => $pass,
                'remember'      => !empty($_POST['lr_remember']),
            ], is_ssl());

            if (is_wp_error($user)) {
                // Узагальнена помилка — без розкриття, чи існує логін
                self::redirect_with('badlogin');
            }

            wp_set_current_user($user->ID);
            self::redirect_with('');
        }

        /** Вихід */
        public static function handle_logout(): void
        {
            check_admin_referer(self::NONCE_LOGOUT);
            wp_logout();
            self::redirect_with('logged_out');
        }

        /**
         * Запит партнера на зміну паролю → авто-генерація нового системного паролю + лист.
         * Партнер сам пароль не вводить (його ніхто не показує). Після зміни сесію скинуто.
         */
        public static function handle_change_pass(): void
        {
            check_admin_referer(self::NONCE_PASS);

            $partner_id = LR_Partner_Auth::current_partner_id();
            if ($partner_id <= 0) {
                self::redirect_with('badlogin');
            }

            $res = LR_Partner_Auth::generate_and_send_password($partner_id, 'partner');

            // wp_set_password всередині розлогінює сесії — після цього показуємо екран входу
            self::redirect_with(is_wp_error($res) ? 'pass_error' : 'pass_sent');
        }

        /**
         * «Забув пароль» (незалогінений self-service): за логіном/email знаходимо
         * партнера, генеруємо новий системний пароль і шлемо логін+пароль на пошту.
         *
         * Безпека:
         *   - скидаємо пароль ЛИШЕ користувачам із роллю partner (ніколи не адміну);
         *   - завжди узагальнене повідомлення (анти-енумерація: не розкриваємо, чи існує акаунт);
         *   - throttle (90с на ідентифікатор) проти спаму скидань на чужу пошту.
         */
        public static function handle_forgot(): void
        {
            check_admin_referer(self::NONCE_FORGOT);

            $id = isset($_POST['lr_identifier']) ? sanitize_text_field(wp_unslash($_POST['lr_identifier'])) : '';
            $id = trim($id);

            if ($id !== '') {
                // Throttle: одна спроба на ідентифікатор за 90с (також вирівнює тайминг)
                $throttle_key = 'lr_forgot_' . md5(strtolower($id));
                if (!get_transient($throttle_key)) {
                    set_transient($throttle_key, 1, 90);

                    $user = is_email($id) ? get_user_by('email', $id) : false;
                    if (!$user) {
                        $user = get_user_by('login', $id);
                    }

                    if ($user instanceof WP_User
                        && !user_can($user, 'manage_options')
                        && in_array(LR_Partner_Auth::ROLE, (array) $user->roles, true)) {
                        $pid = LR_Partner_Auth::get_partner_id_for_user((int) $user->ID);
                        if ($pid > 0 && get_post_status($pid) === 'publish') {
                            // Шле лист account_access (логін + новий пароль + посилання)
                            LR_Partner_Auth::generate_and_send_password($pid, 'partner');
                        }
                    }
                }
            }

            // Завжди однакова відповідь — не розкриваємо існування акаунта
            self::redirect_with('reset_requested');
        }

        /** Чи рядок — валідна дата у форматі Y-m-d */
        private static function valid_date(string $d): bool
        {
            if ($d === '') {
                return false;
            }
            $dt = DateTimeImmutable::createFromFormat('Y-m-d', $d);
            return $dt !== false && $dt->format('Y-m-d') === $d;
        }

        /** Дата + 1 день (для верхньої межі періоду «to» включно). Вхід — валідний Y-m-d. */
        private static function date_plus_one(string $d): string
        {
            return (new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d');
        }

        /** Редірект назад у кабінет із кодом повідомлення */
        private static function redirect_with(string $code): void
        {
            $url = LR_Partner_Auth::cabinet_url();
            if ($code !== '') {
                $url = add_query_arg('lr_msg', $code, $url);
            }
            wp_safe_redirect($url);
            exit;
        }

        /* ============================================================
         * Shortcode [lr_partner_cabinet]
         * ============================================================ */
        public static function shortcode($atts = [], $content = ''): string
        {
            $partner_id = LR_Partner_Auth::current_partner_id();

            ob_start();
            echo '<div class="lr-cabinet">';

            if ($partner_id <= 0) {
                // Екран авторизації — центрована картка Tabler
                $body = is_user_logged_in() ? self::no_access_html() : self::login_form_html();
                echo '<div class="lr-auth-wrap"><div class="lr-auth-card">';
                echo self::notice_html();
                echo $body;
                echo '</div></div>';
            } else {
                echo self::render_cabinet($partner_id);
            }

            echo '</div>';
            return (string) ob_get_clean();
        }

        /** Повідомлення за кодом lr_msg (екрановані) */
        private static function notice_html(): string
        {
            $code = isset($_GET['lr_msg']) ? sanitize_key($_GET['lr_msg']) : '';
            if ($code === '') {
                return '';
            }

            $map = [
                'badlogin'   => ['danger',  __('Invalid login or password.', 'leadrouter')],
                'logged_out' => ['success', __('You have been logged out.', 'leadrouter')],
                'pass_sent'  => ['success', __('A new password has been emailed to you. Use it to log in.', 'leadrouter')],
                'pass_error' => ['danger',  __('Could not send the new password. Please contact support.', 'leadrouter')],
                'reset_requested' => ['info', __('If an account exists for that login, your login and a new password have been emailed to you.', 'leadrouter')],
            ];
            if (!isset($map[$code])) {
                return '';
            }
            [$type, $text] = $map[$code];

            return '<div class="alert alert-' . esc_attr($type) . '" role="alert">' . esc_html($text) . '</div>';
        }

        /** Форма входу (Tabler card) */
        private static function login_form_html(): string
        {
            ob_start();
            ?>
            <div class="card card-md">
                <div class="card-body">
                    <h2 class="h2 text-center mb-4"><?php echo esc_html__('Partner Cabinet', 'leadrouter'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="lr_partner_login">
                        <?php wp_nonce_field(self::NONCE_LOGIN); ?>
                        <div class="mb-3">
                            <label class="form-label" for="lr_login"><?php echo esc_html__('Login', 'leadrouter'); ?></label>
                            <input type="text" class="form-control" id="lr_login" name="lr_login" autocomplete="username" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="lr_pass"><?php echo esc_html__('Password', 'leadrouter'); ?></label>
                            <input type="password" class="form-control" id="lr_pass" name="lr_pass" autocomplete="current-password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-check">
                                <input type="checkbox" class="form-check-input" name="lr_remember" value="1">
                                <span class="form-check-label"><?php echo esc_html__('Remember me', 'leadrouter'); ?></span>
                            </label>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary w-100"><?php echo esc_html__('Log in', 'leadrouter'); ?></button>
                        </div>
                    </form>
                </div>
                <div class="card-footer">
                    <div class="text-center">
                        <a href="#lr-forgot" data-bs-toggle="collapse" role="button"
                           aria-expanded="false" aria-controls="lr-forgot">
                            <?php echo esc_html__('Forgot your password?', 'leadrouter'); ?>
                        </a>
                    </div>
                    <div class="collapse mt-3" id="lr-forgot">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="lr_partner_forgot">
                            <?php wp_nonce_field(self::NONCE_FORGOT); ?>
                            <div class="mb-2">
                                <label class="form-label" for="lr_identifier"><?php echo esc_html__('Login or email', 'leadrouter'); ?></label>
                                <input type="text" class="form-control" id="lr_identifier" name="lr_identifier" autocomplete="username" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100"><?php echo esc_html__('Email me a new password', 'leadrouter'); ?></button>
                            <p class="small text-secondary mt-2 mb-0">
                                <?php echo esc_html__('We will email your login and a new password if the account exists.', 'leadrouter'); ?>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /** Екран «немає доступу» для залогіненого не-партнера (Tabler card) */
        private static function no_access_html(): string
        {
            ob_start();
            ?>
            <div class="card card-md">
                <div class="card-body text-center">
                    <h2 class="h2 mb-3"><?php echo esc_html__('No access', 'leadrouter'); ?></h2>
                    <p class="text-secondary mb-4"><?php echo esc_html__('This account is not linked to a partner profile.', 'leadrouter'); ?></p>
                    <?php echo self::logout_form_html('btn btn-outline-secondary'); ?>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /** Форма виходу (кнопка з nonce). $btn_class — клас кнопки Tabler. */
        public static function logout_form_html(string $btn_class = 'btn btn-outline-secondary'): string
        {
            ob_start();
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lr-navbar-form">
                <input type="hidden" name="action" value="lr_partner_logout">
                <?php wp_nonce_field(self::NONCE_LOGOUT); ?>
                <button type="submit" class="<?php echo esc_attr($btn_class); ?>">
                    <?php echo esc_html__('Log out', 'leadrouter'); ?>
                </button>
            </form>
            <?php
            return (string) ob_get_clean();
        }

        /** Форма зміни паролю (авто-генерація + лист). $btn_class — клас кнопки Tabler. */
        public static function change_pass_form_html(string $btn_class = 'btn btn-outline-secondary'): string
        {
            ob_start();
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lr-navbar-form"
                  onsubmit="return confirm('<?php echo esc_js(__('Generate a new password and email it to you? Your current session will end.', 'leadrouter')); ?>');">
                <input type="hidden" name="action" value="lr_partner_change_pass">
                <?php wp_nonce_field(self::NONCE_PASS); ?>
                <button type="submit" class="<?php echo esc_attr($btn_class); ?>">
                    <?php echo esc_html__('Reset password', 'leadrouter'); ?>
                </button>
            </form>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * Рендер кабінету для залогіненого партнера — каркас Tabler:
         * навбар + page-body з табами (Overview / Transactions / Leads / Payment card).
         *
         * Фаза 3 — секції-заглушки; реальні дані додаються:
         *   - баланс/статус + транзакції — Фаза 4;
         *   - таблиця лідів зі списань — Фаза 5;
         *   - прив'язка картки (Stripe) — Фаза 6.
         */
        /**
         * Ім'я партнера для UI: partner_display_name з білінг-профілю, а якщо
         * порожній — заголовок поста партнера. Дзеркалить LR_Billing_Mailer::build_vars().
         */
        private static function partner_display_name(int $partner_id): string
        {
            global $wpdb;
            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';
            $name = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT partner_display_name FROM {$t_billing} WHERE partner_id = %d", $partner_id)
            );
            $name = trim($name);
            if ($name !== '') {
                return $name;
            }
            return get_the_title($partner_id) ?: ('#' . $partner_id);
        }

        private static function render_cabinet(int $partner_id): string
        {
            // Ім'я для привітання: спершу partner_display_name з білінг-профілю,
            // і лише якщо порожній — заголовок поста партнера.
            // Дзеркалить логіку LR_Billing_Mailer::build_vars().
            $title = self::partner_display_name($partner_id);

            // Активна секція з ?section (whitelist); за замовчуванням overview.
            // Transactions об'єднано в Overview — старий ?section=transactions падає на overview.
            $tabs    = ['overview', 'leads', 'card'];
            $active  = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'overview';
            if (!in_array($active, $tabs, true)) {
                $active = 'overview';
            }

            ob_start();
            ?>
            <div class="page">
                <header class="navbar navbar-expand-md d-print-none">
                    <div class="container-xl">
                        <span class="navbar-brand fs-3"><?php echo esc_html__('Partner Cabinet', 'leadrouter'); ?></span>
                        <div class="navbar-nav flex-row align-items-center ms-auto gap-2">
                            <span class="text-secondary d-none d-sm-inline">
                                <?php echo esc_html(sprintf(__('Welcome, %s', 'leadrouter'), $title)); ?>
                            </span>
                            <?php echo self::change_pass_form_html('btn btn-outline-secondary btn-sm'); ?>
                            <?php echo self::logout_form_html('btn btn-primary btn-sm'); ?>
                        </div>
                    </div>
                </header>

                <div class="page-body">
                    <div class="container-xl">
                        <?php echo self::notice_html(); ?>

                        <!-- Навігація між секціями (Bootstrap tabs з tabler.min.js) -->
                        <ul class="nav nav-tabs mb-3" role="tablist">
                            <?php
                            $nav = [
                                'overview'     => ['ti-wallet',      __('Overview', 'leadrouter')],
                                'leads'        => ['ti-car',         __('Leads', 'leadrouter')],
                                'card'         => ['ti-credit-card', __('Payment card', 'leadrouter')],
                            ];
                            foreach ($nav as $key => $meta) {
                                $is = ($key === $active);
                                printf(
                                    '<li class="nav-item" role="presentation"><a href="#lr-tab-%1$s" class="nav-link%2$s" data-bs-toggle="tab" role="tab" aria-selected="%3$s"><i class="ti %4$s me-1"></i>%5$s</a></li>',
                                    esc_attr($key),
                                    $is ? ' active' : '',
                                    $is ? 'true' : 'false',
                                    esc_attr($meta[0]),
                                    esc_html($meta[1])
                                );
                            }
                            ?>
                        </ul>

                        <div class="tab-content">
                            <div class="tab-pane<?php echo $active === 'overview' ? ' active show' : ''; ?>" id="lr-tab-overview" role="tabpanel">
                                <?php echo self::section_overview_html($partner_id); ?>
                                <div class="mt-3"><?php echo self::section_transactions_html($partner_id); ?></div>
                            </div>
                            <div class="tab-pane<?php echo $active === 'leads' ? ' active show' : ''; ?>" id="lr-tab-leads" role="tabpanel">
                                <?php echo self::section_leads_html($partner_id); ?>
                            </div>
                            <div class="tab-pane<?php echo $active === 'card' ? ' active show' : ''; ?>" id="lr-tab-card" role="tabpanel">
                                <?php echo self::section_card_html($partner_id); ?>
                                <div class="mt-3"><?php echo self::section_card_topups_html($partner_id); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /* ============================================================
         * Секції кабінету (Фаза 3 — заглушки; дані у Фазах 4–6)
         * ============================================================ */

        /** Заглушка-обгортка секції (картка Tabler з заголовком і текстом «скоро») */
        private static function section_stub_html(string $title, string $note): string
        {
            return '<div class="card"><div class="card-body">'
                . '<h3 class="card-title">' . esc_html($title) . '</h3>'
                . '<p class="text-secondary mb-0">' . esc_html($note) . '</p>'
                . '</div></div>';
        }

        /** Секція «Overview»: поточний баланс + пасивний бейдж статусу (ТЗ 7.1) */
        private static function section_overview_html(int $partner_id): string
        {
            $row      = self::billing_row($partner_id);
            $currency = (string) ($row['currency'] ?? 'USD');
            $balance  = (float) ($row['balance'] ?? 0);
            $min      = (float) ($row['min_balance'] ?? 0);
            [$st_class, $st_label] = self::status_badge($partner_id, $row);

            // Підсвітка балансу помаранчевим — лише коли партнер активний і нижче порогу
            $low = self::partner_is_active($partner_id) && $balance < $min;

            ob_start();
            ?>
            <div class="row row-cards">
                <div class="col-12 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="subheader"><?php echo esc_html__('Current balance', 'leadrouter'); ?></div>
                            <div class="h1 mb-0 <?php echo $low ? 'text-orange' : ''; ?>">
                                <?php echo esc_html(number_format($balance, 2) . ' ' . $currency); ?>
                            </div>
                            <div class="text-secondary mt-2">
                                <?php echo esc_html(sprintf(__('Low-balance threshold: %s', 'leadrouter'),
                                    number_format($min, 2) . ' ' . $currency)); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <div class="subheader"><?php echo esc_html__('Account status', 'leadrouter'); ?></div>
                            <div class="mt-1">
                                <span class="badge <?php echo esc_attr($st_class); ?> fs-3"><?php echo esc_html($st_label); ?></span>
                            </div>
                            <?php if ($st_class === 'lr-status-deactivated') : ?>
                                <div class="text-secondary mt-2">
                                    <?php echo esc_html__('Lead delivery is currently paused.', 'leadrouter'); ?>
                                </div>
                            <?php elseif ($st_class === 'lr-status-low') : ?>
                                <div class="text-secondary mt-2">
                                    <?php echo esc_html__('Your balance is below the threshold.', 'leadrouter'); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /** Секція «Transactions»: журнал списань/поповнень із пагінацією (ТЗ 7.2) */
        /**
         * WHERE + params для ПОПОВНЕНЬ балансу (topup + auto_charge) з фільтром
         * дат (?cardfrom/?cardto, EST). Використовується секцією і експортом.
         *
         * @return array{0:string, 1:array, 2:string, 3:string} [where, params, from, to]
         */
        private static function card_topups_query_parts(int $partner_id): array
        {
            $from = isset($_GET['cardfrom']) ? sanitize_text_field(wp_unslash($_GET['cardfrom'])) : '';
            $to   = isset($_GET['cardto'])   ? sanitize_text_field(wp_unslash($_GET['cardto']))   : '';

            $from_dt = self::valid_date($from) ? $from . ' 00:00:00' : '';
            $to_next = self::valid_date($to)   ? self::date_plus_one($to) . ' 00:00:00' : '';

            $where  = "partner_id = %d AND type IN ('topup', 'auto_charge')";
            $params = [$partner_id];
            if ($from_dt !== '') { $where .= ' AND created_at >= %s'; $params[] = $from_dt; }
            if ($to_next !== '') { $where .= ' AND created_at < %s';  $params[] = $to_next; }

            return [$where, $params, self::valid_date($from) ? $from : '', self::valid_date($to) ? $to : ''];
        }

        /**
         * Картка «Top-ups» на вкладці Payment card: усі поповнення внутрішнього
         * балансу (ручні topup і auto_charge з картки) з фільтром дат і Excel.
         */
        private static function section_card_topups_html(int $partner_id): string
        {
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';

            $page   = isset($_GET['cpage']) ? max(1, absint($_GET['cpage'])) : 1;
            $per    = self::PER_PAGE;
            $offset = ($page - 1) * $per;

            [$where, $params, $from, $to] = self::card_topups_query_parts($partner_id);

            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$t_tx} WHERE {$where}", $params)
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT created_at, type, amount, balance_after, currency, description
                       FROM {$t_tx} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                    array_merge($params, [$per, $offset])
                ),
                ARRAY_A
            );

            $extra = [];
            if ($from !== '') { $extra['cardfrom'] = $from; }
            if ($to   !== '') { $extra['cardto']   = $to; }

            $cab = LR_Partner_Auth::cabinet_url();

            ob_start();
            ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo esc_html__('Top-ups', 'leadrouter'); ?></h3>
                </div>
                <div class="card-body border-bottom py-3">
                    <form method="get" action="<?php echo esc_url($cab); ?>" class="row g-2 align-items-end">
                        <input type="hidden" name="section" value="card">
                        <div class="col-12 col-sm-auto">
                            <label class="form-label" for="lr-cardfrom"><?php echo esc_html__('From (EST)', 'leadrouter'); ?></label>
                            <input type="date" class="form-control" id="lr-cardfrom" name="cardfrom" value="<?php echo esc_attr($from); ?>">
                        </div>
                        <div class="col-12 col-sm-auto">
                            <label class="form-label" for="lr-cardto"><?php echo esc_html__('To (EST)', 'leadrouter'); ?></label>
                            <input type="date" class="form-control" id="lr-cardto" name="cardto" value="<?php echo esc_attr($to); ?>">
                        </div>
                        <div class="col-12 col-sm-auto">
                            <button type="submit" class="btn btn-primary w-100"><?php echo esc_html__('Filter', 'leadrouter'); ?></button>
                        </div>
                        <div class="col-12 col-sm-auto">
                            <button type="submit" name="lr_export" value="card_topups" class="btn btn-outline-success w-100">
                                <i class="ti ti-file-spreadsheet me-1"></i><?php echo esc_html__('Download Excel', 'leadrouter'); ?>
                            </button>
                        </div>
                        <?php if ($from !== '' || $to !== '') : ?>
                        <div class="col-12 col-sm-auto">
                            <a class="btn btn-link w-100" href="<?php echo esc_url(add_query_arg('section', 'card', $cab)); ?>">
                                <?php echo esc_html__('Reset', 'leadrouter'); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Date (EST)', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Type', 'leadrouter'); ?></th>
                                <th class="text-end"><?php echo esc_html__('Amount', 'leadrouter'); ?></th>
                                <th class="text-end"><?php echo esc_html__('Balance after', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Description', 'leadrouter'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)) : ?>
                            <tr><td colspan="5" class="text-secondary"><?php echo esc_html__('No top-ups yet.', 'leadrouter'); ?></td></tr>
                        <?php else : foreach ($rows as $r) :
                            $amount   = (float) ($r['amount'] ?? 0);
                            $currency = (string) ($r['currency'] ?? 'USD');
                            $type     = (string) ($r['type'] ?? '');
                            $amt_str  = ($amount > 0 ? '+' : '') . number_format($amount, 2) . ' ' . $currency;
                            ?>
                            <tr>
                                <td class="text-nowrap"><?php echo esc_html((string) ($r['created_at'] ?? '')); ?></td>
                                <td><span class="badge <?php echo esc_attr(self::tx_type_badge($type)); ?>"><?php
                                    echo esc_html($type); ?></span></td>
                                <td class="text-end text-green"><?php echo esc_html($amt_str); ?></td>
                                <td class="text-end"><?php echo esc_html(number_format((float) ($r['balance_after'] ?? 0), 2)); ?></td>
                                <td><?php echo esc_html((string) ($r['description'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo self::pagination_html($total, $page, $per, 'card', 'cpage', $extra); ?>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * WHERE + params для транзакцій партнера з фільтром дат (?txfrom/?txto, EST).
         * Використовується і в секції, і в Excel-експорті — фільтри збігаються 1:1.
         *
         * @return array{0:string, 1:array, 2:string, 3:string} [where, params, from, to]
         */
        private static function tx_query_parts(int $partner_id): array
        {
            $from = isset($_GET['txfrom']) ? sanitize_text_field(wp_unslash($_GET['txfrom'])) : '';
            $to   = isset($_GET['txto'])   ? sanitize_text_field(wp_unslash($_GET['txto']))   : '';

            $from_dt = self::valid_date($from) ? $from . ' 00:00:00' : '';
            $to_next = self::valid_date($to)   ? self::date_plus_one($to) . ' 00:00:00' : '';

            $where  = 'partner_id = %d';
            $params = [$partner_id];
            if ($from_dt !== '') { $where .= ' AND created_at >= %s'; $params[] = $from_dt; }
            if ($to_next !== '') { $where .= ' AND created_at < %s';  $params[] = $to_next; }

            return [$where, $params, self::valid_date($from) ? $from : '', self::valid_date($to) ? $to : ''];
        }

        private static function section_transactions_html(int $partner_id): string
        {
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';

            $page   = isset($_GET['txpage']) ? max(1, absint($_GET['txpage'])) : 1;
            $per    = self::PER_PAGE;
            $offset = ($page - 1) * $per;

            // partner_id — лише із сесії; усе через prepare
            [$where, $params, $from, $to] = self::tx_query_parts($partner_id);

            $total = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM {$t_tx} WHERE {$where}", $params)
            );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT created_at, type, lead_id, amount, balance_after, currency, description
                       FROM {$t_tx} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
                    array_merge($params, [$per, $offset])
                ),
                ARRAY_A
            );

            // Аргументи фільтра для пагінації (лише непорожні)
            $extra = [];
            if ($from !== '') { $extra['txfrom'] = $from; }
            if ($to   !== '') { $extra['txto']   = $to; }

            $cab = LR_Partner_Auth::cabinet_url();

            ob_start();
            ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo esc_html__('Transactions', 'leadrouter'); ?></h3>
                </div>
                <div class="card-body border-bottom py-3">
                    <form method="get" action="<?php echo esc_url($cab); ?>" class="row g-2 align-items-end">
                        <input type="hidden" name="section" value="overview">
                        <div class="col-12 col-sm-auto">
                            <label class="form-label" for="lr-txfrom"><?php echo esc_html__('From (EST)', 'leadrouter'); ?></label>
                            <input type="date" class="form-control" id="lr-txfrom" name="txfrom" value="<?php echo esc_attr($from); ?>">
                        </div>
                        <div class="col-12 col-sm-auto">
                            <label class="form-label" for="lr-txto"><?php echo esc_html__('To (EST)', 'leadrouter'); ?></label>
                            <input type="date" class="form-control" id="lr-txto" name="txto" value="<?php echo esc_attr($to); ?>">
                        </div>
                        <div class="col-12 col-sm-auto">
                            <button type="submit" class="btn btn-primary w-100"><?php echo esc_html__('Filter', 'leadrouter'); ?></button>
                        </div>
                        <div class="col-12 col-sm-auto">
                            <button type="submit" name="lr_export" value="transactions" class="btn btn-outline-success w-100">
                                <i class="ti ti-file-spreadsheet me-1"></i><?php echo esc_html__('Download Excel', 'leadrouter'); ?>
                            </button>
                        </div>
                        <?php if ($from !== '' || $to !== '') : ?>
                        <div class="col-12 col-sm-auto">
                            <a class="btn btn-link w-100" href="<?php echo esc_url(add_query_arg('section', 'overview', $cab)); ?>">
                                <?php echo esc_html__('Reset', 'leadrouter'); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Date (EST)', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Type', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Lead', 'leadrouter'); ?></th>
                                <th class="text-end"><?php echo esc_html__('Amount', 'leadrouter'); ?></th>
                                <th class="text-end"><?php echo esc_html__('Balance after', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Description', 'leadrouter'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)) : ?>
                            <tr><td colspan="6" class="text-secondary"><?php echo esc_html__('No transactions yet.', 'leadrouter'); ?></td></tr>
                        <?php else : foreach ($rows as $r) :
                            $amount   = (float) ($r['amount'] ?? 0);
                            $currency = (string) ($r['currency'] ?? 'USD');
                            $lead_id  = (int) ($r['lead_id'] ?? 0);
                            $type     = (string) ($r['type'] ?? '');
                            $amt_cls  = $amount < 0 ? 'text-red' : ($amount > 0 ? 'text-green' : '');
                            $amt_str  = ($amount > 0 ? '+' : '') . number_format($amount, 2) . ' ' . $currency;
                            // Списання за лід → згенерований англ. лейбл (старі укр-записи теж коректні)
                            $descr    = ($type === 'spend' && $lead_id > 0)
                                ? sprintf(__('Lead charge #%d', 'leadrouter'), $lead_id)
                                : (string) ($r['description'] ?? '');
                            ?>
                            <tr>
                                <td class="text-nowrap"><?php echo esc_html((string) ($r['created_at'] ?? '')); ?></td>
                                <td><span class="badge <?php echo esc_attr(self::tx_type_badge($type)); ?>"><?php
                                    echo esc_html($type); ?></span></td>
                                <td>
                                    <?php if ($lead_id > 0) : ?>
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#lr-lead-details-modal"
                                           data-lead-id="<?php echo esc_attr($lead_id); ?>">#<?php echo (int) $lead_id; ?></a>
                                    <?php else : ?>
                                        <span class="text-secondary">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end <?php echo esc_attr($amt_cls); ?>"><?php echo esc_html($amt_str); ?></td>
                                <td class="text-end"><?php echo esc_html(number_format((float) ($r['balance_after'] ?? 0), 2)); ?></td>
                                <td><?php echo esc_html($descr); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo self::pagination_html($total, $page, $per, 'overview', 'txpage', $extra); ?>
            </div>
            <?php echo self::lead_details_modal_html(); ?>
            <?php
            return (string) ob_get_clean();
        }

        /** Tabler-модалка деталей ліда (заповнює lr-cabinet-leaddetails.js) */
        private static function lead_details_modal_html(): string
        {
            ob_start();
            ?>
            <div class="modal modal-blur fade" id="lr-lead-details-modal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><?php echo esc_html__('Lead', 'leadrouter'); ?> <span class="lr-ld-title"></span></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo esc_attr__('Close', 'leadrouter'); ?>"></button>
                        </div>
                        <div class="modal-body lr-ld-body"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal"><?php echo esc_html__('Close', 'leadrouter'); ?></button>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /* ============================================================
         * Excel-експорт відфільтрованих даних (?lr_export=leads|transactions)
         * ============================================================ */

        /**
         * Перехоплення GET-запиту експорту на сторінці кабінету. Фільтри —
         * ті самі GET-параметри, що й у формах секцій, тож «що бачиш у
         * таблиці — те і в файлі» (тільки без пагінації).
         */
        public static function maybe_export(): void
        {
            if (!self::is_cabinet_page()) {
                return;
            }
            $what = isset($_GET['lr_export']) ? sanitize_key(wp_unslash($_GET['lr_export'])) : '';
            if (!in_array($what, ['leads', 'transactions', 'card_topups'], true)) {
                return;
            }

            $partner_id = class_exists('LR_Partner_Auth') ? LR_Partner_Auth::current_partner_id() : 0;
            if ($partner_id <= 0 || !class_exists('LR_Xlsx')) {
                return; // незалогінений побачить звичайну сторінку з формою входу
            }

            if ($what === 'transactions') {
                self::export_transactions($partner_id);
            } elseif ($what === 'card_topups') {
                self::export_card_topups($partner_id);
            } else {
                self::export_leads($partner_id);
            }
        }

        /** Поповнення балансу (topup + auto_charge) за фільтром дат → Excel */
        private static function export_card_topups(int $partner_id): void
        {
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';

            [$where, $params, $from, $to] = self::card_topups_query_parts($partner_id);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT created_at, type, amount, currency, balance_after, description
                   FROM {$t_tx} WHERE {$where} ORDER BY id DESC LIMIT %d",
                array_merge($params, [self::EXPORT_MAX_ROWS])
            ), ARRAY_A);

            $out = [];
            foreach ((array) $rows as $r) {
                $out[] = [
                    (string) ($r['created_at'] ?? ''),
                    (string) ($r['type'] ?? ''),
                    (float) ($r['amount'] ?? 0),
                    (string) ($r['currency'] ?? 'USD'),
                    (float) ($r['balance_after'] ?? 0),
                    (string) ($r['description'] ?? ''),
                ];
            }

            LR_Xlsx::stream(
                'top-ups-' . ($from !== '' ? $from : 'all') . '_' . ($to !== '' ? $to : 'all'),
                [
                    __('Date (EST)', 'leadrouter'),
                    __('Type', 'leadrouter'),
                    __('Amount', 'leadrouter'),
                    __('Currency', 'leadrouter'),
                    __('Balance after', 'leadrouter'),
                    __('Description', 'leadrouter'),
                ],
                $out
            );
        }

        /** Транзакції партнера за фільтром дат → Excel */
        private static function export_transactions(int $partner_id): void
        {
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';

            [$where, $params, $from, $to] = self::tx_query_parts($partner_id);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT created_at, type, lead_id, amount, currency, balance_after, description
                   FROM {$t_tx} WHERE {$where} ORDER BY id DESC LIMIT %d",
                array_merge($params, [self::EXPORT_MAX_ROWS])
            ), ARRAY_A);

            $out = [];
            foreach ((array) $rows as $r) {
                $lead_id = (int) ($r['lead_id'] ?? 0);
                $type    = (string) ($r['type'] ?? '');
                $descr   = ($type === 'spend' && $lead_id > 0)
                    ? sprintf(__('Lead charge #%d', 'leadrouter'), $lead_id)
                    : (string) ($r['description'] ?? '');
                $out[] = [
                    (string) ($r['created_at'] ?? ''),
                    $type,
                    $lead_id > 0 ? $lead_id : '',
                    (float) ($r['amount'] ?? 0),
                    (string) ($r['currency'] ?? 'USD'),
                    (float) ($r['balance_after'] ?? 0),
                    $descr,
                ];
            }

            LR_Xlsx::stream(
                'transactions-' . ($from !== '' ? $from : 'all') . '_' . ($to !== '' ? $to : 'all'),
                [
                    __('Date (EST)', 'leadrouter'),
                    __('Type', 'leadrouter'),
                    __('Lead ID', 'leadrouter'),
                    __('Amount', 'leadrouter'),
                    __('Currency', 'leadrouter'),
                    __('Balance after', 'leadrouter'),
                    __('Description', 'leadrouter'),
                ],
                $out
            );
        }

        /** Ліди партнера (spend ⋈ leads) за фільтром дат/пошуку → Excel */
        private static function export_leads(int $partner_id): void
        {
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';
            $t_l  = $wpdb->prefix . 'leadrouter_leads';

            // Той самий фільтр, що в section_leads_html (from/to/q)
            $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
            $to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';
            $q    = isset($_GET['q'])    ? sanitize_text_field(wp_unslash($_GET['q']))    : '';

            $from_dt = self::valid_date($from) ? $from . ' 00:00:00' : '';
            $to_next = self::valid_date($to)   ? self::date_plus_one($to) . ' 00:00:00' : '';

            $where  = "t.partner_id = %d AND t.type = 'spend'";
            $params = [$partner_id];
            if ($from_dt !== '') { $where .= ' AND t.created_at >= %s'; $params[] = $from_dt; }
            if ($to_next !== '') { $where .= ' AND t.created_at < %s';  $params[] = $to_next; }
            if ($q !== '') {
                $like = '%' . $wpdb->esc_like($q) . '%';
                $where .= ' AND (l.name LIKE %s OR l.phone LIKE %s OR l.email LIKE %s)';
                $params[] = $like; $params[] = $like; $params[] = $like;
            }

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT t.lead_id, t.created_at AS charged_at, t.amount,
                        l.name, l.email, l.phone,
                        l.vehicle_year, l.vehicle_brand, l.vehicle_model
                   FROM {$t_tx} t JOIN {$t_l} l ON l.id = t.lead_id
                  WHERE {$where}
                  ORDER BY t.created_at DESC
                  LIMIT %d",
                array_merge($params, [self::EXPORT_MAX_ROWS])
            ), ARRAY_A);

            $out = [];
            foreach ((array) $rows as $r) {
                $vehicle = trim(sprintf(
                    '%s %s %s',
                    (string) ($r['vehicle_year'] ?? ''),
                    (string) ($r['vehicle_brand'] ?? ''),
                    (string) ($r['vehicle_model'] ?? '')
                ));
                $out[] = [
                    (int) ($r['lead_id'] ?? 0),
                    (string) ($r['name'] ?? ''),
                    (string) ($r['email'] ?? ''),
                    (string) ($r['phone'] ?? ''),
                    $vehicle,
                    (string) ($r['charged_at'] ?? ''),
                    (float) ($r['amount'] ?? 0),
                ];
            }

            LR_Xlsx::stream(
                'leads-' . ($from !== '' ? $from : 'all') . '_' . ($to !== '' ? $to : 'all'),
                [
                    __('Lead ID', 'leadrouter'),
                    __('Customer', 'leadrouter'),
                    __('Email', 'leadrouter'),
                    __('Phone', 'leadrouter'),
                    __('Vehicle', 'leadrouter'),
                    __('Charged at (EST)', 'leadrouter'),
                    __('Amount', 'leadrouter'),
                ],
                $out
            );
        }

        /* ============================================================
         * Деталі ліда для модалки (транзакції): AJAX
         * ============================================================ */

        /**
         * Партнер бачить деталі ЛИШЕ ліда, за який із нього списано кошти
         * (spend-транзакція) — це і перевірка доступу, і джерело суми.
         */
        public static function ajax_lead_details(): void
        {
            check_ajax_referer(self::NONCE_LEAD, 'nonce');

            $partner_id = class_exists('LR_Partner_Auth') ? LR_Partner_Auth::current_partner_id() : 0;
            if ($partner_id <= 0) {
                wp_send_json_error(['message' => __('Not authorized.', 'leadrouter')], 403);
            }

            $lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
            if ($lead_id <= 0) {
                wp_send_json_error(['message' => __('Bad lead id.', 'leadrouter')], 400);
            }

            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';
            $t_l  = $wpdb->prefix . 'leadrouter_leads';

            $tx = $wpdb->get_row($wpdb->prepare(
                "SELECT created_at, amount, currency FROM {$t_tx}
                  WHERE partner_id = %d AND lead_id = %d AND type = 'spend'
                  ORDER BY id DESC LIMIT 1",
                $partner_id, $lead_id
            ), ARRAY_A);
            if (!$tx) {
                wp_send_json_error(['message' => __('Lead not found.', 'leadrouter')], 404);
            }

            $l = $wpdb->get_row($wpdb->prepare(
                "SELECT name, email, phone, est_ship_date,
                        vehicle_year, vehicle_brand, vehicle_model, vehicle_condition,
                        from_city, from_state, from_zip, to_city, to_state, to_zip
                   FROM {$t_l} WHERE id = %d",
                $lead_id
            ), ARRAY_A);
            if (!$l) {
                wp_send_json_error(['message' => __('Lead not found.', 'leadrouter')], 404);
            }

            $place = static function ($city, $state, $zip): string {
                $parts = array_filter([trim((string) $city), trim((string) $state), trim((string) $zip)]);
                return $parts ? implode(', ', $parts) : '';
            };
            $vehicle = trim(sprintf(
                '%s %s %s',
                (string) ($l['vehicle_year'] ?? ''),
                (string) ($l['vehicle_brand'] ?? ''),
                (string) ($l['vehicle_model'] ?? '')
            ));
            $cond = trim((string) ($l['vehicle_condition'] ?? ''));
            if ($cond !== '') {
                $cond = (strcasecmp($cond, 'Running') === 0)
                    ? __('Operable', 'leadrouter')
                    : __('Inoperable', 'leadrouter');
            }
            $amount = (float) ($tx['amount'] ?? 0);

            $fields = [
                ['label' => __('Customer', 'leadrouter'),         'value' => (string) ($l['name'] ?? '')],
                ['label' => __('Email', 'leadrouter'),            'value' => (string) ($l['email'] ?? '')],
                ['label' => __('Phone', 'leadrouter'),            'value' => (string) ($l['phone'] ?? '')],
                ['label' => __('From', 'leadrouter'),             'value' => $place($l['from_city'] ?? '', $l['from_state'] ?? '', $l['from_zip'] ?? '')],
                ['label' => __('To', 'leadrouter'),               'value' => $place($l['to_city'] ?? '', $l['to_state'] ?? '', $l['to_zip'] ?? '')],
                ['label' => __('Vehicle', 'leadrouter'),          'value' => $vehicle],
                ['label' => __('Condition', 'leadrouter'),        'value' => $cond],
                ['label' => __('Est. ship date', 'leadrouter'),   'value' => (string) ($l['est_ship_date'] ?? '')],
                ['label' => __('Charged at (EST)', 'leadrouter'), 'value' => (string) ($tx['created_at'] ?? '')],
                ['label' => __('Charged amount', 'leadrouter'),   'value' => number_format(abs($amount), 2) . ' ' . (string) ($tx['currency'] ?? 'USD')],
            ];

            $title = '#' . $lead_id;
            if (trim((string) ($l['name'] ?? '')) !== '') {
                $title .= ' — ' . (string) $l['name'];
            }

            wp_send_json_success(['title' => $title, 'fields' => $fields]);
        }

        /**
         * Секція «Leads»: таблиця лідів, ЗА ЯКІ СПИСАНО кошти (ТЗ 7.3).
         * Джерело — spend-транзакції ⋈ leads (НЕ лог відправки). Фільтри період(EST)+пошук.
         * Колонки — мінімум: lead_id, ім'я, контакт (email/phone, повний PII), авто, дата списання.
         */
        private static function section_leads_html(int $partner_id): string
        {
            global $wpdb;
            $t_tx = $wpdb->prefix . 'leadrouter_billing_transactions';
            $t_l  = $wpdb->prefix . 'leadrouter_leads';

            // Фільтри (усе — лише для свого partner_id, що береться з сесії)
            $from = isset($_GET['from']) ? sanitize_text_field(wp_unslash($_GET['from'])) : '';
            $to   = isset($_GET['to'])   ? sanitize_text_field(wp_unslash($_GET['to']))   : '';
            $q    = isset($_GET['q'])    ? sanitize_text_field(wp_unslash($_GET['q']))    : '';
            $page = isset($_GET['lpage']) ? max(1, absint($_GET['lpage'])) : 1;
            $per  = self::PER_PAGE;
            $offset = ($page - 1) * $per;

            // Період EST: from 00:00:00 .. (to+1 день) 00:00:00 (to включно). Невалідне — ігноруємо.
            $from_dt = self::valid_date($from) ? $from . ' 00:00:00' : '';
            $to_next = self::valid_date($to)   ? self::date_plus_one($to) . ' 00:00:00' : '';

            // Динамічний WHERE з prepare-параметрами (partner_id — перший)
            $where  = "t.partner_id = %d AND t.type = 'spend'";
            $params = [$partner_id];
            if ($from_dt !== '') { $where .= ' AND t.created_at >= %s'; $params[] = $from_dt; }
            if ($to_next !== '') { $where .= ' AND t.created_at < %s';  $params[] = $to_next; }
            if ($q !== '') {
                $like = '%' . $wpdb->esc_like($q) . '%';
                $where .= ' AND (l.name LIKE %s OR l.phone LIKE %s OR l.email LIKE %s)';
                $params[] = $like; $params[] = $like; $params[] = $like;
            }

            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$t_tx} t JOIN {$t_l} l ON l.id = t.lead_id WHERE {$where}",
                $params
            ));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT t.lead_id, t.created_at AS charged_at,
                        l.name, l.email, l.phone,
                        l.vehicle_year, l.vehicle_brand, l.vehicle_model
                   FROM {$t_tx} t JOIN {$t_l} l ON l.id = t.lead_id
                  WHERE {$where}
                  ORDER BY t.created_at DESC
                  LIMIT %d OFFSET %d",
                array_merge($params, [$per, $offset])
            ), ARRAY_A);

            // Скарги: множина lead_id поточної сторінки, на які партнер уже скаржився
            // (один запит на сторінку — без N+1). Кнопка → бейдж для таких лідів.
            $lead_ids   = array_map(static function ($r) { return (int) ($r['lead_id'] ?? 0); }, (array) $rows);
            $complained = LR_Complaints::complained_map($partner_id, $lead_ids);

            // Аргументи фільтра для пагінації (лише непорожні)
            $extra = [];
            if ($from !== '') { $extra['from'] = $from; }
            if ($to   !== '') { $extra['to']   = $to; }
            if ($q    !== '') { $extra['q']    = $q; }

            $cab = LR_Partner_Auth::cabinet_url();

            ob_start();
            ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><?php echo esc_html__('Leads', 'leadrouter'); ?></h3>
                </div>
                <div class="card-body border-bottom py-3">
                    <form method="get" action="<?php echo esc_url($cab); ?>" class="row g-2 align-items-end">
                        <input type="hidden" name="section" value="leads">
                        <div class="col-12 col-sm-auto">
                            <label class="form-label" for="lr-from"><?php echo esc_html__('From (EST)', 'leadrouter'); ?></label>
                            <input type="date" class="form-control" id="lr-from" name="from" value="<?php echo esc_attr($from); ?>">
                        </div>
                        <div class="col-12 col-sm-auto">
                            <label class="form-label" for="lr-to"><?php echo esc_html__('To (EST)', 'leadrouter'); ?></label>
                            <input type="date" class="form-control" id="lr-to" name="to" value="<?php echo esc_attr($to); ?>">
                        </div>
                        <div class="col-12 col-sm">
                            <label class="form-label" for="lr-q"><?php echo esc_html__('Search', 'leadrouter'); ?></label>
                            <input type="text" class="form-control" id="lr-q" name="q"
                                   placeholder="<?php echo esc_attr__('Name, phone or email', 'leadrouter'); ?>"
                                   value="<?php echo esc_attr($q); ?>">
                        </div>
                        <div class="col-12 col-sm-auto">
                            <button type="submit" class="btn btn-primary w-100"><?php echo esc_html__('Filter', 'leadrouter'); ?></button>
                        </div>
                        <div class="col-12 col-sm-auto">
                            <button type="submit" name="lr_export" value="leads" class="btn btn-outline-success w-100">
                                <i class="ti ti-file-spreadsheet me-1"></i><?php echo esc_html__('Download Excel', 'leadrouter'); ?>
                            </button>
                        </div>
                        <?php if ($from !== '' || $to !== '' || $q !== '') : ?>
                        <div class="col-12 col-sm-auto">
                            <a class="btn btn-link w-100" href="<?php echo esc_url(add_query_arg('section', 'leads', $cab)); ?>">
                                <?php echo esc_html__('Reset', 'leadrouter'); ?>
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th><?php echo esc_html__('Lead ID', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Customer', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Contact', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Vehicle', 'leadrouter'); ?></th>
                                <th><?php echo esc_html__('Charged at (EST)', 'leadrouter'); ?></th>
                                <th class="text-end"><?php echo esc_html__('Actions', 'leadrouter'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)) : ?>
                            <tr><td colspan="6" class="text-secondary"><?php echo esc_html__('No leads found.', 'leadrouter'); ?></td></tr>
                        <?php else : foreach ($rows as $r) :
                            $vehicle = trim(sprintf('%s %s %s',
                                (string) ($r['vehicle_year'] ?? ''),
                                (string) ($r['vehicle_brand'] ?? ''),
                                (string) ($r['vehicle_model'] ?? '')
                            ));
                            $email = (string) ($r['email'] ?? '');
                            $phone = (string) ($r['phone'] ?? '');
                            $lid   = (int) ($r['lead_id'] ?? 0);
                            // Лейбл ліда для модалки (#id — імʼя)
                            $lead_label = '#' . $lid;
                            if (trim((string) ($r['name'] ?? '')) !== '') {
                                $lead_label .= ' — ' . (string) $r['name'];
                            }
                            ?>
                            <tr>
                                <td class="text-nowrap">#<?php echo $lid; ?></td>
                                <td><?php echo esc_html((string) ($r['name'] ?? '')); ?></td>
                                <td>
                                    <?php if ($email !== '') : ?>
                                        <div><a href="mailto:<?php echo esc_attr($email); ?>"><?php echo esc_html($email); ?></a></div>
                                    <?php endif; ?>
                                    <?php if ($phone !== '') : ?>
                                        <div><a href="tel:<?php echo esc_attr($phone); ?>"><?php echo esc_html($phone); ?></a></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $vehicle !== '' ? esc_html($vehicle) : '<span class="text-secondary">—</span>'; ?></td>
                                <td class="text-nowrap"><?php echo esc_html((string) ($r['charged_at'] ?? '')); ?></td>
                                <td class="text-end">
                                    <?php if (isset($complained[$lid])) : ?>
                                        <span class="badge bg-secondary-lt"><?php echo esc_html__('Complaint sent', 'leadrouter'); ?></span>
                                    <?php else : ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger lr-complaint-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#lr-complaint-modal"
                                                data-lead-id="<?php echo esc_attr($lid); ?>"
                                                data-lead-label="<?php echo esc_attr($lead_label); ?>">
                                            <i class="ti ti-alert-triangle me-1"></i><?php echo esc_html__('Complain', 'leadrouter'); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo self::pagination_html($total, $page, $per, 'leads', 'lpage', $extra); ?>
            </div>
            <?php echo self::complaint_modal_html(); ?>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * Спільна Tabler-модалка скарги на лід. Кнопка рядка передає lead_id/label
         * через data-атрибути; JS (lr-cabinet-complaints.js) заповнює і шле AJAX.
         * Теми — з налаштувань (lr_complaint_topics) + завжди «Other».
         */
        private static function complaint_modal_html(): string
        {
            $topics = LR_Complaints::get_topics();
            $other  = LR_Complaints::other_topic();

            ob_start();
            ?>
            <div class="modal modal-blur fade" id="lr-complaint-modal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <form class="lr-complaint-form">
                            <div class="modal-header">
                                <h5 class="modal-title"><?php echo esc_html__('Report a problem with this lead', 'leadrouter'); ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo esc_attr__('Close', 'leadrouter'); ?>"></button>
                            </div>
                            <div class="modal-body">
                                <div class="lr-complaint-alert"></div>
                                <div class="mb-3">
                                    <span class="text-secondary"><?php echo esc_html__('Lead', 'leadrouter'); ?>:</span>
                                    <strong class="lr-complaint-lead-label"></strong>
                                </div>
                                <input type="hidden" class="lr-complaint-lead-id" value="">
                                <div class="mb-3">
                                    <label class="form-label" for="lr-complaint-topic"><?php echo esc_html__('Topic', 'leadrouter'); ?></label>
                                    <select class="form-select lr-complaint-topic" id="lr-complaint-topic">
                                        <?php foreach ($topics as $t) : ?>
                                            <option value="<?php echo esc_attr($t); ?>"><?php echo esc_html($t); ?></option>
                                        <?php endforeach; ?>
                                        <option value="<?php echo esc_attr($other); ?>"><?php echo esc_html($other); ?></option>
                                    </select>
                                </div>
                                <div class="mb-1">
                                    <label class="form-label" for="lr-complaint-message"><?php echo esc_html__('Message', 'leadrouter'); ?></label>
                                    <textarea class="form-control lr-complaint-message" id="lr-complaint-message"
                                              rows="4" minlength="5" maxlength="2000" required
                                              placeholder="<?php echo esc_attr__('Describe the problem with this lead…', 'leadrouter'); ?>"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal"><?php echo esc_html__('Cancel', 'leadrouter'); ?></button>
                                <button type="submit" class="btn btn-primary lr-complaint-submit"><?php echo esc_html__('Submit complaint', 'leadrouter'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /**
         * Секція «Payment card» (ТЗ 7.4): статус картки + прив'язка/перев'язка через Stripe,
         * пояснення про автосписання + read-only min_balance / auto_charge_amount.
         * Stripe customer_id / payment_method_id НЕ показуємо — лише brand •••• last4.
         */
        private static function section_card_html(int $partner_id): string
        {
            $row      = self::billing_row($partner_id);
            $currency = (string) ($row['currency'] ?? 'USD');
            $min      = (float) ($row['min_balance'] ?? 0);
            $auto     = (float) ($row['auto_charge_amount'] ?? 0);
            $pm_id    = trim((string) ($row['stripe_payment_method_id'] ?? ''));
            $has_card = $pm_id !== '';

            // Наявні картки без збережених brand/last4 — підтягнути зі Stripe (lazy-fetch, кеш у БД)
            $brand = ''; $last4 = '';
            if ($has_card && class_exists('LR_Partner_Card')) {
                $d = LR_Partner_Card::ensure_card_details($partner_id, $row);
                $brand = (string) $d['brand'];
                $last4 = (string) $d['last4'];
            }

            $setup_form = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="d-inline-block">'
                . '<input type="hidden" name="action" value="lr_partner_card_setup">'
                . wp_nonce_field(LR_Partner_Card::NONCE_SETUP, '_wpnonce', true, false)
                . '<button type="submit" class="btn btn-primary">'
                . ($has_card ? esc_html__('Change card', 'leadrouter') : esc_html__('Link card', 'leadrouter'))
                . '</button></form>';

            ob_start();
            ?>
            <?php echo self::card_notice_html(); ?>
            <div class="row row-cards">
                <div class="col-12 col-lg-6">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="card-title"><?php echo esc_html__('Payment card', 'leadrouter'); ?></h3>
                            <?php if ($has_card) : ?>
                                <div class="d-flex align-items-center mb-3">
                                    <span class="badge lr-status-active me-2"><?php echo esc_html__('Card linked', 'leadrouter'); ?> ✅</span>
                                    <span class="fs-3">
                                        <i class="ti ti-credit-card me-1"></i>
                                        <?php
                                        if ($brand !== '' || $last4 !== '') {
                                            echo esc_html(ucfirst($brand) . ' •••• ' . $last4);
                                        } else {
                                            echo esc_html__('Card on file', 'leadrouter');
                                        }
                                        ?>
                                    </span>
                                </div>
                            <?php else : ?>
                                <div class="mb-3">
                                    <span class="badge lr-status-deactivated"><?php echo esc_html__('No card linked', 'leadrouter'); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php
                            // wp_nonce_field уже екранований/безпечний HTML
                            echo $setup_form; // phpcs:ignore WordPress.Security.EscapeOutput
                            ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="card-title"><?php echo esc_html__('Auto-charge', 'leadrouter'); ?></h3>
                            <p class="text-secondary">
                                <?php echo esc_html__('Payment for leads is charged automatically from the linked card when your balance falls below the threshold set by the administrator.', 'leadrouter'); ?>
                            </p>
                            <div class="datagrid">
                                <div class="datagrid-item">
                                    <div class="datagrid-title"><?php echo esc_html__('Low-balance threshold', 'leadrouter'); ?></div>
                                    <div class="datagrid-content"><?php echo esc_html(number_format($min, 2) . ' ' . $currency); ?></div>
                                </div>
                                <div class="datagrid-item">
                                    <div class="datagrid-title"><?php echo esc_html__('Auto-charge amount', 'leadrouter'); ?></div>
                                    <div class="datagrid-content"><?php echo esc_html(number_format($auto, 2) . ' ' . $currency); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /** Повідомлення секції картки (?lr_card=...) */
        private static function card_notice_html(): string
        {
            $code = isset($_GET['lr_card']) ? sanitize_key($_GET['lr_card']) : '';
            if ($code === '') {
                return '';
            }
            $map = [
                'linked'     => ['success', __('Your payment card has been linked.', 'leadrouter')],
                'cancel'     => ['info',    __('Card setup was cancelled.', 'leadrouter')],
                'card_error' => ['danger',  __('Could not link the card. Please try again.', 'leadrouter')],
                'badlogin'   => ['danger',  __('Your session has expired. Please log in again.', 'leadrouter')],
            ];
            if (!isset($map[$code])) {
                return '';
            }
            [$type, $text] = $map[$code];
            return '<div class="alert alert-' . esc_attr($type) . '" role="alert">' . esc_html($text) . '</div>';
        }

        /* ============================================================
         * Допоміжні методи секцій (дані/рендер)
         * ============================================================ */

        /** Білінг-рядок партнера (read-only). Префікс — лише $wpdb->prefix, partner_id із сесії. */
        private static function billing_row(int $partner_id): array
        {
            global $wpdb;
            $t = $wpdb->prefix . 'leadrouter_partner_billing';
            $row = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM {$t} WHERE partner_id = %d LIMIT 1", $partner_id),
                ARRAY_A
            );
            return is_array($row) ? $row : [];
        }

        /**
         * Бейдж статусу для кабінету. Активність беремо з поля «Основні → Активний?»
         * (_leadrouter_partner_active), а НЕ з deactivated_by_billing:
         *   не активний → Deactivated; активний і balance < min_balance → Low balance;
         *   інакше → Active.
         *
         * @return array{0:string,1:string} [css_class, label]
         */
        private static function status_badge(int $partner_id, array $row): array
        {
            if (!self::partner_is_active($partner_id)) {
                return ['lr-status-deactivated', __('Deactivated', 'leadrouter')];
            }

            $balance = (float) ($row['balance'] ?? 0);
            $min     = (float) ($row['min_balance'] ?? 0);
            if ($balance < $min) {
                return ['lr-status-low', __('Low balance', 'leadrouter')];
            }
            return ['lr-status-active', __('Active', 'leadrouter')];
        }

        /**
         * Активність партнера з поля «Основні → Активний?» (_leadrouter_partner_active).
         * Порожнє/null/'1' = активний, явне '0' = вимкнено — та сама логіка,
         * що в LeadRouter_Partners::is_active.
         */
        private static function partner_is_active(int $partner_id): bool
        {
            $raw = get_post_meta($partner_id, '_leadrouter_partner_active', true);
            return ($raw === '' || $raw === null) ? true : ($raw == '1');
        }

        /** Колір бейджа типу транзакції (Tabler light badges) */
        private static function tx_type_badge(string $type): string
        {
            switch ($type) {
                case 'spend':
                case 'manual_debit':
                    return 'bg-red-lt';
                case 'topup':
                case 'auto_charge':
                case 'credit':
                    return 'bg-green-lt';
                case 'refund':
                    return 'bg-orange-lt';
                default:
                    return 'bg-secondary-lt';
            }
        }

        /**
         * Пагінація (Tabler) — Prev / «Page X of Y» / Next.
         * Лінки зберігають активну секцію (?section=...) і номер сторінки ($page_key).
         */
        private static function pagination_html(int $total, int $page, int $per, string $section, string $page_key, array $extra = []): string
        {
            $pages = (int) ceil($total / max(1, $per));
            if ($pages <= 1) {
                return '';
            }

            $base = LR_Partner_Auth::cabinet_url();
            $url  = function (int $p) use ($base, $section, $page_key, $extra) {
                $args = array_merge($extra, ['section' => $section, $page_key => $p]);
                return esc_url(add_query_arg($args, $base) . '#lr-tab-' . $section);
            };

            $prev_disabled = $page <= 1 ? ' disabled' : '';
            $next_disabled = $page >= $pages ? ' disabled' : '';

            ob_start();
            ?>
            <div class="card-footer d-flex align-items-center">
                <ul class="pagination m-0 ms-auto">
                    <li class="page-item<?php echo esc_attr($prev_disabled); ?>">
                        <a class="page-link" href="<?php echo $page > 1 ? $url($page - 1) : '#'; ?>" tabindex="-1">
                            <?php echo esc_html__('Prev', 'leadrouter'); ?>
                        </a>
                    </li>
                    <li class="page-item active">
                        <span class="page-link">
                            <?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'leadrouter'), $page, $pages)); ?>
                        </span>
                    </li>
                    <li class="page-item<?php echo esc_attr($next_disabled); ?>">
                        <a class="page-link" href="<?php echo $page < $pages ? $url($page + 1) : '#'; ?>">
                            <?php echo esc_html__('Next', 'leadrouter'); ?>
                        </a>
                    </li>
                </ul>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        /* ============================================================
         * Сторінка кабінету (slug /partner) — створення/перевірка
         * ============================================================ */
        /**
         * Гарантує наявність опублікованої сторінки з shortcode [lr_partner_cabinet]
         * і зберігає її id в опції LR_Partner_Auth::OPTION_PAGE_ID. Ідемпотентно.
         */
        public static function ensure_cabinet_page(): void
        {
            $opt_id = (int) get_option(LR_Partner_Auth::OPTION_PAGE_ID);
            if ($opt_id > 0) {
                $p = get_post($opt_id);
                if ($p && $p->post_type === 'page' && $p->post_status === 'publish') {
                    return; // сторінка на місці
                }
            }

            // Можливо, сторінка зі slug вже існує (створена раніше/вручну)
            $existing = get_page_by_path(self::PAGE_SLUG);
            if ($existing instanceof WP_Post) {
                update_option(LR_Partner_Auth::OPTION_PAGE_ID, (int) $existing->ID);
                return;
            }

            $page_id = wp_insert_post([
                'post_title'   => 'Partner Cabinet',
                'post_name'    => self::PAGE_SLUG,
                'post_content' => '[lr_partner_cabinet]',
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ]);

            if ($page_id && !is_wp_error($page_id)) {
                update_option(LR_Partner_Auth::OPTION_PAGE_ID, (int) $page_id);
            }
        }
    }
}
