<?php
/**
 * REST endpoints the support hub calls on a customer site.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every route requires a request signed with this site's hub secret. The hub can only:
 * ask for a fresh single-use login link while access is active, read the support activity
 * log for a ticket, push updates, and check that the site is reachable.
 */
final class Helpdesk_Hero_Client_REST {

	const NS = 'helpdesk-hero/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		$auth = array( __CLASS__, 'authorize' );
		register_rest_route(
			self::NS,
			'/client/login-link',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'login_link' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/client/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'activity' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/client/push',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'push' ),
				'permission_callback' => $auth,
			)
		);
		register_rest_route(
			self::NS,
			'/client/ping',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'ping' ),
				'permission_callback' => $auth,
			)
		);
	}

	/**
	 * Check the hub's signature.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( WP_REST_Request $request ) {
		if ( ! Helpdesk_Hero_Settings::is_connected() ) {
			return new WP_Error( 'helpdesk_hero_not_connected', __( 'This site is not connected to a support hub.', 'helpdesk-hero' ), array( 'status' => 403 ) );
		}
		$result = Helpdesk_Hero_Signer::verify(
			$request,
			static function ( $id ) {
				return (string) $id === (string) Helpdesk_Hero_Settings::get( 'hub_site_id' ) ? Helpdesk_Hero_Settings::secret( 'hub_secret' ) : '';
			}
		);
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Local ticket for a hub ticket ID.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private static function ticket( WP_REST_Request $request ) {
		$ticket = Helpdesk_Hero_Tickets::by_remote( (int) $request->get_param( 'hub_ticket_id' ) );
		return $ticket ? $ticket : new WP_Error( 'helpdesk_hero_ticket', __( 'Ticket not found on the site.', 'helpdesk-hero' ), array( 'status' => 404 ) );
	}

	/**
	 * Issue a fresh single-use login link for an agent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function login_link( WP_REST_Request $request ) {
		$ticket = self::ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		$grant = Helpdesk_Hero_Access::for_ticket( (int) $ticket['id'] );
		if ( ! $grant ) {
			return new WP_Error( 'helpdesk_hero_no_access', __( 'The customer has not granted access for this ticket, or access has ended. Ask them to grant access from their dashboard.', 'helpdesk-hero' ), array( 'status' => 403 ) );
		}
		// A named supporter (hubs with Helpdesk Hero Pro) gets a personal account under the same access.
		$supporter = (array) $request->get_param( 'supporter' );
		$user_id   = 0;
		if ( ! empty( $supporter['id'] ) && ! empty( $supporter['name'] ) ) {
			$user_id = Helpdesk_Hero_Access::supporter_user( $grant, (string) $supporter['id'], (string) $supporter['name'] );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}
		$url = Helpdesk_Hero_Access::new_link( (int) $grant['id'], (int) $user_id );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$agent = ! empty( $supporter['name'] ) ? (string) $supporter['name'] : (string) $request->get_param( 'agent' );
		Helpdesk_Hero_Monitor::record( 'access', 'link_issued', '', array( 'agent' => sanitize_text_field( $agent ) ), (int) $grant['id'] );
		return array(
			'url'        => $url,
			'expires_at' => $grant['expires_at'],
		);
	}

	/**
	 * Support activity for a ticket's grants.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function activity( WP_REST_Request $request ) {
		global $wpdb;
		$ticket = self::ticket( $request );
		if ( is_wp_error( $ticket ) ) {
			return $ticket;
		}
		$grant_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE ticket_id = %d', Helpdesk_Hero_DB::table( 'grants' ), $ticket['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$entries   = array();
		foreach ( $grant_ids as $grant_id ) {
			foreach ( Helpdesk_Hero_Monitor::query( array( 'grant_id' => (int) $grant_id, 'limit' => 500 ) ) as $row ) {
				$entries[] = array(
					'time' => $row['created_at'],
					'type' => $row['type'],
					'text' => Helpdesk_Hero_Activity::describe( $row ),
					'by'   => (string) ( ( (array) $row['details'] )['by'] ?? '' ),
				);
			}
		}
		usort(
			$entries,
			static function ( $a, $b ) {
				return strcmp( $a['time'], $b['time'] );
			}
		);
		$grant = Helpdesk_Hero_Access::for_ticket( (int) $ticket['id'] );
		return array(
			'entries' => $entries,
			'access'  => Helpdesk_Hero_Connection::access_payload( $grant ),
		);
	}

	/**
	 * Updates pushed by the hub (same format as the pull endpoint).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function push( WP_REST_Request $request ) {
		$items = $request->get_json_params();
		return array( 'applied' => Helpdesk_Hero_Connection::apply( (array) ( $items['items'] ?? array() ) ) );
	}

	/**
	 * Reachability and versions.
	 *
	 * @return array
	 */
	public static function ping() {
		return array(
			'ok'        => true,
			'version'   => HELPDESK_HERO_VERSION,
			'wordpress' => get_bloginfo( 'version' ),
			'php'       => PHP_VERSION,
			'name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
	}
}
