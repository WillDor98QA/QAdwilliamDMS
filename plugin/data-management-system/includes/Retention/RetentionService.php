<?php
/**
 * Bin retention and data housekeeping (ARCH §71.4, Decisions §21, MP §29).
 *
 * - Disapproved registrations become tombstones once disapproved_at is at
 *   least 30 days old (bin_retention_days; a business rule, not editable).
 * - Each record is purged in its own transaction; one failure is logged and
 *   does not stop the others.
 * - A MySQL named lock prevents overlapping runs. Re-running is harmless: a
 *   purged record is no longer DISAPPROVED, so it is never selected again.
 * - Housekeeping: expired rate-limit windows and OTP requests older than the
 *   retention period (they contain phone numbers) are deleted.
 *
 * @package DMS
 */

namespace DMS\Retention;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Registrations\RegistrationRepository;
use DMS\Security\RateLimiter;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\Settings;
use DMS\Workflow\RegistrationWorkflow;

defined( 'ABSPATH' ) || exit;

class RetentionService {

	private const LOCK = 'dms_retention';

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private Settings $settings,
		private RegistrationRepository $registrations,
		private RegistrationWorkflow $workflow,
		private RateLimiter $limiter,
		private Logger $logger,
	) {
	}

	/** Cutoff: a record disapproved at or before this moment is due. */
	public function cutoff(): string {
		$days = max( 1, $this->settings->int( 'bin_retention_days' ) );
		return $this->clock->now()->modify( "-{$days} days" )->format( 'Y-m-d H:i:s' );
	}

	/** @return array{purged:int,failed:int,skipped_locked:bool} */
	public function run( int $batch = 200 ): array {
		$lock = self::LOCK . '_' . $this->db->prefix;
		if ( 1 !== (int) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return array(
				'purged'         => 0,
				'failed'         => 0,
				'skipped_locked' => true,
			);
		}

		$purged = 0;
		$failed = 0;
		try {
			foreach ( $this->registrations->disapproved_before( $this->cutoff(), $batch ) as $id ) {
				try {
					$this->workflow->purge( $id, null, AuditAction::RETENTION_PURGED );
					++$purged;
				} catch ( \Throwable $e ) {
					++$failed;
					$this->logger->error(
						'Retention purge failed',
						array(
							'registration_id' => $id,
							'error'           => $e->getMessage(),
						)
					);
				}
			}
			$this->housekeeping();
		} finally {
			$this->db->query( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		return array(
			'purged'         => $purged,
			'failed'         => $failed,
			'skipped_locked' => false,
		);
	}

	private function housekeeping(): void {
		$this->limiter->purge_expired();
		$otp = Tables::name( Tables::OTP_REQUESTS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		$this->db->query( $this->db->prepare( "DELETE FROM {$otp} WHERE updated_at < %s", $this->cutoff() ) );
	}
}
