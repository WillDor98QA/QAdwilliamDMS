<?php
/**
 * Server-side search/filter criteria for registration lists (ARCH §73, Decisions §23).
 *
 * Built from untrusted request input by from_array(): every value is cast,
 * whitelisted or bounded here, so the repository only receives safe values.
 *
 * @package DMS
 */

namespace DMS\Registrations;

use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

final class RegistrationFilter {

	public const MAX_PER_PAGE = 100;

	/** Sortable columns: request key => SQL expression. */
	public const SORTABLE = array(
		'registration_number' => 'r.registration_number',
		'last_name'           => 'r.last_name',
		'submitted_at'        => 'r.submitted_at',
		'reviewed_at'         => 'r.reviewed_at',
		'approved_at'         => 'r.approved_at',
		'disapproved_at'      => 'r.disapproved_at',
		'status'              => 'r.status',
	);

	/** Date filters: request prefix => column. */
	public const DATE_COLUMNS = array(
		'submitted'   => 'submitted_at',
		'reviewed'    => 'reviewed_at',
		'approved'    => 'approved_at',
		'disapproved' => 'disapproved_at',
	);

	/** @var list<Status> */
	public array $statuses          = array();
	public string $search           = '';
	public ?int $region_id          = null;
	public ?int $constituency_id    = null;
	public ?int $polling_station_id = null;
	public ?int $officer_id         = null;
	public bool $unassigned_only    = false;

	/**
	 * Restricts results to records currently assigned to this user.
	 * Set by the access layer for users holding only registrations.view_assigned (Decisions §17).
	 */
	public ?int $scope_officer_id = null;

	/**
	 * Access scope from AccessPolicy::scope() (ID-27). When set, a row matches if its
	 * status is in $scope_statuses OR it is assigned to $scope_own_officer_id.
	 * null = unrestricted (registrations.view). An empty scope matches nothing.
	 *
	 * @var list<Status>|null
	 */
	public ?array $scope_statuses     = null;
	public ?int $scope_own_officer_id = null;

	/** @var list<int> Restrict to these registration IDs (bulk selection). Set internally, never from raw input. */
	public array $ids = array();

	/** @var array<string,array{from:?string,to:?string}> Keyed by DATE_COLUMNS prefix. Values 'Y-m-d'. */
	public array $dates = array();

	public int $page       = 1;
	public int $per_page   = 20;
	public string $orderby = 'submitted_at';
	public string $order   = 'DESC';

	/** @param array<string,mixed> $input Raw request parameters. */
	public static function from_array( array $input ): self {
		$filter = new self();

		$statuses = $input['status'] ?? array();
		foreach ( (array) $statuses as $status ) {
			$case = Status::tryFrom( strtoupper( (string) $status ) );
			if ( null !== $case && Status::DELETED !== $case ) {
				$filter->statuses[] = $case;
			}
		}

		$filter->search = mb_substr( trim( sanitize_text_field( (string) ( $input['search'] ?? '' ) ) ), 0, 100 );

		foreach ( array( 'region_id', 'constituency_id', 'polling_station_id', 'officer_id' ) as $key ) {
			$value          = absint( $input[ $key ] ?? 0 );
			$filter->{$key} = $value > 0 ? $value : null;
		}
		$filter->unassigned_only = ! empty( $input['unassigned'] );

		foreach ( array_keys( self::DATE_COLUMNS ) as $prefix ) {
			$from = self::date_or_null( $input[ "{$prefix}_from" ] ?? null );
			$to   = self::date_or_null( $input[ "{$prefix}_to" ] ?? null );
			if ( null !== $from || null !== $to ) {
				$filter->dates[ $prefix ] = array(
					'from' => $from,
					'to'   => $to,
				);
			}
		}

		$filter->page     = max( 1, absint( $input['page'] ?? 1 ) );
		$filter->per_page = min( self::MAX_PER_PAGE, max( 1, absint( $input['per_page'] ?? 20 ) ) );
		$orderby          = (string) ( $input['orderby'] ?? '' );
		$filter->orderby  = isset( self::SORTABLE[ $orderby ] ) ? $orderby : 'submitted_at';
		$filter->order    = 'ASC' === strtoupper( (string) ( $input['order'] ?? '' ) ) ? 'ASC' : 'DESC';

		return $filter;
	}

	/** Workspace areas (ARCH §2, §24) → statuses. */
	public const AREAS = array(
		'holding'      => array( 'PENDING', 'ASSIGNED', 'UNDER_REVIEW' ),
		'assigned'     => array( 'ASSIGNED' ),
		'under_review' => array( 'UNDER_REVIEW' ),
		'approved'     => array( 'APPROVED' ),
		'bin'          => array( 'DISAPPROVED' ),
	);

	/**
	 * Limits the filter to an area; a status filter inside the area narrows it further.
	 */
	public function restrict_to_area( string $area ): void {
		$allowed        = array_map( static fn( string $s ): Status => Status::from( $s ), self::AREAS[ $area ] ?? array() );
		$wanted         = array_values( array_filter( $this->statuses, static fn( Status $s ): bool => in_array( $s, $allowed, true ) ) );
		$this->statuses = array() !== $wanted ? $wanted : $allowed;
	}

	/**
	 * Applies the user's access scope (AccessPolicy::scope()).
	 *
	 * @param array{all:bool,statuses:list<Status>,own_assigned:bool} $scope
	 */
	public function apply_scope( array $scope, int $user_id ): void {
		if ( $scope['all'] ) {
			$this->scope_statuses       = null;
			$this->scope_own_officer_id = null;
			return;
		}
		$this->scope_statuses       = $scope['statuses'];
		$this->scope_own_officer_id = $scope['own_assigned'] ? $user_id : null;
	}

	private static function date_or_null( mixed $value ): ?string {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : null;
	}
}
