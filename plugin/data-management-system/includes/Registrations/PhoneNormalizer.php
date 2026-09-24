<?php
/**
 * Canonical Ghanaian mobile numbers (MP §11, Decisions §8).
 *
 * All of these normalize to +233241234567:
 *   0241234567 · 024 123 4567 · 024-123-4567 · +233241234567
 *   233241234567 · 00233241234567 · +233 (0) 24 123 4567
 *
 * Only mobile numbers are accepted, because the number must receive an SMS
 * OTP (implementation decision ID-12): the 9-digit national number must
 * start with 2 or 5 (Ghana mobile ranges 2x / 5x).
 *
 * @package DMS
 */

namespace DMS\Registrations;

defined( 'ABSPATH' ) || exit;

final class PhoneNormalizer {

	public const COUNTRY_CODE = '233';

	/** @return string|null E.164 form (+233XXXXXXXXX), or null if not a valid Ghana mobile number. */
	public static function normalize( string $input ): ?string {
		$input = trim( $input );
		if ( '' === $input || preg_match( '/[^0-9+\s\-().]/', $input ) ) {
			return null;
		}

		$has_plus = str_starts_with( $input, '+' );
		if ( substr_count( $input, '+' ) > ( $has_plus ? 1 : 0 ) ) {
			return null;
		}

		// "+233 (0) 24…" — drop the conventional trunk-prefix marker before stripping.
		$input  = preg_replace( '/\(\s*0\s*\)/', '', $input );
		$digits = preg_replace( '/\D/', '', (string) $input );

		if ( $has_plus || str_starts_with( $digits, '00' . self::COUNTRY_CODE ) || str_starts_with( $digits, self::COUNTRY_CODE ) ) {
			$digits = preg_replace( '/^(00)?' . self::COUNTRY_CODE . '/', '', $digits, 1, $count );
			if ( 0 === $count ) {
				return null; // A "+" followed by another country code.
			}
		} elseif ( str_starts_with( $digits, '0' ) ) {
			$digits = substr( $digits, 1 );
		} else {
			return null;
		}

		if ( ! preg_match( '/^[25]\d{8}$/', (string) $digits ) ) {
			return null;
		}
		return '+' . self::COUNTRY_CODE . $digits;
	}

	public static function is_valid( string $input ): bool {
		return null !== self::normalize( $input );
	}

	/** For display in logs/UI without exposing the full number, e.g. "+233 •••• 4567". */
	public static function mask( string $normalized ): string {
		return '+' . self::COUNTRY_CODE . ' •••• ' . substr( $normalized, -4 );
	}
}
