<?php
/**
 * Role management use cases (ARCH §14, §18; MP §31): create, edit, set
 * permissions, delete. Every action is authorized server-side and audited.
 *
 * Privilege-escalation guard (implementation decision ID-13): a user can only
 * grant permissions they already hold. Members of the system Administrator
 * role hold everything, so they can grant anything.
 *
 * The system role cannot be edited, deactivated or deleted (ID-02).
 *
 * @package DMS
 */

namespace DMS\Permissions;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Transaction;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\NotFoundException;
use DMS\Errors\ValidationException;
use DMS\Support\Authorizer;
use DMS\Support\RequestContext;

defined( 'ABSPATH' ) || exit;

class RoleService {

	public function __construct(
		private \wpdb $db,
		private RoleRepository $roles,
		private PermissionRegistry $registry,
		private CapabilityManager $capabilities,
		private AuditService $audit,
		private Authorizer $authorizer,
		private RequestContext $context,
	) {
	}

	/**
	 * @param list<string> $permissions
	 * @return int New role ID.
	 */
	public function create( string $name, string $description = '', array $permissions = array() ): int {
		$this->authorizer->require( 'roles.create' );
		if ( array() !== $permissions ) {
			$this->authorizer->require( 'roles.assign_permissions' );
		}

		$name        = $this->clean_name( $name );
		$description = sanitize_textarea_field( $description );
		$slug        = sanitize_title( $name );
		$permissions = $this->validate_permissions( $permissions );
		$this->assert_can_grant( $permissions );

		if ( '' === $slug || null !== $this->roles->find_by_slug( $slug ) ) {
			throw new ValidationException( array( 'name' => __( 'A role with this name already exists.', 'dms' ) ) );
		}

		return Transaction::run(
			$this->db,
			function () use ( $name, $slug, $description, $permissions ): int {
				$role_id = $this->roles->create( $name, $slug, $description );
				$this->roles->set_permissions( $role_id, $permissions );
				$this->audit->record(
					AuditAction::ROLE_CREATED,
					array(
						'object_type' => 'role',
						'object_id'   => $role_id,
						'metadata'    => array(
							'name'        => $name,
							'slug'        => $slug,
							'permissions' => $permissions,
						),
					)
				);
				return $role_id;
			}
		);
	}

	/** @param array{name?:string,description?:string,status?:string} $fields */
	public function update( int $role_id, array $fields ): void {
		$this->authorizer->require( 'roles.edit' );
		$role = $this->editable_role( $role_id );

		$changes = array();
		if ( array_key_exists( 'name', $fields ) ) {
			$changes['name'] = $this->clean_name( (string) $fields['name'] );
		}
		if ( array_key_exists( 'description', $fields ) ) {
			$changes['description'] = sanitize_textarea_field( (string) $fields['description'] );
		}
		if ( array_key_exists( 'status', $fields ) ) {
			if ( ! in_array( $fields['status'], array( RoleRepository::STATUS_ACTIVE, RoleRepository::STATUS_INACTIVE ), true ) ) {
				throw new ValidationException( array( 'status' => __( 'Invalid status.', 'dms' ) ) );
			}
			$changes['status'] = $fields['status'];
		}

		$diff = array();
		foreach ( $changes as $key => $value ) {
			if ( (string) $role->{$key} !== (string) $value ) {
				$diff[ $key ] = array(
					'old' => $role->{$key},
					'new' => $value,
				);
			}
		}
		if ( array() === $diff ) {
			return;
		}

		Transaction::run(
			$this->db,
			function () use ( $role_id, $changes, $diff ): void {
				$this->roles->update( $role_id, $changes );
				$this->audit->record(
					AuditAction::ROLE_UPDATED,
					array(
						'object_type' => 'role',
						'object_id'   => $role_id,
						'metadata'    => array( 'changes' => $diff ),
					)
				);
			}
		);
	}

