<?php
/**
 * REQ-SEARCH-002 untrusted filter input is whitelisted and bounded (ARCH §73, MP §33).
 */

namespace DMS\Tests\Unit;

use DMS\Registrations\RegistrationFilter;
use DMS\Workflow\Status;
use PHPUnit\Framework\TestCase;

final class RegistrationFilterTest extends TestCase {

	public function test_builds_combined_filter(): void {
		$f = RegistrationFilter::from_array(
			array(
				'status'          => array( 'under_review' ),
				'region_id'       => '3',
				'constituency_id' => '42',
				'officer_id'      => '7',
				'submitted_from'  => '2026-09-01',
				'submitted_to'    => '2026-09-30',
				'search'          => '  Kofi  ',
			)
		);
		$this->assertSame( array( Status::UNDER_REVIEW ), $f->statuses );
		$this->assertSame( 3, $f->region_id );
		$this->assertSame( 42, $f->constituency_id );
		$this->assertSame( 7, $f->officer_id );
		$this->assertSame( array( 'from' => '2026-09-01', 'to' => '2026-09-30' ), $f->dates['submitted'] );
		$this->assertSame( 'Kofi', $f->search );
	}

	public function test_rejects_unknown_status_and_deleted(): void {
		$f = RegistrationFilter::from_array( array( 'status' => array( 'HACKED', 'DELETED', 'APPROVED' ) ) );
		$this->assertSame( array( Status::APPROVED ), $f->statuses );
	}

	public function test_orderby_is_whitelisted(): void {
		$f = RegistrationFilter::from_array( array( 'orderby' => 'id; DROP TABLE x', 'order' => 'sideways' ) );
		$this->assertSame( 'submitted_at', $f->orderby );
		$this->assertSame( 'DESC', $f->order );
	}

	public function test_pagination_is_bounded(): void {
		$f = RegistrationFilter::from_array( array( 'page' => '-5', 'per_page' => '100000' ) );
		$this->assertSame( 5, $f->page, 'absint of -5' );
		$this->assertSame( RegistrationFilter::MAX_PER_PAGE, $f->per_page );
		$this->assertSame( 1, RegistrationFilter::from_array( array( 'per_page' => '0' ) )->per_page );
	}

	public function test_invalid_dates_are_ignored(): void {
		$f = RegistrationFilter::from_array( array( 'approved_from' => '2026-02-30', 'approved_to' => "2026-01-01' OR 1=1" ) );
		$this->assertArrayNotHasKey( 'approved', $f->dates );
	}

	public function test_non_positive_ids_become_null(): void {
		$f = RegistrationFilter::from_array( array( 'region_id' => 'abc', 'polling_station_id' => '0' ) );
		$this->assertNull( $f->region_id );
		$this->assertNull( $f->polling_station_id );
	}
}
