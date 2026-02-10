<?php
/**
 * Plugin Name: LeadRouter by Maks Devda
 * Plugin URI: https://example.com/leadrouter
 * Description: Розподіл лідів між партнерами за групами з логами та адмін-інтерфейсом.
 * Version: 1.1.0
 * Author: Maks Devda
 * Author URI: https://example.com
 * License: GPLv2 or later
 * Text Domain: leadrouter
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}


define('LEADROUTER_VERSION', '1.1.0');
define('LEADROUTER_PLUGIN_FILE', __FILE__);
define('LEADROUTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LEADROUTER_PLUGIN_URL', plugin_dir_url(__FILE__));

/** i18n: вантажимо переклади на init */
function leadrouter_load_textdomain()
{
    load_plugin_textdomain('leadrouter', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

add_action('init', 'leadrouter_load_textdomain');

/** Composer autoload (опційно) */
if (file_exists(LEADROUTER_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once LEADROUTER_PLUGIN_DIR . 'vendor/autoload.php';
}

/** Core include-и */
require_once LEADROUTER_PLUGIN_DIR . 'includes/class-leadrouter-cpt.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/class-leadrouter-admin.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/class-leadrouter-transform.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/class-leadrouter-sender-test.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/class-leadrouter-sender-test-http.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/functions-leadrouter.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/leadrouter-main.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/helpers.php';

require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-hooks.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_dispatcher_eff.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-partners.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_sender_light.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-flow.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_cron_new_leads.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_cron_await_leads.php';

if (is_admin()) {
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter_leads_table.php';
    // require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter_logs_table.php';
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter_leads_stats.php';
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter-logviewer.php';

    LeadRouter_LogViewer::init();

}

/** Carbon Fields + custom fields */
add_action('after_setup_theme', function () {
    if (class_exists('\Carbon_Fields\Carbon_Fields')) {
        \Carbon_Fields\Carbon_Fields::boot();
        if (function_exists('leadrouter_create_custom_fields')) {
            leadrouter_create_custom_fields();
        }
    }
}, 11);

/** DB install/upgrade */
function leadrouter_install_db()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $table_logs = $wpdb->prefix . 'leadrouter_logs';
    $table_groups = $wpdb->prefix . 'leadrouter_groups';
    $table_state = $wpdb->prefix . 'leadrouter_state';
    $table_leads = $wpdb->prefix . 'leadrouter_leads';
    $table_partner_logs = $wpdb->prefix . 'leadrouter_partner_logs';
    $table_send_log = $wpdb->prefix . 'leadrouter_send_log';

    $sql = "
CREATE TABLE {$table_send_log} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT(20) UNSIGNED NOT NULL,
  group_id BIGINT(20) UNSIGNED NULL,
  partner_id BIGINT(20) UNSIGNED NOT NULL,

  delivery_uuid CHAR(64) NOT NULL,
  attempt_no INT(10) UNSIGNED NOT NULL,
  attempted_at DATETIME NOT NULL,
  dispatch_method VARCHAR(20) NOT NULL DEFAULT 'sender',

  request_json LONGTEXT NULL,          -- payload з маскуванням PII
  response_excerpt TEXT NULL,          -- урізана відповідь або 'HTML_SAVED:<url>'
  http_code SMALLINT(5) UNSIGNED NULL,
  content_type VARCHAR(100) NULL,
  latency_ms INT NULL,

  status VARCHAR(30) NOT NULL,         -- ok|temp_fail|perm_fail|duplicate|temp_fail_exhausted
  reason_code VARCHAR(50) NULL,        -- HTTP_5XX|TIMEOUT|UNAUTHORIZED|UNPROCESSABLE_ENTITY|...
  retry_after_s INT NULL,

  final_flag TINYINT(1) NOT NULL DEFAULT 0,  -- 0=спроба, 1=підсумок
  final_status VARCHAR(30) NULL,             -- OK|PERM_FAIL|TEMP_FAIL_EXHAUSTED|DUPLICATE

  PRIMARY KEY (id),
  KEY idx_lead_partner (lead_id, partner_id),
  KEY idx_group_id (group_id),
  KEY idx_attempted_at (attempted_at),
  KEY idx_status (status),
  KEY idx_reason_code (reason_code),
  KEY idx_delivery_uuid (delivery_uuid),

  UNIQUE KEY uniq_delivery_ok (delivery_uuid, final_flag, final_status)
) ENGINE=InnoDB {$charset_collate};
";
    dbDelta($sql);


    // 1) Логи партнерських відправок
    $sql = "
CREATE TABLE {$table_partner_logs} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id BIGINT(20) UNSIGNED NOT NULL,
  group_id BIGINT(20) UNSIGNED NOT NULL,
  partner_id BIGINT(20) UNSIGNED NOT NULL,

  attempt_no INT(10) UNSIGNED NOT NULL DEFAULT 1,
  attempted_at DATETIME NOT NULL,
  dispatch_method VARCHAR(20) NOT NULL DEFAULT 'script',

  request_json LONGTEXT NULL,
  response_json LONGTEXT NULL,
  http_code SMALLINT(5) UNSIGNED NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'queued',

  error_code VARCHAR(50) NULL,
  error_details TEXT NULL,
  state_filter VARCHAR(50) NULL,
  is_skipped TINYINT(1) NOT NULL DEFAULT 0,
  error_message VARCHAR(255) NULL,

  PRIMARY KEY (id),
  KEY idx_lead_id (lead_id),
  KEY idx_partner_id (partner_id),
  KEY idx_group_id (group_id),
  KEY idx_attempted_at (attempted_at),
  KEY idx_status (status),
  KEY idx_error_code (error_code),
  KEY idx_is_skipped (is_skipped)
) ENGINE=InnoDB {$charset_collate};
";
    dbDelta($sql);


    $sql = "
CREATE TABLE {$table_leads} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

  name VARCHAR(191) NOT NULL,
  email VARCHAR(191) NULL,
  phone VARCHAR(50) NULL,

  est_ship_date DATE NULL,

  vehicle_bodytype   VARCHAR(50)  NULL,
  vehicle_year       SMALLINT(5) UNSIGNED NULL,
  vehicle_brand      VARCHAR(100) NULL,
  vehicle_model      VARCHAR(100) NULL,
  vehicle_condition  VARCHAR(50)  NULL,

  from_city  VARCHAR(100) NULL,
  from_state VARCHAR(100) NULL,
  from_zip   VARCHAR(20)  NULL,

  to_city  VARCHAR(100) NULL,
  to_state VARCHAR(100) NULL,
  to_zip   VARCHAR(20)  NULL,

  created_at      DATETIME NOT NULL,
  sent_at         DATETIME NULL,
  dispatch_method VARCHAR(20) NOT NULL DEFAULT 'manual',
  crm_response_json LONGTEXT NULL,

  response_status VARCHAR(50) NOT NULL DEFAULT 'new',
  partner_id      BIGINT(20) UNSIGNED NULL,

  -- життєвий цикл для кронів
  status          VARCHAR(32)  NOT NULL DEFAULT 'new',
  attempts_total  INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME NULL,
  last_error_code VARCHAR(64)  NOT NULL DEFAULT '',
  last_error_at   DATETIME NULL,
  await_groups    LONGTEXT NULL,

  -- кешований підсумок, кому відправили (JSON)
  sent_summary_json LONGTEXT NULL,

  -- UTM/атрибуція (JSON)
  utm_json LONGTEXT NULL,

  PRIMARY KEY (id),
  KEY idx_partner_id   (partner_id),
  KEY idx_created_at   (created_at),
  KEY idx_status_next  (status, next_attempt_at)
) {$charset_collate};
";
    dbDelta($sql);

    // 3) Загальні логи (події) — ВАЖЛИВО: payload присутній
    $sql = "
    CREATE TABLE {$table_logs} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      lead_id BIGINT(20) UNSIGNED NOT NULL,
      partner_id LONGTEXT NULL,
      group_id BIGINT(20) UNSIGNED NOT NULL,
      assigned_at DATETIME NOT NULL,
      status VARCHAR(50) NOT NULL DEFAULT 'assigned',
      payload LONGTEXT NULL,
      PRIMARY KEY (id),
      KEY idx_lead_id (lead_id),
      KEY idx_group_id (group_id),
      KEY idx_assigned_at (assigned_at),
      KEY idx_status (status)
    ) ENGINE=InnoDB {$charset_collate};
    ";
    dbDelta($sql);

    // 4) Групи
    $sql = "
    CREATE TABLE {$table_groups} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT(20) UNSIGNED NOT NULL,
      name VARCHAR(191) NOT NULL,
      weight_1 INT NOT NULL DEFAULT 0,
      weight_2 INT NOT NULL DEFAULT 0,
      weight_3 INT NOT NULL DEFAULT 0,
      weight_4 INT NOT NULL DEFAULT 0,
      weight_5 INT NOT NULL DEFAULT 0,
      weight_6 INT NOT NULL DEFAULT 0,
      weight_7 INT NOT NULL DEFAULT 0,
      eff BIGINT(20) NOT NULL DEFAULT 0,
      active TINYINT(1) NOT NULL DEFAULT 1,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_post_id (post_id),
      KEY idx_active (active)
    ) ENGINE=InnoDB {$charset_collate};
    ";
    dbDelta($sql);

    // 5) Службовий стан
    $sql = "
    CREATE TABLE {$table_state} (
      `key` VARCHAR(64) NOT NULL,
      val_int BIGINT(20) NOT NULL DEFAULT 0,
      updated_at DATETIME NULL,
      PRIMARY KEY (`key`)
    ) ENGINE=InnoDB {$charset_collate};
    ";
    dbDelta($sql);
}

