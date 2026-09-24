<?php
/**
 * The registration state machine (ARCH §2, §26; Decisions §5).
 *
 * Pure rules only — no persistence, no current-user lookups. The workflow
 * service (Phase 5) uses it to validate a transition before writing,
 * auditing and notifying (MP §8).
 *
 *   PENDING      --ASSIGN-------> ASSIGNED
 *   ASSIGNED     --REASSIGN-----> ASSIGNED        (officer changes)
 *   UNDER_REVIEW --REASSIGN-----> ASSIGNED        (new officer starts own review, OD-16)
 *   ASSIGNED     --START_REVIEW-> UNDER_REVIEW    (assigned officer only, Decisions §18)
 *   UNDER_REVIEW --APPROVE------> APPROVED        (terminal, locked)
 *   UNDER_REVIEW --DISAPPROVE---> DISAPPROVED     (reason required, Decisions §19)
 *   DISAPPROVED  --RESTORE------> PENDING         (assignment cleared, ARCH §3)
 *   DISAPPROVED  --DELETE-------> DELETED         (tombstone, Decisions §21)
 *
 * Anything else is rejected, including APPROVED -> DISAPPROVED (Decisions §5).
 * Approve/disapprove are assignee-only (OD-17, ARCH §27 "verify permission + assignment").
 *
 * @package DMS
 */

namespace DMS\Workflow;

defined( 'ABSPATH' ) || exit;

final class StateMachine {

	public const DISAPPROVAL_REASON_MIN_LENGTH = 5;

	/**
	 * @return array<string,array{from:list<Status>,to:Status,permission:string,assignee_only:bool,requires_reason:bool}>
	 */
	private static function rules(): array {
		return array(
			Transition::ASSIGN->value       => array(
				'from'            => array( Status::PENDING ),
				'to'              => Status::ASSIGNED,
				'permission'      => 'assignment.assign',
				'assignee_only'   => false,
				'requires_reason' => false,
			),
			Transition::REASSIGN->value     => array(
				'from'            => array( Status::ASSIGNED, Status::UNDER_REVIEW ),
				'to'              => Status::ASSIGNED,
				'permission'      => 'assignment.reassign',
				'assignee_only'   => false,
				'requires_reason' => false,
			),
			Transition::START_REVIEW->value => array(
				'from'            => array( Status::ASSIGNED ),
				'to'              => Status::UNDER_REVIEW,
				'permission'      => 'review.view',
				'assignee_only'   => true,
				'requires_reason' => false,
			),
			Transition::APPROVE->value      => array(
				'from'            => array( Status::UNDER_REVIEW ),
				'to'              => Status::APPROVED,
				'permission'      => 'review.approve',
				'assignee_only'   => true,
				'requires_reason' => false,
			),
			Transition::DISAPPROVE->value   => array(
				'from'            => array( Status::UNDER_REVIEW ),
				'to'              => Status::DISAPPROVED,
				'permission'      => 'review.disapprove',
				'assignee_only'   => true,
				'requires_reason' => true,
			),
			Transition::RESTORE->value      => array(
				'from'            => array( Status::DISAPPROVED ),
				'to'              => Status::PENDING,
				'permission'      => 'bin.restore',
				'assignee_only'   => false,
				'requires_reason' => false,
			),
			Transition::DELETE->value       => array(
				'from'            => array( Status::DISAPPROVED ),
				'to'              => Status::DELETED,
				'permission'      => 'bin.delete',
				'assignee_only'   => false,
				'requires_reason' => false,
			),
		);
	}

	public function can( Status $from, Transition $transition ): bool {
		return in_array( $from, self::rules()[ $transition->value ]['from'], true );
	}

	/** @throws InvalidTransitionException */
	public function target( Status $from, Transition $transition ): Status {
		if ( ! $this->can( $from, $transition ) ) {
			throw new InvalidTransitionException( $from, $transition );
		}
		return self::rules()[ $transition->value ]['to'];
	}

	/** Permission a user actor needs. Automatic (system) assignment is not a user action. */
	public function permission( Transition $transition ): string {
		return self::rules()[ $transition->value ]['permission'];
	}

	/** Whether only the currently assigned officer may perform the transition. */
	public function assignee_only( Transition $transition ): bool {
		return self::rules()[ $transition->value ]['assignee_only'];
	}

	public function requires_reason( Transition $transition ): bool {
		return self::rules()[ $transition->value ]['requires_reason'];
	}

	public function is_valid_reason( ?string $reason ): bool {
		return null !== $reason && mb_strlen( trim( $reason ) ) >= self::DISAPPROVAL_REASON_MIN_LENGTH;
	}

	/** @return list<Transition> Transitions available from a state. */
	public function available_from( Status $from ): array {
		return array_values( array_filter( Transition::cases(), fn( Transition $t ): bool => $this->can( $from, $t ) ) );
	}
}
