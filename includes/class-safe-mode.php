<?php
/**
 * Troubleshooting mode: disable plugins for one browser session only.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets support (or the site owner) switch off plugins, or use a default theme, for their own
 * browser session only, to find conflicts without changing what visitors see. Needs a small
 * must-use plugin, which is copied in when troubleshooting starts and removed when the last
 * session ends.
 */
final class Helpdesk_Hero_Safe_Mode {

	const OPTION = 'helpdesk_hero_troubleshoot';
	const COOKIE = 'helpdesk_hero_troubleshoot';
	const PAGE   = 'helpdesk-hero-troubleshoot';
	const MU     = 'helpdesk-hero-troubleshoot.php';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	public static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_action( 'helpdesk_hero_access_changed', array( __CLASS__, 'on_access_changed' ), 10, 2 );
	}

	/**
	 * Who may use troubleshooting mode.
	 *
	 * @return bool
	 */
	public static function can_use() {
		if ( Helpdesk_Hero_Access::current_grant_id() > 0 ) {
			return (bool) Helpdesk_Hero_Policy::section( 'access' )['troubleshooting'];
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether this request runs in troubleshooting mode.
	 *
	 * @return bool
	 */
	public static function is_on() {
		return defined( 'HELPDESK_HERO_TROUBLESHOOTING' ) && HELPDESK_HERO_TROUBLESHOOTING;
	}

	/**
	 * Tools > Troubleshoot.
	 */
	public static function menu() {
		if ( self::can_use() ) {
			self::$hook = add_management_page( __( 'Troubleshooting mode', 'helpdesk-hero' ), __( 'Troubleshoot', 'helpdesk-hero' ), 'read', self::PAGE, array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Admin bar indicator.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( self::is_on() && self::can_use() ) {
			$bar->add_node(
				array(
					'id'    => 'helpdesk-hero-troubleshoot',
					'title' => '<span style="background:#b32d2e;color:#fff;padding:2px 8px;border-radius:3px">' . esc_html__( 'Troubleshooting mode', 'helpdesk-hero' ) . '</span>',
					'href'  => admin_url( 'tools.php?page=' . self::PAGE ),
				)
			);
		}
	}

	/**
	 * Page: mount point for the app's troubleshooting view.
	 */
	public static function render() {
		if ( ! self::can_use() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'helpdesk-hero' ) );
		}
		echo '<div id="hdh-root" class="hdh-root" data-view="troubleshoot"><div class="hdh-boot" role="status">' . esc_html__( 'Loading…', 'helpdesk-hero' ) . '</div></div>';
	}

	/**
	 * State for the troubleshooting screen.
	 *
	 * @return array
	 */
	public static function state() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$session = self::session();
		$own     = plugin_basename( HELPDESK_HERO_FILE );
		$plugins = get_plugins();
		$active  = (array) get_option( 'active_plugins', array() );
		$keep    = $session ? (array) $session['keep'] : $active;
		$list    = array();
		foreach ( $active as $file ) {
			if ( $file === $own || ! isset( $plugins[ $file ] ) ) {
				continue;
			}
			$list[] = array(
				'file'    => $file,
				'name'    => $plugins[ $file ]['Name'],
				'version' => $plugins[ $file ]['Version'],
				'keep'    => in_array( $file, $keep, true ),
			);
		}
		$default = self::default_theme();
		return array(
			'active'        => (bool) $session,
			'expires_at'    => $session ? gmdate( 'c', (int) $session['expires'] ) : null,
			'plugins'       => $list,
			'default_theme' => $default ? wp_get_theme( $default )->get( 'Name' ) : '',
			'use_theme'     => ! empty( $session['theme'] ),
		);
	}

	/**
	 * Start or update troubleshooting for this browser.
	 *
	 * @param string[] $keep      Plugin files to keep on.
	 * @param bool     $use_theme Use a default theme too.
	 * @return array|WP_Error State.
	 */
	public static function start( array $keep, $use_theme ) {
		if ( ! self::install_mu() ) {
			return new WP_Error( 'helpdesk_hero_mu', __( 'Troubleshooting mode could not start because the must-use plugins folder is not writable.', 'helpdesk-hero' ), array( 'status' => 500 ) );
		}
		$sessions = self::sessions();
		$cookie   = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		$key      = '' !== $cookie ? hash( 'sha256', $cookie ) : '';
		$grant_id = Helpdesk_Hero_Access::current_grant_id();
		$active   = (array) get_option( 'active_plugins', array() );
		$keep     = array_values( array_intersect( $keep, $active ) );
		if ( '' === $key || ! isset( $sessions[ $key ] ) ) {
			$cookie = bin2hex( random_bytes( 20 ) );
			$key    = hash( 'sha256', $cookie );
		}
		$expires = time() + 4 * HOUR_IN_SECONDS;
		if ( $grant_id ) {
			$grant   = Helpdesk_Hero_Access::current_grant();
			$expires = min( $expires, strtotime( $grant['expires_at'] . ' UTC' ) );
		}
		$sessions[ $key ] = array(
			'user'     => get_current_user_id(),
			'grant_id' => $grant_id,
			'keep'     => $keep,
			'theme'    => $use_theme ? self::default_theme() : '',
			'expires'  => $expires,
		);
		self::save( $sessions );
		setcookie( self::COOKIE, $cookie, $expires, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		$_COOKIE[ self::COOKIE ] = $cookie;
		Helpdesk_Hero_Monitor::record( 'activity', 'safe_mode_on', '', array( 'disabled' => array_values( array_diff( $active, $keep, array( plugin_basename( HELPDESK_HERO_FILE ) ) ) ) ), $grant_id );
		return self::state();
	}

	/**
	 * Stop troubleshooting for this browser.
	 *
	 * @return array State.
	 */
	public static function stop() {
		$sessions = self::sessions();
		$cookie   = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		unset( $sessions[ hash( 'sha256', $cookie ) ] );
		self::save( $sessions );
		setcookie( self::COOKIE, '', time() - HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
		unset( $_COOKIE[ self::COOKIE ] );
		Helpdesk_Hero_Monitor::record( 'activity', 'safe_mode_off', '', array(), Helpdesk_Hero_Access::current_grant_id() );
		return self::state();
	}

	/**
	 * Session for the current browser.
	 *
	 * @return array|null
	 */
	public static function session() {
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' === $cookie ) {
			return null;
		}
		$sessions = self::sessions();
		$session  = $sessions[ hash( 'sha256', $cookie ) ] ?? null;
		return $session && $session['expires'] > time() ? $session : null;
	}

	/**
	 * All live sessions (expired ones dropped).
	 *
	 * @return array
	 */
	private static function sessions() {
		$sessions = get_option( self::OPTION, array() );
		$sessions = is_array( $sessions ) ? $sessions : array();
		return array_filter(
			$sessions,
			static function ( $s ) {
				return (int) $s['expires'] > time();
			}
		);
	}

	/**
	 * Save sessions; remove the must-use plugin when none are left.
	 *
	 * @param array $sessions Sessions.
	 */
	private static function save( array $sessions ) {
		if ( $sessions ) {
			update_option( self::OPTION, $sessions, true );
		} else {
			delete_option( self::OPTION );
			self::remove_mu();
		}
	}

	/**
	 * End sessions that belong to a grant that ended.
	 *
	 * @param int    $grant_id Grant.
	 * @param string $reason   Reason.
	 */
	public static function on_access_changed( $grant_id, $reason ) {
		if ( ! in_array( $reason, array( 'revoked', 'expired' ), true ) ) {
			return;
		}
		$sessions = self::sessions();
		foreach ( $sessions as $key => $session ) {
			if ( (int) $session['grant_id'] === (int) $grant_id ) {
				unset( $sessions[ $key ] );
			}
		}
		self::save( $sessions );
	}

	/**
	 * A default (bundled Twenty-*) theme that is installed, if any.
	 *
	 * @return string
	 */
	private static function default_theme() {
		$candidates = array_unique( array( WP_DEFAULT_THEME, 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree' ) );
		foreach ( $candidates as $slug ) {
			if ( wp_get_theme( $slug )->exists() && get_stylesheet() !== $slug ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Copy the must-use plugin in place.
	 *
	 * @return bool
	 */
	public static function install_mu() {
		$target = WPMU_PLUGIN_DIR . '/' . self::MU;
		$source = HELPDESK_HERO_DIR . 'mu-plugin/' . self::MU;
		if ( file_exists( $target ) && md5_file( $target ) === md5_file( $source ) ) {
			return true;
		}
		$fs = self::filesystem();
		if ( ! $fs ) {
			return false;
		}
		if ( ! $fs->is_dir( WPMU_PLUGIN_DIR ) && ! $fs->mkdir( WPMU_PLUGIN_DIR ) ) {
			return false;
		}
		return (bool) $fs->copy( $source, $target, true );
	}

	/**
	 * Remove the must-use plugin.
	 */
	public static function remove_mu() {
		$target = WPMU_PLUGIN_DIR . '/' . self::MU;
		if ( file_exists( $target ) ) {
			$fs = self::filesystem();
			if ( $fs ) {
				$fs->delete( $target );
			}
		}
	}

	/**
	 * Direct filesystem access, or null when WordPress would need FTP credentials.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
			return null;
		}
		return $wp_filesystem;
	}
}
