<?php
/**
 * Validated, authorized, audited changes to settings and credentials.
 *
 * CR-01 rules enforced here:
 * - "Allow OTP Verification" can only be ON while the SMS configuration is ready.
 * - While OTP is ON, the SMS provider or its credentials cannot be changed into
 *   a broken state (the change is refused rather than silently disabling OTP).
 * - Turnstile can only be ON while its keys are present.
 *
 * bin_retention_days is deliberately not editable: 30 days is a business
 * rule (ARCH §71.4), not configuration.
 *
 * @package DMS
 */

namespace DMS\Support;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Errors\ValidationException;
use DMS\Security\TurnstileVerifier;
use DMS\Sms\SmsConfiguration;
use DMS\Sms\SmsGatewayRegistry;

defined( 'ABSPATH' ) || exit;

class SettingsService {

	/** Integer settings: key => [min, max]. */
	private const INT_RANGES = array(
		'otp_expiry_seconds'           => array( 60, 3600 ),
		'otp_max_attempts'             => array( 1, 20 ),
		'otp_resend_cooldown_seconds'  => array( 0, 3600 ),
		'otp_max_sends_per_phone_hour' => array( 1, 100 ),
		'otp_max_sends_per_phone_day'  => array( 1, 500 ),
		'otp_max_sends_per_ip_hour'    => array( 1, 1000 ),
		'import_max_file_bytes'        => array( 1048576, 104857600 ),
		'import_file_retention_days'   => array( 0, 3650 ),
		'notification_max_attempts'    => array( 1, 20 ),
		'submission_max_per_ip_hour'   => array( 1, 1000 ),
		'lookup_max_per_ip_minute'     => array( 10, 10000 ),
		'export_sync_max_rows'         => array( 100, 100000 ),
	);

	private const BOOL_KEYS = array( 'otp_enabled', 'turnstile_enabled' );

	public function __construct(
		private Settings $settings,
		private SecretStore $secrets,
		private SmsGatewayRegistry $gateways,
		private SmsConfiguration $sms,
		private TurnstileVerifier $turnstile,
		private AuditService $audit,
		private Authorizer $authorizer,
	) {
	}

	/** @return list<string> Setting keys editable through this service. */
	public static function editable_keys(): array {
		return array_merge(
			self::BOOL_KEYS,
			array_keys( self::INT_RANGES ),
			array( 'sms_gateway', 'sms_sender_id', 'otp_message_template', 'admin_notification_emails' )
		);
	}

	/**
	 * @param array<string,mixed> $input Raw values; unknown keys are rejected.
	 * @return array<string,array{old:mixed,new:mixed}> What changed.
	 * @throws ValidationException
	 */
	public function update( array $input ): array {
		$this->authorizer->require( 'settings.edit' );

		$errors = array();
		$clean  = array();
		foreach ( $input as $key => $value ) {
			if ( ! in_array( $key, self::editable_keys(), true ) ) {
				$errors[ (string) $key ] = __( 'This setting cannot be changed here.', 'dms' );
				continue;
			}
			try {
				$clean[ $key ] = $this->clean( $key, $value );
			} catch ( \InvalidArgumentException $e ) {
				$errors[ $key ] = $e->getMessage();
			}
		}

		$merged = static fn( string $key ) => array_key_exists( $key, $clean ) ? $clean[ $key ] : null;
		$hour   = $merged( 'otp_max_sends_per_phone_hour' ) ?? $this->settings->int( 'otp_max_sends_per_phone_hour' );
		$day    = $merged( 'otp_max_sends_per_phone_day' ) ?? $this->settings->int( 'otp_max_sends_per_phone_day' );
		if ( $day < $hour ) {
			$errors['otp_max_sends_per_phone_day'] = __( 'The daily limit cannot be lower than the hourly limit.', 'dms' );
		}
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}

