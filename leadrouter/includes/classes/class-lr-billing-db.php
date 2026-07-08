<?php
/**
 * LeadRouter Billing — інсталяція схеми БД для модуля білінгу партнерів.
 *
 * Таблиці:
 *   - {prefix}leadrouter_partner_billing       — баланс і налаштування партнера (1 рядок на партнера)
 *   - {prefix}leadrouter_billing_transactions  — всі транзакції (append-only, без UPDATE)
 *   - {prefix}leadrouter_billing_audit_log     — детальний audit trail (append-only, без UPDATE)
 *   - {prefix}leadrouter_billing_errors         — помилки і аномалії білінгу
 *   - {prefix}leadrouter_stripe_payments        — лог Stripe-операцій
 *
 * Викликається з leadrouter_install_db() у leadrouter.php (через leadrouter_install_billing_db()).
 * Використовує dbDelta() — тому формат SQL має суворо відповідати вимогам WordPress.
 */

defined('ABSPATH') || exit;

/**
 * Обгортка над dbDelta: прибирає інлайн-коментарі «-- ...» перед діффом.
 *
 * Важливо: dbDelta() при ПОРІВНЯННІ наявної таблиці зі схемою не розуміє рядки
 * з інлайн-коментарями і через це не помічає нових колонок (тобто не доганяє
 * схему на апґрейді). На сам CREATE коментарі не впливають. Тому коментарі
 * лишаємо у вихідному коді для читабельності, але в dbDelta віддаємо чистий SQL.
 */
function leadrouter_billing_dbdelta(string $sql)
{
    $sql = preg_replace('/--[^\r\n]*/', '', $sql);
    return dbDelta($sql);
}

/**
 * Створити/оновити таблиці білінгу.
 * Безпечно викликати повторно — dbDelta() лише доганяє схему.
 */
function leadrouter_install_billing_db()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // Не хардкодимо w4pMd_ — беремо реальний префікс з $wpdb
    $table_partner_billing = $wpdb->prefix . 'leadrouter_partner_billing';
    $table_transactions    = $wpdb->prefix . 'leadrouter_billing_transactions';
    $table_audit_log       = $wpdb->prefix . 'leadrouter_billing_audit_log';
    $table_errors          = $wpdb->prefix . 'leadrouter_billing_errors';
    $table_stripe_payments = $wpdb->prefix . 'leadrouter_stripe_payments';
    $table_stripe_events   = $wpdb->prefix . 'leadrouter_stripe_events';

    // 1) Баланс і налаштування партнера — 1 рядок на партнера (мутабельна таблиця)
    $sql = "
CREATE TABLE {$table_partner_billing} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id BIGINT(20) UNSIGNED NOT NULL,

  balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,          -- поточний баланс
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  email VARCHAR(191) NULL,                              -- email партнера для сповіщень білінгу
  partner_display_name VARCHAR(191) NULL,              -- ім'я партнера для відображення в листах ({partner_name})

  lead_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,       -- ціна ліда пн-пт (weekday)
  lead_price_saturday DECIMAL(8,4) NULL DEFAULT NULL,   -- ціна ліда в суботу (NULL = використати lead_price)
  lead_price_sunday DECIMAL(8,4) NULL DEFAULT NULL,     -- ціна ліда в неділю (NULL = використати lead_price)
  min_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,      -- поріг для auto-charge (balance < min_balance)

  auto_charge_enabled TINYINT(1) NOT NULL DEFAULT 0,    -- чи дозволено Stripe auto-charge
  auto_charge_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,-- сума поповнення при auto-charge

  stripe_customer_id VARCHAR(64) NULL,                  -- Stripe customer (cus_...)
  stripe_payment_method_id VARCHAR(64) NULL,            -- збережений спосіб оплати (pm_...)
  stripe_card_brand VARCHAR(20) NULL,                   -- бренд картки для показу (visa/mastercard...)
  stripe_card_last4 CHAR(4) NULL,                       -- останні 4 цифри для показу

  allow_negative_balance TINYINT(1) NOT NULL DEFAULT 0,    -- дозволити від'ємний баланс (не зупиняти партнера)
  disable_low_balance_email TINYINT(1) NOT NULL DEFAULT 0, -- не надсилати лист про низький баланс
  deactivated_by_billing TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = партнера зупинено білінгом

  notified_low_balance TINYINT(1) NOT NULL DEFAULT 0,      -- чи вже надіслано лист про низький баланс
  notified_stopped TINYINT(1) NOT NULL DEFAULT 0,          -- чи вже надіслано лист про зупинку відправки
  notified_admin_negative TINYINT(1) NOT NULL DEFAULT 0,   -- чи вже сповіщено адміна про від'ємний баланс
  notified_charge_failed TINYINT(1) NOT NULL DEFAULT 0,    -- 1 = у цьому епізоді auto-charge вже невдало пробували (анти-спам Stripe)

  status VARCHAR(20) NOT NULL DEFAULT 'active',         -- active|suspended|blocked
  last_charged_at DATETIME NULL,                        -- коли востаннє списали за лід
  last_topup_at DATETIME NULL,                          -- коли востаннє поповнили

  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,

  PRIMARY KEY (id),
  UNIQUE KEY uniq_partner_id (partner_id),              -- один білінговий рядок на партнера
  KEY idx_status (status),
  KEY idx_deactivated (deactivated_by_billing),
  KEY idx_balance (balance),
  KEY idx_auto_charge (auto_charge_enabled),
  KEY idx_stripe_customer (stripe_customer_id)
) ENGINE=InnoDB {$charset_collate};
";
    leadrouter_billing_dbdelta($sql);

    // 2) Транзакції — APPEND-ONLY. Жодних UPDATE: тільки INSERT нових рядків.
    //    Кожне списання/поповнення фіксує balance_before/after для повної відновлюваності.
    $sql = "
