<?php
/**
 * Activity log for temporary support users.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records what a support user does while their access is active: pages visited, settings
 * changed (with old and new values, secrets hidden), content edited, plugins and themes changed.
 */
final class Helpdesk_Hero_Activity {

	/**
	 * Options that change on their own all the time and say nothing about what support did.
	 *
	 * @var string[]
	 */
	private static $noise = array( 'cron', 'rewrite_rules', 'recently_edited', 'active_plugins', 'uninstall_plugins', 'recently_activated', 'auto_updater.lock', 'core_updater.lock', 'can_compress_scripts', 'db_upgraded', 'theme_mods_', 'widget_', 'sidebars_widgets', 'user_count', 'helpdesk_hero_', 'new_admin_email', 'adminhash', 'admin_email_lifespan' );

	/**
	 * Last URL logged in this request (avoids duplicates).
	 *
	 * @var string
	 */
	private static $last_view = '';

	/**
	 * Register hooks. They do nothing unless the current user is a support user.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'page_view' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'page_view' ), 1 );
		add_action( 'updated_option', array( __CLASS__, 'option_updated' ), 10, 3 );
		add_action( 'added_option', array( __CLASS__, 'option_added' ), 10, 2 );
		add_action( 'deleted_option', array( __CLASS__, 'option_deleted' ) );
		add_action( 'save_post', array( __CLASS__, 'post_saved' ), 10, 3 );
		add_action( 'wp_trash_post', array( __CLASS__, 'post_trashed' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'post_deleted' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'customizer_saved' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_write' ), 10, 3 );
		add_action( 'wp_logout', array( __CLASS__, 'logout' ) );
	}

	/**
	 * Current support grant ID, or 0 when the current user is not support.
	 *
	 * @return int
	 */
	private static function grant() {
		return did_action( 'set_current_user' ) || did_action( 'init' ) ? Helpdesk_Hero_Access::current_grant_id() : 0;
	}

	/**
	 * Log a page view.
	 */
	public static function page_view() {
		$grant = self::grant();
		if ( ! $grant || ! Helpdesk_Hero_Policy::section( 'access' )['log_page_views'] || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$uri = remove_query_arg( array( '_wpnonce', '_wp_http_referer', 'token' ), $uri );
		if ( '' === $uri || $uri === self::$last_view ) {
			return;
		}
		self::$last_view = $uri;
		$method          = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
		$details         = array( 'title' => is_admin() ? self::admin_title() : '' );
		if ( 'post' === $method ) {
			// Form submissions: record which form, never the submitted values.
			$details['action'] = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		Helpdesk_Hero_Monitor::record( 'activity', 'post' === $method ? 'form_submitted' : 'page_view', Helpdesk_Hero_Redactor::text( $uri ), array_filter( $details ), $grant );
	}

	/**
	 * Admin page label from the query string.
	 *
	 * @return string
	 */
	private static function admin_title() {
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $page ? $pagenow . ' › ' . $page : (string) $pagenow;
	}

	/**
	 * Whether an option is noise.
	 *
	 * @param string $option Option.
	 * @return bool
	 */
	private static function is_noise( $option ) {
		if ( 0 === strpos( $option, '_transient' ) || 0 === strpos( $option, '_site_transient' ) ) {
			return true;
		}
		if ( in_array( $option, Helpdesk_Hero_Monitor::WATCHED_OPTIONS, true ) ) {
			return true; // Logged by the change timeline, with the support grant attached.
		}
		foreach ( self::$noise as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Value prepared for the log: secrets hidden, long values shortened.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Value.
	 * @return string
	 */
	public static function loggable( $option, $value ) {
		if ( Helpdesk_Hero_Redactor::is_secret_key( $option ) ) {
			return '[hidden]';
		}
		$text = is_scalar( $value ) || null === $value ? (string) $value : (string) wp_json_encode( $value );
		$text = Helpdesk_Hero_Redactor::text( $text );
		return strlen( $text ) > 500 ? substr( $text, 0, 500 ) . '…' : $text;
	}

	/**
	 * Option changed.
	 *
	 * @param string $option Option.
	 * @param mixed  $old    Old.
	 * @param mixed  $value  New.
	 */
	public static function option_updated( $option, $old, $value ) {
		$grant = self::grant();
		if ( ! $grant || self::is_noise( $option ) ) {
			return;
		}
		if ( is_array( $old ) && is_array( $value ) ) {
			// Only the keys that changed, so a big settings array stays readable.
			$changed = array();
			foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $value ) ) ) as $key ) {
				$a = $old[ $key ] ?? null;
				$b = $value[ $key ] ?? null;
				if ( $a !== $b ) {
					$changed[ $key ] = array(
						'from' => self::loggable( (string) $key, $a ),
						'to'   => self::loggable( (string) $key, $b ),
					);
				}
				if ( count( $changed ) >= 20 ) {
					break;
				}
			}
			if ( ! $changed ) {
				return;
			}
			$details = array( 'changes' => $changed );
		} else {
			$details = array(
				'from' => self::loggable( $option, $old ),
				'to'   => self::loggable( $option, $value ),
			);
			if ( $details['from'] === $details['to'] ) {
				return; // Saved without a real change (for example "0" and 0).
			}
		}
		Helpdesk_Hero_Monitor::record( 'activity', 'option_updated', $option, $details, $grant );
	}

