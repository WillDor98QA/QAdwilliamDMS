<?php
/**
 * The official electoral workbook contract (ARCH §49, §50; Decisions §27).
 *
 * @package DMS
 */

namespace DMS\Imports;

use DMS\Electoral\ElectoralLevel;

defined( 'ABSPATH' ) || exit;

final class WorkbookSpec {

	public const MAX_CODE_LENGTH = 50;
	public const MAX_NAME_LENGTH = 191;
	public const MAX_ROWS        = 150000;

	/**
	 * Sheets in dependency order (ARCH §52).
	 *
	 * @return array<string,array{sheet:string,code:string,name:string,parent:?string}> keyed by ElectoralLevel value
	 */
	public static function sheets(): array {
		return array(
			ElectoralLevel::REGION->value          => array(
				'sheet'  => 'Regions',
				'code'   => 'region_code',
				'name'   => 'region_name',
				'parent' => null,
			),
			ElectoralLevel::CONSTITUENCY->value    => array(
				'sheet'  => 'Constituencies',
				'code'   => 'constituency_code',
				'name'   => 'constituency_name',
				'parent' => 'region_code',
			),
			ElectoralLevel::POLLING_STATION->value => array(
				'sheet'  => 'Polling Stations',
				'code'   => 'polling_station_code',
				'name'   => 'polling_station_name',
				'parent' => 'constituency_code',
			),
		);
	}

	/** @return list<string> Column headers for a level, in template order. */
	public static function columns( ElectoralLevel $level ): array {
		$s = self::sheets()[ $level->value ];
		return array_values( array_filter( array( $s['code'], $s['name'], $s['parent'] ) ) );
	}

	/**
	 * Codes are the stable identity (ARCH §50). They are trimmed and upper-cased,
	 * because the database compares codes case-insensitively (implementation decision ID-42).
	 */
	public static function normalise_code( string $value ): string {
		return strtoupper( trim( $value ) );
	}

	public static function is_valid_code( string $code ): bool {
		return (bool) preg_match( '/^[A-Z0-9][A-Z0-9._\/-]{0,' . ( self::MAX_CODE_LENGTH - 1 ) . '}$/', $code );
	}

	/** Collapses whitespace and removes control characters from a name. */
	public static function normalise_name( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/u', ' ', $value );
		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}
}
