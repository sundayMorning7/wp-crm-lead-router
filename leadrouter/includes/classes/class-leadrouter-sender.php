<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * LeadRouter_Sender
 * Єдиний вхід для доставки ліда партнеру. Працює з різними транспортами:
 * - webhook/api (HTTP POST)
 * - email (заглушка, щоб легко додати)
 *
 * Повертає:
 * - array ['ok'=>true, 'http_code'=>200, 'attempts'=>1, 'raw'=>...]
 * - або WP_Error('code','message', ['http_code'=>..., 'response'=>...])
 *
 * Хуки/фільтри (ключові):
 * - leadrouter_sender_endpoint          ($endpoint, $partner_id, $method, $partner_row, $opts)
 * - leadrouter_sender_headers           ($headers,  $partner_id, $method, $payload, $opts)
 * - leadrouter_sender_payload           ($payload,  $lead_id, $partner_row, $opts)
 * - leadrouter_sender_is_success        (bool $ok, $http_code, $body, $headers, $partner_id, $opts)
 * - leadrouter_sender_error_code_map    ($code, $http_code, $body, $err)
 * - leadrouter_sender_before_request    ($lead_id, $partner_row, $endpoint, $payload, $headers, $opts)
 * - leadrouter_sender_after_request     ($lead_id, $partner_row, $endpoint, $payload, $headers, $resp, $opts)
 */
class LeadRouter_Sender {

