<?php
// Добавляет кастомную колонку и кнопку "Send test lead" в таблицу партнёров (CPT leadrouter_partner)
add_filter('manage_leadrouter_partner_posts_columns', function($columns) {
    $columns['send_test_lead'] = __('Send test lead', 'leadrouter');
    return $columns;
});

add_action('manage_leadrouter_partner_posts_custom_column', function($column, $post_id) {
    if ($column === 'send_test_lead') {
        echo '<button type="button" class="button lr-send-test-lead-btn" data-partner-id="' . esc_attr($post_id) . '">' . esc_html__('Send test lead', 'leadrouter') . '</button>';
        echo '<span class="lr-send-test-lead-status" style="margin-left:8px;"></span>';
    }
}, 10, 2);

// Подключаем JS для обработки клика по кнопке
add_action('admin_enqueue_scripts', function($hook) {
    global $typenow;
    if ($typenow === 'leadrouter_partner' && $hook === 'edit.php') {
        // Путь исправлен: assets/js/...
        wp_enqueue_script('lr-send-test-lead-js', plugins_url('/assets/js/lr-send-test-lead.js', dirname(dirname(__FILE__))), ['jquery'], null, true);
        wp_localize_script('lr-send-test-lead-js', 'LRAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lr_send_test_lead'),
        ]);
    }
});

// AJAX обработчик
add_action('wp_ajax_lr_send_test_lead', function() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Недостаточно прав']);
    }
    check_ajax_referer('lr_send_test_lead', 'nonce');
    $partner_id = isset($_POST['partner_id']) ? (int)$_POST['partner_id'] : 0;
    if ($partner_id <= 0) {
        wp_send_json_error(['message' => 'Некорректный partner_id']);
    }
    if (!function_exists('lr_sender_test_sample_payload') || !function_exists('lr_build_partner_payload')) {
        wp_send_json_error(['message' => 'Не найдены функции тестовой отправки']);
    }
    if (!class_exists('LeadRouter_Leads')) {
        wp_send_json_error(['message' => 'Не найден класс LeadRouter_Leads']);
    }
    // 1. Создаём тестового лида
    $payload = lr_sender_test_sample_payload();
    $lead_id = LeadRouter_Leads::create([
        'name'           => trim($payload['first_name'] . ' ' . $payload['last_name']),
        'email'          => $payload['email'],
        'phone'          => $payload['phone'],
        'from_city'      => $payload['origin_city'],
        'from_state'     => $payload['origin_state'],
        'from_zip'       => $payload['origin_postal_code'],
        'to_city'        => $payload['destination_city'],
        'to_state'       => $payload['destination_state'],
        'to_zip'         => $payload['destination_postal_code'],
        'vehicle_brand'  => $payload['Vehicles'][0]['vehicle_make'] ?? '',
        'vehicle_model'  => $payload['Vehicles'][0]['vehicle_model'] ?? '',
        'vehicle_year'   => $payload['Vehicles'][0]['vehicle_model_year'] ?? '',
        'status'         => 'new',
    ]);
    if (!$lead_id || is_wp_error($lead_id)) {
        wp_send_json_error(['message' => 'Ошибка создания тестового лида']);
    }
    // 2. Отправляем этого лида выбранному партнёру
    if (!class_exists('LeadRouter_Sender_Light')) {
        wp_send_json_error(['message' => 'Не найден класс LeadRouter_Sender_Light']);
    }
    $result = LeadRouter_Sender_Light::send($payload, $partner_id, [
        'attempt'      => 1,
        'http_retries' => 2,
        'timeout'      => 20,
        'lead_id'      => $lead_id,
    ]);
    wp_send_json_success(['result' => $result, 'lead_id' => $lead_id]);
});
