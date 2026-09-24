<?php
/**
 * Removes secrets from arrays before they are written to logs or audit metadata.
 *
 * OTP values, passwords and tokens must never be persisted in plaintext
 * (Master Prompt §10, §43; Decisions §11).
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

final class Redactor {

	public const MASK = '[REDACTED]';

	/** Keys masked only on exact match — short names that would over-match as fragments (e.g. "region_code"). */
	private const EXACT_KEYS = array( 'otp', 'code', 'otp_code', 'pin', 'pass', 'nonce' );

	/** Keys masked when they contain any of these fragments. */
	private const KEY_FRAGMENTS = array( 'password', 'secret', 'token', 'api_key', 'apikey', 'authorization', 'cookie', 'otp_hash', 'otp_value' );

	/**
	 * @param array<mixed> $data
	 * @return array<mixed>
	 */
	public static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_secret_key( $key ) ) {
				$data[ $key ] = self::MASK;
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			}
		}
		return $data;
	}

	public static function is_secret_key( string $key ): bool {
		$key = strtolower( $key );
		if ( in_array( $key, self::EXACT_KEYS, true ) ) {
			return true;
		}
		foreach ( self::KEY_FRAGMENTS as $fragment ) {
			if ( str_contains( $key, $fragment ) ) {
				return true;
			}
		}
		// Suffixes such as WordPress's "user_pass", without masking "passport_number".
		return str_ends_with( $key, '_pass' ) || str_ends_with( $key, '_pin' );
	}
}