    /** Головний метод */
    public static function send( int $lead_id, array $partner_row, array $opts = [] ) {
        $method      = isset($opts['dispatch_method']) ? (string)$opts['dispatch_method'] : 'webhook'; // webhook|api|email
        $partner_id  = (int)($partner_row['partner_id'] ?? 0);

        // 1) endpoint (для webhook/api)
        $endpoint = null;
        if ( in_array($method, ['webhook','api'], true) ) {
            // Метаполе з endpoint партнера (зміни за потреби)
            $endpoint = get_post_meta($partner_id, '_leadrouter_partner_endpoint', true);
            $endpoint = apply_filters('leadrouter_sender_endpoint', $endpoint, $partner_id, $method, $partner_row, $opts);
            if ( ! $endpoint ) {
                self::log('error', 'no endpoint for partner', compact('lead_id','partner_id','method'));
                return new WP_Error('no_endpoint', 'У партнера не задано endpoint');
            }
        }

        // 2) payload
        $payload = self::build_payload($lead_id, $partner_row, $opts);
        $payload = apply_filters('leadrouter_sender_payload', $payload, $lead_id, $partner_row, $opts);

        // 3) headers (HMAC підпис як опція)
        $headers = [
            'Content-Type' => 'application/json; charset=UTF-8',
        ];
        $secret = (string)get_post_meta($partner_id, '_leadrouter_partner_secret', true);

        if ( $secret ) {
            $sig = hash_hmac('sha256', wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $secret);
            $headers['X-Leadrouter-Signature'] = $sig;
        }
        $headers = apply_filters('leadrouter_sender_headers', $headers, $partner_id, $method, $payload, $opts);

        // 4) Доставка за методом
        if ( $method === 'email' ) {
            return self::send_email($lead_id, $partner_row, $payload, $opts);
        }

        // webhook/api -> HTTP POST
        do_action('leadrouter_sender_before_request', $lead_id, $partner_row, $endpoint, $payload, $headers, $opts);

        $args = [
            'timeout' => isset($opts['timeout']) ? (int)$opts['timeout'] : 10,
            'headers' => $headers,
            'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ];
        $resp = wp_remote_post($endpoint, $args);

        do_action('leadrouter_sender_after_request', $lead_id, $partner_row, $endpoint, $payload, $headers, $resp, $opts);

        if ( is_wp_error($resp) ) {
            $code = self::map_error_code(null, null, $resp); // transport_error
            self::log('error', 'http transport error', ['lead_id'=>$lead_id,'partner_id'=>$partner_id,'error'=>$resp->get_error_message()]);
            return new WP_Error($code, 'Транспортна помилка доставки', ['error' => $resp->get_error_message()]);
        }

        $http_code = (int) wp_remote_retrieve_response_code($resp);
        $body      = wp_remote_retrieve_body($resp);
        $headers_r = wp_remote_retrieve_headers($resp);

        // 5) Визначаємо успіх
        $ok = self::is_success($http_code, $body, $headers_r, $partner_id, $opts);
        if ( ! $ok ) {
            $err_code = self::map_error_code($http_code, $body, null);
            self::log('error', 'http non-success', [
                'lead_id'    => $lead_id,
                'partner_id' => $partner_id,
                'http_code'  => $http_code,
                'error_code' => $err_code,
                'body'       => self::truncate($body),
            ]);
            return new WP_Error($err_code, 'Партнер не прийняв лід', ['http_code'=>$http_code,'response'=>$body]);
        }

        // 6) OK
        self::log('info', 'send ok', ['lead_id'=>$lead_id,'partner_id'=>$partner_id,'http_code'=>$http_code]);
        return [
            'ok'        => true,
            'http_code' => $http_code,
            'raw'       => ['body'=>$body,'headers'=>$headers_r],
        ];
    }

    /** Побудова payload (з БД) — можна розширювати фільтром */
    protected static function build_payload( int $lead_id, array $partner_row, array $opts ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'leadrouter_leads';
        $lead  = $wpdb->get_row( $wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", $lead_id), ARRAY_A );

        // якщо раптом немає рядка — вважаємо це помилкою (але тут лише формуємо)
        if ( ! $lead ) {
            // мінімальний payload, щоб не впасти
            $lead = ['id'=>$lead_id];
        }

        $payload = [
            'id'                => (int)$lead['id'],
            'name'              => (string)($lead['name'] ?? ''),
            'email'             => (string)($lead['email'] ?? ''),
            'phone'             => (string)($lead['phone'] ?? ''),
            'est_ship_date'     => (string)($lead['est_ship_date'] ?? ''),
            'vehicle'           => [
                'year'       => isset($lead['vehicle_year']) ? (int)$lead['vehicle_year'] : null,
                'brand'      => (string)($lead['vehicle_brand'] ?? ''),
                'model'      => (string)($lead['vehicle_model'] ?? ''),
                'condition'  => (string)($lead['vehicle_condition'] ?? ''),
            ],
            'route'             => [
                'from' => [
                    'city'  => (string)($lead['from_city'] ?? ''),
                    'state' => (string)($lead['from_state'] ?? ''),
                    'zip'   => (string)($lead['from_zip'] ?? ''),
                ],
                'to'   => [
                    'city'  => (string)($lead['to_city'] ?? ''),
                    'state' => (string)($lead['to_state'] ?? ''),
                    'zip'   => (string)($lead['to_zip'] ?? ''),
                ],
            ],
            'meta'              => [
                'dispatch_method' => (string)($opts['dispatch_method'] ?? 'webhook'),
                'timestamp'       => self::now_mysql_est(),
                'partner_id'      => (int)($partner_row['partner_id'] ?? 0),
            ],
        ];

        return $payload;
    }

    /** Перевірка успіху відповіді */
    protected static function is_success( int $http_code, string $body, $headers, int $partner_id, array $opts ): bool {
        // За замовчуванням: 2xx — успіх. Якщо партнер хоче інший формат — фільтр нижче.
        $ok = ($http_code >= 200 && $http_code < 300);

        // Додатково: якщо JSON має {"ok":true} — також вважаємо успіхом
        if ( ! $ok && is_string($body) && strlen($body) ) {
            $j = json_decode($body, true);
            if ( is_array($j) && isset($j['ok']) && $j['ok'] === true ) {
                $ok = true;
            }
        }

        /** Дозволяємо перевизначити логику */
        $ok = apply_filters('leadrouter_sender_is_success', $ok, $http_code, $body, $headers, $partner_id, $opts);
        return (bool)$ok;
    }

    /** Маппер помилок у наші reason-коди */
    protected static function map_error_code( ?int $http_code, ?string $body, $transport_err ): string {
        $code = 'internal_error';

        if ( $transport_err instanceof WP_Error ) {
            // типові транспортні
            $msg = implode(';', $transport_err->get_error_messages());
            if ( stripos($msg, 'timed out') !== false ) {
                $code = 'http_timeout';
            } else {
                $code = 'transport_error';
            }
        } elseif ( is_int($http_code) ) {
            if ( $http_code >= 500 ) $code = 'http_5xx';
            elseif ( $http_code === 429 ) $code = 'http_429';
            elseif ( $http_code >= 400 ) $code = 'http_4xx';
            else $code = 'unknown_http';
        }

        /** Фільтр для кастомізації reason-кодів */
        $code = apply_filters('leadrouter_sender_error_code_map', $code, $http_code, $body, $transport_err);
        return $code;
    }

    /** Відправка email (заглушка, щоб легко підхопити пізніше) */
    protected static function send_email( int $lead_id, array $partner_row, array $payload, array $opts ) {
        // TODO: імплементація за потреби
        return new WP_Error('not_implemented', 'Email-доставка ще не реалізована');
    }

    /** Час у EST */
    protected static function now_mysql_est(): string {
        $tz  = new DateTimeZone('America/New_York');
        return (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s');
    }

    /** Тихе логування через LeadRouter_Flow (якщо доступне) */
    protected static function log( string $level, string $message, array $ctx = [] ): void {
        if ( class_exists('LeadRouter_Flow') && method_exists('LeadRouter_Flow','log_info') ) {
            if ( $level === 'error' ) LeadRouter_Flow::log_error($message, $ctx);
            elseif ( $level === 'debug' ) LeadRouter_Flow::log_debug($message, $ctx);
            else LeadRouter_Flow::log_info($message, $ctx);
        } else {
            error_log('[LeadRouter_Sender]['.strtoupper($level).'] '.$message.' '.json_encode($ctx));
        }
    }

    /** Обрізка рядка для логів */
    protected static function truncate( ?string $s, int $max = 2000 ): string {
        if ( ! is_string($s) ) return '';
        return (strlen($s) > $max) ? (substr($s,0,$max).'…') : $s;
    }
}
