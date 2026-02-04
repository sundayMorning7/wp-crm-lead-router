<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
        the_title();

        $post_id = $q->post->ID;


        if (md_check_day_limits() && !in_array(get_field('md_os', $post_id), array('AK','HI')) && !in_array(get_field('md_ds', $post_id) ,array('AK','HI'))) {

            the_title();
        }


    endwhile;
endif;




?>