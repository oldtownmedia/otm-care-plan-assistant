<?php
/**
 * Uninstall OTM Care Plan Assistant
 *
 * Fired when the plugin is uninstalled. Drops the custom table and removes the API key option.
 *
 * @package OTM_Update_Logger
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'otm_update_log';

$wpdb->query( "DROP TABLE IF EXISTS $table_name" );
delete_option( 'otm_ul_api_key' );
