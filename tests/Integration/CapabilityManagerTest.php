<?php
/**
 * REQ-PERM-003..009 — permissions reach current_user_can() through configurable roles
 * (ARCH §15, §16, §19, §20, §25; Decisions §35).
 */

namespace DMS\Tests\Integration;

use DMS\Plugin;
use DMS\Users\UserProfileRepository;

final class CapabilityManagerTest extends \WP_UnitTestCase {

	private function role( string $slug, array $permissions ): int {
		$roles = Plugin::instance()->roles();
		$id    = $roles->create( ucfirst( $slug ), $slug );
		$roles->set_permissions( $id, $permissions );
		return $id;
	}

	private function user_with_roles( int ...$role_ids ): int {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( $role_ids as $role_id ) {
			Plugin::instance()->roles()->assign_user( $user_id, $role_id );
		}
		return $user_id;
	}

	public function test_role_permission_is_visible_to_current_user_can(): void {
		$user = $this->user_with_roles( $this->role( 'auditor', array( 'bin.view' ) ) );
		wp_set_current_user( $user );

		$this->assertTrue( current_user_can( 'bin.view' ) );
		$this->assertFalse( current_user_can( 'bin.restore' ), 'Menu permission does not imply action permission (ARCH §16)' );
	}

	public function test_multiple_roles_are_a_union(): void {
		$user = $this->user_with_roles(
			$this->role( 'officer-x', array( 'review.view', 'review.approve' ) ),
			$this->role( 'analytics-viewer', array( 'analytics.view', 'analytics.export' ) )
		);
		$this->assertEqualsCanonicalizing(
			array( 'review.view', 'review.approve', 'analytics.view', 'analytics.export' ),
			Plugin::instance()->capabilities()->permissions_for( $user )
		);
	}

	public function test_changing_role_permissions_takes_effect_without_code_changes(): void {
		$role = $this->role( 'changer', array( 'review.view' ) );
		$user = $this->user_with_roles( $role );
		$this->assertFalse( user_can( $user, 'bin.view' ) );

		Plugin::instance()->roles()->set_permissions( $role, array( 'review.view', 'bin.view' ) );
		$this->assertTrue( user_can( $user, 'bin.view' ) );
	}

	public function test_inactive_role_grants_nothing(): void {
		global $wpdb;
		$role = $this->role( 'dormant', array( 'audit.view' ) );
		$user = $this->user_with_roles( $role );
		$wpdb->update( \DMS\Database\Tables::name( \DMS\Database\Tables::ROLES ), array( 'status' => 'INACTIVE' ), array( 'id' => $role ) );
		Plugin::instance()->capabilities()->flush();

		$this->assertFalse( user_can( $user, 'audit.view' ) );
	}

	public function test_disabled_user_has_no_permissions_and_cannot_log_in(): void {
		$user = $this->user_with_roles( $this->role( 'to-disable', array( 'review.view' ) ) );
		$this->assertTrue( user_can( $user, 'review.view' ) );

		Plugin::instance()->profiles()->set_status( $user, UserProfileRepository::STATUS_DISABLED, 1 );

		$this->assertFalse( user_can( $user, 'review.view' ) );
		$result = apply_filters( 'wp_authenticate_user', get_user_by( 'id', $user ), 'password' );
		$this->assertWPError( $result );
		$this->assertSame( 'dms_account_disabled', $result->get_error_code() );
	}

	public function test_system_administrator_role_has_every_non_reserved_permission(): void {
		$admin_role = Plugin::instance()->roles()->find_by_slug( 'administrator' );
		$user       = $this->user_with_roles( (int) $admin_role->id );

		foreach ( Plugin::instance()->permissions()->all() as $key => $permission ) {
			$this->assertSame( ! $permission->reserved, user_can( $user, $key ), $key );
		}
	}

	public function test_reserved_permission_is_never_granted_even_if_stored(): void {
		$user = $this->user_with_roles( $this->role( 'sneaky', array( 'approved.edit' ) ) );
		$this->assertFalse( user_can( $user, 'approved.edit' ), 'Approved records are locked in V1' );
	}

	public function test_capability_granted_directly_on_wp_role_is_ignored(): void {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_role( 'editor' )->add_cap( 'review.approve' );
		try {
			$this->assertFalse( user_can( $user, 'review.approve' ), 'DMS tables are the only source of truth' );
		} finally {
			get_role( 'editor' )->remove_cap( 'review.approve' );
		}
	}

	public function test_wordpress_administrator_without_dms_role_has_no_dms_permissions(): void {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue( user_can( $user, 'manage_options' ), 'Native caps untouched' );
		$this->assertFalse( user_can( $user, 'review.approve' ) );
	}

	public function test_guest_has_no_permissions(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( current_user_can( 'dashboard.view' ) );
	}

	public function test_activation_bootstraps_first_administrator_once(): void {
		$first  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$second = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( Plugin::instance()->bootstrap_administrator( $first ) );
		$this->assertFalse( Plugin::instance()->bootstrap_administrator( $second ), 'Only when nobody holds the role' );
		$this->assertTrue( user_can( $first, 'roles.edit' ) );
		$this->assertFalse( user_can( $second, 'roles.edit' ) );
	}

	public function test_bootstrap_refuses_non_admin_wp_user(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertFalse( Plugin::instance()->bootstrap_administrator( $user ) );
	}
}
