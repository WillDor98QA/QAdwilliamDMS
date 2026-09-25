<?php
/**
 * "No active officer available" exceptions (ARCH §72.4, Decisions §4).
 *
 * At most one OPEN exception per registration. Resolved (not deleted) when
 * the registration is assigned, so the history stays visible.
 *
 * @package DMS
 */

namespace DMS\Assignments;

use DMS\Database\Tables;
use DMS\Support\Clock;

defined( 'ABSPATH' ) || exit;

class AssignmentExceptionRepository {

	public const STATUS_OPEN     = 'OPEN';
	public const STATUS_RESOLVED = 'RESOLVED';

	public const REASON_NO_ACTIVE_OFFICER = 'NO_ACTIVE_OFFICER';

	public function __construct( private \wpdb $db, private Clock $clock ) {
	}

	private function table(): string {
		return Tables::name( Tables::ASSIGNMENT_EXCEPTIONS );
	}

	public function open_for( int $registration_id ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE registration_id = %d AND status = %s ORDER BY id DESC LIMIT 1", $registration_id, self::STATUS_OPEN ) );
	}

	/** @return array{id:int,created:bool} Existing open exception is reused. */
	public function open( int $registration_id, int $region_id, string $reason_code, string $details ): array {
		$existing = $this->open_for( $registration_id );
		if ( null !== $existing ) {
			return array(
				'id'      => (int) $existing->id,
				'created' => false,
			);
		}
		$ok = $this->db->insert(
			$this->table(),
			array(
				'registration_id' => $registration_id,
				'region_id'       => $region_id,
				'reason_code'     => $reason_code,
				'details'         => $details,
				'status'          => self::STATUS_OPEN,
				'created_at'      => $this->clock->now_mysql(),
			)
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not record assignment exception: ' . $this->db->last_error );
		}
		return array(
			'id'      => (int) $this->db->insert_id,
			'created' => true,
		);
	}

	/** @return int Number of exceptions resolved. */
	public function resolve_for( int $registration_id, ?int $resolved_by ): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table()} SET status = %s, resolved_at = %s, resolved_by = %d WHERE registration_id = %d AND status = %s",
				self::STATUS_RESOLVED,
				$this->clock->now_mysql(),
				(int) $resolved_by,
				$registration_id,
				self::STATUS_OPEN
			)
		);
		if ( false === $n ) {
			throw new \RuntimeException( 'Could not resolve assignment exception: ' . $this->db->last_error );
		}
		return (int) $n;
	}

	/** Number of open exceptions (for paging and counts). */
	public function count_open(): int {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE status = %s", self::STATUS_OPEN ) );
	}

	/** @return list<object> Open exceptions, oldest first, with registration number and region name. */
	public function open_list( int $limit = 100, int $offset = 0 ): array {
		$regs    = Tables::name( Tables::REGISTRATIONS );
		$regions = Tables::name( Tables::REGIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT e.*, r.registration_number, rg.name AS region_name FROM {$this->table()} e
				INNER JOIN {$regs} r ON r.id = e.registration_id
				INNER JOIN {$regions} rg ON rg.id = e.region_id
				WHERE e.status = %s ORDER BY e.created_at ASC, e.id ASC LIMIT %d OFFSET %d",
				self::STATUS_OPEN,
				$limit,
				max( 0, $offset )
			)
		);
	}
}
