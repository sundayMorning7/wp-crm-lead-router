<?php
if (!defined('ABSPATH')) exit;

// Добавляем новую колонку "Статус группы" в список групп
add_filter('manage_leadrouter_group_posts_columns', function($columns) {
    $columns['group_active'] = __('Статус', 'leadrouter');
    $columns['group_toggle'] = __('Вкл/Выкл', 'leadrouter');
    return $columns;
});

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
    $wpdb->update($table, ['active' => $set_active, 'updated_at' => current_time('mysql', 1)], ['id' => $group_id]);
    // Редирект обратно на страницу групп
    $redirect = admin_url('edit.php?post_type=leadrouter_group');
    wp_safe_redirect($redirect);
    exit;
});


// Выводим статус и кнопку в новой колонке
add_action('manage_leadrouter_group_posts_custom_column', function($column, $post_id) {
    global $wpdb;
    if ($column === 'group_active') {
        // Получаем ID группы в leadrouter_groups
        $group_row = $wpdb->get_row($wpdb->prepare("SELECT active FROM {$wpdb->prefix}leadrouter_groups WHERE post_id = %d", $post_id));
        $active = $group_row ? (int)$group_row->active : 0;
        echo $active ? '<span style="color:green;font-weight:bold;">Активна</span>' : '<span style="color:#b00;font-weight:bold;">Вимкнена</span>';
    }
    if ($column === 'group_toggle') {
        $group_row = $wpdb->get_row($wpdb->prepare("SELECT id, active FROM {$wpdb->prefix}leadrouter_groups WHERE post_id = %d", $post_id));
        if (!$group_row) return;
        $active = (int)$group_row->active;
        $group_id = (int)$group_row->id;
        $nonce = wp_create_nonce('leadrouter_toggle_group_active_' . $group_id);
        $url = add_query_arg([
            'action' => 'leadrouter_toggle_group_active',
            'group_id' => $group_id,
            'set_active' => $active ? 0 : 1,
            '_wpnonce' => $nonce,
        ], admin_url('admin-post.php'));
        if ($active) {
            echo '<a href="' . esc_url($url) . '" class="button button-secondary">Вимкнути</a>';
        } else {
            echo '<a href="' . esc_url($url) . '" class="button button-primary">Увімкнути</a>';
        }
    }
}, 10, 2);
