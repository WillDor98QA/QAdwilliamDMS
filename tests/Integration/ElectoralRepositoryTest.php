<?php
/**
 * REQ-ELEC-001..005 — hierarchy, cascade options, chain validation, dependency protection
 * (ARCH §35–§38, §61; MP §14).
 */

namespace DMS\Tests\Integration;

use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Errors\ConflictException;
use DMS\Plugin;

final class ElectoralRepositoryTest extends \WP_UnitTestCase {

	use Fixtures;

	private ElectoralRepository $repo;

	public function set_up(): void {
		parent::set_up();
		$this->repo = Plugin::instance()->electoral();
	}

	public function test_options_return_only_active_children_of_the_parent_sorted_by_name(): void {
		$r1 = $this->repo->insert( ElectoralLevel::REGION, 'OPT-R1', 'Region One' );
		$r2 = $this->repo->insert( ElectoralLevel::REGION, 'OPT-R2', 'Region Two' );
		$this->repo->insert( ElectoralLevel::CONSTITUENCY, 'OPT-C2', 'Zeta', $r1 );
		$this->repo->insert( ElectoralLevel::CONSTITUENCY, 'OPT-C1', 'Alpha', $r1 );
		$inactive = $this->repo->insert( ElectoralLevel::CONSTITUENCY, 'OPT-C3', 'Beta', $r1 );
		$this->repo->update( ElectoralLevel::CONSTITUENCY, $inactive, array( 'status' => ElectoralRepository::STATUS_INACTIVE ) );
		$this->repo->insert( ElectoralLevel::CONSTITUENCY, 'OPT-C4', 'Other region', $r2 );

		$names = array_column( $this->repo->options( ElectoralLevel::CONSTITUENCY, $r1 ), 'name' );
		$this->assertSame( array( 'Alpha', 'Zeta' ), $names );
	}

	public function test_child_options_require_a_parent(): void {
		$this->assertSame( array(), $this->repo->options( ElectoralLevel::POLLING_STATION, null ) );
		$this->assertSame( array(), $this->repo->options( ElectoralLevel::POLLING_STATION, 0 ) );
	}

	public function test_options_cache_is_invalidated_on_write(): void {
		$r = $this->repo->insert( ElectoralLevel::REGION, 'CACHE-R', 'Cache Region' );
		$this->assertCount( 0, $this->repo->options( ElectoralLevel::CONSTITUENCY, $r ) );
		$this->repo->insert( ElectoralLevel::CONSTITUENCY, 'CACHE-C', 'Cached', $r );
		$this->assertCount( 1, $this->repo->options( ElectoralLevel::CONSTITUENCY, $r ) );
	}

	public function test_valid_chain_accepts_matching_hierarchy_only(): void {
		$a = $this->make_hierarchy();
		$b = $this->make_hierarchy();
		$this->assertTrue( $this->repo->is_valid_chain( $a['region'], $a['constituency'], $a['station'] ) );
		$this->assertFalse( $this->repo->is_valid_chain( $a['region'], $b['constituency'], $b['station'] ), 'Constituency from another region' );
		$this->assertFalse( $this->repo->is_valid_chain( $a['region'], $a['constituency'], $b['station'] ), 'Station from another constituency' );
		$this->assertFalse( $this->repo->is_valid_chain( $a['region'], $a['constituency'], 999999 ) );
	}

	public function test_inactive_record_breaks_the_chain(): void {
		$h = $this->make_hierarchy();
		$this->repo->update( ElectoralLevel::POLLING_STATION, $h['station'], array( 'status' => ElectoralRepository::STATUS_INACTIVE ) );
		$this->assertFalse( $this->repo->is_valid_chain( $h['region'], $h['constituency'], $h['station'] ) );
	}

	public function test_delete_blocked_while_children_exist(): void {
		$h = $this->make_hierarchy();
		try {
			$this->repo->delete( ElectoralLevel::REGION, $h['region'] );
			$this->fail( 'Expected ConflictException' );
		} catch ( ConflictException $e ) {
			$this->assertSame( 'dms_dependency_exists', $e->error_code() );
		}
		$this->assertNotNull( $this->repo->find( ElectoralLevel::REGION, $h['region'] ) );
	}

	public function test_delete_blocked_while_registrations_reference_station(): void {
		$h = $this->make_hierarchy();
		$this->make_registration( $h );
		$deps = $this->repo->dependencies( ElectoralLevel::POLLING_STATION, $h['station'] );
		$this->assertSame( 1, $deps['registrations'] );
		$this->expectException( ConflictException::class );
		$this->repo->delete( ElectoralLevel::POLLING_STATION, $h['station'] );
	}

	public function test_unreferenced_leaf_can_be_deleted(): void {
		$h = $this->make_hierarchy();
		$this->repo->delete( ElectoralLevel::POLLING_STATION, $h['station'] );
		$this->assertNull( $this->repo->find( ElectoralLevel::POLLING_STATION, $h['station'] ) );
	}

	public function test_find_by_codes_resolves_many_codes(): void {
		$h     = $this->make_hierarchy( 'BULK' );
		$found = $this->repo->find_by_codes( ElectoralLevel::CONSTITUENCY, array( 'BULKC', 'MISSING' ) );
		$this->assertSame( array( 'BULKC' ), array_keys( $found ) );
		$this->assertSame( (string) $h['region'], (string) $found['BULKC']->region_id );
	}
}
