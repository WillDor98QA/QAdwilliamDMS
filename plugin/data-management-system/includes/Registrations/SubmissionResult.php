<?php
/**
 * Outcome of one public submission request.
 *
 * STATUS_CREATED       — registration exists; registration_number is set.
 * STATUS_OTP_REQUIRED  — OTP is ON: a code was issued (or silently withheld for a
 *                        duplicate number); nothing has been created yet.
 *
 * @package DMS
 */

namespace DMS\Registrations;

defined( 'ABSPATH' ) || exit;

final class SubmissionResult {

	public const STATUS_CREATED      = 'created';
	public const STATUS_OTP_REQUIRED = 'otp_required';

	private function __construct(
		public readonly string $status,
		public readonly ?string $registration_number = null,
		public readonly ?int $registration_id = null,
		public readonly ?string $otp_request_id = null,
		public readonly ?int $expires_in = null,
		public readonly ?int $resend_after = null,
	) {
	}

	public static function created( int $id, string $number ): self {
		return new self( self::STATUS_CREATED, $number, $id );
	}

	public static function otp_required( string $request_id, int $expires_in, int $resend_after ): self {
		return new self( self::STATUS_OTP_REQUIRED, null, null, $request_id, $expires_in, $resend_after );
	}

	/** @return array<string,mixed> Public JSON (never contains the registration ID). */
	public function to_public_array(): array {
		if ( self::STATUS_CREATED === $this->status ) {
			return array(
				'status'              => $this->status,
				'registration_number' => $this->registration_number,
				'message'             => __( 'Thank you. Your registration has been received.', 'dms' ),
			);
		}
		return array(
			'status'         => $this->status,
			'otp_request_id' => $this->otp_request_id,
			'expires_in'     => $this->expires_in,
			'resend_after'   => $this->resend_after,
			// Same wording whether or not the number is already registered (Decisions §10).
			'message'        => __( 'If this number can be used, a verification code has been sent. Enter it below to complete your registration.', 'dms' ),
		);
	}
}
