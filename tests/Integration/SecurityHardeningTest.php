<?php
/**
 * Phase 10 security pass: REST surface, mass assignment, lookup abuse,
 * client IP behind proxies, private storage.
 */

namespace DMS\Tests\Integration;

use DMS\Api\PublicRegistrationController;
use DMS\Database\Tables;
use DMS\Errors\RateLimitedException;
use DMS\Plugin;
use DMS\Support\PrivateStorage;

final class SecurityHardeningTest extends \WP_UnitTestCase {

	use Fixtures;
	use PublicFlowFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_public_flow();
		$this->otp_off();
	}

	public function tear_down(): void {
		$this->tear_down_public_flow();
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		remove_all_filters( 'dms_trusted_proxies' );
		parent::tear_down();
	}

	private function submit( array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/dms/v1/public/registrations' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-DMS-Nonce', wp_create_nonce( PublicRegistrationController::NONCE_ACTION ) );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	// ------------------------------------------------------------ REST surface

	public function test_rest_surface_is_exactly_the_six_public_routes(): void {
		$routes = array_keys( array_filter( rest_get_server()->get_routes(), static fn( $k ) => str_starts_with( (string) $k, '/dms/' ), ARRAY_FILTER_USE_KEY ) );
		sort( $routes );
		$this->assertSame(
			array(
				'/dms/v1',
				'/dms/v1/public/constituencies/(?P<id>\d+)/polling-stations',
				'/dms/v1/public/form',
				'/dms/v1/public/regions',
				'/dms/v1/public/regions/(?P<id>\d+)/constituencies',
				'/dms/v1/public/registrations',
				'/dms/v1/public/registrations/otp/resend',
			),
			$routes,
			'No admin data is reachable over REST; staff actions go through nonce-checked admin-post only'
		);
	}

	public function test_wrong_methods_are_refused(): void {
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'GET', '/dms/v1/public/registrations' ) )->get_status() );
		$this->assertSame( 404, rest_do_request( new \WP_REST_Request( 'DELETE', '/dms/v1/public/regions' ) )->get_status() );
	}

	// -------------------------------------------------------- mass assignment

	public function test_public_submission_cannot_set_internal_fields(): void {
		$officer  = $this->make_officer( array( $this->h['region'] ) );
		$response = $this->submit(
			$this->payload(
				array(
					'status'              => 'APPROVED',
					'assigned_officer_id' => 1,
					'phone_verified'      => 1,
					'registration_number' => 'REG-HACK',
					'approved_at'         => '2020-01-01 00:00:00',
					'id'                  => 999999,
				)
			)
		);
		$this->assertSame( 201, $response->get_status() );
		$number = $response->get_data()['registration_number'];
		$this->assertMatchesRegularExpression( '/^REG-\d{4}-\d{6}$/', $number );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Tables::name( Tables::REGISTRATIONS ) . ' WHERE registration_number = %s', $number ) );
		$this->assertNotSame( '999999', $row->id );
		$this->assertContains( $row->status, array( 'PENDING', 'ASSIGNED' ) );
		$this->assertContains( (int) $row->assigned_officer_id, array( 0, $officer ), 'Only the assignment engine assigns' );
		$this->assertSame( '0', (string) $row->phone_verified, 'OTP is off: the phone is not verified' );
		$this->assertNull( $row->approved_at );
	}

	public function test_staff_edit_cannot_change_status_or_assignment(): void {
		$id = $this->make_registration( $this->h );
		$this->act_as( array( 'registrations.view', 'registrations.edit' ) );
		Plugin::instance()->admin_actions()->handle(
			'registration',
			array(
				'op'              => 'edit',
				'registration_id' => $id,
				'reason'          => 'Tamper',
				'changes'         => array(
					'status'              => 'APPROVED',
					'assigned_officer_id' => '1',
					'phone_verified'      => '1',
					'registration_number' => 'REG-HACK',
					'first_name'          => 'Kofi',
				),
			)
		);
		$row = Plugin::instance()->registrations()->get( $id );
		$this->assertSame( 'PENDING', $row->status );
		$this->assertNull( $row->assigned_officer_id );
		$this->assertStringStartsWith( 'REG-', $row->registration_number );
		$this->assertNotSame( 'REG-HACK', $row->registration_number );
	}

	// ------------------------------------------------------------ lookup abuse

	private function stored_lookup_transients(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dms\\_opts\\_%'" );
	}

	public function test_made_up_parent_ids_do_not_grow_storage(): void {
		$before = $this->stored_lookup_transients();
		for ( $i = 0; $i < 20; $i++ ) { // Bounded: well under the per-minute limit.
			$r = rest_do_request( new \WP_REST_Request( 'GET', '/dms/v1/public/regions/' . ( 900000 + $i ) . '/constituencies' ) );
			$this->assertSame( array(), $r->get_data() );
		}
		$this->assertSame( $before, $this->stored_lookup_transients(), 'D-10' );

		rest_do_request( new \WP_REST_Request( 'GET', '/dms/v1/public/regions/' . $this->h['region'] . '/constituencies' ) );
		$this->assertSame( $before + 1, $this->stored_lookup_transients(), 'Real lookups are still cached' );
	}

	// ------------------------------------------------------- client IP / proxy

	public function test_forwarded_header_is_ignored_unless_the_proxy_is_trusted(): void {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
		$this->assertSame( '10.0.0.5', Plugin::instance()->context()->ip() );

		add_filter( 'dms_trusted_proxies', static fn(): array => array( '10.0.0.0/8' ) );
		$this->assertSame( '198.51.100.20', Plugin::instance()->context()->ip() );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.50'; // Not the proxy: the header is the client's own claim.
		$this->assertSame( '203.0.113.50', Plugin::instance()->context()->ip() );
	}

	public function test_clients_behind_a_trusted_proxy_get_separate_rate_limits(): void {
		$this->configure( array( 'lookup_max_per_ip_minute' => 2 ) );
		add_filter( 'dms_trusted_proxies', static fn(): array => array( '10.0.0.0/8' ) );
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5';
		$status                 = function ( string $client ): int {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $client;
			return rest_do_request( new \WP_REST_Request( 'GET', '/dms/v1/public/regions' ) )->get_status();
		};
		$this->assertSame( array( 200, 200, 429 ), array( $status( '198.51.100.1' ), $status( '198.51.100.1' ), $status( '198.51.100.1' ) ) );
		$this->assertSame( 200, $status( '198.51.100.2' ), 'Another visitor is not blocked by the first one' );
		$this->assertSame( 429, $status( '1.2.3.4, 198.51.100.1' ), 'Prepending a fake address does not escape the limit' );
	}

	// ----------------------------------------------------------- private files

	public function test_private_folder_denies_web_access_on_apache_22_and_24(): void {
		$base = PrivateStorage::base();
		wp_mkdir_p( $base );
		file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // The pre-Phase-10 rule.
		PrivateStorage::dir( 'exports' );
		$rules = (string) file_get_contents( $base . '/.htaccess' );
		$this->assertSame( PrivateStorage::HTACCESS, $rules, 'Old rule upgraded' );
		$this->assertStringContainsString( 'Require all denied', $rules );
		$this->assertStringContainsString( 'Deny from all', $rules );
		$this->assertFileExists( $base . '/exports/index.php' );
	}
}
