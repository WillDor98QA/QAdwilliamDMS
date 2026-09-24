<?php
/**
 * REQ-WF / REQ-EDIT / REQ-ACCESS — staff workflow with permission, object access,
 * assignee and audit checks (ARCH §7, §10, §26, §71.2, §76, §78.5–§78.7, §78.14, §78.23–§78.24;
 * Decisions §5, §6, §17–§19; OD-16, OD-17, OD-20).
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\NotFoundException;
use DMS\Errors\ValidationException;
use DMS\Notifications\NotificationService;
use DMS\Plugin;
use DMS\Registrations\RegistrationFields;
use DMS\Workflow\InvalidTransitionException;
use DMS\Workflow\RegistrationWorkflow;

final class RegistrationWorkflowTest extends \WP_UnitTestCase {

	use Fixtures;

	/** @var array{region:int,constituency:int,station:int} */
	private array $h;
	private int $officer;

	public function set_up(): void {
		parent::set_up();
		delete_option( \DMS\Support\Settings::OPTION );
		$this->h       = $this->make_hierarchy();
		$this->officer = $this->make_officer( array( $this->h['region'] ) );
	}

	private function wf(): RegistrationWorkflow {
		return Plugin::instance()->workflow();
	}

	private function row( int $id ): object {
		return Plugin::instance()->registrations()->get( $id );
	}

	/** A registration assigned to $this->officer. */
	private function assigned( array $overrides = array() ): int {
		$id = $this->make_registration( $this->h, $overrides );
		Plugin::instance()->assignments()->auto_assign( $id );
		return $id;
	}

	private function under_review( array $overrides = array() ): int {
		$id = $this->assigned( $overrides );
		wp_set_current_user( $this->officer );
		$this->wf()->start_review( $id );
		return $id;
	}

	private function actions( int $id ): array {
		return array_map( static fn( $e ) => $e->action, Plugin::instance()->audit()->for_registration( $id ) );
	}

	private function notifications( int $id, string $event ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d AND event = %s', $id, $event ) );
	}

	// ---------------------------------------------------------------- review

	public function test_assigned_officer_starts_review(): void {
		$id = $this->assigned();
		wp_set_current_user( $this->officer );
		$this->wf()->start_review( $id );
		$row = $this->row( $id );
		$this->assertSame( 'UNDER_REVIEW', $row->status );
		$this->assertNotNull( $row->reviewed_at );
		$this->assertContains( AuditAction::REVIEW_STARTED, $this->actions( $id ) );

		$this->wf()->start_review( $id ); // Re-opening is a no-op.
		$this->assertSame( 1, count( array_keys( $this->actions( $id ), AuditAction::REVIEW_STARTED, true ) ) );
	}

	public function test_other_officer_cannot_start_review(): void {
		$id    = $this->assigned();
		$other = $this->make_officer( array( $this->h['region'] ) );
		wp_set_current_user( $other );
		$this->expectException( NotFoundException::class ); // Not assigned → not even visible to them.
		$this->wf()->start_review( $id );
	}

	public function test_admin_viewing_does_not_change_status_but_is_audited(): void {
		$id = $this->assigned();
		$this->act_as_admin();
		$this->wf()->view( $id );
		$this->assertSame( 'ASSIGNED', $this->row( $id )->status );
		$this->assertContains( AuditAction::VIEWED, $this->actions( $id ) );
	}

	public function test_admin_who_is_not_assignee_cannot_start_review(): void {
		$id = $this->assigned();
		$this->act_as_admin();
		$this->expectException( AuthorizationException::class );
		$this->wf()->start_review( $id );
	}

	// ------------------------------------------------------------------ edit

	/** @return iterable<string,array{string}> */
	public static function editable_states(): iterable {
		yield 'pending' => array( 'PENDING' );
		yield 'assigned' => array( 'ASSIGNED' );
		yield 'under review' => array( 'UNDER_REVIEW' );
	}

	/** @dataProvider editable_states */
	public function test_holding_area_records_can_be_edited_and_every_edit_is_audited( string $state ): void {
		$id = match ( $state ) {
			'PENDING'  => $this->make_registration( $this->h, array( 'first_name' => 'Kofi', 'organization' => 'Old Org' ) ),
			'ASSIGNED' => $this->assigned( array( 'first_name' => 'Kofi', 'organization' => 'Old Org' ) ),
			default    => $this->under_review( array( 'first_name' => 'Kofi', 'organization' => 'Old Org' ) ),
		};
		$editor = $this->act_as( array( 'registrations.edit', 'registrations.view' ) );

		$diff = $this->wf()->edit( $id, array( 'first_name' => 'Kwame', 'organization' => 'New Org' ), 'Typo on form' );

		$this->assertSame( array( 'old' => 'Kofi', 'new' => 'Kwame' ), $diff['first_name'] );
		$this->assertSame( 'Kwame', $this->row( $id )->first_name );
		$edits = array_values( array_filter( Plugin::instance()->audit()->for_registration( $id ), static fn( $e ) => AuditAction::EDITED === $e->action ) );
		$this->assertCount( 1, $edits );
		$this->assertSame( (string) $editor, (string) $edits[0]->user_id );
		$this->assertSame( 'Typo on form', $edits[0]->reason );
		$meta = json_decode( $edits[0]->metadata, true );
		$this->assertSame( array( 'old' => 'Old Org', 'new' => 'New Org' ), $meta['changes']['organization'] );
		$this->assertSame( $state, $this->row( $id )->status, 'Editing does not change status' );
	}

	public function test_approved_record_edit_is_rejected_and_audited(): void {
		$id = $this->under_review();
		$this->wf()->approve( $id );
		$this->act_as( array( 'registrations.edit', 'registrations.view' ) );

		try {
			$this->wf()->edit( $id, array( 'first_name' => 'Tampered' ) );
			$this->fail( 'Approved must be locked' );
		} catch ( ConflictException $e ) {
			$this->assertSame( 'dms_record_locked', $e->error_code() );
		}
		$this->assertSame( 'Ama', $this->row( $id )->first_name );
		$this->assertContains( AuditAction::EDIT_REJECTED, $this->actions( $id ) );
	}

	public function test_edit_requires_permission_and_object_access(): void {
		$mine  = $this->assigned();
		$other = $this->make_registration( $this->make_hierarchy() );

		$this->act_as( array( 'registrations.view' ) );
		try {
			$this->wf()->edit( $mine, array( 'first_name' => 'X' ) );
			$this->fail( 'registrations.edit required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}

		$limited = self::factory()->user->create();
		\DMS\Plugin::instance()->roles()->assign_user( $limited, $this->role_with( array( 'registrations.edit', 'registrations.view_assigned' ) ) );
		wp_set_current_user( $limited );
		$this->expectException( NotFoundException::class );
		$this->wf()->edit( $other, array( 'first_name' => 'X' ) );
	}

	public function test_edit_validates_values_and_blocks_phone(): void {
		$id = $this->assigned();
		$this->act_as( array( 'registrations.edit', 'registrations.view' ) );
		try {
			$this->wf()->edit( $id, array( 'email' => 'nope', 'phone' => '0241112222', 'status' => 'APPROVED' ) );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertEqualsCanonicalizing( array( 'email', 'phone', 'status' ), array_keys( $e->errors ) );
		}
	}

	public function test_no_op_edit_writes_no_audit(): void {
		$id = $this->assigned();
		$this->act_as( array( 'registrations.edit', 'registrations.view' ) );
		$this->assertSame( array(), $this->wf()->edit( $id, array( 'first_name' => 'Ama' ) ) );
		$this->assertNotContains( AuditAction::EDITED, $this->actions( $id ) );
	}

	// ------------------------------------------------------ approve / disapprove

	public function test_assigned_officer_approves_and_applicant_is_emailed(): void {
		$id = $this->under_review( array( 'email' => 'applicant@example.org' ) );
		$this->wf()->approve( $id );

		$row = $this->row( $id );
		$this->assertSame( 'APPROVED', $row->status );
		$this->assertSame( (string) $this->officer, (string) $row->approved_by );
		$this->assertNotNull( $row->approved_at );
		$this->assertContains( AuditAction::APPROVED, $this->actions( $id ) );
		$mail = $this->notifications( $id, NotificationService::EVENT_REGISTRATION_APPROVED );
		$this->assertCount( 1, $mail );
		$this->assertSame( 'applicant@example.org', $mail[0]->recipient_email );
	}

	public function test_only_assigned_officer_may_approve(): void {
		$id = $this->under_review();
		$this->act_as( array( 'review.approve', 'registrations.view' ) ); // e.g. a supervisor (OD-17)
		$this->expectException( AuthorizationException::class );
		$this->wf()->approve( $id );
	}

	public function test_cannot_approve_before_review_starts(): void {
		$id = $this->assigned();
		wp_set_current_user( $this->officer );
		$this->expectException( InvalidTransitionException::class );
		$this->wf()->approve( $id );
	}

	public function test_approved_cannot_be_disapproved(): void {
		$id = $this->under_review();
		$this->wf()->approve( $id );
		$this->expectException( InvalidTransitionException::class );
		$this->wf()->disapprove( $id, 'Changed my mind' );
	}

	public function test_disapproval_requires_a_reason(): void {
		$id = $this->under_review();
		try {
			$this->wf()->disapprove( $id, 'bad' );
			$this->fail( 'Reason of < 5 characters must be rejected' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'reason', $e->errors );
		}
		$this->assertSame( 'UNDER_REVIEW', $this->row( $id )->status );
	}

	public function test_disapproval_is_audited_and_sends_no_public_email(): void {
		$id = $this->under_review( array( 'email' => 'applicant@example.org' ) );
		$this->wf()->disapprove( $id, 'Invalid documentation' );

		$row = $this->row( $id );
		$this->assertSame( 'DISAPPROVED', $row->status );
		$this->assertSame( 'Invalid documentation', $row->disapproval_reason );
		$audit = array_values( array_filter( Plugin::instance()->audit()->for_registration( $id ), static fn( $e ) => AuditAction::DISAPPROVED === $e->action ) );
		$this->assertSame( 'Invalid documentation', $audit[0]->reason );

		global $wpdb;
		$to_applicant = $wpdb->get_col( $wpdb->prepare( 'SELECT event FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d AND recipient_email = %s', $id, 'applicant@example.org' ) );
		$this->assertNotContains( NotificationService::EVENT_REGISTRATION_APPROVED, $to_applicant );
		$this->assertSame( array(), array_diff( $to_applicant, array( NotificationService::EVENT_REGISTRATION_RECEIVED ) ), 'No disapproval email of any kind' );
	}

	public function test_approval_email_failure_does_not_undo_approval(): void {
		$id = $this->under_review( array( 'email' => 'applicant@example.org' ) );
		add_filter( 'pre_wp_mail', '__return_false' );
		$this->wf()->approve( $id );
		remove_filter( 'pre_wp_mail', '__return_false' );
		$this->assertSame( 'APPROVED', $this->row( $id )->status );
		$this->assertSame( NotificationService::STATUS_FAILED, $this->notifications( $id, NotificationService::EVENT_REGISTRATION_APPROVED )[0]->status );
	}

	// -------------------------------------------------------- bin: restore / delete

	public function test_restore_clears_assignment_returns_to_pending_and_reassigns(): void {
		$id = $this->under_review();
		$this->wf()->disapprove( $id, 'Invalid documentation' );

		$this->act_as( array( 'bin.restore', 'bin.view' ) );
		$this->wf()->restore( $id );

		$row = $this->row( $id );
		$this->assertContains( AuditAction::RESTORED, $this->actions( $id ) );
		$this->assertNull( $row->disapproved_at );
		$this->assertNull( $row->reviewed_at );
		// The only officer in the region is chosen afresh by the engine (OD-22).
		$this->assertSame( 'ASSIGNED', $row->status );
		$this->assertCount( 2, array_keys( $this->actions( $id ), AuditAction::ASSIGNED, true ) );
	}

	public function test_restore_without_available_officer_stays_pending_unassigned(): void {
		$id = $this->under_review();
		$this->wf()->disapprove( $id, 'Invalid documentation' );
		Plugin::instance()->profiles()->set_status( $this->officer, \DMS\Users\UserProfileRepository::STATUS_DISABLED, 1 );

		$this->act_as( array( 'bin.restore', 'bin.view' ) );
		$this->wf()->restore( $id );
		$row = $this->row( $id );
		$this->assertSame( 'PENDING', $row->status );
		$this->assertNull( $row->assigned_officer_id );
		$this->assertNull( $row->assigned_at );
	}

	public function test_restore_requires_permission(): void {
		$id = $this->under_review();
		$this->wf()->disapprove( $id, 'Invalid documentation' );
		$this->act_as( array( 'bin.view' ) ); // Auditor: can see the Bin, cannot restore (ARCH §16).
		$this->assertSame( 'DISAPPROVED', $this->wf()->view( $id )->status );
		$this->expectException( AuthorizationException::class );
		$this->wf()->restore( $id );
	}

	public function test_permanent_delete_tombstones_and_scrubs_personal_data(): void {
		$id = $this->under_review( array( 'last_name' => 'Mensah-Unique', 'email' => 'secret.person@example.org' ) );
		$editor = $this->act_as( array( 'registrations.edit', 'registrations.view' ) );
		$this->wf()->edit( $id, array( 'last_name' => 'Mensah-Edited' ) );
		wp_set_current_user( $this->officer );
		$this->wf()->disapprove( $id, 'Invalid documentation' );

		$deleter = $this->act_as( array( 'bin.delete', 'bin.view' ) );
		$this->wf()->delete_permanently( $id );

		$row = $this->row( $id );
		$this->assertSame( 'DELETED', $row->status );
		foreach ( RegistrationFields::PII as $column ) {
			$this->assertNull( $row->{$column}, $column );
		}
		global $wpdb;
		$audit_text = implode( ' ', $wpdb->get_col( $wpdb->prepare( 'SELECT COALESCE(metadata, "") FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE registration_id = %d', $id ) ) );
		$this->assertStringNotContainsString( 'Mensah-Unique', $audit_text );
		$this->assertStringNotContainsString( 'Mensah-Edited', $audit_text );
		$this->assertStringContainsString( AuditService::SCRUBBED, $audit_text );
		$mail_text = implode( ' ', $wpdb->get_col( $wpdb->prepare( 'SELECT CONCAT_WS(" ", recipient_email, subject, body) FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d', $id ) ) );
		$this->assertStringNotContainsString( 'secret.person@example.org', $mail_text );

		$deleted = array_values( array_filter( Plugin::instance()->audit()->for_registration( $id ), static fn( $e ) => AuditAction::DELETED === $e->action ) );
		$this->assertSame( (string) $deleter, (string) $deleted[0]->user_id );
		$this->assertContains( AuditAction::EDITED, $this->actions( $id ), 'History is kept, only values are scrubbed' );
		unset( $editor );
	}

	public function test_only_bin_records_can_be_permanently_deleted(): void {
		$id = $this->assigned();
		$this->act_as( array( 'bin.delete', 'registrations.view' ) );
		$this->expectException( InvalidTransitionException::class );
		$this->wf()->delete_permanently( $id );
	}

	public function test_deleted_record_is_no_longer_visible(): void {
		$id = $this->under_review();
		$this->wf()->disapprove( $id, 'Invalid documentation' );
		$this->act_as_admin();
		$this->wf()->delete_permanently( $id );
		$this->expectException( NotFoundException::class );
		$this->wf()->view( $id );
	}

	// ------------------------------------------------------------- access / notes

	public function test_access_scopes_by_permission(): void {
		$bin_id  = $this->under_review();
		$this->wf()->disapprove( $bin_id, 'Invalid documentation' );
		$hold_id = $this->make_registration( $this->h );

		$auditor = self::factory()->user->create();
		Plugin::instance()->roles()->assign_user( $auditor, $this->role_with( array( 'bin.view' ) ) );
		$policy = Plugin::instance()->access_policy();

		$this->assertTrue( $policy->can_view( $auditor, $this->row( $bin_id ) ) );
		$this->assertFalse( $policy->can_view( $auditor, $this->row( $hold_id ) ) );
		$this->assertTrue( $policy->can_view( $this->officer, $this->row( $bin_id ) ), 'Officer still sees records assigned to them' );
		$this->assertFalse( $policy->can_view( $this->officer, $this->row( $hold_id ) ) );
		$this->assertFalse( $policy->can_view( 0, $this->row( $hold_id ) ) );
	}

	public function test_notes_are_recorded_in_history(): void {
		$id = $this->under_review();
		$this->wf()->add_note( $id, 'Called the applicant to confirm details.' );
		$notes = array_values( array_filter( Plugin::instance()->audit()->for_registration( $id ), static fn( $e ) => AuditAction::NOTE_ADDED === $e->action ) );
		$this->assertSame( 'Called the applicant to confirm details.', $notes[0]->reason );
		$this->expectException( ValidationException::class );
		$this->wf()->add_note( $id, '   ' );
	}
}
