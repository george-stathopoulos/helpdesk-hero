<?php
/**
 * The support team's policy, as received from the hub.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Support decides what customers can do (access level and length, extensions, replies,
 * which diagnostics are sent…). The hub sends its policy when the site connects and whenever
 * it changes; this class reads it and enforces it on the site, so a customer can never choose
 * something the support team did not allow. Values are re-checked here, whatever the hub sent.
 */
final class Helpdesk_Hero_Policy {

	/**
	 * "Hours" that mean access with no end date ("Until I end it").
	 */
	const PERMANENT = 999999;

	/**
	 * Safe defaults (used for anything the hub did not send).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'access'      => array(
				'mode'              => 'ask',
				'roles'             => array( 'restricted_admin' ),
				'default_role'      => 'restricted_admin',
				'customer_role'     => false,
				'default_hours'     => 24,
				'max_hours'         => 168,
				'customer_duration' => true,
				'plugin_installs'   => false,
				'extension'         => 'approve',
				'customer_extend'   => true,
				'log_page_views'    => true,
				'troubleshooting'   => true,
				'end_on_close'      => true,
				'permanent'         => false,
			),
			'pinpoint'    => array(
				'mode' => 'customer',
			),
			'tickets'     => array(
				'customer_replies' => true,
				'customer_close'   => true,
				'priorities'       => true,
				'categories'       => array(),
				'intro'            => '',
				'manual_email'     => '',
				'ai_assistant'     => true,
				'ratings'          => false,
			),
			'diagnostics' => array(
				'environment' => 'required',
				'extensions'  => 'required',
				'errors'      => 'on',
				'changes'     => 'on',
				'debug_log'   => 'off',
			),
		);
	}

	/**
	 * Current policy.
	 *
	 * @return array
	 */
	public static function get() {
		return self::sanitize( (array) Helpdesk_Hero_Settings::get( 'policy' ) );
	}

	/**
	 * One section of the policy.
	 *
	 * @param string $section access | tickets | diagnostics.
	 * @return array
	 */
	public static function section( $section ) {
		$policy = self::get();
		return (array) ( $policy[ $section ] ?? array() );
	}

