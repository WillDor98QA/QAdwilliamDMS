<?php
/**
 * Outcome of one SMS send. $error is admin-safe (no credentials, no message body).
 *
 * @package DMS
 */

namespace DMS\Sms;

defined( 'ABSPATH' ) || exit;

final class SmsResult {

	private function __construct(
		public readonly bool $success,
		public readonly ?string $provider_reference,
		public readonly ?string $error,
	) {
	}

	public static function sent( ?string $provider_reference = null ): self {
		return new self( true, $provider_reference, null );
	}

	public static function failed( string $error ): self {
		return new self( false, null, $error );
	}
}
