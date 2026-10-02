<?php
/**
 * Temporary support access: the support user, one-time login links, expiry and limits.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * A "grant" is one period of support access. It owns a temporary user account that is deleted
 * when the grant expires or is revoked. Login links are single-use: a link opens a confirmation
 * page and only the button press (a POST) uses it up, so email security scanners that open links
 * automatically cannot burn it.
 */
final class Helpdesk_Hero_Access {

	const META         = 'helpdesk_hero_grant';
	const SUPPORTER    = 'helpdesk_hero_supporter';
	const SUPPORTER_NAME = 'helpdesk_hero_supporter_name';
	const CRON         = 'helpdesk_hero_expire_access';
	const LOGIN_ACTION = 'helpdesk_hero';
	const CAP          = 'helpdesk_hero_manage';

	/**
	 * Cached grant for the current user.
	 *
	 * @var array|null|false
	 */
	private static $current = false;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'login_form_' . self::LOGIN_ACTION, array( __CLASS__, 'login_screen' ) );
		add_filter( 'authenticate', array( __CLASS__, 'block_password_login' ), 100, 2 );
		add_filter( 'determine_current_user', array( __CLASS__, 'enforce_expiry' ), 100 );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'cookie_expiration' ), 100, 2 );
		add_filter( 'user_has_cap', array( __CLASS__, 'limit_caps' ), 100, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect' ), 100, 4 );
		add_action( self::CRON, array( __CLASS__, 'expire_due' ) );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	/**
	 * Roles a grant can use.
	 *
	 * @return array Key => label.
	 */
	public static function roles() {
		$roles = array(
			'restricted_admin' => __( 'Administrator, without user management or code editing (recommended)', 'helpdesk-hero' ),
			'administrator'    => __( 'Full administrator', 'helpdesk-hero' ),
			'editor'           => __( 'Editor (content only)', 'helpdesk-hero' ),
		);
		if ( get_role( 'shop_manager' ) ) {
			$roles['shop_manager'] = __( 'Shop manager (WooCommerce)', 'helpdesk-hero' );
		}
		/**
		 * Filters the roles that can be given to support.
		 *
		 * @param array $roles Key => label. Keys are WordPress roles, plus "restricted_admin".
		 */
		return (array) apply_filters( 'helpdesk_hero_access_roles', $roles );
	}

	/**
	 * Durations offered, in hours.
	 *
	 * @return array Hours => label.
	 */
	public static function durations() {
		return array(
			1   => __( '1 hour', 'helpdesk-hero' ),
			4   => __( '4 hours', 'helpdesk-hero' ),
			24  => __( '24 hours', 'helpdesk-hero' ),
			72  => __( '3 days', 'helpdesk-hero' ),
			168 => __( '7 days', 'helpdesk-hero' ),
			336 => __( '14 days', 'helpdesk-hero' ),
		);
	}

	/**
	 * Capabilities a restricted administrator never gets.
	 *
	 * @return string[]
	 */
	public static function restricted_caps() {
		return (array) apply_filters(
			'helpdesk_hero_restricted_caps',
			array( 'create_users', 'delete_users', 'edit_users', 'promote_users', 'remove_users', 'add_users', 'edit_plugins', 'edit_themes', 'edit_files', 'install_plugins', 'delete_plugins', 'install_themes', 'delete_themes', 'update_core', 'export', 'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins' )
		);
	}

	/**
	 * Create a grant with its temporary user and first login link.
	 *
	 * @param array $args hours, role, ticket_id, note, allow_plugins.
	 * @return array|WP_Error { grant: array, url: string }
	 */
	public static function create( array $args ) {
		global $wpdb;
		if ( 'off' === Helpdesk_Hero_Policy::section( 'access' )['mode'] ) {
			return new WP_Error( 'helpdesk_hero_policy', __( 'Your support team does not use site access.', 'helpdesk-hero' ) );
		}
		// The support team's policy decides the role, the length and plugin installs.
		$resolved = Helpdesk_Hero_Policy::resolve_access( $args );
		$hours    = $resolved['hours'];
		$role     = $resolved['role'];
		if ( 'shop_manager' === $role && ! get_role( 'shop_manager' ) ) {
			$role = 'restricted_admin';
		}

		$user_id = self::create_user( $role, sprintf( /* translators: %s: support team name */ __( '%s (temporary access)', 'helpdesk-hero' ), Helpdesk_Hero_Settings::support_name() ) );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$suffix = substr( get_userdata( $user_id )->user_login, 8 );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Helpdesk_Hero_DB::table( 'grants' ),
			array(
				'user_id'       => $user_id,
				'ticket_id'     => (int) ( $args['ticket_id'] ?? 0 ),
				'role'          => $role,
				'allow_plugins' => $resolved['allow_plugins'] ? 1 : 0,
				'expires_at'    => Helpdesk_Hero_DB::now( $hours * HOUR_IN_SECONDS ),
				'created_by'    => get_current_user_id(),
				'created_at'    => Helpdesk_Hero_DB::now(),
				'note'          => substr( sanitize_text_field( (string) ( $args['note'] ?? '' ) ), 0, 255 ),
			)
		);
		$grant_id = (int) $wpdb->insert_id;
		update_user_meta( $user_id, self::META, $grant_id );

		Helpdesk_Hero_Monitor::record(
			'access',
			'access_granted',
			'support-' . $suffix,
			array(
				'hours' => $hours,
				'role'  => $role,
			),
			$grant_id
		);

		$url = self::new_link( $grant_id );

		/**
		 * Fires after support access is granted.
		 *
		 * @param int   $grant_id Grant ID.
		 * @param array $args     Arguments.
		 */
		do_action( 'helpdesk_hero_access_granted', $grant_id, $args );

		return array(
			'grant' => self::get( $grant_id ),
			'url'   => $url,
		);
	}

	/**
	 * Create a temporary support user.
	 *
	 * @param string $role         Grant role (restricted_admin becomes an administrator limited by user_has_cap).
	 * @param string $display_name Display name.
	 * @param string $login_hint   Optional readable part of the login name (e.g. the supporter's name).
	 * @return int|WP_Error User ID.
	 */
	private static function create_user( $role, $display_name, $login_hint = '' ) {
		$suffix  = strtolower( wp_generate_password( 6, false ) );
		$hint    = substr( sanitize_user( strtolower( remove_accents( str_replace( ' ', '-', $login_hint ) ) ), true ), 0, 20 );
		$login   = 'support-' . ( '' !== $hint ? $hint . '-' : '' ) . $suffix;
		$host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$host    = $host && false !== strpos( $host, '.' ) ? $host : 'example.invalid';
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => 'helpdesk-hero-' . $suffix . '@' . $host,
				'display_name' => $display_name,
				'first_name'   => Helpdesk_Hero_Settings::support_name(),
				'role'         => 'restricted_admin' === $role ? 'administrator' : $role,
				'description'  => __( 'Temporary support account created by Helpdesk Hero. It is deleted automatically when access ends.', 'helpdesk-hero' ),
			)
		);
		if ( ! is_wp_error( $user_id ) ) {
			update_user_meta( $user_id, 'show_admin_bar_front', 'true' );
		}
		return $user_id;
	}

	/**
	 * The personal account of a named supporter under a grant, created on first use. Their
	 * name is shown on the site (user list, admin bar, activity log) instead of the team account.
	 *
	 * @param array  $grant Grant.
	 * @param string $key   Supporter ID from the hub.
	 * @param string $name  Display name chosen by the support team.
	 * @return int|WP_Error User ID.
	 */
	public static function supporter_user( array $grant, $key, $name ) {
		$key  = sanitize_key( $key );
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $key || '' === $name ) {
			return (int) $grant['user_id'];
		}
		foreach ( self::users_for_grant( (int) $grant['id'] ) as $user_id ) {
			if ( get_user_meta( $user_id, self::SUPPORTER, true ) === $key ) {
				if ( get_user_meta( $user_id, self::SUPPORTER_NAME, true ) !== $name ) {
					wp_update_user(
						array(
							'ID'           => $user_id,
							/* translators: 1: supporter name, 2: support team name */
							'display_name' => sprintf( __( '%1$s (%2$s)', 'helpdesk-hero' ), $name, Helpdesk_Hero_Settings::support_name() ),
						)
					);
					update_user_meta( $user_id, self::SUPPORTER_NAME, $name );
				}
				return $user_id;
			}
		}
		/* translators: 1: supporter name, 2: support team name */
		$user_id = self::create_user( (string) $grant['role'], sprintf( __( '%1$s (%2$s)', 'helpdesk-hero' ), $name, Helpdesk_Hero_Settings::support_name() ), $name );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		update_user_meta( $user_id, self::META, (int) $grant['id'] );
		update_user_meta( $user_id, self::SUPPORTER, $key );
		update_user_meta( $user_id, self::SUPPORTER_NAME, $name );
		return $user_id;
	}

	/**
	 * Every account created under a grant: the team account and supporters' personal accounts.
	 *
	 * @param int $grant_id Grant.
	 * @return int[]
	 */
	public static function users_for_grant( $grant_id ) {
		return array_map(
			'intval',
			get_users(
				array(
					'meta_key'   => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => (int) $grant_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'fields'     => 'ID',
					'blog_id'    => get_current_blog_id(),
				)
			)
		);
	}

	/**
	 * Issue a fresh single-use login link. Any earlier unused link stops working.
	 *
	 * @param int $grant_id Grant.
	 * @param int $user_id  Account the link logs in to (a supporter's); 0 for the grant's own account.
	 * @return string|WP_Error URL.
	 */
	public static function new_link( $grant_id, $user_id = 0 ) {
		global $wpdb;
		$grant = self::get( $grant_id );
		if ( ! $grant || ! self::is_active( $grant ) ) {
			return new WP_Error( 'helpdesk_hero_inactive', __( 'Support access is not active.', 'helpdesk-hero' ) );
		}
		$secret = bin2hex( random_bytes( 24 ) );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Helpdesk_Hero_DB::table( 'grants' ),
			array(
				'token_hash'       => hash( 'sha256', $secret ),
				'token_created_at' => Helpdesk_Hero_DB::now(),
				'token_used_at'    => null,
				'token_user_id'    => (int) $user_id,
			),
			array( 'id' => $grant_id )
		);
		return add_query_arg(
			array(
				'action' => self::LOGIN_ACTION,
				'token'  => $grant_id . '.' . $secret,
			),
			wp_login_url()
		);
	}

	/**
	 * A grant row.
	 *
	 * @param int $grant_id ID.
	 * @return array|null
	 */
	public static function get( $grant_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Helpdesk_Hero_DB::table( 'grants' ), $grant_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $row && $row['extension_request'] ) {
			$row['extension_request'] = json_decode( $row['extension_request'], true );
		}
		return $row ? $row : null;
	}

	/**
	 * Grants, newest first.
	 *
	 * @param bool $active_only Only active ones.
	 * @param int  $limit       Limit.
	 * @return array[]
	 */
	public static function all( $active_only = false, $limit = 50 ) {
		global $wpdb;
		if ( $active_only ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE revoked_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT %d', Helpdesk_Hero_DB::table( 'grants' ), Helpdesk_Hero_DB::now(), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', Helpdesk_Hero_DB::table( 'grants' ), $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		foreach ( $rows as &$row ) {
			$row['extension_request'] = $row['extension_request'] ? json_decode( $row['extension_request'], true ) : null;
		}
		return $rows;
	}

	/**
	 * The active grant for a ticket.
	 *
	 * @param int $ticket_id Ticket.
	 * @return array|null
	 */
	public static function for_ticket( $ticket_id ) {
		foreach ( self::all( true ) as $grant ) {
			if ( (int) $grant['ticket_id'] === (int) $ticket_id ) {
				return $grant;
			}
		}
		return null;
	}

	/**
	 * Whether a grant is usable now.
	 *
	 * @param array $grant Grant.
	 * @return bool
	 */
	public static function is_active( $grant ) {
		return $grant && empty( $grant['revoked_at'] ) && strtotime( $grant['expires_at'] . ' UTC' ) > time();
	}

	/**
	 * Grant ID of the current user, or 0.
	 *
	 * @return int
	 */
	public static function current_grant_id() {
		$grant = self::current_grant();
		return $grant ? (int) $grant['id'] : 0;
	}

	/**
	 * Grant of the current user.
	 *
	 * @return array|null
	 */
	public static function current_grant() {
		static $for_user = null;
		if ( false === self::$current || get_current_user_id() !== $for_user ) {
			$for_user       = get_current_user_id();
			$user_id        = $for_user;
			$grant_id       = $user_id ? (int) get_user_meta( $user_id, self::META, true ) : 0;
			self::$current  = $grant_id ? self::get( $grant_id ) : null;
		}
		return self::$current;
	}

	/**
	 * Whether a user is a temporary support user.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_support_user( $user_id ) {
		return $user_id && (int) get_user_meta( $user_id, self::META, true ) > 0;
	}

	/**
	 * Extend a grant.
	 *
	 * @param int $grant_id Grant.
	 * @param int $hours    Hours to add (from now, or from the current expiry if later).
	 * @return bool
	 */
	public static function extend( $grant_id, $hours ) {
		global $wpdb;
		$grant = self::get( $grant_id );
		if ( ! $grant || ! empty( $grant['revoked_at'] ) ) {
			return false;
		}
		$base    = max( time(), strtotime( $grant['expires_at'] . ' UTC' ) );
		$ceiling = time() + (int) Helpdesk_Hero_Policy::section( 'access' )['max_hours'] * HOUR_IN_SECONDS;
		$new     = min( $ceiling, $base + max( 1, (int) $hours ) * HOUR_IN_SECONDS );
		if ( $new <= strtotime( $grant['expires_at'] . ' UTC' ) ) {
			return false;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Helpdesk_Hero_DB::table( 'grants' ),
			array(
				'expires_at'        => gmdate( 'Y-m-d H:i:s', $new ),
				'extension_request' => null,
			),
			array( 'id' => $grant_id )
		);
		Helpdesk_Hero_Monitor::record( 'access', 'access_extended', '', array( 'hours' => (int) $hours ), $grant_id );
		do_action( 'helpdesk_hero_access_changed', $grant_id, 'extended' );
		return true;
	}

	/**
	 * Store support's request for more time; the site owner approves or declines it.
	 *
	 * @param int    $grant_id Grant.
	 * @param int    $hours    Hours requested.
	 * @param string $reason   Reason.
	 * @param string $by       Who asked.
	 * @return bool
	 */
	public static function request_extension( $grant_id, $hours, $reason = '', $by = '' ) {
		global $wpdb;
		$grant = self::get( $grant_id );
		if ( ! $grant || ! empty( $grant['revoked_at'] ) ) {
			return false;
		}
		if ( 'auto' === Helpdesk_Hero_Policy::section( 'access' )['extension'] ) {
			// The support team's policy grants extensions automatically, up to its maximum length.
			Helpdesk_Hero_Monitor::record( 'access', 'extension_requested', '', array( 'hours' => (int) $hours, 'reason' => sanitize_textarea_field( $reason ) ), $grant_id );
			return self::extend( $grant_id, (int) $hours );
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Helpdesk_Hero_DB::table( 'grants' ),
			array(
				'extension_request' => wp_json_encode(
					array(
						'hours'        => max( 1, min( 24 * 14, (int) $hours ) ),
						'reason'       => sanitize_textarea_field( $reason ),
						'by'           => sanitize_text_field( $by ),
						'requested_at' => Helpdesk_Hero_DB::now(),
					)
				),
			),
			array( 'id' => $grant_id )
		);
		Helpdesk_Hero_Monitor::record( 'access', 'extension_requested', '', array( 'hours' => (int) $hours, 'reason' => sanitize_textarea_field( $reason ) ), $grant_id );

		wp_mail(
			Helpdesk_Hero_Settings::notify_email(),
			/* translators: %s: site name */
			sprintf( __( '[%s] Support asks for more time', 'helpdesk-hero' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: support name, 2: hours, 3: reason, 4: URL */
				__( "%1\$s asked to extend their access to your site by %2\$d hours.\n\nReason: %3\$s\n\nApprove or decline: %4\$s", 'helpdesk-hero' ),
				Helpdesk_Hero_Settings::support_name(),
				(int) $hours,
				'' !== $reason ? $reason : '—',
				admin_url( 'admin.php?page=helpdesk-hero#/access' )
			)
		);
		return true;
	}

	/**
	 * Decline a pending extension request.
	 *
	 * @param int $grant_id Grant.
	 */
	public static function decline_extension( $grant_id ) {
		global $wpdb;
		$wpdb->update( Helpdesk_Hero_DB::table( 'grants' ), array( 'extension_request' => null ), array( 'id' => (int) $grant_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		Helpdesk_Hero_Monitor::record( 'access', 'extension_declined', '', array(), $grant_id );
		do_action( 'helpdesk_hero_access_changed', $grant_id, 'extension_declined' );
	}

	/**
	 * End a grant now and delete its user. Content the user created is given to the person who granted access.
	 *
	 * @param int    $grant_id Grant.
	 * @param string $reason   revoked | expired.
	 */
	public static function revoke( $grant_id, $reason = 'revoked' ) {
		global $wpdb;
		$grant = self::get( $grant_id );
		if ( ! $grant ) {
			return;
		}
		if ( empty( $grant['revoked_at'] ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				Helpdesk_Hero_DB::table( 'grants' ),
				array(
					'revoked_at'        => Helpdesk_Hero_DB::now(),
					'token_hash'        => '',
					'extension_request' => null,
				),
				array( 'id' => $grant_id )
			);
			Helpdesk_Hero_Monitor::record( 'access', 'expired' === $reason ? 'access_expired' : 'access_revoked', '', array(), $grant_id );
		}
		// Every account created for this access goes: the team account and each supporter's own.
		$user_ids = array_unique( array_filter( array_merge( array( (int) $grant['user_id'] ), self::users_for_grant( (int) $grant_id ) ) ) );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$reassign = (int) $grant['created_by'] && get_userdata( (int) $grant['created_by'] ) ? (int) $grant['created_by'] : null;
		foreach ( $user_ids as $user_id ) {
			if ( ! get_userdata( $user_id ) ) {
				continue;
			}
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				// Reassign content first: wpmu_delete_user() would otherwise delete it.
				if ( $reassign ) {
					remove_user_from_blog( $user_id, get_current_blog_id(), $reassign );
				}
				wpmu_delete_user( $user_id );
			} else {
				wp_delete_user( $user_id, $reassign );
			}
		}
		self::$current = false;
		do_action( 'helpdesk_hero_access_changed', $grant_id, $reason );
	}

	/**
	 * Cron: end grants that ran out.
	 */
	public static function expire_due() {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE revoked_at IS NULL AND expires_at <= %s', Helpdesk_Hero_DB::table( 'grants' ), Helpdesk_Hero_DB::now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $ids as $id ) {
			self::revoke( (int) $id, 'expired' );
		}
	}

	/**
	 * Login link screen: GET shows a confirmation button, POST uses up the link and logs in.
	 */
	public static function login_screen() {
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the single-use token is the credential.
		$grant = self::grant_for_token( $token );
		$error = null;

		if ( is_wp_error( $grant ) ) {
			$error = $grant;
		} elseif ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( ! isset( $_POST['helpdesk_hero_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['helpdesk_hero_nonce'] ) ), 'helpdesk_hero_login_' . $grant['id'] ) ) {
				$error = new WP_Error( 'helpdesk_hero_nonce', __( 'This page expired. Open the link again.', 'helpdesk-hero' ) );
			} else {
				self::consume_and_login( $grant );
			}
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		login_header( __( 'Support login', 'helpdesk-hero' ), '', $error );

		if ( ! $error ) {
			$expires = strtotime( $grant['expires_at'] . ' UTC' );
			echo '<form method="post" action="' . esc_url( add_query_arg( array( 'action' => self::LOGIN_ACTION ), wp_login_url() ) ) . '">';
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: site name, 2: time left */
					__( 'Log in to %1$s with temporary support access. Access ends in %2$s.', 'helpdesk-hero' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					human_time_diff( time(), $expires )
				)
			) . '</p>';
			echo '<p style="margin:12px 0">' . esc_html__( 'This link works once. Everything you do while logged in is recorded and shown to the site owner.', 'helpdesk-hero' ) . '</p>';
			echo '<input type="hidden" name="token" value="' . esc_attr( $token ) . '">';
			wp_nonce_field( 'helpdesk_hero_login_' . $grant['id'], 'helpdesk_hero_nonce' );
			echo '<p class="submit"><button type="submit" class="button button-primary button-large">' . esc_html__( 'Log in as support', 'helpdesk-hero' ) . '</button></p>';
			echo '</form>';
		}
		login_footer();
		exit;
	}

	/**
	 * Validate a login token.
	 *
	 * @param string $token "{grant_id}.{secret}".
	 * @return array|WP_Error Grant.
	 */
	public static function grant_for_token( $token ) {
		$invalid = new WP_Error( 'helpdesk_hero_link', __( 'This support login link is not valid. It may have been used already, replaced by a newer link, or access may have ended. Ask the site owner for a new link.', 'helpdesk-hero' ) );
		if ( ! preg_match( '/^(\d+)\.([a-f0-9]{48})$/', (string) $token, $m ) ) {
			return $invalid;
		}
		$grant = self::get( (int) $m[1] );
		if ( ! $grant || '' === $grant['token_hash'] || ! empty( $grant['token_used_at'] ) || ! hash_equals( $grant['token_hash'], hash( 'sha256', $m[2] ) ) ) {
			return $invalid;
		}
		if ( ! self::is_active( $grant ) || ! get_userdata( self::login_user_id( $grant ) ) ) {
			return new WP_Error( 'helpdesk_hero_expired', __( 'Support access to this site has ended.', 'helpdesk-hero' ) );
		}
		return $grant;
	}

	/**
	 * The account a grant's current link logs in to.
	 *
	 * @param array $grant Grant.
	 * @return int User ID.
	 */
	private static function login_user_id( array $grant ) {
		$user_id = (int) ( $grant['token_user_id'] ?? 0 );
		return $user_id && (int) get_user_meta( $user_id, self::META, true ) === (int) $grant['id'] ? $user_id : (int) $grant['user_id'];
	}

	/**
	 * Mark the link used and log in.
	 *
	 * @param array $grant Grant.
	 */
	private static function consume_and_login( array $grant ) {
		global $wpdb;
		// Atomic: only one request can flip token_used_at from NULL.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET token_used_at = %s WHERE id = %d AND token_used_at IS NULL AND token_hash = %s', Helpdesk_Hero_DB::table( 'grants' ), Helpdesk_Hero_DB::now(), $grant['id'], $grant['token_hash'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( 1 !== (int) $updated ) {
			wp_die( esc_html__( 'This support login link was already used.', 'helpdesk-hero' ), '', array( 'response' => 403 ) );
		}
		$user = get_userdata( self::login_user_id( $grant ) );
		wp_clear_auth_cookie();
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		Helpdesk_Hero_Monitor::record( 'access', 'support_login', $user->user_login, array( 'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '' ), (int) $grant['id'] );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's login hook, fired as wp_signon() does.
		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Tell the site owner that support logged in.
	 *
	 * @param string  $login User login.
	 * @param WP_User $user  User.
	 */
	public static function on_login( $login, $user ) {
		if ( ! self::is_support_user( $user->ID ) ) {
			return;
		}
		$grant_id = (int) get_user_meta( $user->ID, self::META, true );
		do_action( 'helpdesk_hero_access_changed', $grant_id, 'login' );
		wp_mail(
			Helpdesk_Hero_Settings::notify_email(),
			/* translators: %s: site name */
			sprintf( __( '[%s] Support logged in to your site', 'helpdesk-hero' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: support name, 2: IP, 3: URL */
				__( "%1\$s just logged in using the temporary access you granted (IP %2\$s).\n\nSee what they do, or end access now: %3\$s", 'helpdesk-hero' ),
				Helpdesk_Hero_Settings::support_name(),
				Helpdesk_Hero_Monitor::ip(),
				admin_url( 'admin.php?page=helpdesk-hero#/access' )
			)
		);
	}

	/**
	 * Support users can only log in with a link, never a password.
	 *
	 * @param WP_User|WP_Error|null $user     User.
	 * @param string                $username Username.
	 * @return WP_User|WP_Error|null
	 */
	public static function block_password_login( $user, $username ) {
		if ( $user instanceof WP_User && self::is_support_user( $user->ID ) && '' !== (string) $username ) {
			return new WP_Error( 'helpdesk_hero_password', __( 'Temporary support accounts can only log in with a support login link.', 'helpdesk-hero' ) );
		}
		return $user;
	}

	/**
	 * Treat expired or revoked support users as logged out, even before cron deletes them.
	 *
	 * @param int|false $user_id User.
	 * @return int|false
	 */
	public static function enforce_expiry( $user_id ) {
		if ( ! $user_id ) {
			return $user_id;
		}
		$grant_id = (int) get_user_meta( $user_id, self::META, true );
		if ( ! $grant_id ) {
			return $user_id;
		}
		$grant = self::get( $grant_id );
		if ( ! self::is_active( $grant ) ) {
			if ( $grant && empty( $grant['revoked_at'] ) ) {
				wp_schedule_single_event( time(), self::CRON ); // Duplicates within 10 minutes are ignored by WordPress.
			}
			return false;
		}
		return $user_id;
	}

	/**
	 * Login cookies for support users never outlive the grant.
	 *
	 * @param int $length  Seconds.
	 * @param int $user_id User.
	 * @return int
	 */
	public static function cookie_expiration( $length, $user_id ) {
		$grant_id = (int) get_user_meta( $user_id, self::META, true );
		if ( $grant_id ) {
			$grant = self::get( $grant_id );
			if ( $grant ) {
				return max( 60, min( (int) $length, strtotime( $grant['expires_at'] . ' UTC' ) - time() ) );
			}
		}
		return $length;
	}

	/**
	 * Remove restricted capabilities from restricted administrators.
	 *
	 * @param array   $allcaps All caps.
	 * @param array   $caps    Required caps.
	 * @param array   $args    Args.
	 * @param WP_User $user    User.
	 * @return array
	 */
	public static function limit_caps( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return $allcaps;
		}
		$grant_id = (int) get_user_meta( $user->ID, self::META, true );
		if ( ! $grant_id ) {
			return $allcaps;
		}
		static $grants = array();
		if ( ! array_key_exists( $grant_id, $grants ) ) {
			$grants[ $grant_id ] = self::get( $grant_id );
		}
		$grant = $grants[ $grant_id ];
		if ( ! $grant || 'restricted_admin' !== $grant['role'] ) {
			return $allcaps;
		}
		foreach ( self::restricted_caps() as $cap ) {
			if ( $grant['allow_plugins'] && in_array( $cap, array( 'install_plugins', 'delete_plugins' ), true ) ) {
				continue;
			}
			$allcaps[ $cap ] = false;
		}
		return $allcaps;
	}

	/**
	 * Support users cannot deactivate or delete Helpdesk Hero (that would stop the activity log),
	 * edit other users, or change support access.
	 *
	 * @param string[] $caps    Primitive caps.
	 * @param string   $cap     Meta cap.
	 * @param int      $user_id User.
	 * @param array    $args    Args.
	 * @return string[]
	 */
	public static function protect( $caps, $cap, $user_id, $args ) {
		if ( ! self::is_support_user( $user_id ) ) {
			return 'helpdesk_hero_manage' === $cap ? array( 'manage_options' ) : $caps;
		}
		$own = plugin_basename( HELPDESK_HERO_FILE );
		if ( in_array( $cap, array( 'deactivate_plugin', 'delete_plugin' ), true ) && isset( $args[0] ) && $own === $args[0] ) {
			return array( 'do_not_allow' );
		}
		if ( in_array( $cap, array( 'delete_plugins', 'deactivate_plugins' ), true ) && isset( $_REQUEST['checked'] ) && in_array( $own, (array) wp_unslash( $_REQUEST['checked'] ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return array( 'do_not_allow' );
		}
		if ( in_array( $cap, array( 'edit_user', 'delete_user', 'promote_user', 'remove_user' ), true ) ) {
			return array( 'do_not_allow' );
		}
		if ( 'helpdesk_hero_manage' === $cap ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}
}
