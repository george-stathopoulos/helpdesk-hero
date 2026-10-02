<?php
/**
 * Messages from support, and access status in the admin bar.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows support's messages as dashboard notices, pending extension requests with approve/decline
 * buttons, and a clear "support access is active" indicator in the admin bar.
 */
final class Helpdesk_Hero_Notices {

	const OPTION = 'helpdesk_hero_notices';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_notices', array( __CLASS__, 'support_session' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
		add_action( 'admin_post_helpdesk_hero_dismiss', array( __CLASS__, 'dismiss' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'bar_style' ) );
	}

	/**
	 * Store a notice from support.
	 *
	 * @param array $notice title, body, level, ticket_id, action.
	 */
	public static function add( array $notice ) {
		$all   = self::all();
		$all[] = array_merge(
			$notice,
			array(
				'id'        => wp_generate_password( 10, false ),
				'created'   => time(),
				'dismissed' => false,
			)
		);
		update_option( self::OPTION, array_slice( $all, -30 ), false );
		wp_mail(
			Helpdesk_Hero_Settings::notify_email(),
			/* translators: 1: support name, 2: title */
			sprintf( __( 'Message from %1$s: %2$s', 'helpdesk-hero' ), Helpdesk_Hero_Settings::support_name(), $notice['title'] ),
			$notice['body'] . "\n\n" . admin_url()
		);
	}

	/**
	 * All notices.
	 *
	 * @return array[]
	 */
	public static function all() {
		$all = get_option( self::OPTION, array() );
		return is_array( $all ) ? $all : array();
	}

	/**
	 * Dismiss a notice.
	 */
	public static function dismiss() {
		check_admin_referer( 'helpdesk_hero_dismiss' );
		if ( ! current_user_can( Helpdesk_Hero_Access::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'helpdesk-hero' ) );
		}
		$id  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$all = self::all();
		foreach ( $all as &$notice ) {
			if ( $notice['id'] === $id ) {
				$notice['dismissed'] = true;
			}
		}
		update_option( self::OPTION, $all, false );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Admin notices: messages from support and extension requests.
	 */
	public static function render() {
		if ( ! current_user_can( Helpdesk_Hero_Access::CAP ) ) {
			return;
		}
		$brand = Helpdesk_Hero_Settings::support_name();
		foreach ( self::all() as $notice ) {
			if ( $notice['dismissed'] ) {
				continue;
			}
			$level = in_array( $notice['level'], array( 'info', 'success', 'warning', 'error' ), true ) ? $notice['level'] : 'info';
			echo '<div class="notice notice-' . esc_attr( $level ) . ' helpdesk-hero-notice">';
			echo '<p><strong>' . esc_html( sprintf( /* translators: %s: support name */ __( 'Message from %s', 'helpdesk-hero' ), $brand ) ) . ( $notice['title'] ? ': ' . esc_html( $notice['title'] ) : '' ) . '</strong></p>';
			if ( $notice['body'] ) {
				echo wp_kses_post( wpautop( esc_html( $notice['body'] ) ) );
			}
			echo '<p>';
			if ( ! empty( $notice['action']['url'] ) ) {
				echo '<a class="button button-primary" href="' . esc_url( $notice['action']['url'] ) . '">' . esc_html( $notice['action']['label'] ) . '</a> ';
			}
			if ( ! empty( $notice['ticket_id'] ) ) {
				echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=helpdesk-hero#/ticket/' . (int) $notice['ticket_id'] ) ) . '">' . esc_html__( 'Open ticket', 'helpdesk-hero' ) . '</a> ';
			}
			echo '<a class="button-link" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=helpdesk_hero_dismiss&id=' . rawurlencode( $notice['id'] ) ), 'helpdesk_hero_dismiss' ) ) . '">' . esc_html__( 'Dismiss', 'helpdesk-hero' ) . '</a>';
			echo '</p></div>';
		}

		foreach ( Helpdesk_Hero_Access::all( true ) as $grant ) {
			if ( empty( $grant['extension_request'] ) ) {
				continue;
			}
			$req = $grant['extension_request'];
			echo '<div class="notice notice-warning helpdesk-hero-notice"><p><strong>';
			/* translators: 1: support name, 2: hours */
			echo esc_html( sprintf( __( '%1$s asks for %2$d more hours of access.', 'helpdesk-hero' ), $brand, (int) $req['hours'] ) );
			echo '</strong>';
			if ( ! empty( $req['reason'] ) ) {
				echo ' ' . esc_html( $req['reason'] );
			}
			echo '</p><p>';
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=helpdesk-hero#/access' ) ) . '">' . esc_html__( 'Review request', 'helpdesk-hero' ) . '</a>';
			echo '</p></div>';
		}
	}

	/**
	 * Admin bar: active access for site owners; session time left for support.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$grant = Helpdesk_Hero_Access::current_grant();
		if ( $grant ) {
			$bar->add_node(
				array(
					'id'    => 'helpdesk-hero-session',
					'title' => '<span class="hh-bar hh-bar--support">' . esc_html(
						sprintf(
							/* translators: %s: time left */
							__( 'Support session · %s left', 'helpdesk-hero' ),
							human_time_diff( time(), strtotime( $grant['expires_at'] . ' UTC' ) )
						)
					) . '</span>',
					'meta'  => array( 'title' => __( 'Everything you do is recorded and shown to the site owner.', 'helpdesk-hero' ) ),
				)
			);
			return;
		}
		if ( ! current_user_can( Helpdesk_Hero_Access::CAP ) ) {
			return;
		}
		$active = Helpdesk_Hero_Access::all( true );
		if ( $active ) {
			$bar->add_node(
				array(
					'id'    => 'helpdesk-hero-access',
					'title' => '<span class="hh-bar">' . esc_html(
						1 === count( $active )
							? __( 'Support access active', 'helpdesk-hero' )
							/* translators: %d: number of active grants (always more than one) */
							: sprintf( _n( '%d support access active', '%d support accesses active', count( $active ), 'helpdesk-hero' ), count( $active ) )
					) . '</span>',
					'href'  => admin_url( 'admin.php?page=helpdesk-hero#/access' ),
				)
			);
		}
	}

	/**
	 * Admin bar badge style.
	 */
	public static function bar_style() {
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		wp_register_style( 'helpdesk-hero-bar', false, array(), HELPDESK_HERO_VERSION );
		wp_enqueue_style( 'helpdesk-hero-bar' );
		wp_add_inline_style( 'helpdesk-hero-bar', '#wpadminbar .hh-bar{background:#dba617;color:#1d2327;padding:2px 8px;border-radius:3px;font-weight:600}#wpadminbar .hh-bar--support{background:#2271b1;color:#fff}' );
	}

	/**
	 * On every admin screen during a support session: you're working on someone else's site, and
	 * what the site owner will see of it.
	 */
	public static function support_session() {
		$grant = Helpdesk_Hero_Access::current_grant();
		if ( ! $grant ) {
			return;
		}
		$access  = Helpdesk_Hero_Policy::section( 'access' );
		$logged  = ! empty( $access['log_page_views'] )
			? __( 'pages you visit, settings you change, plugins and themes you switch on or off, and content you edit', 'helpdesk-hero' )
			: __( 'settings you change, plugins and themes you switch on or off, and content you edit', 'helpdesk-hero' );
		echo '<div class="notice notice-info hh-session-notice"><p><strong>';
		echo esc_html(
			sprintf(
				/* translators: 1: site name, 2: support team */
				__( 'You are remotely accessing %1$s as %2$s support.', 'helpdesk-hero' ),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				Helpdesk_Hero_Settings::support_name()
			)
		);
		echo '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: what is logged, 2: time left */
				__( 'Under your team’s policy, the site owner sees a log of %1$s. Access ends in %2$s. Reply to the customer from your support hub, not from here.', 'helpdesk-hero' ),
				$logged,
				human_time_diff( time(), strtotime( $grant['expires_at'] . ' UTC' ) )
			)
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Helpdesk_Hero_Admin::SLUG ) ) . '">' . esc_html__( 'Session details', 'helpdesk-hero' ) . '</a>';
		echo '</p></div>';
	}
}
