<?php
/**
 * Application account state for native WordPress users (dms_user_profiles).
 *
 * WordPress has no "disabled" account flag, so the plugin keeps one here.
 * A user without a profile row is treated as ACTIVE (e.g. the site owner
 * before the plugin managed them).
 *
 * @package DMS
 */

namespace DMS\Users;

use DMS\Database\Tables;
use DMS\Support\Clock;

defined( 'ABSPATH' ) || exit;

class UserProfileRepository {

	public const STATUS_ACTIVE   = 'ACTIVE';
	public const STATUS_DISABLED = 'DISABLED';

	/** @var array<int,string> Request-level cache of statuses. */
	private array $status_cache = array();

	public function __construct( private \wpdb $db, private Clock $clock ) {
	}

	public function status( int $user_id ): string {
		if ( ! isset( $this->status_cache[ $user_id ] ) ) {
			$table = Tables::name( Tables::USER_PROFILES );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$status                         = $this->db->get_var( $this->db->prepare( "SELECT status FROM {$table} WHERE user_id = %d", $user_id ) );
			$this->status_cache[ $user_id ] = null !== $status ? $status : self::STATUS_ACTIVE;
		}
		return $this->status_cache[ $user_id ];
	}

	public function is_active( int $user_id ): bool {
		return $user_id > 0 && self::STATUS_ACTIVE === $this->status( $user_id );
	}

	/** Creates the profile row if missing. Idempotent. */
	public function ensure( int $user_id ): void {
		$table = Tables::name( Tables::USER_PROFILES );
		$now   = $this->clock->now_mysql();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$table} (user_id, status, created_at, updated_at) VALUES (%d, %s, %s, %s)",
				$user_id,
				self::STATUS_ACTIVE,
				$now,
				$now
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not create user profile: ' . $this->db->last_error );
		}
	}

	public function set_status( int $user_id, string $status, ?int $changed_by ): void {
		if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_DISABLED ), true ) ) {
			throw new \InvalidArgumentException( "Unknown user status {$status}" );
		}
		$this->ensure( $user_id );
		$disabled = self::STATUS_DISABLED === $status;
		$ok       = $this->db->update(
			Tables::name( Tables::USER_PROFILES ),
			array(
				'status'      => $status,
				'disabled_at' => $disabled ? $this->clock->now_mysql() : null,
				'disabled_by' => $disabled ? $changed_by : null,
				'updated_at'  => $this->clock->now_mysql(),
			),
			array( 'user_id' => $user_id )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not update user status: ' . $this->db->last_error );
		}
		unset( $this->status_cache[ $user_id ] );
		do_action( 'dms_permissions_changed', $user_id );
	}

	/**
	 * Records when the officer last received a new assignment (tie-breaker, ARCH §72.3).
	 *
	 * @param string $at UTC time, to the microsecond (R-04), e.g. 2026-09-24 10:00:00.123456.
	 */
	public function touch_last_assigned( int $user_id, string $at ): void {
		$this->ensure( $user_id );
		$ok = $this->db->update(
			Tables::name( Tables::USER_PROFILES ),
			array(
				'last_assigned_at' => $at,
				'updated_at'       => substr( $at, 0, 19 ),
			),
			array( 'user_id' => $user_id )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not update last assignment time: ' . $this->db->last_error );
		}
	}

	public function flush( ?int $user_id = null ): void {
		if ( null === $user_id ) {
			$this->status_cache = array();
		} else {
			unset( $this->status_cache[ $user_id ] );
		}
	}
}
