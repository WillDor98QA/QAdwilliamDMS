<?php
/**
 * Runs a unit of work atomically.
 *
 * Nested calls use SAVEPOINTs, so services can compose (e.g. registration
 * creation calling the audit service) without an inner COMMIT ending the
 * outer transaction. When something outside this class already owns the
 * transaction — the WordPress test suite wraps every test in one — set
 * $externally_managed so even the outermost call uses a savepoint.
 *
 * @package DMS
 */

namespace DMS\Database;

defined( 'ABSPATH' ) || exit;

final class Transaction {

	public static bool $externally_managed = false;

	private static int $depth = 0;

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 * @throws \Throwable Re-throws whatever $work throws, after rolling back.
	 */
	public static function run( \wpdb $db, callable $work ): mixed {
		$use_savepoint = self::$depth > 0 || self::$externally_managed;
		$savepoint     = 'dms_sp_' . self::$depth;

		self::exec( $db, $use_savepoint ? "SAVEPOINT {$savepoint}" : 'START TRANSACTION' );
		++self::$depth;

		try {
			$result = $work();
		} catch ( \Throwable $e ) {
			--self::$depth;
			self::exec( $db, $use_savepoint ? "ROLLBACK TO SAVEPOINT {$savepoint}" : 'ROLLBACK' );
			throw $e;
		}

		--self::$depth;
		self::exec( $db, $use_savepoint ? "RELEASE SAVEPOINT {$savepoint}" : 'COMMIT' );
		return $result;
	}

	private static function exec( \wpdb $db, string $sql ): void {
		// Statements are fixed strings built above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( false === $db->query( $sql ) ) {
			throw new \RuntimeException( "Transaction statement failed ({$sql}): " . $db->last_error );
		}
	}
}
