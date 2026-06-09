<?php
/**
 * LR_Billing — core-логіка білінгу партнерів.
 *
 * Працює з таблицями зі схеми class-lr-billing-db.php:
 *   - {prefix}leadrouter_partner_billing       — баланс + налаштування (мутабельний, 1 рядок/партнер)
 *   - {prefix}leadrouter_billing_transactions  — журнал транзакцій (append-only)
 *   - {prefix}leadrouter_billing_audit_log     — audit trail (append-only)
 *   - {prefix}leadrouter_billing_errors         — помилки/аномалії
 *
 * Усі мутації балансу серіалізуються пострядковим локом:
 *   START TRANSACTION → SELECT ... FOR UPDATE → UPDATE → INSERT → COMMIT.
 * Завдяки цьому одночасні списання за того ж партнера не накладаються (race-safe).
 *
 * Час — EST (America/New_York), як і решта LeadRouter.
 * Методи статичні, у стилі LeadRouter_Partners.
 */

defined('ABSPATH') || exit;

class LR_Billing
{
    /** Часова зона проекту */
    private const TZ = 'America/New_York';

    /* ============================================================
     * Імена таблиць
     * ============================================================ */
    private static function t_billing(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'leadrouter_partner_billing';
    }

    private static function t_transactions(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'leadrouter_billing_transactions';
    }

    private static function t_audit(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'leadrouter_billing_audit_log';
    }

    private static function t_errors(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'leadrouter_billing_errors';
    }

