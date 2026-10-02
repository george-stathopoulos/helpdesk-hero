<?php
/**
 * REST API for the Get Help dashboard.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoints the React dashboard uses (cookie authentication). Everything needs the
 * helpdesk_hero_manage capability (administrators, never the support account), except
 * troubleshooting mode, which support may use when the policy allows it.
 */
final class Helpdesk_Hero_Admin_REST {

	const NS = 'helpdesk-hero/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		$owner = static function () {
			return current_user_can( Helpdesk_Hero_Access::CAP );
		};
		$support = static function () {
			return Helpdesk_Hero_Access::current_grant_id() > 0;
		};
		$routes = array(
			array( '/admin/session', 'GET', 'session', $support ),
			array( '/admin/state', 'GET', 'state', $owner ),
			array( '/admin/connect', 'POST', 'connect', $owner ),
			array( '/admin/disconnect', 'POST', 'disconnect', $owner ),
			array( '/admin/settings', 'POST', 'save_settings', $owner ),
			array( '/admin/test-connection', 'POST', 'test_connection', $owner ),
			array( '/admin/tickets', 'GET', 'tickets', $owner ),
			array( '/admin/tickets', 'POST', 'create_ticket', $owner ),
			array( '/admin/tickets/(?P<id>\d+)', 'GET', 'ticket', $owner ),
			array( '/admin/tickets/(?P<id>\d+)/reply', 'POST', 'reply', $owner ),
			array( '/admin/tickets/(?P<id>\d+)/status', 'POST', 'status', $owner ),
			array( '/admin/tickets/(?P<id>\d+)/emailed', 'POST', 'emailed', $owner ),
			array( '/admin/tickets/(?P<id>\d+)/discard', 'POST', 'discard', $owner ),
			array( '/admin/tickets/(?P<id>\d+)/rate', 'POST', 'rate', $owner ),
			array( '/admin/compose', 'GET', 'compose', $owner ),
			array( '/admin/access', 'GET', 'access', $owner ),
			array( '/admin/access', 'POST', 'grant', $owner ),
			array( '/admin/access/(?P<id>\d+)', 'POST', 'access_action', $owner ),
			array( '/admin/activity', 'GET', 'activity', $owner ),
			array( '/admin/ai/improve', 'POST', 'ai_improve', $owner ),
			array( '/admin/ai/summary', 'POST', 'ai_summary', $owner ),
			array( '/admin/troubleshoot', 'GET', 'troubleshoot', array( 'Helpdesk_Hero_Safe_Mode', 'can_use' ) ),
			array( '/admin/troubleshoot', 'POST', 'troubleshoot_set', array( 'Helpdesk_Hero_Safe_Mode', 'can_use' ) ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( __CLASS__, $r[2] ),
					'permission_callback' => $r[3],
				)
			);
		}
	}

	/**
	 * ISO 8601 from a UTC MySQL date.
	 *
	 * @param string|null $mysql Date.
	 * @return string|null
	 */
	private static function iso( $mysql ) {
		return $mysql ? gmdate( 'c', strtotime( $mysql . ' UTC' ) ) : null;
	}

	/**
	 * Refuse when not connected.
	 *
	 * @return WP_Error|null
	 */
	private static function need_connection() {
		return Helpdesk_Hero_Settings::is_connected() ? null : new WP_Error( 'helpdesk_hero_not_connected', __( 'Connect this site to your support team first.', 'helpdesk-hero' ), array( 'status' => 409 ) );
	}

	/**
	 * Error with an HTTP 400 status.
	 *
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function bad( WP_Error $error ) {
		$error->add_data( array( 'status' => 400 ) );
		return $error;
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Connection, policy and counts.
	 *
	 * @return array
	 */
	public static function state() {
		$s      = Helpdesk_Hero_Settings::all();
		$policy = Helpdesk_Hero_Policy::get();
		return array(
			'connected'     => Helpdesk_Hero_Settings::is_connected(),
			'hub'           => array(
				'name'         => Helpdesk_Hero_Settings::support_name(),
				'url'          => $s['hub_url'],
				'connected_at' => $s['connected_at'],
			),
			'policy'        => $policy,
			'role_labels'   => self::role_labels(),
			'branding'      => (array) $s['branding'],
			'notify_email'  => $s['notify_email'],
			'admin_email'   => (string) get_option( 'admin_email' ),
			'unread'        => Helpdesk_Hero_Tickets::unread_count(),
			'open'          => count( Helpdesk_Hero_Tickets::all( 'active', 500 ) ),
			'active_access' => count( Helpdesk_Hero_Access::all( true ) ),
		);
	}

	/**
	 * Role labels.
	 *
	 * @return array
	 */
	private static function role_labels() {
		return array(
			'restricted_admin' => __( 'Administrator without user management or code editing', 'helpdesk-hero' ),
			'administrator'    => __( 'Full administrator', 'helpdesk-hero' ),
			'editor'           => __( 'Editor (content only)', 'helpdesk-hero' ),
			'shop_manager'     => __( 'Shop manager (WooCommerce)', 'helpdesk-hero' ),
		);
	}

	/**
	 * Connect with a code.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function connect( WP_REST_Request $request ) {
		$result = Helpdesk_Hero_Connection::pair( (string) $request->get_param( 'code' ) );
		return is_wp_error( $result ) ? self::bad( $result ) : self::state();
	}

	/**
	 * Disconnect.
	 *
	 * @return array
	 */
	public static function disconnect() {
		Helpdesk_Hero_Connection::disconnect();
		return self::state();
	}

	/**
	 * Check the connection to the hub.
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		$error = self::need_connection();
		if ( $error ) {
			return $error;
		}
		$result = Helpdesk_Hero_Connection::test();
		return is_wp_error( $result ) ? self::bad( $result ) : $result;
	}

	/**
	 * Save the owner's preferences.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save_settings( WP_REST_Request $request ) {
		Helpdesk_Hero_Settings::update( array( 'notify_email' => sanitize_email( (string) $request->get_param( 'notify_email' ) ) ) );
		return self::state();
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Grant as JSON.
	 *
	 * @param array|null $g Grant.
	 * @return array|null
	 */
	private static function grant_json( $g ) {
		if ( ! $g ) {
			return null;
		}
		$user   = get_userdata( (int) $g['user_id'] );
		$ticket = $g['ticket_id'] ? Helpdesk_Hero_Tickets::get( (int) $g['ticket_id'] ) : null;
		$labels = self::role_labels();
		return array(
			'id'           => (int) $g['id'],
			'active'       => Helpdesk_Hero_Access::is_active( $g ),
			'account'      => $user ? $user->user_login : '',
			'role'         => $g['role'],
			'role_label'   => $labels[ $g['role'] ] ?? $g['role'],
			'plugins'      => (bool) $g['allow_plugins'],
			'ticket'       => $ticket ? array(
				'id'      => (int) $ticket['id'],
				'subject' => $ticket['subject'],
			) : null,
			'note'         => $g['note'],
			'created_at'   => self::iso( $g['created_at'] ),
			'expires_at'   => self::iso( $g['expires_at'] ),
			'ended_at'     => self::iso( $g['revoked_at'] ),
			'link_used_at' => self::iso( $g['token_used_at'] ),
			'link_pending' => '' !== $g['token_hash'] && empty( $g['token_used_at'] ),
			'extension'    => $g['extension_request'] ? array(
				'hours'  => (int) $g['extension_request']['hours'],
				'reason' => (string) $g['extension_request']['reason'],
				'by'     => (string) $g['extension_request']['by'],
			) : null,
			'events'       => count( Helpdesk_Hero_Monitor::query( array( 'grant_id' => (int) $g['id'], 'limit' => 1000 ) ) ),
		);
	}

	/**
	 * Ticket list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function tickets( WP_REST_Request $request ) {
		if ( Helpdesk_Hero_Settings::is_connected() ) {
			Helpdesk_Hero_Connection::pull( true );
		}
		$filter = sanitize_key( (string) $request->get_param( 'status' ) );
		$rows   = Helpdesk_Hero_Tickets::all( 'all' === $filter ? '' : ( 'closed' === $filter ? 'closed' : 'active' ), 200 );
		$out    = array();
		foreach ( $rows as $t ) {
			$grant = $t['grant_id'] ? Helpdesk_Hero_Access::get( (int) $t['grant_id'] ) : null;
			$out[] = array(
				'id'             => (int) $t['id'],
				'subject'        => $t['subject'],
				'status'         => $t['status'],
				'priority'       => $t['priority'],
				'channel'        => $t['channel'],
				'reference'      => $t['helpdesk_ref'],
				'unread'         => (bool) $t['unread'],
				'tags'           => $t['tags'] ? (array) json_decode( $t['tags'], true ) : array(),
				'rating'         => (int) $t['rating'],
				'needs_rating'   => self::needs_rating( $t ),
				'access_active'  => $grant ? Helpdesk_Hero_Access::is_active( $grant ) : false,
				'access_expires' => $grant ? self::iso( $grant['expires_at'] ) : null,
				'created_at'     => self::iso( $t['created_at'] ),
				'updated_at'     => self::iso( $t['updated_at'] ),
			);
		}
		return array( 'tickets' => $out );
	}

	/**
	 * One ticket with messages, access and what support did.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function ticket( WP_REST_Request $request ) {
		$t = Helpdesk_Hero_Tickets::get( (int) $request['id'] );
		if ( ! $t ) {
			return new WP_Error( 'helpdesk_hero_ticket', __( 'Ticket not found.', 'helpdesk-hero' ), array( 'status' => 404 ) );
		}
		if ( $t['unread'] ) {
			Helpdesk_Hero_Tickets::update(
				(int) $t['id'],
				array(
					'unread'     => 0,
					'updated_at' => $t['updated_at'],
				)
			);
		}
		$messages = array();
		foreach ( Helpdesk_Hero_Tickets::messages( (int) $t['id'] ) as $m ) {
			$messages[] = array(
				'id'     => (int) $m['id'],
				'from'   => 'in' === $m['direction'] ? 'support' : ( 'system' === $m['direction'] ? 'system' : 'you' ),
				'author' => $m['author'],
				'body'   => $m['body'],
				'time'   => self::iso( $m['created_at'] ),
			);
		}
		$grant    = $t['grant_id'] ? Helpdesk_Hero_Access::get( (int) $t['grant_id'] ) : null;
		$activity = array();
		if ( $grant ) {
			foreach ( Helpdesk_Hero_Monitor::query( array( 'grant_id' => (int) $grant['id'], 'limit' => 50 ) ) as $row ) {
				$activity[] = array(
					'id'   => (int) $row['id'],
					'type' => $row['type'],
					'text' => Helpdesk_Hero_Activity::describe( $row ),
					'time' => self::iso( $row['created_at'] ),
				);
			}
		}
		return array(
			'id'        => (int) $t['id'],
			'subject'   => $t['subject'],
			'status'    => $t['status'],
			'priority'  => $t['priority'],
			'channel'   => $t['channel'],
			'reference' => $t['helpdesk_ref'],
			'created'   => self::iso( $t['created_at'] ),
			'tags'      => $t['tags'] ? (array) json_decode( $t['tags'], true ) : array(),
			'emailed'   => '' !== (string) $t['manual_to'],
			'manual'    => '' !== (string) $t['manual_text'] ? array(
				'to'         => $t['manual_to'],
				'text'       => $t['manual_text'],
				'registered' => '' === (string) $t['pending_payload'],
			) : null,
			'rating'    => (int) $t['rating'] ? array(
				'stars'   => (int) $t['rating'],
				'comment' => (string) $t['rating_comment'],
			) : null,
			'can_rate'  => self::needs_rating( $t ),
			'messages'  => $messages,
			'grant'     => self::grant_json( $grant ),
			'activity'  => $activity,
		);
	}

	/**
	 * Whether the customer should be asked to rate a ticket: the policy asks for ratings, it
	 * isn't rated yet, and it is closed or its support access has ended.
	 *
	 * @param array $t Ticket row.
	 * @return bool
	 */
	private static function needs_rating( array $t ) {
		if ( (int) $t['rating'] || 'hub' !== $t['channel'] || ! Helpdesk_Hero_Policy::section( 'tickets' )['ratings'] ) {
			return false;
		}
		if ( 'closed' === $t['status'] ) {
			return true;
		}
		$grant = $t['grant_id'] ? Helpdesk_Hero_Access::get( (int) $t['grant_id'] ) : null;
		return $grant && ! Helpdesk_Hero_Access::is_active( $grant ) && ! empty( $grant['token_used_at'] );
	}

	/**
	 * The customer emailed the ticket: register it with the hub.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function emailed( WP_REST_Request $request ) {
		$result = Helpdesk_Hero_Connection::confirm_manual( (int) $request['id'] );
		if ( is_wp_error( $result ) ) {
			return self::bad( $result );
		}
		return array_merge( self::ticket( $request ), array( 'registered' => $result['registered'] ) );
	}

	/**
	 * Discard a ticket that was never emailed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function discard( WP_REST_Request $request ) {
		$result = Helpdesk_Hero_Connection::discard_manual( (int) $request['id'] );
		return is_wp_error( $result ) ? self::bad( $result ) : array( 'ok' => true );
	}

	/**
	 * Rate a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function rate( WP_REST_Request $request ) {
		$result = Helpdesk_Hero_Connection::rate( (int) $request['id'], (int) $request->get_param( 'rating' ), (string) $request->get_param( 'comment' ) );
		return is_wp_error( $result ) ? self::bad( $result ) : self::ticket( $request );
	}

	/**
	 * Everything the New ticket form needs, shaped by the policy.
	 *
	 * @return array|WP_Error
	 */
	public static function compose() {
		$error = self::need_connection();
		if ( $error ) {
			return $error;
		}
		$policy   = Helpdesk_Hero_Policy::get();
		$preview  = Helpdesk_Hero_Diagnostics::collect( Helpdesk_Hero_Policy::resolve_sections( array_keys( Helpdesk_Hero_Diagnostics::sections() ) ) );
		$flags    = Helpdesk_Hero_Health_Flags::evaluate( $preview );
		$sections = array();
		foreach ( Helpdesk_Hero_Diagnostics::sections() as $key => $label ) {
			$rule = $policy['diagnostics'][ $key ] ?? 'off';
			if ( 'never' !== $rule ) {
				$sections[] = array(
					'key'      => $key,
					'label'    => $label,
					'required' => 'required' === $rule,
					'checked'  => in_array( $rule, array( 'required', 'on' ), true ),
				);
			}
		}
		$labels = self::role_labels();
		$roles  = array();
		foreach ( $policy['access']['roles'] as $role ) {
			$roles[] = array(
				'value' => $role,
				'label' => $labels[ $role ] ?? $role,
			);
		}
		$durations = array();
		foreach ( Helpdesk_Hero_Policy::durations() as $hours => $label ) {
			$durations[] = array(
				'value' => (int) $hours,
				'label' => $label,
			);
		}
		$user = wp_get_current_user();
		return array(
			'flags'       => $flags,
			'sections'    => $sections,
			'preview'     => Helpdesk_Hero_Diagnostics::to_text( $preview, $flags ),
			'access'      => array_merge(
				$policy['access'],
				array(
					'role_options'     => $roles,
					'duration_options' => $durations,
					'default_label'    => $labels[ $policy['access']['default_role'] ] ?? $policy['access']['default_role'],
				)
			),
			'tickets'     => $policy['tickets'],
			'priorities'  => Helpdesk_Hero_Tickets::priorities(),
			'contact'     => array(
				'name'  => $user->display_name,
				'email' => $user->user_email,
			),
			'ai'          => $policy['tickets']['ai_assistant'] && Helpdesk_Hero_AI::available(),
		);
	}

	/**
	 * Send a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function create_ticket( WP_REST_Request $request ) {
		$p      = (array) $request->get_json_params();
		$result = Helpdesk_Hero_Connection::send_ticket(
			array(
				'subject'       => sanitize_text_field( (string) ( $p['subject'] ?? '' ) ),
				'description'   => sanitize_textarea_field( (string) ( $p['description'] ?? '' ) ),
				'priority'      => sanitize_key( (string) ( $p['priority'] ?? 'normal' ) ),
				'category'      => sanitize_text_field( (string) ( $p['category'] ?? '' ) ),
				'page_url'      => esc_url_raw( (string) ( $p['page_url'] ?? '' ) ),
				'contact_name'  => sanitize_text_field( (string) ( $p['contact_name'] ?? '' ) ),
				'contact_email' => sanitize_email( (string) ( $p['contact_email'] ?? '' ) ),
				'sections'      => array_map( 'sanitize_key', (array) ( $p['sections'] ?? array() ) ),
				'grant'         => ! empty( $p['grant'] ),
				'hours'         => absint( $p['hours'] ?? 0 ),
				'role'          => sanitize_key( (string) ( $p['role'] ?? '' ) ),
				'manual'        => ! empty( $p['manual'] ),
			)
		);
		return is_wp_error( $result ) ? self::bad( $result ) : $result;
	}

	/**
	 * Reply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function reply( WP_REST_Request $request ) {
		$result = Helpdesk_Hero_Connection::reply( (int) $request['id'], (string) $request->get_param( 'body' ) );
		return is_wp_error( $result ) ? self::bad( $result ) : self::ticket( $request );
	}

	/**
	 * Close or reopen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function status( WP_REST_Request $request ) {
		$result = Helpdesk_Hero_Connection::set_status( (int) $request['id'], sanitize_key( (string) $request->get_param( 'status' ) ) );
		return is_wp_error( $result ) ? self::bad( $result ) : self::ticket( $request );
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Active and past access.
	 *
	 * @return array
	 */
	public static function access() {
		$active = array();
		$past   = array();
		foreach ( Helpdesk_Hero_Access::all( false, 60 ) as $g ) {
			$json = self::grant_json( $g );
			if ( $json['active'] ) {
				$active[] = $json;
			} else {
				$past[] = $json;
			}
		}
		$durations = array();
		foreach ( Helpdesk_Hero_Policy::durations() as $hours => $label ) {
			$durations[] = array(
				'value' => (int) $hours,
				'label' => $label,
			);
		}
		return array(
			'active'    => $active,
			'past'      => $past,
			'policy'    => Helpdesk_Hero_Policy::section( 'access' ),
			'durations' => $durations,
			'roles'     => self::role_labels(),
		);
	}

	/**
	 * Grant access without a ticket.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function grant( WP_REST_Request $request ) {
		$error = self::need_connection();
		if ( $error ) {
			return $error;
		}
		$ticket = absint( $request->get_param( 'ticket' ) ) ? Helpdesk_Hero_Tickets::get( absint( $request->get_param( 'ticket' ) ) ) : null;
		$created = Helpdesk_Hero_Access::create(
			array(
				'hours'     => absint( $request->get_param( 'hours' ) ),
				'role'      => sanitize_key( (string) $request->get_param( 'role' ) ),
				'note'      => sanitize_text_field( (string) $request->get_param( 'note' ) ),
				'ticket_id' => $ticket ? (int) $ticket['id'] : 0,
			)
		);
		if ( is_wp_error( $created ) ) {
			return self::bad( $created );
		}
		if ( $ticket ) {
			Helpdesk_Hero_Tickets::update( (int) $ticket['id'], array( 'grant_id' => (int) $created['grant']['id'] ) );
			// The hub learns about it, and agents log in from there; no link to pass on.
			do_action( 'helpdesk_hero_access_changed', (int) $created['grant']['id'], 'granted' );
		}
		return array(
			'grant' => self::grant_json( $created['grant'] ),
			'url'   => $ticket && 'hub' === $ticket['channel'] ? '' : $created['url'],
		);
	}

	/**
	 * Extend, end, approve, decline, or issue a new link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function access_action( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$grant = Helpdesk_Hero_Access::get( $id );
		if ( ! $grant ) {
			return new WP_Error( 'helpdesk_hero_grant', __( 'Access not found.', 'helpdesk-hero' ), array( 'status' => 404 ) );
		}
		$out = array();
		switch ( sanitize_key( (string) $request->get_param( 'op' ) ) ) {
			case 'extend':
				if ( ! Helpdesk_Hero_Policy::section( 'access' )['customer_extend'] ) {
					return self::bad( new WP_Error( 'helpdesk_hero_policy', __( 'Your support team’s policy does not allow extending access here.', 'helpdesk-hero' ) ) );
				}
				if ( ! Helpdesk_Hero_Access::extend( $id, absint( $request->get_param( 'hours' ) ) ) ) {
					return self::bad( new WP_Error( 'helpdesk_hero_max', __( 'Access is already at the longest time your support team allows.', 'helpdesk-hero' ) ) );
				}
				break;
			case 'approve':
				if ( empty( $grant['extension_request']['hours'] ) ) {
					return self::bad( new WP_Error( 'helpdesk_hero_none', __( 'There is no request to approve.', 'helpdesk-hero' ) ) );
				}
				Helpdesk_Hero_Access::extend( $id, (int) $grant['extension_request']['hours'] );
				break;
			case 'decline':
				Helpdesk_Hero_Access::decline_extension( $id );
				break;
			case 'revoke':
				Helpdesk_Hero_Access::revoke( $id );
				break;
			case 'link':
				$url = Helpdesk_Hero_Access::new_link( $id );
				if ( is_wp_error( $url ) ) {
					return self::bad( $url );
				}
				$out['url'] = $url;
				break;
			default:
				return self::bad( new WP_Error( 'helpdesk_hero_op', __( 'Unknown action.', 'helpdesk-hero' ) ) );
		}
		return array_merge( $out, array( 'grant' => self::grant_json( Helpdesk_Hero_Access::get( $id ) ) ) );
	}

	/**
	 * Activity, changes or errors.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function activity( WP_REST_Request $request ) {
		$type     = sanitize_key( (string) $request->get_param( 'type' ) );
		$grant_id = absint( $request->get_param( 'grant' ) );
		$args     = array( 'limit' => 300 );
		if ( $grant_id ) {
			$args['grant_id'] = $grant_id;
		} elseif ( 'error' === $type ) {
			$args['type'] = array( 'error', 'connection' );
		} elseif ( 'change' === $type ) {
			$args['type'] = $type;
		} else {
			$args['type'] = array( 'activity', 'access', 'change' );
		}
		$rows = Helpdesk_Hero_Monitor::query( $args );
		if ( ! $grant_id && ! in_array( $type, array( 'change', 'error' ), true ) ) {
			// Support activity: everything done under a grant.
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $r ) {
						return (int) $r['grant_id'] > 0;
					}
				)
			);
		}
		$support = Helpdesk_Hero_Settings::support_name();
		$out     = array();
		foreach ( $rows as $row ) {
			$user  = $row['actor_id'] ? get_userdata( (int) $row['actor_id'] ) : null;
			$out[] = array(
				'id'      => (int) $row['id'],
				'type'    => $row['type'],
				'action'  => $row['action'],
				'time'    => self::iso( $row['created_at'] ),
				'who'     => (int) $row['grant_id'] > 0 ? self::support_actor( $row, $support ) : ( $user ? $user->display_name : __( 'System', 'helpdesk-hero' ) ),
				'support' => (int) $row['grant_id'] > 0,
				'ip'      => $row['ip'],
				'text'    => Helpdesk_Hero_Activity::describe( $row ),
				'changes' => isset( $row['details']['changes'] ) ? $row['details']['changes'] : null,
			);
		}
		return array( 'entries' => $out );
	}

	/* ------------------------------------------------------------------------------------ */

	/**
	 * Ticket assistant.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function ai_improve( WP_REST_Request $request ) {
		if ( ! Helpdesk_Hero_Policy::section( 'tickets' )['ai_assistant'] ) {
			return self::bad( new WP_Error( 'helpdesk_hero_policy', __( 'The assistant is turned off by your support team.', 'helpdesk-hero' ) ) );
		}
		$description = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		if ( '' === trim( $description ) ) {
			return self::bad( new WP_Error( 'helpdesk_hero_empty', __( 'Write a few words about the problem first.', 'helpdesk-hero' ) ) );
		}
		$flags  = Helpdesk_Hero_Health_Flags::evaluate( Helpdesk_Hero_Diagnostics::collect() );
		$result = Helpdesk_Hero_AI::improve_ticket( sanitize_text_field( (string) $request->get_param( 'subject' ) ), $description, $flags );
		return is_wp_error( $result ) ? self::bad( $result ) : $result;
	}

	/**
	 * Plain-English summary of a support session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function ai_summary( WP_REST_Request $request ) {
		$result = Helpdesk_Hero_AI::summarize_session( absint( $request->get_param( 'grant' ) ) );
		return is_wp_error( $result ) ? self::bad( $result ) : array( 'summary' => $result );
	}

	/**
	 * Troubleshooting state.
	 *
	 * @return array
	 */
	public static function troubleshoot() {
		return Helpdesk_Hero_Safe_Mode::state();
	}

	/**
	 * Start, update or stop troubleshooting.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function troubleshoot_set( WP_REST_Request $request ) {
		if ( 'stop' === $request->get_param( 'op' ) ) {
			return Helpdesk_Hero_Safe_Mode::stop();
		}
		return Helpdesk_Hero_Safe_Mode::start( array_map( 'sanitize_text_field', (array) $request->get_param( 'keep' ) ), (bool) $request->get_param( 'theme' ) );
	}

	/**
	 * Who did something under support access: the supporter's name when the support team uses
	 * named supporters, otherwise the team.
	 *
	 * @param array  $row     Log row.
	 * @param string $support Team name.
	 * @return string
	 */
	private static function support_actor( array $row, $support ) {
		$by = (string) ( ( (array) $row['details'] )['by'] ?? '' );
		/* translators: 1: supporter name, 2: support team name */
		return '' !== $by ? sprintf( __( '%1$s (%2$s)', 'helpdesk-hero' ), $by, $support ) : $support;
	}

	/**
	 * The current support session, for the support account's read-only page.
	 *
	 * @return array
	 */
	public static function session() {
		$grant  = Helpdesk_Hero_Access::current_grant();
		$ticket = $grant && $grant['ticket_id'] ? Helpdesk_Hero_Tickets::get( (int) $grant['ticket_id'] ) : null;
		$access = Helpdesk_Hero_Policy::section( 'access' );
		$first  = $ticket ? Helpdesk_Hero_Tickets::messages( (int) $ticket['id'] ) : array();
		return array(
			'site'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'team'            => Helpdesk_Hero_Settings::support_name(),
			'user'            => wp_get_current_user()->display_name,
			'expires'         => self::iso( $grant['expires_at'] ),
			'role'            => ucfirst( Helpdesk_Hero_Activity::role_label( (string) $grant['role'] ) ),
			'plugin_installs' => (bool) $grant['allow_plugins'],
			'page_views'      => ! empty( $access['log_page_views'] ),
			'troubleshooting' => Helpdesk_Hero_Safe_Mode::can_use() ? admin_url( 'tools.php?page=' . Helpdesk_Hero_Safe_Mode::PAGE ) : '',
			'logout'          => wp_logout_url( home_url( '/' ) ),
			'ticket'          => $ticket ? array(
				'subject'     => $ticket['subject'],
				'priority'    => $ticket['priority'],
				'status'      => $ticket['status'],
				'created'     => self::iso( $ticket['created_at'] ),
				'description' => $first ? (string) $first[0]['body'] : '',
			) : null,
		);
	}
}
