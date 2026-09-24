<?php
/**
 * Fixed-window counters in dms_rate_limits (Decisions §39).
 *
 * Buckets are keyed by action and a hashed subject (phone or IP), never raw values.
 * The increment is one atomic INSERT … ON DUPLICATE KEY UPDATE.
 *
 * @package DMS
 */

namespace DMS\Security;

use DMS\Database\Tables;
use DMS\Errors\RateLimitedException;
use DMS\Support\Clock;
use DMS\Support\RequestContext;

defined( 'ABSPATH' ) || exit;

class RateLimiter {

	public function __construct( private \wpdb $db, private Clock $clock ) {
	}

	public static function bucket( string $action, string $subject ): string {
		return $action . ':' . RequestContext::hash( $subject );
	}

	/** Current count in the active window, without incrementing. */
	public function count( string $bucket, int $window_seconds ): int {
		$table = Tables::name( Tables::RATE_LIMITS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return (int) $this->db->get_var( $this->db->prepare( "SELECT hits FROM {$table} WHERE bucket = %s AND window_start = %s", $bucket, $this->window_start( $window_seconds ) ) );
	}

	/** Throws without incrementing if the bucket is already at its limit. */
	public function check( string $bucket, int $limit, int $window_seconds ): void {
		if ( $limit > 0 && $this->count( $bucket, $window_seconds ) >= $limit ) {
			throw new RateLimitedException( $this->retry_after( $window_seconds ) );
		}
	}

	/** Records one hit and throws if that hit exceeded the limit. */
	public function hit( string $bucket, int $limit, int $window_seconds ): void {
		$table  = Tables::name( Tables::RATE_LIMITS );
		$start  = $this->window_start( $window_seconds );
		$expiry = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' UTC' ) + $window_seconds );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $this->db->query(
			$this->db->prepare(
				"INSERT INTO {$table} (bucket, window_start, hits, expires_at) VALUES (%s, %s, 1, %s)
				ON DUPLICATE KEY UPDATE hits = hits + 1",
				$bucket,
				$start,
				$expiry
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Rate limit update failed: ' . $this->db->last_error );
		}
		if ( $limit > 0 && $this->count( $bucket, $window_seconds ) > $limit ) {
			throw new RateLimitedException( $this->retry_after( $window_seconds ) );
		}
	}

	/** Deletes expired windows. Called by the daily maintenance job. */
	public function purge_expired(): int {
		$table = Tables::name( Tables::RATE_LIMITS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->query( $this->db->prepare( "DELETE FROM {$table} WHERE expires_at < %s", $this->clock->now_mysql() ) );
	}

	private function window_start( int $window_seconds ): string {
		$now = $this->clock->now()->getTimestamp();
		return gmdate( 'Y-m-d H:i:s', $now - ( $now % max( 1, $window_seconds ) ) );
	}

	private function retry_after( int $window_seconds ): int {
		$now = $this->clock->now()->getTimestamp();
		return max( 1, $window_seconds - ( $now % max( 1, $window_seconds ) ) );
	}
}