	/**
	 * Option added.
	 *
	 * @param string $option Option.
	 * @param mixed  $value  Value.
	 */
	public static function option_added( $option, $value ) {
		$grant = self::grant();
		if ( $grant && ! self::is_noise( $option ) && '' !== self::loggable( $option, $value ) ) {
			Helpdesk_Hero_Monitor::record( 'activity', 'option_added', $option, array( 'to' => self::loggable( $option, $value ) ), $grant );
		}
	}

	/**
	 * Option deleted.
	 *
	 * @param string $option Option.
	 */
	public static function option_deleted( $option ) {
		$grant = self::grant();
		if ( $grant && ! self::is_noise( $option ) ) {
			Helpdesk_Hero_Monitor::record( 'activity', 'option_deleted', $option, array(), $grant );
		}
	}

	/**
	 * Post saved.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 * @param bool    $update  Update.
	 */
	public static function post_saved( $post_id, $post, $update ) {
		$grant = self::grant();
		if ( ! $grant || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		Helpdesk_Hero_Monitor::record(
			'activity',
			$update ? 'post_updated' : 'post_created',
			(string) $post_id,
			array(
				'title'  => $post->post_title,
				'type'   => $post->post_type,
				'status' => $post->post_status,
			),
			$grant
		);
	}

	/**
	 * Post trashed.
	 *
	 * @param int $post_id Post.
	 */
	public static function post_trashed( $post_id ) {
		$grant = self::grant();
		if ( $grant ) {
			Helpdesk_Hero_Monitor::record( 'activity', 'post_trashed', (string) $post_id, array( 'title' => get_the_title( $post_id ), 'type' => get_post_type( $post_id ) ), $grant );
		}
	}

	/**
	 * Post deleted.
	 *
	 * @param int $post_id Post.
	 */
	public static function post_deleted( $post_id ) {
		$grant = self::grant();
		if ( $grant && ! wp_is_post_revision( $post_id ) ) {
			Helpdesk_Hero_Monitor::record( 'activity', 'post_deleted', (string) $post_id, array( 'title' => get_the_title( $post_id ), 'type' => get_post_type( $post_id ) ), $grant );
		}
	}

	/**
	 * Customizer saved.
	 *
	 * @param WP_Customize_Manager $manager Manager.
	 */
	public static function customizer_saved( $manager ) {
		$grant = self::grant();
		if ( $grant ) {
			Helpdesk_Hero_Monitor::record( 'activity', 'customizer_saved', '', array( 'settings' => array_slice( array_keys( (array) $manager->unsanitized_post_values() ), 0, 30 ) ), $grant );
		}
	}

	/**
	 * REST API writes (block editor, site editor, plugin settings screens).
	 *
	 * @param mixed           $result  Result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rest_write( $result, $server, $request ) {
		$grant = self::grant();
		if ( $grant && 'GET' !== $request->get_method() && 0 !== strpos( $request->get_route(), '/wp/v2/posts' ) && 0 !== strpos( $request->get_route(), '/wp/v2/pages' ) ) {
			Helpdesk_Hero_Monitor::record( 'activity', 'rest_write', $request->get_method() . ' ' . $request->get_route(), array(), $grant );
		}
		return $result;
	}

	/**
	 * Support logged out.
	 *
	 * @param int $user_id User.
	 */
	public static function logout( $user_id = 0 ) {
		$grant_id = $user_id ? (int) get_user_meta( $user_id, Helpdesk_Hero_Access::META, true ) : 0;
		if ( $grant_id ) {
			Helpdesk_Hero_Monitor::record( 'access', 'support_logout', '', array(), $grant_id );
		}
	}

	/**
	 * One-line description of a log row.
	 *
	 * @param array $row Log row (details decoded).
	 * @return string
	 */
	public static function describe( array $row ) {
		$d    = (array) $row['details'];
		$obj  = (string) $row['object'];
		$name = $d['name'] ?? $obj;
		$ver  = ! empty( $d['version'] ) ? ' ' . $d['version'] : '';

		switch ( $row['action'] ) {
			case 'plugin_activated':
				/* translators: %s: plugin */
				return sprintf( __( 'Activated plugin %s', 'helpdesk-hero' ), $name . $ver );
			case 'plugin_deactivated':
				/* translators: %s: plugin */
				return sprintf( __( 'Deactivated plugin %s', 'helpdesk-hero' ), $name . $ver );
			case 'plugin_deleted':
				/* translators: %s: plugin */
				return sprintf( __( 'Deleted plugin %s', 'helpdesk-hero' ), $name );
			case 'plugin_installed':
				/* translators: %s: plugin */
				return sprintf( __( 'Installed plugin %s', 'helpdesk-hero' ), $name . $ver );
			case 'plugin_updated':
				/* translators: %s: plugin */
				return sprintf( __( 'Updated plugin %s', 'helpdesk-hero' ), $name . $ver );
			case 'theme_installed':
				/* translators: %s: theme */
				return sprintf( __( 'Installed theme %s', 'helpdesk-hero' ), $name . $ver );
			case 'theme_updated':
				/* translators: %s: theme */
				return sprintf( __( 'Updated theme %s', 'helpdesk-hero' ), $name . $ver );
			case 'theme_switched':
				/* translators: 1: new theme, 2: old theme */
				return sprintf( __( 'Switched theme to %1$s (from %2$s)', 'helpdesk-hero' ), $name . $ver, $d['from'] ?? '?' );
			case 'core_updated':
				/* translators: %s: version */
				return sprintf( __( 'Updated WordPress to %s', 'helpdesk-hero' ), $d['version'] ?? '' );
			case 'setting_changed':
			case 'option_updated':
				if ( ! empty( $d['changes'] ) ) {
					/* translators: 1: option, 2: changed keys */
					return sprintf( __( 'Changed setting %1$s (%2$s)', 'helpdesk-hero' ), $obj, implode( ', ', array_keys( $d['changes'] ) ) );
				}
				/* translators: 1: option, 2: old value, 3: new value */
				return sprintf( __( 'Changed setting %1$s from "%2$s" to "%3$s"', 'helpdesk-hero' ), $obj, self::short( $d['from'] ?? '' ), self::short( $d['to'] ?? '' ) );
			case 'option_added':
				/* translators: %s: option */
				return sprintf( __( 'Added setting %s', 'helpdesk-hero' ), $obj );
			case 'option_deleted':
				/* translators: %s: option */
				return sprintf( __( 'Deleted setting %s', 'helpdesk-hero' ), $obj );
			case 'post_created':
			case 'post_updated':
				/* translators: 1: post type, 2: title, 3: status */
				return sprintf( 'post_created' === $row['action'] ? __( 'Created %1$s "%2$s" (%3$s)', 'helpdesk-hero' ) : __( 'Edited %1$s "%2$s" (%3$s)', 'helpdesk-hero' ), $d['type'] ?? 'post', $d['title'] ?? '#' . $obj, $d['status'] ?? '' );
			case 'post_trashed':
				/* translators: 1: post type, 2: title */
				return sprintf( __( 'Moved %1$s "%2$s" to the trash', 'helpdesk-hero' ), $d['type'] ?? 'post', $d['title'] ?? '#' . $obj );
			case 'post_deleted':
				/* translators: 1: post type, 2: title */
				return sprintf( __( 'Permanently deleted %1$s "%2$s"', 'helpdesk-hero' ), $d['type'] ?? 'post', $d['title'] ?? '#' . $obj );
			case 'customizer_saved':
				return __( 'Saved changes in the Customizer', 'helpdesk-hero' );
			case 'rest_write':
				/* translators: %s: REST route */
				return sprintf( __( 'Saved data through the REST API: %s', 'helpdesk-hero' ), $obj );
			case 'page_view':
				/* translators: %s: URL */
				return sprintf( __( 'Viewed %s', 'helpdesk-hero' ), $obj );
			case 'form_submitted':
				/* translators: %s: URL */
				return sprintf( __( 'Submitted a form on %s', 'helpdesk-hero' ), $obj ) . ( ! empty( $d['action'] ) ? ' (' . $d['action'] . ')' : '' );
			case 'access_granted':
				/* translators: 1: hours, 2: role */
				return sprintf( __( 'Support access granted for %1$d hours (%2$s)', 'helpdesk-hero' ), $d['hours'] ?? 0, self::role_label( (string) ( $d['role'] ?? '' ) ) );
			case 'access_extended':
				/* translators: %d: hours */
				return sprintf( __( 'Support access extended by %d hours', 'helpdesk-hero' ), $d['hours'] ?? 0 );
			case 'extension_requested':
				/* translators: %d: hours */
				return sprintf( __( 'Support asked for %d more hours', 'helpdesk-hero' ), $d['hours'] ?? 0 ) . ( ! empty( $d['reason'] ) ? ': ' . $d['reason'] : '' );
			case 'extension_declined':
				return __( 'Extension request declined', 'helpdesk-hero' );
			case 'access_revoked':
				return __( 'Support access ended by the site owner', 'helpdesk-hero' );
			case 'access_expired':
				return __( 'Support access expired', 'helpdesk-hero' );
			case 'support_login':
				return __( 'Support logged in', 'helpdesk-hero' );
			case 'link_issued':
				/* translators: %s: agent name */
				return sprintf( __( 'New login link issued for %s', 'helpdesk-hero' ), ! empty( $d['agent'] ) ? $d['agent'] : __( 'support', 'helpdesk-hero' ) );
			case 'support_logout':
				return __( 'Support logged out', 'helpdesk-hero' );
			case 'safe_mode_on':
				$count = count( (array) ( $d['disabled'] ?? array() ) );
				/* translators: %d: number of plugins */
				return sprintf( _n( 'Troubleshooting mode on: %d plugin disabled for the support session only', 'Troubleshooting mode on: %d plugins disabled for the support session only', $count, 'helpdesk-hero' ), $count );
			case 'safe_mode_off':
				return __( 'Troubleshooting mode off', 'helpdesk-hero' );
			case 'hub_error':
				/* translators: 1: request, 2: error message */
				return sprintf( __( 'Could not reach your support team (%1$s): %2$s', 'helpdesk-hero' ), $obj, $d['message'] ?? '' );
			case 'php_fatal':
				/* translators: 1: component, 2: message */
				return sprintf( __( 'PHP fatal error in %1$s: %2$s', 'helpdesk-hero' ), $obj, $d['message'] ?? '' );
			case 'js_error':
				/* translators: 1: component, 2: message */
				return sprintf( __( 'JavaScript error in %1$s: %2$s', 'helpdesk-hero' ), $obj, $d['message'] ?? '' );
		}
		/**
		 * Filters the description of a custom log action.
		 *
		 * @param string $text Description.
		 * @param array  $row  Log row.
		 */
		return (string) apply_filters( 'helpdesk_hero_describe_log', $row['action'] . ( '' !== $obj ? ': ' . $obj : '' ), $row );
	}

	/**
	 * Short role name for a grant role key.
	 *
	 * @param string $role Role key.
	 * @return string
	 */
	public static function role_label( $role ) {
		$labels = array(
			'restricted_admin' => __( 'administrator without user management or code editing', 'helpdesk-hero' ),
			'administrator'    => __( 'full administrator', 'helpdesk-hero' ),
			'editor'           => __( 'editor', 'helpdesk-hero' ),
			'shop_manager'     => __( 'shop manager', 'helpdesk-hero' ),
		);
		return $labels[ $role ] ?? $role;
	}

	/**
	 * Shorten a value for one-line display.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function short( $text ) {
		$text = (string) $text;
		return strlen( $text ) > 60 ? substr( $text, 0, 57 ) . '…' : $text;
	}

	/**
	 * Activity for a grant as plain text (oldest first), for sending to support or summarising.
	 *
	 * @param int $grant_id Grant.
	 * @return string
	 */
	public static function grant_text( $grant_id ) {
		$rows = array_reverse(
			Helpdesk_Hero_Monitor::query(
				array(
					'grant_id' => (int) $grant_id,
					'limit'    => 500,
				)
			)
		);
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = $row['created_at'] . ' UTC  ' . self::describe( $row );
		}
		return implode( "\n", $lines );
	}
}
