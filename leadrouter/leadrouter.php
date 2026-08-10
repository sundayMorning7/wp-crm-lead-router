<?php
/**
 * Plugin Name: LeadRouter by Maks Devda
 * Plugin URI: https://example.com/leadrouter
 * Description: Розподіл лідів між партнерами за групами з логами та адмін-інтерфейсом.
 * Version: 1.9.0
 * Author: Maks Devda
 * Author URI: https://example.com
 * License: GPLv2 or later
 * Text Domain: leadrouter
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}


define('LEADROUTER_VERSION', '1.9.2');
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

require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-dns-fix.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-hooks.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-shared-sync.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-slot-planner.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-slot-sim.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_dispatcher_eff.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-partners.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_sender_light.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-flow.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_cron_new_leads.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_cron_await_leads.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter_cron_error_leads.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-billing-db.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-billing.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-stripe.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-stripe-webhook.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-billing-mailer.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-billing-cron.php';

// Кабінет партнера: звʼязок user↔partner, роль, доступ
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-partner-auth.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-partner-portal.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-xlsx.php';
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-partner-card.php';

// Адмін: перегляд кабінету партнера («Login as partner»), лише manage_options
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-partner-impersonate.php';

// Скарги партнерів на ліди (core: submit/валідація/лист + AJAX-ендпоінт)
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-lr-complaints.php';

// Обмежений доступ працівника (роль leadrouter_manager: тільки сторінки LeadRouter)
require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-restricted-access.php';

// WP-CLI команди (leadrouter simulate-*, billing-test-setup). Файл сам захищений WP_CLI-гардом.
if (defined('WP_CLI') && WP_CLI) {
    require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-cli.php';
}

if (is_admin()) {
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter_leads_table.php';
    // require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter_logs_table.php';
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter_leads_stats.php';
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter-logviewer.php';

    // Кнопка Вкл/Выкл группы в списке групп
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-leadrouter-group-toggle.php';

    // Подключаем CRUD для лидов (LeadRouter_Leads)
    require_once LEADROUTER_PLUGIN_DIR . 'includes/classes/class-leadrouter-lead.php';

    // Кастомная колонка и AJAX для тестовой отправки лида партнёру
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/lr-send-test-lead.php';

    // Сторінка білінгу партнера
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/page-partner-billing.php';

    // Метабокс «Доступ до кабінету» на екрані партнера
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/lr-partner-user-link.php';
    LR_Partner_User_Link::register();

    // Загальний дашборд білінгу (LeadRouter → Billing)
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/page-billing-dashboard.php';

    // Звіт білінгу за місяць (LeadRouter → Report)
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/page-billing-report.php';

    // Резервні групи переїхали у вкладку «Резервні групи» налаштувань
    // (Carbon Fields, див. leadrouter_create_custom_fields). Стара окрема
    // сторінка includes/admin/page-settings.php більше не підключається.

    // Пісочниця плану слотів (LeadRouter → Симулятор слотів), read-only
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/page-slot-simulator.php';

    // Адмін-інтерфейс заявок-скарг (LeadRouter → Complaints)
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/page-complaints.php';

    // Рядок з поточним часом плагіна (America/New_York) на всіх сторінках LeadRouter
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/lr-est-clock.php';

    // Панель балансування на головній сторінці LeadRouter
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-lr-balance-panel.php';
    LR_Balance_Panel::register();

    // Склад групи: додавання/вилучення партнерів на сторінці групи
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-lr-group-partners.php';
    LR_Group_Partners::register();

    // Тег «Власник»: канонічний вигляд при збереженні + автопідказка
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-lr-partner-owner.php';
    LR_Partner_Owner::register();

    // Список партнерів: колонка «Група» + сортування «активні → за групою»
    require_once LEADROUTER_PLUGIN_DIR . 'includes/admin/class-lr-partner-columns.php';
    LR_Partner_Columns::register();

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

  -- нормалізовані ключі для пошуку дублів (телефон — останні 10 цифр,
  -- email — lower/trim). Заповнюються при створенні ліда + backfill.
  phone_norm VARCHAR(20) NULL,
  email_norm VARCHAR(191) NULL,

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

  -- скільки копій ліда планували продати і скільки продали
  -- (для класичних груп target = 1, shared-режим — фаза 1)
  copies_target TINYINT UNSIGNED NOT NULL DEFAULT 1,
  copies_sold   TINYINT UNSIGNED NOT NULL DEFAULT 0,

  -- кешований підсумок, кому відправили (JSON)
  sent_summary_json LONGTEXT NULL,

  PRIMARY KEY (id),
  KEY idx_partner_id   (partner_id),
  KEY idx_created_at   (created_at),
  KEY idx_status_next  (status, next_attempt_at),
  KEY idx_phone_norm   (phone_norm),
  KEY idx_email_norm   (email_norm)
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

      -- shared-розподіл (джерело правди — post meta групи, сюди синкається:
      -- mode/share_n/daily_volume на межі доби EST, coef — негайно)
      mode VARCHAR(10) NOT NULL DEFAULT 'classic',
      share_n INT UNSIGNED NOT NULL DEFAULT 1,
      daily_volume INT UNSIGNED NOT NULL DEFAULT 0,
      coef DECIMAL(6,2) NOT NULL DEFAULT 1.00,

      -- м'яка квота (overflow): після вичерпання daily_volume група може
      -- приймати ліди понад план, поки в партнерів є вільні денні ліміти.
      -- overflow_cap — стеля додаткових лідів (0 = без стелі). Синк — негайно.
      overflow_on TINYINT(1) NOT NULL DEFAULT 0,
      overflow_cap INT UNSIGNED NOT NULL DEFAULT 0,

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

    // 6) Денні FB-показники для білінг-звіту (Ad Spend / Fb leads).
    //    Вводяться вручну в адмінці (один рядок на день, дата в EST).
    $table_daily_adstats = $wpdb->prefix . 'leadrouter_daily_adstats';
    $sql = "
    CREATE TABLE {$table_daily_adstats} (
      stat_date DATE NOT NULL,
      ad_spend DECIMAL(12,2) NOT NULL DEFAULT 0.00,
      fb_leads INT UNSIGNED NOT NULL DEFAULT 0,
      updated_at DATETIME NULL,
      PRIMARY KEY (stat_date)
    ) ENGINE=InnoDB {$charset_collate};
    ";
    dbDelta($sql);

    // 7) Скарги/претензії партнерів на ліди (інбокс: new/read).
    //    Одна скарга на лід — UNIQUE (partner_id, lead_id). Час — EST.
    $table_complaints = $wpdb->prefix . 'leadrouter_lead_complaints';
    $sql = "
    CREATE TABLE {$table_complaints} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      lead_id BIGINT(20) UNSIGNED NOT NULL,
      partner_id BIGINT(20) UNSIGNED NOT NULL,
      topic VARCHAR(191) NOT NULL,
      message TEXT NOT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'new',
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_partner_lead (partner_id, lead_id),
      KEY idx_lead_id (lead_id),
      KEY idx_partner_id (partner_id),
      KEY idx_status (status),
      KEY idx_created_at (created_at)
    ) ENGINE=InnoDB {$charset_collate};
    ";
    dbDelta($sql);

    // 8) Призначення лідів партнерам (append-only). Один рядок = одна копія ліда.
    //    Два UNIQUE — фізичний запобіжник бізнес-правил: той самий лід не може
    //    вдруге піти тому ж партнеру і тому ж власнику (кластеру компаній).
    $table_assignments = $wpdb->prefix . 'leadrouter_lead_assignments';
    $sql = "
    CREATE TABLE {$table_assignments} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      lead_id BIGINT(20) UNSIGNED NOT NULL,
      group_id BIGINT(20) UNSIGNED NOT NULL,
      partner_id BIGINT(20) UNSIGNED NOT NULL,
      owner_id VARCHAR(64) NOT NULL,
      copy_no TINYINT UNSIGNED NOT NULL DEFAULT 1,
      status VARCHAR(20) NOT NULL,
      pick_mode VARCHAR(10) NOT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uniq_lead_partner (lead_id, partner_id),
      UNIQUE KEY uniq_lead_owner (lead_id, owner_id),
      KEY idx_partner_created (partner_id, created_at),
      KEY idx_group_created (group_id, created_at),
      KEY idx_lead (lead_id)
    ) ENGINE=InnoDB {$charset_collate};
    ";
    dbDelta($sql);

    // 9) Журнал змін коефіцієнтів груп і партнерів (хто, коли, старе → нове)
    $table_coef_audit = $wpdb->prefix . 'leadrouter_coef_audit';
    $sql = "
    CREATE TABLE {$table_coef_audit} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      object_type VARCHAR(10) NOT NULL,
      object_id BIGINT(20) UNSIGNED NOT NULL,
      old_val DECIMAL(6,2) NOT NULL,
      new_val DECIMAL(6,2) NOT NULL,
      user_id BIGINT(20) UNSIGNED NOT NULL,
      changed_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY idx_obj (object_type, object_id)
    ) ENGINE=InnoDB {$charset_collate};
    ";
    dbDelta($sql);

    // 10) Колонки, додані після першого релізу.
    //     Через dbDelta їх додавати не можна: на цій схемі (коментарі й порожні
    //     рядки всередині CREATE TABLE) він генерує биті ALTER-и і мовчки
    //     пропускає частину колонок. Тому — явні ідемпотентні ALTER-и.
    leadrouter_add_column_if_missing($table_groups, 'mode', "VARCHAR(10) NOT NULL DEFAULT 'classic' AFTER active");
    leadrouter_add_column_if_missing($table_groups, 'share_n', 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER mode');
    leadrouter_add_column_if_missing($table_groups, 'daily_volume', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER share_n');
    leadrouter_add_column_if_missing($table_groups, 'coef', 'DECIMAL(6,2) NOT NULL DEFAULT 1.00 AFTER daily_volume');

    leadrouter_add_column_if_missing($table_leads, 'copies_target', 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER await_groups');
    leadrouter_add_column_if_missing($table_leads, 'copies_sold', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER copies_target');

    // Колонки анти-дублю (фаза D) — те саме, на випадок якщо dbDelta їх не додав
    leadrouter_add_column_if_missing($table_leads, 'phone_norm', 'VARCHAR(20) NULL AFTER phone');
    leadrouter_add_column_if_missing($table_leads, 'email_norm', 'VARCHAR(191) NULL AFTER phone_norm');
    leadrouter_add_index_if_missing($table_leads, 'idx_phone_norm', '(phone_norm)');
    leadrouter_add_index_if_missing($table_leads, 'idx_email_norm', '(email_norm)');

    // Таблиці модуля білінгу партнерів
    if (function_exists('leadrouter_install_billing_db')) {
        leadrouter_install_billing_db();
    }

    // 1.9.1: група партнера В МОМЕНТ списання — щоб Billing Report не
    // переписував історію заднім числом при переміщенні партнера між групами
    $table_tx = $wpdb->prefix . 'leadrouter_billing_transactions';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_tx)) === $table_tx) {
        leadrouter_add_column_if_missing($table_tx, 'group_id', 'BIGINT UNSIGNED NULL AFTER lead_id');
        leadrouter_add_index_if_missing($table_tx, 'idx_group_id', '(group_id)');
        leadrouter_backfill_tx_group();
    }

    // Роль `partner` для кабінету (ідемпотентно)
    if (class_exists('LR_Partner_Auth')) {
        LR_Partner_Auth::install_role();
    }

    // Одноразове заповнення phone_norm/email_norm для вже існуючих лідів.
    // Тут — лише маленька перша порція (це звичайний запит користувача),
    // основну масу доганяє wp-cron подія.
    leadrouter_backfill_lead_norm(200, 1);
}

/**
 * Додати колонку, якщо її ще немає (ідемпотентно, тільки ADD).
 * $definition — тип + опції + за потреби AFTER (без назви колонки).
 */
