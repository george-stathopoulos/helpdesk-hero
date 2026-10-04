<?php
/**
 * Encryption at rest and connection keys.
 *
 * @package Helpdesk_Hero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keys for the connection between a customer site and its hub.
 *
 * Signatures: each side has its own Ed25519 key pair and knows only the other side's public key,
 * so a copy of one site's database can't be used to pretend to be the other site. Connections
 * made before version 2.1 used one shared HMAC key; they upgrade to key pairs on their own.
 *
 * At rest: keys are encrypted (XSalsa20-Poly1305, libsodium or WordPress's sodium_compat) with a
 * key derived from the site's wp-config.php security keys, or from HELPDESK_HERO_ENCRYPTION_KEY
 * when defined. A database dump alone doesn't reveal them. Someone who can read wp-config.php
 * as well still can; that's the limit of encryption at rest.
 *
 * Credentials ("creds") are an array:
 * - hmac:      the shared key of older connections (until both sides use key pairs).
 * - sk, pk:    this side's secret and public key (base64).
 * - peer:      the other side's public key (base64).
 * - confirmed: a request signed with the new keys arrived from the other side, so it has them.
 * - initiated: this side started the key change and has the other side's answer.
 * - old:       the credentials before a key change, used until the change is confirmed.
 */
final class Helpdesk_Hero_Crypto {

	const PREFIX = 'hdhenc1:';
	const CHECK  = 'helpdesk_hero_crypto_check';

	/**
	 * The 32-byte encryption key.
	 *
	 * @return string Binary.
	 */
	private static function key() {
		if ( defined( 'HELPDESK_HERO_ENCRYPTION_KEY' ) && '' !== (string) HELPDESK_HERO_ENCRYPTION_KEY ) {
			$material = (string) HELPDESK_HERO_ENCRYPTION_KEY;
		} else {
			$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
		}
		return hash( 'sha256', 'helpdesk-hero|' . $material, true );
	}

	/**
	 * Short fingerprint of the encryption key, to notice when wp-config.php keys change.
	 *
	 * @return string
	 */
	public static function fingerprint() {
		return substr( hash( 'sha256', 'check|' . self::key() ), 0, 16 );
	}

	/**
	 * Whether the encryption key changed since secrets were last saved (the security keys in
	 * wp-config.php were replaced), so stored keys can't be read.
	 *
	 * @return bool
	 */
	public static function key_changed() {
		$stored = (string) get_option( self::CHECK, '' );
		return '' !== $stored && ! hash_equals( $stored, self::fingerprint() );
	}

	/**
	 * Encrypt a string for storage.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		update_option( self::CHECK, self::fingerprint(), false );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding of ciphertext.
	}

	/**
	 * Decrypt a stored string. Values stored before encryption existed pass through unchanged.
	 *
	 * @param string $stored Stored value.
	 * @return string|false Plain text, or false when it can't be decrypted.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding of ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return false;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( Exception $e ) {
			return false;
		}
		return false === $plain ? false : $plain;
	}

	/**
	 * Encrypt with a passphrase (backup files). PBKDF2-SHA256, 200,000 rounds.
	 *
	 * @param string $plain      Plain text.
	 * @param string $passphrase Passphrase.
	 * @return array { cipher, kdf, rounds, salt, nonce, data }
	 */
	public static function seal( $plain, $passphrase ) {
		$salt  = random_bytes( 16 );
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$key   = hash_pbkdf2( 'sha256', (string) $passphrase, $salt, 200000, 32, true );
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- file encoding of ciphertext.
		return array(
			'cipher' => 'xsalsa20poly1305',
			'kdf'    => 'pbkdf2-sha256',
			'rounds' => 200000,
			'salt'   => base64_encode( $salt ),
			'nonce'  => base64_encode( $nonce ),
			'data'   => base64_encode( sodium_crypto_secretbox( (string) $plain, $nonce, $key ) ),
		);
		// phpcs:enable
	}

