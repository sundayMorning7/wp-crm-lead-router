<?php
function lr_sender_test_http_send(int $partner_id) {
    if (!function_exists('carbon_get_post_meta')) {
        return 'Carbon Fields не підключений';
    }

    // 1) Беремо налаштування партнера
    $endpoint      = carbon_get_post_meta($partner_id, 'leadrouter_partner_endpoint');
    $auth_variant  = carbon_get_post_meta($partner_id, 'leadrouter_partner_auth_variant');
    $api_key       = carbon_get_post_meta($partner_id, 'leadrouter_partner_api_key');
    $api_key_header= carbon_get_post_meta($partner_id, 'leadrouter_partner_api_key_header');
    $mapRows       = (array)carbon_get_post_meta($partner_id, 'leadrouter_partner_map');

    if (empty($endpoint)) {
        return '<strong>Endpoint не задано</strong>';
    }

    // 2) Наш тестовий payload (можеш замінити на реальні дані)
    $ourPayload = lr_sender_test_sample_payload();

    // 3) Побудова партнерського payload
    $partnerPayload = lr_build_partner_payload($ourPayload, $mapRows);

    // 4) Формування заголовків
    $headers = [
        'Content-Type' => 'application/json',
    ];

    // 5) Авторизація — залежить від типу
    switch ($auth_variant) {
        case 'header':
            if (!empty($api_key) && !empty($api_key_header)) {
                $headers[$api_key_header] = $api_key;
            }
            break;

        case 'query':
            $endpoint = add_query_arg(['apikey' => $api_key], $endpoint);
            break;

        case 'payload':
            if (!empty($api_key)) {
                $partnerPayload['apikey'] = $api_key;
            }
            break;
    }

    // 6) HTTP-відправка
    $args = [
        'method'      => 'POST',
        'headers'     => $headers,
        'body'        => wp_json_encode($partnerPayload),
        'timeout'     => 20,
        'redirection' => 3,
    ];

    $start = microtime(true);
    $response = wp_remote_post($endpoint, $args);
    $elapsed = round((microtime(true) - $start) * 1000, 2);

    // 7) Розбір відповіді
    if (is_wp_error($response)) {
        return '<strong>WP Error:</strong> ' . esc_html($response->get_error_message());
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);

    return '
    <div style="background:#111;color:#eee;padding:12px;">
      <h2>📡 Результат тестової відправки</h2>
      <p><b>Endpoint:</b> '.esc_html($endpoint).'</p>
      <p><b>Status:</b> '.$code.' <b>Time:</b> '.$elapsed.' ms</p>
      <h3>➡ Відправлений payload</h3>
      <pre>'.esc_html(json_encode($partnerPayload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>
      <h3>⬅ Відповідь сервера</h3>
      <pre>'.esc_html($body).'</pre>
    </div>';
}

/**
 * Шорткод для швидкого тесту:
 * [lr_sender_http_test partner_id="6287"]
 */
add_shortcode('lr_sender_http_test', function($atts){
    lr_sender_test_require_admin();
    $atts = shortcode_atts([
        'partner_id' => 0,
    ], $atts, 'lr_sender_http_test');

    $partner_id = (int)$atts['partner_id'];
    if ($partner_id <= 0) return 'partner_id не вказаний';

    return lr_sender_test_http_send($partner_id);
});


// URL-тригер у бек-офісі:
// /wp-admin/?lr_sender_test_http=1&partner_id=6287
add_action('admin_init', function () {
    if (!isset($_GET['lr_sender_test_http']) || (int) $_GET['lr_sender_test_http'] !== 1) {
        return;
    }
    // доступ лише адміну
    lr_sender_test_require_admin();

    $partner_id = isset($_GET['partner_id']) ? (int) $_GET['partner_id'] : 0;
    if ($partner_id <= 0) {
        wp_die('Missing partner_id');
    }

    // Виводимо HTML-результат тесту і завершуємо
    echo lr_sender_test_http_send($partner_id);



    exit;
});
