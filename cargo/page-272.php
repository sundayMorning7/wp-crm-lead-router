<?php

if (!isset($_COOKIE["md_oc"]) || !isset($_COOKIE["md_dc"]) || !isset($_COOKIE["model"]) || !isset($_COOKIE["brand"]) || !isset($_COOKIE["year"])) {
    wp_redirect(get_permalink(13));
    exit();
}


if (isset($_POST['md_name']) && isset($_POST['md_email'])) {


    $name = mb_convert_encoding($_POST['md_name'], 'UTF-8', 'UTF-8');
    $name = htmlentities($name, ENT_QUOTES, 'UTF-8');

    $email = mb_convert_encoding($_POST['md_email'], 'UTF-8', 'UTF-8');
    $email = htmlentities($email, ENT_QUOTES, 'UTF-8');


    $phone = mb_convert_encoding($_POST['md_phone'], 'UTF-8', 'UTF-8');
    $phone = htmlentities($phone, ENT_QUOTES, 'UTF-8');
    //$phone = '+1 ' . $phone;

    $phone = str_replace('+1 ', '', $phone);
    $phone = str_replace(') ', '-', $phone);
    $phone = str_replace('(', '', $phone);


    $date = mb_convert_encoding($_POST['md_date'], 'UTF-8', 'UTF-8');
    $date = htmlentities($date, ENT_QUOTES, 'UTF-8');

    if (empty($date)) {
        $date = date("m-d-Y");
    }


    $title = $name; //. ' | ' . $email . ' | ' . $phone . ' | ' . $date;

    $place_from = $_COOKIE['md_oc'] . ', ' . $_COOKIE['md_os'] . ', ' . $_COOKIE['md_oz'];
    $place_to = $_COOKIE['md_dc'] . ', ' . $_COOKIE['md_ds'] . ', ' . $_COOKIE['md_dz'];


    $content = '<table><tr><td>Name:</td><td>' . $name . '</td></tr>';
    $content .= '<tr><td>Email:</td><td>' . $email . '</td></tr>';
    $content .= '<tr><td>Phone:</td><td>' . $phone . '</td></tr>';
    $content .= '<tr><td>Est. Ship Date:</td><td>' . $date . '</td></tr>';
    $content .= '<tr><td>Year:</td><td>' . $_COOKIE['year'] . '</td></tr>';
    $content .= '<tr><td>Brand:</td><td>' . $_COOKIE['brand'] . '</td></tr>';
    $content .= '<tr><td>Model:</td><td>' . $_COOKIE['model'] . '</td></tr>';
    $content .= '<tr><td>Condition:</td><td>' . $_COOKIE['condition'] . '</td></tr>';
    $content .= '<tr><td>From:</td><td>' . $place_from . '</td></tr>';
    $content .= '<tr><td>To:</td><td>' . $place_to . '</td></tr></table>';


    $post_data = array(
        'post_type' => 'lead',
        'post_title' => sanitize_text_field($title),
        'post_content' => $content,
        'post_status' => 'private',
        'post_author' => 1,
    );

    $post_id = wp_insert_post($post_data);

    setcookie("md_lead_id", $post_id, time() + 60, '/');
    setcookie("md_lead_name", $name, time() + 60, '/');
    setcookie("md_lead_email", $email, time() + 60, '/');
    setcookie("md_lead_phone", $phone, time() + 60, '/');

    update_field('name', $name, $post_id);
    update_field('email', $email, $post_id);
    update_field('phone', $phone, $post_id);
    update_field('date', $date, $post_id);
    update_field('year', htmlentities(mb_convert_encoding($_COOKIE['year'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('brand', htmlentities(mb_convert_encoding($_COOKIE['brand'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('model', htmlentities(mb_convert_encoding($_COOKIE['model'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('bodytype', htmlentities(mb_convert_encoding($_COOKIE['bodytype'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('condition', htmlentities(mb_convert_encoding($_COOKIE['condition'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('from', htmlentities(mb_convert_encoding($place_from, 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('to', htmlentities(mb_convert_encoding($place_to, 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);


    update_field('md_oc', htmlentities(mb_convert_encoding($_COOKIE['md_oc'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('md_os', htmlentities(mb_convert_encoding($_COOKIE['md_os'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('md_oz', htmlentities(mb_convert_encoding($_COOKIE['md_oz'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('md_dc', htmlentities(mb_convert_encoding($_COOKIE['md_dc'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('md_ds', htmlentities(mb_convert_encoding($_COOKIE['md_ds'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);
    update_field('md_dz', htmlentities(mb_convert_encoding($_COOKIE['md_dz'], 'UTF-8', 'UTF-8'), ENT_QUOTES, 'UTF-8'), $post_id);


    $current_user_id = get_current_user_id();
    /***
     *
     * // check sending crm flag
     * if ($current_user_id != 1 && get_field('th_crm_flag', 22)) {
     *
     * // check Alaska and Hawaii
     * if (in_array($_COOKIE['md_os'],array('AK','HI')) || in_array($_COOKIE['md_ds'],array('AK','HI'))) {
     *
     *
     * $server_output = md_send_lead_pride($post_id);
     * $answer_array = json_decode($server_output, true) ?? $server_output;
     * $crm_answer = print_r($answer_array, true);
     * update_field('crm_answer', $crm_answer, $post_id);
     * update_field('broker_sent_id', 99, $post_id);
     *
     *
     * if (strpos($server_output, 'OK, Lead') !== false || $answer_array['statusCode'] == 200) {
     * update_field('crm_status', 1, $post_id);
     * update_field('crm_attempts', 1, $post_id);
     * update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);
     * update_field('crm_who_sent', 0, $post_id);
     *
     * } else {
     * update_field('crm_status', 0, $post_id);
     * }
     *
     *
     *
     * } else {
     *
     * // check send time
     * if (md_check_send_time()) {
     *
     * if (md_check_day_limits()) {
     *
     *
     * $name = explode(' ', str_replace('+', ' ', $name));
     *
     * $bodytype = str_replace('+', ' ', $_COOKIE['bodytype']);
     * if (empty($bodytype)) {
     * $bodytype = 'none';
     * }
     *
     * $data = array(
     * 'ip' => $_SERVER['SERVER_ADDR'],
     * 'src' => 'pridecar',
     * 'fn' => $name[0],
     * 'ln' => $name[1],
     * 'em' => $email,
     * 'ph' => $phone,
     * 'ps' => str_replace('-', '/', $date),
     *
     * 'ty' => $bodytype,
     * 'yr' => str_replace('+', ' ', $_COOKIE['year']),
     * 'ma' => str_replace('+', ' ', $_COOKIE['brand']),
     * 'mo' => str_replace('+', ' ', $_COOKIE['model']),
     *
     *
     * 'rc' => $_COOKIE['condition'],
     * 'tc' => 'Open',
     *
     * // from
     * 'oc' => str_replace('+', ' ', $_COOKIE['md_oc']), /// city
     * 'os' => $_COOKIE['md_os'], ///
     * 'oz' => $_COOKIE['md_oz'], /// zip
     * // to
     * 'dc' => str_replace('+', ' ', $_COOKIE['md_dc']), // city
     * 'ds' => $_COOKIE['md_ds'], //
     * 'dz' => $_COOKIE['md_dz'], // zip
     *
     * 'XAPIKEY' => 'hVwq@9pZJcY6.eG*L2mN!tQ5B8sKdX1'
     * );
     *
     *
     * //$data = array_map(fn($value) => urlencode($value), $data);
     *
     *
     * $url = 'http://157.230.58.117/api/apiquote?' . http_build_query($data);
     *
     *
     * $ch = curl_init();
     * curl_setopt($ch, CURLOPT_URL, $url);
     * curl_setopt($ch, CURLOPT_POST, 1);
     * curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
     * curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
     * $server_output = curl_exec($ch);
     * curl_close($ch);
     *
     *
     * $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
     *
     *
     *
     *
     * if (stripos($content_type, 'text/html') !== false) {
     *
     *
     * $hash = md5(uniqid('', true));
     *
     * $upload = wp_upload_dir();
     *
     * file_put_contents($upload['basedir'] . '/logs/html/lead_error_' . $post_id . '_' . $hash . '.html', $server_output);
     *
     *
     * $server_output = json_encode(array(
     * 'status' => 500,
     * 'fileerror' => $upload['baseurl'] . '/logs/html/lead_error_' . $post_id . '_' . $hash . '.html',
     * ));
     * }
     *
     *
     * $answer_array = json_decode($server_output, true);
     * $crm_answer = print_r($answer_array, true);
     *
     * update_field('crm_answer', $crm_answer, $post_id);
     * update_field('crm_attempts', 1, $post_id);
     * update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);
     *
     * if ($answer_array['status'] == 200) {
     *
     * update_field('crm_est_time_answer_crm', $answer_array['data']['RequestedOn'], $post_id);
     * update_field('crm_who_sent', 0, $post_id);
     *
     * update_field('crm_status', 1, $post_id);
     * } else {
     *
     * file_put_contents('answer_client.txt', $server_output, FILE_APPEND | LOCK_EX);
     * update_field('crm_status', 0, $post_id);
     * }
     *
     * update_field('broker_sent_id', 1, $post_id);
     *
     *
     *
     *
     *
     *
     * } else {
     * // set overlimit
     *
     * update_field('crm_est_time', date('m/d/Y h:i:s a', time()), $post_id);
     * update_field('crm_status', 3, $post_id);
     * }
     *
     * } else {
     * // set await
     *
     * update_field('crm_attempts', 0, $post_id);
     * update_field('crm_status', 2, $post_id);
     * }
     *
     *
     *
     *
     *
     * }
     *
     *
     *
     * }
     **/


    // === LeadRouter: створити лід у нашій таблиці + запустити broadcast ===

// 0) Перевіримо, що клас і методи доступні

    if (!class_exists('LeadRouter_Flow') || !method_exists('LeadRouter_Flow', 'create_lead_simple') || !method_exists('LeadRouter_Flow', 'dispatch_broadcast')) {
        error_log('[LeadRouter] Flow class/methods not available');
    } else {

        // 1) Приведемо дату до Y-m-d (підтримка m-d-Y, m/d/Y, Y-m-d)
        $est_ship_date = null;
        if (!empty($date)) {
            $tz = new DateTimeZone('America/New_York');
            $tryFormats = ['m-d-Y', 'm/d/Y', 'Y-m-d'];
            foreach ($tryFormats as $fmt) {
                $dt = DateTime::createFromFormat($fmt, $date, $tz);
                if ($dt && $dt->format($fmt) === $date) { // точне співпадіння формату
                    $est_ship_date = $dt->format('Y-m-d');
                    break;
                }
            }
            // fallback: якщо не розпарсили — залишимо null
            if (!$est_ship_date) {
                error_log('[LeadRouter] Unable to parse est_ship_date from: ' . $date);
            }
        }

        // 2) Збираємо дані (аккуратно з кукі)
        $cookie = function ($key) {
            return isset($_COOKIE[$key]) ? sanitize_text_field((string) $_COOKIE[$key]) : '';
        };

        $cookie_json = function ($key) use ($cookie) {
            $raw = $cookie($key);
            if ($raw === '') return [];

            // інколи лапки в JSON приходять екрановані
            $raw = stripslashes($raw);

            $arr = json_decode($raw, true);
            return is_array($arr) ? $arr : [];
        };

        $utm_first = $cookie_json('md_utm_first');
        $utm_last  = $cookie_json('md_utm_last');


        $utm_source = $utm_last['utm_source'] ?? ($utm_first['utm_source'] ?? '');
        $utm_medium = $utm_last['utm_medium'] ?? ($utm_first['utm_medium'] ?? '');
        $utm_content = $utm_last['utm_content'] ?? ($utm_first['utm_content'] ?? '');
        $utm_campaign = $utm_last['utm_campaign'] ?? ($utm_first['utm_campaign'] ?? '');
        $utm_term = $utm_last['utm_term'] ?? ($utm_first['utm_term'] ?? '');




        $lead_data = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'est_ship_date' => $est_ship_date,

            'vehicle_bodytype' => $cookie('bodytype'),
            'vehicle_year' => isset($_COOKIE['year']) && is_numeric($_COOKIE['year']) ? (int)$_COOKIE['year'] : null,
            'vehicle_brand' => $cookie('brand'),
            'vehicle_model' => $cookie('model'),
            'vehicle_condition' => $cookie('condition'),

            'from_city' => $cookie('md_oc'),
            'from_state' => $cookie('md_os'),
            'from_zip' => $cookie('md_oz'),

            'to_city' => $cookie('md_dc'),
            'to_state' => $cookie('md_ds'),
            'to_zip' => $cookie('md_dz'),

            'utm_source' => $utm_source,
            'utm_campaign' => $utm_campaign,
            'utm_content' => $utm_content,
            'utm_medium' => $utm_medium,
            'utm_term' => $utm_term,

            'dispatch_method' => 'frontend',

        ];

        // 3) Створюємо лід
        $lr_lead_id = LeadRouter_Flow::create_lead_simple($lead_data);
        if (is_wp_error($lr_lead_id)) {
            error_log('[LeadRouter] create_lead_simple failed: ' . $lr_lead_id->get_error_message());
        } else {
            // (опціонально) збережемо id у кукі — не заважає старому коду
            setcookie('lr_lead_id', (string)$lr_lead_id, time() + 600, '/');

            update_field('crm_status', 5, $post_id);

            /*if ( is_wp_error($res) ) {
                error_log('[LeadRouter] dispatch_broadcast error: '.$res->get_error_message());
            } else {
                update_field('crm_status', 5, $post_id);
            }*/
        }
    }
// === /LeadRouter ===


    date_default_timezone_set('US/Eastern');
    update_field('crm_create_est_time', date('m/d/Y h:i:s a', time()), $post_id);

    $formData = array(
        "year" => $_COOKIE['year'],
        "brand" => $_COOKIE['brand'],
        "model" => $_COOKIE['model'],
        "condition" => $_COOKIE['condition'],
        "bodytype" => $_COOKIE['bodytype'],
        "md_oc" => $_COOKIE['md_oc'],
        "md_os" => $_COOKIE['md_os'],
        "md_oz" => $_COOKIE['md_oz'],
        "md_dc" => $_COOKIE['md_dc'],
        "md_ds" => $_COOKIE['md_ds'],
        "md_dz" => $_COOKIE['md_dz']
    );


    setcookie('formData', json_encode($formData), time() + 400, '/');


    setcookie("year", '', time() + 400, '/');
    setcookie("brand", '', time() + 400, '/');
    setcookie("model", '', time() + 400, '/');
    setcookie("condition", '', time() + 400, '/');
    setcookie("place_from", '', time() + 400, '/');
    setcookie("place_to", '', time() + 400, '/');
    setcookie("md_oc", '', time() + 400, '/');
    setcookie("md_os", '', time() + 400, '/');
    setcookie("md_oz", '', time() + 400, '/');
    setcookie("md_dc", '', time() + 400, '/');
    setcookie("md_ds", '', time() + 400, '/');
    setcookie("md_dz", '', time() + 400, '/');


    wp_redirect(get_permalink(22));
}
