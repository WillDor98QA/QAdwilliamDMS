<?php
/**
 * Automatic regional assignment and manual (re)assignment (ARCH §72, Decisions §4, OD-16).
 *
 * Automatic rule, in order:
 *   1. Candidates: users actively linked to the registration's Region, whose
 *      account is active, and who hold assignment.receive.
 *   2. Lowest active workload (ASSIGNED + UNDER_REVIEW).
 *   3. Tie: longest since their last new assignment (never assigned = longest).
 *   4. Still tied: lowest user ID, so the result is deterministic.
 * No candidate → stay PENDING, open an assignment exception, email admins.
 *
 * A per-region MySQL named lock serializes selection + assignment, so two
 * simultaneous submissions cannot both read the same "lowest" workload.
 * Every assignment is audited with method, reason, previous officer and the
 * workloads that were compared.
 *
 * @package DMS
 */

namespace DMS\Assignments;

use DMS\Audit\ActorType;
use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Transaction;
use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Errors\ValidationException;
use DMS\Notifications\NotificationService;
use DMS\Permissions\CapabilityManager;
use DMS\Registrations\RegistrationRepository;
use DMS\Support\Authorizer;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\RequestContext;
use DMS\Users\OfficerRegionRepository;
use DMS\Users\UserProfileRepository;
use DMS\Workflow\StateMachine;
use DMS\Workflow\Status;
use DMS\Workflow\Transition;

defined( 'ABSPATH' ) || exit;

class AssignmentService {

	public const METHOD_AUTOMATIC = 'AUTOMATIC';
	public const METHOD_MANUAL    = 'MANUAL';

	public const REASON_LOWEST_WORKLOAD = 'LOWEST_ACTIVE_WORKLOAD';
	public const REASON_TIE_BREAK       = 'TIE_BREAK_LONGEST_WITHOUT_ASSIGNMENT';
	public const REASON_TIE_BREAK_ID    = 'TIE_BREAK_LOWEST_USER_ID';

