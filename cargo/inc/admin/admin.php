<?php
function admin_add_datepicker($hook)
{
    if ('edit.php' !== $hook) {
        return;
    }


    wp_enqueue_style('md_datepicker_css', get_template_directory_uri() . '/assets/js/admin/datepicker/jquery-ui.min.css');
    wp_enqueue_style('md_datepicker_theme', get_template_directory_uri() . '/assets/js/admin/datepicker/jquery-ui.theme.min.css');
    wp_enqueue_style('md_admin_css', get_template_directory_uri() . '/assets/css/md_admin.css');

    wp_enqueue_script('md_datepicker_lib', get_template_directory_uri() . '/assets/js/admin/datepicker/jquery-ui.min.js');
    wp_enqueue_script('md_excellentexport', get_template_directory_uri() . '/assets/js/admin/excellentexport.js');
    wp_enqueue_script('md_admin_js', get_template_directory_uri() . '/assets/js/admin/admin.js');


    wp_localize_script(
        'md_admin_js',
        'md_admin_js',
        array(
            'url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ship_ajax'),
        )
    );


}


add_action('admin_enqueue_scripts', 'admin_add_datepicker');


add_action('restrict_manage_posts', function () {

    $date_from = isset($_GET['md-date-from']) && $_GET['md-date-from'] ? $_GET['md-date-from'] : '';
    $date_to = isset($_GET['md-date-to']) && $_GET['md-date-to'] ? $_GET['md-date-to'] : '';
    $status_filter = isset($_GET['md-status-filter']) && $_GET['md-status-filter'] || $_GET['md-status-filter'] === '0' ? $_GET['md-status-filter'] : '';


    ?>
    <select name="md-status-filter">
        <option>Choose status</option>

        <?php
        $field = get_field_object('field_682643c18a627', false, true);

        foreach ($field['choices'] as $key => $value) {

            echo '<option ' . (($status_filter == $key) ? 'selected="selected"' : '') . ' value="' . $key . '">' . $value . '</option>';
        }
        ?>

        <option value="all">All</option>
    </select>

    <input type="text" name="md-date-from" placeholder="Date From" value="<?php echo esc_attr($date_from) ?>" size="15"
           autocomplete="new-password"/>
    <input type="text" name="md-date-to" placeholder="Date To" value="<?php echo esc_attr($date_to) ?>" size="15"
           autocomplete="new-password"/>

    <?php

});

add_filter('months_dropdown_results', '__return_empty_array');


add_action('pre_get_posts', 'custom_orderby_leads_admin');
function custom_orderby_leads_admin($query)
{

    if (is_admin() && $query->is_main_query()) {

        global $pagenow;
        if ($pagenow == 'edit.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'lead' && !isset($_GET['orderby'])) {


            $query->set('orderby', 'date');
            $query->set('order', 'DESC');


            if (!empty($_GET['md-date-from']) || !empty($_GET['md-date-to'])) {

                $date_query = array(
                    'inclusive' => true, // include selected days as well
                    'column' => 'post_date',
                );
                $date_query['after'] = $_GET['md-date-from'];
                $date_query['before'] = $_GET['md-date-to'];

                $query->set('date_query', $date_query);
            }


            if (!empty($_GET['md-status-filter']) || $_GET['md-status-filter'] === '0') {


                $meta_query = $query->query_vars['meta_query'];

                $meta_query[] = array(
                    array(
                        'key' => 'crm_status',
                        'value' => (int)$_GET['md-status-filter']
                    ),

                );
                $query->set('meta_query', $meta_query);


            }

        }


    }
}


add_filter('manage_' . 'lead' . '_posts_columns', 'add_views_column', 4);
function add_views_column($columns)
{
    $num = 2; // после какой по счету колонки вставлять новые

    $new_columns = [
        'md_email' => 'Email',
        'md_phone' => 'Phone',
        'md_date' => 'Est. Ship Date',
        'md_car' => 'Car Info',
        'md_send_status' => 'Send Status',
        'md_crm_est_time' => 'EST try time',
        'md_admin_info' => 'Broker/Who sent',
    ];

    return array_slice($columns, 0, $num) + $new_columns + array_slice($columns, $num);
}


