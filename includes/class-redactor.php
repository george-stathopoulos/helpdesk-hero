<?php
/**
 * Removes personal data and secrets from text before it leaves the site.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pattern-based redaction for logs, diagnostics and activity details.
 */
final class Helpdesk_Hero_Redactor {

	/**
	 * Redact a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return $text;
		}
		$patterns = array(
			// key=value secrets in URLs, query strings, config dumps and logs.
			'/((?:pass(?:word|wd)?|pwd|secret|token|api[_-]?key|apikey|auth|authorization|license[_-]?key|nonce|session)["\']?\s*[=:]\s*["\']?)([^\s"\'&,;]{3,})/i' => '$1[redacted]',
			// Bearer / Basic credentials.
			'/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]{8,}=*/' => '$1 [redacted]',
			// Well-known key formats (OpenAI, Anthropic, Stripe, GitHub, Slack, AWS, Google).
			'/\b(?:sk|pk|rk)[-_](?:live|test|ant|proj)?[-_]?[A-Za-z0-9_\-]{16,}\b/' => '[redacted-key]',
			'/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|xox[abpr]-[A-Za-z0-9\-]{10,}|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z\-_]{30,})\b/' => '[redacted-key]',
			// Long random-looking strings (hashes, tokens).
			'/\b[A-Fa-f0-9]{40,}\b/' => '[redacted-hash]',
			// Card-like numbers.
			'/\b(?:\d[ -]?){13,19}\b/' => '[redacted-number]',
		);
		$text = (string) preg_replace( array_keys( $patterns ), array_values( $patterns ), $text );

		// Emails: keep the domain, hide the mailbox, so support still sees "which provider".
		$text = (string) preg_replace_callback(
			'/([A-Za-z0-9._%+\-]+)@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
			static function ( $m ) {
				return substr( $m[1], 0, 1 ) . '***@' . $m[2];
			},
			$text
		);

		/**
		 * Filters redacted text before it is sent to support.
		 *
		 * @param string $text Redacted text.
		 */
		return (string) apply_filters( 'helpdesk_hero_redact', $text );
	}

	/**
	 * Redact every string inside an array (recursively).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function deep( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::deep( $v );
			}
			return $value;
		}
		return is_string( $value ) ? self::text( $value ) : $value;
	}

	/**
	 * Whether an option name looks like it holds a secret, so its value is never logged.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_secret_key( $name ) {
		return (bool) preg_match( '/(pass|secret|token|api_?key|apikey|license|auth|salt|private|credential|nonce)/i', (string) $name );
	}
}
