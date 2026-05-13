<?php
// Безпека: запускати тільки адміністраторам
function lr_sender_test_require_admin() {
    if (!is_user_logged_in() || !current_user_can('manage_options')) {
        wp_die(__('Access denied', 'leadrouter'));
    }
}

/**
 * Тестові дані нашого стандартного payload (спеціально з "гострими кутами")
 */
function lr_sender_test_sample_payload(): array {
    $vehicle_inop = 'Running'; // подивимось як працює inop_binary → 0
    $vehicle_inop = (mb_strtolower($vehicle_inop) === 'running') ? '0' : '1';

    $name = 'John   DOE'; // перевіримо title/trim у мапінгу, а також split_name_* при потребі

    return [
        'first_name' => 'TEST',
        'last_name'  => 'TEST',
        'email'      => 'TEST.TEST@MAIL.COM',
        'phone'      => '+1 (213) 555-0199 ext 77', // перевірка phone_us_dashed
        'ship_date'  => '11 5 2025',                // перевірка date_mdy_dash
        'comment_from_shipper' => '',
        'transport_type'       => '1',
        'Vehicles' => [
            [
                'vehicle_type'        => 'SUV',
                'vehicle_model_year'  => '2021',
                'vehicle_make'        => 'toyota',
                'vehicle_model'       => 'camry',
                'vehicle_inop'        => $vehicle_inop, // має стати 0 через inop_binary
            ]
        ],
        // from
        'origin_country'     => 'USA',
        'origin_city'        => 'los angeles',
        'origin_state'       => 'ca',
        'origin_postal_code' => '90001-1234',
        // to
        'destination_country'     => 'USA',
        'destination_city'        => 'new york',
        'destination_state'       => 'ny',
        'destination_postal_code' => '10001',
        // auth у payload (для прикладу)
        'utm_source' => 'test facebook',
    ];
}

/**
 * Рендер: акуратно показати JSON у <pre>
 */
function lr_sender_test_pretty_json($data): string {
    return '<pre style="padding:12px; background:#111; color:#eee; overflow:auto; border-radius:6px;">'
        . esc_html(json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))
        . '</pre>';
}

/**
 * Головна функція тесту:
 * - бере partner_id
 * - читає мапінг із Carbon Fields
 * - будує partner payload за lr_build_partner_payload()
 * - показує "Наш → Партнерський"
 */
function lr_sender_test_run(int $partner_id): string {
    // 1) Мапінг партнера з адмінки
    if (!function_exists('carbon_get_post_meta')) {
        return '<div style="color:#c00;">Carbon Fields is not available (carbon_get_post_meta missing).</div>';
    }
    $mapRows = (array) carbon_get_post_meta($partner_id, 'leadrouter_partner_map');

    // 2) Наш тестовий payload
    $our = lr_sender_test_sample_payload();

    // 3) Побудова партнерського payload (застосовує трансформації)
    $partnerPayload = lr_build_partner_payload($our, $mapRows);

    // 4) Дрібний диф: які ключі з’явились / пропали
    $flatOur = lr_dot_flatten($our);
    $ourKeys   = array_keys($flatOur);
    $theirKeys = array_keys($partnerPayload);
    sort($ourKeys); sort($theirKeys);

    $html  = '<div class="wrap"><h1>LeadRouter Sender — Test Payload</h1>';
    $html .= '<p><strong>Partner ID:</strong> ' . (int)$partner_id . '</p>';

    $html .= '<h2>Наш payload (вхід)</h2>' . lr_sender_test_pretty_json($our);
    $html .= '<h2>Партнерський payload (після мапінгу+трансформацій)</h2>' . lr_sender_test_pretty_json($partnerPayload);

    $html .= '<h3>Ключі (для довідки)</h3>';
    $html .= '<div style="display:flex; gap:16px; flex-wrap:wrap;">';
    $html .= '<div style="flex:1; min-width:320px;"><h4>Our (dot)</h4>' . lr_sender_test_pretty_json($ourKeys) . '</div>';
    $html .= '<div style="flex:1; min-width:320px;"><h4>Their (flat)</h4>' . lr_sender_test_pretty_json($theirKeys) . '</div>';
    $html .= '</div>';

    $html .= '<p style="margin-top:24px;">Готово. Перевір регістр, цифри телефону (має бути <code>ddd-ddd-dddd</code>) і дату (<code>MM-DD-YYYY</code>), '
        . 'напр. <code>ph → 213-555-0199</code>, <code>ps → 11-05-2025</code>.</p>';

    $html .= '</div>';
    return $html;
}

/**
 * Шорткод: [lr_sender_test partner_id="123"]
 */
add_shortcode('lr_sender_test', function($atts){
    lr_sender_test_require_admin();
    $atts = shortcode_atts([
        'partner_id' => 0,
    ], $atts, 'lr_sender_test');

    $partner_id = (int)$atts['partner_id'];
    if ($partner_id <= 0) {
        return '<div style="color:#c00;">Add partner_id: [lr_sender_test partner_id="123"]</div>';
    }
    return lr_sender_test_run($partner_id);
});

