<?php
/**
 * REQ-ASSIGN-* automatic regional assignment, manual (re)assignment and exceptions
 * (ARCH §72, §78.8–§78.13; Decisions §4; OD-16; MP §22–§24, §39 "Assignment").
 */

namespace DMS\Tests\Integration;

use DMS\Assignments\AssignmentExceptionRepository;
use DMS\Assignments\AssignmentService;
use DMS\Audit\ActorType;
use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ValidationException;
use DMS\Notifications\NotificationService;
use DMS\Plugin;
use DMS\Users\UserProfileRepository;
use DMS\Workflow\InvalidTransitionException;
use DMS\Workflow\Status;

final class AssignmentServiceTest extends \WP_UnitTestCase {

	use Fixtures;

	/** @var array{region:int,constituency:int,station:int} */
	private array $h;

	public function set_up(): void {
		parent::set_up();
		delete_option( \DMS\Support\Settings::OPTION );
		$this->h = $this->make_hierarchy();
	}

	public function tear_down(): void {
		Plugin::instance()->clock()->freeze( null );
		parent::tear_down();
	}

	private function engine(): AssignmentService {
		return Plugin::instance()->assignments();
	}

	private function reg(): object {
		return Plugin::instance()->registrations();
	}

	private function freeze_at( string $time ): void {
		Plugin::instance()->clock()->freeze( new \DateTimeImmutable( $time, new \DateTimeZone( 'UTC' ) ) );
	}

	private function audits( int $registration_id, string $action ): array {
		return array_values( array_filter( Plugin::instance()->audit()->for_registration( $registration_id ), static fn( $e ) => $e->action === $action ) );
	}

