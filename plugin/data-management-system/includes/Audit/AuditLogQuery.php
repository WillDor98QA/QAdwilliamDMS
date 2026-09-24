<?php
/**
 * Read side of the audit trail for the Audit Logs screen (MP §21, audit.view).
 * Filtered and paginated on the server, with actor and registration joined in one query.
 *
 * @package DMS
 */

namespace DMS\Audit;

use DMS\Database\Tables;

defined( 'ABSPATH' ) || exit;

class AuditLogQuery {

	public const MAX_PER_PAGE = 100;

	public function __construct( private \wpdb $db ) {
	}

	/**
	 * @param array<string,mixed> $input action, object_type, user_id, registration_number, date_from, date_to, page, per_page.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function search( array $input ): array {
		$where  = array( '1 = 1' );
		$params = array();

		$action = strtoupper( sanitize_key( (string) ( $input['action'] ?? '' ) ) );
		if ( '' !== $action ) {
			$where[]  = 'a.action = %s';
			$params[] = $action;
		}
		$type = sanitize_key( (string) ( $input['object_type'] ?? '' ) );
		if ( '' !== $type ) {
			$where[]  = 'a.object_type = %s';
			$params[] = $type;
		}
		$user = absint( $input['user_id'] ?? 0 );
		if ( $user > 0 ) {
			$where[]  = 'a.user_id = %d';
			$params[] = $user;
		}
		$number = strtoupper( trim( sanitize_text_field( (string) ( $input['registration_number'] ?? '' ) ) ) );
		if ( '' !== $number ) {
			$where[]  = 'r.registration_number = %s';
			$params[] = $number;
		}
		foreach ( array(
			'date_from' => '>=',
			'date_to'   => '<=',
		) as $key => $op ) {
			$date = (string) ( $input[ $key ] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				$where[]  = "a.created_at {$op} %s";
				$params[] = $date . ( '>=' === $op ? ' 00:00:00' : ' 23:59:59' );
			}
		}

		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $input['per_page'] ?? 50 ) ) );
		$page     = max( 1, absint( $input['page'] ?? 1 ) );
		$audit    = Tables::name( Tables::AUDIT_LOG );
		$regs     = Tables::name( Tables::REGISTRATIONS );
		$from     = "{$audit} a LEFT JOIN {$regs} r ON r.id = a.registration_id LEFT JOIN {$this->db->users} u ON u.ID = a.user_id";
		$clause   = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- clauses are fixed fragments with placeholders.
		$items     = $this->db->get_results(
			$this->db->prepare( "SELECT a.*, r.registration_number, u.display_name AS actor_name FROM {$from} WHERE {$clause} ORDER BY a.id DESC LIMIT %d OFFSET %d", ...array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) )
		);
		$count_sql = "SELECT COUNT(*) FROM {$from} WHERE {$clause}";
		$total     = (int) ( array() === $params ? $this->db->get_var( $count_sql ) : $this->db->get_var( $this->db->prepare( $count_sql, ...$params ) ) );
		// phpcs:enable

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/** @return list<string> Distinct actions present, for the filter dropdown. */
	public function actions(): array {
		$audit = Tables::name( Tables::AUDIT_LOG );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_col( "SELECT DISTINCT action FROM {$audit} ORDER BY action" );
	}
}
