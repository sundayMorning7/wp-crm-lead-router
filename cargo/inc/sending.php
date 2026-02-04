<?php


add_filter('cron_schedules', 'md_my_intervals');

function md_my_intervals($intervals)
{
    $intervals['every_minute'] = array(
        'interval' => 65,
        'display' => 'Every 1 minute + 5 seconds',
    );

    $intervals['every30sec'] = array(
        'interval' => 30,
        'display' => 'Every 30 second'
    );
    $intervals['every20sec'] = array(
        'interval' => 20,
        'display' => 'Every 20 second'
    );
    $intervals['every10sec'] = array(
        'interval' => 10,
        'display' => 'Every 10 second'
    );


    return $intervals;
}


add_action('wp_ajax_md_admin_ajax_send_lead', 'md_admin_ajax_send_lead');
function md_admin_ajax_send_lead()
{

    if (!wp_verify_nonce($_POST['nonce'], 'ship_ajax')) {
        wp_die('Проверка не пройдена!');
    }

    date_default_timezone_set('US/Eastern');

    $json = array();

    $post_id = intval($_POST['post_id']);

    $post_status = get_field('crm_status', $post_id);

    if ($post_status != 1) {

        $server_output = md_send_lead($post_id);
        $answer_array = json_decode($server_output, true);
        $crm_answer = print_r($answer_array, true);
        update_field('crm_answer', $crm_answer, $post_id);
        update_field('broker_sent_id', 1, $post_id);

        if ($answer_array['status'] == 200) {
            update_field('crm_status', 1, $post_id);
            update_field('crm_attempts', 1, $post_id);
            update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);

            update_field('crm_who_sent', 2, $post_id);
            $json['status'] = 'success';
        } else {
            $json['status'] = 'error';
            $json['error'] = $answer_array;
            $json['error_server'] = $server_output;
        }
    } else {
        $json['status'] = 'error';
        $json['text'] = 'The lead must have been sent automatically. The page will refresh now or do it yourself.';
    }


    wp_send_json($json);
    wp_die();


}



add_action('wp_ajax_md_admin_ajax_send_lead_pride', 'md_admin_ajax_send_lead_pride');
function md_admin_ajax_send_lead_pride()
{

    if (!wp_verify_nonce($_POST['nonce'], 'ship_ajax')) {
        wp_die('Проверка не пройдена!');
    }

    date_default_timezone_set('US/Eastern');

    $json = array();

    $post_id = intval($_POST['post_id']);

    $post_status = get_field('crm_status', $post_id);

    if ($post_status != 1) {



        $server_output = md_send_lead_pride($post_id);
        $answer_array = json_decode($server_output, true) ?? $server_output;
        $crm_answer = print_r($answer_array, true);
        update_field('crm_answer', $crm_answer, $post_id);
        update_field('broker_sent_id', 99, $post_id);


        if (strpos($server_output, 'OK, Lead') !== false || $answer_array['statusCode'] == 200) {
            update_field('crm_status', 1, $post_id);
            update_field('crm_attempts', 1, $post_id);
            update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);

            update_field('crm_who_sent', 2, $post_id);
            $json['status'] = 'success';


        } else {
            $json['status'] = 'error';
            $json['error'] = $answer_array;
            $json['error_server'] = $server_output;
        }
    } else {
        $json['status'] = 'error';
        $json['text'] = 'The lead must have been sent automatically. The page will refresh now or do it yourself.';
    }


    wp_send_json($json);
    wp_die();


}


function md_send_lead($post_id)
{


    $name = explode(' ', get_field('name', $post_id));

    $data = array(
        'ip' => $_SERVER['SERVER_ADDR'],
        'src' => 'pridecar',
        'fn' => $name[0],
        'ln' => $name[1],
        'em' => get_field('email', $post_id),
        'ph' => get_field('phone', $post_id),
        'ps' => str_replace('-', '/', get_field('date', $post_id)),

        'ty' => get_field('bodytype', $post_id),
        'yr' => get_field('year', $post_id),
        'ma' => get_field('brand', $post_id),
        'mo' => get_field('model', $post_id),


        'rc' => get_field('condition', $post_id),
        'tc' => 'Open',

        // from
        'oc' => get_field('md_oc', $post_id), /// city
        'os' => get_field('md_os', $post_id), ///
        'oz' => get_field('md_oz', $post_id), /// zip
        // to
        'dc' => get_field('md_dc', $post_id), // city
        'ds' => get_field('md_ds', $post_id), //
        'dz' => get_field('md_dz', $post_id), // zip

        'XAPIKEY' => 'hVwq@9pZJcY6.eG*L2mN!tQ5B8sKdX1'
    );


    $url = 'http://157.230.58.117/api/apiquote?' . http_build_query($data);


    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $server_output = curl_exec($ch);
    curl_close($ch);


    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    if (stripos($content_type, 'text/html') !== false) {


        $hash = md5(uniqid('', true));

        $upload = wp_upload_dir();

        file_put_contents($upload['basedir'] . '/logs/html/lead_error_' . $post_id . '_' . $hash . '.html', $server_output);


        $server_output = json_encode(array(
            'status' => 500,
            'fileerror' => $upload['baseurl'] . '/logs/html/lead_error_' . $post_id . '_' . $hash . '.html',
        ));
    }


    return $server_output;

}


