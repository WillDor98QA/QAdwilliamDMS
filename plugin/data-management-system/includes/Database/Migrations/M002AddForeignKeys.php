<?php
/**
 * Adds foreign-key constraints (dbDelta cannot). Skips constraints that
 * already exist, so re-running is a no-op.
 *
 * @package DMS
 */

namespace DMS\Database\Migrations;

use DMS\Database\Migration;
use DMS\Database\MigrationException;
use DMS\Database\Schema;
use DMS\Database\Tables;

defined( 'ABSPATH' ) || exit;

final class M002AddForeignKeys implements Migration {

	public function version(): int {
		return 2;
	}

	public function description(): string {
		return 'Add foreign-key constraints';
	}

	public function up( \wpdb $db ): void {
		foreach ( Schema::foreign_keys() as $fk ) {
			if ( self::exists( $db, Tables::name( $fk['table'] ), $fk['name'] ) ) {
				continue;
			}
			$sql = sprintf(
				'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE RESTRICT',
				Tables::name( $fk['table'] ),
				$fk['name'],
				$fk['column'],
				Tables::name( $fk['ref_table'] ),
				$fk['ref_column'],
				$fk['on_delete']
			);
			// Identifiers come from Schema constants, not user input.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $db->query( $sql ) ) {
				throw new MigrationException( "Could not add {$fk['name']}: " . $db->last_error );
			}
		}
	}

	public static function exists( \wpdb $db, string $table, string $constraint ): bool {
		return (bool) $db->get_var(
			$db->prepare(
				"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
				WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
				$table,
				$constraint
			)
		);
	}
}