	private const LOCK_TIMEOUT = 10;

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private RegistrationRepository $registrations,
		private ElectoralRepository $electoral,
		private OfficerRegionRepository $officer_regions,
		private UserProfileRepository $profiles,
		private CapabilityManager $capabilities,
		private AssignmentExceptionRepository $exceptions,
		private StateMachine $machine,
		private NotificationService $notifications,
		private AuditService $audit,
		private Authorizer $authorizer,
		private Logger $logger,
		private RequestContext $context,
	) {
	}

	/**
	 * Eligible officers for a region with their workload, best candidate first.
	 *
	 * @return list<array{user_id:int,workload:int,last_assigned_at:?string}>
	 */
	public function ranked_candidates( int $region_id ): array {
		$ids   = array_values(
			array_filter(
				$this->officer_regions->active_user_ids_for_region( $region_id ),
				fn( int $id ): bool => $this->capabilities->user_has_permission( $id, 'assignment.receive' )
			)
		);
		$loads = $this->officer_regions->workloads( $ids );

		$ranked = array();
		foreach ( $loads as $user_id => $load ) {
			$ranked[] = array( 'user_id' => $user_id ) + $load;
		}
		usort(
			$ranked,
			static fn( array $a, array $b ): int => array( $a['workload'], $a['last_assigned_at'] ?? '', $a['user_id'] ) <=> array( $b['workload'], $b['last_assigned_at'] ?? '', $b['user_id'] )
		);
		return $ranked;
	}

	/**
	 * Automatically assigns one PENDING registration (system action).
	 *
	 * @return int|null Officer user ID, or null if it stayed PENDING (exception opened).
	 */
	public function auto_assign( int $registration_id ): ?int {
		$registration = $this->registrations->get( $registration_id );
		if ( Status::PENDING->value !== $registration->status ) {
			return $registration->assigned_officer_id ? (int) $registration->assigned_officer_id : null;
		}
		$region_id = (int) $registration->region_id;

		return $this->with_region_lock(
			$region_id,
			function () use ( $registration_id, $region_id ): ?int {
				$registration = $this->registrations->get( $registration_id );
				if ( Status::PENDING->value !== $registration->status ) {
					return $registration->assigned_officer_id ? (int) $registration->assigned_officer_id : null;
				}
				$ranked = $this->ranked_candidates( $region_id );
				if ( array() === $ranked ) {
					$this->record_exception( $registration );
					return null;
				}
				$officer_id = $ranked[0]['user_id'];
				$this->apply(
					$registration,
					Status::PENDING,
					$officer_id,
					self::METHOD_AUTOMATIC,
					self::reason( $ranked ),
					null,
					array_slice( $ranked, 0, 10 )
				);
				return $officer_id;
			}
		);
	}

	/**
	 * Tries to assign every PENDING registration in the given regions (e.g. after an
	 * officer is added or re-enabled, ARCH §72.4 "once an officer is configured").
	 *
	 * @param list<int> $region_ids Empty = all regions.
	 * @return array{assigned:int,unassigned:int}
	 */
	public function assign_pending( array $region_ids = array(), int $limit = 200 ): array {
		$assigned   = 0;
		$unassigned = 0;
		foreach ( $this->pending_ids( $region_ids, $limit ) as $id ) {
			try {
				null === $this->auto_assign( $id ) ? ++$unassigned : ++$assigned;
			} catch ( \Throwable $e ) {
				++$unassigned;
				$this->logger->error(
					'Automatic assignment failed',
					array(
						'registration_id' => $id,
						'error'           => $e->getMessage(),
					)
				);
			}
		}
		return array(
			'assigned'   => $assigned,
			'unassigned' => $unassigned,
		);
	}

	/** Manual assignment of a PENDING record (assignment.assign). */
	public function assign( int $registration_id, int $officer_id, string $reason = '' ): void {
		$this->authorizer->require( $this->machine->permission( Transition::ASSIGN ) );
		$registration = $this->registrations->get( $registration_id );
		$this->machine->target( Status::from( $registration->status ), Transition::ASSIGN );
		$this->assert_eligible( $officer_id, (int) $registration->region_id );

		$this->with_region_lock(
			(int) $registration->region_id,
			fn() => $this->apply( $registration, Status::PENDING, $officer_id, self::METHOD_MANUAL, sanitize_textarea_field( $reason ), $this->context->user_id(), array() )
		);
	}

	/**
	 * Reassigns an ASSIGNED or UNDER_REVIEW record to another eligible officer.
	 * The record returns to ASSIGNED and its review start is cleared (OD-16).
	 */
	public function reassign( int $registration_id, int $officer_id, string $reason = '' ): void {
		$this->authorizer->require( $this->machine->permission( Transition::REASSIGN ) );
		$registration = $this->registrations->get( $registration_id );
		$from         = Status::from( $registration->status );
		$this->machine->target( $from, Transition::REASSIGN );
		if ( (int) $registration->assigned_officer_id === $officer_id ) {
			throw new ValidationException( array( 'officer_id' => __( 'The registration is already assigned to this officer.', 'dms' ) ) );
		}
		$this->assert_eligible( $officer_id, (int) $registration->region_id );

		$this->with_region_lock(
			(int) $registration->region_id,
			fn() => $this->apply( $registration, $from, $officer_id, self::METHOD_MANUAL, sanitize_textarea_field( $reason ), $this->context->user_id(), array() )
		);
	}

	/** @throws ValidationException */
	private function assert_eligible( int $officer_id, int $region_id ): void {
		$eligible = array_column( $this->ranked_candidates( $region_id ), 'user_id' );
		if ( ! in_array( $officer_id, $eligible, true ) ) {
			throw new ValidationException( array( 'officer_id' => __( 'This officer cannot receive registrations for this region. Check that the account is active, has the officer permission and is linked to the region.', 'dms' ) ) );
		}
	}

	/**
	 * Writes the assignment, updates the tie-breaker timestamp, resolves any
	 * open exception, audits, and notifies the officer after commit.
	 *
	 * @param list<array<string,mixed>> $candidates
	 */
	private function apply( object $registration, Status $from, int $officer_id, string $method, string $reason, ?int $actor_id, array $candidates ): void {
		$now      = $this->clock->now_mysql();
		$previous = $registration->assigned_officer_id ? (int) $registration->assigned_officer_id : null;
		$is_new   = null === $previous;

		$notification_ids = Transaction::run(
			$this->db,
			function () use ( $registration, $from, $officer_id, $method, $reason, $actor_id, $candidates, $now, $previous, $is_new ): array {
				$id = (int) $registration->id;
				$this->registrations->transition(
					$id,
					$from,
					Status::ASSIGNED,
					array(
						'assigned_officer_id' => $officer_id,
						'assigned_at'         => $now,
						'assigned_by'         => $actor_id,
						'assignment_method'   => $method,
						'reviewed_at'         => null,
					)
				);
				$this->profiles->touch_last_assigned( $officer_id, $this->clock->now_mysql_precise() );
				$this->exceptions->resolve_for( $id, $actor_id );

				$this->audit->record(
					$is_new ? AuditAction::ASSIGNED : AuditAction::REASSIGNED,
					array(
						'registration_id' => $id,
						'old_status'      => $from->value,
						'new_status'      => Status::ASSIGNED->value,
						'reason'          => '' !== $reason ? $reason : null,
						'user_id'         => $actor_id,
						'actor_type'      => null === $actor_id ? ActorType::SYSTEM : ActorType::USER,
						'metadata'        => array(
							'officer_id'          => $officer_id,
							'previous_officer_id' => $previous,
							'method'              => $method,
							'region_id'           => (int) $registration->region_id,
							'candidates'          => $candidates,
						),
					)
				);
				return $this->notifications->queue_officer_assignment( $this->registrations->get( $id ), $officer_id );
			}
		);
		$this->notifications->dispatch( $notification_ids );
	}

	private function record_exception( object $registration ): void {
		$region      = $this->electoral->find( ElectoralLevel::REGION, (int) $registration->region_id );
		$region_name = $region ? (string) $region->name : '#' . $registration->region_id;

		$notification_ids = Transaction::run(
			$this->db,
			function () use ( $registration, $region_name ): array {
				$result = $this->exceptions->open( (int) $registration->id, (int) $registration->region_id, AssignmentExceptionRepository::REASON_NO_ACTIVE_OFFICER, 'No active officer is configured for this region.' );
				if ( ! $result['created'] ) {
					return array();
				}
				$this->audit->record(
					AuditAction::ASSIGNMENT_EXCEPTION,
					array(
						'registration_id' => (int) $registration->id,
						'user_id'         => null,
						'actor_type'      => ActorType::SYSTEM,
						'reason'          => AssignmentExceptionRepository::REASON_NO_ACTIVE_OFFICER,
						'metadata'        => array(
							'region_id'    => (int) $registration->region_id,
							'exception_id' => $result['id'],
						),
					)
				);
				return $this->notifications->queue_assignment_exception( $registration, $region_name );
			}
		);
		$this->notifications->dispatch( $notification_ids );
	}

	/** @param list<array{user_id:int,workload:int,last_assigned_at:?string}> $ranked */
	private static function reason( array $ranked ): string {
		if ( count( $ranked ) < 2 || $ranked[0]['workload'] !== $ranked[1]['workload'] ) {
			return self::REASON_LOWEST_WORKLOAD;
		}
		return ( $ranked[0]['last_assigned_at'] ?? '' ) !== ( $ranked[1]['last_assigned_at'] ?? '' ) ? self::REASON_TIE_BREAK : self::REASON_TIE_BREAK_ID;
	}

	/** @return list<int> */
	private function pending_ids( array $region_ids, int $limit ): array {
		$table      = \DMS\Database\Tables::name( \DMS\Database\Tables::REGISTRATIONS );
		$sql        = "SELECT id FROM {$table} WHERE status = %s";
		$params     = array( Status::PENDING->value );
		$region_ids = array_values( array_filter( array_map( 'intval', $region_ids ) ) );
		if ( array() !== $region_ids ) {
			$sql   .= ' AND region_id IN (' . implode( ',', array_fill( 0, count( $region_ids ), '%d' ) ) . ')';
			$params = array_merge( $params, $region_ids );
		}
		$sql     .= ' ORDER BY submitted_at ASC, id ASC LIMIT %d';
		$params[] = $limit;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragments with placeholders.
		return array_map( 'intval', $this->db->get_col( $this->db->prepare( $sql, ...$params ) ) );
	}

	/**
	 * @template T
	 * @param callable():T $work
	 * @return T
	 */
	private function with_region_lock( int $region_id, callable $work ): mixed {
		$name = 'dms_assign_' . $this->db->prefix . $region_id;
		if ( 1 !== (int) $this->db->get_var( $this->db->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) ) ) {
			throw new \RuntimeException( 'Could not acquire assignment lock for region ' . $region_id );
		}
		try {
			return $work();
		} finally {
			$this->db->query( $this->db->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}
}