function md_send_lead_pride($post_id)
{



    $vehicle_inop = get_field('condition', $post_id);
    $vehicle_inop = ($vehicle_inop == 'Running') ? '0' : '1';


    $name = explode(' ', get_field('name', $post_id));

    $data = array(
        'first_name' => $name[0],
        'last_name' => $name[1],
        'email' => get_field('email', $post_id),
        'phone' => get_field('phone', $post_id),
        'ship_date' => str_replace('-', '/', get_field('date', $post_id)),
        'comment_from_shipper' => '',
        'transport_type' => '1',
        'Vehicles' => array(
            array(
                'vehicle_type' => '',
                'vehicle_model_year' => get_field('year', $post_id),
                'vehicle_make' => get_field('brand', $post_id),
                'vehicle_model' => get_field('model', $post_id),
                'vehicle_inop' => $vehicle_inop,
            )
        ),

        // from
        'origin_country' => 'USA',
        'origin_city' => get_field('md_oc', $post_id), /// city
        'origin_state' => get_field('md_os', $post_id), ///
        'origin_postal_code' => get_field('md_oz', $post_id), /// zip
        // to
        'destination_country' => 'USA',
        'destination_city' => get_field('md_dc', $post_id), // city
        'destination_state' => get_field('md_ds', $post_id), //
        'destination_postal_code' => get_field('md_dz', $post_id), // zip

        'AuthKey' => '0d5f5969-a747-4980-8851-8b67a2692d65',



    );


    //$url = 'https://api.batscrm.com/leads-sandbox/sandbox?' . http_build_query($data);
    $url = 'https://api.batscrm.com/leads?' . http_build_query($data);



    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $server_output = curl_exec($ch);
    curl_close($ch);


    $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);




    if (stripos($content_type, 'text/html') !== false) {


        $hash = md5(uniqid('', true));

        $upload = wp_upload_dir();

        file_put_contents($upload['basedir'] . '/logs/html/lead_error_' . $post_id . '_' . $hash . '.html', $server_output);


        $server_output = json_encode(array(
            'status' => 500,
            'fileerror' => $upload['baseurl'] . '/logs/html/lead_error_' . $post_id . '_' . $hash . '.html',
        ));
    }


    return $server_output;

}

function md_check_day_limits()
{

    // 1. Таймзона EST/EDT
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
            'relation' => 'AND',
            array(
                'key' => 'crm_status',
                'value' => '1' // SENT
            ),
            array(
                'key' => 'broker_sent_id',
                'value' => array(1) // CG
            ),

        ),

    ];

    $leads = get_posts($args);
    $sent_lead_count = 0;
    foreach ($leads as $lead) {


        $sent_est_time = get_post_meta($lead->ID, 'crm_est_time', true);
        $sent_timestamp = strtotime($sent_est_time);

        if ($start->format('U') < $sent_timestamp && $end->format('U') > $sent_timestamp) {
            $sent_lead_count ++;
            //echo get_the_title($lead->ID) .'<br/>';
        }


    }

    // $max_lead_mon_sat = get_field('max_lead_mon_sat' ,'option');


    $max_lead_mon = get_field('max_lead_mon' ,'option');
    $max_lead_tue = get_field('max_lead_tue' ,'option');
    $max_lead_wed = get_field('max_lead_wed' ,'option');
    $max_lead_thur = get_field('max_lead_thur' ,'option');
    $max_lead_fri = get_field('max_lead_fri' ,'option');
    $max_lead_sut = get_field('max_lead_sut' ,'option');
    $max_lead_sun = get_field('max_lead_sun' ,'option');



    $limits = array(
        1 => $max_lead_mon,
        2 => $max_lead_tue,
        3 => $max_lead_wed,
        4 => $max_lead_thur,
        5 => $max_lead_fri,
        6 => $max_lead_sut,
        7 => $max_lead_sun
    );





    if ($sent_lead_count < $limits[$current_day]) {
        return true;
    }

    return false;



}


function md_check_send_time()
{


    $start_time = get_field('time_start_sending', 'options');
    $end_time = get_field('time_end_sending', 'options');


    date_default_timezone_set('US/Eastern');

    $unix_current_time = time();
    $unix_start_time_today = strtotime(date('Y-m-d') . " " . $start_time); // next day 7:00 am
    $unix_start_time_next_day = strtotime(date('Y-m-d') . " " . $start_time . "+1 days"); // next day 7:00 am
    $unix_end_time_today = strtotime(date('Y-m-d') . " " . $end_time); // today


    if (($unix_current_time > $unix_start_time_today && $unix_current_time < $unix_end_time_today)) {
        return true;
    }

    return false;
}


