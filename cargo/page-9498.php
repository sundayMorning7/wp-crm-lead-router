<?php

echo 'TEST';


/*


$data = array(
    'first_name' => 'test',
    'last_name' => 'test',
    'email' => 'test@test.com',
    'phone' => '1111111111',
    'ship_date' => '01-01-2000',
    'comment_from_shipper' => '',
    'transport_type' => '1',
    'Vehicles' => array(
        array(
            'vehicle_type' => 'Sedan',
            'vehicle_model_year' => 2015,
            'vehicle_make' => 'BMW',
            'vehicle_model' => '3 Series',
            'vehicle_inop' => 0,
        )
    ),

    // from
    'origin_country' => 'USA',
    'origin_city' => 'Calico Rock', /// city
    'origin_state' => 'AR', ///
    'origin_postal_code' => 72519, /// zip
    // to
    'destination_country' => 'USA',
    'destination_city' => 'Maxwell', // city
    'destination_state' => 'IA', //
    'destination_postal_code' => 50161, // zip

    'AuthKey' => '9F86A641-A99F-4E09-B737-BA8597E731B3',

);
*/

//$url = 'https://api.batscrm.com/leads-sandbox/sandbox?' . http_build_query($data);
$url = 'https://api.batscrm.com/leads?' . http_build_query($data);



/*
$data = array(
    'first_name' => 'test',
    'last_name' => 'test',
    'email' => 'test@test.com',
    'phone' => '1111111111',
    'ship_date' => '01/01/2000',
    'comment_from_shipper' => '',
    'transport_type' => '1',
    'Vehicles' => array(
        array(
            'vehicle_type' => 'Sedan',
            'vehicle_model_year' => 2015,
            'vehicle_make' => 'BMW',
            'vehicle_model' => '3 Series',
            'vehicle_inop' => 0,
        )
    ),

    // from
    'origin_country' => 'USA',
    'origin_city' => 'Calico Rock', /// city
    'origin_state' => 'AR', ///
    'origin_postal_code' => 72519, /// zip
    // to
    'destination_country' => 'USA',
    'destination_city' => 'Maxwell', // city
    'destination_state' => 'IA', //
    'destination_postal_code' => 50161, // zip

    'AuthKey' => 'mbsoo7embs567mbs8eu9mbs--b1595cf564ddb233c116bdc0e777fdbb',

);


$url = 'https://leads.msgplane.com?' . http_build_query($data);

*/

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$server_output = curl_exec($ch);
curl_close($ch);


var_dump($server_output);

$content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
