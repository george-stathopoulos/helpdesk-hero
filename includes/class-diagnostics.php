<?php
/**
 * Site diagnostics collected for a ticket.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the diagnostics bundle. Nothing here makes network requests; the bundle only leaves the
 * site when the site owner sends a ticket, and they choose which sections go with it.
 */
final class Helpdesk_Hero_Diagnostics {

	/**
	 * Section keys and labels.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'environment' => __( 'WordPress, PHP, server and configuration', 'helpdesk-hero' ),
			'extensions'  => __( 'Plugins and themes, with versions and pending updates', 'helpdesk-hero' ),
			'errors'      => __( 'Recent PHP and JavaScript errors', 'helpdesk-hero' ),
			'changes'     => __( 'What changed recently (updates, activations, settings)', 'helpdesk-hero' ),
			'debug_log'   => __( 'Last lines of debug.log (secrets and emails removed)', 'helpdesk-hero' ),
		);
	}

	/**
	 * Collect the bundle.
	 *
	 * @param string[]|null $sections Section keys to include (null = all).
	 * @return array
	 */
	public static function collect( $sections = null ) {
		$sections = null === $sections ? array_keys( self::sections() ) : array_values( array_intersect( (array) $sections, array_keys( self::sections() ) ) );
		$data     = array(
			'generated_at' => gmdate( 'c' ),
			'generator'    => 'Helpdesk Hero ' . HELPDESK_HERO_VERSION,
			'site'         => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'  => home_url( '/' ),
			),
			'sections'     => $sections,
		);

		if ( in_array( 'environment', $sections, true ) ) {
			$data['environment'] = self::environment();
		}
		if ( in_array( 'extensions', $sections, true ) ) {
			$data['extensions'] = self::extensions();
		}
		if ( in_array( 'errors', $sections, true ) ) {
			$data['errors'] = self::errors();
		}
		if ( in_array( 'changes', $sections, true ) ) {
			$data['changes'] = self::changes();
		}
		if ( in_array( 'debug_log', $sections, true ) ) {
			$data['debug_log'] = self::debug_log();
		}

		/**
		 * Filters the diagnostics bundle, for example to add your own plugin's settings.
		 * Values are redacted after this filter runs.
		 *
		 * @param array    $data     Bundle.
		 * @param string[] $sections Selected sections.
		 */
		$data = (array) apply_filters( 'helpdesk_hero_diagnostics', $data, $sections );

