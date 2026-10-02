<?php
/**
 * Uninstall: delete support accounts, tables and options.
 *
 * @package Helpdesk_Hero
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Any remaining temporary support accounts (deactivation normally removed them already).
$helpdesk_hero_users = get_users(
	array(
		'meta_key' => 'helpdesk_hero_grant', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'fields'   => 'ID',
	)
);
if ( $helpdesk_hero_users ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $helpdesk_hero_users as $helpdesk_hero_user ) {
		wp_delete_user( (int) $helpdesk_hero_user );
	}
}

foreach ( array( 'tickets', 'messages', 'grants', 'log' ) as $helpdesk_hero_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'helpdesk_hero_' . $helpdesk_hero_table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

foreach ( array( 'helpdesk_hero_settings', 'helpdesk_hero_secrets', 'helpdesk_hero_db', 'helpdesk_hero_notices', 'helpdesk_hero_troubleshoot' ) as $helpdesk_hero_option ) {
	delete_option( $helpdesk_hero_option );
}

$helpdesk_hero_mu = WPMU_PLUGIN_DIR . '/helpdesk-hero-troubleshoot.php';
if ( file_exists( $helpdesk_hero_mu ) ) {
	wp_delete_file( $helpdesk_hero_mu );
}

foreach ( array( 'helpdesk_hero_prune', 'helpdesk_hero_expire_access', 'helpdesk_hero_pull' ) as $helpdesk_hero_hook ) {
	wp_clear_scheduled_hook( $helpdesk_hero_hook );
}
