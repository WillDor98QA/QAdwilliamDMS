<?php
/**
 * REQ-ASSIGN-001, -003, -006 data inputs for the assignment engine (ARCH §72, Decisions §4).
 */

namespace DMS\Tests\Integration;

use DMS\Plugin;
use DMS\Users\UserProfileRepository;
use DMS\Workflow\Status;

final class OfficerRegionRepositoryTest extends \WP_UnitTestCase {

	use Fixtures;

	public function test_set_regions_replaces_active_set(): void {
		$repo = Plugin::instance()->officer_regions();
		$a    = $this->make_hierarchy();
		$b    = $this->make_hierarchy();
		$user = self::factory()->user->create();

		$repo->set_regions( $user, array( $a['region'], $b['region'] ), 1 );
		$this->assertEqualsCanonicalizing( array( $a['region'], $b['region'] ), $repo->active_region_ids( $user ) );

		$change = $repo->set_regions( $user, array( $b['region'] ), 1 );
		$this->assertSame( array( $a['region'] ), $change['removed'] );
		$this->assertSame( array( $b['region'] ), $repo->active_region_ids( $user ) );

		$change = $repo->set_regions( $user, array( $a['region'], $b['region'] ), 1 );
		$this->assertSame( array( $a['region'] ), $change['added'], 'Re-activation of an inactive link' );
	}

	public function test_active_users_for_region_excludes_disabled_and_unlinked(): void {
		$repo     = Plugin::instance()->officer_regions();
		$h        = $this->make_hierarchy();
		$active   = self::factory()->user->create();
		$disabled = self::factory()->user->create();
		$unlinked = self::factory()->user->create();
		$repo->set_regions( $active, array( $h['region'] ), 1 );
		$repo->set_regions( $disabled, array( $h['region'] ), 1 );
		$repo->set_regions( $unlinked, array( $h['region'] ), 1 );
		$repo->set_regions( $unlinked, array(), 1 );
		Plugin::instance()->profiles()->set_status( $disabled, UserProfileRepository::STATUS_DISABLED, 1 );

		$this->assertSame( array( $active ), $repo->active_user_ids_for_region( $h['region'] ) );
	}

	public function test_workload_counts_only_assigned_and_under_review(): void {
		$repo  = Plugin::instance()->officer_regions();
		$regs  = Plugin::instance()->registrations();
		$h     = $this->make_hierarchy();
		$busy  = self::factory()->user->create();
		$idle  = self::factory()->user->create();
		$ids   = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$ids[ $i ] = $this->make_registration( $h );
			$regs->transition( $ids[ $i ], Status::PENDING, Status::ASSIGNED, array( 'assigned_officer_id' => $busy ) );
		}
		$regs->transition( $ids[1], Status::ASSIGNED, Status::UNDER_REVIEW );
		$regs->transition( $ids[2], Status::ASSIGNED, Status::UNDER_REVIEW );
		$regs->transition( $ids[2], Status::UNDER_REVIEW, Status::APPROVED, array( 'approved_by' => $busy ) );
		$regs->transition( $ids[3], Status::ASSIGNED, Status::UNDER_REVIEW );
		$regs->transition( $ids[3], Status::UNDER_REVIEW, Status::DISAPPROVED, array( 'disapproval_reason' => 'Wrong data' ) );

		$loads = $repo->workloads( array( $busy, $idle ) );
		$this->assertSame( 2, $loads[ $busy ]['workload'], '1 ASSIGNED + 1 UNDER_REVIEW' );
		$this->assertSame( 0, $loads[ $idle ]['workload'] );
		$this->assertSame( 2, $repo->outstanding_count( $busy ) );
	}
}