    /* ============================================================
     * 1) Списання за один лід (realtime, з хука leadrouter_after_send)
     * ============================================================ */
    /**
     * Списати з партнера вартість одного ліда.
     * Ідемпотентно: повторний виклик за той самий (partner_id, lead_id) нічого не списує.
     *
     * @return array{status:string, ...} | WP_Error
     */
    public static function deduct_for_lead(int $partner_id, int $lead_id)
    {
        global $wpdb;

        if ($partner_id <= 0 || $lead_id <= 0) {
            return new WP_Error('billing_bad_args', 'Некоректні partner_id/lead_id');
        }

        $t_billing = self::t_billing();
        $t_tx      = self::t_transactions();

        $wpdb->query('START TRANSACTION');

        // 🔒 Лочимо рядок партнера на час операції
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t_billing} WHERE partner_id = %d FOR UPDATE", $partner_id),
            ARRAY_A
        );

        // Немає білінг-профілю → не списуємо «всліпу», фіксуємо помилку
        if (!$row) {
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'NO_BILLING_PROFILE', 'error',
                'Спроба списання за лід без білінг-профілю партнера',
                ['lead_id' => $lead_id]);
            return new WP_Error('no_billing_profile', 'Партнер не має білінг-профілю');
        }

        // 🛡️ Захист від дублів: уже списували за цей лід?
        $exists = (int)$wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$t_tx} WHERE partner_id = %d AND lead_id = %d AND type = 'spend' LIMIT 1",
                $partner_id, $lead_id
            )
        );
        if ($exists > 0) {
            $wpdb->query('ROLLBACK');
            return ['status' => 'duplicate', 'partner_id' => $partner_id, 'lead_id' => $lead_id, 'transaction_id' => $exists];
        }

        $price          = self::get_lead_price_for_today($partner_id);
        $currency       = (string)$row['currency'];
        $balance_before = (float)$row['balance'];
        $balance_after  = round($balance_before - $price, 2);

        // INSERT транзакції (UNIQUE (partner_id, lead_id) — фінальний бар'єр від гонок)
        $tx_id = self::insert_transaction([
            'partner_id'     => $partner_id,
            'lead_id'        => $lead_id,
            'type'           => 'spend',
            'amount'         => -$price,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'currency'       => $currency,
            'idempotency_key' => 'lead_spend:' . $partner_id . ':' . $lead_id,
            'reference_type' => 'lead',
            'reference_id'   => (string)$lead_id,
            'description'    => 'Списання за лід #' . $lead_id,
        ]);

        if (!$tx_id) {
            // Найімовірніше — гонка, що впала в UNIQUE; вважаємо дублем
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'DUPLICATE_CHARGE', 'warning',
                'INSERT транзакції відхилено (можливий дубль)',
                ['lead_id' => $lead_id, 'db_error' => $wpdb->last_error]);
            return new WP_Error('duplicate_charge', 'Транзакцію за цей лід вже створено');
        }

        // Оновлюємо баланс
        $wpdb->update(
            $t_billing,
            ['balance' => $balance_after, 'last_charged_at' => self::now(), 'updated_at' => self::now()],
            ['id' => (int)$row['id']],
            ['%f', '%s', '%s'],
            ['%d']
        );

        // Audit
        self::log_audit([
            'partner_id'     => $partner_id,
            'transaction_id' => $tx_id,
            'actor_type'     => 'system',
            'action'         => 'lead_spend',
            'entity_type'    => 'partner_billing',
            'entity_id'      => (int)$row['id'],
            'old_value'      => ['balance' => $balance_before],
            'new_value'      => ['balance' => $balance_after],
            'context_json'   => ['lead_id' => $lead_id, 'lead_price' => $price],
        ]);

        $wpdb->query('COMMIT');

        // Аномалія: пішли в мінус — фіксуємо, але списання вже відбулось
        if ($balance_after < 0) {
            self::log_error($partner_id, 'INSUFFICIENT_FUNDS', 'warning',
                'Баланс пішов у мінус після списання за лід',
                ['lead_id' => $lead_id, 'balance_after' => $balance_after]);
        }

        return [
            'status'         => 'ok',
            'partner_id'     => $partner_id,
            'lead_id'        => $lead_id,
            'transaction_id' => $tx_id,
            'amount'         => -$price,
            'balance_after'  => $balance_after,
        ];
    }

    /* ============================================================
     * 2) Ручне списання (адмін)
     * ============================================================ */
    public static function manual_debit(int $partner_id, float $amount, string $reason, int $admin_user_id)
    {
        global $wpdb;

        $amount = round(abs($amount), 2);
        if ($partner_id <= 0 || $amount <= 0) {
            return new WP_Error('billing_bad_args', 'Некоректні partner_id/amount');
        }

        $t_billing = self::t_billing();

        $wpdb->query('START TRANSACTION');

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t_billing} WHERE partner_id = %d FOR UPDATE", $partner_id),
            ARRAY_A
        );
        if (!$row) {
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'NO_BILLING_PROFILE', 'error',
                'Ручне списання для партнера без білінг-профілю', ['admin_user_id' => $admin_user_id]);
            return new WP_Error('no_billing_profile', 'Партнер не має білінг-профілю');
        }

        $currency       = (string)$row['currency'];
        $balance_before = (float)$row['balance'];
        $balance_after  = round($balance_before - $amount, 2);

        $tx_id = self::insert_transaction([
            'partner_id'     => $partner_id,
            'lead_id'        => null,
            'type'           => 'manual_debit',
            'amount'         => -$amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'currency'       => $currency,
            'idempotency_key' => 'manual_debit:' . $partner_id . ':' . self::uuid(),
            'reference_type' => 'manual',
            'reference_id'   => (string)$admin_user_id,
            'description'    => $reason,
        ]);

        if (!$tx_id) {
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'TX_INSERT_FAILED', 'error',
                'Не вдалося створити транзакцію ручного списання',
                ['db_error' => $wpdb->last_error]);
            return new WP_Error('tx_insert_failed', 'Не вдалося створити транзакцію');
        }

        $wpdb->update(
            $t_billing,
            ['balance' => $balance_after, 'updated_at' => self::now()],
            ['id' => (int)$row['id']],
            ['%f', '%s'],
            ['%d']
        );

        self::log_audit([
            'partner_id'     => $partner_id,
            'transaction_id' => $tx_id,
            'actor_type'     => 'admin',
            'actor_id'       => $admin_user_id,
            'action'         => 'manual_debit',
            'entity_type'    => 'partner_billing',
            'entity_id'      => (int)$row['id'],
            'old_value'      => ['balance' => $balance_before],
            'new_value'      => ['balance' => $balance_after],
            'context_json'   => ['amount' => $amount, 'reason' => $reason],
        ]);

        $wpdb->query('COMMIT');

        return [
            'status'         => 'ok',
            'transaction_id' => $tx_id,
            'balance_after'  => $balance_after,
        ];
    }

    /* ============================================================
     * 3) Поповнення (topup / auto_charge / credit / refund)
     * ============================================================ */
    public static function topup(int $partner_id, float $amount, string $type, string $description, string $stripe_charge_id = null)
    {
        global $wpdb;

        $amount = round(abs($amount), 2);
        $type   = $type !== '' ? $type : 'topup';
        if ($partner_id <= 0 || $amount <= 0) {
            return new WP_Error('billing_bad_args', 'Некоректні partner_id/amount');
        }

        $t_billing = self::t_billing();
        $t_tx      = self::t_transactions();

        $wpdb->query('START TRANSACTION');

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t_billing} WHERE partner_id = %d FOR UPDATE", $partner_id),
            ARRAY_A
        );
        if (!$row) {
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'NO_BILLING_PROFILE', 'error',
                'Поповнення для партнера без білінг-профілю', ['type' => $type]);
            return new WP_Error('no_billing_profile', 'Партнер не має білінг-профілю');
        }

        // Ідемпотентність по Stripe-charge: якщо вже проводили — не дублюємо
        $idem = $stripe_charge_id
            ? $type . ':' . $partner_id . ':' . $stripe_charge_id
            : $type . ':' . $partner_id . ':' . self::uuid();

        if ($stripe_charge_id) {
            $dup = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$t_tx} WHERE idempotency_key = %s LIMIT 1", $idem)
            );
            if ($dup > 0) {
                $wpdb->query('ROLLBACK');
                return ['status' => 'duplicate', 'transaction_id' => $dup];
            }
        }

        $currency       = (string)$row['currency'];
        $balance_before = (float)$row['balance'];
        $balance_after  = round($balance_before + $amount, 2);

        $tx_id = self::insert_transaction([
            'partner_id'     => $partner_id,
            'lead_id'        => null,
            'type'           => $type,
            'amount'         => $amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'currency'       => $currency,
            'idempotency_key' => $idem,
            'reference_type' => $stripe_charge_id ? 'stripe_payment' : 'manual',
            'reference_id'   => $stripe_charge_id,
            'description'    => $description,
        ]);

        if (!$tx_id) {
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'TX_INSERT_FAILED', 'error',
                'Не вдалося створити транзакцію поповнення',
                ['type' => $type, 'db_error' => $wpdb->last_error]);
            return new WP_Error('tx_insert_failed', 'Не вдалося створити транзакцію');
        }

        $wpdb->update(
            $t_billing,
            ['balance' => $balance_after, 'last_topup_at' => self::now(), 'updated_at' => self::now()],
            ['id' => (int)$row['id']],
            ['%f', '%s', '%s'],
            ['%d']
        );

        self::log_audit([
            'partner_id'     => $partner_id,
            'transaction_id' => $tx_id,
            'actor_type'     => $stripe_charge_id ? 'stripe_webhook' : 'system',
            'action'         => $type,
            'entity_type'    => 'partner_billing',
            'entity_id'      => (int)$row['id'],
            'old_value'      => ['balance' => $balance_before],
            'new_value'      => ['balance' => $balance_after],
            'context_json'   => ['amount' => $amount, 'stripe_charge_id' => $stripe_charge_id, 'description' => $description],
        ]);

        $wpdb->query('COMMIT');

        // Поповнення = нові гроші → анти-спам замок невдалого auto-charge знімаємо
        // БЕЗУМОВНО, незалежно від того, чи піднявся баланс над min_balance.
        // Критично для allow_negative=1: баланс може лишитись нижчим за поріг,
        // але кошти надійшли, тож наступна спроба charge при падінні знову виправдана.
        self::reset_notification_flag($partner_id, 'notified_charge_failed');

        // Миттєва реактивація: баланс відновлено хоча б до ціни ліда, а партнера
        // раніше зупинив білінг → одразу повертаємо його в роботу (актуально для ручного поповнення).
        if ($balance_after >= (float)$row['lead_price'] && self::is_deactivated_by_billing($partner_id)) {
            self::reactivate_partner($partner_id, 'manual_topup_restored');
        }

        return [
            'status'         => 'ok',
            'transaction_id' => $tx_id,
            'balance_after'  => $balance_after,
        ];
    }

    /* ============================================================
     * 3b) Рефанд — повернення коштів на картку → ЗМЕНШУЄМО баланс у нас
     * ============================================================ */
    /**
     * Застосувати Stripe-рефанд: створює транзакцію type='refund' зі знаком «-»
     * і зменшує баланс партнера. Викликається з вебхука charge.refunded.
     *
     * Ідемпотентно за $stripe_ref (id рефанду re_... або charge ch_...):
     * повторний вебхук тієї ж події не списує двічі.
     *
     * @return array{status:string,...} | WP_Error
     */
    public static function apply_refund(int $partner_id, float $amount, string $stripe_ref = null, string $description = 'Stripe refund')
    {
        global $wpdb;

        $amount = round(abs($amount), 2);
        if ($partner_id <= 0 || $amount <= 0) {
            return new WP_Error('billing_bad_args', 'Некоректні partner_id/amount');
        }

        $t_billing = self::t_billing();
        $t_tx      = self::t_transactions();

        $wpdb->query('START TRANSACTION');

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$t_billing} WHERE partner_id = %d FOR UPDATE", $partner_id),
            ARRAY_A
        );
        if (!$row) {
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'NO_BILLING_PROFILE', 'error',
                'Рефанд для партнера без білінг-профілю', ['stripe_ref' => $stripe_ref]);
            return new WP_Error('no_billing_profile', 'Партнер не має білінг-профілю');
        }

        // Ідемпотентність за id рефанду/чарджу
        $idem = $stripe_ref
            ? 'refund:' . $partner_id . ':' . $stripe_ref
            : 'refund:' . $partner_id . ':' . self::uuid();

        if ($stripe_ref) {
            $dup = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$t_tx} WHERE idempotency_key = %s LIMIT 1", $idem)
            );
            if ($dup > 0) {
                $wpdb->query('ROLLBACK');
                return ['status' => 'duplicate', 'transaction_id' => $dup];
            }
        }

        $currency       = (string)$row['currency'];
        $balance_before = (float)$row['balance'];
        $balance_after  = round($balance_before - $amount, 2);

        $tx_id = self::insert_transaction([
            'partner_id'     => $partner_id,
            'lead_id'        => null,
            'type'           => 'refund',
            'amount'         => -$amount,
            'balance_before' => $balance_before,
            'balance_after'  => $balance_after,
            'currency'       => $currency,
            'idempotency_key' => $idem,
            'reference_type' => 'stripe_refund',
            'reference_id'   => $stripe_ref,
            'description'    => $description,
        ]);

        if (!$tx_id) {
            $wpdb->query('ROLLBACK');
            self::log_error($partner_id, 'TX_INSERT_FAILED', 'error',
                'Не вдалося створити транзакцію рефанду',
                ['db_error' => $wpdb->last_error, 'stripe_ref' => $stripe_ref]);
            return new WP_Error('tx_insert_failed', 'Не вдалося створити транзакцію');
        }

        $wpdb->update(
            $t_billing,
            ['balance' => $balance_after, 'updated_at' => self::now()],
            ['id' => (int)$row['id']],
            ['%f', '%s'],
            ['%d']
        );

        self::log_audit([
            'partner_id'     => $partner_id,
            'transaction_id' => $tx_id,
            'actor_type'     => 'stripe_webhook',
            'action'         => 'stripe_refund',
            'entity_type'    => 'partner_billing',
            'entity_id'      => (int)$row['id'],
            'old_value'      => ['balance' => $balance_before],
            'new_value'      => ['balance' => $balance_after],
            'context_json'   => ['amount' => $amount, 'stripe_ref' => $stripe_ref, 'description' => $description],
        ]);

        $wpdb->query('COMMIT');

        return [
            'status'         => 'ok',
            'transaction_id' => $tx_id,
            'balance_after'  => $balance_after,
        ];
    }

    /* ============================================================
     * 4) Поточний баланс
     * ============================================================ */
    public static function get_balance(int $partner_id): float
    {
        global $wpdb;
        $t_billing = self::t_billing();
        $val = $wpdb->get_var(
            $wpdb->prepare("SELECT balance FROM {$t_billing} WHERE partner_id = %d LIMIT 1", $partner_id)
        );
        return $val === null ? 0.0 : (float)$val;
    }

    /* ============================================================
     * 4b) Ціна ліда на сьогодні (за днем тижня в EST)
     * ============================================================ */
    /**
     * Повертає вартість ліда для поточного дня тижня (EST, America/New_York):
     *   - неділя  → lead_price_sunday;   якщо NULL → fallback на lead_price
     *   - субота  → lead_price_saturday; якщо NULL → fallback на lead_price
     *   - пн-пт   → lead_price
     *
     * NULL у вихідних колонках = «не задано» = беремо weekday-ціну (НЕ нуль).
     * Public, бо викликається ще й з LR_Billing_Mailer для плейсхолдера {lead_price}.
     */
    public static function get_lead_price_for_today(int $partner_id): float
    {
        global $wpdb;
        $t_billing = self::t_billing();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT lead_price, lead_price_saturday, lead_price_sunday
                   FROM {$t_billing} WHERE partner_id = %d LIMIT 1",
                $partner_id
            ),
            ARRAY_A
        );
        if (!$row) {
            return 0.0;
        }

        $weekday = (int)(new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('N'); // 1=Mon .. 7=Sun

        if ($weekday === 7) {
            // неділя
            return $row['lead_price_sunday'] !== null
                ? (float)$row['lead_price_sunday']
                : (float)$row['lead_price'];
        }

        if ($weekday === 6) {
            // субота
            return $row['lead_price_saturday'] !== null
                ? (float)$row['lead_price_saturday']
                : (float)$row['lead_price'];
        }

        // пн-пт
        return (float)$row['lead_price'];
    }

    /* ============================================================
     * 5) Деактивувати партнера через білінг
     * ============================================================ */
    /**
     * Зупиняє партнера: знімає прапорець активності (_leadrouter_partner_active = '0')
     * і ставить deactivated_by_billing = 1. Подію фіксуємо в audit.
     */
    public static function deactivate_partner(int $partner_id, string $reason): bool
    {
        global $wpdb;
        $t_billing = self::t_billing();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id, deactivated_by_billing FROM {$t_billing} WHERE partner_id = %d LIMIT 1", $partner_id),
            ARRAY_A
        );
        if (!$row) {
            self::log_error($partner_id, 'NO_BILLING_PROFILE', 'error', 'deactivate_partner: профіль не знайдено');
            return false;
        }

        // Знімаємо активність партнера (поле з вкладки «Основні»)
        update_post_meta($partner_id, '_leadrouter_partner_active', '0');

        $ok = $wpdb->update(
            $t_billing,
            ['deactivated_by_billing' => 1, 'updated_at' => self::now()],
            ['id' => (int)$row['id']],
            ['%d', '%s'],
            ['%d']
        );

        self::log_audit([
            'partner_id'   => $partner_id,
            'actor_type'   => 'system',
            'action'       => 'partner_deactivated',
            'entity_type'  => 'partner_billing',
            'entity_id'    => (int)$row['id'],
            'old_value'    => ['deactivated_by_billing' => (int)$row['deactivated_by_billing']],
            'new_value'    => ['deactivated_by_billing' => 1],
            'context_json' => ['reason' => $reason],
        ]);

        return $ok !== false;
    }

    /* ============================================================
     * 6) Реактивувати партнера
     * ============================================================ */
    /**
     * Повертає партнера в роботу: _leadrouter_partner_active = '1'
     * і deactivated_by_billing = 0. Подію фіксуємо в audit.
     */
    public static function reactivate_partner(int $partner_id, string $reason = 'balance_restored'): bool
    {
        global $wpdb;
        $t_billing = self::t_billing();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT id, deactivated_by_billing FROM {$t_billing} WHERE partner_id = %d LIMIT 1", $partner_id),
            ARRAY_A
        );
        if (!$row) {
            self::log_error($partner_id, 'NO_BILLING_PROFILE', 'error', 'reactivate_partner: профіль не знайдено');
            return false;
        }

        // Повертаємо активність партнера
        update_post_meta($partner_id, '_leadrouter_partner_active', '1');

        $ok = $wpdb->update(
            $t_billing,
            ['deactivated_by_billing' => 0, 'updated_at' => self::now()],
            ['id' => (int)$row['id']],
            ['%d', '%s'],
            ['%d']
        );

        // Знімаємо «замок» невдалого auto-charge: реактивований партнер знову
        // має право на спробу списання, коли баланс наступного разу впаде нижче порогу.
        self::reset_notification_flag($partner_id, 'notified_charge_failed');

        self::log_audit([
            'partner_id'   => $partner_id,
            'actor_type'   => 'system',
            'action'       => 'partner_reactivated',
            'entity_type'  => 'partner_billing',
            'entity_id'    => (int)$row['id'],
            'old_value'    => ['deactivated_by_billing' => (int)$row['deactivated_by_billing']],
            'new_value'    => ['deactivated_by_billing' => 0],
            'context_json' => ['reason' => $reason],
        ]);

        return $ok !== false;
    }

    /* ============================================================
     * 7) Чи зупинено партнера білінгом
     * ============================================================ */
    public static function is_deactivated_by_billing(int $partner_id): bool
    {
        global $wpdb;
        $t_billing = self::t_billing();
        $val = $wpdb->get_var(
            $wpdb->prepare("SELECT deactivated_by_billing FROM {$t_billing} WHERE partner_id = %d LIMIT 1", $partner_id)
        );
        return (int)$val === 1;
    }

    /* ============================================================
     * 8) Прапорці сповіщень (скинути / встановити)
     * ============================================================ */
    /** Дозволені колонки-прапорці (whitelist проти SQL-injection) */
    private static function notification_flags(): array
    {
        return ['notified_low_balance', 'notified_stopped', 'notified_admin_negative', 'notified_charge_failed'];
    }

    /** Скинути прапорець сповіщення (0) */
    public static function reset_notification_flag(int $partner_id, string $flag): void
    {
        self::update_notification_flag($partner_id, $flag, 0);
    }

    /** Встановити прапорець сповіщення (1) */
    public static function set_notification_flag(int $partner_id, string $flag): void
    {
        self::update_notification_flag($partner_id, $flag, 1);
    }

    /** Внутрішнє: оновити прапорець сповіщення з перевіркою whitelist */
    private static function update_notification_flag(int $partner_id, string $flag, int $value): void
    {
        global $wpdb;

        if (!in_array($flag, self::notification_flags(), true)) {
            self::log_error($partner_id, 'BAD_NOTIFICATION_FLAG', 'warning',
                'Спроба оновити невідомий прапорець сповіщення', ['flag' => $flag]);
            return;
        }

        $t_billing = self::t_billing();
        // $flag — зі статичного whitelist вище, тож інтерполяція безпечна
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$t_billing} SET {$flag} = %d, updated_at = %s WHERE partner_id = %d",
                $value, self::now(), $partner_id
            )
        );
    }

    /* ============================================================
     * 9) Запис в audit_log (внутрішній/публічний хелпер)
     * ============================================================ */
    /**
     * @param array $data Ключі: partner_id, transaction_id, actor_type, actor_id, action,
     *                    entity_type, entity_id, old_value, new_value, context_json, ip_address
     * @return int insert_id або 0
     */
    public static function log_audit(array $data): int
    {
        global $wpdb;

        $row = [
            'partner_id'     => isset($data['partner_id']) ? (int)$data['partner_id'] : null,
            'transaction_id' => isset($data['transaction_id']) ? (int)$data['transaction_id'] : null,
            'actor_type'     => (string)($data['actor_type'] ?? 'system'),
            'actor_id'       => isset($data['actor_id']) ? (int)$data['actor_id'] : null,
            'action'         => (string)($data['action'] ?? ''),
            'entity_type'    => isset($data['entity_type']) ? (string)$data['entity_type'] : null,
            'entity_id'      => isset($data['entity_id']) ? (int)$data['entity_id'] : null,
            'old_value'      => self::maybe_json($data['old_value'] ?? null),
            'new_value'      => self::maybe_json($data['new_value'] ?? null),
            'context_json'   => self::maybe_json($data['context_json'] ?? null),
            'ip_address'     => $data['ip_address'] ?? self::client_ip(),
            'created_at'     => self::now(),
        ];

        $ok = $wpdb->insert(
            self::t_audit(),
            $row,
            ['%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /* ============================================================
     * 10) Запис в billing_errors
     * ============================================================ */
    public static function log_error(int $partner_id, string $error_type, string $severity, string $message, array $context = []): int
    {
        global $wpdb;

        $row = [
            'partner_id'    => $partner_id > 0 ? $partner_id : null,
            'lead_id'       => isset($context['lead_id']) ? (int)$context['lead_id'] : null,
            'transaction_id' => isset($context['transaction_id']) ? (int)$context['transaction_id'] : null,
            'error_code'    => $error_type,
            'error_message' => $message,
            'severity'      => $severity !== '' ? $severity : 'error',
            'source'        => (string)($context['source'] ?? 'billing'),
            'context_json'  => self::maybe_json($context),
            'is_resolved'   => 0,
            'created_at'    => self::now(),
        ];

        $ok = $wpdb->insert(
            self::t_errors(),
            $row,
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /* ============================================================
     * Внутрішні хелпери
     * ============================================================ */

    /** INSERT у журнал транзакцій (append-only). Повертає insert_id або 0. */
    private static function insert_transaction(array $data): int
    {
        global $wpdb;

        // Глушимо помилку дубль-ключа, щоб самим обробити її через return
        $prev = $wpdb->suppress_errors(true);

        $row = [
            'partner_id'     => (int)$data['partner_id'],
            'lead_id'        => isset($data['lead_id']) && $data['lead_id'] !== null ? (int)$data['lead_id'] : null,
            'type'           => (string)$data['type'],
            'amount'         => (float)$data['amount'],
            'balance_before' => (float)$data['balance_before'],
            'balance_after'  => (float)$data['balance_after'],
            'currency'       => (string)($data['currency'] ?? 'USD'),
            'idempotency_key' => (string)$data['idempotency_key'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id'   => $data['reference_id'] ?? null,
            'description'    => $data['description'] ?? null,
            'created_at'     => self::now(),
        ];

        $ok = $wpdb->insert(
            self::t_transactions(),
            $row,
            ['%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        $wpdb->suppress_errors($prev);

        return $ok ? (int)$wpdb->insert_id : 0;
    }

    /** Поточний час у EST у форматі MySQL */
    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d H:i:s');
    }

    /** Згенерувати унікальний ключ (UUIDv4, з фолбеком) */
    private static function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }
        return uniqid('lr', true);
    }

    /** Серіалізувати масив/обʼєкт у JSON; скаляри й null лишаємо як є */
    private static function maybe_json($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return (string)$value;
    }

    /** IP клієнта для audit (для адмінських дій); у cron/CLI може бути порожнім */
    private static function client_ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return $ip !== '' ? substr((string)$ip, 0, 45) : null;
    }
}