	/** @param list<string> $permissions The complete new permission set. */
	public function set_permissions( int $role_id, array $permissions ): void {
		$this->authorizer->require( 'roles.edit', 'roles.assign_permissions' );
		$this->editable_role( $role_id );

		$permissions = $this->validate_permissions( $permissions );
		$current     = $this->roles->permissions( $role_id );
		// Granting and revoking both require holding the permission: otherwise a
		// limited user could strip permissions from roles above them.
		$this->assert_can_grant( array_values( array_merge( array_diff( $permissions, $current ), array_diff( $current, $permissions ) ) ) );

		Transaction::run(
			$this->db,
			function () use ( $role_id, $permissions ): void {
				$change = $this->roles->set_permissions( $role_id, $permissions );
				if ( array() === $change['added'] && array() === $change['removed'] ) {
					return;
				}
				$this->audit->record(
					AuditAction::PERMISSIONS_CHANGED,
					array(
						'object_type' => 'role',
						'object_id'   => $role_id,
						'metadata'    => $change,
					)
				);
			}
		);
	}

	public function delete( int $role_id ): void {
		$this->authorizer->require( 'roles.delete' );
		$role  = $this->editable_role( $role_id );
		$users = $this->roles->user_count( $role_id );
		if ( $users > 0 ) {
			throw new ConflictException(
				/* translators: %d: number of users */
				sprintf( _n( 'This role is assigned to %d user. Remove it from them first.', 'This role is assigned to %d users. Remove it from them first.', $users, 'dms' ), $users ),
				'role_in_use'
			);
		}
		$permissions = $this->roles->permissions( $role_id );
		$this->assert_can_grant( $permissions );

		Transaction::run(
			$this->db,
			function () use ( $role_id, $role, $permissions ): void {
				$this->roles->delete( $role_id );
				$this->audit->record(
					AuditAction::ROLE_DELETED,
					array(
						'object_type' => 'role',
						'object_id'   => $role_id,
						'metadata'    => array(
							'name'        => $role->name,
							'slug'        => $role->slug,
							'permissions' => $permissions,
						),
					)
				);
			}
		);
	}

	/**
	 * Throws unless the current user holds every listed permission.
	 *
	 * @param list<string> $permissions
	 * @throws AuthorizationException
	 */
	public function assert_can_grant( array $permissions ): void {
		$held    = $this->capabilities->permissions_for( $this->context->user_id() );
		$missing = array_diff( $permissions, $held );
		if ( array() !== $missing ) {
			throw new AuthorizationException( __( 'You cannot grant or remove permissions that you do not hold yourself.', 'dms' ) );
		}
	}

	/**
	 * @param list<mixed> $permissions
	 * @return list<string>
	 * @throws ValidationException Unknown or reserved permission.
	 */
	private function validate_permissions( array $permissions ): array {
		$clean   = array();
		$invalid = array();
		foreach ( $permissions as $key ) {
			$key        = (string) $key;
			$definition = $this->registry->get( $key );
			if ( null === $definition || $definition->reserved ) {
				$invalid[] = $key;
				continue;
			}
			$clean[] = $key;
		}
		if ( array() !== $invalid ) {
			throw new ValidationException( array( 'permissions' => __( 'One or more selected permissions are not available.', 'dms' ) ) );
		}
		sort( $clean );
		return array_values( array_unique( $clean ) );
	}

	private function editable_role( int $role_id ): object {
		$role = $this->roles->find( $role_id );
		if ( null === $role ) {
			throw new NotFoundException();
		}
		if ( (int) $role->is_system ) {
			throw new ConflictException( __( 'The Administrator role is protected and cannot be changed.', 'dms' ), 'protected_role' );
		}
		return $role;
	}

	private function clean_name( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			throw new ValidationException( array( 'name' => __( 'Role name is required (up to 100 characters).', 'dms' ) ) );
		}
		return $name;
	}
}
