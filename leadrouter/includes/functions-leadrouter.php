<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }



/**
 * Assign a lead to a random partner from given group.
 * Returns partner ID on success or false.
 */
function leadrouter_assign_lead( $group_id, $lead_id = 0 ) {
    $partners = get_posts( [
        'post_type'      => 'leadrouter_partner',
        'posts_per_page' => -1,
        'post_status'    => 'any',
        'meta_key'       => 'leadrouter_group_id',
        'meta_value'     => intval($group_id),
        'fields'         => 'ids',
    ] );

    if ( empty( $partners ) ) {
        return false;
    }

    $partner_id = $partners[ array_rand( $partners ) ];
    leadrouter_log_assignment( $lead_id, $partner_id, $group_id, 'assigned' );

    /**
     * Action hook for developers to react when a lead is assigned.
     */
    do_action( 'leadrouter_lead_assigned', $lead_id, $partner_id, $group_id );

    return $partner_id;
}

/**
 * Log assignment to DB table.
 */
function leadrouter_log_assignment( $lead_id, $partner_id, $group_id, $status = 'assigned' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'leadrouter_logs';

    $wpdb->insert( $table, [
        'lead_id'     => intval($lead_id),
        'partner_id'  => intval($partner_id),
        'group_id'    => intval($group_id),
        'assigned_at' => current_time('mysql'),
        'status'      => sanitize_text_field($status),
    ], [ '%d','%d','%d','%s','%s' ] );
}