CREATE TABLE {$table_transactions} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id BIGINT(20) UNSIGNED NOT NULL,
  lead_id BIGINT(20) UNSIGNED NULL,                     -- заповнюється для типу 'charge' за лід

  type VARCHAR(30) NOT NULL,                            -- spend|manual_debit|topup|auto_charge|credit|refund|adjustment
  amount DECIMAL(12,2) NOT NULL,                        -- знакова сума (+поповнення / -списання)
  balance_before DECIMAL(12,2) NOT NULL,
  balance_after DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'USD',

  idempotency_key VARCHAR(191) NOT NULL,                -- захист від подвійного списання
  reference_type VARCHAR(30) NULL,                      -- lead|stripe_payment|manual|...
  reference_id VARCHAR(64) NULL,                        -- id пов'язаної сутності
  description VARCHAR(255) NULL,

  created_at DATETIME NOT NULL,                         -- append-only: updated_at немає навмисно

  PRIMARY KEY (id),
  UNIQUE KEY uniq_idempotency_key (idempotency_key),    -- ідемпотентність транзакцій
  UNIQUE KEY uniq_partner_lead (partner_id, lead_id),   -- захист від подвійного списання за один лід (lead_id NULL не конфліктує)
  KEY idx_partner_id (partner_id),
  KEY idx_lead_id (lead_id),
  KEY idx_type (type),
  KEY idx_reference (reference_type, reference_id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB {$charset_collate};
";
    leadrouter_billing_dbdelta($sql);

    // 3) Audit log — APPEND-ONLY. Детальний слід «хто/що/коли» по кожній зміні білінгу.
    $sql = "
CREATE TABLE {$table_audit_log} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id BIGINT(20) UNSIGNED NULL,
  transaction_id BIGINT(20) UNSIGNED NULL,              -- посилання на leadrouter_billing_transactions.id

  actor_type VARCHAR(30) NOT NULL,                      -- system|cron|admin|stripe_webhook
  actor_id BIGINT(20) UNSIGNED NULL,                    -- WP user id, якщо дію зробив адмін
  action VARCHAR(50) NOT NULL,                          -- charge_lead|auto_charge|balance_adjust|...

  entity_type VARCHAR(30) NULL,                         -- partner_billing|transaction|stripe_payment
  entity_id BIGINT(20) UNSIGNED NULL,

  old_value LONGTEXT NULL,                              -- стан до зміни (JSON)
  new_value LONGTEXT NULL,                              -- стан після зміни (JSON)
  context_json LONGTEXT NULL,                           -- довільний контекст події

  ip_address VARCHAR(45) NULL,                          -- IPv4/IPv6
  created_at DATETIME NOT NULL,                         -- append-only: updated_at немає навмисно

  PRIMARY KEY (id),
  KEY idx_partner_id (partner_id),
  KEY idx_transaction_id (transaction_id),
  KEY idx_actor (actor_type, actor_id),
  KEY idx_action (action),
  KEY idx_entity (entity_type, entity_id),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB {$charset_collate};
";
    leadrouter_billing_dbdelta($sql);

    // 4) Помилки і аномалії білінгу — для моніторингу та ручного розбору.
    $sql = "
CREATE TABLE {$table_errors} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id BIGINT(20) UNSIGNED NULL,
  lead_id BIGINT(20) UNSIGNED NULL,
  transaction_id BIGINT(20) UNSIGNED NULL,

  error_code VARCHAR(50) NOT NULL,                      -- INSUFFICIENT_FUNDS|STRIPE_DECLINED|DUPLICATE_CHARGE|...
  error_message TEXT NULL,
  severity VARCHAR(20) NOT NULL DEFAULT 'error',        -- warning|error|critical
  source VARCHAR(30) NOT NULL DEFAULT 'billing',        -- billing|stripe|cron

  context_json LONGTEXT NULL,
  is_resolved TINYINT(1) NOT NULL DEFAULT 0,
  resolved_at DATETIME NULL,

  created_at DATETIME NOT NULL,

  PRIMARY KEY (id),
  KEY idx_partner_id (partner_id),
  KEY idx_lead_id (lead_id),
  KEY idx_transaction_id (transaction_id),
  KEY idx_error_code (error_code),
  KEY idx_severity (severity),
  KEY idx_source (source),
  KEY idx_is_resolved (is_resolved),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB {$charset_collate};
";
    leadrouter_billing_dbdelta($sql);

    // 5) Лог Stripe-операцій — повний запит/відповідь по кожному платежу.
    $sql = "
CREATE TABLE {$table_stripe_payments} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  partner_id BIGINT(20) UNSIGNED NOT NULL,
  transaction_id BIGINT(20) UNSIGNED NULL,              -- пов'язана транзакція поповнення

  stripe_payment_intent_id VARCHAR(64) NULL,            -- pi_...
  stripe_charge_id VARCHAR(64) NULL,                    -- ch_...
  stripe_customer_id VARCHAR(64) NULL,                  -- cus_...

  idempotency_key VARCHAR(191) NOT NULL,                -- Stripe-Idempotency-Key
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',

  status VARCHAR(30) NOT NULL DEFAULT 'pending',        -- pending|succeeded|failed|refunded|canceled
  failure_code VARCHAR(50) NULL,
  failure_message VARCHAR(255) NULL,

  request_json LONGTEXT NULL,                           -- payload запиту до Stripe
  response_json LONGTEXT NULL,                          -- сира відповідь Stripe

  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,                             -- статус оновлюється з вебхуків

  PRIMARY KEY (id),
  UNIQUE KEY uniq_idempotency_key (idempotency_key),    -- ідемпотентність Stripe-запитів
  UNIQUE KEY uniq_payment_intent (stripe_payment_intent_id),
  KEY idx_partner_id (partner_id),
  KEY idx_transaction_id (transaction_id),
  KEY idx_charge_id (stripe_charge_id),
  KEY idx_status (status),
  KEY idx_created_at (created_at)
) ENGINE=InnoDB {$charset_collate};
";
    leadrouter_billing_dbdelta($sql);

    // 6) Лог отриманих Stripe-вебхуків — idempotency проти подвійної обробки події.
    //    UNIQUE(stripe_event_id) гарантує, що кожну подію обробляємо рівно один раз.
    $sql = "
