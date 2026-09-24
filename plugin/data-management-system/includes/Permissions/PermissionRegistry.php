<?php
/**
 * Central permission registry (ARCH §17).
 *
 * Every application permission is declared here once. Roles store permission
 * keys; CapabilityManager exposes them to current_user_can(). Future modules
 * add permissions through the `dms_register_permissions` filter without
 * changing the authorization system.
 *
 * Keys follow ARCH §17 exactly. Additions not in ARCH §17 are marked with
 * their source:
 *   - registrations.export  — ARCH §75, MP §27
 *   - assignment.receive    — Decisions §4 (officer eligibility)
 *   - imports.*             — ARCH §66
 *   - notifications.*       — MP §28 retry/monitoring (implementation decision ID-04)
 *
 * @package DMS
 */

namespace DMS\Permissions;

defined( 'ABSPATH' ) || exit;

final class PermissionRegistry {

	/** @var array<string,Permission>|null */
	private ?array $permissions = null;

	/** @var array<string,string> Group key => label, in menu order. */
	private const GROUPS = array(
		'dashboard'     => 'Dashboard',
		'registrations' => 'Registration Management',
		'review'        => 'Review',
		'assignment'    => 'Assignments',
		'approved'      => 'Approved Data',
		'bin'           => 'Bin',
		'analytics'     => 'Analytics',
		'reports'       => 'Reports',
		'imports'       => 'Electoral Data Import',
		'users'         => 'Users',
		'roles'         => 'Roles',
		'audit'         => 'Audit',
		'notifications' => 'Notifications',
		'settings'      => 'Settings',
	);

	/** @return array<string,Permission> Keyed by permission key. */
	public function all(): array {
		if ( null === $this->permissions ) {
			$this->permissions = $this->build();
		}
		return $this->permissions;
	}

	/** @return list<string> */
	public function keys(): array {
		return array_keys( $this->all() );
	}

	public function has( string $key ): bool {
		return isset( $this->all()[ $key ] );
	}

	public function get( string $key ): ?Permission {
		return $this->all()[ $key ] ?? null;
	}

	/** @return array<string,array{label:string,permissions:list<Permission>}> */
	public function grouped(): array {
		$out = array();
		foreach ( $this->all() as $permission ) {
			$out[ $permission->group ]                ??= array(
				'label'       => self::GROUPS[ $permission->group ] ?? ucfirst( $permission->group ),
				'permissions' => array(),
			);
			$out[ $permission->group ]['permissions'][] = $permission;
		}
		return $out;
	}

	/** Clears the memoized list (used after the filter changes, e.g. in tests). */
	public function reset(): void {
		$this->permissions = null;
	}

