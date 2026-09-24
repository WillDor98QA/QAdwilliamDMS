<?php
/**
 * SMS-01: Npontu Deywuro gateway (NPONTU_SMS_API_DOCUMENT.pdf).
 *
 * The HTTP call is intercepted with pre_http_request: nothing reaches
 * deywuro.com and no SMS credit is used.
 */

namespace DMS\Tests\Integration;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Errors\UnavailableException;
use DMS\Plugin;
use DMS\Sms\DeywuroGateway;
use DMS\Support\Settings;

final class DeywuroGatewayTest extends \WP_UnitTestCase {

	use Fixtures;

	private const USER = 'npontutest';
	private const PASS = 'S3cr3t-Pa55word';

	/** @var list<array{url:string,args:array}> */
	private array $requests = array();

	/** @var array|\WP_Error What the fake Deywuro answers. */
	private $reply;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( \DMS\Support\SecretStore::OPTION );
		$this->reply = $this->json( 200, array( 'code' => 0, 'message' => '1 sms successfully sent!' ) );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( ! str_starts_with( (string) $url, 'https://deywuro.com/' ) ) {
					return $pre;
				}
				$this->requests[] = array( 'url' => (string) $url, 'args' => $args );
				return $this->reply;
			},
			10,
			3
		);
	}

	private function json( int $status, $body ): array {
		return array(
			'response' => array( 'code' => $status, 'message' => '' ),
			'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
			'headers'  => array(),
			'cookies'  => array(),
		);
	}

	private function configure(): void {
		Plugin::instance()->secrets()->set( DeywuroGateway::USERNAME_NAME, self::USER );
		Plugin::instance()->secrets()->set( DeywuroGateway::PASSWORD_NAME, self::PASS );
		Plugin::instance()->settings()->update( array( 'sms_gateway' => DeywuroGateway::ID, 'sms_sender_id' => 'Jubilare' ) );
	}

	private function gateway(): DeywuroGateway {
		$g = Plugin::instance()->sms_gateways()->get( DeywuroGateway::ID );
		$this->assertInstanceOf( DeywuroGateway::class, $g );
		return $g;
	}

	public function test_is_offered_with_username_and_password_fields(): void {
		$this->assertSame( array( DeywuroGateway::USERNAME_NAME, DeywuroGateway::PASSWORD_NAME ), array_column( $this->gateway()->fields(), 'key' ) );
		$this->assertContains( DeywuroGateway::PASSWORD_NAME, Plugin::instance()->settings_service()->credential_names() );
	}

	public function test_reports_every_missing_piece_and_sends_nothing(): void {
		$problems = $this->gateway()->configuration_problems();
		$this->assertCount( 3, $problems, implode( ' | ', $problems ) );
		$this->assertFalse( $this->gateway()->send( '+233241234567', 'x' )->success );
		$this->assertSame( array(), $this->requests );
	}

	public function test_sends_documented_post_request(): void {
		$this->configure();
		$this->assertSame( array(), $this->gateway()->configuration_problems() );
		$result = $this->gateway()->send( '+233241234567', 'Your code is 123456' );

		$this->assertTrue( $result->success );
		$this->assertCount( 1, $this->requests );
		[ 'url' => $url, 'args' => $args ] = $this->requests[0];
		$this->assertSame( 'https://deywuro.com/api/sms', $url, 'No query string: credentials never go in the URL' );
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame(
			array(
				'username'    => self::USER,
				'password'    => self::PASS,
				'destination' => '233241234567',
				'source'      => 'Jubilare',
				'message'     => 'Your code is 123456',
			),
			$args['body']
		);
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'], 'Never follow a redirect with the credentials' );
		$this->assertLessThanOrEqual( 15, $args['timeout'] );
	}

	/** @return iterable<string,array{int,string}> */
	public static function error_codes(): iterable {
		yield 'invalid credential'   => array( 401, 'username or password' );
		yield 'missing fields'       => array( 402, 'missing required fields' );
		yield 'insufficient balance' => array( 403, 'insufficient balance' );
		yield 'not routable'         => array( 404, 'could not route' );
		yield 'other'                => array( 500, 'internal error' );
		yield 'undocumented'         => array( 999, 'error code 999' );
	}

	/** @dataProvider error_codes */
	public function test_error_codes_become_safe_messages( int $code, string $expected ): void {
		$this->configure();
		$this->reply = $this->json( 200, array( 'code' => $code, 'message' => 'provider says ' . self::PASS ) );
		$result      = $this->gateway()->send( '+233241234567', 'Your code is 654321' );
		$this->assertFalse( $result->success );
		$this->assertStringContainsStringIgnoringCase( $expected, (string) $result->error );
		$this->assertStringNotContainsString( self::PASS, (string) $result->error );
		$this->assertStringNotContainsString( '654321', (string) $result->error );
	}

	public function test_network_failure_and_garbage_are_failures(): void {
		$this->configure();
		$this->reply = new \WP_Error( 'http_request_failed', 'cURL error 28 for https://deywuro.com/api/sms with ' . self::PASS );
		$down        = $this->gateway()->send( '+233241234567', 'x' );
		$this->assertFalse( $down->success );
		$this->assertSame( 'Could not reach Deywuro (http_request_failed).', $down->error );

		$this->reply = $this->json( 502, '<html>Bad gateway</html>' );
		$this->assertSame( 'Unexpected Deywuro response (HTTP 502).', $this->gateway()->send( '+233241234567', 'x' )->error );
	}

	public function test_otp_is_delivered_through_deywuro(): void {
		$this->configure();
		$this->act_as_admin();
		Plugin::instance()->settings_service()->update( array( 'otp_enabled' => true ) ); // Allowed: the provider is ready.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.44';

		Plugin::instance()->otp()->start( '+233241234567', false );
		$this->assertCount( 1, $this->requests );
		$this->assertMatchesRegularExpression( '/\b\d{6}\b/', $this->requests[0]['args']['body']['message'] );

		Plugin::instance()->otp()->start( '+233249999999', true ); // Registered number: no SMS, no cost (Decisions §10).
		$this->assertCount( 1, $this->requests );
	}

	public function test_provider_failure_is_audited_without_secrets_or_code(): void {
		$this->configure();
		$this->reply            = $this->json( 200, array( 'code' => 403, 'message' => 'Insufficient balance' ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.45';
		try {
			Plugin::instance()->otp()->start( '+233241230000', false );
			$this->fail( 'Expected the applicant to see "could not send"' );
		} catch ( UnavailableException $e ) {
			$this->assertStringNotContainsString( 'balance', $e->getMessage(), 'Applicants are not told about the account balance' );
		}
		$code = null;
		preg_match( '/\b(\d{6})\b/', $this->requests[0]['args']['body']['message'], $m );
		$code = $m[1];
		global $wpdb;
		$audit = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT metadata FROM ' . Tables::name( Tables::AUDIT_LOG ) . ' WHERE action = %s ORDER BY id DESC LIMIT 1', AuditAction::OTP_SEND_FAILED ) );
		$this->assertStringContainsString( 'insufficient balance', strtolower( $audit ) );
		$this->assertStringNotContainsString( self::PASS, $audit );
		$this->assertStringNotContainsString( $code, $audit );
	}

	public function test_settings_page_never_shows_the_credentials(): void {
		$this->configure();
		$this->act_as_admin();
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		set_current_screen( 'dashboard' );
		ob_start();
		Plugin::instance()->settings_page()->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Npontu Deywuro SMS — Password', $html );
		$this->assertStringNotContainsString( self::PASS, $html );
		$this->assertStringNotContainsString( self::USER, $html );
	}
}