		return Helpdesk_Hero_Redactor::deep( $data );
	}

	/**
	 * WordPress, PHP, database and server.
	 *
	 * @return array
	 */
	public static function environment() {
		global $wpdb, $wp_version;

		$cron       = _get_cron_array();
		$overdue    = 0;
		$next_event = null;
		if ( is_array( $cron ) ) {
			foreach ( $cron as $time => $hooks ) {
				if ( $time < time() - 15 * MINUTE_IN_SECONDS ) {
					$overdue += count( $hooks );
				}
				$next_event = null === $next_event ? $time : min( $next_event, $time );
			}
		}

		$db_server = method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : $wpdb->db_version();

		return array(
			'wordpress'           => $wp_version,
			'php'                 => PHP_VERSION,
			'database'            => $db_server,
			'server'              => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'os'                  => PHP_OS,
			'environment_type'    => wp_get_environment_type(),
			'multisite'           => is_multisite(),
			'https'               => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			'locale'              => get_locale(),
			'timezone'            => wp_timezone_string(),
			'permalinks'          => (string) get_option( 'permalink_structure' ),
			'search_engines'      => (bool) get_option( 'blog_public' ),
			'memory_limit'        => (string) ini_get( 'memory_limit' ),
			'wp_memory_limit'     => WP_MEMORY_LIMIT,
			'wp_max_memory_limit' => WP_MAX_MEMORY_LIMIT,
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
			'post_max_size'       => (string) ini_get( 'post_max_size' ),
			'php_extensions'      => array_values( array_intersect( array( 'curl', 'gd', 'imagick', 'intl', 'mbstring', 'openssl', 'sodium', 'xml', 'zip', 'exif', 'fileinfo', 'json', 'mysqli' ), get_loaded_extensions() ) ),
			'object_cache'        => wp_using_ext_object_cache(),
			'page_cache_dropin'   => file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'wp_debug_display'    => defined( 'WP_DEBUG_DISPLAY' ) ? (bool) WP_DEBUG_DISPLAY : true,
			'script_debug'        => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			'disallow_file_edit'  => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'disallow_file_mods'  => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
			'wp_cron_disabled'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cron_overdue_events' => $overdue,
			'table_prefix_custom' => 'wp_' !== $wpdb->prefix,
			'users'               => (int) ( count_users()['total_users'] ?? 0 ),
			'content_dir_writable' => wp_is_writable( WP_CONTENT_DIR ),
		);
	}

	/**
	 * Plugins, must-use plugins, drop-ins and themes.
	 *
	 * @return array
	 */
	public static function extensions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = get_site_transient( 'update_plugins' );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		$active   = array();
		$inactive = array();
		foreach ( get_plugins() as $file => $plugin ) {
			$row = array(
				'file'    => $file,
				'name'    => $plugin['Name'],
				'version' => $plugin['Version'],
				'author'  => wp_strip_all_tags( $plugin['Author'] ),
			);
			if ( isset( $updates->response[ $file ]->new_version ) ) {
				$row['update'] = $updates->response[ $file ]->new_version;
			}
			if ( in_array( $file, $network, true ) ) {
				$row['network'] = true;
			}
			if ( is_plugin_active( $file ) ) {
				$active[] = $row;
			} else {
				$inactive[] = $row;
			}
		}

		$mu = array();
		foreach ( get_mu_plugins() as $file => $plugin ) {
			$mu[] = array(
				'file'    => $file,
				'name'    => $plugin['Name'] ? $plugin['Name'] : $file,
				'version' => $plugin['Version'],
			);
		}

		$dropins = array_keys( get_dropins() );

		$theme         = wp_get_theme();
		$theme_updates = get_site_transient( 'update_themes' );
		$theme_row     = static function ( WP_Theme $t ) use ( $theme_updates ) {
			$row = array(
				'slug'    => $t->get_stylesheet(),
				'name'    => $t->get( 'Name' ),
				'version' => $t->get( 'Version' ),
				'block'   => method_exists( $t, 'is_block_theme' ) ? $t->is_block_theme() : false,
			);
			if ( isset( $theme_updates->response[ $t->get_stylesheet() ]['new_version'] ) ) {
				$row['update'] = $theme_updates->response[ $t->get_stylesheet() ]['new_version'];
			}
			return $row;
		};

		return array(
			'active_plugins'   => $active,
			'inactive_plugins' => $inactive,
			'mu_plugins'       => $mu,
			'dropins'          => $dropins,
			'theme'            => $theme_row( $theme ),
			'parent_theme'     => $theme->parent() ? $theme_row( $theme->parent() ) : null,
		);
	}

	/**
	 * Errors captured in the last 14 days.
	 *
	 * @return array
	 */
	public static function errors() {
		$rows = Helpdesk_Hero_Monitor::query(
			array(
				'type'  => 'error',
				'since' => Helpdesk_Hero_DB::now( -14 * DAY_IN_SECONDS ),
				'limit' => 40,
			)
		);
		return array_map(
			static function ( $row ) {
				return array(
					'time'      => $row['created_at'] . ' UTC',
					'kind'      => 'js_error' === $row['action'] ? 'JavaScript' : 'PHP fatal',
					'component' => $row['object'],
					'message'   => $row['details']['message'] ?? '',
					'file'      => ( $row['details']['file'] ?? '' ) . ( ! empty( $row['details']['line'] ) ? ':' . $row['details']['line'] : '' ),
					'url'       => $row['details']['url'] ?? '',
				);
			},
			$rows
		);
	}

	/**
	 * Changes in the last 30 days, newest first.
	 *
	 * @return array
	 */
	public static function changes() {
		$rows = Helpdesk_Hero_Monitor::query(
			array(
				'type'  => 'change',
				'since' => Helpdesk_Hero_DB::now( -30 * DAY_IN_SECONDS ),
				'limit' => 60,
			)
		);
		return array_map(
			static function ( $row ) {
				return array(
					'time'    => $row['created_at'] . ' UTC',
					'event'   => Helpdesk_Hero_Activity::describe( $row ),
					'object'  => $row['object'],
					'support' => (int) $row['grant_id'] > 0,
				);
			},
			$rows
		);
	}

	/**
	 * Last lines of the debug log, when one exists and is readable.
	 *
	 * @return array{path:string,lines:string[]}|null
	 */
	public static function debug_log() {
		$path = WP_CONTENT_DIR . '/debug.log';
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			$path = WP_DEBUG_LOG;
		}
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			return null;
		}
		$size   = filesize( $path );
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return null;
		}
		$read = min( $size, 64 * KB_IN_BYTES );
		fseek( $handle, -$read, SEEK_END );
		$chunk = (string) fread( $handle, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$lines = array_slice( array_filter( explode( "\n", $chunk ) ), -60 );
		return array(
			'path'  => Helpdesk_Hero_Monitor::relative_path( $path ),
			'size'  => size_format( $size ),
			'lines' => array_map( array( 'Helpdesk_Hero_Redactor', 'text' ), $lines ),
		);
	}

	/**
	 * Plain-text version of a bundle, for emails and attachments.
	 *
	 * @param array $data  Bundle.
	 * @param array $flags Health flags.
	 * @return string
	 */
	public static function to_text( array $data, array $flags = array() ) {
		$out   = array();
		$out[] = 'SITE DIAGNOSTICS — ' . ( $data['site']['name'] ?? '' );
		$out[] = ( $data['site']['url'] ?? '' ) . ' · generated ' . ( $data['generated_at'] ?? '' ) . ' by ' . ( $data['generator'] ?? '' );
		$out[] = '';

		if ( $flags ) {
			$out[] = '== Health flags ==';
			foreach ( $flags as $flag ) {
				$out[] = '[' . strtoupper( $flag['level'] ) . '] ' . $flag['title'] . ( ! empty( $flag['detail'] ) ? ' — ' . $flag['detail'] : '' );
			}
			$out[] = '';
		}

		if ( ! empty( $data['environment'] ) ) {
			$out[] = '== Environment ==';
			foreach ( $data['environment'] as $key => $value ) {
				$out[] = str_pad( $key, 22 ) . ': ' . self::scalar( $value );
			}
			$out[] = '';
		}

		if ( ! empty( $data['extensions'] ) ) {
			$ext   = $data['extensions'];
			$out[] = '== Theme ==';
			$out[] = self::ext_line( $ext['theme'] ) . ( ! empty( $ext['parent_theme'] ) ? '  (child of ' . self::ext_line( $ext['parent_theme'] ) . ')' : '' );
			$out[] = '';
			$out[] = '== Active plugins (' . count( $ext['active_plugins'] ) . ') ==';
			foreach ( $ext['active_plugins'] as $p ) {
				$out[] = '- ' . self::ext_line( $p ) . ' [' . $p['file'] . ']';
			}
			if ( $ext['mu_plugins'] ) {
				$out[] = '';
				$out[] = '== Must-use plugins ==';
				foreach ( $ext['mu_plugins'] as $p ) {
					$out[] = '- ' . self::ext_line( $p );
				}
			}
			if ( $ext['dropins'] ) {
				$out[] = '';
				$out[] = '== Drop-ins == ' . implode( ', ', $ext['dropins'] );
			}
			if ( $ext['inactive_plugins'] ) {
				$out[] = '';
				$out[] = '== Inactive plugins (' . count( $ext['inactive_plugins'] ) . ') ==';
				foreach ( $ext['inactive_plugins'] as $p ) {
					$out[] = '- ' . self::ext_line( $p );
				}
			}
			$out[] = '';
		}

		if ( isset( $data['errors'] ) ) {
			$out[] = '== Recent errors (14 days) ==';
			if ( ! $data['errors'] ) {
				$out[] = 'None recorded.';
			}
			foreach ( $data['errors'] as $e ) {
				$out[] = $e['time'] . ' · ' . $e['kind'] . ' · ' . $e['component'] . "\n  " . $e['message'] . ( $e['file'] ? "\n  at " . $e['file'] : '' ) . ( $e['url'] ? "\n  on " . $e['url'] : '' );
			}
			$out[] = '';
		}

		if ( isset( $data['changes'] ) ) {
			$out[] = '== Recent changes (30 days) ==';
			if ( ! $data['changes'] ) {
				$out[] = 'None recorded.';
			}
			foreach ( $data['changes'] as $c ) {
				$out[] = $c['time'] . ' · ' . $c['event'] . ( $c['support'] ? ' (by support)' : '' );
			}
			$out[] = '';
		}

		if ( ! empty( $data['debug_log'] ) ) {
			$out[] = '== ' . $data['debug_log']['path'] . ' (' . $data['debug_log']['size'] . ', last lines) ==';
			$out   = array_merge( $out, $data['debug_log']['lines'] );
			$out[] = '';
		}

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, array( 'generated_at', 'generator', 'site', 'sections', 'environment', 'extensions', 'errors', 'changes', 'debug_log' ), true ) ) {
				continue;
			}
			$out[] = '== ' . $key . ' ==';
			$out[] = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$out[] = '';
		}

		return implode( "\n", $out );
	}

	/**
	 * Plugin or theme as "Name 1.2 (update 1.3 available)".
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function ext_line( $row ) {
		return $row['name'] . ' ' . $row['version'] . ( ! empty( $row['update'] ) ? ' (update ' . $row['update'] . ' available)' : '' ) . ( ! empty( $row['network'] ) ? ' [network]' : '' );
	}

	/**
	 * Scalar for display.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 'yes' : 'no';
		}
		if ( is_array( $value ) ) {
			return implode( ', ', $value );
		}
		return (string) $value;
	}
}
