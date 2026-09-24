<?php
/**
 * Seeds the protected Administrator role and the example roles from ARCH §18.
 *
 * - Administrator is a system role (is_system = 1): it cannot be deleted and
 *   holds every registered permission (implementation decision ID-02).
 * - The ARCH §18 roles are ordinary, editable configuration — seeded only as
 *   a starting point, never enforced by code. assignment.receive is added to
 *   each because Decisions §4 makes it the officer-eligibility permission.
 *
 * Idempotent: a role whose slug already exists is left untouched.
 *
 * @package DMS
 */

namespace DMS\Database\Migrations;

use DMS\Database\Migration;
use DMS\Database\MigrationException;
use DMS\Database\Tables;

defined( 'ABSPATH' ) || exit;

final class M003SeedRoles implements Migration {

	public const ADMINISTRATOR_SLUG = 'administrator';

	public function version(): int {
		return 3;
	}

	public function description(): string {
		return 'Seed Administrator and example roles';
	}

	/** @return list<array{slug:string,name:string,description:string,is_system:bool,permissions:list<string>}> */
	public static function seed_roles(): array {
		$officer = array(
			'review.view',
			'review.approve',
			'review.disapprove',
			'review.add_note',
			'registrations.view_assigned',
			'assignment.receive',
		);
		$senior  = array_merge( $officer, array( 'bin.view', 'bin.restore', 'approved.view' ) );
		$super   = array_merge( $senior, array( 'assignment.assign', 'assignment.reassign', 'analytics.view', 'reports.export' ) );

		return array(
			array(
				'slug'        => self::ADMINISTRATOR_SLUG,
				'name'        => 'Administrator',
				'description' => 'Full access to every module. Protected system role.',
				'is_system'   => true,
				'permissions' => array(),
			),
			array(
				'slug'        => 'verification-officer',
				'name'        => 'Verification Officer',
				'description' => 'Reviews registrations assigned to them.',
				'is_system'   => false,
				'permissions' => $officer,
			),
			array(
				'slug'        => 'senior-verification-officer',
				'name'        => 'Senior Verification Officer',
				'description' => 'Verification Officer plus Bin and Approved access.',
				'is_system'   => false,
				'permissions' => $senior,
			),
			array(
				'slug'        => 'supervisor',
				'name'        => 'Supervisor',
				'description' => 'Senior Verification Officer plus assignment, analytics and report export.',
				'is_system'   => false,
				'permissions' => $super,
			),
		);
	}

	public function up( \wpdb $db ): void {
		$roles = Tables::name( Tables::ROLES );
		$perms = Tables::name( Tables::ROLE_PERMISSIONS );
		$now   = current_time( 'mysql', true );

		foreach ( self::seed_roles() as $role ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $db->get_var( $db->prepare( "SELECT id FROM {$roles} WHERE slug = %s", $role['slug'] ) );
			if ( $exists ) {
				continue;
			}
			$ok = $db->insert(
				$roles,
				array(
					'name'        => $role['name'],
					'slug'        => $role['slug'],
					'description' => $role['description'],
					'status'      => 'ACTIVE',
					'is_system'   => $role['is_system'] ? 1 : 0,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( false === $ok ) {
				throw new MigrationException( "Could not seed role {$role['slug']}: " . $db->last_error );
			}
			$role_id = (int) $db->insert_id;
			foreach ( $role['permissions'] as $permission ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ok = $db->query( $db->prepare( "INSERT IGNORE INTO {$perms} (role_id, permission, created_at) VALUES (%d, %s, %s)", $role_id, $permission, $now ) );
				if ( false === $ok ) {
					throw new MigrationException( "Could not seed permission {$permission}: " . $db->last_error );
				}
			}
		}
	}
}
