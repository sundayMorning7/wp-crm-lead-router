<?php

require_once "inc/sending.php";
require_once "inc/admin/admin.php";

function md_register_style_and_scripts()
{

    $ver = '1.5.0';

    wp_enqueue_script('md_jquery', get_template_directory_uri() . '/assets/js/jquery-3.5.1.min.js', array(), $ver, true);


    wp_enqueue_script('md_webflow_js', get_template_directory_uri() . '/assets/js/webflow.js', array('md_jquery'), $ver, true);

    // wp_enqueue_script('md_intlTelInput_js', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/intlTelInput.min.js', array('md_jquery'), $ver, true);
    //  wp_enqueue_script('md_utils_js', 'https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.8/js/utils.js', array('md_jquery'), $ver, true);

    wp_enqueue_script('md_datepicker', get_template_directory_uri() . '/assets/js/datepicker.js', array('md_jquery'), $ver, true);
    wp_enqueue_script('md_choices', get_template_directory_uri() . '/assets/js/choices.min.js', array('md_jquery'), $ver, true);

    wp_enqueue_script('md_maks', 'https://unpkg.com/imask', array('md_jquery'), $ver, true);

    wp_enqueue_script('md_main_js', get_template_directory_uri() . '/assets/js/md_main.js', array('md_jquery'), $ver, true);
    wp_enqueue_script('md_utm_js', get_template_directory_uri() . '/assets/js/utm.js', array(), $ver, true);


    wp_localize_script(
        'md_main_js',
        'md_main_js',
        array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cargo_ajax'),
        )
    );


    if (is_page(22)) {
        wp_enqueue_script('md_calc_js', get_template_directory_uri() . '/assets/js/priceEstimator.js', array('md_jquery'), $ver, true);
    }
}

add_action('wp_enqueue_scripts', 'md_register_style_and_scripts');


add_action('wp_ajax_md_get_model', 'md_get_model');
add_action('wp_ajax_nopriv_md_get_model', 'md_get_model');

function md_get_model()
{

    if (!wp_verify_nonce($_POST['nonce'], 'cargo_ajax')) {
        wp_die('Проверка не пройдена!');
    }


    global $wpdb;
    $models = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT DISTINCT model FROM `w4pMd_cars` WHERE brand = %s",
            $_POST['brand'])
        , ARRAY_N
    );


    $models_to_json = array();
    foreach ($models as $model) {
        $models_to_json[] = array(
            'label' => $model[0],
            'value' => $model[0],
        );
    }

    $json['models'] = $models_to_json;

    $json['success'] = true;

    wp_send_json($json);
    wp_die();
}


add_action('wp_ajax_md_get_city', 'md_get_city');
add_action('wp_ajax_nopriv_md_get_city', 'md_get_city');

function md_get_city()
{

    if (!wp_verify_nonce($_POST['nonce'], 'cargo_ajax')) {
        wp_die('Проверка не пройдена!');
    }


    global $wpdb;
    $cities = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT `city`, `zip`, `state`, `code` FROM `w4pMd_city` WHERE `city` LIKE %s",
            $_POST['search'] . '%')
        , ARRAY_N
    );


    $city_to_json = array();
    foreach ($cities as $city) {
        $city_to_json[] = array(
            'city' => $city[0],
            'zip' => $city[1],
            'state' => $city[2],
            'code' => $city[3],
            'text' => $city[0] . ', ' . $city[3] . ', ' . $city[1],
        );
    }

    $json['result'] = $city_to_json;

    $json['success'] = true;

    wp_send_json($json);
    wp_die();
}


add_action('wp_ajax_md_get_zip', 'md_get_zip');
add_action('wp_ajax_nopriv_md_get_zip', 'md_get_zip');

