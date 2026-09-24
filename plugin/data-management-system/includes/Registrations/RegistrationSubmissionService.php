<?php
/**
 * The public registration workflow (ARCH §4, §71, §77; MP §9–§13; CR-01).
 *
 * One implementation. OTP is a verification step switched by the
 * `otp_enabled` setting, read on every request so a change takes effect
 * immediately:
 *
 *  OTP OFF   validate → anti-bot + rate limit → duplicate check → CREATE (phone_verified = 0)
 *
 *  OTP ON    step 1 (no code): validate → anti-bot + rate limit → SMS ready? →
 *            internal duplicate check → issue OTP → "otp_required" (neutral message)
 *            step 2 (request id + code, same form data re-sent and re-validated):
 *            validate → verify OTP → CREATE (phone_verified = 1) + consume OTP
 *
 * Nothing is stored before verification succeeds, so no unverified personal
 * data sits in the database while OTP is ON. After commit, notifications are
 * dispatched and `dms_registration_submitted` fires (assignment, Phase 5).
 *
 * @package DMS
 */

namespace DMS\Registrations;

use DMS\Audit\ActorType;
use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Transaction;
use DMS\Electoral\ElectoralRepository;
use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Forms\FormValidator;
use DMS\Notifications\NotificationService;
use DMS\Otp\OtpService;
use DMS\Security\Honeypot;
use DMS\Security\RateLimiter;
use DMS\Security\TurnstileVerifier;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\RequestContext;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class RegistrationSubmissionService {

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private Settings $settings,
		private FormValidator $validator,
		private ElectoralRepository $electoral,
		private RegistrationRepository $registrations,
		private OtpService $otp,
		private TurnstileVerifier $turnstile,
		private RateLimiter $limiter,
		private NotificationService $notifications,
		private AuditService $audit,
		private Logger $logger,
		private RequestContext $context,
	) {
	}

	/** Read on every call — never cached — so toggling the setting applies immediately. */
	public function otp_enabled(): bool {
		return $this->settings->bool( 'otp_enabled' );
	}

	/**
	 * @param array<string,mixed> $input Raw request data (form fields, consent,
	 *                                   captcha token, honeypot, optional otp_request_id + otp_code).
	 */
	public function submit( array $input ): SubmissionResult {
		if ( Honeypot::tripped( $input ) ) {
			$this->logger->warning( 'Registration rejected by honeypot', array( 'ip_hash' => $this->context->ip_hash() ) );
			throw new ValidationException( array( 'form' => __( 'Your submission could not be processed.', 'dms' ) ) );
		}

		$otp_on   = $this->otp_enabled();
		$has_code = $otp_on && '' !== (string) ( $input['otp_request_id'] ?? '' );

		// Anti-bot and submission rate limit guard every *new* submission. The OTP
		// verification step is instead guarded by the per-code attempt limit.
		if ( ! $has_code ) {
			$ip = $this->context->ip();
			if ( '' !== $ip ) {
				$this->limiter->hit( RateLimiter::bucket( 'submit_ip_h', $ip ), $this->settings->int( 'submission_max_per_ip_hour' ), HOUR_IN_SECONDS );
			}
			if ( ! $this->turnstile->verify( (string) ( $input['captcha_token'] ?? '' ), $ip ) ) {
				throw new ValidationException( array( 'captcha' => __( 'Please complete the security check and try again.', 'dms' ) ) );
			}
		}

		$data = $this->validator->validate( $input );
		if ( ! $this->electoral->is_valid_chain( (int) $data['region_id'], (int) $data['constituency_id'], (int) $data['polling_station_id'] ) ) {
			throw new ValidationException( array( 'polling_station_id' => __( 'Please select a valid Region, Constituency and Polling Station.', 'dms' ) ) );
		}
		$phone = (string) $data['phone_normalized'];

		if ( ! $otp_on ) {
			// OD-21: without OTP there is no proof of ownership to wait for.
			if ( $this->registrations->phone_exists( $phone ) ) {
				throw $this->duplicate();
			}
			return $this->create( $data, null );
		}

		if ( ! $has_code ) {
			$challenge = $this->otp->start( $phone, $this->registrations->phone_exists( $phone ) );
			return SubmissionResult::otp_required( $challenge->request_id, $challenge->expires_in, $challenge->resend_after );
		}

		$otp_request_id = $this->otp->verify( (string) $input['otp_request_id'], $phone, (string) ( $input['otp_code'] ?? '' ) );
		return $this->create( $data, $otp_request_id );
	}

	/** Resends the code for an in-progress OTP step. */
	public function resend_otp( string $request_id, string $raw_phone ): SubmissionResult {
		$phone = PhoneNormalizer::normalize( $raw_phone );
		if ( ! $this->otp_enabled() || null === $phone ) {
			throw new ValidationException( array( 'otp_code' => __( 'This verification code is invalid or has expired. Please request a new code.', 'dms' ) ) );
		}
		$challenge = $this->otp->resend( $request_id, $phone );
		return SubmissionResult::otp_required( $challenge->request_id, $challenge->expires_in, $challenge->resend_after );
	}

	/** @param array<string,mixed> $data Validated form data. */
	private function create( array $data, ?int $otp_request_id ): SubmissionResult {
		$verified = null !== $otp_request_id;
		$now      = $this->clock->now_mysql();

		$data['consent_given_at'] = $now;
		if ( $verified ) {
			$data['phone_verified']    = 1;
			$data['phone_verified_at'] = $now;
		}

		try {
			[ $id, $notification_ids ] = Transaction::run(
				$this->db,
				function () use ( $data, $verified, $otp_request_id ): array {
					$id = $this->registrations->create( $data, $verified );
					if ( $verified ) {
						$this->otp->consume( $otp_request_id );
					}
					$registration = $this->registrations->get( $id );
					$public       = array(
						'registration_id' => $id,
						'actor_type'      => ActorType::PUBLIC_USER,
						'user_id'         => null,
					);

					$this->audit->record(
						AuditAction::SUBMITTED,
						$public + array(
							'new_status' => 'PENDING',
							'metadata'   => array(
								'registration_number' => $registration->registration_number,
								'phone_verified'      => $verified,
							),
						)
					);
					$this->audit->record( AuditAction::CONSENT_RECORDED, $public + array( 'metadata' => array( 'consent_given_at' => $registration->consent_given_at ) ) );
					if ( $verified ) {
						$this->audit->record( AuditAction::OTP_VERIFIED, $public + array( 'metadata' => array( 'otp_request_id' => $otp_request_id ) ) );
					}

					$ids = array_merge(
						$this->notifications->queue_registration_received( $registration ),
						$this->notifications->queue_admin_new_registration( $registration )
					);
					return array( $id, $ids );
				}
			);
		} catch ( ConflictException $e ) {
			if ( 'dms_duplicate_phone' === $e->error_code() ) {
				throw $this->duplicate();
			}
			throw $e;
		}

		$registration = $this->registrations->get( $id );
		// After commit: delivery problems can never undo the registration (ARCH §76.4).
		$this->notifications->dispatch( $notification_ids );
		try {
			do_action( 'dms_registration_submitted', $id );
		} catch ( \Throwable $e ) {
			// The registration is committed; a follow-up failure is logged for staff, not shown to the public.
			$this->logger->error(
				'Post-submission hook failed',
				array(
					'registration_id' => $id,
					'error'           => $e->getMessage(),
				)
			);
		}

		return SubmissionResult::created( $id, (string) $registration->registration_number );
	}

	private function duplicate(): ConflictException {
		return new ConflictException(
			__( 'This phone number has already been used for a registration. If you believe this is an error, please contact support.', 'dms' ),
			'duplicate_phone'
		);
	}
}
