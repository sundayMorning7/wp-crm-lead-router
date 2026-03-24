<?php wp_footer(); ?>
<?php
if (is_page(22)) {
    ?>
    <script type="text/javascript">

        fbq('track', 'Lead', {
            content_name: 'Lead: <?php echo $_COOKIE['md_lead_name']; ?>',
            content_category: 'Thanks page',
            content_ids: ['<?php echo $_COOKIE['md_lead_id']; ?>'],
            content_type: 'form',
            status: 'new',
            lead_type: 'form_submission'
        });
    </script>
<?php } ?>




<?php if (isset($_GET['maks22'])) {


    echo 'WP timezone: ' . wp_timezone_string();
    echo '<br>';
    echo 'WP current time: ' . current_time('mysql');
    echo '<br>';
    echo 'Server time: ' . date('Y-m-d H:i:s');




    ?>






    <?php



/*
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header'  => "User-Agent: PHP\r\n"
        ]
    ]);

    $url = 'http://209.97.146.104/vehicles';
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        $error = error_get_last();
        print_r($error);
    } else {
        echo $response;
    }
*/


/*
    $c = curl_init('http://209.97.146.104:3000/vehicles/');
    curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 0);
    curl_setopt($c, CURLOPT_TIMEOUT, 10);

    $html = curl_exec($c);

    $status = curl_getinfo($c, CURLINFO_HTTP_CODE);
    var_dump($status);
    var_dump($html);

    if (curl_error($c)) {
        echo 'Error:' . curl_error($c);
        die(curl_error($c));


    curl_close($c);
    }*/





};






?>




</body>
</html>