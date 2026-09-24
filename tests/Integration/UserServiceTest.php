<?php
/**
 * REQ-USER-001..010 user management through native WP users
 * (ARCH §12, §13, §72.1, §72.5; MP §24, §30; ID-13, ID-14).
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Plugin;
use DMS\Workflow\Status;

final class UserServiceTest extends \WP_UnitTestCase {

	use Fixtures;

	private static int $n = 0;

	private function input( array $overrides = array() ): array {
		$n = ++self::$n;
		return array_merge(
			array(
				'first_name' => 'Kwame',
				'last_name'  => 'Boateng',
				'email'      => "kwame{$n}@example.org",
				'username'   => "kwame{$n}",
				'password'   => 'correct-horse-battery',
				'role_ids'   => array(),
				'region_ids' => array(),
			),
			$overrides
		);
	}

	private function officer_role(): int {
		return (int) Plugin::instance()->roles()->find_by_slug( 'verification-officer' )->id;
	}

	private function audit_rows( string $action, int $user_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::AUDIT_LOG ) . " WHERE action = %s AND object_type = 'user' AND object_id = %d", $action, $user_id ) );
	}

	public function test_creates_native_wordpress_user_with_roles_regions_and_audit(): void {
		$this->act_as_admin();
		$h  = $this->make_hierarchy();
		$id = Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $this->officer_role() ), 'region_ids' => array( $h['region'] ) ) ) );

		$user = get_user_by( 'id', $id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( array( 'dms_user' ), $user->roles );
		$this->assertTrue( wp_check_password( 'correct-horse-battery', $user->user_pass, $id ), 'WordPress hashes the password' );
		$this->assertTrue( user_can( $id, 'review.approve' ) );
		$this->assertSame( array( $h['region'] ), Plugin::instance()->officer_regions()->active_region_ids( $id ) );

		$audit = $this->audit_rows( AuditAction::USER_CREATED, $id );
		$this->assertCount( 1, $audit );
		$this->assertStringNotContainsString( 'correct-horse-battery', $audit[0]->metadata );
	}

	public function test_officer_requires_a_region(): void {
		$this->act_as_admin();
		try {
			Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $this->officer_role() ) ) ) );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'region_ids', $e->errors );
		}
	}

	public function test_validation_errors_are_reported_per_field(): void {
		$this->act_as_admin();
		try {
			Plugin::instance()->user_service()->create( $this->input( array( 'email' => 'not-an-email', 'password' => 'short', 'first_name' => '' ) ) );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertEqualsCanonicalizing( array( 'email', 'password', 'first_name' ), array_keys( $e->errors ) );
		}
	}

	public function test_create_requires_users_create(): void {
		$this->act_as( array( 'users.view' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->user_service()->create( $this->input() );
	}

	public function test_cannot_assign_role_with_permissions_actor_lacks(): void {
		$this->act_as( array( 'users.create' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $this->role_with( array( 'bin.delete' ) ) ) ) ) );
	}

	public function test_only_administrator_can_assign_administrator_role(): void {
		$everything = array_values( array_filter( Plugin::instance()->permissions()->keys(), static fn( string $k ) => ! Plugin::instance()->permissions()->get( $k )->reserved ) );
		$this->act_as( $everything );
		$admin_role = (int) Plugin::instance()->roles()->find_by_slug( 'administrator' )->id;
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $admin_role ) ) ) );
	}

	public function test_cannot_edit_wordpress_administrator_account(): void {
		$wp_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->act_as( array( 'users.edit' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->user_service()->update( $wp_admin, array( 'email' => 'attacker@example.org' ) );
	}

	public function test_cannot_edit_user_with_more_permissions(): void {
		$this->act_as_admin();
		$strong = Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $this->role_with( array( 'bin.delete' ) ) ) ) ) );
		$this->act_as( array( 'users.edit' ) );
		$this->expectException( AuthorizationException::class );
		Plugin::instance()->user_service()->update( $strong, array( 'first_name' => 'Hijacked' ) );
	}

	public function test_update_changes_roles_and_regions_with_audit(): void {
		$this->act_as_admin();
		$a  = $this->make_hierarchy();
		$b  = $this->make_hierarchy();
		$id = Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $this->officer_role() ), 'region_ids' => array( $a['region'] ) ) ) );

		Plugin::instance()->user_service()->update( $id, array( 'region_ids' => array( $b['region'] ), 'first_name' => 'Kojo' ) );

		$this->assertSame( array( $b['region'] ), Plugin::instance()->officer_regions()->active_region_ids( $id ) );
		$this->assertSame( 'Kojo', get_user_meta( $id, 'first_name', true ) );
		$this->assertCount( 1, $this->audit_rows( AuditAction::USER_REGIONS_CHANGED, $id ) );
		$this->assertCount( 1, $this->audit_rows( AuditAction::USER_UPDATED, $id ) );
	}

	public function test_removing_all_regions_from_officer_is_rejected(): void {
		$this->act_as_admin();
		$h  = $this->make_hierarchy();
		$id = Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $this->officer_role() ), 'region_ids' => array( $h['region'] ) ) ) );
		$this->expectException( ValidationException::class );
		Plugin::instance()->user_service()->update( $id, array( 'region_ids' => array() ) );
	}

	public function test_disable_revokes_access_keeps_history_and_reports_outstanding_work(): void {
		$admin = $this->act_as_admin();
		$h     = $this->make_hierarchy();
		$id    = Plugin::instance()->user_service()->create( $this->input( array( 'role_ids' => array( $this->officer_role() ), 'region_ids' => array( $h['region'] ) ) ) );
		$reg   = $this->make_registration( $h );
		Plugin::instance()->registrations()->transition( $reg, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => $id ) );

		$outstanding = Plugin::instance()->user_service()->disable( $id, 'Left the organization' );

		$this->assertSame( 1, $outstanding );
		$this->assertFalse( user_can( $id, 'review.approve' ) );
		$this->assertNotFalse( get_user_by( 'id', $id ), 'User is disabled, not deleted' );
		$this->assertSame( (string) $id, (string) Plugin::instance()->registrations()->get( $reg )->assigned_officer_id, 'Historical assignment kept' );
		$this->assertSame( array(), Plugin::instance()->officer_regions()->active_user_ids_for_region( $h['region'] ) );
		$row = $this->audit_rows( AuditAction::USER_DISABLED, $id )[0];
		$this->assertSame( (string) $admin, (string) $row->user_id );
		$this->assertStringContainsString( '"outstanding_registrations":1', $row->metadata );

		Plugin::instance()->user_service()->enable( $id );
		$this->assertTrue( user_can( $id, 'review.approve' ) );
		$this->assertCount( 1, $this->audit_rows( AuditAction::USER_ENABLED, $id ) );
	}

	public function test_cannot_disable_self(): void {
		$admin = $this->act_as_admin();
		$this->expectException( ConflictException::class );
		Plugin::instance()->user_service()->disable( $admin );
	}

	public function test_last_active_administrator_cannot_be_disabled_or_demoted(): void {
		global $wpdb;
		// Start from a state with no administrators, then create exactly one.
		$wpdb->query( 'DELETE ur FROM ' . Tables::name( Tables::USER_ROLES ) . ' ur INNER JOIN ' . Tables::name( Tables::ROLES ) . ' r ON r.id = ur.role_id WHERE r.is_system = 1' );
		Plugin::instance()->capabilities()->flush();

		$only_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin_role = (int) Plugin::instance()->roles()->find_by_slug( 'administrator' )->id;
		Plugin::instance()->roles()->assign_user( $only_admin, $admin_role );

		// A second WP admin with the DMS Administrator role acts; then leaves only $only_admin active.
		$actor = $this->act_as_admin();
		Plugin::instance()->user_service()->disable( $only_admin );
		$this->assertSame( 1, Plugin::instance()->roles()->count_system_role_members() );

		try {
			Plugin::instance()->user_service()->update( $actor, array( 'role_ids' => array() ) );
			$this->fail( 'Last administrator must keep the role' );
		} catch ( ConflictException $e ) {
			$this->assertSame( 'dms_last_administrator', $e->error_code() );
		}
	}
}
