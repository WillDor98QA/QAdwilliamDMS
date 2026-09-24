<?php
/**
 * REQ-REG / REQ-EDIT / REQ-SEARCH / REQ-RET data-layer guarantees
 * (ARCH §37, §71.1, §71.2, §73, §78.1–§78.7; Decisions §7, §21).
 */

namespace DMS\Tests\Integration;

use DMS\Errors\ConflictException;
use DMS\Errors\ValidationException;
use DMS\Plugin;
use DMS\Registrations\RegistrationFields;
use DMS\Registrations\RegistrationFilter;
use DMS\Registrations\RegistrationRepository;
use DMS\Workflow\Status;

final class RegistrationRepositoryTest extends \WP_UnitTestCase {

	use Fixtures;

	private RegistrationRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = Plugin::instance()->registrations();
	}

	public function test_create_produces_pending_record_with_reference_number(): void {
		$id  = $this->make_registration( $this->make_hierarchy() );
		$row = $this->repo->get( $id );
		$this->assertSame( 'PENDING', $row->status );
		$this->assertMatchesRegularExpression( '/^REG-\d{4}-\d{6}$/', $row->registration_number );
		$this->assertNotEmpty( $row->submitted_at );
		$this->assertNull( $row->assigned_officer_id );
	}

	public function test_create_rejects_unverified_phone(): void {
		$this->expectException( ValidationException::class );
		$this->repo->create( $this->registration_data( $this->make_hierarchy(), '', array( 'phone_verified' => 0 ) ) );
	}

	public function test_create_rejects_missing_consent(): void {
		$this->expectException( ValidationException::class );
		$this->repo->create( $this->registration_data( $this->make_hierarchy(), '', array( 'consent_given' => 0 ) ) );
	}

	public function test_create_rejects_constituency_from_another_region(): void {
		$a = $this->make_hierarchy();
		$b = $this->make_hierarchy();
		try {
			$this->repo->create( $this->registration_data( $a, '', array( 'constituency_id' => $b['constituency'], 'polling_station_id' => $b['station'] ) ) );
			$this->fail( 'Expected ValidationException' );
		} catch ( ValidationException $e ) {
			$this->assertArrayHasKey( 'polling_station_id', $e->errors );
		}
	}

	public function test_duplicate_normalized_phone_is_rejected_with_generic_message(): void {
		$h = $this->make_hierarchy();
		$this->repo->create( $this->registration_data( $h, '+233201112222' ) );
		try {
			$this->repo->create( $this->registration_data( $h, '+233201112222' ) );
			$this->fail( 'Expected ConflictException' );
		} catch ( ConflictException $e ) {
			$this->assertSame( 'dms_duplicate_phone', $e->error_code() );
			$this->assertStringNotContainsString( 'REG-', $e->getMessage(), 'Must not reveal the existing record' );
		}
		$this->assertTrue( $this->repo->phone_exists( '+233201112222' ) );
	}

	public function test_failed_create_does_not_consume_registration_number(): void {
		$h     = $this->make_hierarchy();
		$first = $this->repo->get( $this->repo->create( $this->registration_data( $h, '+233201113333' ) ) );
		try {
			$this->repo->create( $this->registration_data( $h, '+233201113333' ) );
		} catch ( ConflictException $e ) {
			unset( $e );
		}
		$next = $this->repo->get( $this->make_registration( $h ) );
		$this->assertSame( (int) substr( $first->registration_number, -6 ) + 1, (int) substr( $next->registration_number, -6 ) );
	}

	public function test_update_fields_returns_diff_for_holding_area_record(): void {
		$id   = $this->make_registration( $this->make_hierarchy() );
		$diff = $this->repo->update_fields( $id, array( 'first_name' => 'Abena', 'organization' => 'Org X' ) );
		$this->assertSame( 'Ama', $diff['first_name']['old'] );
		$this->assertSame( 'Abena', $diff['first_name']['new'] );
		$this->assertSame( 'Abena', $this->repo->get( $id )->first_name );
		$this->assertSame( array(), $this->repo->update_fields( $id, array( 'first_name' => 'Abena' ) ), 'No-op edit returns no diff' );
	}

	public function test_update_fields_rejects_non_editable_columns(): void {
		$id = $this->make_registration( $this->make_hierarchy() );
		foreach ( array( 'status', 'phone_normalized', 'phone', 'assigned_officer_id', 'approved_by', 'registration_number' ) as $column ) {
			try {
				$this->repo->update_fields( $id, array( $column => 'x' ) );
				$this->fail( "{$column} must not be editable" );
			} catch ( ValidationException $e ) {
				$this->assertArrayHasKey( $column, $e->errors );
			}
		}
	}

	public function test_approved_record_cannot_be_edited(): void {
		$id = $this->make_registration( $this->make_hierarchy() );
		$this->walk_to( $id, Status::APPROVED );

		try {
			$this->repo->update_fields( $id, array( 'first_name' => 'Tampered' ) );
			$this->fail( 'Approved record must be locked' );
		} catch ( ConflictException $e ) {
			$this->assertSame( 'dms_record_locked', $e->error_code() );
		}
		$this->assertSame( 'Ama', $this->repo->get( $id )->first_name );
	}

	public function test_edit_cannot_break_electoral_chain(): void {
		$a  = $this->make_hierarchy();
		$b  = $this->make_hierarchy();
		$id = $this->make_registration( $a );
		$this->expectException( ValidationException::class );
		$this->repo->update_fields( $id, array( 'polling_station_id' => $b['station'] ) );
	}

	public function test_transition_requires_expected_current_state(): void {
		$id = $this->make_registration( $this->make_hierarchy() );
		$this->repo->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 1 ) );

		$this->expectException( ConflictException::class );
		$this->repo->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 2 ) );
	}

	public function test_transition_rejects_non_workflow_columns(): void {
		$id = $this->make_registration( $this->make_hierarchy() );
		$this->expectException( \InvalidArgumentException::class );
		$this->repo->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'first_name' => 'x' ) );
	}

	public function test_tombstone_nulls_personal_data_and_keeps_reference(): void {
		$h  = $this->make_hierarchy();
		$id = $this->make_registration( $h, array( 'extra_fields' => array( 'nickname' => 'Ama B' ) ) );
		$this->walk_to( $id, Status::DISAPPROVED );
		$number = $this->repo->get( $id )->registration_number;

		$this->repo->tombstone( $id, 5 );
		$row = $this->repo->get( $id );

		$this->assertSame( 'DELETED', $row->status );
		$this->assertSame( $number, $row->registration_number );
		$this->assertSame( (string) $h['region'], (string) $row->region_id );
		$this->assertNotNull( $row->deleted_at );
		$this->assertNotNull( $row->disapproved_at );
		foreach ( RegistrationFields::PII as $column ) {
			$this->assertNull( $row->{$column}, "{$column} must be null" );
		}
	}

	public function test_tombstone_frees_the_phone_number(): void {
		$h  = $this->make_hierarchy();
		$id = $this->repo->create( $this->registration_data( $h, '+233201114444' ) );
		$this->walk_to( $id, Status::DISAPPROVED );
		$this->assertTrue( $this->repo->phone_exists( '+233201114444' ), 'Blocked while in the Bin (OD-18)' );

		$this->repo->tombstone( $id, null );
		$this->assertFalse( $this->repo->phone_exists( '+233201114444' ) );
		$this->assertGreaterThan( 0, $this->repo->create( $this->registration_data( $h, '+233201114444' ) ) );
	}

	public function test_tombstone_only_from_bin(): void {
		$id = $this->make_registration( $this->make_hierarchy() );
		$this->walk_to( $id, Status::APPROVED );
		$this->expectException( ConflictException::class );
		$this->repo->tombstone( $id, null );
	}

	public function test_disapproved_before_selects_by_disapproval_date(): void {
		$h   = $this->make_hierarchy();
		$old = $this->make_registration( $h );
		$new = $this->make_registration( $h );
		$this->walk_to( $old, Status::DISAPPROVED, '2026-08-01 12:00:00' );
		$this->walk_to( $new, Status::DISAPPROVED, '2026-09-20 12:00:00' );

		$ids = $this->repo->disapproved_before( '2026-08-25 12:00:00' );
		$this->assertContains( $old, $ids );
		$this->assertNotContains( $new, $ids );
	}

	public function test_search_filters_combine_and_paginate(): void {
		$a = $this->make_hierarchy();
		$b = $this->make_hierarchy();
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$ids[] = $this->make_registration( $a, array( 'organization' => 'Needle Org' ) );
		}
		$this->make_registration( $b, array( 'organization' => 'Needle Org' ) );
		$this->walk_to( $ids[0], Status::UNDER_REVIEW );

		$result = $this->repo->search( RegistrationFilter::from_array( array( 'region_id' => $a['region'], 'search' => 'needle', 'per_page' => 2 ) ) );
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 2, $result['items'] );
		$this->assertStringStartsWith( 'Region ', $result['items'][0]->region_name, 'Names joined in the same query' );
		$this->assertNotEmpty( $result['items'][0]->polling_station_name );

		$review = $this->repo->search( RegistrationFilter::from_array( array( 'region_id' => $a['region'], 'status' => array( 'UNDER_REVIEW' ) ) ) );
		$this->assertSame( 1, $review['total'] );
		$this->assertSame( (string) $ids[0], (string) $review['items'][0]->id );
	}

	public function test_search_by_any_phone_format(): void {
		$id = $this->repo->create( $this->registration_data( $this->make_hierarchy(), '+233501231234' ) );
		$hits = $this->repo->search( RegistrationFilter::from_array( array( 'search' => '050 123 1234' ) ) );
		$this->assertSame( array( (string) $id ), array_map( static fn( $r ) => (string) $r->id, $hits['items'] ) );
	}

	public function test_search_scope_limits_to_assigned_officer(): void {
		$h    = $this->make_hierarchy();
		$mine = $this->make_registration( $h );
		$this->make_registration( $h );
		$this->repo->transition( $mine, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 77 ) );

		$filter                   = RegistrationFilter::from_array( array( 'region_id' => $h['region'] ) );
		$filter->scope_officer_id = 77;
		$result                   = $this->repo->search( $filter );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( (string) $mine, (string) $result['items'][0]->id );
	}

	public function test_search_never_returns_tombstones(): void {
		$h  = $this->make_hierarchy();
		$id = $this->make_registration( $h );
		$this->walk_to( $id, Status::DISAPPROVED );
		$this->repo->tombstone( $id, null );
		$this->assertSame( 0, $this->repo->search( RegistrationFilter::from_array( array( 'region_id' => $h['region'] ) ) )['total'] );
	}

	/** Drives a record through valid transitions at the repository level. */
	private function walk_to( int $id, Status $target, string $decided_at = '2026-09-24 12:00:00' ): void {
		$this->repo->transition( $id, Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => 1, 'assigned_at' => '2026-09-24 11:00:00' ) );
		if ( Status::ASSIGNED === $target ) {
			return;
		}
		$this->repo->transition( $id, Status::ASSIGNED, Status::UNDER_REVIEW, array( 'reviewed_at' => '2026-09-24 11:30:00' ) );
		if ( Status::UNDER_REVIEW === $target ) {
			return;
		}
		if ( Status::APPROVED === $target ) {
			$this->repo->transition( $id, Status::UNDER_REVIEW, Status::APPROVED, array( 'approved_at' => $decided_at, 'approved_by' => 1 ) );
			return;
		}
		$this->repo->transition( $id, Status::UNDER_REVIEW, Status::DISAPPROVED, array( 'disapproved_at' => $decided_at, 'disapproved_by' => 1, 'disapproval_reason' => 'Invalid details' ) );
	}
}
