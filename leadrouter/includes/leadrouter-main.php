<?php
use Carbon_Fields\Helper\Helper;
use Carbon_Fields\Container;
use Carbon_Fields\Field;


function leadrouter_mainfunc($lead_id)
{
/*
    $groups_adn_partners = leadrouter_get_sorted_groups_and_partners();

    $current_partners = leadrouter_get_current_partners($groups_adn_partners);

    $result = leadrouter_send_lead($current_partners, $lead_id);

    leadrouter_write_logs($result);*/

    /***
     *
     * поступає лід ->
     * система бере всі групи і сортує їх по пріоритету -> виставляє тип відправлення

     *
     *
     * —> перевіряє чи є доступні вільні ліміти в кожному з партнерів на дану добу
     * —> перевіряє чи вкладаємося в часові ліміти по партнеру який підходить по умовах відправки (якщо по черзі або якщо всім за раз)
     * —> відправляємо лід.
     * —> записуємо дані про відправку в логи / обробляємо помилку
     *
     * */
}



function leadrouter_get_sorted_groups_and_partners($group_id = NULL)
{

    $partners = get_posts([
        'post_type' => 'leadrouter_partner',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'fields' => 'all',

    ]);


    if (empty($partners)) {
        return false;
    }


    $groups_and_partners = [];
    foreach ($partners as $partner) {


        $partner_group_id = carbon_get_post_meta($partner->ID, 'leadrouter_partner_group');



        $week_limits = [];

        foreach (array('mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun') as $day) {

            $week_limits[$day] = [
                'limit' => carbon_get_post_meta($partner->ID, "leadrouter_partner_{$day}_limit"),
                'start' => carbon_get_post_meta($partner->ID, "leadrouter_partner_{$day}_start"),
                'end' => carbon_get_post_meta($partner->ID, "leadrouter_partner_{$day}_end"),
            ];

        }

        $groups_and_partners[$partner_group_id]['group'] = [
            'slug' => '',
            'name' => get_the_title($partner_group_id),
            'type' => carbon_get_post_meta($partner->ID, 'leadrouter_group_distribution_type'),
            'priority' => carbon_get_post_meta($partner->ID, 'leadrouter_group_priority'),
        ];

        $groups_and_partners[$partner_group_id]['partners'][] = [
            'id' => $partner->ID,
            'name' => $partner->page_title,
            'slug' => '',
            'priority' => carbon_get_post_meta($partner->ID, 'leadrouter_partner_priority'),
            'week' => $week_limits,
            ];


    }


    echo '<pre>';
    print_r($groups_and_partners);
    echo '</pre>';
}



