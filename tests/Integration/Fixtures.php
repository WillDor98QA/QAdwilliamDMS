<?php
/**
 * Shared builders for integration tests.
 */

namespace DMS\Tests\Integration;

use DMS\Electoral\ElectoralLevel;
use DMS\Plugin;

trait Fixtures {

	private static int $fixture_seq = 0;

	/** @return array{region:int,constituency:int,station:int} */
	protected function make_hierarchy( string $prefix = '' ): array {
		$n  = ++self::$fixture_seq;
		$p  = '' === $prefix ? 'T' . $n : $prefix;
		$el = Plugin::instance()->electoral();
		$r  = $el->insert( ElectoralLevel::REGION, "{$p}R", "Region {$p}" );
		$c  = $el->insert( ElectoralLevel::CONSTITUENCY, "{$p}C", "Constituency {$p}", $r );
		$s  = $el->insert( ElectoralLevel::POLLING_STATION, "{$p}S", "Station {$p}", $c );
		return array( 'region' => $r, 'constituency' => $c, 'station' => $s );
	}

	/** @param array{region:int,constituency:int,station:int} $h */
	protected function registration_data( array $h, string $phone = '', array $overrides = array() ): array {
		$n = ++self::$fixture_seq;
		return array_merge(
			array(
				'first_name'         => 'Ama',
				'last_name'          => 'Mensah' . $n,
				'phone'              => '024 ' . sprintf( '%07d', $n ),
				'phone_normalized'   => '' !== $phone ? $phone : '+23324' . sprintf( '%07d', $n ),
				'phone_verified'     => 1,
				'phone_verified_at'  => '2026-09-24 10:00:00',
				'email'              => "ama{$n}@example.org",
				'organization'       => 'Org ' . $n,
				'region_id'          => $h['region'],
				'constituency_id'    => $h['constituency'],
				'polling_station_id' => $h['station'],
				'consent_given'      => 1,
				'consent_given_at'   => '2026-09-24 10:00:00',
			),
			$overrides
		);
	}

	protected function make_registration( array $h, array $overrides = array() ): int {
		return Plugin::instance()->registrations()->create( $this->registration_data( $h, '', $overrides ) );
	}

	/** Creates a WP user holding the protected Administrator role and makes them current. */
	protected function act_as_admin(): int {
		$user  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin = Plugin::instance()->roles()->find_by_slug( 'administrator' );
		Plugin::instance()->roles()->assign_user( $user, (int) $admin->id );
		wp_set_current_user( $user );
		return $user;
	}

	/** @param list<string> $permissions */
	protected function role_with( array $permissions, string $slug = '' ): int {
		$roles = Plugin::instance()->roles();
		$slug  = '' !== $slug ? $slug : 'role-' . ( ++self::$fixture_seq );
		$id    = $roles->create( ucfirst( $slug ), $slug );
		$roles->set_permissions( $id, $permissions );
		return $id;
	}

	/**
	 * An active officer (seeded Verification Officer role → assignment.receive, review.*,
	 * registrations.view_assigned) linked to the given regions.
	 *
	 * @param list<int> $region_ids
	 */
	protected function make_officer( array $region_ids, string $role_slug = 'verification-officer' ): int {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$role = Plugin::instance()->roles()->find_by_slug( $role_slug );
		Plugin::instance()->roles()->assign_user( $user, (int) $role->id );
		Plugin::instance()->profiles()->ensure( $user );
		Plugin::instance()->officer_regions()->set_regions( $user, $region_ids, null );
		return $user;
	}

	/** @param list<string> $permissions */
	protected function act_as( array $permissions ): int {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		Plugin::instance()->roles()->assign_user( $user, $this->role_with( $permissions ) );
		wp_set_current_user( $user );
		return $user;
	}
}
