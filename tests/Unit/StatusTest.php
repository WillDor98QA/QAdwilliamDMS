<?php
/**
 * REQ-WF-005 holding area grouping, REQ-EDIT-001 editability, REQ-ASSIGN-003 workload states.
 */

namespace DMS\Tests\Unit;

use DMS\Workflow\Status;
use PHPUnit\Framework\TestCase;

final class StatusTest extends TestCase {

	public function test_holding_area_is_pending_assigned_under_review(): void {
		$this->assertSame( array( Status::PENDING, Status::ASSIGNED, Status::UNDER_REVIEW ), Status::holding_area() );
	}

	public function test_only_holding_states_are_editable(): void {
		$editable = array_filter( Status::cases(), static fn( Status $s ): bool => $s->is_editable() );
		$this->assertSame( array( 'PENDING', 'ASSIGNED', 'UNDER_REVIEW' ), array_values( array_map( static fn( Status $s ) => $s->value, $editable ) ) );
		$this->assertFalse( Status::APPROVED->is_editable() );
	}

	public function test_workload_is_assigned_plus_under_review_only(): void {
		foreach ( Status::cases() as $status ) {
			$expected = in_array( $status, array( Status::ASSIGNED, Status::UNDER_REVIEW ), true );
			$this->assertSame( $expected, $status->counts_as_workload(), $status->value );
		}
	}
}
