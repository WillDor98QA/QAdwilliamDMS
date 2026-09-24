<?php
/**
 * CR-01 SMS configuration dependency + settings validation, authorization and audit.
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ValidationException;
use DMS\Plugin;
use DMS\Tests\Support\FakeSmsGateway;

final class SettingsServiceTest extends \WP_UnitTestCase {

	use PublicFlowFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_public_flow();
		$this->act_as_admin();
	}

	public function tear_down(): void {
		$this->tear_down_public_flow();
		parent::tear_down();
	}

	private function service() {
		return Plugin::instance()->settings_service();
	}

	public function test_requires_settings_edit(): void {
		$this->act_as( array( 'settings.view' ) );
		$this->expectException( AuthorizationException::class );
		$this->service()->update( array( 'otp_max_attempts' => 3 ) );
	}

	public function test_cannot_enable_otp_without_an_sms_provider(): void {
		try {
			$this->service()->update( array( 'otp_enabled' => true ) );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'otp_enabled', $e->errors );
			$this->assertStringContainsString( 'No SMS provider', $e->errors['otp_enabled'] );
		}
		$this->assertFalse( Plugin::instance()->settings()->bool( 'otp_enabled' ), 'Nothing was saved' );
	}

	public function test_cannot_enable_otp_with_incomplete_provider_configuration(): void {
		$this->sms->configured = false;
		$this->expectException( ValidationException::class );
		$this->service()->update( array( 'sms_gateway' => FakeSmsGateway::ID, 'otp_enabled' => true ) );
	}

	public function test_can_enable_otp_once_sms_is_ready_and_it_applies_immediately(): void {
		$this->service()->update( array( 'sms_gateway' => FakeSmsGateway::ID, 'otp_enabled' => true ) );
		$this->assertTrue( Plugin::instance()->submissions()->otp_enabled() );
		$this->service()->update( array( 'otp_enabled' => false ) );
		$this->assertFalse( Plugin::instance()->submissions()->otp_enabled() );
	}

	public function test_cannot_remove_the_provider_while_otp_is_on(): void {
		$this->service()->update( array( 'sms_gateway' => FakeSmsGateway::ID, 'otp_enabled' => true ) );
		$this->expectException( ValidationException::class );
		$this->service()->update( array( 'sms_gateway' => '' ) );
	}

	public function test_health_reports_otp_on_with_broken_sms(): void {
		$this->service()->update( array( 'sms_gateway' => FakeSmsGateway::ID, 'otp_enabled' => true ) );
		$this->assertSame( array(), $this->service()->health() );
		$this->sms->configured = false;
		$health = $this->service()->health();
		$this->assertCount( 1, $health );
		$this->assertStringContainsString( 'OTP verification is ON but SMS is not configured', $health[0] );
	}

	public function test_otp_limits_are_configurable_and_validated(): void {
		$this->service()->update( array( 'otp_expiry_seconds' => 600, 'otp_max_attempts' => 3 ) );
		$this->assertSame( 600, Plugin::instance()->settings()->int( 'otp_expiry_seconds' ) );
		try {
			$this->service()->update( array( 'otp_max_attempts' => 0, 'otp_max_sends_per_phone_day' => 1, 'otp_max_sends_per_phone_hour' => 5 ) );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'otp_max_attempts', $e->errors );
			$this->assertArrayHasKey( 'otp_max_sends_per_phone_day', $e->errors );
		}
	}

	public function test_defaults_match_approved_otp_rules(): void {
		$s = Plugin::instance()->settings();
		$this->assertSame( 300, $s->int( 'otp_expiry_seconds' ) );
		$this->assertSame( 5, $s->int( 'otp_max_attempts' ) );
		$this->assertSame( 60, $s->int( 'otp_resend_cooldown_seconds' ) );
		$this->assertSame( 3, $s->int( 'otp_max_sends_per_phone_hour' ) );
		$this->assertSame( 10, $s->int( 'otp_max_sends_per_phone_day' ) );
		$this->assertSame( 10, $s->int( 'otp_max_sends_per_ip_hour' ) );
	}

	public function test_retention_period_is_not_editable(): void {
		$this->expectException( ValidationException::class );
		$this->service()->update( array( 'bin_retention_days' => 5 ) );
	}

	public function test_message_template_must_contain_code(): void {
		$this->expectException( ValidationException::class );
		$this->service()->update( array( 'otp_message_template' => 'Hello there' ) );
	}

	public function test_changes_are_audited_and_credentials_never_recorded(): void {
		global $wpdb;
		$this->service()->set_credential( 'fake_api_key', 'super-secret-value' );
		$this->service()->update( array( 'otp_max_attempts' => 4 ) );

		$rows = $wpdb->get_col( $wpdb->prepare( 'SELECT metadata FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE action = %s', AuditAction::SETTINGS_CHANGED ) );
		$all  = implode( ' ', $rows );
		$this->assertStringContainsString( 'fake_api_key', $all );
		$this->assertStringContainsString( '"otp_max_attempts"', $all );
		$this->assertStringNotContainsString( 'super-secret-value', $all );
	}

	public function test_credentials_are_encrypted_at_rest(): void {
		$this->service()->set_credential( 'fake_api_key', 'super-secret-value' );
		$this->assertStringNotContainsString( 'super-secret-value', wp_json_encode( get_option( \DMS\Support\SecretStore::OPTION ) ) );
		$this->assertSame( 'super-secret-value', Plugin::instance()->secrets()->get( 'fake_api_key' ) );
	}

	public function test_environment_value_takes_precedence_and_locks_ui(): void {
		putenv( 'DMS_FAKE_API_KEY=from-env' );
		try {
			$this->assertSame( 'from-env', Plugin::instance()->secrets()->get( 'fake_api_key' ) );
			$this->expectException( ValidationException::class );
			$this->service()->set_credential( 'fake_api_key', 'ui-value' );
		} finally {
			putenv( 'DMS_FAKE_API_KEY' );
		}
	}

	public function test_unknown_credential_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->service()->set_credential( 'not_a_real_key', 'x' );
	}
}
