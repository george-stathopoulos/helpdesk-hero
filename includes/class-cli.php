<?php
/**
 * WP-CLI commands.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage Helpdesk Hero from the command line.
 */
final class Helpdesk_Hero_CLI {

	/**
	 * Show connection, tickets and active support access.
	 *
	 * ## EXAMPLES
	 *
	 *     wp helpdesk-hero status
	 */
	public function status() {
		$s = Helpdesk_Hero_Settings::all();
		WP_CLI::log( 'Support team: ' . ( Helpdesk_Hero_Settings::is_connected() ? $s['hub_name'] . ' (' . $s['hub_url'] . ')' : 'not connected' ) );
		WP_CLI::log( 'Open tickets: ' . count( Helpdesk_Hero_Tickets::all( 'active', 1000 ) ) );
		$grants = Helpdesk_Hero_Access::all( true );
		WP_CLI::log( 'Active support access: ' . count( $grants ) );
		foreach ( $grants as $g ) {
			WP_CLI::log( sprintf( '  #%d  %s  until %s UTC', $g['id'], $g['role'], $g['expires_at'] ) );
		}
	}

	/**
	 * Print the diagnostics bundle.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : text or json.
	 * ---
	 * default: text
	 * ---
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function diagnostics( $args, $assoc_args ) {
		$data = Helpdesk_Hero_Diagnostics::collect();
		if ( 'json' === ( $assoc_args['format'] ?? 'text' ) ) {
			WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		WP_CLI::log( Helpdesk_Hero_Diagnostics::to_text( $data, Helpdesk_Hero_Health_Flags::evaluate( $data ) ) );
	}

	/**
	 * Grant temporary support access and print the one-time login link.
	 *
	 * ## OPTIONS
	 *
	 * [--hours=<hours>]
	 * : How long access lasts.
	 * ---
	 * default: 24
	 * ---
	 *
	 * [--role=<role>]
	 * : restricted_admin, administrator, editor…
	 * ---
	 * default: restricted_admin
	 * ---
	 *
	 * [--note=<note>]
	 * : Note for your records.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function grant( $args, $assoc_args ) {
		$result = Helpdesk_Hero_Access::create(
			array(
				'hours' => (int) ( $assoc_args['hours'] ?? 24 ),
				'role'  => (string) ( $assoc_args['role'] ?? 'restricted_admin' ),
				'note'  => (string) ( $assoc_args['note'] ?? 'WP-CLI' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( sprintf( 'Access #%d until %s UTC.', $result['grant']['id'], $result['grant']['expires_at'] ) );
		WP_CLI::log( $result['url'] );
	}

	/**
	 * End support access.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Grant ID. Omit with --all.
	 *
	 * [--all]
	 * : End every active grant.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function revoke( $args, $assoc_args ) {
		$ids = ! empty( $assoc_args['all'] ) ? wp_list_pluck( Helpdesk_Hero_Access::all( true, 500 ), 'id' ) : array_map( 'intval', $args );
		if ( ! $ids ) {
			WP_CLI::error( 'Give a grant ID or --all.' );
		}
		foreach ( $ids as $id ) {
			Helpdesk_Hero_Access::revoke( (int) $id );
		}
		WP_CLI::success( sprintf( 'Ended %d grant(s).', count( $ids ) ) );
	}

	/**
	 * Connect to a support team with a connection code.
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Connection code from the support team (starts with hdh1.).
	 *
	 * @param array $args Args.
	 */
	public function connect( $args ) {
		$result = Helpdesk_Hero_Connection::pair( $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( 'Connected to ' . Helpdesk_Hero_Settings::support_name() . '.' );
	}

	/**
	 * Fetch replies and updates from the support team now.
	 */
	public function sync() {
		$pulled = Helpdesk_Hero_Connection::pull();
		WP_CLI::log( 'Site: ' . ( is_wp_error( $pulled ) ? 'error: ' . $pulled->get_error_message() : 'applied ' . $pulled . ' update(s).' ) );
	}
}
