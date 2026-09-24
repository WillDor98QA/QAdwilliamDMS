<?php
/**
 * Data access for application roles, role permissions and user-role links
 * (ARCH §22: dms_roles, dms_role_permissions, dms_user_roles).
 *
 * Authorization rules (who may change roles) and auditing live in the role
 * service layer, not here. Every write fires `dms_permissions_changed` so the
 * capability cache is invalidated.
 *
 * @package DMS
 */

namespace DMS\Permissions;

use DMS\Database\Tables;
use DMS\Database\Transaction;
use DMS\Support\Clock;

defined( 'ABSPATH' ) || exit;

class RoleRepository {

	public const STATUS_ACTIVE   = 'ACTIVE';
	public const STATUS_INACTIVE = 'INACTIVE';

	public function __construct( private \wpdb $db, private Clock $clock ) {
	}

	/**
	 * @return int New role ID.
	 * @throws \RuntimeException On database failure or duplicate slug.
	 */
	public function create( string $name, string $slug, string $description = '', bool $is_system = false ): int {
		$now = $this->clock->now_mysql();
		$ok  = $this->db->insert(
			Tables::name( Tables::ROLES ),
			array(
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
				'status'      => self::STATUS_ACTIVE,
				'is_system'   => $is_system ? 1 : 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'Could not create role: ' . $this->db->last_error );
		}
		return (int) $this->db->insert_id;
	}

	/** @return object|null Role row. */
	public function find( int $role_id ): ?object {
		$table = Tables::name( Tables::ROLES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Tables.
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE id = %d", $role_id ) ) ?? null;
	}

	public function find_by_slug( string $slug ): ?object {
		$table = Tables::name( Tables::ROLES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_row( $this->db->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ) ) ?? null;
	}

	/** @return list<string> Stored permission keys for a role. */
	public function permissions( int $role_id ): array {
		$table = Tables::name( Tables::ROLE_PERMISSIONS );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_col( $this->db->prepare( "SELECT permission FROM {$table} WHERE role_id = %d ORDER BY permission", $role_id ) );
	}

	/**
	 * Replaces a role's permission set atomically.
	 *
	 * @param list<string> $permissions Must already be validated against the registry.
	 * @return array{added:list<string>,removed:list<string>}
	 */
	public function set_permissions( int $role_id, array $permissions ): array {
		$table   = Tables::name( Tables::ROLE_PERMISSIONS );
		$current = $this->permissions( $role_id );
		$wanted  = array_values( array_unique( $permissions ) );
		$added   = array_values( array_diff( $wanted, $current ) );
		$removed = array_values( array_diff( $current, $wanted ) );

		Transaction::run(
			$this->db,
			function () use ( $table, $role_id, $added, $removed ): void {
				foreach ( $removed as $permission ) {
					if ( false === $this->db->delete(
						$table,
						array(
							'role_id'    => $role_id,
							'permission' => $permission,
						),
						array( '%d', '%s' )
					) ) {
						throw new \RuntimeException( 'Could not update role permissions: ' . $this->db->last_error );
					}
				}
				foreach ( $added as $permission ) {
					$ok = $this->db->insert(
						$table,
						array(
							'role_id'    => $role_id,
							'permission' => $permission,
							'created_at' => $this->clock->now_mysql(),
						),
						array( '%d', '%s', '%s' )
					);
					if ( false === $ok ) {
						throw new \RuntimeException( 'Could not update role permissions: ' . $this->db->last_error );
					}
				}
			}
		);

		do_action( 'dms_permissions_changed', null );
		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/** Idempotent: assigning an already-held role is a no-op. */
	public function assign_user( int $user_id, int $role_id ): bool {
		$table = Tables::name( Tables::USER_ROLES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $this->db->query(
			$this->db->prepare(
				"INSERT IGNORE INTO {$table} (user_id, role_id, created_at) VALUES (%d, %d, %s)",
				$user_id,
				$role_id,
				$this->clock->now_mysql()
			)
		);
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not assign role: ' . $this->db->last_error );
		}
		do_action( 'dms_permissions_changed', $user_id );
		return $result > 0;
	}

	public function remove_user( int $user_id, int $role_id ): bool {
		$deleted = $this->db->delete(
			Tables::name( Tables::USER_ROLES ),
			array(
				'user_id' => $user_id,
				'role_id' => $role_id,
			),
			array( '%d', '%d' )
		);
		if ( false === $deleted ) {
			throw new \RuntimeException( 'Could not remove role: ' . $this->db->last_error );
		}
		do_action( 'dms_permissions_changed', $user_id );
		return $deleted > 0;
	}

	/** @return list<object> Active and inactive roles held by the user. */
	public function roles_for_user( int $user_id ): array {
		$roles = Tables::name( Tables::ROLES );
		$links = Tables::name( Tables::USER_ROLES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_results(
			$this->db->prepare( "SELECT r.* FROM {$roles} r INNER JOIN {$links} ur ON ur.role_id = r.id WHERE ur.user_id = %d ORDER BY r.name", $user_id )
		);
	}

	/**
	 * Union of stored permissions across the user's ACTIVE roles (ARCH §19).
	 *
	 * @return array{permissions:list<string>,has_system_role:bool}
	 */
	public function effective_for_user( int $user_id ): array {
		$roles = Tables::name( Tables::ROLES );
		$links = Tables::name( Tables::USER_ROLES );
		$perms = Tables::name( Tables::ROLE_PERMISSIONS );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_system  = (bool) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$roles} r INNER JOIN {$links} ur ON ur.role_id = r.id
				WHERE ur.user_id = %d AND r.status = %s AND r.is_system = 1",
				$user_id,
				self::STATUS_ACTIVE
			)
		);
		$permissions = $this->db->get_col(
			$this->db->prepare(
				"SELECT DISTINCT rp.permission FROM {$perms} rp
				INNER JOIN {$roles} r ON r.id = rp.role_id
				INNER JOIN {$links} ur ON ur.role_id = r.id
				WHERE ur.user_id = %d AND r.status = %s",
				$user_id,
				self::STATUS_ACTIVE
			)
		);
		// phpcs:enable

		return array(
			'permissions'     => $permissions,
			'has_system_role' => $has_system,
		);
	}

