<?php
/**
 * Runs pending migrations in order and records the installed schema version.
 *
 * - Detects the current version from the `dms_db_version` option.
 * - Runs only migrations newer than that version, one at a time, bumping the
 *   stored version after each success so an interrupted upgrade resumes safely.
 * - Uses an option-row lock so two concurrent requests cannot migrate at once.
 * - Never drops tables or data (Master Prompt §7).
 *
 * @package DMS
 */

namespace DMS\Database;

use DMS\Support\Logger;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	public const VERSION_OPTION = 'dms_db_version';
	public const LOCK_OPTION    = 'dms_migration_lock';
	private const LOCK_TTL      = 600;

	/** @var list<Migration> */
	private array $migrations;

	/**
	 * @param list<Migration>|null $migrations Defaults to the plugin's migration list.
	 */
	public function __construct( private \wpdb $db, private Logger $logger, ?array $migrations = null ) {
		$this->migrations = $migrations ?? self::default_migrations();
		usort( $this->migrations, static fn( Migration $a, Migration $b ): int => $a->version() <=> $b->version() );
	}

	/** @return list<Migration> */
	public static function default_migrations(): array {
		return array(
			new Migrations\M001CreateTables(),
			new Migrations\M002AddForeignKeys(),
			new Migrations\M003SeedRoles(),
			new Migrations\M004AddStaffWordPressRole(),
			new Migrations\M005CreateExportJobs(),
			new Migrations\M006PreciseLastAssigned(),
		);
	}

	public function current_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	public function target_version(): int {
		$last = end( $this->migrations );
		return $last ? $last->version() : 0;
	}

	public function needs_migration(): bool {
		return $this->current_version() < $this->target_version();
	}

	/**
	 * @return list<int> Versions applied during this call. Empty if already current or locked.
	 * @throws MigrationException When a migration fails; later migrations are not attempted.
	 */
	public function migrate(): array {
		if ( ! $this->needs_migration() ) {
			return array();
		}
		if ( ! $this->acquire_lock() ) {
			$this->logger->warning( 'Migration skipped: another process holds the lock.' );
			return array();
		}

		$applied = array();
		try {
			foreach ( $this->migrations as $migration ) {
				if ( $migration->version() <= $this->current_version() ) {
					continue;
				}
				$migration->up( $this->db );
				update_option( self::VERSION_OPTION, $migration->version(), true );
				$applied[] = $migration->version();
				$this->logger->info(
					'Migration applied',
					array(
						'version'     => $migration->version(),
						'description' => $migration->description(),
					)
				);
			}
		} catch ( \Throwable $e ) {
			$reference = $this->logger->error(
				'Migration failed',
				array(
					'current_version' => $this->current_version(),
					'error'           => $e->getMessage(),
				)
			);
			throw new MigrationException( 'Database migration failed. Reference: ' . $reference, 0, $e );
		} finally {
			$this->release_lock();
		}

		return $applied;
	}

	private function acquire_lock(): bool {
		// add_option() is atomic on the unique option_name index.
		if ( add_option( self::LOCK_OPTION, (string) time(), '', false ) ) {
			return true;
		}
		$held_since = (int) get_option( self::LOCK_OPTION, 0 );
		if ( $held_since > 0 && ( time() - $held_since ) > self::LOCK_TTL ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, (string) time(), '', false );
		}
		return false;
	}

	private function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
