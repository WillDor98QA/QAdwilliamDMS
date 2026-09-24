<?php
/**
 * Import batch statuses (ARCH §56) and item actions (ARCH §57).
 *
 * @package DMS
 */

namespace DMS\Imports;

defined( 'ABSPATH' ) || exit;

final class ImportStatus {

	public const UPLOADED         = 'UPLOADED';
	public const VALIDATING       = 'VALIDATING';
	public const VALIDATED        = 'VALIDATED';
	public const FAILED           = 'FAILED';
	public const COMPLETED        = 'COMPLETED';
	public const ROLLED_BACK      = 'ROLLED_BACK';
	public const ROLLBACK_BLOCKED = 'ROLLBACK_BLOCKED';

	public const ALL = array( self::UPLOADED, self::VALIDATING, self::VALIDATED, self::FAILED, self::COMPLETED, self::ROLLED_BACK, self::ROLLBACK_BLOCKED );

	public const ACTION_CREATE    = 'CREATE';
	public const ACTION_UPDATE    = 'UPDATE';
	public const ACTION_UNCHANGED = 'UNCHANGED';
	public const ACTION_INVALID   = 'INVALID';
	public const ACTION_DUPLICATE = 'DUPLICATE';

	public const ACTIONS = array( self::ACTION_CREATE, self::ACTION_UPDATE, self::ACTION_UNCHANGED, self::ACTION_INVALID, self::ACTION_DUPLICATE );

	public static function label( string $status ): string {
		return match ( $status ) {
			self::UPLOADED         => __( 'Uploaded', 'dms' ),
			self::VALIDATING       => __( 'Validating', 'dms' ),
			self::VALIDATED        => __( 'Ready to import', 'dms' ),
			self::FAILED           => __( 'Has errors', 'dms' ),
			self::COMPLETED        => __( 'Completed', 'dms' ),
			self::ROLLED_BACK      => __( 'Rolled back', 'dms' ),
			self::ROLLBACK_BLOCKED => __( 'Rollback blocked', 'dms' ),
			default                => $status,
		};
	}

	/** Scope of the V1 official workbook (Regions + Constituencies + Polling Stations). */
	public const SCOPE_ELECTORAL_WORKBOOK = 'ELECTORAL_WORKBOOK';
}