function leadrouter_add_column_if_missing(string $table, string $column, string $definition): bool
{
    global $wpdb;

    $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
    if ($exists) {
        return false;
    }

    $wpdb->query("ALTER TABLE {$table} ADD COLUMN `{$column}` {$definition}");

    return true;
}

/**
 * Одноразовий backfill group_id у spend-транзакціях (міграція 1.9.1).
 *
 * Канонічна група пари lead+partner — з ОСТАННЬОГО успішного запису send_log
 * (той самий прийом, що в прод-звіті Daily Breakdown). send_log.group_id
 * історично містить то post_id групи, то внутрішній id рядка leadrouter_groups —
 * CASE зводить обидва варіанти до post_id.
 */
function leadrouter_backfill_tx_group(): void
{
    global $wpdb;

    if (get_option('leadrouter_tx_group_backfilled') === 'done') {
        return;
    }

    $t_tx   = $wpdb->prefix . 'leadrouter_billing_transactions';
    $t_send = $wpdb->prefix . 'leadrouter_send_log';
    $t_grp  = $wpdb->prefix . 'leadrouter_groups';

    $wpdb->query("
        UPDATE {$t_tx} t
        JOIN (
            SELECT s.lead_id, s.partner_id,
                   CASE
                       WHEN gp.ID IS NOT NULL THEN s.group_id
                       WHEN rg.post_id IS NOT NULL AND rg.post_id > 0 THEN rg.post_id
                       ELSE s.group_id
                   END AS group_post_id
              FROM {$t_send} s
              JOIN (
                    SELECT lead_id, partner_id, MAX(id) AS mid
                      FROM {$t_send}
                     WHERE status = 'success'
                     GROUP BY lead_id, partner_id
              ) c ON c.mid = s.id
              LEFT JOIN {$wpdb->posts} gp
                     ON gp.ID = s.group_id AND gp.post_type = 'leadrouter_group'
              LEFT JOIN {$t_grp} rg ON rg.id = s.group_id
        ) src ON src.lead_id = t.lead_id AND src.partner_id = t.partner_id
        SET t.group_id = src.group_post_id
        WHERE t.type = 'spend' AND t.group_id IS NULL
    ");

    update_option('leadrouter_tx_group_backfilled', 'done', false);
}

/** Додати індекс, якщо його ще немає (ідемпотентно) */
function leadrouter_add_index_if_missing(string $table, string $index, string $columns): bool
{
    global $wpdb;

    $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $index));
    if ($exists) {
        return false;
    }

    $wpdb->query("ALTER TABLE {$table} ADD KEY `{$index}` {$columns}");

    return true;
}

