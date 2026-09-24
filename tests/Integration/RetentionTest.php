<?php
/**
 * REQ-RET — 30-day Bin retention (ARCH §71.4, §78.26–§78.29; Decisions §21; MP §29, §39 "Retention").
 */

namespace DMS\Tests\Integration;

use DMS\Audit\ActorType;
use DMS\Audit\AuditAction;
use DMS\Cron\Scheduler;
use DMS\Database\Tables;
use DMS\Plugin;
use DMS\Registrations\RegistrationFields;
use DMS\Workflow\Status;

final class RetentionTest extends \WP_UnitTestCase {

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

	private function now( string $time ): void {
		Plugin::instance()->clock()->freeze( new \DateTimeImmutable( $time, new \DateTimeZone( 'UTC' ) ) );
	}

	private function disapproved_at( string $when, array $overrides = array() ): int {
		$repo = Plugin::instance()->registrations();
		$id   = $this->make_registration( $this->h, $overrides );
		$repo->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 1 ) );
		$repo->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW );
		$repo->transition( $id, Status::UNDER_REVIEW, Status::DISAPPROVED, array( 'disapproved_at' => $when, 'disapproval_reason' => 'Invalid details' ) );
		return $id;
	}

	private function status( int $id ): string {
		return Plugin::instance()->registrations()->get( $id )->status;
	}

	public function test_record_is_kept_before_30_days(): void {
		$id = $this->disapproved_at( '2026-09-01 12:00:00' );
		$this->now( '2026-10-01 11:59:59' ); // 29 days, 23:59:59
		$this->assertSame( 0, Plugin::instance()->retention()->run()['purged'] );
		$this->assertSame( 'DISAPPROVED', $this->status( $id ) );
	}

	public function test_record_is_purged_at_30_days(): void {
		$id = $this->disapproved_at( '2026-09-01 12:00:00' );
		$this->now( '2026-10-01 12:00:00' );
		$this->assertSame( 1, Plugin::instance()->retention()->run()['purged'] );
		$this->assertSame( 'DELETED', $this->status( $id ) );
	}

	public function test_purge_is_a_tombstone_with_personal_data_removed_and_audited_as_system(): void {
		$id     = $this->disapproved_at( '2026-08-01 00:00:00', array( 'last_name' => 'Retention-Person' ) );
		$number = Plugin::instance()->registrations()->get( $id )->registration_number;
		Plugin::instance()->audit()->record( AuditAction::EDITED, array( 'registration_id' => $id, 'metadata' => array( 'changes' => array( 'last_name' => array( 'old' => 'Retention-Person', 'new' => 'X' ) ) ) ) );
		$before = count( Plugin::instance()->audit()->for_registration( $id ) );

		$this->now( '2026-10-01 00:00:00' );
		Plugin::instance()->retention()->run();

		$row = Plugin::instance()->registrations()->get( $id );
		$this->assertSame( $number, $row->registration_number );
		foreach ( RegistrationFields::PII as $column ) {
			$this->assertNull( $row->{$column}, $column );
		}
		$entries = Plugin::instance()->audit()->for_registration( $id );
		$this->assertSame( $before + 1, count( $entries ), 'Audit history retained, one purge entry added' );
		$last = end( $entries );
		$this->assertSame( AuditAction::RETENTION_PURGED, $last->action );
		$this->assertSame( ActorType::SYSTEM, $last->actor_type );
		$this->assertStringNotContainsString( 'Retention-Person', implode( ' ', array_map( static fn( $e ) => (string) $e->metadata, $entries ) ) );
	}

	public function test_running_twice_is_safe(): void {
		$this->disapproved_at( '2026-08-01 00:00:00' );
		$this->now( '2026-10-01 00:00:00' );
		$this->assertSame( 1, Plugin::instance()->retention()->run()['purged'] );
		$this->assertSame( 0, Plugin::instance()->retention()->run()['purged'] );
	}

	public function test_non_bin_records_are_never_purged(): void {
		$pending  = $this->make_registration( $this->h );
		$approved = $this->make_registration( $this->h );
		$repo     = Plugin::instance()->registrations();
		$repo->transition( $approved, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 1 ) );
		$repo->transition( $approved, Status::ASSIGNED, Status::UNDER_REVIEW );
		$repo->transition( $approved, Status::UNDER_REVIEW, Status::APPROVED, array( 'approved_at' => '2025-01-01 00:00:00' ) );

		$this->now( '2030-01-01 00:00:00' );
		Plugin::instance()->retention()->run();
		$this->assertSame( 'PENDING', $this->status( $pending ) );
		$this->assertSame( 'APPROVED', $this->status( $approved ) );
	}

	public function test_overlapping_run_is_skipped(): void {
		global $wpdb;
		$this->disapproved_at( '2026-08-01 00:00:00' );
		$this->now( '2026-10-01 00:00:00' );

		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', 'dms_retention_' . $wpdb->prefix ) );
		try {
			$result = Plugin::instance()->retention()->run();
			$this->assertTrue( $result['skipped_locked'] );
			$this->assertSame( 0, $result['purged'] );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', 'dms_retention_' . $wpdb->prefix ) );
			$other->close();
		}
	}

	public function test_housekeeping_deletes_old_otp_requests(): void {
		global $wpdb;
		$table = Tables::name( Tables::OTP_REQUESTS );
		foreach ( array( 'old' => '2026-07-01 00:00:00', 'new' => '2026-09-30 00:00:00' ) as $key => $when ) {
			$wpdb->insert( $table, array( 'request_id' => str_pad( $key, 32, '0' ), 'phone_normalized' => '+233240000000', 'status' => 'CONSUMED', 'expires_at' => $when, 'created_at' => $when, 'updated_at' => $when ) );
		}
		$this->now( '2026-10-01 00:00:00' );
		Plugin::instance()->retention()->run();
		$this->assertSame( array( str_pad( 'new', 32, '0' ) ), $wpdb->get_col( "SELECT request_id FROM {$table}" ) );
	}

	public function test_jobs_are_scheduled_and_unscheduled(): void {
		Scheduler::unschedule();
		Plugin::instance()->scheduler()->ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( Scheduler::DAILY ) );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOURLY ) );
		Scheduler::unschedule();
		$this->assertFalse( wp_next_scheduled( Scheduler::DAILY ) );
	}

	public function test_daily_cron_hook_runs_retention(): void {
		$id = $this->disapproved_at( '2026-08-01 00:00:00' );
		$this->now( '2026-10-01 00:00:00' );
		do_action( Scheduler::DAILY );
		$this->assertSame( 'DELETED', $this->status( $id ) );
	}
}
