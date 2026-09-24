<?php
/**
 * Spreadsheet formula-injection guard (OWASP "CSV Injection").
 *
 * A value typed by the public (e.g. a name) that starts with = + - @ or a
 * tab/carriage return could run as a formula when a CSV is opened in Excel.
 * Such values are prefixed with an apostrophe. Numbers are left alone.
 *
 * @package DMS
 */

namespace DMS\Export;

defined( 'ABSPATH' ) || exit;

final class CellSanitizer {

	public static function csv( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		$value = (string) $value;
		if ( '' !== $value && ! is_numeric( $value ) && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