	/**
	 * Clean a policy received from the hub.
	 *
	 * @param array $in Raw.
	 * @return array
	 */
	public static function sanitize( array $in ) {
		$d     = self::defaults();
		$a     = array_merge( $d['access'], (array) ( $in['access'] ?? array() ) );
		$t     = array_merge( $d['tickets'], (array) ( $in['tickets'] ?? array() ) );
		$dg    = array_merge( $d['diagnostics'], (array) ( $in['diagnostics'] ?? array() ) );
		$known = array( 'restricted_admin', 'administrator', 'editor', 'shop_manager' );
		$roles = array_values( array_intersect( array_map( 'sanitize_key', (array) $a['roles'] ), $known ) );
		if ( ! $roles ) {
			$roles = array( 'restricted_admin' );
		}
		$max       = max( 1, min( 24 * 30, (int) $a['max_hours'] ) );
		$permanent = ! empty( $a['permanent'] );
		$default   = $permanent && self::PERMANENT === (int) $a['default_hours'] ? self::PERMANENT : max( 1, min( $max, (int) $a['default_hours'] ) );
		$p         = array_merge( $d['pinpoint'], (array) ( $in['pinpoint'] ?? array() ) );

		$sections = array();
		foreach ( $d['diagnostics'] as $key => $fallback ) {
			$sections[ $key ] = in_array( $dg[ $key ] ?? '', array( 'required', 'on', 'off', 'never' ), true ) ? $dg[ $key ] : $fallback;
		}

		return array(
			'access'      => array(
				'mode'              => in_array( $a['mode'], array( 'ask', 'always', 'off' ), true ) ? $a['mode'] : 'ask',
				'roles'             => $roles,
				'default_role'      => in_array( $a['default_role'], $roles, true ) ? $a['default_role'] : $roles[0],
				'customer_role'     => (bool) $a['customer_role'] && count( $roles ) > 1,
				'default_hours'     => $default,
				'max_hours'         => $max,
				'customer_duration' => (bool) $a['customer_duration'],
				'plugin_installs'   => (bool) $a['plugin_installs'],
				'extension'         => 'auto' === $a['extension'] ? 'auto' : 'approve',
				'customer_extend'   => (bool) $a['customer_extend'],
				'log_page_views'    => (bool) $a['log_page_views'],
				'troubleshooting'   => (bool) $a['troubleshooting'],
				'end_on_close'      => (bool) $a['end_on_close'],
				'permanent'         => $permanent,
				'rules'             => self::clean_access_rules( $a['rules'] ?? array() ),
			),
			'pinpoint'    => array(
				'mode' => in_array( $p['mode'], array( 'customer', 'on', 'always', 'off' ), true ) ? $p['mode'] : 'customer',
			),
			'tickets'     => array(
				'customer_replies' => (bool) $t['customer_replies'],
				'customer_close'   => (bool) $t['customer_close'],
				'priorities'       => (bool) $t['priorities'],
				'categories'       => array_values( array_slice( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) $t['categories'] ) ) ), 0, 20 ) ),
				'intro'            => sanitize_textarea_field( (string) $t['intro'] ),
				'manual_email'     => is_email( $t['manual_email'] ) ? sanitize_email( $t['manual_email'] ) : '',
				'ai_assistant'     => (bool) $t['ai_assistant'],
				'ratings'          => (bool) $t['ratings'],
			),
			'diagnostics' => $sections,
		);
	}

	/**
	 * What the New ticket screen does about support access for a category and priority:
	 * off (not offered), required (comes with the ticket), ticked or unticked (the customer
	 * chooses). A rule for the category wins over one for the priority.
	 *
	 * @param string $category Category.
	 * @param string $priority Priority.
	 * @return string off | required | ticked | unticked
	 */
	public static function access_choice( $category, $priority ) {
		$a = self::section( 'access' );
		if ( 'off' === $a['mode'] ) {
			return 'off';
		}
		$rules = (array) ( $a['rules'] ?? array() );
		$rule  = $rules['categories'][ (string) $category ] ?? ( $rules['priorities'][ (string) $priority ] ?? '' );
		if ( 'always' === $a['mode'] ) {
			return 'off' === $rule ? 'off' : 'required';
		}
		return '' !== $rule ? $rule : 'ticked';
	}

	/**
	 * Durations the customer can pick from, limited by the policy.
	 *
	 * @return array Hours => label.
	 */
	public static function durations() {
		$a   = self::section( 'access' );
		$max = (int) $a['max_hours'];
		$all = array(
			1   => __( '1 hour', 'helpdesk-hero' ),
			4   => __( '4 hours', 'helpdesk-hero' ),
			24  => __( '24 hours', 'helpdesk-hero' ),
			72  => __( '3 days', 'helpdesk-hero' ),
			168 => __( '7 days', 'helpdesk-hero' ),
			336 => __( '14 days', 'helpdesk-hero' ),
			720 => __( '30 days', 'helpdesk-hero' ),
		);
		$out = array_filter(
			$all,
			static function ( $hours ) use ( $max ) {
				return $hours <= $max;
			},
			ARRAY_FILTER_USE_KEY
		);
		if ( ! isset( $out[ $max ] ) ) {
			/* translators: %d: number of hours */
			$out[ $max ] = sprintf( _n( '%d hour', '%d hours', $max, 'helpdesk-hero' ), $max );
		}
		ksort( $out );
		if ( $a['permanent'] ) {
			$out[ self::PERMANENT ] = __( 'Until I end it', 'helpdesk-hero' );
		}
		return $out;
	}

	/**
	 * Access settings for a new grant, after applying the policy to what the customer asked for.
	 *
	 * @param array $asked hours, role.
	 * @return array hours, role, allow_plugins.
	 */
	public static function resolve_access( array $asked ) {
		$a     = self::section( 'access' );
		$hours = $a['customer_duration'] && ! empty( $asked['hours'] ) ? (int) $asked['hours'] : (int) $a['default_hours'];
		$role  = $a['customer_role'] && ! empty( $asked['role'] ) && in_array( $asked['role'], $a['roles'], true ) ? $asked['role'] : $a['default_role'];
		if ( self::PERMANENT === $hours ) {
			// No end date: only when the support team's policy allows it.
			$hours = $a['permanent'] ? self::PERMANENT : (int) $a['max_hours'];
		}
		return array(
			'hours'         => self::PERMANENT === $hours ? $hours : max( 1, min( (int) $a['max_hours'], $hours ) ),
			'role'          => $role,
			'allow_plugins' => (bool) $a['plugin_installs'],
		);
	}

	/**
	 * Diagnostics sections to send, given what the customer ticked.
	 *
	 * @param string[]|null $ticked Ticked sections (null = the policy's defaults).
	 * @return string[]
	 */
	public static function resolve_sections( $ticked ) {
		$out = array();
		foreach ( self::section( 'diagnostics' ) as $key => $rule ) {
			if ( 'required' === $rule ) {
				$out[] = $key;
			} elseif ( 'never' !== $rule && ( null === $ticked ? 'on' === $rule : in_array( $key, (array) $ticked, true ) ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Access rules per category and priority, cleaned.
	 *
	 * @param mixed $in Raw { categories: { name: choice }, priorities: { priority: choice } }.
	 * @return array
	 */
	public static function clean_access_rules( $in ) {
		$in      = is_array( $in ) ? $in : array();
		$choices = array( 'ticked', 'unticked', 'off', 'required' );
		$out     = array(
			'categories' => array(),
			'priorities' => array(),
		);
		foreach ( (array) ( $in['categories'] ?? array() ) as $name => $choice ) {
			if ( in_array( $choice, $choices, true ) ) {
				$out['categories'][ substr( sanitize_text_field( (string) $name ), 0, 60 ) ] = $choice;
			}
		}
		foreach ( (array) ( $in['priorities'] ?? array() ) as $name => $choice ) {
			if ( in_array( $name, array( 'low', 'normal', 'high', 'urgent' ), true ) && in_array( $choice, $choices, true ) ) {
				$out['priorities'][ $name ] = $choice;
			}
		}
		return $out;
	}
}
