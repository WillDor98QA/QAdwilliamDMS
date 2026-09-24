<?php
/**
 * One-time-password issue, resend and verification (MP §10; Decisions §9–§12; CR-01).
 *
 * Security rules (all limits are Settings values, Decisions §11):
 * - Only an HMAC of the code is stored; the code is never logged or audited.
 * - Per-OTP attempt limit, expiry, resend cooldown, per-phone hourly/daily and
 *   per-IP hourly send limits.
 * - A new code supersedes older pending codes for the same phone.
 * - A verified request becomes unusable for a second verification, and is
 *   CONSUMED when the registration is created.
 * - Enumeration protection: an already-registered number gets the same
 *   response and the same limits, but no SMS is sent, so its code can never
 *   verify (Decisions §10). Both paths take at least the same minimum time
 *   (MIN_DELIVERY_MS) so response timing does not reveal which numbers are
 *   registered (R-01).
 * - Failed verification attempts are counted outside the caller's
 *   transaction, so a rollback cannot reset the counter.
 *
 * @package DMS
 */

namespace DMS\Otp;

use DMS\Audit\ActorType;
use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Tables;
use DMS\Errors\ConflictException;
use DMS\Errors\RateLimitedException;
use DMS\Errors\UnavailableException;
use DMS\Errors\ValidationException;
use DMS\Registrations\PhoneNormalizer;
use DMS\Security\RateLimiter;
use DMS\Sms\SmsConfiguration;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\RequestContext;
use DMS\Support\Settings;

defined( 'ABSPATH' ) || exit;

class OtpService {

	public const STATUS_PENDING    = 'PENDING';
	public const STATUS_VERIFIED   = 'VERIFIED';
	public const STATUS_CONSUMED   = 'CONSUMED';
	public const STATUS_LOCKED     = 'LOCKED';
	public const STATUS_EXPIRED    = 'EXPIRED';
	public const STATUS_SUPERSEDED = 'SUPERSEDED';
	public const STATUS_FAILED     = 'SEND_FAILED';

	public const CODE_LENGTH = 6;

