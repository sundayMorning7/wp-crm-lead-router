<?php


function md_register_style_and_scripts()
{

    wp_enqueue_script('md_main_js', get_template_directory_uri() . '/assets/js/md_main.js');

    wp_localize_script(
        'md_main_js',
        'md_main_js',
        array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cargo_ajax'),
        )
    );

}

add_action('wp_enqueue_scripts', 'md_register_style_and_scripts');


/*
add_action( 'template_redirect', function() {
    if ( is_front_page() || is_404() || is_page(13)) {
        return;
    }

    wp_redirect(home_url());
    exit;
} );
*/


/*

$filename = $_SERVER['DOCUMENT_ROOT'] . '/wp-content/themes/cargo/all.json';
if (file_exists($filename)) {

    ini_set('memory_limit', '-1');
    $string = file_get_contents( get_template_directory_uri() . '/all.json');

    $json_array = json_decode($string, true);


    global $wpdb;

    $testarray = array(
        0 => array(
            'make' => 'Brand 1',
            'model' => 'Model 1',
        ),
        1 => array(
            'make' => 'Brand 1',
            'model' => 'Model 1',
        ),
        2 => array(
            'make' => 'Brand 1',
            'model' => 'Model 1',
        ),
        3 => array(
            'make' => 'Brand 2',
            'model' => 'Model 1',
        ),
        4 => array(
            'make' => 'Brand 2',
            'model' => 'Model 2',
        ),
        5 => array(
            'make' => 'Brand 2',
            'model' => 'Model 1',
        ),
        6 => array(
            'make' => 'Brand 3',
            'model' => 'Model 1',
        )
    );

$tmpmodels = array();
foreach ($json_array as $key => $value) {


    if (in_array($value['model'].$value['make'], $tmpmodels)) {
        continue;
    }
    $wpdb->insert(
        $wpdb->prefix . 'cars', // указываем таблицу
        array( // 'название_колонки' => 'значение'
            'brand' => $value['make'],
            'model' => $value['model']
        ),
        array(
            '%s',
            '%s'
        )
    );
    $tmpmodels[] = $value['model'] . $value['make'];

}


}


*/