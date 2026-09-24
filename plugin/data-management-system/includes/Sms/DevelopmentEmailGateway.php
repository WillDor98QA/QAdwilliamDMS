<?php
/**
 * Development-only gateway: delivers the SMS text by email to the site admin
 * address so OTP flows can be exercised locally without an SMS provider.
 *
 * Refuses to work when wp_get_environment_type() is "production", so it can
 * never become a silent production fallback.
 *
 * @package DMS
 */

namespace DMS\Sms;

defined( 'ABSPATH' ) || exit;

final class DevelopmentEmailGateway implements SmsGateway {

	public const ID = 'development_email';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Development only — email the SMS to the site admin', 'dms' );
	}

	public function fields(): array {
		return array();
	}

	public function configuration_problems(): array {
		if ( 'production' === wp_get_environment_type() ) {
			return array( __( 'The development gateway cannot be used on a production site. Choose a real SMS provider.', 'dms' ) );
		}
		return array();
	}

	public function send( string $to, string $message ): SmsResult {
		if ( array() !== $this->configuration_problems() ) {
			return SmsResult::failed( 'Development gateway disabled in production.' );
		}
		$ok = wp_mail( (string) get_option( 'admin_email' ), '[DMS dev SMS] to ' . $to, $message );
		return $ok ? SmsResult::sent( 'dev-email' ) : SmsResult::failed( 'wp_mail() returned false.' );
	}
}