CREATE TABLE {$table_stripe_events} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_event_id VARCHAR(64) NOT NULL,                 -- evt_... (унікальний id події Stripe)
  event_type VARCHAR(60) NULL,                          -- payment_intent.succeeded|charge.refunded|...
  partner_id BIGINT(20) UNSIGNED NULL,                  -- розвʼязаний партнер (може бути NULL)

  received_at DATETIME NOT NULL,                        -- коли вебхук прийнято
  processed_at DATETIME NULL,                           -- коли завершено обробку
  status VARCHAR(20) NOT NULL DEFAULT 'received',       -- received|processed|ignored|error

  payload_excerpt TEXT NULL,                            -- урізаний payload для дебагу

  PRIMARY KEY (id),
  UNIQUE KEY uniq_event (stripe_event_id),              -- захист від подвійної обробки
  KEY idx_event_type (event_type),
  KEY idx_partner_id (partner_id),
  KEY idx_status (status),
  KEY idx_received_at (received_at)
) ENGINE=InnoDB {$charset_collate};
";
    leadrouter_billing_dbdelta($sql);

    // Прибрати застарілі колонки механізму паузи + догнати нові колонки/таблиці
    leadrouter_billing_db_migrate();
}

/**
 * Міграція схеми білінгу:
 *   - додає нові колонки, які dbDelta інколи не доганяє на апґрейді (ADD COLUMN);
 *   - видаляє застарілі колонки механізму «паузи» (DROP COLUMN).
 *
 * dbDelta() НЕ видаляє колонки і ненадійно додає їх при наявних інлайн-коментарях,
 * тому критичні зміни робимо вручну через ALTER TABLE. Безпечно викликати повторно:
 * кожну колонку перевіряємо через SHOW COLUMNS і чіпаємо лише якщо потрібно.
 * Механізм паузи замінено на deactivated_by_billing.
 */