if (!wp_next_scheduled('md_send_cron_leads_hook')) {


    wp_schedule_event(time(), 'every_minute', 'md_send_cron_leads_hook');


}

add_action('md_send_cron_leads_hook', 'md_cron_send_leads', 10, 3);

function md_cron_send_leads()
{

    if (md_check_send_time() ) {


            $pause_in_sec_min = get_field('pause_between_sends', 'options');
            $pause_in_sec_max = get_field('pause_between_sends_max', 'options');
            $latest_execution_time = get_field('latest_execution_time', 'options');
            $sending_leads_count = get_field('sending_leads_count', 'options');


            $unix_current_time = time();


            if ($latest_execution_time + rand($pause_in_sec_min, $pause_in_sec_max) < $unix_current_time) {


                $args = array(
                    'posts_per_page' => 1,
                    'post_status' => 'private',
                    'post_type' => 'lead',
                    'orderby' => 'date',
                    'order' => 'ASC',
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => 'crm_status',
                            'value' => '2'
                        ),
                        array(
                            'key' => 'crm_attempts',
                            'value' => '2',
                            'compare' => '<'
                        ),
                    ),
                );

                $q = new WP_Query($args);


                if ($q->have_posts()) :
                    while ($q->have_posts()) : $q->the_post();

                        $post_id = $q->post->ID;


                        if (md_check_day_limits() && !in_array(get_field('md_os', $post_id), array('AK','HI')) && !in_array(get_field('md_ds', $post_id) ,array('AK','HI'))) {

                            $server_output = md_send_lead($post_id);
                            $answer_array = json_decode($server_output, true);
                            $crm_answer = print_r($answer_array, true);
                            update_field('crm_answer', $crm_answer, $post_id);
                            update_field('broker_sent_id', 1, $post_id);

                            if ($answer_array['status'] == 200) {
                                update_field('crm_status', 1, $post_id);
                                update_field('crm_attempts', 1, $post_id);
                                update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);
                                update_field('crm_est_time_answer_crm', $answer_array['data']['RequestedOn'], $post_id);
                                update_field('latest_execution_time', time(), 'options');
                                update_field('crm_who_sent', 1, $post_id);


                            } else {

                                // повторне відправлення


                                $server_output = md_send_lead($post_id);
                                $answer_array = json_decode($server_output, true);
                                $crm_answer = print_r($answer_array, true);

                                $crm_old_answer = get_field('crm_answer', $post_id);

                                update_field('crm_answer', $crm_old_answer . '/n -------- SECOND ATTEMPT -------- /n' . $crm_answer, $post_id);
                                update_field('crm_attempts', 2, $post_id);

                                if ($answer_array['status'] == 200) {
                                    update_field('crm_status', 1, $post_id);
                                    update_field('latest_execution_time', time(), 'options');

                                    update_field('crm_who_sent', 1, $post_id);

                                    update_field('crm_est_time_answer_crm', $answer_array['data']['RequestedOn'], $post_id);
                                } else {
                                    update_field('crm_status', 0, $post_id);
                                }


                                file_put_contents('answer_cron.txt', $server_output, FILE_APPEND | LOCK_EX);

                                update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);
                            }

                            $text = $post_id . " Спрацювало о :" . date('m/d/Y h:i:s a', time()) . " Last time : " . date('m/d/Y h:i:s a', $latest_execution_time) . " \n";
                            file_put_contents('cron.txt', $text, FILE_APPEND | LOCK_EX);

                        } else {

                            update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);
                            update_field('crm_status', 3, $post_id);

                            $text = $post_id . "DAY OVERLIMIT о :" . date('m/d/Y h:i:s a', time()) . " Last time : " . date('m/d/Y h:i:s a', $latest_execution_time) . " \n";
                            file_put_contents('cron.txt', $text, FILE_APPEND | LOCK_EX);
                        }






                    endwhile;
                else :

                    $text = "БЕЗ відправки о :" . date('m/d/Y h:i:s a', time()) . " Last time : " . date('m/d/Y h:i:s a', $latest_execution_time) . " \n";
                    file_put_contents('cron.txt', $text, FILE_APPEND | LOCK_EX);
                endif;


            } else {

                $text = "ЛІМІТ о :" . date('m/d/Y h:i:s a', time()) . " Last time : " . date('m/d/Y h:i:s a', $latest_execution_time) . " \n";
                file_put_contents('cron.txt', $text, FILE_APPEND | LOCK_EX);
            }


            wp_reset_postdata();

    }

}