	/** @return array<string,Permission> */
	private function build(): array {
		$definitions = array(
			// Dashboard.
			array( 'dashboard.view', 'dashboard', 'View Dashboard' ),
			array( 'dashboard.export', 'dashboard', 'Export Dashboard', 'Reserved: dashboard figures are exported from Analytics (analytics.export).', true ),

			// Registrations.
			array( 'registrations.view', 'registrations', 'View all registrations', 'Access every registration, not only those assigned to you.' ),
			array( 'registrations.view_holding', 'registrations', 'View Holding Area' ),
			array( 'registrations.view_assigned', 'registrations', 'View Assigned Records', 'Access registrations currently assigned to you.' ),
			array( 'registrations.view_approved', 'registrations', 'View Approved list' ),
			array( 'registrations.view_bin', 'registrations', 'View Bin list' ),
			array( 'registrations.create', 'registrations', 'Create Registration', 'Reserved: V1 registrations are created only through the public form.', true ),
			array( 'registrations.edit', 'registrations', 'Edit Registration', 'Edit registrations that are not yet approved.' ),
			array( 'registrations.delete', 'registrations', 'Delete Registration', 'Reserved: permanent deletion is controlled by bin.delete (OD-19).', true ),
			array( 'registrations.export', 'registrations', 'Export Registrations' ),

			// Review.
			array( 'review.view', 'review', 'View Review' ),
			array( 'review.approve', 'review', 'Approve' ),
			array( 'review.disapprove', 'review', 'Disapprove' ),
			array( 'review.add_note', 'review', 'Add Review Note' ),

			// Assignments.
			array( 'assignment.view', 'assignment', 'View Assignments' ),
			array( 'assignment.assign', 'assignment', 'Assign Records' ),
			array( 'assignment.reassign', 'assignment', 'Reassign Records' ),
			array( 'assignment.unassign', 'assignment', 'Unassign Records', 'Reserved: no unassign transition is defined for V1.', true ),
			array( 'assignment.receive', 'assignment', 'Receive Automatic Assignments', 'Eligible to be assigned registrations for the regions linked to the user.' ),

			// Approved data. Approved records are locked in V1 (Decisions §5).
			array( 'approved.view', 'approved', 'View Approved' ),
			array( 'approved.edit', 'approved', 'Edit Approved', 'Reserved: approved records are locked in V1.', true ),
			array( 'approved.delete', 'approved', 'Delete Approved', 'Reserved: approved records are locked in V1.', true ),
			array( 'approved.export', 'approved', 'Export Approved' ),

			// Bin.
			array( 'bin.view', 'bin', 'View Bin' ),
			array( 'bin.restore', 'bin', 'Restore' ),
			array( 'bin.delete', 'bin', 'Permanently Delete' ),
			array( 'bin.empty', 'bin', 'Empty Bin' ),

			// Analytics & reports.
			array( 'analytics.view', 'analytics', 'View Analytics' ),
			array( 'analytics.export', 'analytics', 'Export Analytics' ),
			array( 'reports.view', 'reports', 'View Reports' ),
			array( 'reports.create', 'reports', 'Create Reports', 'Reserved: V1 has a fixed set of reports, no custom report builder.', true ),
			array( 'reports.export', 'reports', 'Export Reports' ),

			// Electoral data import (ARCH §66).
			array( 'imports.view', 'imports', 'View Electoral Data' ),
			array( 'imports.upload', 'imports', 'Upload Workbook' ),
			array( 'imports.validate', 'imports', 'Validate Workbook' ),
			array( 'imports.confirm', 'imports', 'Confirm Import' ),
			array( 'imports.rollback', 'imports', 'Rollback Import' ),
			array( 'imports.view_history', 'imports', 'View Import History' ),

			// Users.
			array( 'users.view', 'users', 'View Users' ),
			array( 'users.create', 'users', 'Create Users' ),
			array( 'users.edit', 'users', 'Edit Users' ),
			array( 'users.disable', 'users', 'Disable Users' ),
			array( 'users.delete', 'users', 'Delete Users' ),

			// Roles.
			array( 'roles.view', 'roles', 'View Roles' ),
			array( 'roles.create', 'roles', 'Create Roles' ),
			array( 'roles.edit', 'roles', 'Edit Roles' ),
			array( 'roles.delete', 'roles', 'Delete Roles' ),
			array( 'roles.assign_permissions', 'roles', 'Assign Permissions' ),

			// Audit.
			array( 'audit.view', 'audit', 'View Audit Logs' ),
			array( 'audit.export', 'audit', 'Export Audit Logs' ),

			// Notifications.
			array( 'notifications.view', 'notifications', 'View Notification Log' ),
			array( 'notifications.retry', 'notifications', 'Retry Failed Notifications' ),

			// Settings (includes Form Builder / Field Configuration, ARCH §44).
			array( 'settings.view', 'settings', 'View Settings' ),
			array( 'settings.edit', 'settings', 'Edit Settings' ),
		);

		/**
		 * Filters permission definitions. Each entry: [key, group, label, description?, reserved?].
		 *
		 * @param array<int,array<int,mixed>> $definitions
		 */
		$definitions = apply_filters( 'dms_register_permissions', $definitions );

		$permissions = array();
		foreach ( $definitions as $def ) {
			$permission = new Permission(
				(string) $def[0],
				(string) $def[1],
				(string) $def[2],
				(string) ( $def[3] ?? '' ),
				(bool) ( $def[4] ?? false )
			);
			if ( ! Permission::is_valid_key( $permission->key ) ) {
				continue;
			}
			$permissions[ $permission->key ] = $permission;
		}
		return $permissions;
	}
}
