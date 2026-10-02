<?php
/**
 * Admin screen.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Get Help page, the admin bar shortcut, and loads the dashboard app.
 */
final class Helpdesk_Hero_Admin {

	const SLUG = 'helpdesk-hero';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_filter( 'plugin_action_links_' . plugin_basename( HELPDESK_HERO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu with an unread badge.
	 */
	public static function menu() {
		if ( Helpdesk_Hero_Access::current_grant_id() ) {
			// Support never uses the customer's help center: no tickets, no replies on their behalf.
			// They get a read-only page about the current session instead.
			self::$hook = add_menu_page( __( 'Support session', 'helpdesk-hero' ), __( 'Support session', 'helpdesk-hero' ), 'read', self::SLUG, array( __CLASS__, 'render' ), self::menu_icon(), 3 );
			return;
		}
		$unread     = Helpdesk_Hero_Tickets::unread_count();
		$badge      = $unread ? ' <span class="awaiting-mod count-' . (int) $unread . '"><span class="pending-count">' . (int) $unread . '</span></span>' : '';
		$branding   = (array) Helpdesk_Hero_Settings::get( 'branding' );
		$title      = ! empty( $branding['center'] ) ? $branding['center'] : __( 'Get Help', 'helpdesk-hero' );
		self::$hook = add_menu_page( $title, $title . $badge, Helpdesk_Hero_Access::CAP, self::SLUG, array( __CLASS__, 'render' ), self::menu_icon(), 3 );
	}

	/**
	 * Mount point for the app.
	 */
	public static function render() {
		echo '<div id="hdh-root" class="hdh-root"><div class="hdh-boot" role="status">' . esc_html__( 'Loading…', 'helpdesk-hero' ) . '</div></div>';
	}

	/**
	 * Whether the current screen is one of ours.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private static function ours( $hook ) {
		return ( self::$hook && $hook === self::$hook ) || ( Helpdesk_Hero_Safe_Mode::$hook && $hook === Helpdesk_Hero_Safe_Mode::$hook );
	}

	/**
	 * Full-bleed layout on our pages.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && self::ours( $screen->id ) ? $classes . ' hdh-app' : $classes;
	}

	/**
	 * "Get help" in the admin bar, carrying the current page's URL.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( Helpdesk_Hero_Access::CAP ) || ! Helpdesk_Hero_Settings::is_connected() ) {
			return;
		}
		$current = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
		$bar->add_node(
			array(
				'id'     => 'helpdesk-hero-help',
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-icon dashicons dashicons-sos" style="top:2px"></span><span class="ab-label">' . esc_html( self::center_name() ) . '</span>',
				'href'   => admin_url( 'admin.php?page=' . self::SLUG . '#/new?page_url=' . rawurlencode( $current ) ),
				'meta'   => array( 'title' => __( 'Open a support ticket about this page', 'helpdesk-hero' ) ),
			)
		);
	}

	/**
	 * Enqueue the app on our pages.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( ! self::ours( $hook ) ) {
			return;
		}
		$asset_file = HELPDESK_HERO_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;
		wp_enqueue_script( 'helpdesk-hero-admin', HELPDESK_HERO_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_enqueue_style( 'helpdesk-hero-admin', HELPDESK_HERO_URL . 'build/style-index.css', array(), $asset['version'] );
		wp_style_add_data( 'helpdesk-hero-admin', 'rtl', 'replace' );
		wp_set_script_translations( 'helpdesk-hero-admin', 'helpdesk-hero' );

		$user = wp_get_current_user();
		wp_add_inline_script(
			'helpdesk-hero-admin',
			'window.hdhBoot = ' . wp_json_encode(
				array(
					'version'     => HELPDESK_HERO_VERSION,
					'view'        => $hook === Helpdesk_Hero_Safe_Mode::$hook ? 'troubleshoot' : 'app',
					'adminUrl'    => admin_url(),
					'siteName'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					'homeUrl'     => home_url( '/' ),
					'userName'    => $user->display_name,
					'userEmail'   => $user->user_email,
					'connected'   => Helpdesk_Hero_Settings::is_connected(),
					'supportName' => Helpdesk_Hero_Settings::support_name(),
					'centerName'  => ! empty( Helpdesk_Hero_Settings::get( 'branding' )['center'] ) ? Helpdesk_Hero_Settings::get( 'branding' )['center'] : __( 'Get Help', 'helpdesk-hero' ),
					'branding'    => (array) Helpdesk_Hero_Settings::get( 'branding' ),
					'isSupport'   => Helpdesk_Hero_Access::current_grant_id() > 0,
					'aiEnabled'   => Helpdesk_Hero_AI::available(),
					'localAi'     => Helpdesk_Hero_AI::local_ai(),
					'gmtOffset'   => (float) get_option( 'gmt_offset' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Plugins screen link.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html( Helpdesk_Hero_Settings::is_connected() ? __( 'Get Help', 'helpdesk-hero' ) : __( 'Connect', 'helpdesk-hero' ) ) . '</a>' );
		return $links;
	}

	/**
	 * Menu icon (a life ring) as a data URI SVG.
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" fill-rule="evenodd" d="M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16Zm3.9 10.5 2 2a6 6 0 0 0 0-9l-2 2a3 3 0 0 1 0 5Zm-1.4 1.4a3 3 0 0 1-5 0l-2 2a6 6 0 0 0 9 0l-2-2ZM6.1 12.5a3 3 0 0 1 0-5l-2-2a6 6 0 0 0 0 9l2-2Zm1.4-6.4a3 3 0 0 1 5 0l2-2a6 6 0 0 0-9 0l2 2ZM10 8.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * The help center's name: the support team's white label name, or "Get Help".
	 *
	 * @return string
	 */
	public static function center_name() {
		$branding = (array) Helpdesk_Hero_Settings::get( 'branding' );
		return ! empty( $branding['center'] ) ? (string) $branding['center'] : __( 'Get Help', 'helpdesk-hero' );
	}
}