/**
 * URL-тригер у бек-офісі:
 * /wp-admin/?lr_sender_test=1&partner_id=123
 * Працює лише для адміністраторів.
 */
add_action('admin_init', function(){
    if (!isset($_GET['lr_sender_test']) || (int)$_GET['lr_sender_test'] !== 1) return;
    lr_sender_test_require_admin();

    $partner_id = isset($_GET['partner_id']) ? (int)$_GET['partner_id'] : 0;
    if ($partner_id <= 0) {
        wp_die('Missing partner_id');
    }

    echo lr_sender_test_run($partner_id);
    exit;
});



if (!function_exists('lr_dot_flatten')) {
    /**
     * Сплющує масив у dot-ключі, напр. Vehicles.0.vehicle_model_year
     */
    function lr_dot_flatten(array $arr, string $prefix = ''): array {
        $res = [];
        foreach ($arr as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) {
                $res += lr_dot_flatten($v, $key);
            } else {
                $res[$key] = $v;
            }
        }
        return $res;
    }
}

if (!function_exists('lr_build_partner_payload')) {
    /**
     * Формує партнерський payload із нашого за мапінгом.
     *
     * @param array $ourPayload   Наш payload (включно з Vehicles[0] тощо)
     * @param array $mapRows      Мапінг партнера (з Carbon Fields)
     * @return array              Плоский партнерський payload
     */
    function lr_build_partner_payload(array $ourPayload, array $mapRows): array {
        $flat = lr_dot_flatten($ourPayload);
        $out  = [];

        // --- Автоматическая сборка full_name, если требуется в маппинге ---
        $need_full_name = false;
        foreach ($mapRows as $row) {
            if (isset($row['our_key']) && trim((string)$row['our_key']) === 'full_name') {
                $need_full_name = true;
                break;
            }
        }
        if ($need_full_name && isset($ourPayload['first_name'], $ourPayload['last_name'])) {
            $flat['full_name'] = trim($ourPayload['first_name'] . ' ' . $ourPayload['last_name']);
        }

        foreach ($mapRows as $row) {
            $our    = trim((string)($row['our_key'] ?? ''));
            $their  = trim((string)($row['their_key'] ?? ''));
            $trans  = (string)($row['transform'] ?? 'none');
            $defVal = array_key_exists('default_value', $row) ? $row['default_value'] : '';

            if ($their === '') continue;

            $value = array_key_exists($our, $flat) ? $flat[$our] : null;
            if ($value === null || $value === '') {
                $value = ($defVal === '') ? null : $defVal;
            }

            if ($value !== null && $trans) {
                $value = LeadRouter_Transform::apply($value, $trans);
            }

            if ($value === null || $value === '') continue;

            $out[$their] = $value;
        }

        // 🧰 Постобробка: якщо є ключі типу "Vehicles.0.*", збираємо їх у масив
        $vehicles = [];
        foreach ($out as $key => $value) {
            if (strpos($key, 'Vehicles.') === 0) {
                // розбиваємо Vehicles.0.vehicle_model_year → [Vehicles, 0, vehicle_model_year]
                $parts = explode('.', $key);
                if (count($parts) >= 3) {
                    $index = (int)$parts[1];
                    $field = $parts[2];
                    $vehicles[$index][$field] = $value;
                    unset($out[$key]); // видаляємо старий плоский ключ
                }
            }
        }

        if (!empty($vehicles)) {
            // сортуємо по індексах, якщо треба
            ksort($vehicles);
            // збираємо як масив
            $out['Vehicles'] = array_values($vehicles);
        }

        return $out;
    }
}



// includes/shortcode-sender-live-test.php
add_shortcode('lr_sender_live', function($atts){
    if (!is_user_logged_in() || !current_user_can('manage_options')) return 'Access denied';
    $atts = shortcode_atts(['partner_id'=>0], $atts, 'lr_sender_live');
    $pid  = (int)$atts['partner_id'];
    if ($pid <= 0) return 'Missing partner_id';

    if (!function_exists('lr_sender_test_sample_payload')) {
        return 'Missing lr_sender_test_sample_payload().';
    }
    $our = lr_sender_test_sample_payload();

    // http_retries=2 → максимум 3 спроби (1 + 2)
    $out = LeadRouter_Sender_Light::send($our, $pid, [
        'attempt'      => 1,
        'http_retries' => 2,
        'timeout'      => 20,
    ]);


    echo $_SERVER['SERVER_ADDR'];

    $html  = '<h2>Sender Live — Result</h2><pre style="background:#111;color:#eee;padding:12px;">'.esc_html(json_encode($out['result'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
    $html .= '<h3>Request</h3><pre style="background:#111;color:#eee;padding:12px;">'.esc_html(json_encode($out['req'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
    $html .= '<h3>Response</h3><pre style="background:#111;color:#eee;padding:12px;">'.esc_html(json_encode($out['resp'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
    $html .= '<h3>Debug</h3><pre style="background:#111;color:#eee;padding:12px;">'.esc_html(json_encode($out['debug'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)).'</pre>';
    return $html;
});