/** Перевірка/апґрейд версії схеми */
function leadrouter_check_version()
{
    $installed = get_option('leadrouter_version');
    if ($installed !== LEADROUTER_VERSION) {
        leadrouter_install_db();
        update_option('leadrouter_version', LEADROUTER_VERSION);
    }
}

add_action('plugins_loaded', 'leadrouter_check_version');

/** Активація/деактивація */
register_activation_hook(__FILE__, function () {
    leadrouter_install_db();
    update_option('leadrouter_version', LEADROUTER_VERSION);
    leadrouter_register_cpts();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/** Init: CPT + Hooks + Admin */
add_action('init', 'leadrouter_register_cpts');
add_action('init', ['LeadRouter_Admin', 'add_scripts']);
LeadRouter_Hooks::init();
LeadRouter_Cron_New_Leads::init();
LeadRouter_Cron_Await_Leads::init();


add_action('admin_menu', ['LeadRouter_Admin', 'register_menus']);



/** Підписка на cron-воркер черги (для queued відправок) */
add_action('leadrouter_queue_send', [LeadRouter_Flow::class, 'cron_send_worker'], 10, 5);

/* ===================== ТЕСТ/СЕРВІС ХУКИ ===================== */

/** SEED UI: /wp-admin/?flow_seed=1&_wpnonce=...  */
add_action('admin_init', function () {
    LeadRouter_Flow::run_seed_ui(20, [
        'group_meta_key' => '_leadrouter_partner_group',
        'statuses' => ['queued', 'sent', 'accepted'],
        'initial_status' => 'sent',
        'dispatch_method' => 'generate'
    ]);
}, 20);





/** PURGE із nonce: /wp-admin/?flow_purge=1&_wpnonce=... */
add_action('admin_init', function () {
    if (!is_admin()) return;
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['flow_purge']) || $_GET['flow_purge'] !== '1') return;

    if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'leadrouter_flow_purge')) {
        wp_die(
            '<h2>LeadRouter Flow</h2><p>Невірний або відсутній nonce. Операцію скасовано.</p><p><a href="' . esc_url(admin_url()) . '">← Повернутись в адмінку</a></p>',
            'LeadRouter Flow',
            403
        );
    }

    $res = LeadRouter_Flow::purge_all_leads_and_logs(['confirm' => true]);
    if (is_wp_error($res)) {
        wp_die(
            '<h2>LeadRouter Flow</h2><p style="color:#b00;">Помилка: ' . esc_html($res->get_error_message()) . '</p><p><a href="' . esc_url(admin_url()) . '">← Повернутись в адмінку</a></p>'
        );
    }

    $ts = esc_html($res['timestamp_est'] ?? '');
    $lead = esc_html($res['tables']['leads'] ?? '');
    $plog = esc_html($res['tables']['partner_logs'] ?? '');
    $glog = esc_html($res['tables']['logs'] ?? '');
    $grp = esc_html($res['tables']['groups'] ?? '');

    wp_die(
        '<h2>LeadRouter Flow</h2>'
        . '<p>Всі ліди, логи та коефіцієнти eff успішно обнулені.</p>'
        . ($ts ? '<p><small>EST: ' . $ts . '</small></p>' : '')
        . '<details style="margin-top:10px;"><summary>Деталі</summary>'
        . '<ul>'
        . '<li>Leads: ' . $lead . '</li>'
        . '<li>Partner logs: ' . $plog . '</li>'
        . '<li>Logs: ' . $glog . '</li>'
        . '<li>Groups: ' . $grp . ' (eff=0, active=1)</li>'
        . '</ul>'
        . '</details>'
        . '<p><a href="' . esc_url(admin_url('admin.php?page=leadrouter')) . '">← Повернутись в адмінку</a></p>'
    );
}, 30);

