<?php
/**
 * Plugin bootstrap.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin together.
 */
final class Helpdesk_Hero_Plugin {

	const PRUNE = 'helpdesk_hero_prune';

	/**
	 * Singleton.
	 *
	 * @var Helpdesk_Hero_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return Helpdesk_Hero_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 5 and 10 minute syncs are intended.
		add_action( 'init', array( 'Helpdesk_Hero_DB', 'maybe_install' ), 1 );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );

		Helpdesk_Hero_Monitor::init();
		Helpdesk_Hero_Access::init();
		Helpdesk_Hero_Activity::init();
		Helpdesk_Hero_Safe_Mode::init();
		Helpdesk_Hero_Connection::init();
		Helpdesk_Hero_Notices::init();
		Helpdesk_Hero_Privacy::init();
		Helpdesk_Hero_Admin::init();
		Helpdesk_Hero_Pinpoint::init();

		add_action( 'rest_api_init', array( 'Helpdesk_Hero_Client_REST', 'register' ) );
		add_action( 'rest_api_init', array( 'Helpdesk_Hero_Admin_REST', 'register' ) );
		add_action( self::PRUNE, array( 'Helpdesk_Hero_Monitor', 'prune' ) );
		add_action( self::PRUNE, array( 'Helpdesk_Hero_Attachments', 'expire_closed' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once HELPDESK_HERO_DIR . 'includes/class-cli.php';
			WP_CLI::add_command( 'helpdesk-hero', 'Helpdesk_Hero_CLI' );
		}
	}

	/**
	 * Extra cron intervals.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function schedules( $schedules ) {
		$schedules['helpdesk_hero_5min']  = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Helpdesk Hero)', 'helpdesk-hero' ),
		);
		$schedules['helpdesk_hero_10min'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 10 minutes (Helpdesk Hero)', 'helpdesk-hero' ),
		);
		return $schedules;
	}

	/**
	 * Re-create scheduled jobs that went missing (for example after a migration).
	 */
	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE );
		}
		if ( ! wp_next_scheduled( Helpdesk_Hero_Access::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'helpdesk_hero_5min', Helpdesk_Hero_Access::CRON );
		}
		Helpdesk_Hero_Connection::schedule();
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		Helpdesk_Hero_DB::install();
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		if ( ! wp_next_scheduled( self::PRUNE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE );
		}
		if ( ! wp_next_scheduled( Helpdesk_Hero_Access::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'helpdesk_hero_5min', Helpdesk_Hero_Access::CRON );
		}
		Helpdesk_Hero_Connection::schedule();
	}

	/**
	 * Deactivation: end all support access (its accounts depend on this plugin to expire),
	 * stop scheduled jobs and remove troubleshooting mode. Tickets and logs are kept.
	 */
	public static function deactivate() {
		foreach ( Helpdesk_Hero_Access::all( true, 500 ) as $grant ) {
			Helpdesk_Hero_Access::revoke( (int) $grant['id'] );
		}
		delete_option( Helpdesk_Hero_Safe_Mode::OPTION );
		Helpdesk_Hero_Safe_Mode::remove_mu();
		foreach ( array( self::PRUNE, Helpdesk_Hero_Access::CRON, Helpdesk_Hero_Connection::CRON ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