function md_get_zip()
{

    if (!wp_verify_nonce($_POST['nonce'], 'cargo_ajax')) {
        wp_die('Проверка не пройдена!');
    }


    global $wpdb;
    $cities = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT `city`, `zip`, `state`, `code` FROM `w4pMd_city` WHERE `zip` LIKE %s",
            $_POST['search'] . '%')
        , ARRAY_N
    );


    $city_to_json = array();
    foreach ($cities as $city) {
        $city_to_json[] = array(
            'city' => $city[0],
            'zip' => $city[1],
            'state' => $city[2],
            'code' => $city[3],
            'text' => $city[1] . ' (' . $city[0] . ', ' . $city[3] . ')',
        );
    }

    $json['result'] = $city_to_json;

    $json['success'] = true;

    wp_send_json($json);
    wp_die();
}

function custom_post_type_event()
{

    $labels = array(
        'name' => 'Lead',
        'singular_name' => 'Lead',
        'menu_name' => 'Leads',
        'add_new_item' => 'Add Lead',
        'add_new' => 'Add Lead',
        'new_item' => 'New lead',
        'edit_item' => 'Edit',
        'update_item' => 'Update',
        'view_item' => 'View',
        'view_items' => 'View all',
    );
    $rewrite = array(
        'slug' => 'lead',
        'with_front' => true,
        'pages' => true,
        'feeds' => true,
    );

    $args = array(
        'label' => 'Lead',
        'description' => 'Lead from forms',
        'labels' => $labels,
        'supports' => array('title', 'editor'),
        'hierarchical' => true,
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => true,
        'menu_position' => 4,
        'menu_icon' => 'dashicons-groups',
        'show_in_admin_bar' => true,
        'show_in_nav_menus' => true,
        'can_export' => true,
        'has_archive' => 'events',
        'exclude_from_search' => false,
        'publicly_queryable' => true,
        'query_var' => 'event',
        'rewrite' => $rewrite,
        'capability_type' => 'post',

    );
    register_post_type('lead', $args);


}

add_action('init', 'custom_post_type_event', 0);

add_filter('manage_edit-lead_columns', function ($columns) {
    $columns['utm_source'] = 'UTM Source';
    $columns['utm_medium'] = 'UTM Medium';
    $columns['utm_campaign'] = 'UTM Campaign';
    return $columns;
});

add_action('manage_lead_posts_custom_column', function ($column, $post_id) {
    if (!in_array($column, ['utm_source', 'utm_medium', 'utm_campaign'], true)) {
        return;
    }

    $value = get_post_meta($post_id, $column, true);
    if ($value !== '') {
        echo esc_html($value);
    }
}, 10, 2);


if (function_exists('acf_add_options_page')) {
    acf_add_options_page([
        'page_title' => 'General Settings',
        'menu_title' => 'Settings',
        'menu_slug' => 'theme-general-settings',
        'capability' => 'edit_posts',
        'redirect' => false
    ]);


    acf_add_options_page([
        'page_title' => 'CRM Settings',
        'menu_title' => 'API Settings',
        'menu_slug' => 'theme-api-settings',
        'capability' => 'edit_posts',
        'redirect' => false
    ]);
}




/*
add_action( 'template_redirect', function() {
    if ( is_front_page() || is_404() || is_page(13) || is_page(17) ) {
        return;
    }

    wp_redirect(home_url());
    exit;
} );
*/


/*
if (isset($_GET['maks'])) {


    $filename = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes/cargo/carwithbodytype.json';

    if (file_exists($filename)) {

        ini_set('memory_limit', '-1');
        $string = file_get_contents(get_template_directory_uri() . '/carwithbodytype.json');

        $json_array = json_decode($string, true);


        global $wpdb;


        $tmpmodels = array();
        foreach ($json_array as $key => $value) {


            if (in_array($value['Model'] . $value['Make'] . $value['BodyType'], $tmpmodels)) {
                continue;
            }
            $wpdb->insert(
                $wpdb->prefix . 'cars', // указываем таблицу
                array( // 'название_колонки' => 'значение'
                    'brand' => $value['Make'],
                    'model' => $value['Model'],
                    'bodytype' => $value['BodyType']
                ),
                array(
                    '%s',
                    '%s',
                    '%s'
                )
            );
            $tmpmodels[] = $value['Model'] . $value['Make'] . $value['BodyType'];

        }


    }

}*/

