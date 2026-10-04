<?php
/**
 * Privacy policy text and personal data tools.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suggested privacy policy text, and export/erase of the tickets a user opened.
 */
final class Helpdesk_Hero_Privacy {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	/**
	 * Suggested policy text.
	 */
	public static function policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$text  = '<p>' . esc_html__( 'This site is connected to its support provider with Helpdesk Hero. When an administrator opens a support ticket, the ticket text, the administrator’s name and email address, and technical information about the site (software versions, active plugins and themes, recent errors and changes, as allowed by the support provider’s policy and chosen by the administrator) are sent to the support provider’s hub, and may be passed on to their help desk (such as Help Scout or Zendesk). Email addresses, passwords and keys found in logs are removed before sending.', 'helpdesk-hero' ) . '</p>';
		$text .= '<p>' . esc_html__( 'When temporary support access is granted, the actions of the support account (pages visited, settings changed, content edited) and its IP address are recorded on this site so the site owner can review them. Visitors to the site are not tracked.', 'helpdesk-hero' ) . '</p>';
		wp_add_privacy_policy_content( 'Helpdesk Hero', wp_kses_post( $text ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 * @return array
	 */
	public static function exporters( $exporters ) {
		$exporters['helpdesk-hero'] = array(
			'exporter_friendly_name' => __( 'Support tickets', 'helpdesk-hero' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 * @return array
	 */
	public static function erasers( $erasers ) {
		$erasers['helpdesk-hero'] = array(
			'eraser_friendly_name' => __( 'Support tickets', 'helpdesk-hero' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Tickets opened by the user with this email.
	 *
	 * @param string $email Email.
	 * @return array[]
	 */
	private static function tickets_for( $email ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array();
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE created_by = %d', Helpdesk_Hero_DB::table( 'tickets' ), $user->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Export.
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function export( $email ) {
		$data = array();
		foreach ( self::tickets_for( $email ) as $ticket ) {
			$messages = array();
			foreach ( Helpdesk_Hero_Tickets::messages( (int) $ticket['id'] ) as $m ) {
				$messages[] = $m['created_at'] . ' ' . $m['author'] . ': ' . $m['body'];
			}
			$data[] = array(
				'group_id'    => 'helpdesk-hero',
				'group_label' => __( 'Support tickets', 'helpdesk-hero' ),
				'item_id'     => 'ticket-' . $ticket['id'],
				'data'        => array(
					array(
						'name'  => __( 'Subject', 'helpdesk-hero' ),
						'value' => $ticket['subject'],
					),
					array(
						'name'  => __( 'Created', 'helpdesk-hero' ),
						'value' => $ticket['created_at'],
					),
					array(
						'name'  => __( 'Messages', 'helpdesk-hero' ),
						'value' => implode( "\n\n", $messages ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase (local copies only; the support provider keeps its own records).
	 *
	 * @param string $email Email.
	 * @return array
	 */
	public static function erase( $email ) {
		global $wpdb;
		$removed = 0;
		foreach ( self::tickets_for( $email ) as $ticket ) {
			Helpdesk_Hero_Attachments::delete_for_ticket( (int) $ticket['id'] );
			$wpdb->delete( Helpdesk_Hero_DB::table( 'messages' ), array( 'ticket_id' => (int) $ticket['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( Helpdesk_Hero_DB::table( 'tickets' ), array( 'id' => (int) $ticket['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			++$removed;
		}
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $removed ? array( __( 'Local copies of support tickets were deleted. The support provider may keep its own copy.', 'helpdesk-hero' ) ) : array(),
			'done'           => true,
		);
	}
}