		$diff = array();
		foreach ( $clean as $key => $value ) {
			$old = $this->settings->get( $key );
			if ( $old !== $value ) {
				$diff[ $key ] = array(
					'old' => $old,
					'new' => $value,
				);
			}
		}
		if ( array() === $diff ) {
			return array();
		}

		$previous = $this->settings->update( array_map( static fn( array $d ): mixed => $d['new'], $diff ) );
		$problems = $this->dependency_problems();
		if ( array() !== $problems ) {
			$this->settings->update( $previous ); // Refuse: restore exactly what was there.
			throw new ValidationException( $problems );
		}

		$this->audit->record(
			AuditAction::SETTINGS_CHANGED,
			array(
				'object_type' => 'settings',
				'metadata'    => array( 'changes' => $diff ),
			)
		);
		return $diff;
	}

	/**
	 * Sets (or with '' clears) a credential. Allowed names are the Turnstile keys
	 * and the fields declared by registered SMS gateways.
	 *
	 * @throws ValidationException
	 */
	public function set_credential( string $name, string $value ): void {
		$this->authorizer->require( 'settings.edit' );
		if ( ! in_array( $name, $this->credential_names(), true ) ) {
			throw new ValidationException( array( $name => __( 'Unknown credential.', 'dms' ) ) );
		}
		if ( $this->secrets->is_locked_by_config( $name ) ) {
			throw new ValidationException( array( $name => __( 'This value is set in wp-config.php and cannot be changed here.', 'dms' ) ) );
		}

		$value    = trim( $value );
		$previous = $this->secrets->get( $name );
		$this->secrets->set( $name, $value );

		$problems = $this->dependency_problems();
		if ( array() !== $problems ) {
			$this->secrets->set( $name, (string) $previous );
			throw new ValidationException( $problems );
		}

		$this->audit->record(
			AuditAction::SETTINGS_CHANGED,
			array(
				'object_type' => 'settings',
				// The value itself is never recorded.
				'metadata'    => array(
					'credential' => $name,
					'action'     => '' === $value ? 'cleared' : 'set',
				),
			)
		);
	}

	/**
	 * Saves credentials and settings as one unit (fixes R-03): if anything fails,
	 * credentials are restored and nothing is audited.
	 *
	 * @param array<string,string> $credentials name => new value ('' = clear); names not present are untouched.
	 * @param array<string,mixed>  $settings
	 * @throws ValidationException
	 */
	public function save_all( array $credentials, array $settings ): void {
		$this->authorizer->require( 'settings.edit' );
		$snapshot = get_option( SecretStore::OPTION, array() );
		$changed  = array();
		try {
			foreach ( $credentials as $name => $value ) {
				if ( ! in_array( $name, $this->credential_names(), true ) ) {
					throw new ValidationException( array( $name => __( 'Unknown credential.', 'dms' ) ) );
				}
				if ( $this->secrets->is_locked_by_config( $name ) ) {
					throw new ValidationException( array( $name => __( 'This value is set in wp-config.php and cannot be changed here.', 'dms' ) ) );
				}
				$this->secrets->set( $name, trim( $value ) );
				$changed[ $name ] = '' === trim( $value ) ? 'cleared' : 'set';
			}
			$this->update( $settings );
			$problems = $this->dependency_problems();
			if ( array() !== $problems ) {
				throw new ValidationException( $problems );
			}
		} catch ( \Throwable $e ) {
			update_option( SecretStore::OPTION, $snapshot, false );
			throw $e;
		}
		foreach ( $changed as $name => $action ) {
			$this->audit->record(
				AuditAction::SETTINGS_CHANGED,
				array(
					'object_type' => 'settings',
					'metadata'    => array(
						'credential' => $name,
						'action'     => $action,
					),
				)
			);
		}
	}

	/**
	 * Problems an administrator must fix (shown as an admin notice / status panel).
	 *
	 * @return list<string>
	 */
	public function health(): array {
		$issues = array();
		if ( $this->settings->bool( 'otp_enabled' ) && ! $this->sms->is_ready() ) {
			$issues[] = __( 'OTP verification is ON but SMS is not configured, so public registrations cannot be submitted:', 'dms' ) . ' ' . implode( ' ', $this->sms->problems() );
		}
		if ( $this->turnstile->is_enabled() && array() !== $this->turnstile->configuration_problems() ) {
			$issues[] = __( 'Turnstile is ON but not configured:', 'dms' ) . ' ' . implode( ' ', $this->turnstile->configuration_problems() );
		}
		return $issues;
	}

	/** @return list<string> */
	public function credential_names(): array {
		$names = array( TurnstileVerifier::SITEKEY_NAME, TurnstileVerifier::SECRET_NAME );
		foreach ( $this->gateways->all() as $gateway ) {
			foreach ( $gateway->fields() as $field ) {
				$names[] = $field['key'];
			}
		}
		return array_values( array_unique( $names ) );
	}

	/** @return array<string,string> Blocking problems created by the proposed state. */
	private function dependency_problems(): array {
		$problems = array();
		if ( $this->settings->bool( 'otp_enabled' ) && ! $this->sms->is_ready() ) {
			$problems['otp_enabled'] = __( 'OTP verification needs a configured SMS provider:', 'dms' ) . ' ' . implode( ' ', $this->sms->problems() );
		}
		if ( $this->turnstile->is_enabled() && array() !== $this->turnstile->configuration_problems() ) {
			$problems['turnstile_enabled'] = implode( ' ', $this->turnstile->configuration_problems() );
		}
		return $problems;
	}

	/** @throws \InvalidArgumentException With a user-safe message when the value is invalid. */
	private function clean( string $key, mixed $value ): mixed {
		if ( in_array( $key, self::BOOL_KEYS, true ) ) {
			return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? $this->invalid( __( 'Choose on or off.', 'dms' ) );
		}
		if ( isset( self::INT_RANGES[ $key ] ) ) {
			[ $min, $max ] = self::INT_RANGES[ $key ];
			$int           = filter_var( $value, FILTER_VALIDATE_INT );
			if ( false === $int || $int < $min || $int > $max ) {
				/* translators: 1: minimum, 2: maximum */
				return $this->invalid( sprintf( __( 'Enter a whole number from %1$d to %2$d.', 'dms' ), $min, $max ) );
			}
			return $int;
		}
		switch ( $key ) {
			case 'sms_gateway':
				$id = sanitize_key( (string) $value );
				return ( '' === $id || null !== $this->gateways->get( $id ) ) ? $id : $this->invalid( __( 'Unknown SMS provider.', 'dms' ) );
			case 'sms_sender_id':
				$sender = trim( sanitize_text_field( (string) $value ) );
				return preg_match( '/^[A-Za-z0-9 ]{0,11}$/', $sender ) ? $sender : $this->invalid( __( 'Sender ID: up to 11 letters, digits or spaces.', 'dms' ) );
			case 'otp_message_template':
				$template = trim( sanitize_text_field( (string) $value ) );
				return ( str_contains( $template, '{code}' ) && mb_strlen( $template ) <= 160 ) ? $template : $this->invalid( __( 'The message must contain {code} and be at most 160 characters.', 'dms' ) );
			case 'admin_notification_emails':
				$emails = is_array( $value ) ? $value : preg_split( '/[\s,;]+/', (string) $value );
				$clean  = array();
				foreach ( array_filter( array_map( 'trim', (array) $emails ) ) as $email ) {
					if ( ! is_email( $email ) ) {
						return $this->invalid( __( 'One or more email addresses are invalid.', 'dms' ) );
					}
					$clean[] = sanitize_email( $email );
				}
				return array_values( array_unique( $clean ) );
		}
		return $this->invalid( __( 'Invalid value.', 'dms' ) );
	}

	private function invalid( string $message ): never {
		throw new \InvalidArgumentException( $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
	}
}