add_action('manage_' . 'lead' . '_posts_custom_column', 'fill_views_column', 5, 2);
function fill_views_column($colname, $post_id)
{
    if ($colname === 'md_email') {

        echo get_field('email', $post_id);
    }

    if ($colname === 'md_phone') {
        echo get_field('phone', $post_id);
    }

    if ($colname === 'md_date') {
        echo get_field('date', $post_id);
    }

    if ($colname === 'md_car') {
        echo get_field('brand', $post_id) . ' ';
        echo get_field('model', $post_id) . ' ';
        echo get_field('year', $post_id);
    }

    if ($colname === 'md_crm_est_time') {
        echo get_field('crm_est_time', $post_id);
    }


    if ($colname === 'md_send_status') {

        echo '<div class="md_flex">';
        $crm_status = get_field('crm_status', $post_id);

        // FOR OLD POSTS
        if ($post_id < 886) {
            $crm_status = 1;
        }

        switch ($crm_status) {
            case 0:
                echo '<i class="md_check_error"><span>Error</span></i><button class="js-sent-lead button-3" data-post-id="' . $post_id . '">Re-send to GC</button><button class="js-sent-lead-to-pride button-3 mod-color1" data-post-id="' . $post_id . '">Send to PRIDE</button>';
                break;
            case 1:
                echo '<i class="md_check_success"><span>Success</span>';
                break;
            case 2:
                echo '<i class="md_check_await"><span>Await</span></i><button class="js-sent-lead button-3" data-post-id="' . $post_id . '">Send to GC</button>';
                break;
            case 3:
                echo '<i class="md_check_overlimit"><span>Overlimit</span></i><button class="js-sent-lead button-3" data-post-id="' . $post_id . '">Send to GC</button><button class="js-sent-lead-to-pride button-3 mod-color1" data-post-id="' . $post_id . '">Send to PRIDE</button>';
                break;
        }

        echo '</div>';
    }

    if ($colname === 'md_admin_info') {
        echo get_field('broker_sent_id', $post_id) . '<br/>';
        echo get_field('crm_who_sent', $post_id) . ' ';

        echo((in_array(get_field('md_os', $post_id), array('AK', 'HI')) || in_array(get_field('md_ds', $post_id), array('AK', 'HI'))) ? '<br/><b>Alaska or Hawaiian</b>' : '');
    }


}


add_action('all_admin_notices', 'custom_block_before_post_list');
function custom_block_before_post_list()
{
    global $pagenow;


    if ($pagenow === 'edit.php' && $_GET['post_type'] === 'lead') {

        md_the_count_title_est_today_sent_leads();
        ?>


        <div class="md-report-panel notice notice-info"
             style="display: none; padding: 10px 15px; margin-bottom: 20px;">
            <div class="md-report-panel_head">
                <h2>Report panel</h2>
                <div class="md-report-panel_head_range">
                    <label><b>From:</b></label>
                    <input readonly name="md_range_from" type="text" id="md_range_from" size="10">
                    <label><b>To:</b></label>
                    <input readonly name="md_range_to" type="text" id="md_range_to" size="10">
                </div>
            </div>

            <div class="md_flex">
                <div class="md_range_datepicker"></div>
            </div>

            <div class="md-report-panel_bottom">


                <button class="button js-create-aggregate-report">Create aggregate report</button>
                <select class="select js-choose-broker-id">
                    <option value="">Select broker</option>
                    <?php
                    $field = get_field_object('field_67f4185b31526', false, true);
                    foreach ($field['choices'] as $key => $value) {
                        echo '<option value="' . $key . '">' . $value . '</option>';
                    }
                    ?>
                    <option value="all">All</option>
                </select>
                <button class="button js-create-aggregate-invoice">Create invoice</button>
            </div>

            <div class="md-report-panel_result">

            </div>

        </div>
    <?php }
}


