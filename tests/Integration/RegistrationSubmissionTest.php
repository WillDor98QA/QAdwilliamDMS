<?php
/**
 * CR-01 — one registration workflow with OTP as a configurable step.
 *
 * Covers the three groups required by the CR-01 test specification:
 *   OTP OFF, OTP ON, and configuration transitions.
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\ConflictException;
use DMS\Errors\RateLimitedException;
use DMS\Errors\UnavailableException;
use DMS\Errors\ValidationException;
use DMS\Notifications\NotificationService;
use DMS\Otp\OtpService;
use DMS\Plugin;
use DMS\Registrations\PhoneNormalizer;
use DMS\Registrations\SubmissionResult;
use DMS\Security\Honeypot;

final class RegistrationSubmissionTest extends \WP_UnitTestCase {

	use PublicFlowFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_public_flow();
	}

	public function tear_down(): void {
		$this->tear_down_public_flow();
		parent::tear_down();
	}

	private function submit( array $payload ): SubmissionResult {
		return Plugin::instance()->submissions()->submit( $payload );
	}

	private function audit_actions( int $registration_id ): array {
		return array_map( static fn( $e ) => $e->action, Plugin::instance()->audit()->for_registration( $registration_id ) );
	}

	// ================================================================ OTP OFF

	public function test_off_is_the_default(): void {
		$this->assertFalse( Plugin::instance()->submissions()->otp_enabled() );
	}

	public function test_off_submits_without_otp_and_creates_unverified_registration(): void {
		$result = $this->submit( $this->payload() );

		$this->assertSame( SubmissionResult::STATUS_CREATED, $result->status );
		$this->assertMatchesRegularExpression( '/^REG-\d{4}-\d{6}$/', $result->registration_number );
		$row = Plugin::instance()->registrations()->get( $result->registration_id );
		$this->assertSame( 'PENDING', $row->status );
		$this->assertSame( '0', (string) $row->phone_verified );
		$this->assertNull( $row->phone_verified_at );
		$this->assertSame( '1', (string) $row->consent_given );
		$this->assertNotNull( $row->consent_given_at );
	}

	public function test_off_never_calls_the_sms_gateway(): void {
		$this->configure( array( 'sms_gateway' => \DMS\Tests\Support\FakeSmsGateway::ID ) );
		$this->submit( $this->payload() );
		$this->assertSame( array(), $this->sms->sent );
	}

	public function test_off_does_not_need_sms_configuration(): void {
		$this->configure( array( 'sms_gateway' => '' ) );
		$this->sms->configured = false;
		$this->assertFalse( Plugin::instance()->sms()->is_ready() );
		$this->assertSame( SubmissionResult::STATUS_CREATED, $this->submit( $this->payload() )->status );
	}

	public function test_off_ignores_stray_otp_fields(): void {
		$result = $this->submit( $this->payload( array( 'otp_request_id' => str_repeat( 'a', 32 ), 'otp_code' => '123456' ) ) );
		$this->assertSame( SubmissionResult::STATUS_CREATED, $result->status );
	}

	public function test_off_still_requires_consent(): void {
		try {
			$this->submit( $this->payload( array( 'consent' => false ) ) );
			$this->fail( 'Consent must be required' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'consent', $e->errors );
		}
		$this->assertSame( 0, $this->registration_count() );
	}

	public function test_off_still_validates_fields(): void {
		try {
			$this->submit( $this->payload( array( 'first_name' => '', 'phone' => '12345', 'email' => 'nope', 'date_of_birth' => '2999-01-01', 'gender' => 'Other' ) ) );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertEqualsCanonicalizing( array( 'first_name', 'phone', 'email', 'date_of_birth', 'gender' ), array_keys( $e->errors ) );
		}
	}

	public function test_off_rejects_mismatched_electoral_chain(): void {
		$other = $this->make_hierarchy();
		$this->expectException( ValidationException::class );
		$this->submit( $this->payload( array( 'constituency_id' => $other['constituency'], 'polling_station_id' => $other['station'] ) ) );
	}

	public function test_off_duplicate_phone_in_any_format_is_blocked_with_generic_message(): void {
		$this->submit( $this->payload( array( 'phone' => '0244000001' ) ) );
		try {
			$this->submit( $this->payload( array( 'phone' => '+233 24 400 0001' ) ) );
			$this->fail( 'Duplicate must be blocked' );
		} catch ( ConflictException $e ) {
			$this->assertSame( 'dms_duplicate_phone', $e->error_code() );
			$this->assertStringContainsString( 'already been used', $e->getMessage() );
			$this->assertStringNotContainsString( 'REG-', $e->getMessage() );
		}
		$this->assertSame( 1, $this->registration_count() );
	}

	public function test_off_captcha_is_enforced_when_enabled(): void {
		$this->stub_turnstile();
		Plugin::instance()->secrets()->set( 'turnstile_site_key', 'site' );
		Plugin::instance()->secrets()->set( 'turnstile_secret_key', 'secret' );
		$this->configure( array( 'turnstile_enabled' => true ) );

		try {
			$this->submit( $this->payload( array( 'captcha_token' => 'bad' ) ) );
			$this->fail( 'Captcha must be enforced' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'captcha', $e->errors );
		}
		$this->assertSame( SubmissionResult::STATUS_CREATED, $this->submit( $this->payload( array( 'captcha_token' => 'good' ) ) )->status );
	}

	public function test_off_honeypot_rejects_bots(): void {
		$this->expectException( ValidationException::class );
		$this->submit( $this->payload( array( Honeypot::FIELD => 'http://spam.example' ) ) );
	}

	public function test_off_submission_rate_limit_per_ip(): void {
		$this->configure( array( 'submission_max_per_ip_hour' => 2 ) );
		$this->submit( $this->payload() );
		$this->submit( $this->payload() );
		$this->expectException( RateLimitedException::class );
		$this->submit( $this->payload() );
	}

	public function test_off_submission_is_audited_and_notifications_are_sent(): void {
		reset_phpmailer_instance();
		$this->configure( array( 'admin_notification_emails' => array( 'ops@example.org' ) ) );
		$result = $this->submit( $this->payload( array( 'email' => 'applicant@example.org' ) ) );

		$actions = $this->audit_actions( $result->registration_id );
		$this->assertContains( AuditAction::SUBMITTED, $actions );
		$this->assertContains( AuditAction::CONSENT_RECORDED, $actions );
		$this->assertNotContains( AuditAction::OTP_VERIFIED, $actions );

		global $wpdb;
		$sent = $wpdb->get_col( $wpdb->prepare( 'SELECT recipient_email FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d AND status = %s AND event IN (%s, %s)', $result->registration_id, NotificationService::STATUS_SENT, NotificationService::EVENT_REGISTRATION_RECEIVED, NotificationService::EVENT_ADMIN_NEW_REGISTRATION ) );
		$this->assertEqualsCanonicalizing( array( 'applicant@example.org', 'ops@example.org' ), $sent );
	}

	public function test_off_notification_failure_does_not_undo_registration(): void {
		add_filter( 'pre_wp_mail', '__return_false' );
		$result = $this->submit( $this->payload() );
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertSame( 'PENDING', Plugin::instance()->registrations()->get( $result->registration_id )->status );
		global $wpdb;
		$failed = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::NOTIFICATIONS ) . ' WHERE registration_id = %d AND event = %s', $result->registration_id, NotificationService::EVENT_REGISTRATION_RECEIVED ) );
		$this->assertSame( NotificationService::STATUS_FAILED, $failed->status );
		$this->assertNotNull( $failed->next_attempt_at, 'Scheduled for retry' );
		$this->assertContains( AuditAction::NOTIFICATION_FAILED, $this->audit_actions( $result->registration_id ) );
	}

	public function test_submitted_hook_fires_after_commit(): void {
		$seen = null;
		add_action( 'dms_registration_submitted', static function ( int $id ) use ( &$seen ): void {
			$seen = $id;
		} );
		$result = $this->submit( $this->payload() );
		$this->assertSame( $result->registration_id, $seen );
	}

	// ================================================================= OTP ON

	public function test_on_first_step_requires_otp_and_creates_nothing(): void {
		$this->otp_on();
		$result = $this->submit( $this->payload() );

		$this->assertSame( SubmissionResult::STATUS_OTP_REQUIRED, $result->status );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $result->otp_request_id );
		$this->assertSame( 300, $result->expires_in );
		$this->assertSame( 60, $result->resend_after );
		$this->assertSame( 0, $this->registration_count(), 'Nothing is created before verification' );
	}

	public function test_on_calls_the_sms_gateway_with_the_code(): void {
		$this->otp_on();
		$payload = $this->payload();
		$this->submit( $payload );

		$this->assertCount( 1, $this->sms->sent );
		$this->assertSame( PhoneNormalizer::normalize( $payload['phone'] ), $this->sms->sent[0]['to'] );
		$this->assertMatchesRegularExpression( '/^\d{6}$/', (string) $this->sms->last_code() );
	}

	public function test_on_correct_code_creates_verified_registration(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$result  = $this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $this->sms->last_code() ) );

		$this->assertSame( SubmissionResult::STATUS_CREATED, $result->status );
		$row = Plugin::instance()->registrations()->get( $result->registration_id );
		$this->assertSame( '1', (string) $row->phone_verified );
		$this->assertNotNull( $row->phone_verified_at );
		$this->assertContains( AuditAction::OTP_VERIFIED, $this->audit_actions( $result->registration_id ) );
		$this->assertSame( OtpService::STATUS_CONSUMED, Plugin::instance()->otp()->find_by_request_id( $step1->otp_request_id )->status );
	}

	public function test_on_cannot_finalize_without_code(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		try {
			$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => '' ) );
			$this->fail( 'Registration must not be created without the code' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'otp_code', $e->errors );
		}
		$this->assertSame( 0, $this->registration_count() );
	}

	public function test_on_incorrect_code_fails_and_reports_remaining_attempts(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$wrong   = '000000' === $this->sms->last_code() ? '111111' : '000000';
		try {
			$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $wrong ) );
			$this->fail( 'Wrong code must fail' );
		} catch ( ValidationException $e ) {
			$this->assertStringContainsString( '4 attempts remaining', $e->errors['otp_code'] );
		}
		$this->assertSame( 0, $this->registration_count() );
	}

	public function test_on_expired_code_fails(): void {
		$this->otp_on();
		$clock = Plugin::instance()->clock();
		$clock->freeze( new \DateTimeImmutable( '2026-10-01 10:00:00', new \DateTimeZone( 'UTC' ) ) );
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$code    = $this->sms->last_code();

		$clock->freeze( new \DateTimeImmutable( '2026-10-01 10:05:01', new \DateTimeZone( 'UTC' ) ) );
		try {
			$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $code ) );
			$this->fail( 'Expired code must fail' );
		} catch ( ValidationException $e ) {
			$this->assertStringContainsString( 'expired', $e->errors['otp_code'] );
		}
		$this->assertSame( 0, $this->registration_count() );
	}

	public function test_on_attempt_limit_locks_the_code_even_for_the_right_code(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$right   = $this->sms->last_code();
		$wrong   = '000000' === $right ? '111111' : '000000';
		for ( $i = 1; $i <= 5; $i++ ) {
			try {
				$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $wrong ) );
			} catch ( ValidationException $e ) {
				unset( $e );
			}
		}
		$this->assertSame( OtpService::STATUS_LOCKED, Plugin::instance()->otp()->find_by_request_id( $step1->otp_request_id )->status );
		$this->expectException( ValidationException::class );
		$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $right ) );
	}

	public function test_on_resend_cooldown(): void {
		$this->otp_on();
		$clock = Plugin::instance()->clock();
		$clock->freeze( new \DateTimeImmutable( '2026-10-02 09:00:00', new \DateTimeZone( 'UTC' ) ) );
		$payload = $this->payload();
		$step1   = $this->submit( $payload );

		try {
			Plugin::instance()->submissions()->resend_otp( $step1->otp_request_id, $payload['phone'] );
			$this->fail( 'Resend inside cooldown must be refused' );
		} catch ( RateLimitedException $e ) {
			$this->assertSame( 60, $e->retry_after );
		}

		$clock->freeze( new \DateTimeImmutable( '2026-10-02 09:01:00', new \DateTimeZone( 'UTC' ) ) );
		$old = $this->sms->last_code();
		Plugin::instance()->submissions()->resend_otp( $step1->otp_request_id, $payload['phone'] );
		$this->assertCount( 2, $this->sms->sent );

		// Only the newest code works.
		if ( $old !== $this->sms->last_code() ) {
			try {
				$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $old ) );
				$this->fail( 'Old code must no longer work' );
			} catch ( ValidationException $e ) {
				unset( $e );
			}
		}
		$result = $this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $this->sms->last_code() ) );
		$this->assertSame( SubmissionResult::STATUS_CREATED, $result->status );
	}

	public function test_on_code_cannot_be_reused(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$code    = $this->sms->last_code();
		$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $code ) );

		$this->expectException( ValidationException::class );
		$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $code ) );
	}

	public function test_on_code_is_bound_to_the_phone_number(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$this->expectException( ValidationException::class );
		$this->submit( array_merge( $payload, array( 'phone' => $this->next_phone(), 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $this->sms->last_code() ) ) );
	}

	public function test_on_unconfigured_sms_fails_safely_and_never_bypasses_otp(): void {
		$this->otp_on();
		$this->sms->configured = false; // e.g. the API key was removed after OTP was switched on.

		try {
			$this->submit( $this->payload() );
			$this->fail( 'Must not continue without SMS' );
		} catch ( UnavailableException $e ) {
			$this->assertSame( 503, $e->http_status() );
			$this->assertStringNotContainsString( 'API key', $e->getMessage(), 'No configuration detail for the public' );
			$this->assertStringNotContainsString( 'Fake', $e->getMessage() );
		}
		$this->assertSame( 0, $this->registration_count(), 'OTP is not bypassed' );
		$this->assertNotEmpty( Plugin::instance()->settings_service()->health(), 'Administrator sees the problem' );
	}

	public function test_on_provider_failure_fails_safely(): void {
		$this->otp_on();
		$this->sms->fail = true;
		try {
			$this->submit( $this->payload() );
			$this->fail( 'Expected UnavailableException' );
		} catch ( UnavailableException $e ) {
			$this->assertStringNotContainsString( 'HTTP 401', $e->getMessage() );
		}
		$this->assertSame( 0, $this->registration_count() );
		global $wpdb;
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE action = %s', AuditAction::OTP_SEND_FAILED ) ) );
	}

	public function test_on_enumeration_protection_duplicate_gets_identical_response_and_no_sms(): void {
		$this->otp_off();
		$this->submit( $this->payload( array( 'phone' => '0244000777' ) ) );
		$this->otp_on();

		$fresh     = $this->submit( $this->payload() );
		$duplicate = $this->submit( $this->payload( array( 'phone' => '024 400 0777' ) ) );

		$this->assertSame( array_keys( $fresh->to_public_array() ), array_keys( $duplicate->to_public_array() ) );
		$this->assertSame( $fresh->to_public_array()['message'], $duplicate->to_public_array()['message'] );
		$this->assertSame( $fresh->status, $duplicate->status );
		$this->assertCount( 1, $this->sms->sent, 'Only the fresh number received an SMS' );
		$this->assertNotSame( '+233244000777', $this->sms->sent[0]['to'] );
	}

	public function test_on_duplicate_can_never_verify(): void {
		$this->otp_off();
		$first = $this->payload( array( 'phone' => '0244000888' ) );
		$this->submit( $first );
		$this->otp_on();

		$payload = $this->payload( array( 'phone' => '0244000888' ) );
		$step1   = $this->submit( $payload );
		foreach ( array( '000000', '123456', '999999' ) as $guess ) {
			try {
				$this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $guess ) );
				$this->fail( 'A duplicate must never verify' );
			} catch ( ValidationException $e ) {
				$this->assertArrayHasKey( 'otp_code', $e->errors );
			}
		}
		$this->assertSame( 1, $this->registration_count() );
	}

	public function test_on_otp_is_never_stored_logged_or_audited_in_plaintext(): void {
		global $wpdb;
		$this->otp_on();
		$step1 = $this->submit( $this->payload() );
		$code  = $this->sms->last_code();
		$row   = Plugin::instance()->otp()->find_by_request_id( $step1->otp_request_id );

		$this->assertNotSame( $code, $row->otp_hash );
		$this->assertSame( OtpService::hash( $step1->otp_request_id, $code ), $row->otp_hash );
		$audit = implode( ' ', $wpdb->get_col( 'SELECT CONCAT_WS(" ", reason, metadata) FROM ' . Tables::name( Tables::AUDIT_LOG ) ) );
		$this->assertStringNotContainsString( $code, $audit );
		$log = (string) @file_get_contents( ini_get( 'error_log' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$this->assertStringNotContainsString( 'verification code is ' . $code, $log );
	}

	// ===================================================== CONFIG TRANSITIONS

	public function test_transition_off_on_off_uses_one_workflow(): void {
		// 1. OFF → succeeds without OTP.
		$this->otp_off();
		$this->assertSame( SubmissionResult::STATUS_CREATED, $this->submit( $this->payload() )->status );
		$this->assertCount( 0, $this->sms->sent );

		// 2. ON → OTP required; nothing created until verified.
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$this->assertSame( SubmissionResult::STATUS_OTP_REQUIRED, $step1->status );
		$this->assertSame( 1, $this->registration_count() );
		$done = $this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => $this->sms->last_code() ) );
		$this->assertSame( '1', (string) Plugin::instance()->registrations()->get( $done->registration_id )->phone_verified );

		// 3. OFF again → OTP no longer required.
		$this->otp_off();
		$sent_before = count( $this->sms->sent );
		$again       = $this->submit( $this->payload() );
		$this->assertSame( SubmissionResult::STATUS_CREATED, $again->status );
		$this->assertSame( '0', (string) Plugin::instance()->registrations()->get( $again->registration_id )->phone_verified );
		$this->assertCount( $sent_before, $this->sms->sent );
	}

	public function test_transition_switching_off_mid_verification_completes_without_otp(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->submit( $payload );
		$this->otp_off();

		$result = $this->submit( $payload + array( 'otp_request_id' => $step1->otp_request_id, 'otp_code' => 'ignored' ) );
		$this->assertSame( SubmissionResult::STATUS_CREATED, $result->status );
		$this->assertSame( '0', (string) Plugin::instance()->registrations()->get( $result->registration_id )->phone_verified );
	}
}
