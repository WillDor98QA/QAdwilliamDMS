<?php
/**
 * Hidden form field that people never fill in but naive bots do (Decisions §14).
 *
 * @package DMS
 */

namespace DMS\Security;

defined( 'ABSPATH' ) || exit;

final class Honeypot {

	public const FIELD = 'dms_website';

	/** @param array<string,mixed> $input */
	public static function tripped( array $input ): bool {
		return isset( $input[ self::FIELD ] ) && '' !== trim( (string) $input[ self::FIELD ] );
	}
}
