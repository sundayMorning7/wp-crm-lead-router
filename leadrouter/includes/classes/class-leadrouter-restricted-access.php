<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * LeadRouter_Restricted_Access
 *
 * Обмежений доступ для працівника: роль `leadrouter_manager` бачить і повноцінно
 * керує ЛИШЕ сторінками LeadRouter (Dashboard, Ліди, Групи, Партнери, Логи,
 * Complaints) і нічим більше в /wp-admin.
 *
 * Принцип (context-gated capability):
 *   - Роль сама по собі має тільки `read` — нуль реальних прав.
 *   - Права `manage_options` та повний CRUD над CPT (Групи/Партнери) видаються
 *     ДИНАМІЧНО через фільтр user_has_cap і ЛИШЕ коли поточний запит належить
 *     LeadRouter (його сторінки, його CPT-екрани, його AJAX/admin-post екшени).
 *   - Поза цим контекстом прав нема — тож options.php, чужі AJAX/REST, інші
 *     плагіни тощо для працівника закриті на рівні capability, а не лише візуально.
 *   - Редірект + прибирання меню — це UX-шар поверх capability-замка.
 *
 * CPT не змінюємо (лишаються capability_type => 'post'); адміністратор уже має всі
 * ці права, тож його нічого не зачіпає.
 */
class LeadRouter_Restricted_Access
{
    /** Slug ролі працівника */
    const ROLE = 'leadrouter_manager';

    /** Дозволені сторінки admin.php?page=... */
    private static $pages = [
        'leadrouter',
        'leadrouter-leads',
        'leadrouter-logviewer',
        'leadrouter-complaints',
    ];

    /** Дозволені CPT (Групи/Партнери) */
    private static $cpts = [
        'leadrouter_group',
        'leadrouter_partner',
    ];

    /**
     * Дозволені AJAX / admin-post екшени — суто робочі дії 6 сторінок.
     * НАВМИСНО виключені фінансові/акаунтні дії (білінг: списання/поповнення/
     * налаштування, генерація пароля кабінету партнера) — їх немає у списку
     * дозволених сторінок. За потреби додати сюди відповідні екшени.
     */
    private static $actions = [
        // Ліди
        'leadrouter_get_lead_send_logs',
        'leadrouter_get_report',
        'leadrouter_manual_broadcast',
        'leadrouter_manual_broadcast_bulk',
        'leadrouter_delete_leads_cascade',
        // Логи
        'leadrouter_export_logs',   // wp_ajax
        'leadrouter_clear_logs',    // admin_post
        // Групи (кнопка Вкл/Викл у списку)
        'leadrouter_toggle_group_active', // admin_post
        // Тестова відправка ліда партнеру (колонка у списках)
        'lr_send_test_lead',        // wp_ajax
    ];

    /**
     * Капабіліті, які видаємо в контексті LeadRouter.
     * manage_options — для 4 сторінок admin.php та їх AJAX.
     * Решта — повний CRUD над CPT з capability_type='post' (Групи/Партнери).
     */
    private static $grant = [
        'manage_options',
        'edit_posts',
        'edit_others_posts',
        'edit_published_posts',
        'edit_private_posts',
        'publish_posts',
        'delete_posts',
        'delete_others_posts',
        'delete_published_posts',
        'delete_private_posts',
        'read_private_posts',
    ];

    public static function register(): void
    {
        // Роль створюємо ідемпотентно на init — щоб існувала й після простого
        // оновлення файлів (без реактивації плагіна).
        add_action('init', [__CLASS__, 'ensure_role']);

        if (!is_admin()) {
            return;
        }

        add_filter('user_has_cap', [__CLASS__, 'grant_caps_in_context'], 10, 4);
        add_action('admin_init', [__CLASS__, 'guard_redirect'], 1);
        add_action('admin_menu', [__CLASS__, 'prune_menus'], 9999);
    }

