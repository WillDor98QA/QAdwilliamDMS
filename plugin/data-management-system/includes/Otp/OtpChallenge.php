<?php
/**
 * What the public client is told after an OTP is requested. Identical whether
 * or not the number is already registered (enumeration protection, Decisions §10).
 *
 * @package DMS
 */

namespace DMS\Otp;

defined( 'ABSPATH' ) || exit;

final class OtpChallenge {

	public function __construct(
		public readonly string $request_id,
		public readonly int $expires_in,
		public readonly int $resend_after,
	) {
	}
}