	private function give_workload( int $officer, int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$id = $this->make_registration( $this->h );
			$this->reg()->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => $officer ) );
		}
	}

	public function test_assigns_only_to_officers_of_the_registration_region(): void {
		$other   = $this->make_hierarchy();
		$outside = $this->make_officer( array( $other['region'] ) );
		$inside  = $this->make_officer( array( $this->h['region'] ) );

		$officer = $this->engine()->auto_assign( $this->make_registration( $this->h ) );
		$this->assertSame( $inside, $officer );
		$this->assertNotSame( $outside, $officer );
	}

	public function test_requires_assignment_receive_permission(): void {
		$no_receive = self::factory()->user->create();
		Plugin::instance()->roles()->assign_user( $no_receive, $this->role_with( array( 'review.view' ) ) );
		Plugin::instance()->officer_regions()->set_regions( $no_receive, array( $this->h['region'] ), null );

		$this->assertNull( $this->engine()->auto_assign( $this->make_registration( $this->h ) ) );
	}

	public function test_picks_lowest_active_workload(): void {
		$a = $this->make_officer( array( $this->h['region'] ) );
		$b = $this->make_officer( array( $this->h['region'] ) );
		$c = $this->make_officer( array( $this->h['region'] ) );
		$this->give_workload( $a, 3 );
		$this->give_workload( $b, 1 );
		$this->give_workload( $c, 2 );

		$id = $this->make_registration( $this->h );
		$this->assertSame( $b, $this->engine()->auto_assign( $id ) );
		$audit = $this->audits( $id, AuditAction::ASSIGNED )[0];
		$meta  = json_decode( $audit->metadata, true );
		$this->assertSame( AssignmentService::METHOD_AUTOMATIC, $meta['method'] );
		$this->assertSame( AssignmentService::REASON_LOWEST_WORKLOAD, $audit->reason );
		$this->assertNull( $meta['previous_officer_id'] );
		$this->assertSame( ActorType::SYSTEM, $audit->actor_type );
		$this->assertCount( 3, $meta['candidates'] );
	}

	public function test_completed_work_does_not_count_toward_workload(): void {
		$a = $this->make_officer( array( $this->h['region'] ) );
		$b = $this->make_officer( array( $this->h['region'] ) );
		foreach ( array( Status::APPROVED, Status::DISAPPROVED ) as $final ) {
			$id = $this->make_registration( $this->h );
			$this->reg()->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => $a ) );
			$this->reg()->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW );
			$this->reg()->transition( $id, Status::UNDER_REVIEW, $final, Status::DISAPPROVED === $final ? array( 'disapproval_reason' => 'Invalid' ) : array() );
		}
		$this->give_workload( $b, 1 );
		$this->assertSame( $a, $this->engine()->auto_assign( $this->make_registration( $this->h ) ), 'a has 0 active, b has 1' );
	}

	public function test_tie_goes_to_officer_waiting_longest(): void {
		$a = $this->make_officer( array( $this->h['region'] ) );
		$b = $this->make_officer( array( $this->h['region'] ) );
		Plugin::instance()->profiles()->touch_last_assigned( $a, '2026-09-01 10:00:00' );
		Plugin::instance()->profiles()->touch_last_assigned( $b, '2026-08-01 10:00:00' );

		$id = $this->make_registration( $this->h );
		$this->assertSame( $b, $this->engine()->auto_assign( $id ) );
		$this->assertSame( AssignmentService::REASON_TIE_BREAK, $this->audits( $id, AuditAction::ASSIGNED )[0]->reason );
	}

	public function test_never_assigned_officer_wins_a_tie(): void {
		$a = $this->make_officer( array( $this->h['region'] ) );
		$b = $this->make_officer( array( $this->h['region'] ) );
		Plugin::instance()->profiles()->touch_last_assigned( $a, '2026-01-01 00:00:00' );
		$this->assertSame( $b, $this->engine()->auto_assign( $this->make_registration( $this->h ) ) );
	}

	public function test_tie_break_orders_assignments_within_the_same_second(): void {
		// R-04: the higher-ID officer was assigned 0.8 s earlier in the same second,
		// so they have waited longer and must win; second precision would fall to the lowest ID.
		$low  = $this->make_officer( array( $this->h['region'] ) );
		$high = $this->make_officer( array( $this->h['region'] ) );
		$this->freeze_at( '2026-09-24 10:00:00.100000' );
		$this->engine()->auto_assign( $first = $this->make_registration( $this->h ) );
		$this->assertSame( $low, (int) $this->reg()->get( $first )->assigned_officer_id );
		$this->act_as_admin();
		$this->engine()->reassign( $first, $high, 'Balance' ); // $high: last assigned at .100
		$this->freeze_at( '2026-09-24 10:00:00.900000' );
		$this->assertSame( $low, $this->engine()->auto_assign( $this->make_registration( $this->h ) ) ); // $low: last assigned at .900

		$id = $this->make_registration( $this->h );
		$this->assertSame( $high, $this->engine()->auto_assign( $id ), 'Both have 1 open; $high has waited 0.8 s longer' );
		$this->assertSame( AssignmentService::REASON_TIE_BREAK, $this->audits( $id, AuditAction::ASSIGNED )[0]->reason );
	}

	public function test_equal_workload_and_history_is_deterministic_by_user_id(): void {
		$a = $this->make_officer( array( $this->h['region'] ) );
		$b = $this->make_officer( array( $this->h['region'] ) );
		$id = $this->make_registration( $this->h );
		$this->assertSame( min( $a, $b ), $this->engine()->auto_assign( $id ) );
		$this->assertSame( AssignmentService::REASON_TIE_BREAK_ID, $this->audits( $id, AuditAction::ASSIGNED )[0]->reason );
	}

	public function test_fair_distribution_across_officers(): void {
		$this->freeze_at( '2026-10-05 09:00:00' );
		$a      = $this->make_officer( array( $this->h['region'] ) );
		$b      = $this->make_officer( array( $this->h['region'] ) );
		$counts = array( $a => 0, $b => 0 );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->freeze_at( '2026-10-05 09:0' . $i . ':00' );
			++$counts[ $this->engine()->auto_assign( $this->make_registration( $this->h ) ) ];
		}
		$this->assertSame( array( $a => 3, $b => 3 ), $counts );
	}

	public function test_disabled_officer_receives_nothing_but_keeps_history(): void {
		$disabled = $this->make_officer( array( $this->h['region'] ) );
		$old      = $this->make_registration( $this->h );
		$this->assertSame( $disabled, $this->engine()->auto_assign( $old ) );
		Plugin::instance()->profiles()->set_status( $disabled, UserProfileRepository::STATUS_DISABLED, 1 );
		$active = $this->make_officer( array( $this->h['region'] ) );

		$this->assertSame( $active, $this->engine()->auto_assign( $this->make_registration( $this->h ) ) );
		$this->assertSame( (string) $disabled, (string) $this->reg()->get( $old )->assigned_officer_id );
	}

	public function test_no_officer_keeps_pending_opens_one_exception_and_alerts_admins(): void {
		update_option( \DMS\Support\Settings::OPTION, array( 'admin_notification_emails' => array( 'ops@example.org' ) ) );
		$id = $this->make_registration( $this->h );

		$this->assertNull( $this->engine()->auto_assign( $id ) );
		$this->assertNull( $this->engine()->auto_assign( $id ), 'Retry does not duplicate the exception' );

		$this->assertSame( 'PENDING', $this->reg()->get( $id )->status );
		$exception = Plugin::instance()->assignment_exceptions()->open_for( $id );
		$this->assertSame( AssignmentExceptionRepository::REASON_NO_ACTIVE_OFFICER, $exception->reason_code );
		$this->assertCount( 1, $this->audits( $id, AuditAction::ASSIGNMENT_EXCEPTION ) );

		global $wpdb;
		$alerts = $wpdb->get_col( $wpdb->prepare( 'SELECT recipient_email FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d AND event = %s', $id, NotificationService::EVENT_ASSIGNMENT_EXCEPTION ) );
		$this->assertSame( array( 'ops@example.org' ), $alerts );
	}

	public function test_waiting_registration_is_assigned_once_an_officer_is_configured(): void {
		$id = $this->make_registration( $this->h );
		$this->engine()->auto_assign( $id );
		$this->assertNotNull( Plugin::instance()->assignment_exceptions()->open_for( $id ) );

		$this->act_as_admin();
		$officer_role = (int) Plugin::instance()->roles()->find_by_slug( 'verification-officer' )->id;
		$officer      = Plugin::instance()->user_service()->create(
			array(
				'first_name' => 'New',
				'last_name'  => 'Officer',
				'email'      => 'new.officer@example.org',
				'username'   => 'newofficer',
				'password'   => 'correct-horse-battery',
				'role_ids'   => array( $officer_role ),
				'region_ids' => array( $this->h['region'] ),
			)
		);

		$this->assertSame( (string) $officer, (string) $this->reg()->get( $id )->assigned_officer_id );
		$this->assertNull( Plugin::instance()->assignment_exceptions()->open_for( $id ), 'Exception resolved' );
	}

	public function test_submission_is_assigned_automatically(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$result = Plugin::instance()->submissions()->submit(
			array(
				'first_name'         => 'Yaw',
				'last_name'          => 'Asante',
				'date_of_birth'      => '1985-02-02',
				'gender'             => 'Male',
				'phone'              => '0209998887',
				'email'              => 'yaw@example.org',
				'organization'       => 'Org',
				'region_id'          => $this->h['region'],
				'constituency_id'    => $this->h['constituency'],
				'polling_station_id' => $this->h['station'],
				'consent'            => true,
			)
		);
		unset( $_SERVER['REMOTE_ADDR'] );
		$row = $this->reg()->get( $result->registration_id );
		$this->assertSame( 'ASSIGNED', $row->status );
		$this->assertSame( (string) $officer, (string) $row->assigned_officer_id );
		$this->assertSame( AssignmentService::METHOD_AUTOMATIC, $row->assignment_method );
	}

	public function test_assigned_officer_is_notified(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->make_registration( $this->h );
		$this->engine()->auto_assign( $id );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d AND event = %s', $id, NotificationService::EVENT_OFFICER_ASSIGNED ) );
		$this->assertSame( get_user_by( 'id', $officer )->user_email, $row->recipient_email );
		$this->assertSame( (string) $officer, (string) $row->recipient_user_id );
		$this->assertSame( NotificationService::STATUS_SENT, $row->status );
	}

	public function test_manual_assign_requires_permission_and_eligible_officer(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$other   = $this->make_officer( array( $this->make_hierarchy()['region'] ) );
		$id      = $this->make_registration( $this->h );

		$this->act_as( array( 'assignment.view' ) );
		try {
			$this->engine()->assign( $id, $officer );
			$this->fail( 'Permission required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}

		$actor = $this->act_as( array( 'assignment.assign' ) );
		try {
			$this->engine()->assign( $id, $other );
			$this->fail( 'Officer outside the region must be rejected' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'officer_id', $e->errors );
		}

		$this->engine()->assign( $id, $officer, 'Urgent' );
		$row = $this->reg()->get( $id );
		$this->assertSame( AssignmentService::METHOD_MANUAL, $row->assignment_method );
		$this->assertSame( (string) $actor, (string) $row->assigned_by );
		$this->assertSame( 'Urgent', $this->audits( $id, AuditAction::ASSIGNED )[0]->reason );
	}

	public function test_reassign_from_under_review_returns_to_assigned(): void {
		$first  = $this->make_officer( array( $this->h['region'] ) );
		$second = $this->make_officer( array( $this->h['region'] ) );
		$id     = $this->make_registration( $this->h );
		$this->engine()->assign_pending( array( $this->h['region'] ) );
		$this->assertSame( (string) min( $first, $second ), (string) $this->reg()->get( $id )->assigned_officer_id );
		$this->reg()->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW, array( 'reviewed_at' => '2026-10-01 10:00:00' ) );
		$current = (int) $this->reg()->get( $id )->assigned_officer_id;
		$target  = $current === $first ? $second : $first;

		$this->act_as( array( 'assignment.reassign' ) );
		$this->engine()->reassign( $id, $target, 'Officer on leave' );

		$row = $this->reg()->get( $id );
		$this->assertSame( 'ASSIGNED', $row->status );
		$this->assertSame( (string) $target, (string) $row->assigned_officer_id );
		$this->assertNull( $row->reviewed_at );
		$audit = $this->audits( $id, AuditAction::REASSIGNED )[0];
		$this->assertSame( 'UNDER_REVIEW', $audit->old_status );
		$this->assertSame( $current, json_decode( $audit->metadata, true )['previous_officer_id'] );
	}

	public function test_reassign_rejects_same_officer_and_invalid_states(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->make_registration( $this->h );
		$this->act_as( array( 'assignment.reassign' ) );

		try {
			$this->engine()->reassign( $id, $officer );
			$this->fail( 'PENDING cannot be reassigned' );
		} catch ( InvalidTransitionException $e ) {
			unset( $e );
		}
		$this->engine()->auto_assign( $id );
		$this->expectException( ValidationException::class );
		$this->engine()->reassign( $id, $officer );
	}
}
