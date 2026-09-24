<?php
/**
 * Analytics defined by ARCH §9 — nothing beyond that list (MP §41 Phase 9).
 *
 * Approved dataset (ARCH §9 "primarily approved/validated records"):
 *   total approved, approved this month, approved in the selected period,
 *   gender breakdown, approved by region (or by constituency within a region).
 * Operational figures also listed in ARCH §9:
 *   approval/disapproval counts and monthly trend, pending workload, officer workload.
 *
 * Decision counts come from the audit trail (APPROVED / DISAPPROVED events),
 * so a record that is later restored or deleted still counts as decided when
 * it was decided (implementation decision ID-52).
 *
 * Filters: period (from/to, site-local dates) and region. Results are cached
 * for 5 minutes per filter combination. Permission: analytics.view (checked
 * by callers; the page and export check it).
 *
 * @package DMS
 */

namespace DMS\Analytics;

use DMS\Audit\AuditAction;
use DMS\Database\Tables;
use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Support\Clock;
use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

class AnalyticsService {

	private const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	public function __construct( private \wpdb $db, private Clock $clock, private ElectoralRepository $electoral ) {
	}

	/**
	 * Normalised filters: from/to as site-local Y-m-d (default: last 12 months), region id or null.
	 *
	 * @param array<string,mixed> $input
	 * @return array{from:string,to:string,region_id:?int,from_utc:string,to_utc:string}
	 */
	public function filters( array $input ): array {
		$tz    = wp_timezone();
		$today = $this->clock->now()->setTimezone( $tz );
		$valid = static function ( $v ): ?string {
			// checkdate() rejects impossible dates such as 2026-02-30, which PHP would roll into March.
			return ( is_string( $v ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) ? $v : null;
		};
		$to    = $valid( $input['to'] ?? null ) ?? $today->format( 'Y-m-d' );
		$from  = $valid( $input['from'] ?? null ) ?? $today->modify( 'first day of this month' )->modify( '-11 months' )->format( 'Y-m-d' );
		if ( $from > $to ) {
			[ $from, $to ] = array( $to, $from );
		}
		// Not absint(): "-4" must mean "no region", never region 4.
		$region = is_numeric( $input['region_id'] ?? null ) ? (int) $input['region_id'] : 0;
		return array(
			'from'      => $from,
			'to'        => $to,
			'region_id' => $region > 0 ? $region : null,
			'from_utc'  => ( new \DateTimeImmutable( $from . ' 00:00:00', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			'to_utc'    => ( new \DateTimeImmutable( $to . ' 23:59:59', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Everything the Analytics screen shows, for one filter set.
	 *
	 * @param array<string,mixed> $input Raw filters.
	 * @return array<string,mixed>
	 */
	public function overview( array $input ): array {
		$f   = $this->filters( $input );
		$key = 'dms_analytics_' . md5( (string) wp_json_encode( array( $f['from'], $f['to'], $f['region_id'], (int) get_option( 'dms_analytics_version', 1 ) ) ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$data = array(
			'filters'   => $f,
			'totals'    => $this->totals( $f ),
			'gender'    => $this->by_gender( $f ),
			'locations' => $this->by_location( $f ),
			'trend'     => $this->monthly_decisions( $f ),
			'decisions' => $this->decisions( $f ),
			'pending'   => $this->pending( $f ),
			'officers'  => $this->officer_workload( $f ),
			'generated' => $this->clock->now_mysql(),
		);
		set_transient( $key, $data, self::CACHE_TTL );
		return $data;
	}

	/** Drops cached figures (called after workflow changes when fresh numbers are needed). */
	public static function invalidate(): void {
		update_option( 'dms_analytics_version', (int) get_option( 'dms_analytics_version', 1 ) + 1, false );
	}

	/** @return array{approved_total:int,approved_this_month:int,approved_in_period:int} */
	public function totals( array $f ): array {
		$month_start = $this->clock->now()->setTimezone( wp_timezone() )->modify( 'first day of this month' )->setTime( 0, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		return array(
			'approved_total'      => $this->count_approved( $f['region_id'], null, null ),
			'approved_this_month' => $this->count_approved( $f['region_id'], $month_start, null ),
			'approved_in_period'  => $this->count_approved( $f['region_id'], $f['from_utc'], $f['to_utc'] ),
		);
	}

	/** @return list<array{label:string,count:int}> Approved records by gender (current approved dataset). */
	public function by_gender( array $f ): array {
		[ $where, $params ] = $this->approved_where( $f['region_id'] );
		$t                  = Tables::name( Tables::REGISTRATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- fixed fragments with placeholders.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT COALESCE(NULLIF(gender, ''), '') AS g, COUNT(*) AS n FROM {$t} r WHERE {$where} GROUP BY g ORDER BY n DESC", ...$params ) );
		return array_map(
			static fn( object $r ): array => array(
				'label' => '' === $r->g ? __( 'Not stated', 'dms' ) : (string) $r->g,
				'count' => (int) $r->n,
			),
			$rows
		);
	}

	/**
	 * Approved by region, or by constituency when a region is selected.
	 * Every active area is listed, including those with zero approvals.
	 *
	 * @return array{level:string,rows:list<array{id:int,label:string,count:int}>}
	 */
	public function by_location( array $f ): array {
		$level              = null === $f['region_id'] ? ElectoralLevel::REGION : ElectoralLevel::CONSTITUENCY;
		$options            = $this->electoral->options( $level, $f['region_id'] );
		$column             = $level->registration_column();
		[ $where, $params ] = $this->approved_where( $f['region_id'] );
		$t                  = Tables::name( Tables::REGISTRATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$counts = $this->db->get_results( $this->db->prepare( "SELECT {$column} AS id, COUNT(*) AS n FROM {$t} r WHERE {$where} GROUP BY {$column}", ...$params ), OBJECT_K );
		$rows   = array();
		foreach ( $options as $o ) {
			$rows[] = array(
				'id'    => $o['id'],
				'label' => $o['name'],
				'count' => isset( $counts[ $o['id'] ] ) ? (int) $counts[ $o['id'] ]->n : 0,
			);
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $b['count'], $a['label'] ) <=> array( $a['count'], $b['label'] ) );
		return array(
			'level' => $level->value,
			'rows'  => $rows,
		);
	}

	/**
	 * Approvals and disapprovals per month in the period (ARCH §9 "approval trends",
	 * "monthly trends"). Months with no decisions are included as zero.
	 *
	 * @return list<array{month:string,approved:int,disapproved:int}>
	 */
	public function monthly_decisions( array $f ): array {
		$offset                    = $this->mysql_offset();
		[ $join, $where, $params ] = $this->decision_where( $f );
		$audit                     = Tables::name( Tables::AUDIT_LOG );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results( $this->db->prepare( "SELECT DATE_FORMAT(CONVERT_TZ(a.created_at, '+00:00', %s), '%%Y-%%m') AS m, a.action, COUNT(*) AS n FROM {$audit} a {$join} WHERE {$where} GROUP BY m, a.action", $offset, ...$params ) );

		$months = array();
		$cursor = new \DateTimeImmutable( substr( $f['from'], 0, 7 ) . '-01' );
		$end    = new \DateTimeImmutable( substr( $f['to'], 0, 7 ) . '-01' );
		$guard  = 0;
		while ( $cursor <= $end && $guard++ < 60 ) {
			$months[ $cursor->format( 'Y-m' ) ] = array(
				'month'       => $cursor->format( 'Y-m' ),
				'approved'    => 0,
				'disapproved' => 0,
			);
			$cursor                             = $cursor->modify( '+1 month' );
		}
		foreach ( $rows as $r ) {
			if ( isset( $months[ $r->m ] ) ) {
				$months[ $r->m ][ AuditAction::APPROVED === $r->action ? 'approved' : 'disapproved' ] = (int) $r->n;
			}
		}
		return array_values( $months );
	}

	/** @return array{approved:int,disapproved:int,approval_rate:?float} Decisions made in the period. */
	public function decisions( array $f ): array {
		$approved    = 0;
		$disapproved = 0;
		foreach ( $this->monthly_decisions( $f ) as $m ) {
			$approved    += $m['approved'];
			$disapproved += $m['disapproved'];
		}
		$total = $approved + $disapproved;
		return array(
			'approved'      => $approved,
			'disapproved'   => $disapproved,
			'approval_rate' => $total > 0 ? round( 100 * $approved / $total, 1 ) : null,
		);
	}

	/** @return array{pending:int,unassigned:int,assigned:int,under_review:int,open_exceptions:int,oldest_waiting_days:?int} Current pending workload. */
	public function pending( array $f ): array {
		$t      = Tables::name( Tables::REGISTRATIONS );
		$region = null === $f['region_id'] ? '' : $this->db->prepare( ' AND region_id = %d', $f['region_id'] );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $region is itself prepared.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT status, SUM(assigned_officer_id IS NULL) AS unassigned, COUNT(*) AS n, MIN(submitted_at) AS oldest FROM {$t} WHERE status IN (%s, %s, %s){$region} GROUP BY status", Status::PENDING->value, Status::ASSIGNED->value, Status::UNDER_REVIEW->value ), OBJECT_K );
		$ex   = Tables::name( Tables::ASSIGNMENT_EXCEPTIONS );
		$open = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$ex} WHERE status = %s{$region}", 'OPEN' ) );
		// phpcs:enable
		$oldest = null;
		foreach ( $rows as $r ) {
			$oldest = null === $oldest || $r->oldest < $oldest ? $r->oldest : $oldest;
		}
		return array(
			'pending'             => (int) ( $rows[ Status::PENDING->value ]->n ?? 0 ),
			'unassigned'          => (int) ( $rows[ Status::PENDING->value ]->unassigned ?? 0 ),
			'assigned'            => (int) ( $rows[ Status::ASSIGNED->value ]->n ?? 0 ),
			'under_review'        => (int) ( $rows[ Status::UNDER_REVIEW->value ]->n ?? 0 ),
			'open_exceptions'     => $open,
			'oldest_waiting_days' => null === $oldest ? null : (int) floor( ( $this->clock->now()->getTimestamp() - strtotime( $oldest . ' UTC' ) ) / DAY_IN_SECONDS ),
		);
	}

	/**
	 * Officer workload (ARCH §9): current active workload and decisions in the period.
	 *
	 * @return list<array{user_id:int,name:string,active:int,approved:int,disapproved:int}>
	 */
	public function officer_workload( array $f ): array {
		$t      = Tables::name( Tables::REGISTRATIONS );
		$region = null === $f['region_id'] ? '' : $this->db->prepare( ' AND region_id = %d', $f['region_id'] );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$active                    = $this->db->get_results( $this->db->prepare( "SELECT assigned_officer_id AS uid, COUNT(*) AS n FROM {$t} WHERE status IN (%s, %s) AND assigned_officer_id IS NOT NULL{$region} GROUP BY assigned_officer_id", Status::ASSIGNED->value, Status::UNDER_REVIEW->value ), OBJECT_K );
		[ $join, $where, $params ] = $this->decision_where( $f );
		$audit                     = Tables::name( Tables::AUDIT_LOG );
		$decided                   = $this->db->get_results( $this->db->prepare( "SELECT a.user_id AS uid, a.action, COUNT(*) AS n FROM {$audit} a {$join} WHERE {$where} AND a.user_id IS NOT NULL GROUP BY a.user_id, a.action", ...$params ) );
		// phpcs:enable

		$out = array();
		foreach ( $active as $uid => $r ) {
			$out[ (int) $uid ] = array(
				'active'      => (int) $r->n,
				'approved'    => 0,
				'disapproved' => 0,
			);
		}
		foreach ( $decided as $r ) {
			$uid           = (int) $r->uid;
			$out[ $uid ] ??= array(
				'active'      => 0,
				'approved'    => 0,
				'disapproved' => 0,
			);
			$out[ $uid ][ AuditAction::APPROVED === $r->action ? 'approved' : 'disapproved' ] = (int) $r->n;
		}
		$rows = array();
		foreach ( $out as $uid => $v ) {
			$user   = get_user_by( 'id', $uid );
			$rows[] = array(
				'user_id' => $uid,
				'name'    => $user ? (string) $user->display_name : '#' . $uid,
			) + $v;
		}
		usort( $rows, static fn( array $a, array $b ): int => array( $b['active'], $a['name'] ) <=> array( $a['active'], $b['name'] ) );
		return $rows;
	}

	private function count_approved( ?int $region_id, ?string $from_utc, ?string $to_utc ): int {
		[ $where, $params ] = $this->approved_where( $region_id );
		if ( null !== $from_utc ) {
			$where   .= ' AND r.approved_at >= %s';
			$params[] = $from_utc;
		}
		if ( null !== $to_utc ) {
			$where   .= ' AND r.approved_at <= %s';
			$params[] = $to_utc;
		}
		$t = Tables::name( Tables::REGISTRATIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$t} r WHERE {$where}", ...$params ) );
	}

	/** @return array{0:string,1:list<mixed>} */
	private function approved_where( ?int $region_id ): array {
		$where  = 'r.status = %s';
		$params = array( Status::APPROVED->value );
		if ( null !== $region_id ) {
			$where   .= ' AND r.region_id = %d';
			$params[] = $region_id;
		}
		return array( $where, $params );
	}

	/** @return array{0:string,1:string,2:list<mixed>} JOIN, WHERE and params for decisions in the period. */
	private function decision_where( array $f ): array {
		$join   = '';
		$where  = 'a.action IN (%s, %s) AND a.created_at BETWEEN %s AND %s';
		$params = array( AuditAction::APPROVED, AuditAction::DISAPPROVED, $f['from_utc'], $f['to_utc'] );
		if ( null !== $f['region_id'] ) {
			$join     = 'INNER JOIN ' . Tables::name( Tables::REGISTRATIONS ) . ' r ON r.id = a.registration_id';
			$where   .= ' AND r.region_id = %d';
			$params[] = $f['region_id'];
		}
		return array( $join, $where, $params );
	}

	/** Current site UTC offset as "+HH:MM" for CONVERT_TZ (no timezone tables needed). */
	private function mysql_offset(): string {
		$seconds = wp_timezone()->getOffset( $this->clock->now() );
		return sprintf( '%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv( abs( $seconds ), 3600 ), intdiv( abs( $seconds ) % 3600, 60 ) );
	}
}