add_action('wp_ajax_md_ajax_create_report', 'md_ajax_create_report');


function md_ajax_create_report()
{

    if (!wp_verify_nonce($_POST['nonce'], 'ship_ajax')) {
        wp_die('Проверка не пройдена!');
    }

    if (!$_POST['from_date']) {
        wp_die('Невказана дата початку');
    }


    error_reporting(E_ALL);
    ini_set('display_errors', 1);


    $tz = new DateTimeZone('America/New_York');


    $from_date_est = DateTime::createFromFormat('m-d-Y H:i:s', $_POST['from_date'] . ' 00:00:00', $tz);
    $from_date_est_timestamp = $from_date_est->getTimestamp();
    $from_filename = $from_date_est->format('Y-m-d');


    $txt_day = 2;

    // Запас для WP QUERY
    $from_date_est->modify('-' . $txt_day . ' day'); // '-1 day'
    $from_str = $from_date_est->format('Y-m-d H:i:s');

    // Якщо порожня змінна to_date
    $from_date_est->modify('+' . ($txt_day + 1) . ' day'); // '+2 day'
    $to_date_est_timestamp = $from_date_est->getTimestamp();
    $from_date_est->modify('+1 day'); // '+1 day'
    $to_str = $from_date_est->format('Y-m-d H:i:s');


    if ($_POST['to_date'] && $_POST['to_date'] != $_POST['from_date']) {
        $to_date_est = DateTime::createFromFormat('m-d-Y H:i:s', $_POST['to_date'] . ' 00:00:00', $tz);
        $to_filename = $to_date_est->format('Y-m-d');
        $to_date_est->setTime(0, 0, 0);
        // Початок наступної доби
        $to_date_est->modify('+1 day'); // '+1 day'
        $to_date_est_timestamp = $to_date_est->getTimestamp();

        // Запас для WP QUERY
        $to_date_est->modify('+2 day'); // '+2 day'
        $to_str = $to_date_est->format('Y-m-d H:i:s');
    }

    $broker_id = $_POST['broker_id'];


    /*

        var_dump($from_str);
        var_dump($from_date_est_timestamp);


        var_dump($to_str);
        var_dump($to_date_est_timestamp);*/


    $args = [
        'date_query' => [
            [
                'after' => $from_str,
                'before' => $to_str,
                'inclusive' => true,
                'column' => 'post_date_gmt'
            ],
        ],
        'posts_per_page' => -1,
        'post_status' => 'private',
        'post_type' => 'lead',
        'order' => 'ASC',
        /**/

    ];


    if ($_POST['type_report'] == 'invoice' && !empty($_POST['broker_id']) && $_POST['broker_id'] != 'all') {


        $args['meta_query'] = array(
            array(
                'key' => 'broker_sent_id',
                'value' => (int)$_POST['broker_id']
            ),
        );

    }


    $leads = get_posts($args);

    if ($leads) {


        $leads_table = md_get_leads_table($leads, $from_date_est_timestamp, $to_date_est_timestamp);

        if ($_POST['type_report'] == 'report') {
            $excel_table = md_prepare_for_report($leads_table);
            $filename = 'leads_summary_' . $from_filename . (isset($to_filename) ? '_to_' . $to_filename : '') . '_EST';
        } else {

            $mon_sut_rate = (float)$_POST['mon_sut_rate'];
            $sun_rate = (float)$_POST['sun_rate'];

            $excel_table = md_prepare_for_invoice($leads_table, $mon_sut_rate, $sun_rate);


            $broker_field = get_field_object('field_67f4185b31526', false, true);

            $filename = 'invoice_' . $broker_field['choices'][(int)$_POST['broker_id']] . '_' . $from_filename . (isset($to_filename) ? '_to_' . $to_filename : '') . '_EST';
        }


        $xlsx_link = md_generate_xlsx_link($excel_table, $filename . '.xlsx');
        $csv_link = md_generate_csv_link($excel_table, $filename . '.csv');


        $json['file_url_xlsx'] = $xlsx_link;
        $json['file_url_csv'] = $csv_link;
        $json['excel_table'] = $excel_table;
        $json['stockdata'] = $leads_table;


    } else {
        $json['error'] = 'No data found with these parameters. Try choosing different dates or different brokers.';

    }

    wp_send_json($json);
    wp_die();


}


