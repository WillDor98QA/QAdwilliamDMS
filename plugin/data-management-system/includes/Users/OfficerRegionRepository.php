<?php
/**
 * Officer ↔ Region links (ARCH §72.1, Decisions §4, §37).
 *
 * Links are deactivated rather than deleted, so historical configuration
 * stays inspectable. Workload counts are computed in one grouped query for
 * all candidates, avoiding N+1 queries (MP §32).
 *
 * @package DMS
 */

namespace DMS\Users;

use DMS\Database\Tables;
use DMS\Database\Transaction;
use DMS\Support\Clock;
use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

class OfficerRegionRepository {

	public const STATUS_ACTIVE   = 'ACTIVE';
	public const STATUS_INACTIVE = 'INACTIVE';

	public function __construct( private \wpdb $db, private Clock $clock ) {
	}

	private function table(): string {
		return Tables::name( Tables::OFFICER_REGIONS );
	}

	/** @return list<int> Region IDs the user is actively linked to. */
	public function active_region_ids( int $user_id ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT region_id FROM {$this->table()} WHERE user_id = %d AND status = %s ORDER BY region_id", $user_id, self::STATUS_ACTIVE ) ) );
	}

	/**
	 * Makes exactly $region_ids active for the user; others become inactive.
	 *
	 * @param list<int> $region_ids Must already be validated as existing regions.
	 * @return array{added:list<int>,removed:list<int>}
	 */
	public function set_regions( int $user_id, array $region_ids, ?int $changed_by ): array {
		$wanted  = array_values( array_unique( array_map( 'intval', $region_ids ) ) );
		$current = $this->active_region_ids( $user_id );
		$added   = array_values( array_diff( $wanted, $current ) );
		$removed = array_values( array_diff( $current, $wanted ) );

		Transaction::run(
			$this->db,
			function () use ( $user_id, $added, $removed, $changed_by ): void {
				$now = $this->clock->now_mysql();
				foreach ( $added as $region_id ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ok = $this->db->query(
						$this->db->prepare(
							"INSERT INTO {$this->table()} (user_id, region_id, status, created_by, created_at, updated_at)
							VALUES (%d, %d, %s, %d, %s, %s)
							ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)",
							$user_id,
							$region_id,
							self::STATUS_ACTIVE,
							(int) $changed_by,
							$now,
							$now
						)
					);
					if ( false === $ok ) {
						throw new \RuntimeException( 'Could not link region: ' . $this->db->last_error );
					}
				}
				foreach ( $removed as $region_id ) {
					$ok = $this->db->update(
						$this->table(),
						array(
							'status'     => self::STATUS_INACTIVE,
							'updated_at' => $now,
						),
						array(
							'user_id'   => $user_id,
							'region_id' => $region_id,
						)
					);
					if ( false === $ok ) {
						throw new \RuntimeException( 'Could not unlink region: ' . $this->db->last_error );
					}
				}
			}
		);

		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Users actively linked to the region whose application account is active.
	 * The assignment engine additionally checks the assignment.receive permission.
	 *
	 * @return list<int>
	 */
	public function active_user_ids_for_region( int $region_id ): array {
		$profiles = Tables::name( Tables::USER_PROFILES );
		$users    = $this->db->users;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			'intval',
			$this->db->get_col(
				$this->db->prepare(
					"SELECT o.user_id FROM {$this->table()} o
					INNER JOIN {$users} u ON u.ID = o.user_id
					LEFT JOIN {$profiles} pr ON pr.user_id = o.user_id
					WHERE o.region_id = %d AND o.status = %s AND COALESCE(pr.status, %s) = %s
					ORDER BY o.user_id",
					$region_id,
					self::STATUS_ACTIVE,
					UserProfileRepository::STATUS_ACTIVE,
					UserProfileRepository::STATUS_ACTIVE
				)
			)
		);
	}

	/**
	 * Active workload (ASSIGNED + UNDER_REVIEW, ARCH §72.2) and last-assignment time per user.
	 *
	 * @param list<int> $user_ids
	 * @return array<int,array{workload:int,last_assigned_at:?string}> Every requested user is present.
	 */
	public function workloads( array $user_ids ): array {
		$result = array();
		foreach ( $user_ids as $user_id ) {
			$result[ (int) $user_id ] = array(
				'workload'         => 0,
				'last_assigned_at' => null,
			);
		}
		if ( array() === $result ) {
			return $result;
		}

		$ids           = array_keys( $result );
		$in            = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$registrations = Tables::name( Tables::REGISTRATIONS );
		$profiles      = Tables::name( Tables::USER_PROFILES );
		$states        = array_map( static fn( Status $s ): string => $s->value, Status::workload() );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$counts = $this->db->get_results(
			$this->db->prepare(
				"SELECT assigned_officer_id AS user_id, COUNT(*) AS workload FROM {$registrations}
				WHERE assigned_officer_id IN ({$in}) AND status IN (%s, %s)
				GROUP BY assigned_officer_id",
				...array_merge( $ids, $states )
			)
		);
		$last   = $this->db->get_results( $this->db->prepare( "SELECT user_id, last_assigned_at FROM {$profiles} WHERE user_id IN ({$in})", ...$ids ) );
		// phpcs:enable

		foreach ( $counts as $row ) {
			$result[ (int) $row->user_id ]['workload'] = (int) $row->workload;
		}
		foreach ( $last as $row ) {
			$result[ (int) $row->user_id ]['last_assigned_at'] = $row->last_assigned_at;
		}
		return $result;
	}

	/** Outstanding actionable registrations for a user (ARCH §72.5 — identify work of disabled officers). */
	public function outstanding_count( int $user_id ): int {
		$registrations = Tables::name( Tables::REGISTRATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$registrations} WHERE assigned_officer_id = %d AND status IN (%s, %s)",
				$user_id,
				Status::ASSIGNED->value,
				Status::UNDER_REVIEW->value
			)
		);
	}
}