function leadrouter_billing_db_migrate()
{
    global $wpdb;
    $table = $wpdb->prefix . 'leadrouter_partner_billing';

    // Нові колонки: ім'я → DDL для ADD COLUMN. Whitelist жорсткий — лише ці назви.
    $add_columns = [
        // Анти-спам auto-charge: одна спроба на епізод падіння балансу
        'notified_charge_failed' => 'ADD COLUMN `notified_charge_failed` TINYINT(1) NOT NULL DEFAULT 0',
        // Ім'я партнера для відображення в листах ({partner_name})
        'partner_display_name'   => 'ADD COLUMN `partner_display_name` VARCHAR(191) NULL',
        // Окрема ціна ліда на вихідні (NULL = fallback на weekday lead_price)
        'lead_price_saturday'    => 'ADD COLUMN `lead_price_saturday` DECIMAL(8,4) NULL DEFAULT NULL',
        'lead_price_sunday'      => 'ADD COLUMN `lead_price_sunday` DECIMAL(8,4) NULL DEFAULT NULL',
        // Бренд/останні цифри картки для показу в кабінеті (картку НЕ зберігаємо)
        'stripe_card_brand'      => 'ADD COLUMN `stripe_card_brand` VARCHAR(20) NULL',
        'stripe_card_last4'      => 'ADD COLUMN `stripe_card_last4` CHAR(4) NULL',
    ];

    foreach ($add_columns as $col => $ddl) {
        // Назва таблиці не може бути плейсхолдером, ім'я колонки передаємо як %s
        $exists = $wpdb->get_results(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col)
        );

        if (empty($exists)) {
            // $ddl — зі статичного білого списку вище, тож інтерполяція безпечна
            $wpdb->query("ALTER TABLE {$table} {$ddl}");
        }
    }

    // Колонки, що більше не потрібні (старі назви механізму паузи).
    // Whitelist навмисно жорсткий — лише ці 3 назви.
    $drop_columns = [
        'billing_paused',
        'paused_reason',
        'paused_at',
    ];

    foreach ($drop_columns as $col) {
        // Назва таблиці не може бути плейсхолдером, ім'я колонки передаємо як %s
        $exists = $wpdb->get_results(
            $wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $col)
        );

        if (!empty($exists)) {
            // $col — зі статичного білого списку вище, тож інтерполяція безпечна
            $wpdb->query("ALTER TABLE {$table} DROP COLUMN `{$col}`");
        }
    }

    // Нова таблиця подій Stripe — підстраховка, якщо dbDelta не створив її на апґрейді.
    // Створюємо лише якщо її ще немає (CREATE TABLE IF NOT EXISTS ідемпотентний).
    $events_table = $wpdb->prefix . 'leadrouter_stripe_events';
    $events_exists = $wpdb->get_var(
        $wpdb->prepare('SHOW TABLES LIKE %s', $events_table)
    );
    if (!$events_exists) {
        $charset_collate = $wpdb->get_charset_collate();
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$events_table} (
              id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
              stripe_event_id VARCHAR(64) NOT NULL,
              event_type VARCHAR(60) NULL,
              partner_id BIGINT(20) UNSIGNED NULL,
              received_at DATETIME NOT NULL,
              processed_at DATETIME NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'received',
              payload_excerpt TEXT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uniq_event (stripe_event_id),
              KEY idx_event_type (event_type),
              KEY idx_partner_id (partner_id),
              KEY idx_status (status),
              KEY idx_received_at (received_at)
            ) ENGINE=InnoDB {$charset_collate}"
        );
    }
}