/**
 * Backfill колонок phone_norm/email_norm у leads (анти-дубль).
 *
 * Йдемо порційно по курсору id, щоб на великій таблиці не впертись у таймаут:
 * за один прохід обробляємо $batch * $max_batches рядків, решту доганяє
 * одноразова wp-cron подія. Нормалізація — тими самими хелперами, що і при
 * INSERT, щоб бекфіл і нові ліди давали однакові ключі.
 *
 * @return bool true — бекфіл завершено.
 */
function leadrouter_backfill_lead_norm(int $batch = 500, int $max_batches = 10): bool
{
    global $wpdb;

    if (get_option('leadrouter_lead_norm_backfilled') === 'done') {
        return true;
    }

    $table  = $wpdb->prefix . 'leadrouter_leads';
    $cursor = (int)get_option('leadrouter_lead_norm_backfill_cursor', 0);

    for ($i = 0; $i < $max_batches; $i++) {
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, phone, email FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
                $cursor,
                $batch
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            update_option('leadrouter_lead_norm_backfilled', 'done', false);
            return true;
        }

        foreach ($rows as $row) {
            $wpdb->update(
                $table,
                leadrouter_lead_norm_columns($row['phone'] ?? '', $row['email'] ?? ''),
                ['id' => (int)$row['id']],
                ['%s', '%s'],
                ['%d']
            );
            $cursor = (int)$row['id'];
        }

        update_option('leadrouter_lead_norm_backfill_cursor', $cursor, false);
    }

    // Не добігли до кінця таблиці — продовжимо наступним запуском крону
    if (!wp_next_scheduled('leadrouter_backfill_lead_norm_event')) {
        wp_schedule_single_event(time() + 60, 'leadrouter_backfill_lead_norm_event');
    }

    return false;
}

