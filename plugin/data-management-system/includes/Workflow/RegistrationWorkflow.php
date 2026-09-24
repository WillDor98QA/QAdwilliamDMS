<?php
/**
 * Staff actions on a registration (ARCH §7, §10, §71.2, §71.4; MP §8, §20; Decisions §5, §6, §18, §19, §21).
 *
 * Every action follows MP §8:
 *   1. authorize: permission (current_user_can) + object access (AccessPolicy)
 *      + assignee rule where the state machine requires it (Decisions §18, OD-17)
 *   2. validate the transition with StateMachine and the business rules
 *   3. write the change and its audit entry in one transaction
 *   4. send notifications after commit
 *
 * @package DMS
 */

namespace DMS\Workflow;

use DMS\Assignments\AssignmentExceptionRepository;
use DMS\Assignments\AssignmentService;
use DMS\Audit\ActorType;
use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Transaction;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Forms\FormValidator;
use DMS\Notifications\NotificationService;
use DMS\Registrations\AccessPolicy;
use DMS\Registrations\RegistrationFields;
use DMS\Registrations\RegistrationRepository;
use DMS\Support\Authorizer;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\RequestContext;

defined( 'ABSPATH' ) || exit;

class RegistrationWorkflow {

	public const NOTE_MAX_LENGTH = 2000;

	public function __construct(
		private \wpdb $db,
		private Clock $clock,
		private RegistrationRepository $registrations,
		private StateMachine $machine,
		private AccessPolicy $access,
		private FormValidator $validator,
		private AssignmentService $assignments,
		private AssignmentExceptionRepository $exceptions,
		private NotificationService $notifications,
		private AuditService $audit,
		private Authorizer $authorizer,
		private Logger $logger,
		private RequestContext $context,
	) {
	}

	/** Opens a record for reading. Viewing never changes status (Decisions §18); it is audited. */
	public function view( int $id ): object {
		$registration = $this->visible( $id );
		$this->audit->record( AuditAction::VIEWED, array( 'registration_id' => $id ) );
		return $registration;
	}

