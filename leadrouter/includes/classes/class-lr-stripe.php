<?php
/**
 * LR_Stripe — інтеграція зі Stripe БЕЗ SDK (тільки wp_remote_post).
 *
 * Stripe API приймає виключно form-encoded (application/x-www-form-urlencoded),
 * НЕ JSON. Вкладені параметри передаються у bracket-нотації (key[sub]=val),
 * булеві — рядками 'true'/'false'.
 *
 * Працює з таблицями білінгу:
 *   - {prefix}leadrouter_partner_billing  — джерело stripe_customer_id / stripe_payment_method_id / auto_charge_amount
 *   - {prefix}leadrouter_stripe_payments  — лог кожної Stripe-операції (idempotency_key UNIQUE)
 *
 * Ключі НЕ хардкодяться — лише з налаштувань плагіна (Carbon Fields options),
 * як у LR_Billing_Mailer::opt(). Усі помилки — через LR_Billing::log_error().
 *
 * Час — EST (America/New_York), як і решта LeadRouter.
 */

defined('ABSPATH') || exit;

if (!class_exists('LR_Stripe')) {

    class LR_Stripe
    {
        /** База Stripe API */
        const API_BASE = 'https://api.stripe.com/v1';

        /** Таймаут HTTP-запиту до Stripe, сек */
        const HTTP_TIMEOUT = 30;

        /** Допуск розбіжності часу для верифікації вебхука, сек (5 хв) */
        const WEBHOOK_TOLERANCE = 300;

        /**
         * Вікно дедуплікації idempotency_key, сек.
         * Дорівнює інтервалу білінг-крона (2 хв): у межах одного крон-циклу
         * ключ стабільний (Stripe + UNIQUE відсікають дубль), у різні цикли/дні — інший.
         */
        const CYCLE_WINDOW = 120;

        /** Часова зона проекту */
        private const TZ = 'America/New_York';

        /* ============================================================
         * 1) CHARGE — поповнення балансу через PaymentIntent (off-session)
         * ============================================================ */
        /**
         * Списати з картки партнера auto_charge_amount і повернути результат.
         *
         * @return array Один зі станів:
         *   ['status'=>'succeeded','charge_id'=>..,'amount'=>..,'raw'=>..]
         *   ['status'=>'requires_action','charge_id'=>..,'reason'=>'authentication_required']
         *   ['status'=>'declined','reason'=>error.code,'message'=>error.message]
         *   ['status'=>'duplicate']
         *   ['status'=>'error','reason'=>'no_stripe_method'|'no_billing_profile'|'invalid_amount'|'stripe_not_configured']
         */
        public static function charge(int $partner_id): array
        {
            global $wpdb;

            if ($partner_id <= 0) {
                return ['status' => 'error', 'reason' => 'bad_partner_id'];
            }

            $t_billing = $wpdb->prefix . 'leadrouter_partner_billing';
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT stripe_customer_id, stripe_payment_method_id, auto_charge_amount, currency
                       FROM {$t_billing} WHERE partner_id = %d LIMIT 1",
                    $partner_id
                ),
                ARRAY_A
            );

            // Немає білінг-профілю
            if (!$row) {
                LR_Billing::log_error($partner_id, 'STRIPE_NO_PROFILE', 'error',
                    'charge: немає білінг-профілю партнера', ['source' => 'stripe']);
                return ['status' => 'error', 'reason' => 'no_billing_profile'];
            }

            $customer_id = trim((string)($row['stripe_customer_id'] ?? ''));
            $pm_id       = trim((string)($row['stripe_payment_method_id'] ?? ''));
            $amount_usd  = (float)($row['auto_charge_amount'] ?? 0);
            $currency    = strtolower(trim((string)($row['currency'] ?? 'USD'))) ?: 'usd';

            // 2) Валідація способу оплати
            if ($customer_id === '' || $pm_id === '') {
                LR_Billing::log_error($partner_id, 'STRIPE_NO_METHOD', 'warning',
                    'charge: відсутній stripe_customer_id або stripe_payment_method_id', ['source' => 'stripe']);
                return ['status' => 'error', 'reason' => 'no_stripe_method'];
            }

            // Сума в центах
            $amount_cents = (int) round($amount_usd * 100);
            if ($amount_cents <= 0) {
                LR_Billing::log_error($partner_id, 'STRIPE_BAD_AMOUNT', 'warning',
                    'charge: некоректна сума auto_charge_amount', ['amount' => $amount_usd, 'source' => 'stripe']);
                return ['status' => 'error', 'reason' => 'invalid_amount'];
            }

            // 3) Idempotency key, стабільний у межах крон-циклу (див. CYCLE_WINDOW)
            $idem = self::idempotency_key('charge', $partner_id);

            // 7) Якщо вже є успішне списання з тим самим ключем — це дубль
            $t_pay = $wpdb->prefix . 'leadrouter_stripe_payments';
            $existing = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status, stripe_payment_intent_id FROM {$t_pay}
                      WHERE idempotency_key = %s LIMIT 1",
                    $idem
                ),
                ARRAY_A
            );
            if ($existing && (string)$existing['status'] === 'succeeded') {
                return ['status' => 'duplicate', 'charge_id' => $existing['stripe_payment_intent_id']];
            }

            // 4) POST /payment_intents
            //    metadata[partner_id] — критично для вебхуків: подія payment_intent.*
            //    несе цей PI разом із metadata, тож LR_Stripe_Webhook може розвʼязати
            //    партнера навіть якщо рядок у leadrouter_stripe_payments відсутній.
            //    (Увага: Stripe НЕ копіює metadata PI у пов'язаний Charge — для
            //    charge.refunded/dispute партнера шукаємо по payment_intent/charge id у БД.)
            $params = [
                'amount'                => $amount_cents,
                'currency'              => $currency,
                'customer'              => $customer_id,
                'payment_method'        => $pm_id,
                'off_session'           => 'true',
                'confirm'               => 'true',
                'metadata'              => ['partner_id' => (string)$partner_id],
            ];

            $resp = self::http_request(self::API_BASE . '/payment_intents', $params, $idem);

            // HTTP-рівень не вдався (no secret / network)
            if (!empty($resp['error']) && (int)$resp['http_code'] === 0) {
                $reason = $resp['error'] === 'no_secret_key' ? 'stripe_not_configured' : 'http_error';
                return ['status' => 'error', 'reason' => $reason, 'message' => (string)$resp['error']];
            }

            $code = (int)$resp['http_code'];
            $body = is_array($resp['body']) ? $resp['body'] : [];
            $is2xx = ($code >= 200 && $code < 300);

            $pi_id  = isset($body['id']) ? (string)$body['id'] : null;
            $status = isset($body['status']) ? (string)$body['status'] : null;
            $charge_id = self::extract_charge_id($body);

            // 5a) Успіх
            if ($is2xx && $status === 'succeeded') {
                self::record_payment([
                    'partner_id'        => $partner_id,
                    'idempotency_key'   => $idem,
                    'payment_intent_id' => $pi_id,
                    'charge_id'         => $charge_id,
                    'customer_id'       => $customer_id,
                    'amount'            => $amount_usd,
                    'currency'          => $currency,
                    'status'            => 'succeeded',
                    'request'           => $params,
                    'response'          => $body,
                ]);

                return [
                    'status'    => 'succeeded',
                    'charge_id' => $pi_id,
                    'amount'    => $amount_usd,
                    'raw'       => $body,
                ];
            }

            // 5b) Потрібна додаткова автентифікація (3DS) — HTTP 200 + requires_action
            if ($is2xx && $status === 'requires_action') {
                self::record_payment([
                    'partner_id'        => $partner_id,
                    'idempotency_key'   => $idem,
                    'payment_intent_id' => $pi_id,
                    'charge_id'         => $charge_id,
                    'customer_id'       => $customer_id,
                    'amount'            => $amount_usd,
                    'currency'          => $currency,
                    'status'            => 'requires_action',
                    'failure_code'      => 'authentication_required',
                    'request'           => $params,
                    'response'          => $body,
                ]);

                return [
                    'status'    => 'requires_action',
                    'charge_id' => $pi_id,
                    'reason'    => 'authentication_required',
                ];
            }

            // 5c/5d/5e) Помилка Stripe (4xx) — у тілі error.{type,code,message,payment_intent}
            $error    = isset($body['error']) && is_array($body['error']) ? $body['error'] : [];
            $err_type = (string)($error['type'] ?? '');          // card_error|invalid_request_error|api_error|...
            $err_code = (string)($error['code'] ?? '');          // card_declined|expired_card|authentication_required|...
            $err_msg  = (string)($error['message'] ?? 'Невідома помилка Stripe');
            $err_pi   = isset($error['payment_intent']['id']) ? (string)$error['payment_intent']['id'] : $pi_id;
            // Причина для логів/повернення: code точніший за type, з fallback на type
            $reason   = $err_code !== '' ? $err_code : ($err_type !== '' ? $err_type : 'stripe_error');

            // 5c) authentication_required → requires_action (потрібне 3DS)
            if ($err_code === 'authentication_required') {
                self::record_payment([
                    'partner_id'        => $partner_id,
                    'idempotency_key'   => $idem,
                    'payment_intent_id' => $err_pi,
                    'customer_id'       => $customer_id,
                    'amount'            => $amount_usd,
                    'currency'          => $currency,
                    'status'            => 'requires_action',
                    'failure_code'      => 'authentication_required',
                    'failure_message'   => $err_msg,
                    'request'           => $params,
                    'response'          => $body,
                ]);

                return [
                    'status'    => 'requires_action',
                    'charge_id' => $err_pi,
                    'reason'    => 'authentication_required',
                ];
            }

            // 5d) Справжня відмова картки (type=card_error: card_declined, expired_card,
            //     incorrect_cvc, insufficient_funds...). Це вина платіжного методу партнера —
            //     повертаємо 'declined', крон деактивує партнера й шле листи.
            if ($err_type === 'card_error') {
                self::record_payment([
                    'partner_id'        => $partner_id,
                    'idempotency_key'   => $idem,
                    'payment_intent_id' => $err_pi,
                    'customer_id'       => $customer_id,
                    'amount'            => $amount_usd,
                    'currency'          => $currency,
                    'status'            => 'failed',
                    'failure_code'      => $reason,
                    'failure_message'   => $err_msg,
                    'request'           => $params,
                    'response'          => $body,
                ]);

                LR_Billing::log_error($partner_id, 'STRIPE_CHARGE_DECLINED', 'error',
                    'Stripe charge відхилено: ' . $err_msg,
                    ['error_code' => $reason, 'http_code' => $code, 'source' => 'stripe']);

                return ['status' => 'declined', 'reason' => $reason, 'message' => $err_msg];
            }

            // 5e) Решта (invalid_request_error, api_error, rate_limit_error, idempotency_error...) —
            //     це проблема конфігурації/інтеграції, а НЕ вина картки партнера. Повертаємо 'error':
            //     крон логує й НЕ деактивує партнера (як і для http_error).
            self::record_payment([
                'partner_id'        => $partner_id,
                'idempotency_key'   => $idem,
                'payment_intent_id' => $err_pi,
                'customer_id'       => $customer_id,
                'amount'            => $amount_usd,
                'currency'          => $currency,
                'status'            => 'failed',
                'failure_code'      => $reason,
                'failure_message'   => $err_msg,
                'request'           => $params,
                'response'          => $body,
            ]);

            LR_Billing::log_error($partner_id, 'STRIPE_REQUEST_ERROR', 'error',
                'Stripe request error (' . ($err_type !== '' ? $err_type : 'unknown') . '): ' . $err_msg,
                ['error_code' => $reason, 'http_code' => $code, 'source' => 'stripe']);

            return ['status' => 'error', 'reason' => $reason, 'message' => $err_msg];
        }

        /* ============================================================
         * 2) REFUND — повернення коштів за PaymentIntent
         * ============================================================ */
        /**
         * @param string     $payment_intent_id pi_...
         * @param float|null $amount Сума у доларах; null = повний рефанд
         * @return array ['status'=>'refunded',...] | ['status'=>'failed',...] | ['status'=>'error',...]
         */
        public static function refund(string $payment_intent_id, float $amount = null): array
        {
            $payment_intent_id = trim($payment_intent_id);
            if ($payment_intent_id === '') {
                return ['status' => 'error', 'reason' => 'no_payment_intent'];
            }

            $params = ['payment_intent' => $payment_intent_id];
            if ($amount !== null) {
                $cents = (int) round($amount * 100);
                if ($cents > 0) {
                    $params['amount'] = $cents;
                }
            }

            $idem = self::idempotency_key('refund', crc32($payment_intent_id));
            $resp = self::http_request(self::API_BASE . '/refunds', $params, $idem);

            if (!empty($resp['error']) && (int)$resp['http_code'] === 0) {
                $reason = $resp['error'] === 'no_secret_key' ? 'stripe_not_configured' : 'http_error';
                return ['status' => 'error', 'reason' => $reason, 'message' => (string)$resp['error']];
            }

            $code = (int)$resp['http_code'];
            $body = is_array($resp['body']) ? $resp['body'] : [];

            // Успіх: створено refund (re_...)
            if ($code >= 200 && $code < 300 && !empty($body['id'])) {
                self::update_payment_by_pi($payment_intent_id, [
                    'status'        => 'refunded',
                    'response_json' => wp_json_encode($body),
                    'updated_at'    => self::now(),
                ]);

                return [
                    'status'    => 'refunded',
                    'refund_id' => (string)$body['id'],
                    'amount'    => isset($body['amount']) ? ((int)$body['amount']) / 100 : null,
                    'raw'       => $body,
                ];
            }

            // Помилка рефанду — НЕ перетираємо status платежу, лише фіксуємо причину
            $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : [];
            $err_code = (string)($error['code'] ?? ($error['type'] ?? 'refund_failed'));
            $err_msg  = (string)($error['message'] ?? 'Невідома помилка рефанду');

            self::update_payment_by_pi($payment_intent_id, [
                'failure_code'    => $err_code,
                'failure_message' => $err_msg,
                'updated_at'      => self::now(),
            ]);

            LR_Billing::log_error(0, 'STRIPE_REFUND_FAILED', 'error',
                'Stripe refund не вдався: ' . $err_msg,
                ['payment_intent' => $payment_intent_id, 'error_code' => $err_code, 'source' => 'stripe']);

            return ['status' => 'failed', 'reason' => $err_code, 'message' => $err_msg];
        }

        /* ============================================================
         * 3) HTTP — низькорівневий form-encoded запит до Stripe
         * ============================================================ */
        /**
         * @return array ['ok'=>bool,'http_code'=>int,'body'=>array,'raw'=>string,'error'=>?string]
         */
        public static function http_request(string $endpoint, array $params, string $idempotency_key = null): array
        {
            $secret = self::get_secret_key();
            if ($secret === '') {
                LR_Billing::log_error(0, 'STRIPE_NO_SECRET', 'error',
                    'Не задано Stripe secret key у налаштуваннях', ['endpoint' => $endpoint, 'source' => 'stripe']);
                return ['ok' => false, 'http_code' => 0, 'body' => [], 'raw' => '', 'error' => 'no_secret_key'];
            }

            $headers = [
                'Authorization' => 'Bearer ' . $secret,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ];
            if ($idempotency_key !== null && $idempotency_key !== '') {
                $headers['Idempotency-Key'] = $idempotency_key;
            }

            $args = [
                'method'      => 'POST',
                'headers'     => $headers,
                'body'        => self::build_query($params), // form-encoded, з підтримкою вкладених ключів
                'timeout'     => self::HTTP_TIMEOUT,
                'redirection' => 0,
            ];

            $response = wp_remote_post($endpoint, $args);

            if (is_wp_error($response)) {
                LR_Billing::log_error(0, 'STRIPE_HTTP_ERROR', 'error',
                    'HTTP помилка запиту до Stripe: ' . $response->get_error_message(),
                    ['endpoint' => $endpoint, 'source' => 'stripe']);
                return ['ok' => false, 'http_code' => 0, 'body' => [], 'raw' => '', 'error' => $response->get_error_message()];
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $raw  = (string) wp_remote_retrieve_body($response);
            $body = json_decode($raw, true);
            if (!is_array($body)) {
                $body = [];
            }

            return [
                'ok'        => ($code >= 200 && $code < 300),
                'http_code' => $code,
                'body'      => $body,
                'raw'       => $raw,
                'error'     => null,
            ];
        }

        /* ============================================================
         * 4) Ключі з налаштувань (НЕ хардкод)
         * ============================================================ */
        public static function get_secret_key(): string
        {
            return self::opt('lr_stripe_secret_key');
        }

        public static function get_publishable_key(): string
        {
            return self::opt('lr_stripe_publishable_key');
        }

        /** Webhook signing secret (whsec_...) */
        public static function get_webhook_secret(): string
        {
            return self::opt('lr_stripe_webhook_secret');
        }

        /* ============================================================
         * 5) Верифікація підпису вебхука Stripe (HMAC SHA256)
         * ============================================================ */
        /**
         * Перевіряє заголовок Stripe-Signature: t=timestamp,v1=signature[,v1=...].
         * signed_payload = "{t}.{payload}", очікуваний підпис = HMAC-SHA256(signed_payload, whsec).
         * Додатково — допуск часу WEBHOOK_TOLERANCE (5 хв) проти replay.
         */
        public static function verify_webhook(string $payload, string $sig_header): bool
        {
            $secret = self::get_webhook_secret();
            if ($secret === '' || trim($sig_header) === '') {
                return false;
            }

            // Розбір заголовка на t і список v1
            $timestamp = null;
            $signatures = [];
            foreach (explode(',', $sig_header) as $part) {
                $kv = explode('=', trim($part), 2);
                if (count($kv) !== 2) {
                    continue;
                }
                [$k, $v] = $kv;
                if ($k === 't') {
                    $timestamp = $v;
                } elseif ($k === 'v1') {
                    $signatures[] = $v;
                }
            }

            if ($timestamp === null || $signatures === [] || !ctype_digit((string)$timestamp)) {
                return false;
            }

            // Допуск часу (анти-replay)
            if (abs(time() - (int)$timestamp) > self::WEBHOOK_TOLERANCE) {
                return false;
            }

            $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

            foreach ($signatures as $sig) {
                if (hash_equals($expected, $sig)) {
                    return true;
                }
            }
            return false;
        }

        /* ============================================================
         * Внутрішні хелпери
         * ============================================================ */

        /**
         * Будує form-encoded тіло з підтримкою вкладених параметрів
         * у Stripe bracket-нотації (key[sub]=val, key[0][x]=val) і
         * булевих як 'true'/'false'.
         */
        private static function build_query(array $data, string $prefix = ''): string
        {
            $pairs = [];
            foreach ($data as $key => $value) {
                $full = ($prefix === '') ? (string)$key : $prefix . '[' . $key . ']';

                if (is_array($value)) {
                    $nested = self::build_query($value, $full);
                    if ($nested !== '') {
                        $pairs[] = $nested;
                    }
                    continue;
                }

                if (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $pairs[] = rawurlencode($full) . '=' . rawurlencode((string)$value);
            }
            return implode('&', $pairs);
        }

        /**
         * Idempotency key, стабільний у межах одного крон-вікна (CYCLE_WINDOW).
         * Різні вікна/дні дають різний ключ → легітимні повторні поповнення не блокуються.
         */
        private static function idempotency_key(string $kind, int $id): string
        {
            $bucket = (int) (floor(time() / self::CYCLE_WINDOW) * self::CYCLE_WINDOW);
            return $kind . '_' . $id . '_' . gmdate('Ymd_His', $bucket);
        }

        /** Витягнути id чарджу з відповіді PaymentIntent (нова/стара форма API) */
        private static function extract_charge_id(array $pi): ?string
        {
            if (!empty($pi['latest_charge']) && is_string($pi['latest_charge'])) {
                return $pi['latest_charge'];
            }
            if (!empty($pi['charges']['data'][0]['id'])) {
                return (string)$pi['charges']['data'][0]['id'];
            }
            return null;
        }

        /**
         * Upsert рядка в leadrouter_stripe_payments за idempotency_key (UNIQUE).
         * Жодних секретів у request_json не пишемо (secret key передається в заголовку).
         */
        private static function record_payment(array $d): void
        {
            global $wpdb;
            $t = $wpdb->prefix . 'leadrouter_stripe_payments';
            $now = self::now();
            $idem = (string)($d['idempotency_key'] ?? '');

            $fields = [
                'partner_id'               => (int)($d['partner_id'] ?? 0),
                'stripe_payment_intent_id' => $d['payment_intent_id'] ?? null,
                'stripe_charge_id'         => $d['charge_id'] ?? null,
                'stripe_customer_id'       => $d['customer_id'] ?? null,
                'amount'                   => (float)($d['amount'] ?? 0),
                'currency'                 => strtoupper((string)($d['currency'] ?? 'USD')),
                'status'                   => (string)($d['status'] ?? 'pending'),
                'failure_code'             => $d['failure_code'] ?? null,
                'failure_message'          => isset($d['failure_message']) ? mb_substr((string)$d['failure_message'], 0, 255) : null,
                'request_json'             => isset($d['request']) ? wp_json_encode($d['request']) : null,
                'response_json'            => isset($d['response']) ? wp_json_encode($d['response']) : null,
                'updated_at'               => $now,
            ];

            $existing_id = (int)$wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$t} WHERE idempotency_key = %s LIMIT 1", $idem)
            );

            if ($existing_id > 0) {
                $wpdb->update($t, $fields, ['id' => $existing_id]);
            } else {
                $fields['idempotency_key'] = $idem;
                $fields['created_at']      = $now;
                $wpdb->insert($t, $fields);
            }
        }

        /** Оновити рядок платежу за payment_intent_id (для рефандів) */
        private static function update_payment_by_pi(string $payment_intent_id, array $fields): void
        {
            global $wpdb;
            $t = $wpdb->prefix . 'leadrouter_stripe_payments';
            $wpdb->update($t, $fields, ['stripe_payment_intent_id' => $payment_intent_id]);
        }

        /**
         * Читання налаштування плагіна (Carbon theme_option → fallback get_option).
         * Аналог LR_Billing_Mailer::opt(): ключі Stripe ніколи не хардкодяться.
         */
        private static function opt(string $name): string
        {
            if (function_exists('carbon_get_theme_option')) {
                $val = carbon_get_theme_option($name);
                if ($val !== null && $val !== '') {
                    return (string)$val;
                }
            }
            $val = get_option($name);
            return ($val !== false && $val !== null) ? (string)$val : '';
        }

        /** Поточний час у EST у форматі MySQL */
        private static function now(): string
        {
            return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d H:i:s');
        }
    }
}