add_action('leadrouter_backfill_lead_norm_event', 'leadrouter_backfill_lead_norm');

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
    if (class_exists('LR_Partner_Auth')) {
        LR_Partner_Auth::install_role();
    }
    if (class_exists('LeadRouter_Restricted_Access')) {
        LeadRouter_Restricted_Access::ensure_role();
    }
    if (class_exists('LR_Partner_Portal')) {
        LR_Partner_Portal::ensure_cabinet_page();
    }
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/** Init: CPT + Hooks + Admin */
add_action('init', 'leadrouter_register_cpts');
add_action('init', ['LeadRouter_Admin', 'add_scripts']);
add_action('init', ['LeadRouter_Admin', 'register_ajax']);

LR_DNS_Fix::init();
LeadRouter_Hooks::init();
LR_Shared_Sync::init();
LR_Partner_Auth::register();
LR_Partner_Portal::register();
LR_Partner_Card::register();
LR_Partner_Impersonate::register();
LR_Complaints::register();
LeadRouter_Cron_New_Leads::init();
LeadRouter_Cron_Await_Leads::init();
LeadRouter_Cron_Error_Leads::init();
LR_Billing_Cron::register();

// Stripe-вебхуки: POST /wp-json/leadrouter/v1/stripe-webhook (захист — підпис Stripe)
add_action('rest_api_init', ['LR_Stripe_Webhook', 'register']);