function md_get_leads_table($leads, $from_date_est_timestamp, $to_date_est_timestamp)
{
    $leads_table = array();


    //var_dump($from_date_est_timestamp);
    // var_dump($to_date_est_timestamp);

    $count = 0;
    foreach ($leads as $lead) {


        $sent_est_time = get_post_meta($lead->ID, 'crm_est_time', true);

        // TODO new field in crm response may add time accuracy
        /*
                echo ++$count;
                echo get_the_title($lead->ID) . '<br/>';*/

        if ($sent_est_time) {


            $tz = new DateTimeZone('America/New_York');
            $date_est_sent = DateTime::createFromFormat('m/d/Y h:i:s a', $sent_est_time, $tz);
            $date_est_sent_timestamp = $date_est_sent->getTimestamp();


            if ($from_date_est_timestamp < $date_est_sent_timestamp && $to_date_est_timestamp > $date_est_sent_timestamp) {


                $date_index = $date_est_sent->format('Y-m-d');


                if (!isset($leads_table[$date_index])) {
                    $leads_table[$date_index] = array(
                        'day' => $date_est_sent->format('N'),
                        'rate' => '',
                        'total' => array(
                            'success' => 0,
                            'error' => 0,
                        ),
                        'titles' => array(),
                        'broker' => array(
                            '1' => array(
                                'success' => 0,
                                'error' => 0,
                            ),
                            '99' => array(
                                'success' => 0,
                                'error' => 0,
                            ),
                            '0' => array(
                                'success' => 0,
                                'error' => 0,
                            )
                        ),

                    );
                }

                $sent_status = get_post_meta($lead->ID, 'crm_status', true);


                $broker_id = get_post_meta($lead->ID, 'broker_sent_id', true);

                /*
                                if ($broker_id == '99') {
                                     echo get_the_title($lead->ID) . '<br/>';
                                }*/

                $broker_id = !empty($broker_id) ? $broker_id : 0;

                switch ($sent_status) {
                    case 0:
                        $leads_table[$date_index]['total']['error'] += 1;
                        if ($broker_id != '0') {
                            $leads_table[$date_index]['broker'][$broker_id]['error'] += 1;
                        }
                        break;
                    case 1:

                        $leads_table[$date_index]['titles'][$lead->ID] = get_the_title($lead->ID) . ' _ ' . $broker_id;

                        $leads_table[$date_index]['total']['success'] += 1;
                        $leads_table[$date_index]['broker'][$broker_id]['success'] += 1;
                        break;
                }


                //echo '<br/>' . ++$count . ' ' . $sent_est_time . ' ' . $lead->post_title;


            };
        }


        /**/


    }


    uksort($leads_table, function ($a, $b) {
        return strtotime($a) <=> strtotime($b);
    });


    return $leads_table;

}