/** Нотіси (кнопки) на сторінці LeadRouter */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    if (empty($_GET['page']) || $_GET['page'] !== 'leadrouter') return;

    $purge_url = wp_nonce_url(add_query_arg(['flow_purge' => '1'], admin_url()), 'leadrouter_flow_purge');
    echo '<div class="notice notice-warning" style="padding:10px 12px;">'
        . '<p><strong>LeadRouter:</strong> Тестове очищення даних (purge) видалить усі ліди та логи і скине ефективності груп. Операція незворотна.</p>'
        . '<p><a class="button button-secondary" href="' . esc_url($purge_url) . '" onclick="return confirm(\'Підтвердити повне очищення?\');">Запустити PURGE</a></p>'
        . '</div>';

    $seed_url = wp_nonce_url(add_query_arg(['flow_seed' => '1'], admin_url()), 'leadrouter_flow_seed');
    echo '<div class="notice notice-info" style="padding:10px 12px;">'
        . '<p><strong>LeadRouter:</strong> Згенерувати тестові ліди і розіслати партнерам?</p>'
        . '<p><a class="button button-primary" href="' . esc_url($seed_url) . '" onclick="return confirm(\'Створити 20 випадкових лідів і розіслати? (EST)\');">Запустити seed</a></p>'
        . '</div>';
});



add_filter('wp_mail_return_path', function() {
    return 'api@highpriorityleads.com';
});

