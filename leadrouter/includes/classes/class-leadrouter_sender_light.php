<?php
/**
 * LeadRouter_Sender_Light
 * ───────────────────────
 * Кроки:
 * 0) Ініціалізація контексту
 * 1) Збір CF-конфігів партнера
 * 2) Формування partner_payload (мапінг + трансформації + Vehicles[] + очищення + soft-валідація)
 * 3) Авторизація + HTTP-обгортка (headers/query/payload, JSON|form|xml)
 * 4) Відправка HTTP + парсинг відповіді + класифікація success/retryable/hard_fail
 *
 * + Вбудоване логування у {$wpdb->prefix}leadrouter_send_log
 *
 * Залежності:
 * - Carbon Fields: carbon_get_post_meta()
 * - LeadRouter_Transform::apply()
 * - lr_build_partner_payload(), lr_dot_flatten()
 */

if (!class_exists('LeadRouter_Sender_Light')) {
    class LeadRouter_Sender_Light {

        /* ===================== ПУБЛІЧНІ API ===================== */

        /**
         * Підготовка (кроки 0–3), БЕЗ відправки
         * @return array ['result'=>..., 'debug'=>..., 'req'=>...]
         */
        public static function prepare(array $our_payload, int $partner_post_id, array $context = []): array {
            $t0 = microtime(true);

            // ── Крок 0. Ініціалізація
            $attempt     = isset($context['attempt']) ? (int)$context['attempt'] : 1;
            $trace_id    = $context['trace_id']   ?? self::gen_uuid_v4();
            $request_id  = $context['request_id'] ?? self::gen_uuid_v4();
            $idem_key    = $context['idempotency_key'] ?? self::make_idempotency_key($our_payload);

            $result = [
                'success'        => false,
                'retryable'      => false,
                'status_code'    => null,
                'external_id'    => null,
                'error_code'     => null,
                'error_message'  => null,
            ];
            $debug = [
                'timing_ms'        => null,
                'trace_id'         => $trace_id,
                'request_id'       => $request_id,
                'attempt'          => $attempt,
                'idempotency_key'  => $idem_key,
                'warnings'         => [],
            ];

            // ── Крок 1. Налаштування партнера
            if (!function_exists('carbon_get_post_meta')) {
                $result['error_code']    = 'cf_missing';
                $result['error_message'] = 'Carbon Fields is not available';
                $debug['timing_ms']      = round((microtime(true) - $t0) * 1000, 2);
                return compact('result','debug');
            }

            $endpoint         = carbon_get_post_meta($partner_post_id, 'leadrouter_partner_endpoint');
            $auth_variant     = carbon_get_post_meta($partner_post_id, 'leadrouter_partner_auth_variant') ?: 'none';
            $api_key          = carbon_get_post_meta($partner_post_id, 'leadrouter_partner_api_key');
            $api_key_header   = carbon_get_post_meta($partner_post_id, 'leadrouter_partner_api_key_header') ?: 'X-API-Key';
            $map_rows         = (array) carbon_get_post_meta($partner_post_id, 'leadrouter_partner_map');

            // необов’язкові
            $http_method      = carbon_get_post_meta($partner_post_id, 'leadrouter_partner_http_method') ?: 'POST';
            $body_type        = carbon_get_post_meta($partner_post_id, 'leadrouter_partner_body_type')   ?: 'json'; // json|form|xml
            $extra_headers_js = carbon_get_post_meta($partner_post_id, 'leadrouter_partner_extra_headers'); // JSON
            $require_ok_json  = (bool) carbon_get_post_meta($partner_post_id, 'leadrouter_partner_require_ok_json');

            $partner_config = [
                'endpoint'       => $endpoint,
                'auth_variant'   => $auth_variant,
                'api_key'        => $api_key,
                'api_key_header' => $api_key_header,
                'http_method'    => $http_method,
                'body_type'      => $body_type,
                'require_ok_json'=> $require_ok_json,
            ];

            if (empty($endpoint)) {
                $result['error_code']    = 'endpoint_missing';
                $result['error_message'] = 'Partner endpoint is not configured';
                $debug['partner_config'] = $partner_config;
                $debug['timing_ms']      = round((microtime(true) - $t0) * 1000, 2);
                return compact('result','debug');
            }

            // ── Крок 2. Payload
            if (!function_exists('lr_build_partner_payload')) {
                $result['error_code']    = 'mapper_missing';
                $result['error_message'] = 'lr_build_partner_payload() is not available';
                $debug['partner_config'] = $partner_config;
                $debug['timing_ms']      = round((microtime(true) - $t0) * 1000, 2);
                return compact('result','debug');
            }

            $partner_payload = lr_build_partner_payload($our_payload, $map_rows);
            $partner_payload = self::array_remove_empty($partner_payload);

            $warnings = self::soft_validate($partner_payload);
            if (!empty($warnings)) {
                $debug['warnings'] = array_merge($debug['warnings'], $warnings);
            }

            // ── Крок 3. Авторизація + обгортка
            $req = [
                'endpoint' => $endpoint,
                'method'   => strtoupper($http_method ?: 'POST'),
                'headers'  => [
                    'Accept'          => 'application/json',
                    'Idempotency-Key' => $idem_key,
                ],
                'body'     => null,
                'payload'  => $partner_payload,
                'meta'     => [
                    'body_type'       => strtolower($body_type ?: 'json'),
                    'require_ok_json' => $require_ok_json,
                ],
            ];

            if (!empty($extra_headers_js)) {
                $parsed = json_decode($extra_headers_js, true);
                if (is_array($parsed)) {
                    foreach ($parsed as $hk => $hv) {
                        if (is_string($hk) && $hk !== '') {
                            $req['headers'][$hk] = is_scalar($hv) ? (string)$hv : json_encode($hv);
                        }
                    }
                }
            }

            switch ($auth_variant) {
                case 'header':
                    if (!empty($api_key) && !empty($api_key_header)) {
                        $req['headers'][$api_key_header] = $api_key;
                    }
                    break;
                case 'query':
                    if (!empty($api_key)) {
                        $req['endpoint'] = add_query_arg(['apikey' => $api_key], $req['endpoint']);
                    }
                    break;
                case 'payload':
                    if (!empty($api_key)) {
                        $partner_payload['apikey'] = $api_key;
                        $req['payload'] = $partner_payload;
                    }
                    break;
                case 'payload_authkey':
                    if (!empty($api_key)) {
                        $partner_payload['AuthKey'] = $api_key;
                        $req['payload'] = $partner_payload;
                    }
                    break;
                case 'payload_xapikey':
                    if (!empty($api_key)) {
                        $partner_payload['XAPIKEY'] = $api_key;
                        $req['payload'] = $partner_payload;
                    }
                    break;
                case 'none':
                default:
                    // no-op
                    break;
            }

            $bt = $req['meta']['body_type'];
            if ($bt === 'form') {
                $req['headers']['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';
                $req['body'] = http_build_query($partner_payload, '', '&', PHP_QUERY_RFC3986);
            } elseif ($bt === 'xml') {
                $req['headers']['Content-Type'] = 'application/xml; charset=utf-8';
                $req['body'] = self::array_to_xml_string($partner_payload, 'payload');
            } else {
                $req['headers']['Content-Type'] = 'application/json; charset=utf-8';
                $req['body'] = wp_json_encode($partner_payload);
            }

            $body_preview = $req['body'];
            if (is_string($body_preview) && strlen($body_preview) > 5000) {
                $body_preview = substr($body_preview, 0, 5000) . '... [truncated]';
            }
            $debug['partner_config']  = $partner_config;
            $debug['partner_payload'] = $partner_payload;
            $debug['http_request']    = [
                'endpoint'     => $req['endpoint'],
                'method'       => $req['method'],
                'headers'      => $req['headers'],
                'body_preview' => $body_preview,
                'body_type'    => $req['meta']['body_type'],
            ];

            $debug['timing_ms'] = round((microtime(true) - $t0) * 1000, 2);
            return compact('result','debug','req');
        }

        public static function send(array $our_payload, int $partner_post_id, array $context = []): array {
            // 0. Тип партнера
            $partner_type = get_post_meta($partner_post_id, '_leadrouter_partner_type', true) ?: 'standard';

            $lead_id  = isset($context['lead_id']) ? (int)$context['lead_id'] : 0;
            $group_id = isset($context['group_post_id']) ? (int)$context['group_post_id'] : null;



            /**
             * EMAIL-ТРАНСПОРТ — МИНАЄМО prepare()
             */
            if ($partner_type === 'email') {
                $debug = [];
                $t1 = microtime(true);

                // send_via_email повертає ['result' => ..., 'resp' => ...]
                $email = self::send_via_email($our_payload, $partner_post_id, $context);

                $result = $email['result'] ?? [
                    'success'       => false,
                    'retryable'     => false,
                    'status_code'   => null,
                    'external_id'   => null,
                    'error_code'    => 'email_unknown',
                    'error_message' => 'send_via_email() did not return result',
                ];
                $resp   = $email['resp'] ?? [];

                // Псевдо-request для логів
                $email_to = (string) get_post_meta($partner_post_id, 'leadrouter_partner_email', true);

                $req = [
                    'endpoint' => 'email:' . $email_to,
                    'method'   => 'EMAIL',
                    'headers'  => [
                        'X-LeadRouter-Transport' => 'email',
                    ],
                    'payload'  => $our_payload,
                    'meta'     => [
                        'body_type' => 'text',
                    ],
                ];

                $resp['latency_ms'] = $resp['latency_ms'] ?? round((microtime(true) - $t1) * 1000, 2);
                $debug['email_total_ms'] = $resp['latency_ms'];

                // Лог у w4pMd_leadrouter_send_log
                self::log_attempt(
                    $lead_id,
                    $group_id,
                    (int)$partner_post_id,
                    $context,
                    $req,
                    $resp,
                    $result
                );

                // Повертаємо у такому ж форматі, як для HTTP
                return [
                    'result' => $result,
                    'debug'  => $debug,
                    'req'    => $req,
                    'resp'   => $resp,
                ];
            }

            /**
             * HTTP / API-ТРАНСПОРТ — як було
             */


            $prep = self::prepare($our_payload, $partner_post_id, $context);
            if (!empty($prep['result']['error_code'])) {
                return $prep;
            }

            $req   = $prep['req'];
            $debug = $prep['debug'];


            // HTTP-ТРАНСПОРТ (як було)
            $attempts = isset($context['http_retries']) ? max(0, (int)$context['http_retries']) : 2; // додаткові
            $timeout  = isset($context['timeout']) ? (int)$context['timeout'] : 20; // сек

            $t1 = microtime(true);
            $resp   = self::http_request($req, $timeout, $attempts);
            $result = self::parse_and_classify($req, $resp);
            $debug['http_total_ms'] = round((microtime(true) - $t1) * 1000, 2);

            // ЛОГ у w4pMd_leadrouter_send_log
            self::log_attempt(
                $lead_id,
                $group_id,
                (int)$partner_post_id,
                $context,
                $req,
                $resp,
                $result
            );

            return compact('result','debug','req','resp');
        }


        /* ===================== HTTP/Парсинг ===================== */

        protected static function http_request(array $req, int $timeout_sec, int $extra_retries): array {
            $endpoint = (string)$req['endpoint'];
            $method   = strtoupper((string)$req['method']);
            $headers  = (array)$req['headers'];
            $body     = $req['body'];

            $attempt = 0;
            $max_try = 1 + $extra_retries;
            $last    = null;

            while ($attempt < $max_try) {
                $attempt++;
                $args = [
                    'method'      => $method,
                    'headers'     => $headers,
                    'body'        => $body,
                    'timeout'     => $timeout_sec,
                    'redirection' => 3,
                ];
                $t0 = microtime(true);
                $response = wp_remote_request($endpoint, $args);



                $latency  = round((microtime(true) - $t0) * 1000, 2);

                $last = [
                    'attempt'     => $attempt,
                    'latency_ms'  => $latency,
                    'is_wp_error' => is_wp_error($response),
                ];

                if (is_wp_error($response)) {
                    $last['wp_error'] = [
                        'code'    => $response->get_error_code(),
                        'message' => $response->get_error_message(),
                    ];
                    if ($attempt < $max_try) {
                        usleep(self::backoff_us($attempt));
                        continue;
                    }
                    $last['status_code'] = null;
                    $last['body_raw']    = null;
                    $last['headers']     = [];
                    return $last;
                }

                $code       = wp_remote_retrieve_response_code($response);
                $body_raw   = wp_remote_retrieve_body($response);
                $resp_hdrs  = wp_remote_retrieve_headers($response);

                $last['status_code'] = $code;
                $last['body_raw']    = $body_raw;
                $last['headers']     = is_array($resp_hdrs) ? $resp_hdrs : (array)$resp_hdrs;

                if ($code === 408 || $code === 429 || ($code >= 500 && $code <= 599)) {
                    if ($attempt < $max_try) {
                        usleep(self::backoff_us($attempt));
                        continue;
                    }
                }
                return $last;
            }
            return $last ?: ['attempt'=>0,'is_wp_error'=>true,'wp_error'=>['code'=>'unknown','message'=>'no attempts']];
        }

        protected static function parse_and_classify(array $req, array $resp): array {
            $res = [
                'success'        => false,
                'retryable'      => false,
                'status_code'    => $resp['status_code'] ?? null,
                'external_id'    => null,
                'error_code'     => null,
                'error_message'  => null,
                'body_raw'       => $resp['body_raw'] ?? '',
            ];

            if (!empty($resp['is_wp_error'])) {
                $res['retryable']     = true;
                $res['error_code']    = $resp['wp_error']['code']    ?? 'wp_error';
                $res['error_message'] = $resp['wp_error']['message'] ?? 'WP Error';
                return $res;
            }

            $code    = (int)($resp['status_code'] ?? 0);
            $raw     = $resp['body_raw'] ?? '';
            $json    = null;

            if (is_string($raw) && $raw !== '') {
                $tmp = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) $json = $tmp;
            }

            $require_ok_json = (bool)($req['meta']['require_ok_json'] ?? false);

            if ($code >= 200 && $code < 300) {
                if ($require_ok_json) {
                    if (is_array($json) && isset($json['ok']) && ($json['ok'] === true || $json['ok'] === 1 || $json['ok'] === 'true')) {
                        $res['success'] = true;
                    } else {
                        $res['error_code']    = 'ok_json_missing';
                        $res['error_message'] = '2xx but missing {ok:true}';
                    }
                } else {
                    $res['success'] = true;
                }
            } elseif ($code === 408 || $code === 429 || ($code >= 500 && $code <= 599)) {
                $res['retryable']    = true;
                $res['error_code']   = 'retryable_http';
                $res['error_message']= 'HTTP '.$code;
            } else {
                $res['error_code']   = 'http_'.$code;
                $res['error_message']= 'HTTP '.$code;
            }

            if ($json && is_array($json)) {
                foreach (['external_id','id','lead_id','uuid','result_id'] as $k) {
                    if (isset($json[$k]) && is_scalar($json[$k])) {
                        $res['external_id'] = (string)$json[$k];
                        break;
                    }
                }
            }
            return $res;
        }

        /* ===================== ЛОГУВАННЯ ===================== */

        /**
         * Запис у {$wpdb->prefix}leadrouter_send_log
         * Поля таблиці:
         *  id, lead_id, group_id, partner_id, delivery_uuid, attempt_no, attempted_at, dispatch_method,
         *  request_json, response_excerpt, http_code, content_type, latency_ms, status, reason_code,
         *  retry_after_s, final_flag, final_status
         */
        protected static function log_attempt(int $lead_id, ?int $group_id, int $partner_id, array $context, array $req, array $resp, array $result): void {
            global $wpdb;
            $table = $wpdb->prefix . 'leadrouter_send_log';

            $attempt_no      = isset($context['attempt']) ? (int)$context['attempt'] : 1;
            $dispatch_method = isset($context['dispatch_method']) ? (string)$context['dispatch_method'] : 'sender';

            // delivery_uuid — бажано idempotency_key (sha1), інакше request_id, інакше hash від endpoint|body
            $delivery_uuid = (string)($context['idempotency_key'] ?? ($context['request_id'] ?? ''));
            if ($delivery_uuid === '') {
                $delivery_uuid = sha1( ((string)($req['endpoint'] ?? '')) . '|' . ((string)($req['body'] ?? '')) );
            }

            // Маскуємо секрети
            $masked_headers = self::mask_headers((array)($req['headers'] ?? []));
            $masked_payload = self::mask_payload((array)($req['payload'] ?? []));

            $request_json = [
                'endpoint' => $req['endpoint'] ?? '',
                'method'   => $req['method']   ?? 'POST',
                'headers'  => $masked_headers,
                'payload'  => $masked_payload,
                'body_type'=> $req['meta']['body_type'] ?? 'json',
            ];
            $request_json_str = wp_json_encode($request_json, JSON_UNESCAPED_UNICODE);

            // Response meta
            $resp_headers = (array)($resp['headers'] ?? []);
            $content_type = null;
            foreach ($resp_headers as $hk => $hv) {
                if (strtolower($hk) === 'content-type') {
                    $content_type = is_array($hv) ? implode(',', $hv) : (string)$hv;
                    break;
                }
            }

            $response_excerpt = '';
            if (isset($resp['body_raw']) && is_string($resp['body_raw'])) {
                $response_excerpt = mb_substr($resp['body_raw'], 0, 3000);
            } elseif (!empty($resp['is_wp_error']) && isset($resp['wp_error']['message'])) {
                $response_excerpt = mb_substr((string)$resp['wp_error']['message'], 0, 3000);
            }

            $http_code   = isset($resp['status_code']) ? (int)$resp['status_code'] : null;
            $latency_ms  = isset($resp['latency_ms']) ? (int)$resp['latency_ms'] : (isset($resp['latency']) ? (int)$resp['latency'] : null);

            $status      = $result['success'] ? 'success' : ($result['retryable'] ? 'retryable_fail' : 'hard_fail');
            $reason_code = $result['error_code'] ?? null;
            $retry_after = self::retry_after_seconds($resp_headers);

            $attempted_at = current_time('mysql');

            $data = [
                'lead_id'          => $lead_id,
                'group_id'         => $group_id,
                'partner_id'       => $partner_id,
                'delivery_uuid'    => $delivery_uuid,
                'attempt_no'       => $attempt_no,
                'attempted_at'     => $attempted_at,
                'dispatch_method'  => $dispatch_method,
                'request_json'     => $request_json_str,
                'response_excerpt' => $response_excerpt ?: null,
                'http_code'        => $http_code,
                'content_type'     => $content_type,
                'latency_ms'       => $latency_ms,
                'status'           => $status,
                'reason_code'      => $reason_code,
                'retry_after_s'    => $retry_after,
                'final_flag'       => 0,
                'final_status'     => null,
            ];

            $formats = [
                '%d','%d','%d','%s','%d','%s','%s','%s','%s','%d','%s','%d','%s','%s','%d','%d','%s'
            ];

            // Тихо ловимо помилки вставки, щоб не зламати відправку
            try {
                $wpdb->insert($table, $data, $formats);
            } catch (\Throwable $e) {
                // no-op; за потреби можеш кинути в error_log
                // error_log('LeadRouter send_log insert failed: '.$e->getMessage());
            }
        }

        /* ===================== УТИЛІТИ ===================== */

        protected static function gen_uuid_v4(): string {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        protected static function make_idempotency_key(array $our_payload): string {
            $flat = function_exists('lr_dot_flatten') ? lr_dot_flatten($our_payload) : self::dot_flatten_local($our_payload);
            $parts = [
                $flat['phone']                    ?? '',
                $flat['origin_postal_code']       ?? '',
                $flat['destination_postal_code']  ?? '',
                $flat['ship_date']                ?? '',
            ];
            return sha1(implode('|', $parts));
        }

        protected static function dot_flatten_local(array $arr, string $prefix=''): array {
            $res = [];
            foreach ($arr as $k => $v) {
                $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
                if (is_array($v)) {
                    $res += self::dot_flatten_local($v, $key);
                } else {
                    $res[$key] = $v;
                }
            }
            return $res;
        }

        protected static function array_remove_empty($value) {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $k => $v) {
                    $vv = self::array_remove_empty($v);
                    $is_empty = ($vv === null) || ($vv === '');
                    if (!$is_empty) $out[$k] = $vv;
                }
                return $out;
            }
            return $value;
        }

        protected static function soft_validate(array $payload): array {
            $warn = [];
            if (isset($payload['email']) && $payload['email'] !== '') {
                if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
                    $warn[] = 'email: invalid format';
                }
            }
            $phone = $payload['ph'] ?? ($payload['phone'] ?? null);
            if ($phone !== null && $phone !== '') {
                if (!preg_match('/^\d{3}-\d{3}-\d{4}$/', (string)$phone)) {
                    $warn[] = 'phone: expected 111-111-1111';
                }
            }
            foreach (['os','ds'] as $stateKey) {
                if (isset($payload[$stateKey]) && $payload[$stateKey] !== '') {
                    if (!preg_match('/^[A-Z]{2}$/', (string)$payload[$stateKey])) {
                        $warn[] = $stateKey.': expected 2-letter uppercase state code';
                    }
                }
            }
            foreach (['oz','dz'] as $zipKey) {
                if (isset($payload[$zipKey]) && $payload[$zipKey] !== '') {
                    if (!preg_match('/^\d{5}(\d{4})?$/', (string)$payload[$zipKey])) {
                        $warn[] = $zipKey.': expected 5 or 9 digits';
                    }
                }
            }
            if (isset($payload['ps']) && $payload['ps'] !== '') {
                if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', (string)$payload['ps'])) {
                    $warn[] = 'ps: expected MM-DD-YYYY';
                }
            }
            return $warn;
        }

        protected static function array_to_xml_string(array $arr, string $root = 'payload'): string {
            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = false;
            $rootNode = $xml->createElement($root);
            $xml->appendChild($rootNode);

            $addNode = function($parent, $key, $val) use ($xml, &$addNode) {
                $key = is_numeric($key) ? 'item' : preg_replace('/[^A-Za-z0-9_\-:.]/', '_', (string)$key);
                if (is_array($val)) {
                    $node = $xml->createElement($key);
                    foreach ($val as $k => $v) {
                        $addNode($node, (string)$k, $v);
                    }
                    $parent->appendChild($node);
                } else {
                    $node = $xml->createElement($key);
                    $node->appendChild($xml->createTextNode((string)$val));
                    $parent->appendChild($node);
                }
            };

            foreach ($arr as $k => $v) {
                $addNode($rootNode, (string)$k, $v);
            }
            return $xml->saveXML();
        }

        protected static function backoff_us(int $attempt): int {
            // 1→300ms, 2→900ms, 3→1800ms ...
            $base = 300000; // 300ms
            return (int)($base * $attempt * ($attempt === 1 ? 1 : 1.5));
        }

        protected static function mask_headers(array $headers): array {
            $masked = [];
            foreach ($headers as $k => $v) {
                $key = strtolower((string)$k);
                $val = is_array($v) ? implode(',', $v) : (string)$v;
                if (strpos($key, 'key') !== false || strpos($key, 'token') !== false ||
                    strpos($key, 'auth') !== false || strpos($key, 'secret') !== false) {
                    $val = '***';
                }
                $masked[$k] = $val;
            }
            return $masked;
        }

        protected static function mask_payload($payload) {
            if (!is_array($payload)) return $payload;
            $out = $payload;
            foreach (['apikey','api_key','token','secret','password'] as $s) {
                if (array_key_exists($s, $out)) $out[$s] = '***';
            }
            return $out;
        }

        protected static function retry_after_seconds($headers): ?int {
            if (!is_array($headers)) return null;
            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'retry-after') {
                    $val = is_array($v) ? reset($v) : $v;
                    $val = trim((string)$val);
                    if ($val === '') return null;
                    if (ctype_digit($val)) return (int)$val;
                    $ts = strtotime($val);
                    if ($ts !== false) {
                        $diff = $ts - time();
                        return $diff > 0 ? $diff : 0;
                    }
                }
            }
            return null;
        }


        /**
         * Відправка ліда партнеру як email-лист.
         * Використовує:
         *  - leadrouter_partner_email
         *  - leadrouter_partner_email_settings (email_title, email_text)
         */
        protected static function send_via_email(array $our_payload, int $partner_id, array $ctx): array
        {
            $to_raw = trim((string) get_post_meta($partner_id, '_leadrouter_partner_email', true));

            if ($to_raw === '') {
                $result = [
                    'success'       => false,
                    'retryable'     => false,
                    'status_code'   => null,
                    'external_id'   => null,
                    'error_code'    => 'email_no_recipient',
                    'error_message' => 'Партнер типу email, але не задано адреси',
                ];

                return [
                    'result' => $result,
                    'resp'   => [
                        'transport' => 'email',
                        'to'        => [],
                        'subject'   => null,
                        'body'      => null,
                    ],
                ];
            }

            // Тягнемо налаштування листа (complex з max(1))
            $settings_rows = function_exists('carbon_get_post_meta')
                ? carbon_get_post_meta($partner_id, 'leadrouter_partner_email_settings')
                : [];

            $settings = (is_array($settings_rows) && !empty($settings_rows))
                ? (array) $settings_rows[0]
                : [];

            $subject_tpl = (string) ($settings['email_title'] ?? 'New lead from Highpriorityleads.com');
            $body_tpl    = (string) ($settings['email_text']  ?? "New lead:\n\n{first_name} {last_name}\n{phone}\n{email}");




            // Будуємо плоску карту значень для шаблонів
            $flat = self::flatten_for_templates($our_payload);

            $render = function (string $tpl) use ($flat): string {
                return preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', function ($m) use ($flat) {
                    $key = $m[1];
                    return array_key_exists($key, $flat) ? (string) $flat[$key] : '';
                }, $tpl);
            };

            $subject = $render($subject_tpl);
            $body    = $render($body_tpl);


            // Кілька email-адрес через кому/крапку з комою
            $to_list = array_filter(array_map('trim', preg_split('/[;,]+/', $to_raw)));

            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                //'From: highpriorityleads.com <api@highpriorityleads.com>',
                'Reply-To: highpriorityleads.com <api@highpriorityleads.com>',
            ];


            $t0 = microtime(true);
            $ok_any = false;

            foreach ($to_list as $to) {
                if ($to === '') {
                    continue;
                }
                $sent = wp_mail($to, $subject, $body, $headers);

                if ($sent) {
                    $ok_any = true;
                }
            }

            $latency = round((microtime(true) - $t0) * 1000, 2);

            $result = [
                'success'       => (bool) $ok_any,
                'retryable'     => false, // за замовчуванням вважаємо email-помилки не ретраїбл
                'status_code'   => $ok_any ? 250 : 550, // умовні SMTP-подібні коди
                'external_id'   => null,
                'error_code'    => $ok_any ? null : 'mail_failed',
                'error_message' => $ok_any ? null : 'wp_mail() повернув false',
            ];

            $resp = [
                'transport'    => 'email',
                'to'           => $to_list,
                'subject'      => $subject,
                'body_excerpt' => mb_substr($body, 0, 500),
                'latency_ms'   => $latency,
            ];


            return [
                'result' => $result,
                'resp'   => $resp,
            ];
        }


        /**
         * Робить плоску карту значень для шаблонів email:
         *  - first_name, last_name, email, phone, ...
         *  - origin_city, origin_state, ...
         *  - Vehicles.0.vehicle_model_year і т.п.
         *
         * Використовується у плейсхолдерах {ключ}.
         */
        protected static function flatten_for_templates(array $data, string $prefix = ''): array
        {
            $out = [];

            foreach ($data as $key => $value) {
                $key = (string) $key;
                $full = ($prefix === '') ? $key : ($prefix . '.' . $key);

                if (is_array($value)) {
                    // Нумерований масив (Vehicles[0], Vehicles[1]...)
                    $is_list = array_keys($value) === range(0, count($value) - 1);

                    if ($is_list) {
                        foreach ($value as $idx => $item) {
                            $sub_prefix = $full . '.' . $idx;
                            if (is_array($item)) {
                                $out += self::flatten_for_templates($item, $sub_prefix);
                            } else {
                                $out[$sub_prefix] = $item;
                            }
                        }
                    } else {
                        // Асоціативний масив
                        $out += self::flatten_for_templates($value, $full);
                    }
                } else {
                    // Просте значення
                    $out[$full] = $value;

                    // Для верхнього рівня дублюємо ключ без префікса:
                    // {first_name}, {email}, {origin_city} і т.п.
                    if ($prefix === '') {
                        $out[$key] = $value;
                    }
                }
            }

            return $out;
        }


    }}