function md_prepare_for_report($leads_table)
{
    $result = array();

    $excel_head_cols = array(
        0 => 'Date',
        1 => 'Source',
        2 => 'Total Leads',
        3 => 'Success',
        4 => 'Error',
        5 => 'Sent to Glasgow Consulting',
        6 => 'Sent to Pride',
        7 => 'Sent to Other',
    );

    $result[] = $excel_head_cols;

    $total_leads = 0;
    $total_success = 0;
    $total_error = 0;
    $total_sent_gc = 0;
    $total_sent_pride = 0;
    $total_sent_other = 0;

    foreach ($leads_table as $date => $item) {

        $total_leads += $item['total']['success'] + $item['total']['error'];
        $total_success += $item['total']['success'];
        $total_error += $item['total']['error'];

        $total_sent_gc += $item['broker'][1]['success'];
        $total_sent_pride += $item['broker'][99]['success'];
        $total_sent_other += $item['broker'][0]['success'];

        /*$total_sent_gc += $item['broker'][1]['success'] + $item['broker'][1]['error'];
        $total_sent_pride += $item['broker'][99]['success'] + $item['broker'][99]['error'];
        $total_sent_other += $item['broker'][0]['success'] + $item['broker'][0]['error'];*/

        $result[] = [$date, 'Facebook', $item['total']['success'] + $item['total']['error'], $item['total']['success'], $item['total']['error'], $item['broker'][1]['success'] + $item['broker'][1]['error'], $item['broker'][99]['success'] + $item['broker'][99]['error'], $item['broker'][0]['success'] + $item['broker'][0]['error']];;

    }

    $result[] = ['Total:', '', $total_leads, $total_success, $total_error, $total_sent_gc, $total_sent_pride, $total_sent_other];


    return $result;
}


function md_prepare_for_invoice($leads_table, $mon_sut_rate = 17.5, $sun_rate = 13.0)
{
    $result = array();


    $excel_head_cols = array(
        0 => 'Date',
        1 => 'Day',
        2 => 'Leads Sent',
        3 => 'Rate',
        4 => 'Accepted',
        5 => 'Errors',
        6 => 'Gross',
        7 => 'Deduction',
        8 => 'Total Due',
    );


    $total_leads = 0;
    $total_accepted = 0;
    $total_error = 0;
    $total_gross = 0;
    $total_deduction = 0;
    $total_due_global = 0;

    $result[] = $excel_head_cols;

    foreach ($leads_table as $date => $item) {

        $day_name = date('l', strtotime("Sunday +{$item['day']} days"));
        $total_leads_sent = $item['total']['success'] + $item['total']['error'];

        $rate = ($item['day'] < 7 ? $mon_sut_rate : $sun_rate);
        $gross = round((int)$total_leads_sent * $rate, 2);
        $deduction = round($item['total']['error'] * $rate, 2);
        $total_due = round($gross - $deduction, 2);


        $total_leads += $total_leads_sent;
        $total_accepted += $item['total']['success'];
        $total_error += $item['total']['error'];
        $total_gross += $gross;
        $total_deduction += $deduction;
        $total_due_global += $total_due;


        $result[] = [
            $date, $day_name, $total_leads_sent, '$' . $rate, $item['total']['success'], $item['total']['error'], '$' . $gross, '$' . $deduction, '$' . $total_due
        ];


    }

    $result[] = ['Total:', '', $total_leads, '', $total_accepted, $total_error, '$' . round($total_gross, 2), '$' . round($total_deduction, 2), '$' . round($total_due_global, 2)];


    return $result;
}


function md_generate_xlsx_link($excel_table, $filename = null)
{


    require_once __DIR__ . '/../libs/SimpleXLSXGen.php';
    require_once __DIR__ . '/../libs/SimpleCSV.php';

    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($excel_table);

    if (!$filename) {
        $filename = 'leads_summary_' . md5(uniqid('', true)) . '.xlsx';
    }

    $upload_dir = wp_get_upload_dir();

    $xlsx->saveAs($upload_dir['basedir'] . '/reports/' . $filename); // or downloadAs('books.xlsx') or $xlsx_content = (string) $xlsx
    return $upload_dir['baseurl'] . '/reports/' . $filename;

}


function md_generate_csv_link($excel_table, $filename = null)
{


    require_once __DIR__ . '/../libs/SimpleCSV.php';

    $csv = Shuchkin\SimpleCSV::export($excel_table);

    if (!$filename) {
        $filename = 'leads_summary_' . md5(uniqid('', true)) . '.csv';
    }

    $upload_dir = wp_get_upload_dir();

    file_put_contents($upload_dir['basedir'] . '/reports/' . $filename, $csv);

    return $upload_dir['baseurl'] . '/reports/' . $filename;

}


