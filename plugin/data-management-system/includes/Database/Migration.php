<?php
/**
 * A single, ordered, idempotent schema/data migration (Master Prompt §7).
 *
 * up() must be safe to run again if a previous run was interrupted.
 *
 * @package DMS
 */

namespace DMS\Database;

defined( 'ABSPATH' ) || exit;

interface Migration {

	/** Monotonic version number this migration brings the database to. */
	public function version(): int;

	public function description(): string;

	/** @throws MigrationException On any failure. */
	public function up( \wpdb $db ): void;
}
