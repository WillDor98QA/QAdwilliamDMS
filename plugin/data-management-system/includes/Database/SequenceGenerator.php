<?php
/**
 * Atomic, year-scoped reference numbers (Decisions §7, ARCH §56).
 *
 *   REG-2026-000001      registration numbers
 *   IMPORT-2026-00017    import batch references
 *
 * The increment is an UPDATE … SET value = LAST_INSERT_ID(value + 1): the row
 * lock serializes concurrent requests, so no two receive the same number. Called inside the
 * caller's transaction, a rolled-back registration also rolls back its
 * number, so numbers are not skipped.
 *
 * @package DMS
 */

namespace DMS\Database;

use DMS\Support\Clock;

defined( 'ABSPATH' ) || exit;

class SequenceGenerator {

	public const REGISTRATION = 'registration';
	public const IMPORT       = 'import';

	public function __construct( private \wpdb $db, private Clock $clock ) {
	}

	/** @throws \RuntimeException On database failure. */
	public function next( string $name, string $scope ): int {
		$table = Tables::name( Tables::SEQUENCES );
		// Two statements: LAST_INSERT_ID(expr) is only reliable in an UPDATE — in an
		// INSERT that creates a row, MySQL returns the AUTO_INCREMENT id instead.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		$created = $this->db->query( $this->db->prepare( "INSERT IGNORE INTO {$table} (name, scope, value) VALUES (%s, %s, 0)", $name, $scope ) );
		$updated = $this->db->query( $this->db->prepare( "UPDATE {$table} SET value = LAST_INSERT_ID(value + 1) WHERE name = %s AND scope = %s", $name, $scope ) );
		// phpcs:enable
		if ( false === $created || 1 !== $updated ) {
			throw new \RuntimeException( 'Sequence increment failed: ' . $this->db->last_error );
		}
		return (int) $this->db->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	public function next_registration_number(): string {
		$year = $this->clock->now()->format( 'Y' );
		return sprintf( 'REG-%s-%06d', $year, $this->next( self::REGISTRATION, $year ) );
	}

	public function next_import_reference(): string {
		$year = $this->clock->now()->format( 'Y' );
		return sprintf( 'IMPORT-%s-%05d', $year, $this->next( self::IMPORT, $year ) );
	}
}