add_action('admin_menu', ['LeadRouter_Admin', 'register_menus']);



/** Підписка на cron-воркер черги (для queued відправок) */
add_action('leadrouter_queue_send', [LeadRouter_Flow::class, 'cron_send_worker'], 10, 5);

/**
 * Білінг: realtime-списання за лід після УСПІШНОЇ відправки партнеру.
 * leadrouter_after_send стріляє і на успіх, і на помилку — тому списуємо
 * тільки коли $result не WP_Error і містить ok=true. Дублі відсікає сам
 * LR_Billing::deduct_for_lead (UNIQUE partner_id+lead_id).
 */
add_action('leadrouter_after_send', function ($lead_id, $partner_row, $result) {
    if (is_wp_error($result) || empty($result['ok'])) {
        return;
    }
    $partner_id = (int) (is_array($partner_row) ? ($partner_row['partner_id'] ?? 0) : 0);
    if ($partner_id <= 0 || (int) $lead_id <= 0) {
        return;
    }
    // Група в момент відправки — лягає в транзакцію, щоб звіт не залежав
    // від пізніших переміщень партнера між групами
    $group_post_id = (int) (is_array($partner_row) ? ($partner_row['group_post_id'] ?? 0) : 0);
    LR_Billing::deduct_for_lead($partner_id, (int) $lead_id, $group_post_id);
}, 10, 3);

/* ===================== ТЕСТ/СЕРВІС ХУКИ ===================== */

