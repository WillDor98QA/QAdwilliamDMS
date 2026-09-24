<?php
/**
 * Stores the officer's last-assignment time to the microsecond (R-04), so two
 * assignments in the same second still order correctly for the "longest
 * waiting" tie-breaker (ARCH §72.3). Additive: existing values keep their
 * seconds, with .000000.
 *
 * @package DMS
 */

namespace DMS\Database\Migrations;

use DMS\Database\Migration;
use DMS\Database\MigrationException;
use DMS\Database\Tables;

defined( 'ABSPATH' ) || exit;

final class M006PreciseLastAssigned implements Migration {

	public function version(): int {
		return 6;
	}

	public function description(): string {
		return 'Microsecond precision for officer last-assignment time';
	}

	public function up( \wpdb $db ): void {
		$table = Tables::name( Tables::USER_PROFILES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- fixed table name; versioned migration.
		if ( false === $db->query( "ALTER TABLE {$table} MODIFY last_assigned_at DATETIME(6) NULL" ) ) {
			throw new MigrationException( 'Could not change last_assigned_at: ' . $db->last_error );
		}
	}
}