    /** Створити роль, якщо її ще нема */
    public static function ensure_role(): void
    {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, 'LeadRouter Manager', ['read' => true]);
        }
    }

    /** Чи є переданий (або поточний) користувач нашим працівником */
    public static function is_manager($user = null): bool
    {
        $user = $user ?: wp_get_current_user();
        return $user && !empty($user->roles) && in_array(self::ROLE, (array) $user->roles, true);
    }

    /** Тип поста, якого стосується поточний post.php / post-new.php / edit.php запит */
    private static function request_post_type(): string
    {
        // Явний post_type у запиті (edit.php, post-new.php, збереження форми)
        if (isset($_REQUEST['post_type'])) {
            return sanitize_key((string) $_REQUEST['post_type']);
        }
        // Редагування/збереження існуючого поста — визначаємо тип за ID
        $pid = 0;
        if (isset($_REQUEST['post'])) {
            $pid = (int) $_REQUEST['post'];
        } elseif (isset($_POST['post_ID'])) {
            $pid = (int) $_POST['post_ID'];
        }
        if ($pid > 0) {
            return (string) get_post_type($pid);
        }
        return '';
    }

    /** Чи належить поточний запит контексту LeadRouter (для видачі прав) */
    public static function is_lr_context(): bool
    {
        $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';

        // AJAX або admin-post — гейтимо за іменем екшену
        if (wp_doing_ajax() || $pagenow === 'admin-post.php') {
            $action = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
            return ($action !== '' && in_array($action, self::$actions, true));
        }

        // admin.php?page=leadrouter*
        if ($pagenow === 'admin.php' && isset($_REQUEST['page'])) {
            return in_array(sanitize_key((string) $_REQUEST['page']), self::$pages, true);
        }

        // Списки/створення/редагування CPT Групи та Партнери
        if (in_array($pagenow, ['edit.php', 'post-new.php', 'post.php'], true)) {
            return in_array(self::request_post_type(), self::$cpts, true);
        }

        return false;
    }

    /** Динамічна видача прав у контексті LeadRouter */
    public static function grant_caps_in_context($allcaps, $caps, $args, $user)
    {
        if (!self::is_manager($user)) {
            return $allcaps;
        }
        if (!self::is_lr_context()) {
            return $allcaps;
        }
        foreach (self::$grant as $cap) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }

    /** Чи дозволено ВІДКРИВАТИ поточний екран (білий список для редіректу) */
    private static function is_allowed_screen(): bool
    {
        $pagenow = isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '';

        if ($pagenow === 'admin.php' && isset($_REQUEST['page'])) {
            return in_array(sanitize_key((string) $_REQUEST['page']), self::$pages, true);
        }
        if (in_array($pagenow, ['edit.php', 'post-new.php', 'post.php'], true)) {
            return in_array(self::request_post_type(), self::$cpts, true);
        }
        // Власний профіль — щоб працівник міг змінити свій пароль
        if ($pagenow === 'profile.php') {
            return true;
        }
        return false;
    }

    /** Редірект будь-яких сторонніх адмін-сторінок на головну LeadRouter */
    public static function guard_redirect(): void
    {
        if (!self::is_manager()) {
            return;
        }
        // AJAX / admin-post — не редіректимо (це не завантаження сторінки; їх гейтять каби)
        if (wp_doing_ajax()) {
            return;
        }
        if ((isset($GLOBALS['pagenow']) ? (string) $GLOBALS['pagenow'] : '') === 'admin-post.php') {
            return;
        }
        if (self::is_allowed_screen()) {
            return;
        }
        wp_safe_redirect(admin_url('admin.php?page=leadrouter'));
        exit;
    }

    /**
     * Прибрати з меню все, що не стосується LeadRouter.
     * На LR-сторінках у контексті видано manage_options/edit_posts, тому WordPress
     * інакше показав би Записи/Налаштування/Плагіни тощо — тут їх ховаємо.
     */
    public static function prune_menus(): void
    {
        if (!self::is_manager()) {
            return;
        }
        global $menu, $submenu;

        // 1) Лишаємо лише топ-меню LeadRouter
        if (is_array($menu)) {
            foreach ($menu as $item) {
                $slug = isset($item[2]) ? (string) $item[2] : '';
                if ($slug !== '' && $slug !== 'leadrouter') {
                    remove_menu_page($slug);
                }
            }
        }

        // 2) Під LeadRouter лишаємо лише дозволені підпункти
        $allowed_sub = array_merge(self::$pages, [
            'edit.php?post_type=leadrouter_group',
            'edit.php?post_type=leadrouter_partner',
        ]);
        if (isset($submenu['leadrouter']) && is_array($submenu['leadrouter'])) {
            foreach ($submenu['leadrouter'] as $item) {
                $slug = isset($item[2]) ? (string) $item[2] : '';
                if ($slug !== '' && !in_array($slug, $allowed_sub, true)) {
                    remove_submenu_page('leadrouter', $slug);
                }
            }
        }
    }
}

LeadRouter_Restricted_Access::register();
