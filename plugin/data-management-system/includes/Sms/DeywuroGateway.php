<?php
/**
 * Npontu Deywuro SMS gateway (SMS-01).
 *
 * API (NPONTU_SMS_API_DOCUMENT.pdf): POST https://deywuro.com/api/sms with
 * username, password, destination (e.g. 233244000000), source (sender ID,
 * up to 11 characters) and message. The reply is JSON {code, message};
 * code 0 = sent, 401 invalid credential, 402 missing fields,
 * 403 insufficient balance, 404 not routable, 500 other.
 *
 * Security:
 * - Always POST (the documented GET form would put the password in URLs and logs).
 * - Credentials come from SecretStore (wp-config constant DMS_DEYWURO_USERNAME /
 *   DMS_DEYWURO_PASSWORD, or the encrypted Settings value). They and the message
 *   text (which holds the OTP) never appear in errors, logs or the audit trail.
 * - TLS certificate verification stays on; short timeout.
 *
 * @package DMS
 */

namespace DMS\Sms;

use DMS\Support\SecretStore;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class DeywuroGateway implements SmsGateway {

	public const ID            = 'deywuro';
	public const ENDPOINT      = 'https://deywuro.com/api/sms';
	public const USERNAME_NAME = 'deywuro_username';
	public const PASSWORD_NAME = 'deywuro_password';
	public const TIMEOUT       = 15;

	public function __construct( private SecretStore $secrets, private Settings $settings ) {
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Npontu Deywuro SMS', 'dms' );
	}

	public function fields(): array {
		return array(
			array(
				'key'      => self::USERNAME_NAME,
				'label'    => __( 'Username', 'dms' ),
				'secret'   => false,
				'required' => true,
			),
			array(
				'key'      => self::PASSWORD_NAME,
				'label'    => __( 'Password', 'dms' ),
				'secret'   => true,
				'required' => true,
			),
		);
	}

	public function configuration_problems(): array {
		$problems = array();
		if ( ! $this->secrets->has( self::USERNAME_NAME ) ) {
			$problems[] = __( 'Deywuro username is missing.', 'dms' );
		}
		if ( ! $this->secrets->has( self::PASSWORD_NAME ) ) {
			$problems[] = __( 'Deywuro password is missing.', 'dms' );
		}
		if ( '' === $this->sender() ) {
			$problems[] = __( 'Sender ID is missing (required by Deywuro, up to 11 letters or digits).', 'dms' );
		}
		return $problems;
	}

	public function send( string $to, string $message ): SmsResult {
		if ( array() !== $this->configuration_problems() ) {
			return SmsResult::failed( 'Deywuro is not fully configured.' );
		}
		$destination = ltrim( trim( $to ), '+' ); // +233241234567 → 233241234567.
		if ( ! preg_match( '/^\d{8,15}$/', $destination ) ) {
			return SmsResult::failed( 'Invalid destination number.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array( 'Accept' => 'application/json' ),
				'body'        => array(
					'username'    => (string) $this->secrets->get( self::USERNAME_NAME ),
					'password'    => (string) $this->secrets->get( self::PASSWORD_NAME ),
					'destination' => $destination,
					'source'      => $this->sender(),
					'message'     => $message,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Error codes only (e.g. http_request_failed); the message text could echo request details.
			return SmsResult::failed( 'Could not reach Deywuro (' . $response->get_error_code() . ').' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! array_key_exists( 'code', $data ) || ! is_numeric( $data['code'] ) ) {
			return SmsResult::failed( sprintf( 'Unexpected Deywuro response (HTTP %d).', $status ) );
		}

		$code = (int) $data['code'];
		if ( 0 === $code ) {
			return SmsResult::sent( 'deywuro' );
		}
		return SmsResult::failed( self::describe( $code ) );
	}

	/** Admin-safe description of a Deywuro error code. */
	public static function describe( int $code ): string {
		$known = array(
			401 => 'Deywuro rejected the username or password (401).',
			402 => 'Deywuro reported missing required fields (402).',
			403 => 'Deywuro account has insufficient balance (403).',
			404 => 'Deywuro could not route the message to this number (404).',
			500 => 'Deywuro reported an internal error (500).',
		);
		return $known[ $code ] ?? sprintf( 'Deywuro returned error code %d.', $code );
	}

	private function sender(): string {
		return trim( (string) $this->settings->get( 'sms_sender_id' ) );
	}
}