function md_the_count_title_est_today_sent_leads()
{
    $tz = new DateTimeZone('America/New_York');

// 2. Початок і кінець поточного дня в EST/EDT
    $now = new DateTime('now', $tz);
    $start = new DateTime('today', $tz);
    $end = new DateTime('tomorrow', $tz);
    $two_days_ago = new DateTime('-2 day', $tz);


    $current_day = $now->format('N');

// 3. Переводимо їх у UTC
    $start_utc = clone $start;
    $end_utc = clone $end;
    $two_days_ago_utc = clone $two_days_ago;

    $start_utc->setTimezone(new DateTimeZone('UTC'));
    $end_utc->setTimezone(new DateTimeZone('UTC'));
    $two_days_ago_utc->setTimezone(new DateTimeZone('UTC'));

// 4. Формати для порівняння
    $start_str = $start_utc->format('Y-m-d H:i:s');
    $end_str = $end_utc->format('Y-m-d H:i:s');
    $two_days_ago_str = $two_days_ago_utc->format('Y-m-d H:i:s');

// 5. Запит постів за post_date_gmt у цьому діапазоні
    $args = [
        'date_query' => [
            [
                'after' => $two_days_ago_str,
                'before' => $end_str,
                'inclusive' => true,
                'column' => 'post_date_gmt'
            ],
        ],
        'posts_per_page' => -1,
        'post_status' => 'private',
        'post_type' => 'lead',
        'meta_query' => array(
            array(
                'key' => 'crm_status',
                'value' => array(1, 3) // SENT && OVER
            ),
            array(
                'key' => 'broker_sent_id',
                'value' => array(1) // SENT && OVER
            )
        ),

    ];

    $leads = get_posts($args);
    $sent_lead_count = 0;
    foreach ($leads as $lead) {


        $sent_est_time = get_post_meta($lead->ID, 'crm_est_time', true);
        $sent_timestamp = strtotime($sent_est_time);

        if ($start->format('U') < $sent_timestamp && $end->format('U') > $sent_timestamp) {
            $sent_lead_count++;
            //echo get_the_title($lead->ID) .'<br/>';
        }


    }

    // $max_lead_mon_sat = get_field('max_lead_mon_sat', 'option');

    $max_lead_mon = get_field('max_lead_mon', 'option');
    $max_lead_tue = get_field('max_lead_tue', 'option');
    $max_lead_wed = get_field('max_lead_wed', 'option');
    $max_lead_thur = get_field('max_lead_thur', 'option');
    $max_lead_fri = get_field('max_lead_fri', 'option');
    $max_lead_sut = get_field('max_lead_sut', 'option');
    $max_lead_sun = get_field('max_lead_sun', 'option');


    $limits = array(
        1 => $max_lead_mon,
        2 => $max_lead_tue,
        3 => $max_lead_wed,
        4 => $max_lead_thur,
        5 => $max_lead_fri,
        6 => $max_lead_sut,
        7 => $max_lead_sun
    );

    $class = 'mod-error';
    if ($sent_lead_count <= $limits[$current_day]) {
        $class = 'mod-green';
    }


    $args = [
        'posts_per_page' => -1,
        'post_status' => 'private',
        'post_type' => 'lead',
        'meta_query' => array(
            array(
                'key' => 'crm_status',
                'value' => array(3) // OVER
            ),
        ),
    ];

    $overlimit_leads = get_posts($args);
    $overlimit_leads_count = count($overlimit_leads);


    $args = [
        'posts_per_page' => -1,
        'post_status' => 'private',
        'post_type' => 'lead',
        'meta_query' => array(
            array(
                'key' => 'crm_status',
                'value' => array(0) // ERROR
            ),
        ),
    ];

    $error_leads = get_posts($args);
    $error_leads_count = count($error_leads);


    $args = [
        'posts_per_page' => -1,
        'post_status' => 'private',
        'post_type' => 'lead',
        'meta_query' => array(
            array(
                'key' => 'crm_status',
                'value' => array(2) // AWAIT
            ),
        ),
    ];

    $await_leads = get_posts($args);
    $await_leads_count = count($await_leads);

    $md_bulk_total = '';
    if (isset($_GET['md_bulk_total'])) {
        $md_bulk_total .= 'Processed <b style="color:#00028a;">' . $_GET['md_bulk_total'] . '</b>';
    }

    if (isset($_GET['md_bulk_success'])) {
        $md_bulk_total .= ' Successful <b style="color:#0c7501;">' . $_GET['md_bulk_total'] . '</b>';
    }


    echo '<div style="display:none;" class="count_title_est_today_sent">EST Today sent <b class="' . $class . '">' . $sent_lead_count . '</b>/' . $limits[$current_day] . '</div>';
    echo '<div style="display:none;" class="count_title_buy_status">Overlimit: <b style="color: #75021d">' . $overlimit_leads_count . '</b> Await: <b style="color: #ceb300">' . $await_leads_count . '</b> Error: <b style="color: #bb0000">' . $error_leads_count . '</b>&nbsp;&nbsp;&nbsp;&nbsp; ' . $md_bulk_total . '</div>';


}

