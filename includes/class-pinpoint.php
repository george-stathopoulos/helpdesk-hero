<?php
/**
 * Pinpoint: report a problem on the exact spot.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * "Report a problem here" in the admin bar, on the front end and in wp-admin. The site owner
 * clicks an element or drags a box; the page address, title, element, position, screen size,
 * browser, and JavaScript errors and failed requests on that page
 * go with a new ticket. Support sees it on the ticket and can open the page with the spot
 * highlighted.
 *
 * There are no screenshots on purpose: support opens the real page with the spot highlighted,
 * which is more faithful than any in-browser screenshot. Return false from the
 * helpdesk_hero_pinpoint filter to turn Pinpoint off.
 */
final class Helpdesk_Hero_Pinpoint {

	const OPTION = 'helpdesk_hero_pins';
	const KEEP   = 90; // Days a pin stays highlightable.

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 99 );
		add_action( 'wp_head', array( __CLASS__, 'collector' ), 1 );
		add_action( 'admin_head', array( __CLASS__, 'collector' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Whether the current person can report with Pinpoint.
	 *
	 * @return bool
	 */
	public static function available() {
		/**
		 * Filters whether Pinpoint ("Report a problem here") is offered.
		 *
		 * @param bool $on On.
		 */
		return (bool) apply_filters( 'helpdesk_hero_pinpoint', self::settings()['enabled'] )
			&& is_user_logged_in()
			&& current_user_can( Helpdesk_Hero_Access::CAP )
			&& ! Helpdesk_Hero_Access::current_grant_id()
			&& Helpdesk_Hero_Settings::is_connected();
	}

	/**
	 * The site owner's choices (Get Help › Pinpoint).
	 *
	 * @return array { enabled, mode }
	 */
	public static function settings() {
		$s      = (array) Helpdesk_Hero_Settings::get( 'pinpoint' );
		$policy = Helpdesk_Hero_Policy::section( 'pinpoint' );
		$mode   = (string) ( $policy['mode'] ?? 'customer' );
		$chosen = isset( $s['enabled'] ) && null !== $s['enabled'] ? (bool) $s['enabled'] : null;
		switch ( $mode ) {
			case 'off':
				$enabled = false;
				break;
			case 'always':
				$enabled = true;
				break;
			case 'on':
				// On unless the site owner turned it off.
				$enabled = null === $chosen ? true : $chosen;
				break;
			default:
				// Off until the site owner turns it on.
				$enabled = (bool) $chosen;
		}
		return array(
			'enabled'     => $enabled,
			// What the support team's policy allows: customer | on | always | off.
			'mode'        => $mode,
		);
	}

	/**
	 * State for the Pinpoint page.
	 *
	 * @return array
	 */
	public static function state() {
		return array_merge(
			self::settings(),
			array(
				'connected' => Helpdesk_Hero_Settings::is_connected(),
			)
		);
	}

	/**
	 * Save the site owner's choices.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	public static function save( WP_REST_Request $request ) {
		if ( null !== $request->get_param( 'enabled' ) ) {
			Helpdesk_Hero_Settings::update( array( 'pinpoint' => array( 'enabled' => (bool) rest_sanitize_boolean( $request->get_param( 'enabled' ) ) ) ) );
		}
		return self::state();
	}

	/**
	 * Admin bar button, next to the help center item.
	 *
	 * @param WP_Admin_Bar $bar Bar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! self::available() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'helpdesk-hero-pinpoint',
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-icon dashicons dashicons-location" style="top:2px"></span><span class="ab-label">' . esc_html__( 'Report a problem', 'helpdesk-hero' ) . '</span>',
				'href'   => '#helpdesk-hero-pinpoint',
				'meta'   => array( 'title' => __( 'Click the part of this page that has the problem, and open a ticket about it', 'helpdesk-hero' ) ),
			)
		);
	}

	/**
	 * Early in the page: remember JavaScript errors and failed requests, so a report made later
	 * on this page includes them. Nothing is sent unless the person reports a problem.
	 */
	public static function collector() {
		if ( ! self::available() ) {
			return;
		}
		wp_print_inline_script_tag(
			'(function(){var w=window,l=w.hdhPinLog={errors:[],requests:[]};function add(a,x){if(a.length<20){a.push(x);}}' .
			'w.addEventListener("error",function(e){if(e&&e.message){add(l.errors,{message:String(e.message).slice(0,300),source:String(e.filename||"").slice(0,300),line:e.lineno||0});}else if(e&&e.target&&(e.target.src||e.target.href)){add(l.requests,{url:String(e.target.src||e.target.href).slice(0,300),status:0,method:"GET"});}},true);' .
			'w.addEventListener("unhandledrejection",function(e){add(l.errors,{message:String(e&&e.reason&&(e.reason.message||e.reason)||"Unhandled promise rejection").slice(0,300),source:"",line:0});});' .
			'if(w.fetch){var f=w.fetch;w.fetch=function(i,o){var u=String(i&&i.url||i),m=(o&&o.method)||(i&&i.method)||"GET";return f.apply(this,arguments).then(function(r){if(!r.ok){add(l.requests,{url:u.slice(0,300),status:r.status,method:m});}return r;},function(e){add(l.requests,{url:u.slice(0,300),status:0,method:m});throw e;});};}' .
			'var X=w.XMLHttpRequest&&w.XMLHttpRequest.prototype;if(X){var op=X.open,se=X.send;X.open=function(m,u){this._hdh=[m,String(u)];return op.apply(this,arguments);};X.send=function(){var x=this;x.addEventListener("loadend",function(){if(x._hdh&&(x.status===0||x.status>=400)){add(l.requests,{url:x._hdh[1].slice(0,300),status:x.status,method:x._hdh[0]});}});return se.apply(this,arguments);};}})();'
		);
	}

	/**
	 * Load the reporting overlay (for those who can report) or the highlighter (a link from
	 * support with ?hdh_pin=).
	 */
	public static function assets() {
		$pin = isset( $_GET['hdh_pin'] ) ? sanitize_key( wp_unslash( $_GET['hdh_pin'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: highlights a spot.
		if ( ! self::available() && '' === $pin ) {
			return;
		}
		wp_enqueue_script( 'helpdesk-hero-pinpoint', HELPDESK_HERO_URL . 'assets/pinpoint.js', array(), HELPDESK_HERO_VERSION, true );
		wp_enqueue_style( 'helpdesk-hero-pinpoint', HELPDESK_HERO_URL . 'assets/pinpoint.css', array(), HELPDESK_HERO_VERSION );
		wp_localize_script(
			'helpdesk-hero-pinpoint',
			'hdhPinpoint',
			array(
				'can'        => self::available(),
				'pin'        => $pin,
				'api'        => esc_url_raw( rest_url( Helpdesk_Hero_Admin_REST::NS . '/' ) ),
				'nonce'      => self::available() ? wp_create_nonce( 'wp_rest' ) : '',
				'newTicket'  => self::available() ? admin_url( 'admin.php?page=' . Helpdesk_Hero_Admin::SLUG . '#/new?pin=' ) : '',
				'ticketUrl'  => self::available() ? admin_url( 'admin.php?page=' . Helpdesk_Hero_Admin::SLUG . '#/ticket/' ) : '',
				'tickets'    => self::available() ? self::open_tickets() : array(),
				'area'       => is_admin() ? 'admin' : 'front',
				'i18n'       => array(
					'pick'      => __( 'Click the part of the page that has the problem, or drag a box around it. Press Esc to cancel.', 'helpdesk-hero' ),
					'cancel'    => __( 'Cancel', 'helpdesk-hero' ),
					'title'     => __( 'Report a problem here', 'helpdesk-hero' ),
					'what'      => __( 'This is sent with your ticket: the page address and title, the spot you picked, your screen size and browser, and any errors on this page.', 'helpdesk-hero' ),
					'next'      => __( 'Continue', 'helpdesk-hero' ),
					'another'   => __( 'Add another spot', 'helpdesk-hero' ),
					'label'     => __( 'What’s wrong here? (optional)', 'helpdesk-hero' ),
					'labelHint' => __( 'For example: wrong price, button does nothing', 'helpdesk-hero' ),
					/* translators: %d: number of spots */
					'count'     => __( 'Spots picked: %d of 5', 'helpdesk-hero' ),
					'sendTo'    => __( 'Send with', 'helpdesk-hero' ),
					'newT'      => __( 'A new ticket', 'helpdesk-hero' ),
					/* translators: 1: ticket number, 2: subject */
					'existingT' => __( 'Ticket #%1$d: %2$s', 'helpdesk-hero' ),
					'working'   => __( 'Preparing…', 'helpdesk-hero' ),
					'failed'    => __( 'Something went wrong. Try again, or open a ticket from the help center.', 'helpdesk-hero' ),
					'here'      => __( 'The reported spot', 'helpdesk-hero' ),
					'notFound'  => __( 'The exact element isn’t on the page any more; the box shows where it was.', 'helpdesk-hero' ),
					'pinGone'   => __( 'This highlight link has expired.', 'helpdesk-hero' ),
					'close'     => __( 'Close', 'helpdesk-hero' ),
				),
			)
		);
	}

	/**
	 * REST routes.
	 */
	public static function routes() {
		$owner = static function () {
			return current_user_can( Helpdesk_Hero_Access::CAP );
		};
		register_rest_route(
			Helpdesk_Hero_Admin_REST::NS,
			'/admin/pinpoint',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => $owner,
			)
		);
		register_rest_route(
			Helpdesk_Hero_Admin_REST::NS,
			'/admin/pinpoint-settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'save' ),
				'permission_callback' => $owner,
			)
		);
		register_rest_route(
			Helpdesk_Hero_Admin_REST::NS,
			'/admin/pinpoint/(?P<id>[a-z0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'read' ),
				'permission_callback' => $owner,
			)
		);
		// Public on purpose: the 32-character random ID is the key, and only the spot is returned.
		register_rest_route(
			Helpdesk_Hero_Admin_REST::NS,
			'/pin/(?P<id>[a-z0-9]{32})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'spot' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Save a report from the overlay.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error { id, ids }
	 */
	public static function create( WP_REST_Request $request ) {
		$params = (array) $request->get_json_params();
		// Up to 5 spots in one report ("Add another spot"); a single spot still works.
		$spots = isset( $params['spots'] ) && is_array( $params['spots'] ) ? array_slice( $params['spots'], 0, 5 ) : array( $params );
		if ( ! $spots ) {
			return new WP_Error( 'helpdesk_hero_pin', __( 'Pick a spot first.', 'helpdesk-hero' ), array( 'status' => 400 ) );
		}
		$ids   = array();
		foreach ( $spots as $spot ) {
			$ids[] = self::store( (array) $spot );
		}
		return array(
			'id'  => $ids[0],
			'ids' => $ids,
		);
	}

	/**
	 * Keep one spot.
	 *
	 * @param array $raw Spot from the browser.
	 * @return string Pin ID.
	 */
	private static function store( array $raw ) {
		$pin       = self::clean( $raw );
		$pin['id'] = strtolower( wp_generate_password( 32, false ) );
		$pins = array_filter(
			self::all(),
			static function ( $p ) {
				return strtotime( $p['created_at'] . ' UTC' ) > time() - self::KEEP * DAY_IN_SECONDS;
			}
		);
		$pins[ $pin['id'] ] = array_merge(
			$pin,
			array(
				'created_at' => Helpdesk_Hero_DB::now(),
				'created_by' => get_current_user_id(),
				'ticket_id'  => 0,
			)
		);
		update_option( self::OPTION, array_slice( $pins, -200, null, true ), false );
		return $pin['id'];
	}

	/**
	 * Spots for a ticket or reply, from a list of pin IDs (comma-separated or an array).
	 *
	 * @param string|array $ids       Pin IDs.
	 * @param int          $ticket_id Ticket to link them to.
	 * @return array[]
	 */
	public static function collect( $ids, $ticket_id ) {
		$ids = is_array( $ids ) ? $ids : explode( ',', (string) $ids );
		$out = array();
		foreach ( array_slice( array_unique( array_filter( array_map( 'sanitize_key', $ids ) ) ), 0, 5 ) as $id ) {
			$pin = self::for_ticket( $id );
			if ( $pin ) {
				$out[] = $pin;
				self::link( $id, (int) $ticket_id );
			}
		}
		return $out;
	}

	/**
	 * Spots sent with a ticket (for the customer's own view).
	 *
	 * @param int $ticket_id Ticket.
	 * @return array[] { id, title, url, label, kind, created_at }
	 */
	public static function of_ticket( $ticket_id ) {
		$out = array();
		foreach ( self::all() as $pin ) {
			if ( (int) ( $pin['ticket_id'] ?? 0 ) === (int) $ticket_id ) {
				$out[] = array(
					'id'         => $pin['id'],
					'title'      => $pin['title'],
					'url'        => $pin['url'],
					'label'      => $pin['label'] ?? '',
					'kind'       => $pin['kind'] ?? 'element',
					'created_at' => gmdate( 'c', strtotime( $pin['created_at'] . ' UTC' ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Open tickets, for "Add to ticket #…" after picking spots.
	 *
	 * @return array[] { id, subject }
	 */
	private static function open_tickets() {
		$out = array();
		foreach ( Helpdesk_Hero_Tickets::all( 'active', 20 ) as $t ) {
			if ( 'hub' === $t['channel'] ) {
				$out[] = array(
					'id'      => (int) $t['id'],
					'subject' => $t['subject'],
				);
			}
		}
		return $out;
	}

	/**
	 * A report, for the New ticket form.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$pin = self::all()[ (string) $request['id'] ] ?? null;
		return $pin ? $pin : new WP_Error( 'helpdesk_hero_pin', __( 'This report wasn’t found. Try again.', 'helpdesk-hero' ), array( 'status' => 404 ) );
	}

	/**
	 * The spot to highlight (nothing else).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	public static function spot( WP_REST_Request $request ) {
		$pin = self::all()[ (string) $request['id'] ] ?? null;
		if ( ! $pin ) {
			return new WP_Error( 'helpdesk_hero_pin', __( 'This highlight link has expired.', 'helpdesk-hero' ), array( 'status' => 404 ) );
		}
		return array(
			'selector' => $pin['selector'],
			'kind'     => $pin['kind'] ?? 'element',
			'box'      => $pin['box'] ?? null,
			'path'     => $pin['path'] ?? array(),
			'rect'     => $pin['rect'],
			'viewport' => $pin['viewport'],
		);
	}

	/**
	 * Everything about a pin that goes with the ticket.
	 *
	 * @param string $id Pin.
	 * @return array|null
	 */
	public static function for_ticket( $id ) {
		$pin = self::all()[ sanitize_key( (string) $id ) ] ?? null;
		if ( ! $pin ) {
			return null;
		}
		unset( $pin['created_by'], $pin['ticket_id'], $pin['attachment'] );
		return $pin;
	}

	/**
	 * Remember which ticket a pin went with.
	 *
	 * @param string $id        Pin.
	 * @param int    $ticket_id Ticket.
	 */
	public static function link( $id, $ticket_id ) {
		$pins = self::all();
		if ( isset( $pins[ $id ] ) ) {
			$pins[ $id ]['ticket_id'] = (int) $ticket_id;
			update_option( self::OPTION, $pins, false );
		}
	}

	/**
	 * All pins by ID.
	 *
	 * @return array
	 */
	private static function all() {
		$pins = get_option( self::OPTION, array() );
		return is_array( $pins ) ? $pins : array();
	}

	/**
	 * Steps to an element: { id } or { t: tag, c: classes, i: position }.
	 *
	 * @param mixed $path Raw.
	 * @return array[]
	 */
	private static function clean_path( $path ) {
		$out = array();
		foreach ( array_slice( (array) $path, 0, 10 ) as $step ) {
			$step = (array) $step;
			if ( ! empty( $step['id'] ) && preg_match( '/^[A-Za-z][\w-]{0,99}$/', (string) $step['id'] ) ) {
				$out[] = array( 'id' => (string) $step['id'] );
				continue;
			}
			if ( empty( $step['t'] ) || ! preg_match( '/^[a-z][a-z0-9-]{0,30}$/', (string) $step['t'] ) ) {
				return array();
			}
			$out[] = array(
				't' => (string) $step['t'],
				'c' => array_values(
					array_filter(
						array_slice( (array) ( $step['c'] ?? array() ), 0, 2 ),
						static function ( $c ) {
							return is_string( $c ) && preg_match( '/^[A-Za-z][\w-]{0,99}$/', $c );
						}
					)
				),
				'i' => max( 0, min( 500, (int) ( $step['i'] ?? 0 ) ) ),
			);
		}
		return $out;
	}

	/**
	 * An area box: pixel offsets from an element, or (older spots) fractions of it.
	 *
	 * @param array $box Raw { x, y, w, h }.
	 * @return array
	 */
	private static function clean_box( array $box ) {
		$out = array();
		if ( ! empty( $box['px'] ) ) {
			// Pixels from the element under the box's centre.
			$out['px'] = true;
			foreach ( array( 'x', 'y', 'w', 'h' ) as $k ) {
				$out[ $k ] = max( -20000, min( 20000, (int) ( $box[ $k ] ?? 0 ) ) );
			}
			return $out;
		}
		// Fractions of the element (spots from before 2.1.1).
		foreach ( array( 'x', 'y', 'w', 'h' ) as $k ) {
			$out[ $k ] = round( max( 0, min( 1, (float) ( $box[ $k ] ?? 0 ) ) ), 4 );
		}
		return $out;
	}

	/**
	 * Clean a report from the browser.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function clean( array $in ) {
		$num  = static function ( $v ) {
			return (int) round( (float) $v );
		};
		$rect = (array) ( $in['rect'] ?? array() );
		$view = (array) ( $in['viewport'] ?? array() );
		$list = static function ( $items, array $keys ) {
			$out = array();
			foreach ( array_slice( (array) $items, 0, 20 ) as $item ) {
				$row = array();
				foreach ( $keys as $k => $type ) {
					$v         = ( (array) $item )[ $k ] ?? '';
					$row[ $k ] = 'int' === $type ? (int) $v : substr( Helpdesk_Hero_Redactor::text( sanitize_text_field( (string) $v ) ), 0, 300 );
				}
				$out[] = $row;
			}
			return $out;
		};
		return array(
			'url'      => esc_url_raw( (string) ( $in['url'] ?? '' ) ),
			'title'    => substr( sanitize_text_field( (string) ( $in['title'] ?? '' ) ), 0, 200 ),
			'selector' => substr( sanitize_text_field( (string) ( $in['selector'] ?? '' ) ), 0, 500 ),
			// element: the element itself · area: a box drawn on the element (fractions of its size).
			'kind'     => 'area' === ( $in['kind'] ?? '' ) ? 'area' : 'element',
			'box'      => 'area' === ( $in['kind'] ?? '' ) && is_array( $in['box'] ?? null ) ? self::clean_box( $in['box'] ) : null,
			// How to find the element again, step by step from <body> (see pinpoint.js).
			'path'     => self::clean_path( $in['path'] ?? array() ),
			'text'     => substr( sanitize_text_field( (string) ( $in['text'] ?? '' ) ), 0, 200 ),
			// The customer's own words for this spot ("this button", "wrong price").
			'label'    => substr( sanitize_text_field( (string) ( $in['label'] ?? '' ) ), 0, 80 ),
			'rect'     => array(
				'x' => $num( $rect['x'] ?? 0 ),
				'y' => $num( $rect['y'] ?? 0 ),
				'w' => max( 0, $num( $rect['w'] ?? 0 ) ),
				'h' => max( 0, $num( $rect['h'] ?? 0 ) ),
			),
			'viewport' => array(
				'w'   => max( 0, $num( $view['w'] ?? 0 ) ),
				'h'   => max( 0, $num( $view['h'] ?? 0 ) ),
				'dpr' => round( max( 0, min( 8, (float) ( $view['dpr'] ?? 1 ) ) ), 2 ),
			),
			'browser'  => substr( sanitize_text_field( (string) ( $in['browser'] ?? '' ) ), 0, 300 ),
			'area'     => 'admin' === ( $in['area'] ?? '' ) ? 'admin' : 'front',
			'errors'   => $list( $in['errors'] ?? array(), array( 'message' => 'text', 'source' => 'text', 'line' => 'int' ) ),
			'requests' => $list( $in['requests'] ?? array(), array( 'url' => 'text', 'status' => 'int', 'method' => 'text' ) ),
		);
	}
}
