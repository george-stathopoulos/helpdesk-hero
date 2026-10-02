<?php
/**
 * Change timeline and error capture.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records what changed on the site (plugins, themes, core, key settings) and the errors it threw,
 * so a ticket can say "this started after X was updated yesterday". Everything stays in the
 * site's own database until the site owner sends a ticket.
 */
final class Helpdesk_Hero_Monitor {

	const AJAX_JS_ERROR = 'helpdesk_hero_js_error';

	/**
	 * Options whose changes are worth a timeline entry.
	 */
	const WATCHED_OPTIONS = array( 'permalink_structure', 'home', 'siteurl', 'WPLANG', 'timezone_string', 'blog_public', 'users_can_register', 'default_role' );

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'activated_plugin', array( __CLASS__, 'plugin_activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'plugin_deactivated' ), 10, 2 );
		add_action( 'deleted_plugin', array( __CLASS__, 'plugin_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgraded' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ), 10, 3 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'core_updated' ) );
		foreach ( self::WATCHED_OPTIONS as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'option_changed' ), 10, 3 );
		}
		register_shutdown_function( array( __CLASS__, 'capture_fatal' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_js_capture' ) );
		add_action( 'wp_ajax_' . self::AJAX_JS_ERROR, array( __CLASS__, 'ajax_js_error' ) );
	}

	/**
	 * Write a log row.
	 *
	 * @param string $type     change | error | activity | access.
	 * @param string $action   Machine action, e.g. plugin_activated.
	 * @param string $object   What it applies to (plugin file, URL, option name).
	 * @param array  $details  Extra data (stored as JSON).
	 * @param int    $grant_id Support grant this belongs to, if any.
	 * @return int Row ID.
	 */
	public static function record( $type, $action, $object = '', array $details = array(), $grant_id = 0 ) {
		global $wpdb;
		if ( get_option( Helpdesk_Hero_DB::OPTION ) !== Helpdesk_Hero_DB::VERSION ) {
			return 0;
		}
		// A named supporter's actions keep their name, even after the account is deleted.
		$supporter = $grant_id ? (string) get_user_meta( get_current_user_id(), Helpdesk_Hero_Access::SUPPORTER_NAME, true ) : '';
		if ( '' !== $supporter && ! isset( $details['by'] ) ) {
			$details['by'] = $supporter;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Helpdesk_Hero_DB::table( 'log' ),
			array(
				'type'       => $type,
				'grant_id'   => (int) $grant_id,
				'actor_id'   => get_current_user_id(),
				'action'     => substr( $action, 0, 64 ),
				'object'     => substr( (string) $object, 0, 255 ),
				'details'    => $details ? wp_json_encode( $details ) : null,
				'ip'         => $grant_id ? self::ip() : '',
				'created_at' => Helpdesk_Hero_DB::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Log rows.
	 *
	 * @param array $args type (string|array), grant_id, since (MySQL date), limit.
	 * @return array[]
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['type'] ) ) {
			$types   = (array) $args['type'];
			$where[] = 'type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$params  = array_merge( $params, $types );
		}
		if ( isset( $args['grant_id'] ) ) {
			$where[]  = 'grant_id = %d';
			$params[] = (int) $args['grant_id'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['since'];
		}
		$params[] = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 200;
		array_unshift( $params, Helpdesk_Hero_DB::table( 'log' ) );
		$sql      = 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		// $sql is built only from fixed strings and placeholders; every value goes through prepare(), the table name through %i.
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as &$row ) {
			$row['details'] = $row['details'] ? (array) json_decode( $row['details'], true ) : array();
		}
		return $rows;
	}

	/**
	 * Delete rows older than the retention period.
	 */
	public static function prune() {
		global $wpdb;
		$days = max( 7, (int) Helpdesk_Hero_Settings::get( 'retention_days' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Helpdesk_Hero_DB::table( 'log' ), Helpdesk_Hero_DB::now( -$days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Plugin name and version for a plugin file.
	 *
	 * @param string $file Plugin basename.
	 * @return array
	 */
	private static function plugin_info( $file ) {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return array( 'name' => $file, 'version' => '' );
		}
		$data = get_plugin_data( $path, false, false );
		return array(
			'name'    => $data['Name'] ? $data['Name'] : $file,
			'version' => $data['Version'],
		);
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $file         Plugin.
	 * @param bool   $network_wide Network.
	 */
	public static function plugin_activated( $file, $network_wide = false ) {
		self::record( 'change', 'plugin_activated', $file, self::plugin_info( $file ) + array( 'network' => (bool) $network_wide ), Helpdesk_Hero_Access::current_grant_id() );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $file         Plugin.
	 * @param bool   $network_wide Network.
	 */
	public static function plugin_deactivated( $file, $network_wide = false ) {
		self::record( 'change', 'plugin_deactivated', $file, self::plugin_info( $file ) + array( 'network' => (bool) $network_wide ), Helpdesk_Hero_Access::current_grant_id() );
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $file    Plugin.
	 * @param bool   $deleted Whether it worked.
	 */
	public static function plugin_deleted( $file, $deleted ) {
		if ( $deleted ) {
			self::record( 'change', 'plugin_deleted', $file, array( 'name' => $file ), Helpdesk_Hero_Access::current_grant_id() );
		}
	}

	/**
	 * Plugins, themes or translations updated.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $extra    Details.
	 */
	public static function upgraded( $upgrader, $extra ) {
		$type   = $extra['type'] ?? '';
		$action = $extra['action'] ?? '';
		if ( 'plugin' === $type ) {
			$files = ! empty( $extra['plugins'] ) ? (array) $extra['plugins'] : ( ! empty( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
			if ( ! $files && 'install' === $action && $upgrader instanceof Plugin_Upgrader && method_exists( $upgrader, 'plugin_info' ) ) {
				$files = array_filter( array( $upgrader->plugin_info() ) );
			}
			foreach ( $files as $file ) {
				self::record( 'change', 'install' === $action ? 'plugin_installed' : 'plugin_updated', $file, self::plugin_info( $file ), Helpdesk_Hero_Access::current_grant_id() );
			}
		} elseif ( 'theme' === $type ) {
			$themes = ! empty( $extra['themes'] ) ? (array) $extra['themes'] : ( ! empty( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
			foreach ( $themes as $slug ) {
				$theme = wp_get_theme( $slug );
				self::record(
					'change',
					'install' === $action ? 'theme_installed' : 'theme_updated',
					$slug,
					array(
						'name'    => $theme->exists() ? $theme->get( 'Name' ) : $slug,
						'version' => $theme->exists() ? $theme->get( 'Version' ) : '',
					),
					Helpdesk_Hero_Access::current_grant_id()
				);
			}
		}
	}

	/**
	 * Theme switched.
	 *
	 * @param string   $new_name  New name.
	 * @param WP_Theme $new_theme New theme.
	 * @param WP_Theme $old_theme Old theme.
	 */
	public static function theme_switched( $new_name, $new_theme, $old_theme = null ) {
		self::record(
			'change',
			'theme_switched',
			$new_theme->get_stylesheet(),
			array(
				'name'    => $new_name,
				'version' => $new_theme->get( 'Version' ),
				'from'    => $old_theme instanceof WP_Theme ? $old_theme->get( 'Name' ) : '',
			),
			Helpdesk_Hero_Access::current_grant_id()
		);
	}

	/**
	 * WordPress core updated.
	 *
	 * @param string $version New version.
	 */
	public static function core_updated( $version ) {
		self::record( 'change', 'core_updated', 'WordPress', array( 'version' => $version ), Helpdesk_Hero_Access::current_grant_id() );
	}

	/**
	 * A watched option changed.
	 *
	 * @param mixed  $old    Old value.
	 * @param mixed  $value  New value.
	 * @param string $option Option.
	 */
	public static function option_changed( $old, $value, $option ) {
		if ( maybe_serialize( $old ) === maybe_serialize( $value ) || ( is_scalar( $old ) && is_scalar( $value ) && (string) $old === (string) $value ) ) {
			return;
		}
		self::record(
			'change',
			'setting_changed',
			$option,
			array(
				'from' => is_scalar( $old ) ? (string) $old : wp_json_encode( $old ),
				'to'   => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			),
			Helpdesk_Hero_Access::current_grant_id()
		);
	}

	/**
	 * Record PHP fatal errors (also when WP_DEBUG is off). Repeats of the same error are counted once per hour.
	 */
	public static function capture_fatal() {
		$error = error_get_last();
		if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}
		if ( ! function_exists( 'get_transient' ) || ! did_action( 'plugins_loaded' ) ) {
			return;
		}
		$key = 'helpdesk_hero_fatal_' . md5( $error['message'] . $error['file'] . $error['line'] );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		self::record(
			'error',
			'php_fatal',
			self::component_for_path( $error['file'] ),
			array(
				'message' => Helpdesk_Hero_Redactor::text( substr( $error['message'], 0, 2000 ) ),
				'file'    => self::relative_path( $error['file'] ),
				'line'    => (int) $error['line'],
				'url'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			)
		);
	}

	/**
	 * Small inline script that reports JavaScript errors on admin screens.
	 */
	public static function enqueue_js_capture() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_register_script( 'helpdesk-hero-js-errors', false, array(), HELPDESK_HERO_VERSION, false );
		wp_enqueue_script( 'helpdesk-hero-js-errors' );
		$config = array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::AJAX_JS_ERROR ),
		);
		wp_add_inline_script(
			'helpdesk-hero-js-errors',
			'(function(c){var n=0;window.addEventListener("error",function(e){if(n++>4||!e||!e.message||!navigator.sendBeacon){return;}var d=new FormData();d.append("action","' . self::AJAX_JS_ERROR . '");d.append("_ajax_nonce",c.nonce);d.append("message",String(e.message).slice(0,500));d.append("source",String(e.filename||"").slice(0,300));d.append("line",e.lineno||0);d.append("page",location.pathname+location.search);navigator.sendBeacon(c.url,d);});})(' . wp_json_encode( $config ) . ');'
		);
	}

	/**
	 * Store a reported JavaScript error.
	 */
	public static function ajax_js_error() {
		check_ajax_referer( self::AJAX_JS_ERROR );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', '', array( 'response' => 403 ) );
		}
		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$source  = isset( $_POST['source'] ) ? esc_url_raw( wp_unslash( $_POST['source'] ) ) : '';
		$page    = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : '';
		$key     = 'helpdesk_hero_js_' . md5( $message . $source );
		if ( '' !== $message && ! get_transient( $key ) ) {
			set_transient( $key, 1, HOUR_IN_SECONDS );
			self::record(
				'error',
				'js_error',
				self::component_for_url( $source ),
				array(
					'message' => Helpdesk_Hero_Redactor::text( $message ),
					'file'    => $source,
					'line'    => isset( $_POST['line'] ) ? absint( $_POST['line'] ) : 0,
					'url'     => $page,
				)
			);
		}
		wp_die( '', '', array( 'response' => 204 ) );
	}

	/**
	 * "plugin:slug", "theme:slug", "core" or "other" for a file path.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	public static function component_for_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		foreach ( array(
			'plugin'    => wp_normalize_path( WP_PLUGIN_DIR ),
			'mu-plugin' => wp_normalize_path( WPMU_PLUGIN_DIR ),
			'theme'     => wp_normalize_path( get_theme_root() ),
		) as $kind => $dir ) {
			if ( 0 === strpos( $path, $dir . '/' ) ) {
				$rest = substr( $path, strlen( $dir ) + 1 );
				return $kind . ':' . strtok( $rest, '/' );
			}
		}
		if ( 0 === strpos( $path, wp_normalize_path( ABSPATH ) ) ) {
			return 'core';
		}
		return 'other';
	}

	/**
	 * Component for an asset URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function component_for_url( $url ) {
		if ( preg_match( '#/wp-content/(plugins|themes|mu-plugins)/([^/?]+)#', (string) $url, $m ) ) {
			return array(
				'plugins'    => 'plugin',
				'themes'     => 'theme',
				'mu-plugins' => 'mu-plugin',
			)[ $m[1] ] . ':' . $m[2];
		}
		if ( preg_match( '#/wp-(includes|admin)/#', (string) $url ) ) {
			return 'core';
		}
		return 'other';
	}

	/**
	 * Path relative to the WordPress root.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function relative_path( $path ) {
		$path = wp_normalize_path( (string) $path );
		$root = wp_normalize_path( ABSPATH );
		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : basename( $path );
	}

	/**
	 * Visitor IP address (for the support access log only).
	 *
	 * @return string
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