add_filter('bulk_actions-edit-lead', function ($bulk_actions) {
    $bulk_actions['md_change_status_await'] = __('Change status to Await', 'txtdomain');
    //$bulk_actions['md_send_to_cg_bulk'] = __('Send leads to CG', 'txtdomain');
    $bulk_actions['md_send_to_pride_bulk'] = __('Send leads to PRIDE', 'txtdomain');
    return $bulk_actions;
});


add_filter('handle_bulk_actions-edit-lead', function ($redirect_url, $action, $post_ids) {


    if ($action == 'md_change_status_await') {

        foreach ($post_ids as $post_id) {
            update_field('crm_status', 2, $post_id);
            update_field('crm_attempts', 0, $post_id);


        }

        $redirect_url = add_query_arg('md_bulk_total', count($post_ids), $redirect_url);
    }


    if ($action == 'md_send_to_cg_bulk') {
        foreach ($post_ids as $post_id) {
            /*
                        $server_output = md_send_lead($post_id);
                        $answer_array = json_decode($server_output, true);
                        $crm_answer = print_r($answer_array, true);
                        update_field('crm_answer', $crm_answer, $post_id);
                        update_field('broker_sent_id', 1, $post_id);

                        if ($answer_array['status'] == 200) {
                            update_field('crm_status', 1, $post_id);
                            update_field('crm_attempts', 1, $post_id);
                            update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);

                            $json['status'] = 'success';
                        } else {
                            $json['status'] = 'error';
                            $json['error'] = $answer_array;
                            $json['error_server'] = $server_output;
                        }
            */
        }

        //$redirect_url = add_query_arg('md_bulk_total', count($post_ids), $redirect_url);
        //$redirect_url = add_query_arg('md_bulk_success', $success_count, $redirect_url);


    }


    if ($action == 'md_send_to_pride_bulk') {

        $success_count = 0;
        foreach ($post_ids as $post_id) {

            $server_output = md_send_lead_pride($post_id);
            $answer_array = json_decode($server_output, true) ?? $server_output;
            $crm_answer = print_r($answer_array, true);
            // update_field('crm_answer', $crm_answer, $post_id);
            // update_field('broker_sent_id', 99, $post_id);


            if (strpos($server_output, 'OK, Lead') !== false || $answer_array['statusCode'] == 200) {
                //update_field('crm_status', 1, $post_id);
                //update_field('crm_attempts', 1, $post_id);
                //update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);

                $success_count++;
            }

        }

        $redirect_url = add_query_arg('md_bulk_total', count($post_ids), $redirect_url);
        $redirect_url = add_query_arg('md_bulk_success', $success_count, $redirect_url);
    }


    return $redirect_url;
}, 10, 3);


