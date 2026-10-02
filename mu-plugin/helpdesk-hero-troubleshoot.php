<?php
/**
 * Plugin Name: Helpdesk Hero troubleshooting mode
 * Description: Disables chosen plugins for one browser session only, so support can test for conflicts without affecting visitors. Added by Helpdesk Hero and removed automatically when troubleshooting ends.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $_COOKIE['helpdesk_hero_troubleshoot'] ) ) {
	return;
}

$helpdesk_hero_sessions = get_option( 'helpdesk_hero_troubleshoot', array() );
$helpdesk_hero_key      = hash( 'sha256', (string) wp_unslash( $_COOKIE['helpdesk_hero_troubleshoot'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed, never output.

if ( ! is_array( $helpdesk_hero_sessions ) || empty( $helpdesk_hero_sessions[ $helpdesk_hero_key ] ) || (int) $helpdesk_hero_sessions[ $helpdesk_hero_key ]['expires'] < time() ) {
	unset( $helpdesk_hero_sessions, $helpdesk_hero_key );
	return;
}

define( 'HELPDESK_HERO_TROUBLESHOOTING', true );

$helpdesk_hero_session = $helpdesk_hero_sessions[ $helpdesk_hero_key ];
$helpdesk_hero_keep    = array_merge( (array) $helpdesk_hero_session['keep'], array( 'helpdesk-hero/helpdesk-hero.php' ) );

add_filter(
	'option_active_plugins',
	static function ( $plugins ) use ( $helpdesk_hero_keep ) {
		return array_values( array_intersect( (array) $plugins, $helpdesk_hero_keep ) );
	},
	1
);
add_filter(
	'site_option_active_sitewide_plugins',
	static function ( $plugins ) use ( $helpdesk_hero_keep ) {
		return array_intersect_key( (array) $plugins, array_flip( $helpdesk_hero_keep ) );
	},
	1
);

if ( ! empty( $helpdesk_hero_session['theme'] ) ) {
	$helpdesk_hero_theme = (string) $helpdesk_hero_session['theme'];
	add_filter(
		'pre_option_template',
		static function () use ( $helpdesk_hero_theme ) {
			return $helpdesk_hero_theme;
		}
	);
	add_filter(
		'pre_option_stylesheet',
		static function () use ( $helpdesk_hero_theme ) {
			return $helpdesk_hero_theme;
		}
	);
}
unset( $helpdesk_hero_sessions, $helpdesk_hero_key, $helpdesk_hero_session, $helpdesk_hero_keep );
