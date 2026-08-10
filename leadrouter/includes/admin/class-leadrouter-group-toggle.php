<?php
if (!defined('ABSPATH')) exit;

// Обработчик смены статуса активности группы
add_action('admin_post_leadrouter_toggle_group_active', function() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Недостаточно прав.', 'leadrouter'));
    }
    $group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
    $set_active = isset($_GET['set_active']) ? (int)$_GET['set_active'] : null;
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
    if (!$group_id || !in_array($set_active, [0,1], true)) {
        wp_die(__('Некорректные данные.', 'leadrouter'));
    }
    if (!wp_verify_nonce($nonce, 'leadrouter_toggle_group_active_' . $group_id)) {
        wp_die(__('Nonce проверка не пройдена.', 'leadrouter'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'leadrouter_groups';
    // updated_at — строго EST: цю колонку читає reset_eff_if_new_day() як дату
    // «останнього дня». GMT-час тут міг зсунути дату вперед і скинути eff серед дня.
    $wpdb->update($table, ['active' => $set_active, 'updated_at' => leadrouter_now_mysql_est()], ['id' => $group_id]);
    // Редирект обратно на страницу групп
    $redirect = admin_url('admin.php?page=leadrouter');
    wp_safe_redirect($redirect);
    exit;
});
