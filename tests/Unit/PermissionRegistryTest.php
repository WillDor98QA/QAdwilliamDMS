<?php
/**
 * REQ-PERM-001 central registry (ARCH §17), REQ-PERM-002 reserved permissions.
 */

namespace DMS\Tests\Unit;

use DMS\Permissions\Permission;
use DMS\Permissions\PermissionRegistry;
use PHPUnit\Framework\TestCase;

final class PermissionRegistryTest extends TestCase {

	public function test_contains_every_arch_section_17_permission(): void {
		$arch_17 = array(
			'dashboard.view', 'dashboard.export',
			'registrations.view', 'registrations.create', 'registrations.edit', 'registrations.delete',
			'registrations.view_holding', 'registrations.view_assigned', 'registrations.view_approved', 'registrations.view_bin',
			'review.view', 'review.approve', 'review.disapprove', 'review.add_note',
			'assignment.view', 'assignment.assign', 'assignment.reassign', 'assignment.unassign',
			'approved.view', 'approved.edit', 'approved.delete', 'approved.export',
			'bin.view', 'bin.restore', 'bin.delete', 'bin.empty',
			'analytics.view', 'analytics.export',
			'reports.view', 'reports.create', 'reports.export',
			'users.view', 'users.create', 'users.edit', 'users.disable', 'users.delete',
			'roles.view', 'roles.create', 'roles.edit', 'roles.delete', 'roles.assign_permissions',
			'audit.view', 'audit.export',
			'settings.view', 'settings.edit',
		);
		$registry = new PermissionRegistry();
		foreach ( $arch_17 as $key ) {
			$this->assertTrue( $registry->has( $key ), "Missing {$key}" );
		}
	}

	public function test_contains_decision_and_import_permissions(): void {
		$registry = new PermissionRegistry();
		foreach ( array( 'assignment.receive', 'registrations.export', 'imports.view', 'imports.upload', 'imports.validate', 'imports.confirm', 'imports.rollback', 'imports.view_history' ) as $key ) {
			$this->assertTrue( $registry->has( $key ), "Missing {$key}" );
		}
	}

	public function test_approved_edit_and_delete_are_reserved(): void {
		$registry = new PermissionRegistry();
		$this->assertTrue( $registry->get( 'approved.edit' )->reserved );
		$this->assertTrue( $registry->get( 'approved.delete' )->reserved );
		$this->assertTrue( $registry->get( 'registrations.delete' )->reserved, 'OD-19' );
		$this->assertFalse( $registry->get( 'review.approve' )->reserved );
	}

	public function test_all_keys_are_well_formed_and_unique(): void {
		$keys = ( new PermissionRegistry() )->keys();
		$this->assertSame( count( $keys ), count( array_unique( $keys ) ) );
		foreach ( $keys as $key ) {
			$this->assertTrue( Permission::is_valid_key( $key ), $key );
		}
	}

	public function test_grouping_covers_every_permission(): void {
		$registry = new PermissionRegistry();
		$count    = array_sum( array_map( static fn( array $g ): int => count( $g['permissions'] ), $registry->grouped() ) );
		$this->assertSame( count( $registry->keys() ), $count );
	}

	public function test_key_validation(): void {
		$this->assertTrue( Permission::is_valid_key( 'bin.view' ) );
		$this->assertFalse( Permission::is_valid_key( 'bin' ) );
		$this->assertFalse( Permission::is_valid_key( 'Bin.View' ) );
		$this->assertFalse( Permission::is_valid_key( 'bin.view; DROP' ) );
	}
}
