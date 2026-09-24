<?php
/**
 * Adds the export-jobs table (Phase 6). Additive: dbDelta creates it if missing.
 *
 * @package DMS
 */

namespace DMS\Database\Migrations;

use DMS\Database\Migration;
use DMS\Database\MigrationException;
use DMS\Database\Schema;
use DMS\Database\Tables;

defined( 'ABSPATH' ) || exit;

final class M005CreateExportJobs implements Migration {

	public function version(): int {
		return 5;
	}

	public function description(): string {
		return 'Create export jobs table';
	}

	public function up( \wpdb $db ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Schema::statements()[ Tables::EXPORT_JOBS ] );
		$name = Tables::name( Tables::EXPORT_JOBS );
		if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $name ) ) ) !== $name ) {
			throw new MigrationException( "Table {$name} was not created: " . $db->last_error );
		}
	}
}
