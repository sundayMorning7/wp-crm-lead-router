<?php
/**
 * On plugin uninstall.
 * We DO NOT delete data by default to prevent accidental loss.
 * If you want to drop tables/options, uncomment the code below.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Example of cleanup (disabled by default):
// global $wpdb;
// delete_option( 'leadrouter_version' );
// delete_option( 'leadrouter_settings' );
// $table = $wpdb->prefix . 'leadrouter_logs';
// $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