	/** @return list<object> All roles, alphabetically. */
	public function all(): array {
		$table = Tables::name( Tables::ROLES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $this->db->get_results( "SELECT * FROM {$table} ORDER BY name ASC" );
	}

	/**
	 * @param list<int> $role_ids
	 * @return array<int,object> role_id => row, only for roles that exist.
	 */
	public function find_many( array $role_ids ): array {
		$role_ids = array_values( array_unique( array_filter( array_map( 'intval', $role_ids ) ) ) );
		if ( array() === $role_ids ) {
			return array();
		}
		$table = Tables::name( Tables::ROLES );
		$in    = implode( ',', array_fill( 0, count( $role_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$table} WHERE id IN ({$in})", ...$role_ids ) );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}

	/** @param array<string,mixed> $fields Subset of name, description, status. */
	public function update( int $role_id, array $fields ): void {
		$data = array_intersect_key( $fields, array_flip( array( 'name', 'description', 'status' ) ) );
		if ( array() === $data ) {
			return;
		}
		$data['updated_at'] = $this->clock->now_mysql();
		if ( false === $this->db->update( Tables::name( Tables::ROLES ), $data, array( 'id' => $role_id ) ) ) {
			throw new \RuntimeException( 'Could not update role: ' . $this->db->last_error );
		}
		do_action( 'dms_permissions_changed', null );
	}

	/** Deletes a role and its permissions. Callers must first ensure no user holds it (FK RESTRICT also refuses). */
	public function delete( int $role_id ): void {
		if ( false === $this->db->delete( Tables::name( Tables::ROLES ), array( 'id' => $role_id ), array( '%d' ) ) ) {
			throw new \RuntimeException( 'Could not delete role: ' . $this->db->last_error );
		}
		do_action( 'dms_permissions_changed', null );
	}

	public function user_count( int $role_id ): int {
		$links = Tables::name( Tables::USER_ROLES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$links} WHERE role_id = %d", $role_id ) );
	}

	/** @return list<int> */
	public function role_ids_for_user( int $user_id ): array {
		return array_map( static fn( object $r ): int => (int) $r->id, $this->roles_for_user( $user_id ) );
	}

	/** Active (not disabled) users holding an active system role — for last-administrator lockout protection. */
	public function count_system_role_members(): int {
		$roles    = Tables::name( Tables::ROLES );
		$links    = Tables::name( Tables::USER_ROLES );
		$profiles = Tables::name( Tables::USER_PROFILES );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(DISTINCT ur.user_id) FROM {$links} ur
				INNER JOIN {$roles} r ON r.id = ur.role_id
				LEFT JOIN {$profiles} p ON p.user_id = ur.user_id
				WHERE r.is_system = 1 AND r.status = %s AND COALESCE(p.status, %s) = %s",
				self::STATUS_ACTIVE,
				'ACTIVE',
				'ACTIVE'
			)
		);
	}
}
