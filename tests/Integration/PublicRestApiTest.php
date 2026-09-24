<?php
/**
 * Public REST endpoints (MP §36): routes, statuses, structured errors, nonce, rate limits,
 * and that OTP state is reported dynamically.
 */

namespace DMS\Tests\Integration;

use DMS\Api\PublicRegistrationController;
use DMS\Plugin;

final class PublicRestApiTest extends \WP_UnitTestCase {

	use PublicFlowFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_public_flow();
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		$this->tear_down_public_flow();
		parent::tear_down();
	}

	private function get( string $path ): \WP_REST_Response {
		return rest_do_request( new \WP_REST_Request( 'GET', '/dms/v1/public/' . $path ) );
	}

	private function post( string $path, array $body, ?string $nonce = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/dms/v1/public/' . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-DMS-Nonce', $nonce ?? wp_create_nonce( PublicRegistrationController::NONCE_ACTION ) );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	public function test_form_endpoint_reports_otp_state_dynamically(): void {
		$this->otp_off();
		$this->assertFalse( $this->get( 'form' )->get_data()['otp']['enabled'] );
		$this->otp_on();
		$this->assertTrue( $this->get( 'form' )->get_data()['otp']['enabled'] );
	}

	public function test_form_endpoint_exposes_no_secrets(): void {
		Plugin::instance()->secrets()->set( 'fake_api_key', 'never-leak-this' );
		$json = wp_json_encode( $this->get( 'form' )->get_data() );
		$this->assertStringNotContainsString( 'never-leak-this', $json );
		$this->assertStringNotContainsString( 'sms_gateway', $json );
	}

	public function test_cascade_returns_only_children_of_parent(): void {
		$other = $this->make_hierarchy();
		$this->assertSame( 200, $this->get( 'regions' )->get_status() );
		$constituencies = $this->get( 'regions/' . $this->h['region'] . '/constituencies' )->get_data();
		$this->assertSame( array( $this->h['constituency'] ), array_column( $constituencies, 'id' ) );
		$stations = $this->get( 'constituencies/' . $this->h['constituency'] . '/polling-stations' )->get_data();
		$this->assertSame( array( $this->h['station'] ), array_column( $stations, 'id' ) );
		$this->assertNotContains( $other['station'], array_column( $stations, 'id' ) );
	}

	public function test_lookup_rate_limit_returns_429_with_retry_after(): void {
		$this->configure( array( 'lookup_max_per_ip_minute' => 10 ) );
		for ( $i = 0; $i < 10; $i++ ) {
			$this->get( 'regions' );
		}
		$response = $this->get( 'regions' );
		$this->assertSame( 429, $response->get_status() );
		$this->assertArrayHasKey( 'Retry-After', $response->get_headers() );
		$this->assertSame( 'dms_rate_limited', $response->get_data()['code'] );
	}

	public function test_post_without_valid_nonce_is_forbidden(): void {
		$response = $this->post( 'registrations', $this->payload(), 'bad-nonce' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->registration_count() );
	}

	public function test_otp_off_returns_201_with_reference_and_no_internal_id(): void {
		$this->otp_off();
		$response = $this->post( 'registrations', $this->payload() );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'created', $data['status'] );
		$this->assertMatchesRegularExpression( '/^REG-\d{4}-\d{6}$/', $data['registration_number'] );
		$this->assertArrayNotHasKey( 'registration_id', $data );
	}

	public function test_otp_on_returns_202_then_201_after_verification(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->post( 'registrations', $payload );
		$this->assertSame( 202, $step1->get_status() );
		$this->assertSame( 'otp_required', $step1->get_data()['status'] );

		$step2 = $this->post( 'registrations', $payload + array( 'otp_request_id' => $step1->get_data()['otp_request_id'], 'otp_code' => $this->sms->last_code() ) );
		$this->assertSame( 201, $step2->get_status() );
	}

	public function test_validation_errors_are_structured_422(): void {
		$response = $this->post( 'registrations', $this->payload( array( 'first_name' => '', 'consent' => false ) ) );
		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'dms_validation_failed', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'first_name', $response->get_data()['errors'] );
		$this->assertArrayHasKey( 'consent', $response->get_data()['errors'] );
	}

	public function test_sms_misconfigured_returns_503_with_generic_message(): void {
		$this->otp_on();
		$this->sms->configured = false;
		$response = $this->post( 'registrations', $this->payload() );
		$this->assertSame( 503, $response->get_status() );
		$this->assertStringNotContainsString( 'Fake', wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'API key', wp_json_encode( $response->get_data() ) );
	}

	public function test_unexpected_error_returns_500_with_reference_only(): void {
		add_filter( 'pre_option_dms_form_config', static function () {
			throw new \RuntimeException( 'SQLSTATE secret internals' );
		} );
		$response = $this->post( 'registrations', $this->payload() );
		$this->assertSame( 500, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^REF-[0-9A-F]{8}$/', $response->get_data()['reference'] );
		$this->assertStringNotContainsString( 'SQLSTATE', wp_json_encode( $response->get_data() ) );
	}

	public function test_resend_endpoint_respects_cooldown(): void {
		$this->otp_on();
		$payload = $this->payload();
		$step1   = $this->post( 'registrations', $payload )->get_data();
		$resend  = $this->post( 'registrations/otp/resend', array( 'otp_request_id' => $step1['otp_request_id'], 'phone' => $payload['phone'] ) );
		$this->assertSame( 429, $resend->get_status() );
	}
}
