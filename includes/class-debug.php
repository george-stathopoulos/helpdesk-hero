<?php
/**
 * Debugging tools.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only debugging information: the end of debug.log (searchable), the PHP and JavaScript
 * errors this plugin caught, scheduled tasks, and the debugging constants. Nothing here changes
 * the site. Paths, emails and keys are removed before anything is shown or shared.
 */
final class Helpdesk_Hero_Debug {

	/**
	 * Where WordPress writes its debug log, or '' when logging is off.
	 *
	 * @return string
	 */
	public static function log_path() {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return '';
		}
		return is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * The last lines of debug.log.
	 *
	 * @param int    $lines  How many lines.
	 * @param string $search Only lines containing this ('' for all).
	 * @return array { enabled, exists, size, lines[], truncated }
	 */
	public static function log_tail( $lines = 200, $search = '' ) {
		$path = self::log_path();
		$out  = array(
			'enabled' => '' !== $path,
			'exists'  => false,
			'size'    => 0,
			'lines'   => array(),
		);
		if ( '' === $path || ! is_readable( $path ) ) {
			return $out;
		}
		$out['exists'] = true;
		$out['size']   = (int) filesize( $path );
		// Read at most the last 512 KB: enough for hundreds of lines without loading huge logs.
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the end of a log file.
		if ( ! $handle ) {
			return $out;
		}
		$read = min( $out['size'], 512 * KB_IN_BYTES );
		fseek( $handle, -$read, SEEK_END );
		$chunk = (string) fread( $handle, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$all = preg_split( '/\r\n|\n/', rtrim( $chunk ) );
		if ( $read < $out['size'] ) {
			array_shift( $all ); // The first line is probably cut.
		}
		if ( '' !== $search ) {
			$all = array_values(
				array_filter(
					$all,
					static function ( $l ) use ( $search ) {
						return false !== stripos( $l, $search );
					}
				)
			);
		}
		$out['lines'] = array_map(
			static function ( $l ) {
				return Helpdesk_Hero_Redactor::text( substr( $l, 0, 2000 ) );
			},
			array_slice( $all, -max( 10, min( 1000, (int) $lines ) ) )
		);
		return $out;
	}

	/**
	 * PHP and JavaScript errors caught by this plugin, newest first.
	 *
	 * @return array[]
	 */
	public static function errors() {
		$out = array();
		foreach ( Helpdesk_Hero_Monitor::query( array( 'type' => 'error', 'limit' => 100 ) ) as $row ) {
			$d     = (array) $row['details'];
			$out[] = array(
				'time'      => gmdate( 'c', strtotime( $row['created_at'] . ' UTC' ) ),
				'kind'      => $row['action'],
				'component' => $row['object'],
				'message'   => (string) ( $d['message'] ?? '' ),
				'where'     => trim( (string) ( $d['file'] ?? $d['source'] ?? '' ) . ( ! empty( $d['line'] ) ? ':' . (int) $d['line'] : '' ), ':' ),
				'page'      => (string) ( $d['url'] ?? $d['page'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Scheduled tasks (WP-Cron), soonest first, with ones that are late marked.
	 *
	 * @return array { disabled, tasks[] }
	 */
	public static function cron() {
		$tasks = array();
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					$tasks[] = array(
						'hook'     => (string) $hook,
						'next'     => gmdate( 'c', (int) $time ),
						'late'     => (int) $time < time() - 10 * MINUTE_IN_SECONDS,
						'schedule' => (string) ( $event['schedule'] ?? '' ),
					);
				}
			}
		}
		return array(
			'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'tasks'    => array_slice( $tasks, 0, 200 ),
		);
	}

	/**
	 * Debugging constants and settings worth knowing.
	 *
	 * @return array[] { name, value }
	 */
	public static function constants() {
		$flag = static function ( $name ) {
			if ( ! defined( $name ) ) {
				return __( 'not set', 'helpdesk-hero' );
			}
			$v = constant( $name );
			return is_bool( $v ) ? ( $v ? 'true' : 'false' ) : ( is_scalar( $v ) ? (string) $v : 'set' );
		};
		$out = array();
		foreach ( array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'SAVEQUERIES', 'WP_CACHE', 'DISABLE_WP_CRON', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_ENVIRONMENT_TYPE', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'CONCATENATE_SCRIPTS' ) as $name ) {
			$value = $flag( $name );
			// A log path can reveal the server layout: show it relative to the site.
			if ( 'WP_DEBUG_LOG' === $name && defined( $name ) && is_string( constant( $name ) ) ) {
				$value = Helpdesk_Hero_Monitor::relative_path( constant( $name ) );
			}
			$out[] = array(
				'name'  => $name,
				'value' => $value,
			);
		}
		$out[] = array(
			'name'  => __( 'Object cache', 'helpdesk-hero' ),
			'value' => wp_using_ext_object_cache() ? __( 'persistent (drop-in)', 'helpdesk-hero' ) : __( 'none', 'helpdesk-hero' ),
		);
		$out[] = array(
			'name'  => 'display_errors',
			'value' => (string) ini_get( 'display_errors' ),
		);
		return $out;
	}

	/**
	 * Everything on the Debug page.
	 *
	 * @param int    $lines  Log lines.
	 * @param string $search Log search.
	 * @return array
	 */
	public static function state( $lines = 200, $search = '' ) {
		return array(
			'log'       => self::log_tail( $lines, $search ),
			'errors'    => self::errors(),
			'cron'      => self::cron(),
			'constants' => self::constants(),
		);
	}
}
