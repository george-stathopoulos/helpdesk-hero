<?php
/**
 * Tickets and messages stored on the customer site.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Local copy of every ticket the site opened, with the conversation, so the site owner can
 * read past tickets in wp-admin. There is no limit on the number of tickets.
 */
final class Helpdesk_Hero_Tickets {

	/**
	 * Status labels.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'open'    => __( 'Open', 'helpdesk-hero' ),
			'pending' => __( 'Waiting for you', 'helpdesk-hero' ),
			'sent'    => __( 'Sent by email', 'helpdesk-hero' ),
			'unsent'  => __( 'Not sent yet', 'helpdesk-hero' ),
			'closed'  => __( 'Closed', 'helpdesk-hero' ),
		);
	}

	/**
	 * Priority labels.
	 *
	 * @return array
	 */
	public static function priorities() {
		return array(
			'low'    => __( 'Low: a question or small issue', 'helpdesk-hero' ),
			'normal' => __( 'Normal: something is not working', 'helpdesk-hero' ),
			'high'   => __( 'High: an important feature is broken', 'helpdesk-hero' ),
			'urgent' => __( 'Urgent: the site is down or losing sales', 'helpdesk-hero' ),
		);
	}

	/**
	 * Insert a ticket.
	 *
	 * @param array $data Columns.
	 * @return int ID.
	 */
	public static function insert( array $data ) {
		global $wpdb;
		$now = Helpdesk_Hero_DB::now();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			Helpdesk_Hero_DB::table( 'tickets' ),
			array_merge(
				array(
					'subject'    => '',
					'status'     => 'open',
					'channel'    => 'email',
					'priority'   => 'normal',
					'created_by' => get_current_user_id(),
					'created_at' => $now,
					'updated_at' => $now,
				),
				$data
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a ticket.
	 *
	 * @param int   $id   ID.
	 * @param array $data Columns.
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = $data['updated_at'] ?? Helpdesk_Hero_DB::now();
		$wpdb->update( Helpdesk_Hero_DB::table( 'tickets' ), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * One ticket.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Helpdesk_Hero_DB::table( 'tickets' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Ticket by its ID on the hub.
	 *
	 * @param int $remote_id Hub ticket ID.
	 * @return array|null
	 */
	public static function by_remote( $remote_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE remote_id = %d AND channel = %s', Helpdesk_Hero_DB::table( 'tickets' ), $remote_id, 'hub' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Tickets, most recently updated first.
	 *
	 * @param string $status Filter: '' (all), 'active' (not closed) or a status.
	 * @param int    $limit  Limit.
	 * @param int    $offset Offset.
	 * @return array[]
	 */
	public static function all( $status = '', $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = Helpdesk_Hero_DB::table( 'tickets' );
		if ( 'active' === $status ) {
			$sql = $wpdb->prepare( "SELECT * FROM %i WHERE status <> %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table, 'closed', $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} elseif ( '' !== $status ) {
			$sql = $wpdb->prepare( "SELECT * FROM %i WHERE status = %s ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table, $status, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d OFFSET %d", $table, $limit, $offset ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count of tickets with unread replies.
	 *
	 * @return int
	 */
	public static function unread_count() {
		global $wpdb;
		if ( get_option( Helpdesk_Hero_DB::OPTION ) !== Helpdesk_Hero_DB::VERSION ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE unread = 1', Helpdesk_Hero_DB::table( 'tickets' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Add a message. Messages with a remote ID that is already stored are skipped.
	 *
	 * @param int    $ticket_id Ticket.
	 * @param string $direction out (from the site), in (from support), system.
	 * @param string $author    Author name.
	 * @param string $body      Text.
	 * @param string $remote_id Help desk thread ID.
	 * @param string $created   MySQL UTC time, or '' for now.
	 * @return int Message ID, or 0 when skipped.
	 */
	public static function add_message( $ticket_id, $direction, $author, $body, $remote_id = '', $created = '' ) {
		global $wpdb;
		$table = Helpdesk_Hero_DB::table( 'messages' );
		if ( '' !== $remote_id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE ticket_id = %d AND remote_id = %s", $table, $ticket_id, $remote_id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return 0;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'ticket_id'  => (int) $ticket_id,
				'direction'  => $direction,
				'author'     => substr( (string) $author, 0, 190 ),
				'body'       => (string) $body,
				'remote_id'  => (string) $remote_id,
				'created_at' => '' !== $created ? $created : Helpdesk_Hero_DB::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Messages of a ticket, oldest first.
	 *
	 * @param int $ticket_id Ticket.
	 * @return array[]
	 */
	public static function messages( $ticket_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ticket_id = %d ORDER BY created_at ASC, id ASC', Helpdesk_Hero_DB::table( 'messages' ), $ticket_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Delete everything (used by the privacy eraser and uninstall).
	 */
	public static function delete_all() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Helpdesk_Hero_DB::table( 'messages' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Helpdesk_Hero_DB::table( 'tickets' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
