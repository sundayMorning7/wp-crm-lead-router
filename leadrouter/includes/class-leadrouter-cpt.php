<?php

use Carbon_Fields\Container;
use Carbon_Fields\Field;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register custom post types for LeadRouter: Groups and Partners
 */
function leadrouter_register_cpts()
{
    // Groups CPT
    register_post_type('leadrouter_group', array(
        'labels' => array(
            'name' => __('Групи', 'leadrouter'),
            'singular_name' => __('Група', 'leadrouter'),
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => array('title'),
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-groups',
    ));

    // Partners CPT
    register_post_type('leadrouter_partner', array(
        'labels' => array(
            'name' => __('Партнери', 'leadrouter'),
            'singular_name' => __('Партнер', 'leadrouter'),
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false,
        'supports' => array('title'),
        'capability_type' => 'post',
        'menu_icon' => 'dashicons-businessman',
    ));
}

add_action('init', 'leadrouter_register_cpts');

/**
 * Carbon Fields meta
 */
function leadrouter_create_custom_fields()
{

    // OPTIONS

    Container::make('theme_options', __('LeadRouter Settings', 'leadrouter'))
        ->set_page_parent('leadrouter')
        ->set_page_menu_title(__('Налаштування', 'leadrouter'))
        ->set_page_file('leadrouter-settings')
        ->add_tab(__('Основні', 'leadrouter'), array(

/*
            Field::make('number', 'leadrouter_pause_min', __('Pause between sends (MIN, sec)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(5)
                ->set_help_text('Мінімальна затримка між відправками'),

            Field::make('number', 'leadrouter_pause_max', __('Pause between sends (MAX, sec)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(15)
                ->set_help_text('Максимальна затримка між відправками'),*/

/*
            Field::make('select', 'leadrouter_default_group', __('Група за замовчуванням', 'leadrouter'))
                ->add_options(function () {
                    $options = array(
                        '0' => '—',
                    );

                    $groups = get_posts(array(
                        'post_type' => 'leadrouter_group',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ));

                    foreach ($groups as $group) {
                        $options[$group->ID] = $group->post_title;
                    }

                    return $options;
                })
                ->set_default_value('0'),*/

        ))
        ->add_tab(__('Dispatch', 'leadrouter'), array(

            Field::make('text', 'leadrouter_pause_min', __('Pause between sends (MIN, min)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(5)
                ->set_help_text('Мінімальна затримка між відправками в хвилинах' ),

            Field::make('text', 'leadrouter_pause_max', __('Pause between sends (MAX, min)', 'leadrouter'))
                ->set_attribute('min', 0)
                ->set_default_value(15)
                ->set_help_text('Максимальна затримка між відправками в хвилинах'),

                Field::make('select', 'leadrouter_error_group_id', __('Група для помилкових статусів', 'leadrouter'))
                    ->add_options(function () {
                        $options = array(
                            '0' => '—',
                        );

                        $groups = get_posts(array(
                            'post_type' => 'leadrouter_group',
                            'posts_per_page' => -1,
                            'post_status' => 'publish',
                            'orderby' => 'title',
                            'order' => 'ASC',
                        ));

                        foreach ($groups as $group) {
                            $options[$group->ID] = $group->post_title;
                        }

                        return $options;
                    })
                    ->set_default_value('0'),





/*
            Field::make('select', 'leadrouter_dispatch_method', __('Метод відправки', 'leadrouter'))
                ->add_options(array(
                    'manual' => __('Manual', 'leadrouter'),
                    'script' => __('Script', 'leadrouter'),
                    'cron'   => __('Cron', 'leadrouter'),
                ))
                ->set_default_value('script'),

            Field::make('checkbox', 'leadrouter_queue_if_closed', __('Ставити в чергу, якщо партнер закритий', 'leadrouter'))
                ->set_option_value('yes')
                ->set_default_value('yes'),*/

        ))
        ->add_tab(__('Logs', 'leadrouter'), array(
/*
            Field::make('checkbox', 'leadrouter_log_enabled', __('Увімкнути логування', 'leadrouter'))
                ->set_option_value('yes')
                ->set_default_value('yes'),*/

        ));

    // ===== GROUP =====
    Container::make('post_meta', __('Налаштування групи', 'leadrouter'))
        ->where('post_type', '=', 'leadrouter_group')
        ->add_tab(__('Основні', 'leadrouter'), array(
/*
            Field::make('select', 'leadrouter_group_distribution_type', __('Тип розподілу', 'leadrouter'))
                ->set_options(array(
                    'all_at_once' => __('Одразу всім', 'leadrouter'),
                    'one_by_one' => __('По одному', 'leadrouter'),
                ))
                ->set_width(50),*/
/*
            Field::make('text', 'leadrouter_group_priority', __('Пріоритет', 'leadrouter'))
                ->set_attribute('type', 'number')
                ->set_width(50)
                ->set_default_value('100'),*/

/*
            Field::make('radio', 'leadrouter_group_lasthoupegroup', __('Last hope group ? (only one !)', 'leadrouter'))
                ->set_options(array(
                    '1' => __('Yes', 'leadrouter'),
                    '0' => __('No', 'leadrouter'),
                ))
                ->set_default_value('0')
                ->set_width(50),
*/
            Field::make('html', 'leadrouter_group_list_partner')
                ->set_html(function () {
                    $group_id = get_the_ID();
                    if (!$group_id) {
                        return '';
                    }

                    $posts = get_posts(array(
                        'post_type' => 'leadrouter_partner',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'meta_query' => array(
                            array(
                                'key' => 'leadrouter_partner_group',
                                'value' => $group_id,
                                'compare' => '=',
                                'type' => 'NUMERIC',
                            ),
                        ),
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ));

                    if (!$posts) {
                        return '<em>' . esc_html__('Партнери відсутні', 'leadrouter') . '</em>';
                    }

                    $html = '<h4>' . esc_html__('Партнери у цій групі', 'leadrouter') . '</h4>';
                    foreach ($posts as $post) {
                        $html .= '<div><a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html($post->post_title) . '</a></div>';
                    }
                    return $html;
                })
                ->set_width(30),
        ));

    // ===== PARTNER =====
    Container::make('post_meta', __('Налаштування партнера', 'leadrouter'))
        ->where('post_type', '=', 'leadrouter_partner')
        ->add_tab(__('Основні', 'leadrouter'), array(

            Field::make('select', 'leadrouter_partner_group', __('Група', 'leadrouter'))
                ->add_options(function () {
                    $posts = get_posts(array(
                        'post_type' => 'leadrouter_group',
                        'posts_per_page' => -1,
                        'post_status' => 'publish',
                        'orderby' => 'title',
                        'order' => 'ASC',
                    ));
                    $options = [];
                    foreach ($posts as $post) {
                        $options[$post->ID] = $post->post_title;
                    }
                    return $options;
                })
                ->set_width(50)
                ->set_required(true),

            Field::make('radio', 'leadrouter_partner_active', __('Активний?', 'leadrouter'))
                ->set_options(array(
                    '1' => __('Так', 'leadrouter'),
                    '0' => __('Ні', 'leadrouter'),
                ))
                ->set_default_value('1')
                ->set_width(50),

            Field::make('separator', 'leadrouter_partner_sep0', ''),

            Field::make('radio', 'leadrouter_partner_allow_alaska', __('Дозволяти Alaska?', 'leadrouter'))
                ->set_options(array(
                    '1' => __('Так', 'leadrouter'),
                    '0' => __('Ні', 'leadrouter'),
                ))
                ->set_default_value('0')
                ->set_width(50),

            Field::make('radio', 'leadrouter_partner_allow_hawaii', __('Дозволяти Hawaii?', 'leadrouter'))
                ->set_options(array(
                    '1' => __('Так', 'leadrouter'),
                    '0' => __('Ні', 'leadrouter'),
                ))
                ->set_default_value('0')
                ->set_width(50),

            Field::make('separator', 'leadrouter_partner_sep1', ''),

            // Monday
            Field::make('html', 'leadrouter_partner_mon_label')->set_html('<h3>' . esc_html__('Monday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_mon_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_mon_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_mon_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_mon_label_end')->set_html('')->set_width(30),

            // Tuesday
            Field::make('html', 'leadrouter_partner_tue_label')->set_html('<h3>' . esc_html__('Tuesday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_tue_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_tue_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_tue_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_tue_label_end')->set_html('')->set_width(30),

            // Wednesday
            Field::make('html', 'leadrouter_partner_wed_label')->set_html('<h3>' . esc_html__('Wednesday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_wed_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_wed_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_wed_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_wed_label_end')->set_html('')->set_width(30),

            // Thursday
            Field::make('html', 'leadrouter_partner_thu_label')->set_html('<h3>' . esc_html__('Thursday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_thu_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_thu_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_thu_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_thu_label_end')->set_html('')->set_width(30),

            // Friday
            Field::make('html', 'leadrouter_partner_fri_label')->set_html('<h3>' . esc_html__('Friday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_fri_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_fri_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_fri_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_fri_label_end')->set_html('')->set_width(30),

            // Saturday
            Field::make('html', 'leadrouter_partner_sat_label')->set_html('<h3>' . esc_html__('Saturday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_sat_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_sat_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_sat_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_sat_label_end')->set_html('')->set_width(30),

            // Sunday
            Field::make('html', 'leadrouter_partner_sun_label')->set_html('<h3>' . esc_html__('Sunday', 'leadrouter') . ':</h3>')->set_width(10),
            Field::make('text', 'leadrouter_partner_sun_limit', __('Ліміт', 'leadrouter'))->set_attribute('type', 'number')->set_width(20),
            Field::make('time', 'leadrouter_partner_sun_start', __('Початок', 'leadrouter'))->set_width(20),
            Field::make('time', 'leadrouter_partner_sun_end', __('Завершення', 'leadrouter'))->set_width(20),
            Field::make('html', 'leadrouter_partner_sun_label_end')->set_html('')->set_width(30),
        ))
        ->add_tab(__('Тех інфо', 'leadrouter'), array(

            Field::make('select', 'leadrouter_partner_type', __('Тип партнера', 'leadrouter'))
                ->set_options(array(
                    'standard' => __('Standard', 'leadrouter'),
                    'custom' => __('API', 'leadrouter'),
                    'email' => __('Email', 'leadrouter'),
                ))
                ->set_default_value('standard')
                ->set_required(true),


            Field::make('text', 'leadrouter_partner_endpoint', __('Endpoint URL', 'leadrouter'))
                ->set_width(50)
                ->set_help_text('Повний URL партнерського API')
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('select', 'leadrouter_partner_auth_variant', __('Auth variant', 'leadrouter'))
                ->set_width(25)
                ->add_options([
                    'header' => 'Header (X-API-Key: ...)',
                    'payload' => 'Payload ("apikey" у тілі)',
                    'payload_authkey' => 'Payload ("AuthKey" у тілі)',
                    'payload_xapikey' => 'Payload ("XAPIKEY" у тілі)',
                    'query' => 'Query (?apikey=...)',
                    'none' => 'None',
                ])
                ->set_default_value('header')
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('text', 'leadrouter_partner_api_key', __('API Key', 'leadrouter'))
                ->set_width(25)
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('text', 'leadrouter_partner_api_key_header', __('API Key Header', 'leadrouter'))
                ->set_width(25)
                ->set_default_value('X-API-Key')
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('text', 'leadrouter_partner_email', __('Email', 'leadrouter'))
                ->set_width(25),

            Field::make('checkbox', 'leadrouter_partner_require_ok_json', __('Require {"ok": true} in 2xx', 'leadrouter'))
                ->set_option_value('yes')
                ->set_width(25)
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ]),

            Field::make('complex', 'leadrouter_partner_email_settings', __('Налаштування email', 'leadrouter'))
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'email',
                        'compare' => '=',
                    ],
                ])
                ->set_collapsed(false)
                ->set_max(1)
                ->setup_labels([
                    'plural_name' => 'Налаштування листа',
                    'singular_name' => 'Налаштування листа',
                ]) ->add_fields([
                    Field::make('text', 'email_title', 'Заголовок')
                        ->set_width(100)
                        ->set_help_text('
        Використовуйте плейсхолдери у фігурних дужках.<br/>
        Напр.: <code>{first_name}</code>, <code>{last_name}</code>, <code>{phone}</code>, <code>{email}</code>,<br/>
        <code>{origin_city}</code>, <code>{origin_state}</code>, <code>{destination_city}</code>, <code>{destination_state}</code>,<br/>
        <code>{vehicle_year}</code>, <code>{vehicle_brand}</code>, <code>{vehicle_model}</code>,<br/>
        а також вкладені: <code>{Vehicles.0.vehicle_model_year}</code>, <code>{Vehicles.0.vehicle_make}</code> і т.п.
    '),
                    Field::make('textarea', 'email_text', 'Текст')
                        ->set_width(100)
                        ->set_rows(15)
                        ->set_classes('cf-code-editor')
                        ->set_help_text('
        Тіло листа. Підтримуються ті ж плейсхолдери, що й у заголовку:<br/>
        <code>{first_name}</code>, <code>{last_name}</code>, <code>{email}</code>, <code>{phone}</code>,<br/>
        <code>{origin_city}</code>, <code>{origin_state}</code>, <code>{destination_city}</code>, <code>{destination_state}</code>,<br/>
        <code>{vehicle_year}</code>, <code>{vehicle_brand}</code>, <code>{vehicle_model}</code>, <code>{vehicle_condition}</code>,<br/>
        <code>{Vehicles.0.vehicle_model_year}</code>, <code>{Vehicles.0.vehicle_make}</code>, <code>{Vehicles.0.vehicle_model}</code>, <code>{Vehicles.0.vehicle_inop}</code>.<br/><br/>
        Наприклад:<br/>
        <code>
        New lead from {first_name} {last_name}<br/>
        Phone: {phone}<br/>
        From: {origin_city}, {origin_state}<br/>
        To: {destination_city}, {destination_state}<br/>
        Vehicle: {vehicle_year} {vehicle_brand} {vehicle_model}
        </code>
    '),
                ]),

            Field::make('complex', 'leadrouter_partner_map', __('Basic Map — Наш ключ → Їх ключ', 'leadrouter'))
                ->set_conditional_logic([
                    [
                        'field' => 'leadrouter_partner_type',
                        'value' => 'custom',
                        'compare' => '=',
                    ],
                ])
                ->set_collapsed(false)
                ->setup_labels([
                    'plural_name' => 'Відповідності',
                    'singular_name' => 'Відповідність',
                ])
                ->add_fields([
                    Field::make('text', 'our_key', 'Наш ключ (our_key)')->set_width(30)
                        ->set_help_text('напр. first_name, last_name, email, phone, ship_date, Vehicles.0.vehicle_model_year, origin_city, ...'),
                    Field::make('text', 'their_key', 'Їх ключ (their_key)')->set_width(20)
                        ->set_help_text('напр. fn, ln, em, ph, ps, ty, yr, ma, mo, rc, oc, os, oz, dc, ds, dz, AuthKey'),
                    Field::make('select', 'transform', 'Трансформація')->set_width(25)->add_options([
                        'none'           => 'none',
                        'lower'          => 'lower',
                        'upper'          => 'upper',
                        'title'          => 'title',
                        'digits'         => 'digits (телефон/zip)',
                        'int'            => 'int',
                        'float2'         => 'float(2)',
                        'date_Ymd'       => 'date:Y-m-d (ps)',
                        'date_mdy'       => 'date:m/d/Y (ps)',
                        'date_mdy_dash'  => 'date → MM-DD-YYYY',       // ⬅️ нове
                        'split_name_fn'  => 'split_name:fn (з name)',
                        'split_name_ln'  => 'split_name:ln (з name)',
                        'map_running'    => 'map:Running→operable, NonRunning→inoperable',
                        'inop_binary'    => 'condition→vehicle_inop (Running→0, інше→1)',
                        'phone_us_dashed'=> 'phone → 111-111-1111',    // ⬅️ нове
                    ])->set_default_value('none'),
                    Field::make('text', 'default_value', 'Default value')->set_width(25)
                        ->set_help_text('Підставляється, якщо значення порожнє'),
                ])
                ->set_default_value(lr_partner_default_map())
                ->set_help_text('
        <b>Наш ключ</b> — з нашого стандартного payload (BATS).<br/>
        <b>Їх ключ</b> — якому партнерському ключу він відповідає (fn, ln, em, ph, ps...).<br/>
        Стартові відповідності вже налаштовані під стандартний BATS payload.<br/><br/>
        <button type="button" class="button button-primary js-lr-autofill-map">Автозаповнити мапінг (BATS)</button>
    ')

            // Додаткові поля
            /*
        Field::make('textarea', 'leadrouter_partner_template_json', __('JSON Template', 'leadrouter'))
            ->set_rows(10)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),

        Field::make('textarea', 'leadrouter_partner_extra_headers', __('Extra Headers (JSON)', 'leadrouter'))
            ->set_rows(3)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),

        Field::make('textarea', 'leadrouter_partner_query_params', __('Query Params (JSON)', 'leadrouter'))
            ->set_rows(3)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),

        Field::make('textarea', 'leadrouter_partner_constants', __('Constants (JSON)', 'leadrouter'))
            ->set_rows(3)->set_conditional_logic(array(
                array(
                    'field' => 'leadrouter_partner_type',
                    'value' => 'standard',
                    'compare' => '=',
                ),
            )),*/

        ));
}
// add_action('carbon_fields_register_fields', 'leadrouter_create_custom_fields');

// Десь у your-plugin.php (один раз усьому плагіну):
// add_action('after_setup_theme', function() {
//     \Carbon_Fields\Carbon_Fields::boot();
// });


function lr_partner_default_map(): array {
    return [
        // ───────── Контакт ─────────
        ['our_key' => 'first_name',                'their_key' => 'fn',  'transform' => 'none',      'default_value' => ''],
        ['our_key' => 'last_name',                 'their_key' => 'ln',  'transform' => 'none',      'default_value' => ''],
        ['our_key' => 'email',                     'their_key' => 'em',  'transform' => 'lower',     'default_value' => ''],
        ['our_key' => 'phone',                     'their_key' => 'ph',  'transform' => 'digits',    'default_value' => ''],

        // Дата
        ['our_key' => 'ship_date',                 'their_key' => 'ps',  'transform' => 'date_mdy',  'default_value' => ''],

        // Додатково
        ['our_key' => 'comment_from_shipper',      'their_key' => 'comment',        'transform' => 'none',  'default_value' => ''],
        ['our_key' => 'transport_type',            'their_key' => 'transport_type', 'transform' => 'int',   'default_value' => '1'],

        // ───────── Vehicles[0] ─────────
        ['our_key' => 'Vehicles.0.vehicle_type',        'their_key' => 'ty', 'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model_year',  'their_key' => 'yr', 'transform' => 'int',         'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_make',        'their_key' => 'ma', 'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_model',       'their_key' => 'mo', 'transform' => 'title',       'default_value' => ''],
        ['our_key' => 'Vehicles.0.vehicle_inop',        'their_key' => 'rc', 'transform' => 'inop_binary', 'default_value' => '0'],

        // ───────── From ─────────
        ['our_key' => 'origin_city',                   'their_key' => 'oc', 'transform' => 'title',  'default_value' => ''],
        ['our_key' => 'origin_state',                  'their_key' => 'os', 'transform' => 'upper',  'default_value' => ''],
        ['our_key' => 'origin_postal_code',            'their_key' => 'oz', 'transform' => 'digits', 'default_value' => ''],

        // ───────── To ─────────
        ['our_key' => 'destination_city',              'their_key' => 'dc', 'transform' => 'title',  'default_value' => ''],
        ['our_key' => 'destination_state',             'their_key' => 'ds', 'transform' => 'upper',  'default_value' => ''],
        ['our_key' => 'destination_postal_code',       'their_key' => 'dz', 'transform' => 'digits', 'default_value' => ''],

        // ───────── Auth ─────────
        ['our_key' => 'AuthKey',                       'their_key' => 'AuthKey', 'transform' => 'none', 'default_value' => ''],
    ];
}