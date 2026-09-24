<?php
/**
 * Credentials for external services (SMS provider, Turnstile).
 *
 * Resolution order (implementation decision ID-20):
 *   1. A wp-config.php constant / environment variable — preferred, never in the database.
 *   2. A value entered on the Settings screen, stored encrypted (libsodium secretbox)
 *      with a key derived from the site's secret salts. It is write-only in the UI.
 *
 * Values are never logged or returned to the browser. If the salts change,
 * stored values can no longer be decrypted and are treated as missing — the
 * service then reports "not configured" instead of failing silently.
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

class SecretStore {

	public const OPTION = 'dms_secrets';

	public function get( string $name ): ?string {
		$constant = self::constant_name( $name );
		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return (string) constant( $constant );
		}
		$env = getenv( $constant );
		if ( is_string( $env ) && '' !== $env ) {
			return $env;
		}
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || empty( $stored[ $name ] ) ) {
			return null;
		}
		return $this->decrypt( (string) $stored[ $name ] );
	}

	public function has( string $name ): bool {
		return null !== $this->get( $name );
	}

	/** True when the value comes from wp-config/environment (the UI then cannot change it). */
	public function is_locked_by_config( string $name ): bool {
		$constant = self::constant_name( $name );
		return ( defined( $constant ) && '' !== (string) constant( $constant ) ) || ( is_string( getenv( $constant ) ) && '' !== getenv( $constant ) );
	}

	/** Stores (or with '' clears) a UI-entered secret, encrypted. */
	public function set( string $name, string $value ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( '' === $value ) {
			unset( $stored[ $name ] );
		} else {
			$stored[ $name ] = $this->encrypt( $value );
		}
		update_option( self::OPTION, $stored, false );
	}

	/** e.g. "sms_api_key" => "DMS_SMS_API_KEY". */
	public static function constant_name( string $name ): string {
		return 'DMS_' . strtoupper( preg_replace( '/[^a-z0-9]+/i', '_', $name ) );
	}

	private function key(): string {
		return sodium_crypto_generichash( wp_salt( 'secure_auth' ) . '|dms-secret-store', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private function encrypt( string $plaintext ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $this->key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext transport.
	}

	private function decrypt( string $encoded ): ?string {
		$raw = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $this->key() );
		return false === $plain ? null : $plain;
	}
}
