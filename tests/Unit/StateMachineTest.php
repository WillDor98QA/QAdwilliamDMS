<?php
/**
 * REQ-WF-001..004 — every valid and invalid transition (MP §39 "Workflow", ARCH §26).
 */

namespace DMS\Tests\Unit;

use DMS\Workflow\InvalidTransitionException;
use DMS\Workflow\StateMachine;
use DMS\Workflow\Status;
use DMS\Workflow\Transition;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase {

	/** The complete allowed set. Anything not listed must be rejected. */
	private const ALLOWED = array(
		'PENDING:ASSIGN'            => 'ASSIGNED',
		'ASSIGNED:REASSIGN'         => 'ASSIGNED',
		'UNDER_REVIEW:REASSIGN'     => 'ASSIGNED',
		'ASSIGNED:START_REVIEW'     => 'UNDER_REVIEW',
		'UNDER_REVIEW:APPROVE'      => 'APPROVED',
		'UNDER_REVIEW:DISAPPROVE'   => 'DISAPPROVED',
		'DISAPPROVED:RESTORE'       => 'PENDING',
		'DISAPPROVED:DELETE'        => 'DELETED',
	);

	/** @return iterable<string,array{Status,Transition}> All 6 x 7 combinations. */
	public static function all_combinations(): iterable {
		foreach ( Status::cases() as $status ) {
			foreach ( Transition::cases() as $transition ) {
				yield "{$status->value} + {$transition->value}" => array( $status, $transition );
			}
		}
	}

	/** @dataProvider all_combinations */
	public function test_transition_matrix( Status $from, Transition $transition ): void {
		$machine = new StateMachine();
		$key     = "{$from->value}:{$transition->value}";

		if ( isset( self::ALLOWED[ $key ] ) ) {
			$this->assertTrue( $machine->can( $from, $transition ) );
			$this->assertSame( self::ALLOWED[ $key ], $machine->target( $from, $transition )->value );
			return;
		}

		$this->assertFalse( $machine->can( $from, $transition ), "{$key} must be rejected" );
		$this->expectException( InvalidTransitionException::class );
		$machine->target( $from, $transition );
	}

	public function test_approved_is_terminal_and_cannot_be_disapproved(): void {
		$machine = new StateMachine();
		$this->assertSame( array(), $machine->available_from( Status::APPROVED ) );
		$this->assertFalse( $machine->can( Status::APPROVED, Transition::DISAPPROVE ) );
	}

	public function test_deleted_is_terminal(): void {
		$this->assertSame( array(), ( new StateMachine() )->available_from( Status::DELETED ) );
	}

	public function test_permissions_per_transition(): void {
		$machine = new StateMachine();
		$this->assertSame( 'assignment.assign', $machine->permission( Transition::ASSIGN ) );
		$this->assertSame( 'assignment.reassign', $machine->permission( Transition::REASSIGN ) );
		$this->assertSame( 'review.view', $machine->permission( Transition::START_REVIEW ) );
		$this->assertSame( 'review.approve', $machine->permission( Transition::APPROVE ) );
		$this->assertSame( 'review.disapprove', $machine->permission( Transition::DISAPPROVE ) );
		$this->assertSame( 'bin.restore', $machine->permission( Transition::RESTORE ) );
		$this->assertSame( 'bin.delete', $machine->permission( Transition::DELETE ) );
	}

	/** Decisions §18 (start review) and OD-17 (approve/disapprove). */
	public function test_review_and_decision_transitions_are_assignee_only(): void {
		$machine  = new StateMachine();
		$assignee = array( Transition::START_REVIEW, Transition::APPROVE, Transition::DISAPPROVE );
		foreach ( Transition::cases() as $t ) {
			$this->assertSame( in_array( $t, $assignee, true ), $machine->assignee_only( $t ), $t->value );
		}
	}

	/** OD-16: a record under review can be reassigned and returns to ASSIGNED. */
	public function test_under_review_reassign_returns_to_assigned(): void {
		$this->assertSame( Status::ASSIGNED, ( new StateMachine() )->target( Status::UNDER_REVIEW, Transition::REASSIGN ) );
	}

	public function test_disapproval_requires_reason_of_at_least_five_characters(): void {
		$machine = new StateMachine();
		$this->assertTrue( $machine->requires_reason( Transition::DISAPPROVE ) );
		$this->assertFalse( $machine->requires_reason( Transition::APPROVE ) );
		$this->assertFalse( $machine->is_valid_reason( null ) );
		$this->assertFalse( $machine->is_valid_reason( '    ' ) );
		$this->assertFalse( $machine->is_valid_reason( ' abcd ' ) );
		$this->assertTrue( $machine->is_valid_reason( 'abcde' ) );
		$this->assertTrue( $machine->is_valid_reason( 'Invalid documentation' ) );
	}
}
