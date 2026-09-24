<?php
/**
 * Table name registry. Always uses the site's $wpdb->prefix (Decisions §36).
 *
 * @package DMS
 */

namespace DMS\Database;

defined( 'ABSPATH' ) || exit;

final class Tables {

	public const REGIONS               = 'regions';
	public const CONSTITUENCIES        = 'constituencies';
	public const POLLING_STATIONS      = 'polling_stations';
	public const REGISTRATIONS         = 'registrations';
	public const SEQUENCES             = 'sequences';
	public const AUDIT_LOG             = 'audit_log';
	public const ROLES                 = 'roles';
	public const ROLE_PERMISSIONS      = 'role_permissions';
	public const USER_ROLES            = 'user_roles';
	public const USER_PROFILES         = 'user_profiles';
	public const OFFICER_REGIONS       = 'officer_regions';
	public const ASSIGNMENT_EXCEPTIONS = 'assignment_exceptions';
	public const OTP_REQUESTS          = 'otp_requests';
	public const RATE_LIMITS           = 'rate_limits';
	public const NOTIFICATIONS         = 'notifications';
	public const IMPORT_BATCHES        = 'import_batches';
	public const IMPORT_ITEMS          = 'import_items';
	public const EXPORT_JOBS           = 'export_jobs';

	public static function name( string $table ): string {
		global $wpdb;
		return $wpdb->prefix . 'dms_' . $table;
	}

	/** @return list<string> Short names of every plugin table. */
	public static function all(): array {
		return array(
			self::REGIONS,
			self::CONSTITUENCIES,
			self::POLLING_STATIONS,
			self::REGISTRATIONS,
			self::SEQUENCES,
			self::AUDIT_LOG,
			self::ROLES,
			self::ROLE_PERMISSIONS,
			self::USER_ROLES,
			self::USER_PROFILES,
			self::OFFICER_REGIONS,
			self::ASSIGNMENT_EXCEPTIONS,
			self::OTP_REQUESTS,
			self::RATE_LIMITS,
			self::NOTIFICATIONS,
			self::IMPORT_BATCHES,
			self::IMPORT_ITEMS,
			self::EXPORT_JOBS,
		);
	}
}
