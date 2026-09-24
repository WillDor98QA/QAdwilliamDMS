<?php
/**
 * Typed access to configurable values, with defaults from Decisions §11.
 *
 * These are configuration, not business rules in code: every value is
 * stored in the `dms_settings` option and editable under Settings (Phase 6).
 * Secrets (SMS, Turnstile, SMTP credentials) are NOT stored here — they are
 * read from wp-config.php constants / environment (Decisions §12, §14).
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

class Settings {

	public const OPTION = 'dms_settings';

	/** @return array<string,int|string|bool|list<string>> */
	public static function defaults(): array {
		return array(
			// CR-01: OTP verification is a switch. Off by default — it can only be
			// switched on once an SMS gateway is configured (SettingsService).
			'otp_enabled'                  => false,
			'sms_gateway'                  => '',
			'sms_sender_id'                => '',
			'otp_message_template'         => 'Your verification code is {code}. It expires in {minutes} minutes.',
			'otp_expiry_seconds'           => 300,
			'otp_max_attempts'             => 5,
			'otp_resend_cooldown_seconds'  => 60,
			'otp_max_sends_per_phone_hour' => 3,
			'otp_max_sends_per_phone_day'  => 10,
			'otp_max_sends_per_ip_hour'    => 10,
			'bin_retention_days'           => 30,
			'turnstile_enabled'            => false,
			'admin_notification_emails'    => array(),
			'import_max_file_bytes'        => 20 * 1024 * 1024,
			'import_file_retention_days'   => 90, // R-07; 0 keeps uploaded workbooks forever.
			'notification_max_attempts'    => 5,
			// Public endpoint abuse controls (Decisions §39).
			'submission_max_per_ip_hour'   => 10,
			'lookup_max_per_ip_minute'     => 120,
			// Exports above this row count run in the background (implementation threshold, not a business rule).
			'export_sync_max_rows'         => 2000,
		);
	}

	public function get( string $key ): mixed {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}
		if ( 'admin_notification_emails' === $key ) {
			return array( (string) get_option( 'admin_email' ) );
		}
		return self::defaults()[ $key ] ?? null;
	}

	public function int( string $key ): int {
		return (int) $this->get( $key );
	}

	public function bool( string $key ): bool {
		return (bool) $this->get( $key );
	}

	/** @return array<string,mixed> Previous values of changed keys. */
	public function update( array $values ): array {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$previous = array();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, self::defaults() ) ) {
				throw new \InvalidArgumentException( "Unknown setting {$key}" );
			}
			$previous[ $key ] = $this->get( $key );
			$stored[ $key ]   = $value;
		}
		update_option( self::OPTION, $stored, false );
		return $previous;
	}
}