	/**
	 * Open something sealed with seal().
	 *
	 * @param array  $sealed     Sealed.
	 * @param string $passphrase Passphrase.
	 * @return string|false
	 */
	public static function unseal( array $sealed, $passphrase ) {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- file encoding of ciphertext.
		$salt  = base64_decode( (string) ( $sealed['salt'] ?? '' ), true );
		$nonce = base64_decode( (string) ( $sealed['nonce'] ?? '' ), true );
		$data  = base64_decode( (string) ( $sealed['data'] ?? '' ), true );
		// phpcs:enable
		$rounds = max( 10000, min( 2000000, (int) ( $sealed['rounds'] ?? 200000 ) ) );
		if ( ! $salt || ! $nonce || ! $data || SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) {
			return false;
		}
		try {
			return sodium_crypto_secretbox_open( $data, $nonce, hash_pbkdf2( 'sha256', (string) $passphrase, $salt, $rounds, 32, true ) );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Key pairs and signatures.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * A new Ed25519 key pair.
	 *
	 * @return array { sk, pk } base64.
	 */
	public static function keypair() {
		$pair = sodium_crypto_sign_keypair();
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- key encoding.
		return array(
			'sk' => base64_encode( sodium_crypto_sign_secretkey( $pair ) ),
			'pk' => base64_encode( sodium_crypto_sign_publickey( $pair ) ),
		);
		// phpcs:enable
	}

	/**
	 * Whether a string is a base64 Ed25519 public key.
	 *
	 * @param string $pk Key.
	 * @return bool
	 */
	public static function valid_public_key( $pk ) {
		$raw = base64_decode( (string) $pk, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key encoding.
		return false !== $raw && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $raw );
	}

	/**
	 * Sign a message.
	 *
	 * @param string $message Message.
	 * @param string $sk      Secret key (base64).
	 * @return string Signature (base64).
	 */
	public static function sign( $message, $sk ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key and signature encoding.
		return base64_encode( sodium_crypto_sign_detached( (string) $message, base64_decode( (string) $sk ) ) );
	}

	/**
	 * Check a signature.
	 *
	 * @param string $signature Signature (base64).
	 * @param string $message   Message.
	 * @param string $pk        Public key (base64).
	 * @return bool
	 */
	public static function verify( $signature, $message, $pk ) {
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- key and signature encoding.
		$sig = base64_decode( (string) $signature, true );
		$key = base64_decode( (string) $pk, true );
		// phpcs:enable
		if ( false === $sig || false === $key || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached( $sig, (string) $message, $key );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/* ---------------------------------------------------------------------------------------- *
	 * Credentials.
	 * ---------------------------------------------------------------------------------------- */

	/**
	 * Credentials from storage (encrypted JSON, or an older plain HMAC key).
	 *
	 * @param string $stored Stored value.
	 * @return array Credentials ([] when there are none or they can't be read).
	 */
	public static function load( $stored ) {
		$plain = self::decrypt( (string) $stored );
		if ( false === $plain || '' === $plain ) {
			return array();
		}
		if ( preg_match( '/^[a-f0-9]{32,128}$/', $plain ) ) {
			return array( 'hmac' => $plain );
		}
		$creds = json_decode( $plain, true );
		return is_array( $creds ) ? $creds : array();
	}

	/**
	 * Credentials for storage.
	 *
	 * @param array $creds Credentials.
	 * @return string Encrypted.
	 */
	public static function store( array $creds ) {
		$creds = self::clean( $creds );
		return $creds ? self::encrypt( (string) wp_json_encode( $creds ) ) : '';
	}

	/**
	 * Credentials as plain JSON (backups).
	 *
	 * @param array $creds Credentials.
	 * @return string
	 */
	public static function export( array $creds ) {
		$creds = self::clean( $creds );
		return $creds ? (string) wp_json_encode( $creds ) : '';
	}

	/**
	 * Keep known fields only; never more than one level of "old".
	 *
	 * @param array $creds Credentials.
	 * @param bool  $inner Cleaning an "old" entry.
	 * @return array
	 */
	private static function clean( array $creds, $inner = false ) {
		$out = array();
		foreach ( array( 'hmac', 'sk', 'pk', 'peer' ) as $k ) {
			if ( ! empty( $creds[ $k ] ) && is_string( $creds[ $k ] ) ) {
				$out[ $k ] = $creds[ $k ];
			}
		}
		foreach ( array( 'confirmed', 'initiated' ) as $k ) {
			if ( ! empty( $creds[ $k ] ) ) {
				$out[ $k ] = true;
			}
		}
		if ( ! $inner && ! empty( $creds['old'] ) && is_array( $creds['old'] ) ) {
			$old = self::clean( $creds['old'], true );
			if ( $old ) {
				$out['old'] = $old;
			}
		}
		return $out;
	}

	/**
	 * Whether the credentials can sign with the key pair: the other side has our public key.
	 *
	 * @param array $creds Credentials.
	 * @return bool
	 */
	private static function pair_ready( array $creds ) {
		if ( empty( $creds['sk'] ) || empty( $creds['peer'] ) ) {
			return false;
		}
		// Without anything older to fall back on, the key pair is all there is.
		return ! empty( $creds['confirmed'] ) || ! empty( $creds['initiated'] ) || ( empty( $creds['hmac'] ) && empty( $creds['old'] ) );
	}

	/**
	 * How to sign the next request.
	 *
	 * @param array $creds Credentials.
	 * @return array|null [ 'ed25519', sk ] or [ 'hmac', key ], or null.
	 */
	public static function signer( array $creds ) {
		if ( self::pair_ready( $creds ) ) {
			return array( 'ed25519', $creds['sk'] );
		}
		if ( ! empty( $creds['hmac'] ) ) {
			return array( 'hmac', $creds['hmac'] );
		}
		if ( ! empty( $creds['old'] ) ) {
			return self::signer( $creds['old'] );
		}
		if ( ! empty( $creds['sk'] ) && ! empty( $creds['peer'] ) ) {
			return array( 'ed25519', $creds['sk'] );
		}
		return null;
	}

	/**
	 * Check a signature against the credentials.
	 *
	 * @param array  $creds     Credentials.
	 * @param string $alg       ed25519 | hmac.
	 * @param string $signature Signature.
	 * @param string $message   Signed text.
	 * @return array|false { creds: updated credentials, matched: current|old, changed: bool } or false.
	 */
	public static function check( array $creds, $alg, $signature, $message ) {
		if ( 'ed25519' === $alg ) {
			if ( ! empty( $creds['peer'] ) && self::verify( $signature, $message, $creds['peer'] ) ) {
				$changed = empty( $creds['confirmed'] ) || isset( $creds['hmac'] ) || isset( $creds['old'] );
				// The other side signs with the new keys, so it has ours: drop what came before.
				unset( $creds['hmac'], $creds['old'], $creds['initiated'] );
				$creds['confirmed'] = true;
				return array(
					'creds'   => $creds,
					'matched' => 'current',
					'changed' => $changed,
				);
			}
			if ( ! empty( $creds['old']['peer'] ) && self::verify( $signature, $message, $creds['old']['peer'] ) ) {
				return array(
					'creds'   => $creds,
					'matched' => 'old',
					'changed' => false,
				);
			}
			return false;
		}
		// HMAC: only while the key pair isn't confirmed (no going back once it is).
		$keys = array();
		if ( empty( $creds['confirmed'] ) && ! empty( $creds['hmac'] ) ) {
			$keys['current'] = $creds['hmac'];
		}
		if ( ! empty( $creds['old']['hmac'] ) ) {
			$keys['old'] = $creds['old']['hmac'];
		}
		foreach ( $keys as $matched => $key ) {
			if ( hash_equals( hash_hmac( 'sha256', $message, $key ), (string) $signature ) ) {
				return array(
					'creds'   => $creds,
					'matched' => $matched,
					'changed' => false,
				);
			}
		}
		return false;
	}

	/**
	 * Whether the credentials still use a shared HMAC key that should be upgraded.
	 *
	 * @param array $creds Credentials.
	 * @return bool
	 */
	public static function needs_upgrade( array $creds ) {
		return ! empty( $creds['hmac'] ) && empty( $creds['peer'] );
	}

	/**
	 * Short fingerprint of a public key, for people comparing keys on both sites.
	 *
	 * @param string $pk Public key (base64).
	 * @return string e.g. "3F9A-01C2-77DE".
	 */
	public static function key_id( $pk ) {
		return '' === (string) $pk ? '' : strtoupper( implode( '-', str_split( substr( hash( 'sha256', (string) $pk ), 0, 12 ), 4 ) ) );
	}
}
