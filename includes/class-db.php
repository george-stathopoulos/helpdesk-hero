<?php
/**
 * Database tables.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and removes the plugin's tables.
 *
 * Tables: tickets, messages, grants, log.
 */
final class Helpdesk_Hero_DB {

	const VERSION = '3';
	const OPTION  = 'helpdesk_hero_db';

	/**
	 * Full table name.
	 *
	 * @param string $name Short name, e.g. "tickets".
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'helpdesk_hero_' . $name;
	}

	/**
	 * Install or upgrade the site tables when the stored version is behind.
	 */
	public static function maybe_install() {
		if ( get_option( self::OPTION ) !== self::VERSION ) {
			self::install();
		}
	}

	/**
	 * Create the site tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'tickets' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			subject varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'open',
			channel varchar(10) NOT NULL DEFAULT 'email',
			priority varchar(10) NOT NULL DEFAULT 'normal',
			remote_id bigint(20) unsigned NOT NULL DEFAULT 0,
			helpdesk_ref varchar(64) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			grant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			unread tinyint(1) NOT NULL DEFAULT 0,
			tags longtext NULL,
			manual_to varchar(190) NOT NULL DEFAULT '',
			manual_text longtext NULL,
			pending_payload longtext NULL,
			rating tinyint(1) unsigned NOT NULL DEFAULT 0,
			rating_comment text NULL,
			rated_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY remote_id (remote_id)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'messages' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			direction varchar(10) NOT NULL DEFAULT 'out',
			author varchar(190) NOT NULL DEFAULT '',
			body longtext NOT NULL,
			remote_id varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY ticket_id (ticket_id),
			KEY remote_id (remote_id)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'grants' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ticket_id bigint(20) unsigned NOT NULL DEFAULT 0,
			role varchar(40) NOT NULL DEFAULT '',
			allow_plugins tinyint(1) NOT NULL DEFAULT 0,
			token_hash varchar(64) NOT NULL DEFAULT '',
			token_created_at datetime NULL,
			token_used_at datetime NULL,
			token_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			revoked_at datetime NULL,
			extension_request longtext NULL,
			note varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY token_hash (token_hash)
			) $charset;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(20) NOT NULL DEFAULT 'activity',
			grant_id bigint(20) unsigned NOT NULL DEFAULT 0,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(64) NOT NULL DEFAULT '',
			object varchar(255) NOT NULL DEFAULT '',
			details longtext NULL,
			ip varchar(45) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_created (type,created_at),
			KEY grant_id (grant_id)
			) $charset;"
		);
		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Drop every table (uninstall).
	 */
	public static function uninstall() {
		global $wpdb;
		foreach ( array( 'tickets', 'messages', 'grants', 'log' ) as $name ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		delete_option( self::OPTION );
	}

	/**
	 * Current UTC time in MySQL format.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}
}
