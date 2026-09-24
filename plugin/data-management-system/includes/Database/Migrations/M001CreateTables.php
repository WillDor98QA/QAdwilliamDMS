<?php
/**
 * Creates every plugin table from Schema (dbDelta: additive only).
 *
 * @package DMS
 */

namespace DMS\Database\Migrations;

use DMS\Database\Migration;
use DMS\Database\MigrationException;
use DMS\Database\Schema;
use DMS\Database\Tables;

defined( 'ABSPATH' ) || exit;

final class M001CreateTables implements Migration {

	public function version(): int {
		return 1;
	}

	public function description(): string {
		return 'Create core tables';
	}

	public function up( \wpdb $db ): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( array_values( Schema::statements() ) );

		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $table );
			if ( $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $name ) ) ) !== $name ) {
				throw new MigrationException( "Table {$name} was not created: " . $db->last_error );
			}
		}
	}
}