	/** ASSIGNED → UNDER_REVIEW, only by the assigned officer. Re-opening one's own review is a no-op. */
	public function start_review( int $id ): object {
		$this->authorizer->require( $this->machine->permission( Transition::START_REVIEW ) );
		$registration = $this->visible( $id );
		$actor        = $this->context->user_id();

		if ( Status::UNDER_REVIEW->value === $registration->status && (int) $registration->assigned_officer_id === $actor ) {
			return $registration;
		}
		$this->transition_guard( $registration, Transition::START_REVIEW );

		Transaction::run(
			$this->db,
			function () use ( $id ): void {
				$this->registrations->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW, array( 'reviewed_at' => $this->clock->now_mysql() ) );
				$this->audit->record(
					AuditAction::REVIEW_STARTED,
					array(
						'registration_id' => $id,
						'old_status'      => Status::ASSIGNED->value,
						'new_status'      => Status::UNDER_REVIEW->value,
					)
				);
			}
		);
		return $this->registrations->get( $id );
	}

	/**
	 * Edits a Holding-Area record. Every changed field is audited with old and new
	 * values. Attempts on approved records are rejected and audited (ARCH §78.7).
	 *
	 * @param array<string,mixed> $changes Field key => new value (form-definition keys).
	 * @return array<string,array{old:mixed,new:mixed}> What changed.
	 */
	public function edit( int $id, array $changes, string $reason = '' ): array {
		$this->authorizer->require( 'registrations.edit' );
		$registration = $this->visible( $id );
		$status       = Status::from( $registration->status );

		if ( ! $status->is_editable() ) {
			$this->audit->record(
				AuditAction::EDIT_REJECTED,
				array(
					'registration_id' => $id,
					'old_status'      => $status->value,
					'metadata'        => array( 'fields' => array_keys( $changes ) ),
				)
			);
			throw new ConflictException( __( 'This registration can no longer be edited.', 'dms' ), 'record_locked' );
		}

		$clean = $this->validator->validate_changes( $changes );
		if ( isset( $clean['extra_fields'] ) ) {
			$existing              = json_decode( (string) $registration->extra_fields, true );
			$merged                = array_merge( is_array( $existing ) ? $existing : array(), $clean['extra_fields'] );
			$clean['extra_fields'] = wp_json_encode( array_filter( $merged, static fn( $v ): bool => null !== $v ) );
		}
		$reason = sanitize_textarea_field( $reason );

		return Transaction::run(
			$this->db,
			function () use ( $id, $clean, $reason, $status ): array {
				$diff = $this->registrations->update_fields( $id, $clean );
				if ( array() !== $diff ) {
					$this->audit->record(
						AuditAction::EDITED,
						array(
							'registration_id' => $id,
							'old_status'      => $status->value,
							'new_status'      => $status->value,
							'reason'          => '' !== $reason ? $reason : null,
							'metadata'        => array( 'changes' => $diff ),
						)
					);
				}
				return $diff;
			}
		);
	}

	/** Officer note, stored in the append-only audit trail (implementation decision ID-28). */
	public function add_note( int $id, string $note ): void {
		$this->authorizer->require( 'review.add_note' );
		$this->visible( $id );
		$note = trim( sanitize_textarea_field( $note ) );
		if ( '' === $note || mb_strlen( $note ) > self::NOTE_MAX_LENGTH ) {
			/* translators: %d: maximum length */
			throw new ValidationException( array( 'note' => sprintf( __( 'Enter a note of up to %d characters.', 'dms' ), self::NOTE_MAX_LENGTH ) ) );
		}
		$this->audit->record(
			AuditAction::NOTE_ADDED,
			array(
				'registration_id' => $id,
				'reason'          => $note,
			)
		);
	}

	/** UNDER_REVIEW → APPROVED (locked). Approval email to the applicant (ARCH §76.2). */
	public function approve( int $id ): void {
		$this->authorizer->require( $this->machine->permission( Transition::APPROVE ) );
		$registration = $this->visible( $id );
		$this->transition_guard( $registration, Transition::APPROVE );

		$notification_ids = Transaction::run(
			$this->db,
			function () use ( $id ): array {
				$now = $this->clock->now_mysql();
				$this->registrations->transition(
					$id,
					Status::UNDER_REVIEW,
					Status::APPROVED,
					array(
						'approved_at' => $now,
						'approved_by' => $this->context->user_id(),
					)
				);
				$this->audit->record(
					AuditAction::APPROVED,
					array(
						'registration_id' => $id,
						'old_status'      => Status::UNDER_REVIEW->value,
						'new_status'      => Status::APPROVED->value,
					)
				);
				return $this->notifications->queue_registration_approved( $this->registrations->get( $id ) );
			}
		);
		$this->notifications->dispatch( $notification_ids );
	}

	/** UNDER_REVIEW → DISAPPROVED (Bin). Reason required; no applicant email (ARCH §76.3). */
	public function disapprove( int $id, string $reason ): void {
		$this->authorizer->require( $this->machine->permission( Transition::DISAPPROVE ) );
		$registration = $this->visible( $id );
		$reason       = trim( sanitize_textarea_field( $reason ) );
		if ( ! $this->machine->is_valid_reason( $reason ) ) {
			/* translators: %d: minimum length */
			throw new ValidationException( array( 'reason' => sprintf( __( 'Enter a reason of at least %d characters.', 'dms' ), StateMachine::DISAPPROVAL_REASON_MIN_LENGTH ) ) );
		}
		$this->transition_guard( $registration, Transition::DISAPPROVE );

		Transaction::run(
			$this->db,
			function () use ( $id, $reason ): void {
				$this->registrations->transition(
					$id,
					Status::UNDER_REVIEW,
					Status::DISAPPROVED,
					array(
						'disapproved_at'     => $this->clock->now_mysql(),
						'disapproved_by'     => $this->context->user_id(),
						'disapproval_reason' => $reason,
					)
				);
				$this->audit->record(
					AuditAction::DISAPPROVED,
					array(
						'registration_id' => $id,
						'old_status'      => Status::UNDER_REVIEW->value,
						'new_status'      => Status::DISAPPROVED->value,
						'reason'          => $reason,
					)
				);
			}
		);
	}

	/**
	 * DISAPPROVED → PENDING with the previous assignment cleared (ARCH §3, §10),
	 * then assigned afresh by the normal engine (OD-22).
	 */
	public function restore( int $id ): void {
		$this->authorizer->require( $this->machine->permission( Transition::RESTORE ) );
		$registration = $this->visible( $id );
		$this->transition_guard( $registration, Transition::RESTORE );

		Transaction::run(
			$this->db,
			function () use ( $id, $registration ): void {
				$this->registrations->transition(
					$id,
					Status::DISAPPROVED,
					Status::PENDING,
					array(
						'assigned_officer_id' => null,
						'assigned_at'         => null,
						'assigned_by'         => null,
						'assignment_method'   => null,
						'reviewed_at'         => null,
						'disapproved_at'      => null,
						'disapproved_by'      => null,
						'disapproval_reason'  => null,
					)
				);
				$this->audit->record(
					AuditAction::RESTORED,
					array(
						'registration_id' => $id,
						'old_status'      => Status::DISAPPROVED->value,
						'new_status'      => Status::PENDING->value,
						'metadata'        => array(
							'previous_officer_id'  => $registration->assigned_officer_id ? (int) $registration->assigned_officer_id : null,
							'previous_disapproval' => $registration->disapproved_at,
						),
					)
				);
			}
		);

		try {
			$this->assignments->auto_assign( $id );
		} catch ( \Throwable $e ) {
			// The restore is committed; assignment is retried by the scheduled job.
			$this->logger->error(
				'Assignment after restore failed',
				array(
					'registration_id' => $id,
					'error'           => $e->getMessage(),
				)
			);
		}
	}

	/** Manual permanent deletion from the Bin (bin.delete) — tombstone + PII scrub. */
	public function delete_permanently( int $id ): void {
		$this->authorizer->require( $this->machine->permission( Transition::DELETE ) );
		$registration = $this->visible( $id );
		$this->machine->target( Status::from( $registration->status ), Transition::DELETE );
		$this->purge( $id, $this->context->user_id(), AuditAction::DELETED );
	}

	/**
	 * Tombstones a Bin record and removes its personal data everywhere it was
	 * copied: registration row, audit metadata, notification log (Decisions §21).
	 * Safe to call twice: the second call finds no DISAPPROVED record and throws Conflict.
	 */
	public function purge( int $id, ?int $actor_id, string $action ): void {
		Transaction::run(
			$this->db,
			function () use ( $id, $actor_id, $action ): void {
				$registration = $this->registrations->get( $id );
				$this->registrations->tombstone( $id, $actor_id );
				$scrubbed = $this->audit->scrub_registration_pii( $id, RegistrationFields::PII );
				$this->notifications->scrub_registration( $id );
				$this->exceptions->resolve_for( $id, $actor_id );
				$this->audit->record(
					$action,
					array(
						'registration_id' => $id,
						'old_status'      => Status::DISAPPROVED->value,
						'new_status'      => Status::DELETED->value,
						'user_id'         => $actor_id,
						'actor_type'      => null === $actor_id ? ActorType::SYSTEM : ActorType::USER,
						'metadata'        => array(
							'registration_number'    => $registration->registration_number,
							'disapproved_at'         => $registration->disapproved_at,
							'audit_entries_scrubbed' => $scrubbed,
						),
					)
				);
			}
		);
	}

	/** Loads a registration the current user may see; otherwise "not found" (no existence leak). */
	private function visible( int $id ): object {
		$registration = $this->registrations->get( $id );
		$this->access->assert_can_view( $this->context->user_id(), $registration );
		return $registration;
	}

	/** State machine + assignee rule (Decisions §18, OD-17). */
	private function transition_guard( object $registration, Transition $transition ): void {
		$this->machine->target( Status::from( $registration->status ), $transition );
		if ( $this->machine->assignee_only( $transition ) && (int) $registration->assigned_officer_id !== $this->context->user_id() ) {
			throw new AuthorizationException( __( 'Only the officer assigned to this registration can do this.', 'dms' ) );
		}
	}
}