/** SEED UI: /wp-admin/?flow_seed=1&_wpnonce=...  */
add_action('admin_init', function () {
    // DEV ONLY — на продакшні ендпоінт неактивний (та сама логіка, що ховає кнопку нижче).
    if (defined('LEADROUTER_PRODUCTION') && LEADROUTER_PRODUCTION) return;
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
    // DEV ONLY — на продакшні ендпоінт неактивний (та сама логіка, що ховає кнопку нижче).
    if (defined('LEADROUTER_PRODUCTION') && LEADROUTER_PRODUCTION) return;
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

/* Кнопки Seed/Purge з головної сторінки прибрано (артефакт ранніх версій).
 * Самі dev-ендпоінти ?flow_seed=1 / ?flow_purge=1 (з nonce) лишаються робочими
 * поза продом — див. обробники admin_init вище. */



add_filter('wp_mail_return_path', function() {
    return 'api@highpriorityleads.com';
});

add_action('init', function () {

    // Які параметри ловимо
    $keys = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'gclid',
        'fbclid',
        'ttclid',
    ];

    // Чи є хоч один з параметрів у URL
    $has_any = false;
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $has_any = true;
            break;
        }
    }
    if (!$has_any) return;

    // Налаштування cookie
    $days = 30; // скільки днів пам'ятати
    $ttl  = time() + ($days * DAY_IN_SECONDS);

    $cookie_path   = COOKIEPATH ? COOKIEPATH : '/';
    $cookie_domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
    $secure = is_ssl();
    $httponly = true;

    // helper: безпечно дістати GET значення
    $get = function ($key) {
        return (isset($_GET[$key]) && $_GET[$key] !== '')
            ? sanitize_text_field(wp_unslash($_GET[$key]))
            : '';
    };

    $normalize_source = function ($value) {
        $v = strtolower(trim($value));

        $map = [
            'fb'        => 'Facebook',
            'facebook'  => 'Facebook',

            'ig'        => 'Instagram',
            'instagram' => 'Instagram',

            'tt'        => 'TikTok',
            'tiktok'    => 'TikTok',

            'google'    => 'Google',
            'adwords'   => 'Google',
        ];

        return $map[$v] ?? $value;
    };

    // helper: чи всі utm_* порожні в URL
    $utm_keys = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'];
    $utm_all_empty = true;
    foreach ($utm_keys as $k) {
        if ($get($k) !== '') {
            $utm_all_empty = false;
            break;
        }
    }

    // helper: якщо utm порожні — підставляємо source/medium по click id
    $inject_source_by_clickid = function (array &$arr) use ($get, $utm_all_empty) {

        if (!$utm_all_empty) {
            return; // UTM задані — не чіпаємо
        }

        $gclid  = $get('gclid');
        $fbclid = $get('fbclid');
        $ttclid = $get('ttclid');

        // Пріоритет (на випадок якщо раптом прийде 2 одночасно):
        // Google > Facebook > TikTok
        if ($gclid !== '') {
            $arr['utm_source'] = $arr['utm_source'] ?? 'Google';
            $arr['utm_medium'] = $arr['utm_medium'] ?? 'cpc';
            $arr['utm_campaign'] = $arr['utm_campaign'] ?? '';
        } elseif ($fbclid !== '') {
            $arr['utm_source'] = $arr['utm_source'] ?? 'Facebook';
            $arr['utm_medium'] = $arr['utm_medium'] ?? 'cpc';
            $arr['utm_campaign'] = $arr['utm_campaign'] ?? '';
        } elseif ($ttclid !== '') {
            $arr['utm_source'] = $arr['utm_source'] ?? 'TikTok';
            $arr['utm_medium'] = $arr['utm_medium'] ?? 'cpc';
            $arr['utm_campaign'] = $arr['utm_campaign'] ?? '';
        }
    };

    // 1) FIRST TOUCH: записуємо тільки якщо ще нема
    $first_cookie = 'md_utm_first';
    if (empty($_COOKIE[$first_cookie])) {

        $first = [];
        foreach ($keys as $k) {
            $val = $get($k);
            if ($val === '') continue;

            if ($k === 'utm_source') {
                $val = $normalize_source($val);
            }

            if ($k === 'utm_medium') {
                $val = strtolower($val); // cpc, organic, referral
            }

            $first[$k] = $val;
        }

        // інʼєкція utm_source/utm_medium, якщо utm порожні, але є gclid/fbclid/ttclid


        if (!empty($first)) {
            $json = wp_json_encode($first);
            setcookie($first_cookie, $json, $ttl, $cookie_path, $cookie_domain, $secure, $httponly);
            $_COOKIE[$first_cookie] = $json; // щоб було доступно одразу в цьому реквесті
        }
    }

    // 2) LAST TOUCH: перезаписуємо завжди при наявності міток
    $last_cookie = 'md_utm_last';

    $last = [];
    foreach ($keys as $k) {
        $val = $get($k);
        if ($val === '') continue;
        if ($k === 'utm_source') {
            $val = $normalize_source($val);
        }

        if ($k === 'utm_medium') {
            $val = strtolower($val); // cpc, organic, referral
        }

        $last[$k] = $val;
    }

    // інʼєкція utm_source/utm_medium, якщо utm порожні, але є gclid/fbclid/ttclid
    $inject_source_by_clickid($last);

    if (!empty($last)) {
        $json = wp_json_encode($last);
        setcookie($last_cookie, $json, $ttl, $cookie_path, $cookie_domain, $secure, $httponly);
        $_COOKIE[$last_cookie] = $json;
    }

}, 1);
