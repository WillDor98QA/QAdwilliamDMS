<?php
/**
 * Data access for the one authoritative registration record (ARCH §2, §37).
 *
 * Integrity rules enforced here, below any UI or API (ARCH §78.7, MP §33):
 * - create() rejects a broken Region → Constituency → Polling Station chain,
 *   and the UNIQUE phone_normalized index rejects duplicates, even under a race.
 * - update_fields() only touches EDITABLE columns and only while the record is
 *   in the Holding Area. Its WHERE clause includes the status, so an approved
 *   record cannot be modified even if it was approved a moment earlier.
 * - transition() changes status only when the row is still in the expected
 *   state (optimistic concurrency), so two officers cannot both decide.
 * - tombstone() nulls personal data for DISAPPROVED records only.
 *
 * Audit entries and permission checks belong to the calling services.
 *
 * @package DMS
 */

namespace DMS\Registrations;

use DMS\Database\SequenceGenerator;
use DMS\Database\Tables;
use DMS\Database\Transaction;
use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Errors\ConflictException;
use DMS\Errors\NotFoundException;
use DMS\Errors\ValidationException;
use DMS\Support\Clock;
use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

class RegistrationRepository {

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private SequenceGenerator $sequences,
		private ElectoralRepository $electoral,
	) {
	}

	private function table(): string {
		return Tables::name( Tables::REGISTRATIONS );
	}

	/**
	 * Creates a PENDING registration with a new REG-YYYY-NNNNNN number.
	 * Input must already be validated and sanitized by the submission service.
	 *
	 * @param array<string,mixed> $data
	 * @param bool                $require_verified_phone True unless OTP verification is switched
	 *                                                    off (CR-01). Consent is always required.
	 * @return int New registration ID.
	 * @throws ValidationException Broken electoral chain or missing verified phone/consent.
	 * @throws ConflictException   Phone number already registered.
	 */
	public function create( array $data, bool $require_verified_phone = true ): int {
		foreach ( array( 'phone_normalized', 'region_id', 'constituency_id', 'polling_station_id' ) as $required ) {
			if ( empty( $data[ $required ] ) ) {
				throw new ValidationException( array( $required => __( 'This field is required.', 'dms' ) ) );
			}
		}
		if ( empty( $data['consent_given'] ) ) {
			// ARCH §78.1 — never create a registration without consent.
			throw new ValidationException( array( 'consent' => __( 'Consent is required.', 'dms' ) ) );
		}
		if ( $require_verified_phone && empty( $data['phone_verified'] ) ) {
			// ARCH §78.2 while OTP verification is ON (CR-01).
			throw new ValidationException( array( 'phone' => __( 'Phone verification is required.', 'dms' ) ) );
		}
		if ( ! $require_verified_phone ) {
			$data['phone_verified']    = 0;
			$data['phone_verified_at'] = null;
		}
		if ( ! $this->electoral->is_valid_chain( (int) $data['region_id'], (int) $data['constituency_id'], (int) $data['polling_station_id'] ) ) {
			throw new ValidationException( array( 'polling_station_id' => __( 'Please select a valid Region, Constituency and Polling Station.', 'dms' ) ) );
		}

		$columns = array_merge(
			RegistrationFields::PII,
			RegistrationFields::ELECTORAL,
			array( 'phone_verified', 'phone_verified_at', 'consent_given', 'consent_given_at' )
		);
		$row     = array_intersect_key( $data, array_flip( $columns ) );
		if ( isset( $row['extra_fields'] ) && is_array( $row['extra_fields'] ) ) {
			$row['extra_fields'] = wp_json_encode( $row['extra_fields'] );
		}

		return Transaction::run(
			$this->db,
			function () use ( $row ): int {
				$now                        = $this->clock->now_mysql();
				$row['registration_number'] = $this->sequences->next_registration_number();
				$row['status']              = Status::PENDING->value;
				$row['submitted_at']        = $now;
				$row['created_at']          = $now;
				$row['updated_at']          = $now;

				$suppress = $this->db->suppress_errors( true );
				$ok       = $this->db->insert( $this->table(), $row );
				$this->db->suppress_errors( $suppress );

				if ( false === $ok ) {
					if ( str_contains( $this->db->last_error, 'Duplicate entry' ) && str_contains( $this->db->last_error, 'phone_normalized' ) ) {
						throw new ConflictException( __( 'This phone number has already been used for a registration. If you believe this is an error, please contact support.', 'dms' ), 'duplicate_phone' );
					}
					throw new \RuntimeException( 'Could not create registration: ' . $this->db->last_error );
				}
				return (int) $this->db->insert_id;
			}
		);
	}

	public function find( int $id ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
	}

	/** @throws NotFoundException */
	public function get( int $id ): object {
		$row = $this->find( $id );
		if ( null === $row ) {
			throw new NotFoundException();
		}
		return $row;
	}

	public function find_by_number( string $registration_number ): ?object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE registration_number = %s", $registration_number ) );
	}

	/** Duplicate check on the canonical phone (ARCH §71.1). Internal use only — never reveal to the public before OTP. */
	public function phone_exists( string $phone_normalized ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $this->db->get_var( $this->db->prepare( "SELECT 1 FROM {$this->table()} WHERE phone_normalized = %s LIMIT 1", $phone_normalized ) );
	}

	/**
	 * Updates editable fields of a Holding-Area record.
	 *
	 * @param array<string,mixed> $changes Column => new value (validated by the caller).
	 * @return array<string,array{old:mixed,new:mixed}> The columns that actually changed.
	 * @throws ConflictException   Record is approved/disapproved/deleted, or changed concurrently.
	 * @throws ValidationException Non-editable column, or electoral chain broken by the change.
	 */
	public function update_fields( int $id, array $changes ): array {
		$illegal = array_diff( array_keys( $changes ), RegistrationFields::EDITABLE );
		if ( array() !== $illegal ) {
			throw new ValidationException( array_fill_keys( $illegal, __( 'This field cannot be edited.', 'dms' ) ) );
		}
		if ( isset( $changes['extra_fields'] ) && is_array( $changes['extra_fields'] ) ) {
			$changes['extra_fields'] = wp_json_encode( $changes['extra_fields'] );
		}

		return Transaction::run(
			$this->db,
			function () use ( $id, $changes ): array {
				$current = $this->lock( $id );
				$status  = Status::from( $current->status );
				if ( ! $status->is_editable() ) {
					throw new ConflictException( __( 'This registration can no longer be edited.', 'dms' ), 'record_locked' );
				}

				$diff = array();
				foreach ( $changes as $column => $value ) {
					if ( (string) $current->{$column} !== (string) $value ) {
						$diff[ $column ] = array(
							'old' => $current->{$column},
							'new' => $value,
						);
					}
				}
				if ( array() === $diff ) {
					return array();
				}

				$merged = array();
				foreach ( RegistrationFields::ELECTORAL as $column ) {
					$merged[ $column ] = (int) ( $changes[ $column ] ?? $current->{$column} );
				}
				if ( array_intersect_key( $diff, array_flip( RegistrationFields::ELECTORAL ) )
					&& ! $this->electoral->is_valid_chain( $merged['region_id'], $merged['constituency_id'], $merged['polling_station_id'] ) ) {
					throw new ValidationException( array( 'polling_station_id' => __( 'Please select a valid Region, Constituency and Polling Station.', 'dms' ) ) );
				}

				$set               = array_map( static fn( array $d ): mixed => $d['new'], $diff );
				$set['updated_at'] = $this->clock->now_mysql();
				$this->guarded_update( $id, $set, Status::holding_area() );
				return $diff;
			}
		);
	}

	/**
	 * Moves a record from $from to $to, writing the given workflow columns.
	 * The caller (workflow service) has already validated the transition.
	 *
	 * @param array<string,mixed> $columns e.g. approved_at, approved_by.
	 * @throws ConflictException When the record is no longer in $from.
	 */
	public function transition( int $id, Status $from, Status $to, array $columns = array() ): void {
		$allowed = array( 'assigned_officer_id', 'assigned_at', 'assigned_by', 'assignment_method', 'reviewed_at', 'approved_at', 'approved_by', 'disapproved_at', 'disapproved_by', 'disapproval_reason' );
		$illegal = array_diff( array_keys( $columns ), $allowed );
		if ( array() !== $illegal ) {
			throw new \InvalidArgumentException( 'Not a workflow column: ' . implode( ', ', $illegal ) );
		}
		$columns['status']     = $to->value;
		$columns['updated_at'] = $this->clock->now_mysql();
		$this->guarded_update( $id, $columns, array( $from ) );
	}

	/**
	 * Permanent deletion as a tombstone (Decisions §21): personal data nulled,
	 * reference number, electoral IDs and timestamps kept. DISAPPROVED only.
	 *
	 * @throws ConflictException When the record is not in the Bin.
	 */
	public function tombstone( int $id, ?int $deleted_by ): void {
		$columns = array_fill_keys( RegistrationFields::PII, null );
		$now     = $this->clock->now_mysql();

		$columns['phone_verified'] = 0;
		$columns['status']         = Status::DELETED->value;
		$columns['deleted_at']     = $now;
		$columns['deleted_by']     = $deleted_by;
		$columns['updated_at']     = $now;
		$this->guarded_update( $id, $columns, array( Status::DISAPPROVED ) );
	}

	/**
	 * IDs of Bin records disapproved on or before the cutoff (retention, ARCH §71.4).
	 *
	 * @return list<int>
	 */
	public function disapproved_before( string $cutoff_utc, int $limit = 200 ): array {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			'intval',
			$this->db->get_col(
				$this->db->prepare(
					"SELECT id FROM {$this->table()} WHERE status = %s AND disapproved_at <= %s ORDER BY disapproved_at ASC LIMIT %d",
					Status::DISAPPROVED->value,
					$cutoff_utc,
					$limit
				)
			)
		);
	}

	/**
	 * Server-side paginated search (ARCH §73). One query for rows with joined
	 * names (no N+1) and one for the total.
	 *
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function search( RegistrationFilter $filter ): array {
		[ $where, $params ] = $this->where( $filter );

		$r       = $this->table();
		$regions = ElectoralLevel::REGION->table();
		$consts  = ElectoralLevel::CONSTITUENCY->table();
		$station = ElectoralLevel::POLLING_STATION->table();
		$users   = $this->db->users;
		$order   = RegistrationFilter::SORTABLE[ $filter->orderby ] . ' ' . $filter->order . ', r.id ' . $filter->order;
		$offset  = ( $filter->page - 1 ) * $filter->per_page;

		$sql = "SELECT r.*, rg.name AS region_name, c.name AS constituency_name, p.name AS polling_station_name,
				u.display_name AS officer_name
			FROM {$r} r
			INNER JOIN {$regions} rg ON rg.id = r.region_id
			INNER JOIN {$consts} c ON c.id = r.constituency_id
			INNER JOIN {$station} p ON p.id = r.polling_station_id
			LEFT JOIN {$users} u ON u.ID = r.assigned_officer_id
			WHERE {$where}
			ORDER BY {$order}
			LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds only placeholders; values are in $params.
		$items = $this->db->get_results( $this->db->prepare( $sql, ...array_merge( $params, array( $filter->per_page, $offset ) ) ) );
		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$r} r WHERE {$where}", ...$params ) );
		// phpcs:enable

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $filter->page,
			'per_page' => $filter->per_page,
		);
	}

	/** @return array{0:string,1:list<mixed>} WHERE clause with placeholders, and its values. */
	private function where( RegistrationFilter $filter ): array {
		$clauses = array( 'r.status <> %s' );
		$params  = array( Status::DELETED->value );

		if ( array() !== $filter->statuses ) {
			$clauses[] = 'r.status IN (' . implode( ',', array_fill( 0, count( $filter->statuses ), '%s' ) ) . ')';
			foreach ( $filter->statuses as $status ) {
				$params[] = $status->value;
			}
		}
		foreach ( array( 'region_id', 'constituency_id', 'polling_station_id' ) as $column ) {
			if ( null !== $filter->{$column} ) {
				$clauses[] = "r.{$column} = %d";
				$params[]  = $filter->{$column};
			}
		}
		if ( null !== $filter->officer_id ) {
			$clauses[] = 'r.assigned_officer_id = %d';
			$params[]  = $filter->officer_id;
		}
		if ( $filter->unassigned_only ) {
			$clauses[] = 'r.assigned_officer_id IS NULL';
		}
		if ( null !== $filter->scope_officer_id ) {
			$clauses[] = 'r.assigned_officer_id = %d';
			$params[]  = $filter->scope_officer_id;
		}
		if ( array() !== $filter->ids ) {
			$clauses[] = 'r.id IN (' . implode( ',', array_fill( 0, count( $filter->ids ), '%d' ) ) . ')';
			$params    = array_merge( $params, array_map( 'intval', $filter->ids ) );
		}
		if ( null !== $filter->scope_statuses ) {
			$or = array();
			if ( array() !== $filter->scope_statuses ) {
				$or[] = 'r.status IN (' . implode( ',', array_fill( 0, count( $filter->scope_statuses ), '%s' ) ) . ')';
				foreach ( $filter->scope_statuses as $status ) {
					$params[] = $status->value;
				}
			}
			if ( null !== $filter->scope_own_officer_id ) {
				$or[]     = 'r.assigned_officer_id = %d';
				$params[] = $filter->scope_own_officer_id;
			}
			$clauses[] = array() === $or ? '1 = 0' : '(' . implode( ' OR ', $or ) . ')';
		}
		foreach ( $filter->dates as $prefix => $range ) {
			$column = RegistrationFilter::DATE_COLUMNS[ $prefix ];
			if ( null !== $range['from'] ) {
				$clauses[] = "r.{$column} >= %s";
				$params[]  = $range['from'] . ' 00:00:00';
			}
			if ( null !== $range['to'] ) {
				$clauses[] = "r.{$column} <= %s";
				$params[]  = $range['to'] . ' 23:59:59';
			}
		}
		if ( '' !== $filter->search ) {
			$like   = '%' . $this->db->esc_like( $filter->search ) . '%';
			$prefix = $this->db->esc_like( $filter->search ) . '%';
			$or     = array(
				'r.registration_number LIKE %s',
				'r.first_name LIKE %s',
				'r.middle_name LIKE %s',
				'r.last_name LIKE %s',
				'r.email LIKE %s',
				'r.organization LIKE %s',
				"CONCAT_WS(' ', r.first_name, r.last_name) LIKE %s",
			);
			$params = array_merge( $params, array( $prefix, $like, $like, $like, $like, $like, $like ) );
			$phone  = PhoneNormalizer::normalize( $filter->search );
			if ( null !== $phone ) {
				$or[]     = 'r.phone_normalized = %s';
				$params[] = $phone;
			}
			$clauses[] = '(' . implode( ' OR ', $or ) . ')';
		}

		return array( implode( ' AND ', $clauses ), $params );
	}

	/** Row lock for read-modify-write inside a transaction. */
	private function lock( int $id ): object {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table()} WHERE id = %d FOR UPDATE", $id ) );
		if ( null === $row ) {
			throw new NotFoundException();
		}
		return $row;
	}

	/**
	 * UPDATE … WHERE id = ? AND status IN (…). Zero affected rows means the
	 * record is missing or no longer in an allowed state.
	 *
	 * @param array<string,mixed> $set
	 * @param list<Status>        $allowed_statuses
	 */
	private function guarded_update( int $id, array $set, array $allowed_statuses ): void {
		$assignments = array();
		$values      = array();
		foreach ( $set as $column => $value ) {
			if ( null === $value ) {
				$assignments[] = "`{$column}` = NULL";
				continue;
			}
			$assignments[] = "`{$column}` = " . ( is_int( $value ) ? '%d' : '%s' );
			$values[]      = $value;
		}
		$status_placeholders = implode( ',', array_fill( 0, count( $allowed_statuses ), '%s' ) );
		$values[]            = $id;
		foreach ( $allowed_statuses as $status ) {
			$values[] = $status->value;
		}

		$sql = "UPDATE {$this->table()} SET " . implode( ', ', $assignments ) . " WHERE id = %d AND status IN ({$status_placeholders})";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- column names come from whitelists above; values are placeholders.
		$affected = $this->db->query( $this->db->prepare( $sql, ...$values ) );
		if ( false === $affected ) {
			throw new \RuntimeException( 'Registration update failed: ' . $this->db->last_error );
		}
		if ( 0 === $affected ) {
			$row = $this->find( $id );
			if ( null === $row ) {
				throw new NotFoundException();
			}
			// MySQL reports 0 when the row matched but every value was already identical.
			if ( in_array( Status::tryFrom( $row->status ), $allowed_statuses, true ) ) {
				return;
			}
			throw new ConflictException( __( 'This registration was changed by someone else or is no longer in a state that allows this action. Please reload and try again.', 'dms' ), 'stale_state' );
		}
	}
}
