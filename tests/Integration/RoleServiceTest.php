<?php
/**
 * REQ-ROLE-001..007 configurable roles (ARCH §14, §18; MP §31) with escalation guard (ID-13).
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Plugin;

final class RoleServiceTest extends \WP_UnitTestCase {

	use Fixtures;

	private function last_audit( string $action ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE action = %s ORDER BY id DESC LIMIT 1', $action ) );
	}

	public function test_admin_creates_role_with_permissions_and_it_is_audited(): void {
		$this->act_as_admin();
		$id = Plugin::instance()->role_service()->create( 'Data Verification Officer', 'Reviews', array( 'review.view', 'review.approve' ) );

		$role = Plugin::instance()->roles()->find( $id );
		$this->assertSame( 'data-verification-officer', $role->slug );
		$this->assertSame( array( 'review.approve', 'review.view' ), Plugin::instance()->roles()->permissions( $id ) );
		$audit = $this->last_audit( AuditAction::ROLE_CREATED );
		$this->assertSame( (string) $id, (string) $audit->object_id );
		$this->assertStringContainsString( 'review.approve', $audit->metadata );
	}

	public function test_create_requires_roles_create(): void {
		$this->act_as( array( 'roles.view' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->role_service()->create( 'Nope' );
	}

	public function test_granting_permissions_requires_assign_permissions(): void {
		$this->act_as( array( 'roles.create', 'review.view' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->role_service()->create( 'Needs assign', '', array( 'review.view' ) );
	}

	public function test_cannot_grant_permission_actor_does_not_hold(): void {
		$this->act_as( array( 'roles.create', 'roles.assign_permissions', 'review.view' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->role_service()->create( 'Escalation', '', array( 'review.view', 'users.create' ) );
	}

	public function test_unknown_and_reserved_permissions_are_rejected(): void {
		$this->act_as_admin();
		foreach ( array( 'made.up', 'approved.edit', 'registrations.delete' ) as $bad ) {
			try {
				Plugin::instance()->role_service()->create( 'Bad ' . $bad, '', array( $bad ) );
				$this->fail( "{$bad} must be rejected" );
			} catch ( ValidationException $e ) {
				$this->assertArrayHasKey( 'permissions', $e->errors );
			}
		}
	}

	public function test_duplicate_role_name_rejected(): void {
		$this->act_as_admin();
		Plugin::instance()->role_service()->create( 'Auditor' );
		$this->expectException( ValidationException::class );
		Plugin::instance()->role_service()->create( 'auditor' );
	}

	public function test_set_permissions_records_added_and_removed(): void {
		$this->act_as_admin();
		$service = Plugin::instance()->role_service();
		$id      = $service->create( 'Changer', '', array( 'bin.view', 'review.view' ) );
		$service->set_permissions( $id, array( 'bin.view', 'bin.restore' ) );

		$meta = json_decode( $this->last_audit( AuditAction::PERMISSIONS_CHANGED )->metadata, true );
		$this->assertSame( array( 'bin.restore' ), $meta['added'] );
		$this->assertSame( array( 'review.view' ), $meta['removed'] );
	}

	public function test_limited_user_cannot_strip_permissions_they_do_not_hold(): void {
		$this->act_as_admin();
		$target = Plugin::instance()->role_service()->create( 'Powerful', '', array( 'users.create', 'review.view' ) );
		$this->act_as( array( 'roles.edit', 'roles.assign_permissions', 'review.view' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->role_service()->set_permissions( $target, array( 'review.view' ) );
	}

	public function test_system_role_is_protected(): void {
		$this->act_as_admin();
		$admin = Plugin::instance()->roles()->find_by_slug( 'administrator' );
		foreach ( array( 'update', 'set_permissions', 'delete' ) as $method ) {
			try {
				match ( $method ) {
					'update'          => Plugin::instance()->role_service()->update( (int) $admin->id, array( 'status' => 'INACTIVE' ) ),
					'set_permissions' => Plugin::instance()->role_service()->set_permissions( (int) $admin->id, array() ),
					'delete'          => Plugin::instance()->role_service()->delete( (int) $admin->id ),
				};
				$this->fail( "{$method} on system role must fail" );
			} catch ( ConflictException $e ) {
				$this->assertSame( 'dms_protected_role', $e->error_code() );
			}
		}
	}

	public function test_role_in_use_cannot_be_deleted(): void {
		$this->act_as_admin();
		$id   = Plugin::instance()->role_service()->create( 'In use' );
		$user = self::factory()->user->create();
		Plugin::instance()->roles()->assign_user( $user, $id );
		try {
			Plugin::instance()->role_service()->delete( $id );
			$this->fail( 'Expected ConflictException' );
		} catch ( ConflictException $e ) {
			$this->assertSame( 'dms_role_in_use', $e->error_code() );
		}
		Plugin::instance()->roles()->remove_user( $user, $id );
		Plugin::instance()->role_service()->delete( $id );
		$this->assertNull( Plugin::instance()->roles()->find( $id ) );
		$this->assertNotNull( $this->last_audit( AuditAction::ROLE_DELETED ) );
	}

	public function test_update_audits_only_real_changes(): void {
		$this->act_as_admin();
		$service = Plugin::instance()->role_service();
		$id      = $service->create( 'Renamable', 'old' );
		$service->update( $id, array( 'description' => 'new' ) );
		$meta = json_decode( $this->last_audit( AuditAction::ROLE_UPDATED )->metadata, true );
		$this->assertSame( array( 'description' => array( 'old' => 'old', 'new' => 'new' ) ), $meta['changes'] );
	}
}