	/**
	 * Minimum time spent in delivery, duplicate or not (R-01). Covers a typical
	 * SMS provider round trip; filterable via `dms_otp_min_delivery_ms`.
	 */
	public const MIN_DELIVERY_MS = 1500;

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private Settings $settings,
		private SmsConfiguration $sms,
		private RateLimiter $limiter,
		private AuditService $audit,
		private Logger $logger,
		private RequestContext $context,
	) {
	}

	private function table(): string {
		return Tables::name( Tables::OTP_REQUESTS );
	}

	/**
	 * Issues a code for a phone number and sends it (unless the number is a duplicate).
	 *
	 * @throws UnavailableException SMS not configured, or the provider failed.
	 * @throws RateLimitedException Cooldown or send limits reached.
	 */
	public function start( string $phone_normalized, bool $is_duplicate ): OtpChallenge {
		$this->assert_sms_ready();
		$this->enforce_send_limits( $phone_normalized );

		$now        = $this->clock->now_mysql();
		$request_id = bin2hex( random_bytes( 16 ) );
		$code       = $this->new_code();

		$this->db->update(
			$this->table(),
			array(
				'status'     => self::STATUS_SUPERSEDED,
				'updated_at' => $now,
			),
			array(
				'phone_normalized' => $phone_normalized,
				'status'           => self::STATUS_PENDING,
			)
		);
		$ok = $this->db->insert(
			$this->table(),
			array(
				'request_id'       => $request_id,
				'phone_normalized' => $phone_normalized,
				'otp_hash'         => self::hash( $request_id, $code ),
				'status'           => self::STATUS_PENDING,
				'is_duplicate'     => $is_duplicate ? 1 : 0,
				'attempts'         => 0,
				'send_count'       => 1,
				'last_sent_at'     => $now,
				'expires_at'       => $this->expiry(),
				'ip_hash'          => $this->context->ip_hash(),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not store OTP request: ' . $this->db->last_error );
		}
		$id = (int) $this->db->insert_id;

		$this->deliver( $id, $phone_normalized, $code, $is_duplicate );
		$this->audit->record(
			AuditAction::OTP_REQUESTED,
			array(
				'object_type' => 'otp_request',
				'object_id'   => $id,
				'actor_type'  => ActorType::PUBLIC_USER,
				'user_id'     => null,
				'metadata'    => array(
					'phone'    => PhoneNormalizer::mask( $phone_normalized ),
					'sms_sent' => ! $is_duplicate,
				),
			)
		);
		return $this->challenge( $request_id );
	}

	/**
	 * Sends a fresh code for an existing pending request (new code, attempts reset).
	 *
	 * @throws ValidationException Unknown request, or phone mismatch.
	 */
	public function resend( string $request_id, string $phone_normalized ): OtpChallenge {
		$row = $this->find_pending( $request_id, $phone_normalized );
		$this->assert_sms_ready();
		$this->enforce_send_limits( $phone_normalized );

		$code = $this->new_code();
		$now  = $this->clock->now_mysql();
		$this->db->update(
			$this->table(),
			array(
				'otp_hash'     => self::hash( $request_id, $code ),
				'attempts'     => 0,
				'send_count'   => (int) $row->send_count + 1,
				'last_sent_at' => $now,
				'expires_at'   => $this->expiry(),
				'updated_at'   => $now,
			),
			array( 'id' => $row->id )
		);
		$this->deliver( (int) $row->id, $phone_normalized, $code, (bool) (int) $row->is_duplicate );
		$this->audit->record(
			AuditAction::OTP_REQUESTED,
			array(
				'object_type' => 'otp_request',
				'object_id'   => (int) $row->id,
				'actor_type'  => ActorType::PUBLIC_USER,
				'user_id'     => null,
				'metadata'    => array(
					'phone'    => PhoneNormalizer::mask( $phone_normalized ),
					'resend'   => true,
					'sms_sent' => ! (int) $row->is_duplicate,
				),
			)
		);
		return $this->challenge( $request_id );
	}

	/**
	 * Verifies a code. On success the request becomes VERIFIED and cannot be verified again.
	 *
	 * @return int OTP request row ID, to be consumed when the registration is created.
	 * @throws ValidationException Wrong, expired, locked or unknown code (field "otp_code").
	 */
	public function verify( string $request_id, string $phone_normalized, string $code ): int {
		$row = $this->find_pending( $request_id, $phone_normalized );
		$max = $this->settings->int( 'otp_max_attempts' );

		if ( $row->expires_at <= $this->clock->now_mysql() ) {
			$this->set_status( (int) $row->id, self::STATUS_EXPIRED );
			throw new ValidationException( array( 'otp_code' => __( 'This code has expired. Please request a new code.', 'dms' ) ) );
		}
		if ( (int) $row->attempts >= $max ) {
			$this->set_status( (int) $row->id, self::STATUS_LOCKED );
			throw new ValidationException( array( 'otp_code' => __( 'Too many incorrect attempts. Please request a new code.', 'dms' ) ) );
		}

		// Count the attempt first, atomically and outside any caller transaction.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		$counted = $this->db->query( $this->db->prepare( "UPDATE {$this->table()} SET attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = %s AND attempts < %d", $this->clock->now_mysql(), $row->id, self::STATUS_PENDING, $max ) );
		if ( 1 !== $counted ) {
			throw new ValidationException( array( 'otp_code' => __( 'Too many incorrect attempts. Please request a new code.', 'dms' ) ) );
		}
		$attempts = (int) $row->attempts + 1;

		$code = preg_replace( '/\D/', '', $code );
		if ( ! hash_equals( (string) $row->otp_hash, self::hash( $request_id, (string) $code ) ) ) {
			$locked = $attempts >= $max;
			if ( $locked ) {
				$this->set_status( (int) $row->id, self::STATUS_LOCKED );
			}
			$this->audit->record(
				$locked ? AuditAction::OTP_LOCKED : AuditAction::OTP_FAILED,
				array(
					'object_type' => 'otp_request',
					'object_id'   => (int) $row->id,
					'actor_type'  => ActorType::PUBLIC_USER,
					'user_id'     => null,
					'metadata'    => array( 'attempt' => $attempts ),
				)
			);
			$message = $locked
				? __( 'Too many incorrect attempts. Please request a new code.', 'dms' )
				/* translators: %d: remaining attempts */
				: sprintf( _n( 'The code is incorrect. %d attempt remaining.', 'The code is incorrect. %d attempts remaining.', $max - $attempts, 'dms' ), $max - $attempts );
			throw new ValidationException( array( 'otp_code' => $message ) );
		}

		$now = $this->clock->now_mysql();
		$this->db->update(
			$this->table(),
			array(
				'status'      => self::STATUS_VERIFIED,
				'verified_at' => $now,
				'updated_at'  => $now,
			),
			array( 'id' => $row->id )
		);
		return (int) $row->id;
	}

	/**
	 * Marks a verified request as used. Call inside the registration-creation transaction.
	 *
	 * @throws ConflictException If it was already consumed (double submission).
	 */
	public function consume( int $otp_request_id ): void {
		$now = $this->clock->now_mysql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $this->db->query( $this->db->prepare( "UPDATE {$this->table()} SET status = %s, consumed_at = %s, updated_at = %s WHERE id = %d AND status = %s", self::STATUS_CONSUMED, $now, $now, $otp_request_id, self::STATUS_VERIFIED ) );
		if ( 1 !== $updated ) {
			throw new ConflictException( __( 'This verification has already been used. Please start again.', 'dms' ), 'otp_consumed' );
		}
	}

	public function find_by_request_id( string $request_id ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE request_id = %s", $request_id ) );
	}

	/** Keyed hash bound to the request, so a code is useless for any other request. */
	public static function hash( string $request_id, string $code ): string {
		return hash_hmac( 'sha256', $request_id . '|' . $code, wp_salt( 'nonce' ) );
	}

	private function find_pending( string $request_id, string $phone_normalized ): object {
		$invalid = new ValidationException( array( 'otp_code' => __( 'This verification code is invalid or has expired. Please request a new code.', 'dms' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $request_id ) ) {
			throw $invalid;
		}
		$row = $this->find_by_request_id( $request_id );
		if ( null === $row || ! hash_equals( (string) $row->phone_normalized, $phone_normalized ) || self::STATUS_PENDING !== $row->status ) {
			throw $invalid;
		}
		return $row;
	}

	private function assert_sms_ready(): void {
		if ( ! $this->sms->is_ready() ) {
			$this->logger->error( 'OTP requested but SMS is not configured', array( 'problems' => $this->sms->problems() ) );
			throw new UnavailableException( __( 'Phone verification is temporarily unavailable. Please try again later.', 'dms' ) );
		}
	}

	/** Cooldown, then per-phone and per-IP send limits (Decisions §11). */
	private function enforce_send_limits( string $phone_normalized ): void {
		$cooldown = $this->settings->int( 'otp_resend_cooldown_seconds' );
		if ( $cooldown > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$last = $this->db->get_var( $this->db->prepare( "SELECT MAX(last_sent_at) FROM {$this->table()} WHERE phone_normalized = %s", $phone_normalized ) );
			if ( $last ) {
				$elapsed = $this->clock->now()->getTimestamp() - strtotime( $last . ' UTC' );
				if ( $elapsed < $cooldown ) {
					throw new RateLimitedException( $cooldown - $elapsed, __( 'Please wait before requesting another code.', 'dms' ) );
				}
			}
		}

		$ip      = $this->context->ip();
		$buckets = array(
			array( RateLimiter::bucket( 'otp_phone_h', $phone_normalized ), $this->settings->int( 'otp_max_sends_per_phone_hour' ), HOUR_IN_SECONDS ),
			array( RateLimiter::bucket( 'otp_phone_d', $phone_normalized ), $this->settings->int( 'otp_max_sends_per_phone_day' ), DAY_IN_SECONDS ),
		);
		if ( '' !== $ip ) {
			$buckets[] = array( RateLimiter::bucket( 'otp_ip_h', $ip ), $this->settings->int( 'otp_max_sends_per_ip_hour' ), HOUR_IN_SECONDS );
		}
		try {
			foreach ( $buckets as [ $bucket, $limit, $window ] ) {
				$this->limiter->check( $bucket, $limit, $window );
			}
		} catch ( RateLimitedException $e ) {
			$this->audit->record(
				AuditAction::RATE_LIMITED,
				array(
					'object_type' => 'otp_request',
					'actor_type'  => ActorType::PUBLIC_USER,
					'user_id'     => null,
					'metadata'    => array( 'phone' => PhoneNormalizer::mask( $phone_normalized ) ),
				)
			);
			throw $e;
		}
		foreach ( $buckets as [ $bucket, $limit, $window ] ) {
			$this->limiter->hit( $bucket, $limit, $window );
		}
	}

	/**
	 * Sends the code, padded to a minimum duration so a duplicate number (no SMS)
	 * answers no faster than a real send (R-01). The padding also applies when the
	 * provider fails, so an error is no faster than a success.
	 */
	private function deliver( int $id, string $phone, string $code, bool $is_duplicate ): void {
		$started = microtime( true );
		try {
			$this->send_code( $id, $phone, $code, $is_duplicate );
		} finally {
			$floor     = max( 0, (int) apply_filters( 'dms_otp_min_delivery_ms', self::MIN_DELIVERY_MS ) ) / 1000;
			$remaining = $floor - ( microtime( true ) - $started );
			if ( $remaining > 0 ) {
				usleep( (int) ( $remaining * 1000000 ) );
			}
		}
	}

	private function send_code( int $id, string $phone, string $code, bool $is_duplicate ): void {
		if ( $is_duplicate ) {
			return; // Enumeration protection: same response, no SMS.
		}
		$message = strtr(
			(string) $this->settings->get( 'otp_message_template' ),
			array(
				'{code}'    => $code,
				'{minutes}' => (string) max( 1, intdiv( $this->settings->int( 'otp_expiry_seconds' ), 60 ) ),
			)
		);
		$result  = $this->sms->gateway()->send( $phone, $message );
		if ( $result->success ) {
			return;
		}
		$this->set_status( $id, self::STATUS_FAILED );
		$reference = $this->logger->error(
			'OTP SMS delivery failed',
			array(
				'otp_request_id' => $id,
				'provider_error' => $result->error,
			)
		);
		$this->audit->record(
			AuditAction::OTP_SEND_FAILED,
			array(
				'object_type' => 'otp_request',
				'object_id'   => $id,
				'actor_type'  => ActorType::SYSTEM,
				'user_id'     => null,
				'metadata'    => array(
					'reference'      => $reference,
					'provider_error' => $result->error,
				),
			)
		);
		throw new UnavailableException( __( 'We could not send a verification code right now. Please try again later.', 'dms' ) );
	}

	private function challenge( string $request_id ): OtpChallenge {
		return new OtpChallenge( $request_id, $this->settings->int( 'otp_expiry_seconds' ), $this->settings->int( 'otp_resend_cooldown_seconds' ) );
	}

	private function set_status( int $id, string $status ): void {
		$this->db->update(
			$this->table(),
			array(
				'status'     => $status,
				'updated_at' => $this->clock->now_mysql(),
			),
			array( 'id' => $id )
		);
	}

	private function new_code(): string {
		return str_pad( (string) random_int( 0, 10 ** self::CODE_LENGTH - 1 ), self::CODE_LENGTH, '0', STR_PAD_LEFT );
	}

	private function expiry(): string {
		return gmdate( 'Y-m-d H:i:s', $this->clock->now()->getTimestamp() + $this->settings->int( 'otp_expiry_seconds' ) );
	}
}
