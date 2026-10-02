<?php
/**
 * Settings storage.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * The connection to the support hub, the policy and branding it sent, and the site owner's
 * own preferences. The hub secret is kept in a separate option that is never autoloaded.
 */
final class Helpdesk_Hero_Settings {

	const OPTION  = 'helpdesk_hero_settings';
	const SECRETS = 'helpdesk_hero_secrets';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'hub_url'      => '',
			'hub_site_id'  => 0,
			'hub_name'     => '',
			'hub_cursor'   => 0,
			'connected_at' => '',
			'policy'       => array(),
			'branding'     => array(),
			'notify_email' => '',
		);
	}

	/**
	 * All settings.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Merge and save (unknown keys dropped).
	 *
	 * @param array $values Values.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), array_intersect_key( $values, self::defaults() ) ) );
	}

	/**
	 * A secret.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function secret( $key ) {
		$stored = get_option( self::SECRETS, array() );
		return is_array( $stored ) && isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
	}

	/**
	 * Save a secret ('' removes it).
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 */
	public static function set_secret( $key, $value ) {
		$stored = get_option( self::SECRETS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( '' === (string) $value ) {
			unset( $stored[ $key ] );
		} else {
			$stored[ $key ] = (string) $value;
		}
		update_option( self::SECRETS, $stored, false );
	}

	/**
	 * Whether the site is connected to a support hub.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return (int) self::get( 'hub_site_id' ) > 0 && '' !== self::secret( 'hub_secret' );
	}

	/**
	 * Name of the support team.
	 *
	 * @return string
	 */
	public static function support_name() {
		$branding = (array) self::get( 'branding' );
		if ( ! empty( $branding['name'] ) ) {
			return (string) $branding['name'];
		}
		$name = (string) self::get( 'hub_name' );
		return '' !== $name ? $name : __( 'Support', 'helpdesk-hero' );
	}

	/**
	 * Email address for notifications to the site owner.
	 *
	 * @return string
	 */
	public static function notify_email() {
		$email = (string) self::get( 'notify_email' );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}
}
