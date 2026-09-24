<?php
/**
 * REQ-DB-001..005 — migrations create the schema, add constraints, are idempotent (MP §6, §7).
 */

namespace DMS\Tests\Integration;

use DMS\Database\Migrations\M001CreateTables;
use DMS\Database\Migrations\M002AddForeignKeys;
use DMS\Database\Migrator;
use DMS\Database\Schema;
use DMS\Database\Tables;
use DMS\Plugin;

final class MigrationTest extends \WP_UnitTestCase {

	public function test_database_is_at_target_version(): void {
		$migrator = Plugin::instance()->migrator();
		$this->assertSame( $migrator->target_version(), $migrator->current_version() );
		$this->assertFalse( $migrator->needs_migration() );
	}

	public function test_every_table_exists_with_the_site_prefix(): void {
		global $wpdb;
		foreach ( Tables::all() as $table ) {
			$name = Tables::name( $table );
			$this->assertStringStartsWith( $wpdb->prefix . 'dms_', $name );
			$this->assertSame( $name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ), $name );
		}
	}

	public function test_every_foreign_key_exists(): void {
		global $wpdb;
		foreach ( Schema::foreign_keys() as $fk ) {
			$this->assertTrue( M002AddForeignKeys::exists( $wpdb, Tables::name( $fk['table'] ), $fk['name'] ), $fk['name'] );
		}
	}

	public function test_codes_and_phone_are_unique_at_database_level(): void {
		global $wpdb;
		$expected = array(
			Tables::REGIONS          => 'code',
			Tables::CONSTITUENCIES   => 'code',
			Tables::POLLING_STATIONS => 'code',
			Tables::REGISTRATIONS    => 'phone_normalized',
		);
		foreach ( $expected as $table => $column ) {
			$index = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM ' . Tables::name( $table ) . ' WHERE Column_name = %s AND Non_unique = 0', $column ) );
			$this->assertNotNull( $index, "{$table}.{$column} must be UNIQUE" );
		}
	}

	public function test_rerunning_migrations_is_a_no_op_and_keeps_data(): void {
		global $wpdb;
		$regions = Tables::name( Tables::REGIONS );
		$wpdb->insert( $regions, array( 'code' => 'GAR', 'name' => 'Greater Accra', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );

		$this->assertSame( array(), Plugin::instance()->migrator()->migrate(), 'Nothing pending' );

		// Re-apply table and FK migrations directly, as an interrupted upgrade would.
		( new M001CreateTables() )->up( $wpdb );
		( new M002AddForeignKeys() )->up( $wpdb );

		$this->assertSame( 'Greater Accra', $wpdb->get_var( "SELECT name FROM {$regions} WHERE code = 'GAR'" ) );
	}

	public function test_lock_prevents_concurrent_migration(): void {
		global $wpdb;
		add_option( Migrator::LOCK_OPTION, (string) time() );
		update_option( Migrator::VERSION_OPTION, 2 );

		$migrator = new Migrator( $wpdb, Plugin::instance()->logger() );
		$this->assertSame( array(), $migrator->migrate() );
		$this->assertSame( 2, $migrator->current_version() );
	}

	public function test_region_with_constituencies_cannot_be_deleted(): void {
		global $wpdb;
		$now = '2026-01-01 00:00:00';
		$wpdb->insert( Tables::name( Tables::REGIONS ), array( 'code' => 'ASH', 'name' => 'Ashanti', 'created_at' => $now, 'updated_at' => $now ) );
		$region_id = (int) $wpdb->insert_id;
		$wpdb->insert( Tables::name( Tables::CONSTITUENCIES ), array( 'region_id' => $region_id, 'code' => 'ASH-001', 'name' => 'Asokwa', 'created_at' => $now, 'updated_at' => $now ) );

		$suppress = $wpdb->suppress_errors( true );
		$result   = $wpdb->delete( Tables::name( Tables::REGIONS ), array( 'id' => $region_id ) );
		$wpdb->suppress_errors( $suppress );

		$this->assertFalse( $result, 'FK RESTRICT must block orphaning' );
		$this->assertStringContainsString( 'foreign key constraint', strtolower( $wpdb->last_error ) );
	}

	public function test_seeded_roles_exist_and_administrator_is_system(): void {
		$roles = Plugin::instance()->roles();
		$admin = $roles->find_by_slug( 'administrator' );
		$this->assertNotNull( $admin );
		$this->assertSame( '1', (string) $admin->is_system );
		foreach ( array( 'verification-officer', 'senior-verification-officer', 'supervisor' ) as $slug ) {
			$role = $roles->find_by_slug( $slug );
			$this->assertNotNull( $role, $slug );
			$this->assertSame( '0', (string) $role->is_system );
			$this->assertContains( 'assignment.receive', $roles->permissions( (int) $role->id ) );
		}
	}
}
