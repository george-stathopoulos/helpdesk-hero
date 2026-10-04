<?php
/**
 * Signed requests between a site and its support hub.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ed25519 (or, for connections from before 2.1, HMAC-SHA256) over method, route, time, a one-time
 * nonce and the body hash. Each connected site
 * has its own shared secret, created when it pairs with the hub. Requests older than five minutes
 * or with a nonce seen before are rejected.
 */
final class Helpdesk_Hero_Signer {

	const MAX_SKEW = 300;

	/**
	 * Signature base string.
	 *
	 * @param string $time   Unix time.
	 * @param string $nonce  Nonce.
	 * @param string $method HTTP method.
	 * @param string $route  REST route, e.g. /helpdesk-hero/v1/hub/tickets.
	 * @param string $body   Raw body.
	 * @return string
	 */
	private static function base( $time, $nonce, $method, $route, $body ) {
		return implode( "\n", array( 'v1', $time, $nonce, strtoupper( $method ), '/' . ltrim( $route, '/' ), hash( 'sha256', (string) $body ) ) );
	}

	/**
	 * Send a signed request.
	 *
	 * @param string     $rest_root The other side's REST root URL (from rest_url()).
	 * @param string     $route     Route without the root, e.g. /helpdesk-hero/v1/hub/tickets.
	 * @param string     $method    GET or POST.
	 * @param array|null $data      JSON body (POST) or query args (GET).
	 * @param string     $id        Sender ID (site ID on the hub).
	 * @param array|string $creds   Credentials (see the Crypto class), or an older shared key.
	 * @param int        $timeout   Seconds.
	 * @return array|WP_Error Decoded JSON.
	 */
	public static function request( $rest_root, $route, $method, $data, $id, $creds, $timeout = 20 ) {
		if ( ! self::secure_url( $rest_root ) ) {
			return self::insecure_error( $rest_root );
		}
		$signer = Helpdesk_Hero_Crypto::signer( is_array( $creds ) ? $creds : array( 'hmac' => (string) $creds ) );
		if ( ! $signer ) {
			return new WP_Error( 'helpdesk_hero_keys', __( 'The connection keys are missing. Disconnect and connect again.', 'helpdesk-hero' ) );
		}
		$method = strtoupper( $method );
		$body   = 'POST' === $method ? (string) wp_json_encode( $data ? $data : new stdClass() ) : '';
		$url    = self::url( $rest_root, $route );
		if ( 'GET' === $method && $data ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $data ) ), $url );
		}
		$time  = (string) time();
		$nonce = bin2hex( random_bytes( 12 ) );
		$base  = self::base( $time, $nonce, $method, $route, $body );
		$sig   = 'ed25519' === $signer[0] ? Helpdesk_Hero_Crypto::sign( $base, $signer[1] ) : hash_hmac( 'sha256', $base, $signer[1] );

		$response = wp_remote_request(
			$url,
			array(
				'method'  => $method,
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'Accept'            => 'application/json',
					'X-HDH-Id'          => (string) $id,
					'X-HDH-Time'        => $time,
					'X-HDH-Nonce'       => $nonce,
					'X-HDH-Signature'   => $sig,
					'X-HDH-Alg'         => $signer[0],
				),
				'body'    => 'POST' === $method ? $body : null,
			)
		);
		return self::decode( $response );
	}

	/**
	 * Full URL for a route under a REST root (works with and without pretty permalinks).
	 *
	 * @param string $rest_root Root, e.g. https://example.com/wp-json/ or https://example.com/?rest_route=/.
	 * @param string $route     Route.
	 * @return string
	 */
	public static function url( $rest_root, $route ) {
		return rtrim( $rest_root, '/' ) . '/' . ltrim( $route, '/' );
	}

	/**
	 * Whether a URL is a usable http(s) address. Unlike wp_http_validate_url(), private and local
	 * addresses are allowed, because hubs and staging sites often live on internal networks.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function valid_url( $url ) {
		$scheme = wp_parse_url( (string) $url, PHP_URL_SCHEME );
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( $scheme, array( 'http', 'https' ), true ) && '' !== (string) wp_parse_url( (string) $url, PHP_URL_HOST );
	}

	/**
	 * Whether a URL is safe for connection keys, tickets and login links: HTTPS, or a local
	 * development address (localhost, 127.0.0.1, ::1, *.localhost, *.test, *.local). Sites whose
	 * environment type is "local" or "development" (WP_ENVIRONMENT_TYPE) may use plain HTTP too.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function secure_url( $url ) {
		$scheme = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) );
		$host   = strtolower( trim( (string) wp_parse_url( (string) $url, PHP_URL_HOST ), '[]' ) );
		$secure = 'https' === $scheme
			|| in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true )
			|| (bool) preg_match( '/\.(localhost|test|local)$/', $host )
			|| in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
		/**
		 * Filters whether a URL counts as secure enough to connect to.
		 *
		 * @param bool   $secure Secure.
		 * @param string $url    URL.
		 */
		return (bool) apply_filters( 'helpdesk_hero_secure_url', $secure, $url );
	}

	/**
	 * Error for an address that isn't on HTTPS.
	 *
	 * @param string $url URL.
	 * @return WP_Error
	 */
	public static function insecure_error( $url ) {
		return new WP_Error(
			'helpdesk_hero_insecure',
			sprintf(
				/* translators: %s: site address */
				__( 'Helpdesk Hero only connects over HTTPS, so connection keys and login links can’t be read on the way. %s uses plain HTTP. Turn on HTTPS for that site (most hosts offer free certificates) and try again.', 'helpdesk-hero' ),
				(string) wp_parse_url( (string) $url, PHP_URL_HOST )
			),
			array( 'status' => 400 )
		);
	}

	/**
	 * Decode a JSON response. Non-2xx becomes a WP_Error with the remote message, or, when the
	 * answer isn't WordPress JSON (a firewall, proxy or PHP error page), with the start of what
	 * was received, so the cause can be found.
	 *
	 * @param array|WP_Error $response Response.
	 * @return array|WP_Error
	 */
	public static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = self::parse_json( $body );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}
		if ( is_array( $data ) && ! empty( $data['message'] ) ) {
			return new WP_Error( ! empty( $data['code'] ) ? (string) $data['code'] : 'helpdesk_hero_http', (string) $data['message'], array( 'status' => $code ) );
		}
		$server  = (string) wp_remote_retrieve_header( $response, 'server' );
		$excerpt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', ' ', $body ) ) ) );
		$excerpt = strlen( $excerpt ) > 160 ? substr( $excerpt, 0, 157 ) . '…' : $excerpt;
		$message = $code >= 200 && $code < 300
			/* translators: %d: HTTP status */
			? sprintf( __( 'The other site answered with HTTP %d, but not with the data Helpdesk Hero expects.', 'helpdesk-hero' ), $code )
			/* translators: %d: HTTP status */
			: sprintf( __( 'The other site answered with HTTP %d.', 'helpdesk-hero' ), $code );
		if ( '' !== $excerpt ) {
			/* translators: %s: start of the response */
			$message .= ' ' . sprintf( __( 'It said: “%s”', 'helpdesk-hero' ), $excerpt );
		}
		if ( '' !== $server ) {
			/* translators: %s: server software, e.g. cloudflare */
			$message .= ' ' . sprintf( __( '(server: %s)', 'helpdesk-hero' ), sanitize_text_field( $server ) );
		}
		if ( in_array( $code, array( 400, 403, 406, 413, 415, 429, 503 ), true ) ) {
			$message .= ' ' . __( 'This usually means a firewall or security plugin on that server blocked the request. Ask its host to allow requests to /wp-json/helpdesk-hero-hub/ and /wp-json/helpdesk-hero/.', 'helpdesk-hero' );
		}
		return new WP_Error( 'helpdesk_hero_http', $message, array( 'status' => $code ) );
	}

	/**
	 * JSON from a response body, tolerating PHP notices printed before it.
	 *
	 * @param string $body Body.
	 * @return array|null
	 */
	private static function parse_json( $body ) {
		$data = json_decode( $body, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$start = strpos( $body, '{"' );
		if ( false !== $start ) {
			$data = json_decode( substr( $body, $start ), true );
		}
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Verify a signed REST request.
	 *
	 * @param WP_REST_Request $request      Request.
	 * @param callable        $creds_for_id Returns the credentials for a sender ID (array, or a
	 *                                      shared HMAC key as a string), empty if unknown.
	 * @param callable|null   $save         Called with ( $id, $creds ) when the credentials change
	 *                                      (a key change was confirmed).
	 * @return string|WP_Error Sender ID. The request gets a `_hh_key` param: current | old.
	 */
	public static function verify( WP_REST_Request $request, callable $creds_for_id, $save = null ) {
		$id        = (string) $request->get_header( 'X-HDH-Id' );
		$time      = (string) $request->get_header( 'X-HDH-Time' );
		$nonce     = (string) $request->get_header( 'X-HDH-Nonce' );
		$signature = (string) $request->get_header( 'X-HDH-Signature' );
		$alg       = 'ed25519' === $request->get_header( 'X-HDH-Alg' ) ? 'ed25519' : 'hmac';
		$denied    = new WP_Error( 'helpdesk_hero_signature', __( 'Request signature is not valid.', 'helpdesk-hero' ), array( 'status' => 401 ) );

		$format = 'ed25519' === $alg ? '/^[A-Za-z0-9+\/]{86}==$/' : '/^[a-f0-9]{64}$/';
		if ( '' === $id || ! ctype_digit( $time ) || ! preg_match( '/^[a-f0-9]{16,64}$/', $nonce ) || ! preg_match( $format, $signature ) ) {
			return $denied;
		}
		if ( abs( time() - (int) $time ) > self::MAX_SKEW ) {
			return new WP_Error( 'helpdesk_hero_clock', __( 'Request is too old. Check that both servers have the correct time.', 'helpdesk-hero' ), array( 'status' => 401 ) );
		}
		$creds = call_user_func( $creds_for_id, $id );
		$creds = is_array( $creds ) ? $creds : ( '' !== (string) $creds ? array( 'hmac' => (string) $creds ) : array() );
		if ( ! $creds ) {
			return $denied;
		}
		$result = Helpdesk_Hero_Crypto::check( $creds, $alg, $signature, self::base( $time, $nonce, $request->get_method(), $request->get_route(), $request->get_body() ) );
		if ( ! $result ) {
			return $denied;
		}
		$seen = 'helpdesk_hero_nonce_' . md5( $id . $nonce );
		if ( get_transient( $seen ) ) {
			return new WP_Error( 'helpdesk_hero_replay', __( 'Request was already processed.', 'helpdesk-hero' ), array( 'status' => 409 ) );
		}
		set_transient( $seen, 1, 2 * self::MAX_SKEW );
		if ( $result['changed'] && $save ) {
			call_user_func( $save, $id, $result['creds'] );
		}
		$request->set_param( '_hh_key', $result['matched'] );
		return $id;
	}
}
