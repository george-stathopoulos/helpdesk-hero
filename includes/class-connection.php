<?php
/**
 * The connection to the support team's hub.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pairs the site with a hub, sends tickets and replies, and applies what the hub sends back:
 * support replies, status changes, dashboard messages, requests for more time, and the
 * support team's policy and branding.
 */
final class Helpdesk_Hero_Connection {

	const CRON        = 'helpdesk_hero_pull';
	const CODE_PREFIX = 'hdh1.';
	const HUB_NS      = '/helpdesk-hero-hub/v1';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'pull' ) );
		add_action( 'helpdesk_hero_access_changed', array( __CLASS__, 'report_access' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Tickets.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * Open a ticket.
	 *
	 * @param array $input subject, description, priority, category, page_url, sections, contact_name,
	 *                     contact_email, grant (bool), hours, role, manual (bool: the owner emails it themselves).
	 * @return array|WP_Error { ticket_id, login_url, manual_text }
	 */
	public static function send_ticket( array $input ) {
		if ( ! Helpdesk_Hero_Settings::is_connected() ) {
			return new WP_Error( 'helpdesk_hero_not_connected', __( 'Connect this site to your support team first.', 'helpdesk-hero' ) );
		}
		$policy      = Helpdesk_Hero_Policy::get();
		$subject     = trim( sanitize_text_field( $input['subject'] ?? '' ) );
		$description = trim( sanitize_textarea_field( $input['description'] ?? '' ) );
		if ( '' === $subject || '' === $description ) {
			return new WP_Error( 'helpdesk_hero_missing', __( 'Add a subject and describe the problem.', 'helpdesk-hero' ) );
		}
		$manual = ! empty( $input['manual'] );
		if ( $manual && '' === $policy['tickets']['manual_email'] ) {
			return new WP_Error( 'helpdesk_hero_policy', __( 'Your support team does not accept tickets by email.', 'helpdesk-hero' ) );
		}
		$priority = $policy['tickets']['priorities'] && in_array( $input['priority'] ?? '', array_keys( Helpdesk_Hero_Tickets::priorities() ), true ) ? $input['priority'] : 'normal';
		$category = in_array( $input['category'] ?? '', $policy['tickets']['categories'], true ) ? (string) $input['category'] : '';
		$user     = wp_get_current_user();
		$contact  = array(
			'name'  => sanitize_text_field( $input['contact_name'] ?? '' ) ?: $user->display_name, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
			'email' => sanitize_email( $input['contact_email'] ?? '' ) ?: $user->user_email, // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
		);
		$answers = self::clean_field_answers( (array) ( $input['fields'] ?? array() ) );
		if ( is_wp_error( $answers ) ) {
			return $answers;
		}
		$page_url    = esc_url_raw( $input['page_url'] ?? '' );
		$sections    = Helpdesk_Hero_Policy::resolve_sections( isset( $input['sections'] ) ? array_map( 'sanitize_key', (array) $input['sections'] ) : null );
		$diagnostics = Helpdesk_Hero_Diagnostics::collect( $sections );
		$flags       = Helpdesk_Hero_Health_Flags::evaluate( $diagnostics );

		// Access: the policy decides whether it is offered, required or never used.
		// The policy's access rules for this category and priority decide; the customer's choice
		// counts only when they're asked.
		$choice      = Helpdesk_Hero_Policy::access_choice( $category, $priority );
		$want_access = 'required' === $choice || ( in_array( $choice, array( 'ticked', 'unticked' ), true ) && ! empty( $input['grant'] ) );

		$ticket_id = Helpdesk_Hero_Tickets::insert(
			array(
				'subject'  => $subject,
				'status'   => $manual ? 'unsent' : 'open',
				'channel'  => $manual ? 'manual' : 'hub',
				'priority' => $priority,
				'fields'   => $answers ? wp_json_encode( $answers ) : null,
			)
		);
		$message_id = Helpdesk_Hero_Tickets::add_message( $ticket_id, 'out', $contact['name'], $description );
		$files      = array();
		if ( ! $manual && ! empty( $input['attachments'] ) ) {
			$ids   = Helpdesk_Hero_Attachments::config()['enabled'] ? array_map( 'absint', (array) $input['attachments'] ) : array();
			$files = $ids ? Helpdesk_Hero_Attachments::claim( $ticket_id, $message_id, $ids ) : array();
		}

		$login_url = '';
		$grant     = null;
		if ( $want_access && ! Helpdesk_Hero_Access::active() ) {
			$created = Helpdesk_Hero_Access::create(
				array(
					'hours'     => (int) ( $input['hours'] ?? 0 ),
					'role'      => (string) ( $input['role'] ?? '' ),
					'ticket_id' => $ticket_id,
					/* translators: %d: ticket number */
					'note'      => sprintf( __( 'Ticket #%d', 'helpdesk-hero' ), $ticket_id ),
				)
			);
			if ( is_wp_error( $created ) ) {
				self::rollback( $ticket_id, null );
				return $created;
			}
			$grant     = $created['grant'];
			$login_url = $created['url'];
			$existing  = ! empty( $created['existing'] );
			Helpdesk_Hero_Tickets::update( $ticket_id, array( 'grant_id' => (int) $grant['id'] ) );
		} else {
			// Access is per site: a ticket opened while access is active can use it too.
			$grant    = Helpdesk_Hero_Access::active();
			$existing = true;
			if ( $grant ) {
				Helpdesk_Hero_Tickets::update( $ticket_id, array( 'grant_id' => (int) $grant['id'] ) );
				// An emailed ticket carries a login link for the existing access.
				if ( $manual && $want_access ) {
					$url       = Helpdesk_Hero_Access::new_link( (int) $grant['id'] );
					$login_url = is_wp_error( $url ) ? '' : $url;
				}
			}
		}

		// Pinpoint: the spot the customer picked on a page.
		$pinpoints = ! empty( $input['pinpoint'] ) ? Helpdesk_Hero_Pinpoint::collect( (string) $input['pinpoint'], $ticket_id ) : array();

		$ticket = array(
			'id'          => $ticket_id,
			'subject'     => $subject,
			'description' => $description,
			'priority'    => $priority,
			'category'    => $category,
			'page_url'    => $page_url,
			'contact'     => $contact,
		);

		$payload = array(
			'client_ticket_id' => $ticket_id,
			'subject'          => $subject,
			'description'      => $description,
			'priority'         => $priority,
			'category'         => $category,
			'page_url'         => $page_url,
			'contact'          => $contact,
			'access'           => self::access_payload( $grant ),
			'fields'           => (object) $answers,
			'attachments'      => $files,
			'pinpoints'        => $pinpoints,
		) + self::pack_bundle( $diagnostics, $flags );

		if ( $manual ) {
			// Kept until the customer confirms they emailed it; then the ticket is registered
			// with the hub too, so support sees it there with its diagnostics and access.
			Helpdesk_Hero_Tickets::update(
				$ticket_id,
				array(
					'manual_to'       => $policy['tickets']['manual_email'],
					'manual_text'     => self::email_body( $ticket, $flags, $login_url, $grant ) . "\n\n" . Helpdesk_Hero_Diagnostics::to_text( $diagnostics ),
					'pending_payload' => wp_json_encode( array_merge( $payload, array( 'channel' => 'email' ) ) ),
				)
			);
		} else {
			$result = self::hub_request( self::HUB_NS . '/tickets', 'POST', $payload );
			if ( is_wp_error( $result ) ) {
				// Access that existed before this ticket stays.
				self::rollback( $ticket_id, $existing ? null : $grant );
				return $result;
			}
			Helpdesk_Hero_Tickets::update(
				$ticket_id,
				array(
					'remote_id'    => (int) ( $result['hub_ticket_id'] ?? 0 ),
					'helpdesk_ref' => sanitize_text_field( (string) ( $result['reference'] ?? '' ) ),
				)
			);
		}

		/**
		 * Fires after a ticket is opened.
		 *
		 * @param int   $ticket_id Local ticket ID.
		 * @param array $ticket    Ticket data.
		 * @param array $flags     Health flags.
		 */
		do_action( 'helpdesk_hero_ticket_sent', $ticket_id, $ticket, $flags );

		$saved = Helpdesk_Hero_Tickets::get( $ticket_id );
		return array(
			'ticket_id'   => $ticket_id,
			'manual_text' => $manual ? (string) $saved['manual_text'] : '',
			'manual_to'   => $manual ? (string) $saved['manual_to'] : '',
		);
	}

	/**
	 * The customer confirms they emailed a ticket themselves. The ticket is then registered with
	 * the hub (marked as emailed, so it isn't created in the help desk twice). If the hub can't be
	 * reached right now, it is retried in the background.
	 *
	 * @param int $ticket_id Ticket.
	 * @return array|WP_Error { registered: bool }
	 */
	public static function confirm_manual( $ticket_id ) {
		$ticket = Helpdesk_Hero_Tickets::get( $ticket_id );
		if ( ! $ticket || 'manual' !== $ticket['channel'] ) {
			return new WP_Error( 'helpdesk_hero_ticket', __( 'Ticket not found.', 'helpdesk-hero' ) );
		}
		if ( 'unsent' === $ticket['status'] ) {
			Helpdesk_Hero_Tickets::update( $ticket_id, array( 'status' => 'sent' ) );
		}
		return array( 'registered' => self::register_manual( Helpdesk_Hero_Tickets::get( $ticket_id ) ) );
	}

	/**
	 * Throw away a ticket that was never emailed (and end the access created for it).
	 *
	 * @param int $ticket_id Ticket.
	 * @return true|WP_Error
	 */
	public static function discard_manual( $ticket_id ) {
		$ticket = Helpdesk_Hero_Tickets::get( $ticket_id );
		if ( ! $ticket || 'unsent' !== $ticket['status'] ) {
			return new WP_Error( 'helpdesk_hero_ticket', __( 'Only tickets that were not sent yet can be discarded.', 'helpdesk-hero' ) );
		}
		$grant = $ticket['grant_id'] ? Helpdesk_Hero_Access::get( (int) $ticket['grant_id'] ) : null;
		// Only access that was created for this ticket ends with it, and only if no other open ticket uses it.
		if ( $grant && ( (int) $grant['ticket_id'] !== (int) $ticket_id || self::open_tickets_using( (int) $grant['id'], (int) $ticket_id ) ) ) {
			$grant = null;
		}
		self::rollback( $ticket_id, $grant );
		return true;
	}

	/**
	 * Register an emailed ticket with the hub.
	 *
	 * @param array $ticket Ticket.
	 * @return bool Registered.
	 */
	private static function register_manual( array $ticket ) {
		if ( '' === (string) $ticket['pending_payload'] || ! self::is_registrable() ) {
			return '' === (string) $ticket['pending_payload'];
		}
		$payload = (array) json_decode( (string) $ticket['pending_payload'], true );
		// Access may have changed since the email was written.
		$grant             = $ticket['grant_id'] ? Helpdesk_Hero_Access::get( (int) $ticket['grant_id'] ) : null;
		$payload['access'] = self::access_payload( $grant );
		$result            = self::hub_request( self::HUB_NS . '/tickets', 'POST', $payload );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		Helpdesk_Hero_Tickets::update(
			(int) $ticket['id'],
			array(
				'remote_id'       => (int) ( $result['hub_ticket_id'] ?? 0 ),
				'channel'         => 'hub',
				'status'          => 'open',
				'pending_payload' => null,
			)
		);
		return true;
	}

	/**
	 * Whether a connection to the hub exists.
	 *
	 * @return bool
	 */
	private static function is_registrable() {
		return Helpdesk_Hero_Settings::is_connected();
	}

	/**
	 * Retry registering emailed tickets the hub hasn't seen yet.
	 */
	public static function sync_pending() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE channel = 'manual' AND status = 'sent' AND pending_payload IS NOT NULL LIMIT 20", Helpdesk_Hero_DB::table( 'tickets' ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $rows as $row ) {
			self::register_manual( $row );
		}
	}

	/**
	 * Rate a ticket (when the support team's policy asks for ratings).
	 *
	 * @param int    $ticket_id Ticket.
	 * @param int    $rating    1–5.
	 * @param string $comment   Comment.
	 * @return true|WP_Error
	 */
	public static function rate( $ticket_id, $rating, $comment ) {
		$ticket = Helpdesk_Hero_Tickets::get( $ticket_id );
		$rating = (int) $rating;
		if ( ! $ticket || $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'helpdesk_hero_rating', __( 'Choose from 1 to 5 stars.', 'helpdesk-hero' ) );
		}
		if ( ! Helpdesk_Hero_Policy::section( 'tickets' )['ratings'] || 'hub' !== $ticket['channel'] ) {
			return new WP_Error( 'helpdesk_hero_policy', __( 'Ratings are not available for this ticket.', 'helpdesk-hero' ) );
		}
		$comment = substr( sanitize_textarea_field( (string) $comment ), 0, 2000 );
		$result  = self::hub_request(
			self::HUB_NS . '/tickets/' . (int) $ticket['remote_id'] . '/rating',
			'POST',
			array(
				'rating'  => $rating,
				'comment' => $comment,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		Helpdesk_Hero_Tickets::update(
			$ticket_id,
			array(
				'rating'         => $rating,
				'rating_comment' => $comment,
				'rated_at'       => Helpdesk_Hero_DB::now(),
				'updated_at'     => $ticket['updated_at'],
			)
		);
		return true;
	}

	/**
	 * Diagnostics and flags for the hub, gzipped and base64-encoded when possible. File paths,
	 * error messages and config names in diagnostics look like attacks to some web application
	 * firewalls, which then block the whole request; packed, they are also much smaller.
	 *
	 * @param array $diagnostics Diagnostics.
	 * @param array $flags       Health flags.
	 * @return array Payload fields.
	 */
	public static function pack_bundle( array $diagnostics, array $flags ) {
		$json = (string) wp_json_encode(
			array(
				'diagnostics' => $diagnostics,
				'flags'       => $flags,
			)
		);
		if ( function_exists( 'gzencode' ) ) {
			$packed = gzencode( $json, 6 );
			if ( false !== $packed ) {
				return array( 'bundle' => base64_encode( $packed ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding so firewalls don't misread diagnostics.
			}
		}
		return array(
			'diagnostics' => $diagnostics,
			'flags'       => $flags,
		);
	}

	/**
	 * Undo a ticket that could not be delivered.
	 *
	 * @param int        $ticket_id Ticket.
	 * @param array|null $grant     Grant created for it.
	 */
	private static function rollback( $ticket_id, $grant ) {
		global $wpdb;
		if ( $grant ) {
			Helpdesk_Hero_Access::revoke( (int) $grant['id'] );
		}
		$wpdb->delete( Helpdesk_Hero_DB::table( 'messages' ), array( 'ticket_id' => (int) $ticket_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Helpdesk_Hero_DB::table( 'tickets' ), array( 'id' => (int) $ticket_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Files go back to being unsent uploads, so a retry can use them.
		$wpdb->update( Helpdesk_Hero_DB::table( 'attachments' ), array( 'ticket_id' => 0, 'message_id' => 0 ), array( 'ticket_id' => (int) $ticket_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Access state for the hub. Never includes a login link; the hub asks for one when an agent logs in.
	 *
	 * @param array|null $grant Grant.
	 * @return array
	 */
	public static function access_payload( $grant ) {
		if ( ! $grant || ! Helpdesk_Hero_Access::is_active( $grant ) ) {
			return array( 'active' => false );
		}
		return array(
			'active'     => true,
			'expires_at' => $grant['expires_at'],
			'permanent'  => Helpdesk_Hero_Access::is_permanent( $grant ),
			'role'       => $grant['role'],
		);
	}

	/**
	 * Text for "Email it myself".
	 *
	 * @param array      $ticket    Ticket.
	 * @param array      $flags     Flags.
	 * @param string     $login_url Login link.
	 * @param array|null $grant     Grant.
	 * @return string
	 */
	public static function email_body( array $ticket, array $flags, $login_url, $grant ) {
		global $wp_version;
		/* translators: %s: subject */
		$lines   = array( sprintf( __( 'Subject: %s', 'helpdesk-hero' ), $ticket['subject'] ), '', $ticket['description'], '', '— — —' );
		/* translators: %s: priority */
		$lines[] = sprintf( __( 'Priority: %s', 'helpdesk-hero' ), ucfirst( $ticket['priority'] ) );
		if ( $ticket['category'] ) {
			/* translators: %s: category */
			$lines[] = sprintf( __( 'Category: %s', 'helpdesk-hero' ), $ticket['category'] );
		}
		if ( $ticket['page_url'] ) {
			/* translators: %s: URL */
			$lines[] = sprintf( __( 'Page with the problem: %s', 'helpdesk-hero' ), $ticket['page_url'] );
		}
		/* translators: 1: name, 2: email */
		$lines[] = sprintf( __( 'Contact: %1$s <%2$s>', 'helpdesk-hero' ), $ticket['contact']['name'], $ticket['contact']['email'] );
		/* translators: 1: URL, 2: WP version, 3: PHP version */
		$lines[] = sprintf( __( 'Site: %1$s (WordPress %2$s, PHP %3$s)', 'helpdesk-hero' ), home_url( '/' ), $wp_version, PHP_VERSION );
		if ( $flags ) {
			$lines[] = '';
			$lines[] = __( 'Health flags:', 'helpdesk-hero' );
			foreach ( array_slice( $flags, 0, 8 ) as $flag ) {
				$lines[] = '• [' . strtoupper( $flag['level'] ) . '] ' . $flag['title'] . ( $flag['detail'] ? ' — ' . $flag['detail'] : '' );
			}
		}
		if ( $login_url && $grant ) {
			$lines[] = '';
			$lines[] = __( 'Temporary support login (works once):', 'helpdesk-hero' );
			$lines[] = $login_url;
			/* translators: %s: date and time */
			$lines[] = sprintf( __( 'Access ends %s UTC.', 'helpdesk-hero' ), $grant['expires_at'] );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Reply to a ticket.
	 *
	 * @param int    $ticket_id   Ticket.
	 * @param string $body        Message.
	 * @param array  $attachments Upload IDs.
	 * @return true|WP_Error
	 */
	public static function reply( $ticket_id, $body, array $attachments = array(), $pins = '' ) {
		$ticket = Helpdesk_Hero_Tickets::get( $ticket_id );
		$body   = trim( sanitize_textarea_field( $body ) );
		if ( ! $ticket || '' === $body ) {
			return new WP_Error( 'helpdesk_hero_reply', __( 'Write a message first.', 'helpdesk-hero' ) );
		}
		if ( ! Helpdesk_Hero_Policy::section( 'tickets' )['customer_replies'] || 'hub' !== $ticket['channel'] ) {
			return new WP_Error( 'helpdesk_hero_policy', __( 'Replies from the dashboard are not available for this ticket.', 'helpdesk-hero' ) );
		}
		$user = wp_get_current_user();
		// The message is stored first so its files can be attached to it; removed if the hub refuses.
		$message_id = Helpdesk_Hero_Tickets::add_message( $ticket_id, 'out', $user->display_name, $body );
		$files      = $attachments && Helpdesk_Hero_Attachments::config()['enabled'] ? Helpdesk_Hero_Attachments::claim( $ticket_id, $message_id, $attachments ) : array();
		$result     = self::hub_request(
			self::HUB_NS . '/tickets/' . (int) $ticket['remote_id'] . '/reply',
			'POST',
			array(
				'body'        => $body,
				'author'      => $user->display_name,
				'email'       => $user->user_email,
				'attachments' => $files,
				// Problem spots added to this reply (Pinpoint).
				'pinpoints'   => $pins ? Helpdesk_Hero_Pinpoint::collect( $pins, $ticket_id ) : array(),
			)
		);
		if ( is_wp_error( $result ) ) {
			Helpdesk_Hero_Tickets::delete_message( $message_id );
			return $result;
		}
		Helpdesk_Hero_Tickets::update( $ticket_id, array( 'status' => 'open' ) );
		return true;
	}

	/**
	 * Close (or reopen) a ticket.
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $status    closed | open.
	 * @return true|WP_Error
	 */
	public static function set_status( $ticket_id, $status ) {
		$ticket = Helpdesk_Hero_Tickets::get( $ticket_id );
		if ( ! $ticket ) {
			return new WP_Error( 'helpdesk_hero_ticket', __( 'Ticket not found.', 'helpdesk-hero' ) );
		}
		if ( 'hub' === $ticket['channel'] && ! Helpdesk_Hero_Policy::section( 'tickets' )['customer_close'] ) {
			return new WP_Error( 'helpdesk_hero_policy', __( 'Your support team closes tickets.', 'helpdesk-hero' ) );
		}
		$status = 'closed' === $status ? 'closed' : ( 'hub' === $ticket['channel'] ? 'open' : 'sent' );
		if ( 'hub' === $ticket['channel'] ) {
			$result = self::hub_request( self::HUB_NS . '/tickets/' . (int) $ticket['remote_id'] . '/status', 'POST', array( 'status' => 'closed' === $status ? 'closed' : 'open' ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		Helpdesk_Hero_Tickets::update( $ticket_id, array( 'status' => $status ) );
		if ( 'closed' === $status ) {
			self::end_access_on_close( $ticket );
		}
		return true;
	}

	/**
	 * When a ticket closes and the policy says so, end the site's access, but only once no other
	 * open ticket uses it.
	 *
	 * @param array $ticket Ticket.
	 */
	private static function end_access_on_close( array $ticket ) {
		if ( ! Helpdesk_Hero_Policy::section( 'access' )['end_on_close'] ) {
			return;
		}
		$grant = Helpdesk_Hero_Access::active();
		// Access with no end date lasts until someone ends it, whatever happens to tickets.
		if ( ! $grant || Helpdesk_Hero_Access::is_permanent( $grant ) || self::open_tickets_using( (int) $grant['id'], (int) $ticket['id'] ) ) {
			return;
		}
		Helpdesk_Hero_Access::revoke( (int) $grant['id'], 'closed' );
	}

	/**
	 * How many tickets other than one are still open and use the site's access.
	 *
	 * @param int $grant_id  Grant.
	 * @param int $except_id Ticket to leave out.
	 * @return int
	 */
	public static function open_tickets_using( $grant_id, $except_id = 0 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE grant_id = %d AND id <> %d AND status <> 'closed'", Helpdesk_Hero_DB::table( 'tickets' ), (int) $grant_id, (int) $except_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Pairing and sync.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * Read a connection code.
	 *
	 * @param string $code Code.
	 * @return array|WP_Error { u, t, n }
	 */
	public static function parse_code( $code ) {
		$code = trim( preg_replace( '/\s+/', '', (string) $code ) );
		if ( 0 !== strpos( $code, self::CODE_PREFIX ) ) {
			return new WP_Error( 'helpdesk_hero_code', __( 'That does not look like a connection code. It starts with “hdh1.”.', 'helpdesk-hero' ) );
		}
		$json = base64_decode( strtr( substr( $code, strlen( self::CODE_PREFIX ) ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe transport encoding of a JSON connection code.
		$data = $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || empty( $data['u'] ) || empty( $data['t'] ) || ! Helpdesk_Hero_Signer::valid_url( $data['u'] ) ) {
			return new WP_Error( 'helpdesk_hero_code', __( 'The connection code is incomplete. Copy it again from your support team.', 'helpdesk-hero' ) );
		}
		if ( ! Helpdesk_Hero_Signer::secure_url( $data['u'] ) ) {
			return new WP_Error( 'helpdesk_hero_insecure', __( 'Your support team’s hub doesn’t use HTTPS, so this site won’t connect to it: your connection key and login links could be read on the way. Ask your support team to turn on HTTPS for their hub.', 'helpdesk-hero' ) );
		}
		return $data;
	}

	/**
	 * Pair this site with a hub.
	 *
	 * @param string $code Connection code.
	 * @return true|WP_Error
	 */
	public static function pair( $code ) {
		$data = self::parse_code( $code );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! Helpdesk_Hero_Signer::secure_url( get_rest_url() ) ) {
			return new WP_Error( 'helpdesk_hero_insecure', __( 'This site doesn’t use HTTPS yet, so it can’t connect to your support team: the connection key and login links could be read on the way. Turn on HTTPS for this site (most hosts offer free certificates; ask yours), then try again.', 'helpdesk-hero' ) );
		}
		$pair     = Helpdesk_Hero_Crypto::keypair();
		$response = Helpdesk_Hero_Signer::decode(
			wp_remote_post(
				Helpdesk_Hero_Signer::url( $data['u'], self::HUB_NS . '/pair' ),
				array(
					'timeout' => 20,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode(
						array(
							'token'     => $data['t'],
							'name'      => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
							'url'       => home_url( '/' ),
							'endpoint'  => get_rest_url(),
							'email'     => Helpdesk_Hero_Settings::notify_email(),
							'wordpress' => get_bloginfo( 'version' ),
							'version'   => HELPDESK_HERO_VERSION,
							'public_key' => $pair['pk'],
						)
					),
				)
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$hub_key = (string) ( $response['hub_public_key'] ?? '' );
		if ( Helpdesk_Hero_Crypto::valid_public_key( $hub_key ) ) {
			// Each side signs with its own key pair.
			$creds = array(
				'sk'        => $pair['sk'],
				'pk'        => $pair['pk'],
				'peer'      => $hub_key,
				'initiated' => true,
			);
		} elseif ( ! empty( $response['secret'] ) && preg_match( '/^[a-f0-9]{32,128}$/', (string) $response['secret'] ) ) {
			// An older hub: one shared key. Upgraded to key pairs once the hub is updated.
			$creds = array( 'hmac' => (string) $response['secret'] );
		} else {
			$creds = array();
		}
		if ( empty( $response['site_id'] ) || ! $creds ) {
			return new WP_Error( 'helpdesk_hero_pair', __( 'The support hub did not accept the connection.', 'helpdesk-hero' ) );
		}
		self::save_creds( $creds );
		Helpdesk_Hero_Settings::update(
			array(
				'hub_url'      => esc_url_raw( $data['u'] ),
				'hub_site_id'  => (int) $response['site_id'],
				'hub_name'     => sanitize_text_field( $response['hub_name'] ?? ( $data['n'] ?? '' ) ),
				'hub_cursor'   => 0,
				'connected_at' => gmdate( 'c' ),
				'policy'       => Helpdesk_Hero_Policy::sanitize( (array) ( $response['policy'] ?? array() ) ),
				'branding'     => self::clean_branding( $response['branding'] ?? array() ),
				'extras'       => self::clean_extras( $response['extras'] ?? array() ),
			)
		);
		self::schedule();
		return true;
	}

	/**
	 * Disconnect from the hub. Active support access ends too.
	 */
	public static function disconnect() {
		if ( Helpdesk_Hero_Settings::is_connected() ) {
			self::hub_request( self::HUB_NS . '/unpair', 'POST', array(), 8 );
		}
		foreach ( Helpdesk_Hero_Access::all( true, 500 ) as $grant ) {
			Helpdesk_Hero_Access::revoke( (int) $grant['id'] );
		}
		self::save_creds( array() );
		Helpdesk_Hero_Settings::update(
			array(
				'hub_url'      => '',
				'hub_site_id'  => 0,
				'hub_name'     => '',
				'hub_cursor'   => 0,
				'connected_at' => '',
				'policy'       => array(),
				'branding'     => array(),
				'extras'       => array(),
			)
		);
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * Settings from the hub that travel with the policy: custom ticket fields and attachments.
	 *
	 * @param mixed $in Raw.
	 * @return array { fields[], attachments{} }
	 */
	public static function clean_extras( $in ) {
		$in     = is_array( $in ) ? $in : array();
		$fields = array();
		foreach ( array_slice( (array) ( $in['fields'] ?? array() ), 0, 20 ) as $f ) {
			$type = (string) ( $f['type'] ?? '' );
			$id   = sanitize_key( (string) ( $f['id'] ?? '' ) );
			if ( '' === $id || ! in_array( $type, array( 'text', 'textarea', 'number', 'url', 'select', 'checkbox' ), true ) ) {
				continue;
			}
			$fields[] = array(
				'id'       => $id,
				'label'    => sanitize_text_field( (string) ( $f['label'] ?? '' ) ),
				'type'     => $type,
				'options'  => array_values( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $f['options'] ?? array() ) ) ) ),
				'required' => ! empty( $f['required'] ),
				'help'     => sanitize_text_field( (string) ( $f['help'] ?? '' ) ),
			);
		}
		$a = (array) ( $in['attachments'] ?? array() );
		return array(
			'usage'       => self::clean_usage( $in['usage'] ?? null ),
			'fields'      => $fields,
			'attachments' => array(
				'enabled'   => ! empty( $a['enabled'] ),
				'max_mb'    => max( 1, min( 25, (int) ( $a['max_mb'] ?? 5 ) ) ),
				'max_files' => max( 1, min( 10, (int) ( $a['max_files'] ?? 5 ) ) ),
				'types'     => array_values( array_map( 'sanitize_key', (array) ( $a['types'] ?? array() ) ) ),
				'keep_days' => max( 0, min( 3650, (int) ( $a['keep_days'] ?? 0 ) ) ),
			),
		);
	}

	/**
	 * Time the support team spent for this site, when they choose to show it.
	 *
	 * @param mixed $in Raw.
	 * @return array|null
	 */
	public static function clean_usage( $in ) {
		if ( ! is_array( $in ) ) {
			return null;
		}
		$tickets = array();
		foreach ( (array) ( $in['tickets'] ?? array() ) as $hub_id => $minutes ) {
			$tickets[ (int) $hub_id ] = max( 0, (int) $minutes );
		}
		$money  = static function ( $v ) {
			return round( max( 0, (float) $v ), 2 );
		};
		$months = array();
		foreach ( array_slice( (array) ( $in['months'] ?? array() ), 0, 12 ) as $m ) {
			if ( ! preg_match( '/^\d{4}-\d{2}$/', (string) ( $m['month'] ?? '' ) ) ) {
				continue;
			}
			$months[] = array(
				'month'          => $m['month'],
				'minutes'        => max( 0, (int) ( $m['minutes'] ?? 0 ) ),
				'retainer_hours' => max( 0, (float) ( $m['retainer_hours'] ?? 0 ) ),
				'retainer_used'  => max( 0, (int) ( $m['retainer_used'] ?? 0 ) ),
				'charged'        => $money( $m['charged'] ?? 0 ),
				'due'            => $money( $m['due'] ?? 0 ),
				'invoiced'       => $money( $m['invoiced'] ?? 0 ),
				'paid'           => $money( $m['paid'] ?? 0 ),
			);
		}
		$items = array();
		foreach ( array_slice( (array) ( $in['items'] ?? array() ), 0, 300 ) as $i ) {
			$items[] = array(
				'hub_ticket_id' => (int) ( $i['hub_ticket_id'] ?? 0 ),
				'subject'       => sanitize_text_field( (string) ( $i['subject'] ?? '' ) ),
				'month'         => preg_match( '/^\d{4}-\d{2}$/', (string) ( $i['month'] ?? '' ) ) ? $i['month'] : '',
				'service'       => sanitize_text_field( (string) ( $i['service'] ?? '' ) ),
				'minutes'       => max( 0, (int) ( $i['minutes'] ?? 0 ) ),
				'amount'        => $money( $i['amount'] ?? 0 ),
				'state'         => in_array( $i['state'] ?? '', array( 'unbilled', 'invoiced', 'paid', 'free' ), true ) ? $i['state'] : 'unbilled',
				'invoice_ref'   => sanitize_text_field( (string) ( $i['invoice_ref'] ?? '' ) ),
			);
		}
		$currency = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $in['currency'] ?? '' ) ) );
		return array(
			'currency'       => 3 === strlen( $currency ) ? $currency : 'EUR',
			'month'          => preg_match( '/^\d{4}-\d{2}$/', (string) ( $in['month'] ?? '' ) ) ? $in['month'] : '',
			'retainer_hours' => max( 0, (float) ( $in['retainer_hours'] ?? 0 ) ),
			'used_minutes'   => max( 0, (int) ( $in['used_minutes'] ?? 0 ) ),
			'month_minutes'  => max( 0, (int) ( $in['month_minutes'] ?? 0 ) ),
			'tickets'        => $tickets,
			'months'         => $months,
			'items'          => $items,
			'due'            => $money( $in['due'] ?? 0 ),
			'invoiced'       => $money( $in['invoiced'] ?? 0 ),
		);
	}

	/**
	 * Answers to custom fields, checked against the fields the hub defined.
	 *
	 * @param array $in Answers { id: value }.
	 * @return array|WP_Error
	 */
	public static function clean_field_answers( array $in ) {
		$out = array();
		foreach ( (array) ( Helpdesk_Hero_Settings::get( 'extras' )['fields'] ?? array() ) as $f ) {
			$v = $in[ $f['id'] ] ?? null;
			switch ( $f['type'] ) {
				case 'checkbox':
					$v = ! empty( $v );
					break;
				case 'number':
					$v = is_numeric( $v ) ? $v + 0 : null;
					break;
				case 'url':
					$v = esc_url_raw( (string) $v );
					break;
				case 'select':
					$v = in_array( (string) $v, $f['options'], true ) ? (string) $v : null;
					break;
				case 'textarea':
					$v = sanitize_textarea_field( (string) $v );
					break;
				default:
					$v = sanitize_text_field( (string) $v );
			}
			if ( null === $v || '' === $v ) {
				if ( ! empty( $f['required'] ) ) {
					/* translators: %s: field label */
					return new WP_Error( 'helpdesk_hero_field', sprintf( __( 'Fill in “%s”.', 'helpdesk-hero' ), $f['label'] ) );
				}
				continue;
			}
			$out[ $f['id'] ] = $v;
		}
		return $out;
	}

	/**
	 * The connection keys (decrypted). See Helpdesk_Hero_Crypto.
	 *
	 * @return array
	 */
	public static function creds() {
		$keys = Helpdesk_Hero_Settings::secret( 'hub_keys' );
		if ( '' !== $keys ) {
			return Helpdesk_Hero_Crypto::load( $keys );
		}
		$legacy = Helpdesk_Hero_Settings::secret( 'hub_secret' );
		return '' !== $legacy ? array( 'hmac' => $legacy ) : array();
	}

	/**
	 * Save the connection keys (encrypted; [] removes them).
	 *
	 * @param array $creds Credentials.
	 */
	public static function save_creds( array $creds ) {
		Helpdesk_Hero_Settings::set_secret( 'hub_keys', Helpdesk_Hero_Crypto::export( $creds ) );
		Helpdesk_Hero_Settings::set_secret( 'hub_secret', '' );
	}

	/**
	 * Move a connection made with a shared key (before 2.1) to key pairs. Runs with the regular
	 * sync, at most twice a day, until the hub (2.1 or later) accepts.
	 */
	public static function maybe_upgrade_keys() {
		$creds = self::creds();
		if ( ! Helpdesk_Hero_Crypto::needs_upgrade( $creds ) || get_transient( 'helpdesk_hero_keys_tried' ) ) {
			return;
		}
		set_transient( 'helpdesk_hero_keys_tried', 1, 12 * HOUR_IN_SECONDS );
		$pair   = Helpdesk_Hero_Crypto::keypair();
		$result = self::hub_request( self::HUB_NS . '/keys', 'POST', array( 'public_key' => $pair['pk'] ), 15 );
		if ( is_wp_error( $result ) || ! Helpdesk_Hero_Crypto::valid_public_key( (string) ( $result['public_key'] ?? '' ) ) ) {
			return;
		}
		// Sign with the new key from now on; keep the shared key until the hub has used its new one.
		self::save_creds(
			array(
				'hmac'      => $creds['hmac'],
				'sk'        => $pair['sk'],
				'pk'        => $pair['pk'],
				'peer'      => (string) $result['public_key'],
				'initiated' => true,
			)
		);
		Helpdesk_Hero_Monitor::record( 'connection', 'keys_upgraded', '', array() );
	}

	/**
	 * The hub replaces its keys: answer with a new key pair for this site. The keys that signed
	 * the request stay valid until the hub uses the new ones.
	 *
	 * @param string $hub_key The hub's new public key.
	 * @param string $matched Which keys signed the request: current | old.
	 * @return array|WP_Error { public_key }
	 */
	public static function rotate_keys( $hub_key, $matched ) {
		if ( ! Helpdesk_Hero_Crypto::valid_public_key( $hub_key ) ) {
			return new WP_Error( 'helpdesk_hero_keys', __( 'The hub sent a key that isn’t valid.', 'helpdesk-hero' ), array( 'status' => 400 ) );
		}
		$creds = self::creds();
		$base  = 'old' === $matched && ! empty( $creds['old'] ) ? $creds['old'] : $creds;
		unset( $base['old'] );
		$pair = Helpdesk_Hero_Crypto::keypair();
		self::save_creds(
			array(
				'sk'   => $pair['sk'],
				'pk'   => $pair['pk'],
				'peer' => $hub_key,
				'old'  => $base,
			)
		);
		Helpdesk_Hero_Monitor::record( 'connection', 'keys_rotated', '', array() );
		return array( 'public_key' => $pair['pk'] );
	}

	/**
	 * Poll the hub every 10 minutes while connected.
	 */
	public static function schedule() {
		if ( Helpdesk_Hero_Settings::is_connected() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'helpdesk_hero_10min', self::CRON );
		}
	}

	/**
	 * Signed request to the hub.
	 *
	 * @param string $route   Route.
	 * @param string $method  Method.
	 * @param array  $data    Data.
	 * @param int    $timeout Timeout.
	 * @return array|WP_Error
	 */
	public static function hub_request( $route, $method, $data = array(), $timeout = 20 ) {
		if ( ! Helpdesk_Hero_Settings::is_connected() ) {
			return new WP_Error( 'helpdesk_hero_not_connected', __( 'This site is not connected to a support team.', 'helpdesk-hero' ) );
		}
		$result = Helpdesk_Hero_Signer::request(
			Helpdesk_Hero_Settings::get( 'hub_url' ),
			$route,
			$method,
			$data,
			(string) Helpdesk_Hero_Settings::get( 'hub_site_id' ),
			self::creds(),
			$timeout
		);
		if ( is_wp_error( $result ) && '/updates' !== substr( $route, -8 ) ) {
			// Background polling failures are expected now and then; anything else is worth keeping.
			$error_data = $result->get_error_data();
			Helpdesk_Hero_Monitor::record(
				'connection',
				'hub_error',
				$method . ' ' . $route,
				array(
					'message' => $result->get_error_message(),
					'status'  => is_array( $error_data ) && isset( $error_data['status'] ) ? (int) $error_data['status'] : 0,
				)
			);
		}
		return $result;
	}

	/**
	 * Check the connection: the hub is reachable, accepts this site's signature, and the clocks agree.
	 *
	 * @return array|WP_Error { hub_name, version, clock_skew }
	 */
	public static function test() {
		$start  = time();
		$result = self::hub_request( self::HUB_NS . '/ping', 'GET', array(), 15 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'helpdesk_hero_ping', __( 'The support hub answered, but not as expected. Is Helpdesk Hero Hub up to date there?', 'helpdesk-hero' ) );
		}
		return array(
			'hub_name'   => sanitize_text_field( (string) ( $result['hub_name'] ?? '' ) ),
			'version'    => sanitize_text_field( (string) ( $result['version'] ?? '' ) ),
			'clock_skew' => isset( $result['time'] ) ? (int) $result['time'] - $start : 0,
		);
	}

	/**
	 * Fetch and apply updates from the hub.
	 *
	 * @param bool $throttle Skip if pulled in the last minute.
	 * @return int|WP_Error Updates applied.
	 */
	public static function pull( $throttle = false ) {
		if ( ! Helpdesk_Hero_Settings::is_connected() ) {
			return 0;
		}
		if ( $throttle ) {
			if ( get_transient( 'helpdesk_hero_pulled' ) ) {
				return 0;
			}
			set_transient( 'helpdesk_hero_pulled', 1, MINUTE_IN_SECONDS );
		}
		self::sync_pending();
		self::maybe_upgrade_keys();
		if ( ! get_transient( 'helpdesk_hero_files_cleanup' ) ) {
			set_transient( 'helpdesk_hero_files_cleanup', 1, 6 * HOUR_IN_SECONDS );
			Helpdesk_Hero_Attachments::cleanup();
		}
		$result = self::hub_request(
			self::HUB_NS . '/updates',
			'GET',
			array(
				'cursor'    => (int) Helpdesk_Hero_Settings::get( 'hub_cursor' ),
				'version'   => HELPDESK_HERO_VERSION,
				'wordpress' => get_bloginfo( 'version' ),
			),
			15
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( array_key_exists( 'usage', $result ) ) {
			$extras          = (array) Helpdesk_Hero_Settings::get( 'extras' );
			$extras['usage'] = self::clean_usage( $result['usage'] );
			Helpdesk_Hero_Settings::update( array( 'extras' => $extras ) );
		}
		return self::apply( (array) ( $result['items'] ?? array() ) );
	}

	/**
	 * Apply updates in order, each once.
	 *
	 * @param array $items Items: id, type, hub_ticket_id, payload.
	 * @return int Applied.
	 */
	public static function apply( array $items ) {
		$cursor  = (int) Helpdesk_Hero_Settings::get( 'hub_cursor' );
		$applied = 0;
		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);
		foreach ( $items as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( $id <= $cursor ) {
				continue;
			}
			self::apply_item( (string) ( $item['type'] ?? '' ), (int) ( $item['hub_ticket_id'] ?? 0 ), (array) ( $item['payload'] ?? array() ) );
			$cursor = $id;
			Helpdesk_Hero_Settings::update( array( 'hub_cursor' => $cursor ) );
			++$applied;
		}
		return $applied;
	}

	/**
	 * Apply one update.
	 *
	 * @param string $type          Type.
	 * @param int    $hub_ticket_id Hub ticket.
	 * @param array  $payload       Payload.
	 */
	private static function apply_item( $type, $hub_ticket_id, array $payload ) {
		$ticket = $hub_ticket_id ? Helpdesk_Hero_Tickets::by_remote( $hub_ticket_id ) : null;
		switch ( $type ) {
			case 'reply':
				if ( $ticket ) {
					$added = Helpdesk_Hero_Tickets::add_message(
						(int) $ticket['id'],
						'in',
						sanitize_text_field( $payload['author'] ?? Helpdesk_Hero_Settings::support_name() ),
						sanitize_textarea_field( $payload['body'] ?? '' ),
						sanitize_text_field( (string) ( $payload['thread_id'] ?? '' ) ),
						self::mysql_time( $payload['created_at'] ?? '' )
					);
					if ( $added && ! empty( $payload['attachments'] ) ) {
						Helpdesk_Hero_Attachments::from_hub( (int) $ticket['id'], $added, (array) $payload['attachments'] );
					}
					if ( $added ) {
						Helpdesk_Hero_Tickets::update(
							(int) $ticket['id'],
							array(
								'unread' => 1,
								'status' => 'closed' === $ticket['status'] ? 'closed' : 'pending',
							)
						);
						self::notify_reply( $ticket, (string) ( $payload['body'] ?? '' ) );
					}
				}
				break;
			case 'status':
				if ( $ticket ) {
					$status = in_array( $payload['status'] ?? '', array( 'open', 'pending', 'closed' ), true ) ? $payload['status'] : 'open';
					Helpdesk_Hero_Tickets::update(
						(int) $ticket['id'],
						array(
							'status'       => $status,
							// A status name the team chose to show (for example "In progress").
							'status_label' => substr( sanitize_text_field( (string) ( $payload['label'] ?? '' ) ), 0, 40 ),
						)
					);
					if ( 'closed' === $status && 'closed' !== $ticket['status'] ) {
						self::end_access_on_close( $ticket );
					}
				}
				break;
			case 'tags':
				if ( $ticket ) {
					$tags = array();
					foreach ( (array) ( $payload['tags'] ?? array() ) as $tag ) {
						$color  = sanitize_hex_color( (string) ( $tag['color'] ?? '' ) );
						$tags[] = array(
							'name'  => sanitize_text_field( (string) ( $tag['name'] ?? '' ) ),
							'color' => $color ? $color : '#6b6963',
						);
					}
					Helpdesk_Hero_Tickets::update(
						(int) $ticket['id'],
						array(
							'tags'       => wp_json_encode( $tags ),
							'updated_at' => $ticket['updated_at'],
						)
					);
				}
				break;
			case 'reference':
				if ( $ticket ) {
					Helpdesk_Hero_Tickets::update( (int) $ticket['id'], array( 'helpdesk_ref' => sanitize_text_field( (string) ( $payload['reference'] ?? '' ) ) ) );
				}
				break;
			case 'notice':
				Helpdesk_Hero_Notices::add(
					array(
						'title'     => sanitize_text_field( $payload['title'] ?? '' ),
						'body'      => sanitize_textarea_field( $payload['body'] ?? '' ),
						'level'     => in_array( $payload['level'] ?? '', array( 'info', 'success', 'warning', 'error' ), true ) ? $payload['level'] : 'info',
						'ticket_id' => $ticket ? (int) $ticket['id'] : 0,
						'action'    => self::clean_action( $payload['action'] ?? array() ),
					)
				);
				break;
			case 'extension_request':
				$grant = Helpdesk_Hero_Access::active();
				if ( $grant ) {
					Helpdesk_Hero_Access::request_extension( (int) $grant['id'], (int) ( $payload['hours'] ?? 24 ), (string) ( $payload['reason'] ?? '' ), (string) ( $payload['by'] ?? '' ) );
				}
				break;
			case 'end_access':
				// Support ended access from the help desk: the accounts go now.
				$grant = Helpdesk_Hero_Access::active();
				if ( $grant ) {
					Helpdesk_Hero_Access::revoke( (int) $grant['id'], 'support_ended', sanitize_text_field( (string) ( $payload['by'] ?? '' ) ) );
				}
				break;
			case 'removed':
				if ( $ticket && 'closed' !== $ticket['status'] ) {
					Helpdesk_Hero_Tickets::add_message( (int) $ticket['id'], 'system', '', __( 'Your support team removed this ticket from their system. It’s closed; open a new ticket if you still need help.', 'helpdesk-hero' ) );
					Helpdesk_Hero_Tickets::update( (int) $ticket['id'], array( 'status' => 'closed' ) );
				}
				break;
			case 'merged':
				if ( $ticket ) {
					Helpdesk_Hero_Tickets::add_message(
						(int) $ticket['id'],
						'system',
						'',
						sprintf(
							/* translators: %s: subject of the other ticket */
							__( 'Your support team merged this ticket into “%s”. The conversation continues there.', 'helpdesk-hero' ),
							sanitize_text_field( (string) ( $payload['subject'] ?? '' ) )
						)
					);
					// Closed without ending support access: the work goes on in the other ticket.
					Helpdesk_Hero_Tickets::update( (int) $ticket['id'], array( 'status' => 'closed' ) );
				}
				break;
			case 'policy':
				Helpdesk_Hero_Settings::update(
					array(
						'policy'   => Helpdesk_Hero_Policy::sanitize( (array) ( $payload['policy'] ?? array() ) ),
						'branding' => self::clean_branding( $payload['branding'] ?? array() ),
						'extras'   => self::clean_extras( $payload['extras'] ?? array() ),
					)
				);
				if ( ! empty( $payload['branding']['name'] ) ) {
					Helpdesk_Hero_Settings::update( array( 'hub_name' => sanitize_text_field( $payload['branding']['name'] ) ) );
				}
				break;
		}

		/**
		 * Fires after an update from the hub is applied. Add-ons can handle their own types.
		 *
		 * @param string     $type    Type.
		 * @param array      $payload Payload.
		 * @param array|null $ticket  Local ticket.
		 */
		do_action( 'helpdesk_hero_hub_update', $type, $payload, $ticket );
	}

	/**
	 * Email the site owner about a support reply.
	 *
	 * @param array  $ticket Ticket.
	 * @param string $body   Reply.
	 */
	private static function notify_reply( array $ticket, $body ) {
		wp_mail(
			Helpdesk_Hero_Settings::notify_email(),
			/* translators: 1: support name, 2: subject */
			sprintf( __( '%1$s replied: %2$s', 'helpdesk-hero' ), Helpdesk_Hero_Settings::support_name(), $ticket['subject'] ),
			wp_trim_words( $body, 120 ) . "\n\n" . __( 'Read and reply in your dashboard:', 'helpdesk-hero' ) . ' ' . admin_url( 'admin.php?page=helpdesk-hero#/ticket/' . (int) $ticket['id'] )
		);
	}

	/**
	 * Tell the hub when support access changes, so agents see it.
	 *
	 * @param int    $grant_id Grant.
	 * @param string $event    Event.
	 */
	public static function report_access( $grant_id, $event ) {
		global $wpdb;
		$grant = Helpdesk_Hero_Access::get( $grant_id );
		if ( ! $grant || ! Helpdesk_Hero_Settings::is_connected() ) {
			return;
		}
		// Access is per site, so every open ticket learns about it (and the ticket it started on).
		$tickets = $wpdb->get_results( $wpdb->prepare( "SELECT id, remote_id FROM %i WHERE channel = 'hub' AND remote_id > 0 AND ( status <> 'closed' OR id = %d OR grant_id = %d ) ORDER BY id DESC LIMIT 25", Helpdesk_Hero_DB::table( 'tickets' ), (int) $grant['ticket_id'], (int) $grant_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$body    = array_merge( self::access_payload( $grant ), array( 'event' => sanitize_key( $event ) ) );
		foreach ( $tickets as $ticket ) {
			if ( Helpdesk_Hero_Access::is_active( $grant ) && 'closed' !== ( Helpdesk_Hero_Tickets::get( (int) $ticket['id'] )['status'] ?? '' ) ) {
				Helpdesk_Hero_Tickets::update( (int) $ticket['id'], array( 'grant_id' => (int) $grant_id ) );
			}
			self::hub_request( self::HUB_NS . '/tickets/' . (int) $ticket['remote_id'] . '/access', 'POST', $body, 8 );
		}
	}

	/**
	 * Branding from the hub, sanitized.
	 *
	 * @param mixed $branding Raw.
	 * @return array
	 */
	public static function clean_branding( $branding ) {
		$branding = is_array( $branding ) ? $branding : array();
		$color    = sanitize_hex_color( $branding['color'] ?? '' );
		return array_filter(
			array(
				'name'    => sanitize_text_field( $branding['name'] ?? '' ),
				'center'  => substr( sanitize_text_field( $branding['center'] ?? '' ), 0, 40 ),
				'contact' => sanitize_textarea_field( $branding['contact'] ?? '' ),
				'logo'  => esc_url_raw( $branding['logo'] ?? '', array( 'http', 'https' ) ),
				'color' => $color ? $color : '',
				'url'   => esc_url_raw( $branding['url'] ?? '' ),
				'intro' => sanitize_textarea_field( $branding['intro'] ?? '' ),
			)
		);
	}

	/**
	 * Optional link button on a notice: this site's admin ("admin:plugins.php") or an http(s) URL.
	 *
	 * @param mixed $action Raw { label, url }.
	 * @return array
	 */
	private static function clean_action( $action ) {
		if ( ! is_array( $action ) || empty( $action['label'] ) || empty( $action['url'] ) ) {
			return array();
		}
		$url = 0 === strpos( $action['url'], 'admin:' ) ? admin_url( ltrim( substr( $action['url'], 6 ), '/' ) ) : esc_url_raw( $action['url'], array( 'https', 'http' ) );
		return '' !== $url ? array(
			'label' => sanitize_text_field( $action['label'] ),
			'url'   => $url,
		) : array();
	}

	/**
	 * MySQL UTC time from an ISO date, or '' when invalid.
	 *
	 * @param string $iso Date.
	 * @return string
	 */
	private static function mysql_time( $iso ) {
		$ts = $iso ? strtotime( (string) $iso ) : false;
		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
}
