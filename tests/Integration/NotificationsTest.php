<?php
/**
 * Notifications (ARCH §76, §78.20–§78.25; MP §28, §37; Decisions §26):
 * exactly-once delivery, retry with backoff, monitoring, manual retry, templates,
 * cancellation on permanent deletion.
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Cron\Scheduler;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Notifications\NotificationService as N;
use DMS\Plugin;
use DMS\Workflow\Status;

final class NotificationsTest extends \WP_UnitTestCase {

	use Fixtures;

	/** @var array{region:int,constituency:int,station:int} */
	private array $h;

	private \DateTimeImmutable $t0;

	public function set_up(): void {
		parent::set_up();
		delete_option( \DMS\Support\Settings::OPTION );
		delete_option( \DMS\Notifications\EmailTemplates::OPTION );
		update_option( \DMS\Support\Settings::OPTION, array( 'admin_notification_emails' => array( 'ops@example.org' ) ) );
		reset_phpmailer_instance();
		$this->h  = $this->make_hierarchy();
		$this->t0 = new \DateTimeImmutable( '2026-10-10 08:00:00', new \DateTimeZone( 'UTC' ) );
		$this->clock_at( 0 );
	}

	public function tear_down(): void {
		Plugin::instance()->clock()->freeze( null );
		remove_all_filters( 'pre_wp_mail' );
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function clock_at( int $seconds ): void {
		Plugin::instance()->clock()->freeze( $this->t0->modify( "+{$seconds} seconds" ) );
	}

	private function rows( int $registration_id, ?string $event = null ): array {
		global $wpdb;
		$sql = 'SELECT * FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d' . ( $event ? ' AND event = %s' : '' ) . ' ORDER BY id';
		return $wpdb->get_results( $event ? $wpdb->prepare( $sql, $registration_id, $event ) : $wpdb->prepare( $sql, $registration_id ) );
	}

	private function submit(): int {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		return Plugin::instance()->submissions()->submit(
			array(
				'first_name'         => 'Akua',
				'last_name'          => 'Mensah',
				'date_of_birth'      => '1991-03-03',
				'gender'             => 'Female',
				'phone'              => '024' . wp_rand( 1000000, 9999999 ),
				'email'              => 'akua@example.org',
				'organization'       => 'Org',
				'region_id'          => $this->h['region'],
				'constituency_id'    => $this->h['constituency'],
				'polling_station_id' => $this->h['station'],
				'consent'            => true,
			)
		)->registration_id;
	}

	private function mail_fails(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
	}

	private function mail_works(): void {
		remove_all_filters( 'pre_wp_mail' );
	}

	// ------------------------------------------------------------ exactly once

	public function test_each_workflow_event_sends_exactly_one_email_per_recipient(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->submit();

		$this->assertCount( 1, $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED ), 'Applicant confirmation (§78.20)' );
		$this->assertCount( 1, $this->rows( $id, N::EVENT_ADMIN_NEW_REGISTRATION ), 'Admin notice (§78.21)' );
		$this->assertCount( 1, $this->rows( $id, N::EVENT_OFFICER_ASSIGNED ), 'Officer notice (§78.22)' );

		wp_set_current_user( $officer );
		Plugin::instance()->workflow()->start_review( $id );
		Plugin::instance()->workflow()->approve( $id );
		$this->assertCount( 1, $this->rows( $id, N::EVENT_REGISTRATION_APPROVED ), 'Approval email (§78.23)' );

		// Re-queuing the same event never duplicates (dedupe key).
		Plugin::instance()->notifications()->queue_registration_approved( Plugin::instance()->registrations()->get( $id ) );
		$this->assertCount( 1, $this->rows( $id, N::EVENT_REGISTRATION_APPROVED ) );
		foreach ( $this->rows( $id ) as $row ) {
			$this->assertSame( N::STATUS_SENT, $row->status, $row->event );
		}
	}

	public function test_disapproval_sends_nothing_to_the_applicant(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->submit();
		$before  = count( $this->rows( $id ) );
		wp_set_current_user( $officer );
		Plugin::instance()->workflow()->start_review( $id );
		Plugin::instance()->workflow()->disapprove( $id, 'Details could not be confirmed' );
		$this->assertCount( $before, $this->rows( $id ), '§78.24: no email of any kind on disapproval' );
	}

	public function test_reassignment_notifies_the_new_officer_once(): void {
		$first  = $this->make_officer( array( $this->h['region'] ) );
		$second = $this->make_officer( array( $this->h['region'] ) );
		$id     = $this->submit();
		$assigned = (int) Plugin::instance()->registrations()->get( $id )->assigned_officer_id;
		$target   = $assigned === $first ? $second : $first;
		$this->clock_at( 60 );
		$this->act_as( array( 'assignment.reassign' ) );
		Plugin::instance()->assignments()->reassign( $id, $target );
		$to = array_map( static fn( $r ) => (int) $r->recipient_user_id, $this->rows( $id, N::EVENT_OFFICER_ASSIGNED ) );
		$this->assertSame( array( $assigned, $target ), $to );
	}

	// ---------------------------------------------------------------- retry

	public function test_failure_never_undoes_the_registration_and_is_retried_with_backoff(): void {
		$this->mail_fails();
		$id  = $this->submit();
		$row = $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0];
		$this->assertSame( 'PENDING', Plugin::instance()->registrations()->get( $id )->status, '§78.25' );
		$this->assertSame( N::STATUS_FAILED, $row->status );
		$this->assertSame( '1', (string) $row->attempts );
		$this->assertSame( $this->t0->modify( '+5 minutes' )->format( 'Y-m-d H:i:s' ), $row->next_attempt_at, 'First retry after 5 minutes' );

		$this->clock_at( 4 * 60 );
		Plugin::instance()->notifications()->process_due();
		$this->assertSame( '1', (string) $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0]->attempts, 'Not due yet' );

		$this->clock_at( 5 * 60 );
		Plugin::instance()->notifications()->process_due();
		$row = $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0];
		$this->assertSame( '2', (string) $row->attempts );
		$this->assertSame( $this->t0->modify( '+15 minutes' )->format( 'Y-m-d H:i:s' ), $row->next_attempt_at, 'Then 10 minutes' );

		$this->mail_works();
		$this->clock_at( 15 * 60 );
		Plugin::instance()->notifications()->process_due();
		$row = $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0];
		$this->assertSame( N::STATUS_SENT, $row->status );
		$this->assertSame( 1, count( $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED ) ), 'Retry never duplicates' );
	}

	public function test_automatic_retries_stop_at_max_attempts(): void {
		update_option( \DMS\Support\Settings::OPTION, array( 'notification_max_attempts' => 2, 'admin_notification_emails' => array() ) );
		$this->mail_fails();
		$id = $this->submit();
		$this->clock_at( 3600 );
		Plugin::instance()->notifications()->process_due();
		$row = $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0];
		$this->assertSame( '2', (string) $row->attempts );
		$this->assertNull( $row->next_attempt_at, 'Given up after max attempts' );
		$this->clock_at( 86400 );
		Plugin::instance()->notifications()->process_due();
		$this->assertSame( '2', (string) $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0]->attempts );
	}

	public function test_email_left_queued_by_an_interrupted_request_is_picked_up(): void {
		$id = $this->make_registration( $this->h );
		Plugin::instance()->notifications()->queue_registration_received( Plugin::instance()->registrations()->get( $id ) ); // Queued, never dispatched.
		Plugin::instance()->notifications()->process_due();
		$this->assertSame( N::STATUS_QUEUED, $this->rows( $id )[0]->status, 'Not stuck yet' );
		$this->clock_at( N::STUCK_AFTER_SECONDS + 1 );
		Plugin::instance()->notifications()->process_due();
		$this->assertSame( N::STATUS_SENT, $this->rows( $id )[0]->status );
	}

	public function test_retry_job_is_scheduled_every_fifteen_minutes(): void {
		Scheduler::unschedule();
		Plugin::instance()->scheduler()->ensure_scheduled();
		$event = wp_get_scheduled_event( Scheduler::RETRY );
		$this->assertNotFalse( $event );
		$this->assertSame( 900, $event->interval );
	}

	// -------------------------------------------------------- manual retry

	public function test_manual_retry_needs_permission_and_is_audited(): void {
		$this->mail_fails();
		$id  = $this->submit();
		$nid = (int) $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0]->id;
		$this->mail_works();

		$this->act_as( array( 'notifications.view' ) );
		try {
			Plugin::instance()->notifications()->retry( $nid );
			$this->fail( 'notifications.retry required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}

		$this->act_as( array( 'notifications.view', 'notifications.retry' ) );
		$this->assertTrue( Plugin::instance()->notifications()->retry( $nid ) );
		$this->assertSame( N::STATUS_SENT, Plugin::instance()->notifications()->find( $nid )->status );
		$this->assertContains( AuditAction::NOTIFICATION_RETRIED, array_map( static fn( $e ) => $e->action, Plugin::instance()->audit()->for_registration( $id ) ) );

		$this->expectException( ConflictException::class );
		Plugin::instance()->notifications()->retry( $nid ); // Already sent.
	}

	// ------------------------------------------------- deletion cancels mail

	public function test_permanent_deletion_cancels_unsent_email_and_removes_personal_data(): void {
		$officer = $this->make_officer( array( $this->h['region'] ) );
		$id      = $this->submit();
		wp_set_current_user( $officer );
		Plugin::instance()->workflow()->start_review( $id );
		$this->mail_fails();
		Plugin::instance()->notifications()->queue(
			N::EVENT_REGISTRATION_APPROVED,
			$id,
			'akua@example.org',
			null,
			'x',
			'Dear Akua',
			'manual'
		);
		$all = $this->rows( $id );
		Plugin::instance()->notifications()->dispatch( array( (int) end( $all )->id ) );
		Plugin::instance()->workflow()->disapprove( $id, 'Details could not be confirmed' );
		Plugin::instance()->workflow()->purge( $id, null, AuditAction::RETENTION_PURGED );

		foreach ( $this->rows( $id ) as $row ) {
			$this->assertNull( $row->recipient_email );
			$this->assertNull( $row->body );
			$this->assertNotSame( N::STATUS_FAILED, $row->status, 'Nothing left to retry' );
		}
		$this->mail_works();
		$this->clock_at( 86400 );
		$this->assertSame( array( 'sent' => 0, 'failed' => 0 ), Plugin::instance()->notifications()->process_due() );
	}

	// ------------------------------------------------------------ templates

	public function test_custom_template_is_used_with_placeholders(): void {
		$this->act_as_admin();
		Plugin::instance()->email_templates()->save(
			array(
				'REGISTRATION_RECEIVED' => array( 'subject' => 'Akwaaba {first_name}', 'body' => "Your number: {registration_number}\nSite: {site_name}" ),
			)
		);
		$id  = $this->submit();
		$row = $this->rows( $id, N::EVENT_REGISTRATION_RECEIVED )[0];
		$this->assertSame( 'Akwaaba Akua', $row->subject );
		$this->assertStringContainsString( 'Your number: REG-', $row->body );
	}

	public function test_template_validation_rejects_unknown_placeholders_and_half_filled(): void {
		$this->act_as_admin();
		try {
			Plugin::instance()->email_templates()->save(
				array(
					'REGISTRATION_APPROVED'  => array( 'subject' => 'Hi {password}', 'body' => 'x' ),
					'ADMIN_NEW_REGISTRATION' => array( 'subject' => 'Only a subject', 'body' => '' ),
					'NOT_AN_EVENT'           => array( 'subject' => 'x', 'body' => 'y' ),
				)
			);
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'REGISTRATION_APPROVED.body', $e->errors );
			$this->assertArrayHasKey( 'ADMIN_NEW_REGISTRATION.subject', $e->errors );
			$this->assertArrayHasKey( 'NOT_AN_EVENT', $e->errors );
		}
	}

	public function test_subject_can_never_contain_line_breaks(): void {
		$id  = $this->make_registration( $this->h, array( 'first_name' => "Evil\r\nBcc: victim@example.org" ) );
		$out = Plugin::instance()->email_templates();
		update_option( \DMS\Notifications\EmailTemplates::OPTION, array( 'REGISTRATION_RECEIVED' => array( 'subject' => 'Hi {first_name}', 'body' => 'b' ) ) );
		$message = $out->render( 'REGISTRATION_RECEIVED', array( 'first_name' => (string) Plugin::instance()->registrations()->get( $id )->first_name ) );
		$this->assertStringNotContainsString( "\n", $message['subject'] );
		$this->assertStringNotContainsString( "\r", $message['subject'] );
	}

	public function test_templates_require_settings_edit_and_are_audited(): void {
		$this->act_as( array( 'settings.view' ) );
		try {
			Plugin::instance()->email_templates()->save( array() );
			$this->fail( 'settings.edit required' );
		} catch ( AuthorizationException $e ) {
			unset( $e );
		}
		$this->act_as_admin();
		Plugin::instance()->email_templates()->save( array( 'REGISTRATION_APPROVED' => array( 'subject' => 'Approved!', 'body' => 'Well done {first_name}.' ) ) );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( "SELECT COUNT(*) FROM " . Tables::name( Tables::AUDIT_LOG ) . " WHERE object_type = 'email_templates'" ) );
	}

	// ------------------------------------------------------------ monitoring

	public function test_monitoring_lists_without_bodies_and_counts_by_status(): void {
		$this->mail_fails();
		$this->submit();
		$service = Plugin::instance()->notifications();
		$this->assertGreaterThan( 0, $service->stats()[ N::STATUS_FAILED ] );
		$failed = $service->search( array( 'status' => 'failed' ) );
		$this->assertGreaterThan( 0, $failed['total'] );
		$this->assertObjectNotHasProperty( 'body', $failed['items'][0], 'Message bodies (personal data) are not listed' );
		$this->assertNotEmpty( $failed['items'][0]->registration_number );
	}

	public function test_monitoring_page_access_and_retry_button(): void {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		$this->mail_fails();
		$this->submit();
		$render = static function (): string {
			ob_start();
			try {
				Plugin::instance()->page_notifications()->render();
			} finally {
				$html = (string) ob_get_clean();
			}
			return $html;
		};

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		try {
			$render();
			$this->fail( 'Must refuse' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		}

		$this->act_as( array( 'notifications.view' ) );
		$_GET = array( 'page' => 'dms-notifications' );
		$html = $render();
		$this->assertStringContainsString( 'Delivery log', $html );
		$this->assertStringNotContainsString( 'Retry now', $html, 'No retry without notifications.retry' );

		$this->act_as( array( 'notifications.view', 'notifications.retry' ) );
		$this->assertStringContainsString( 'Retry now', $render() );
		$_GET = array();
	}
}
