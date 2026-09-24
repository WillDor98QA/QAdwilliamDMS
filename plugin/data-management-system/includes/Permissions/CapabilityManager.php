<?php
/**
 * Bridges application roles to WordPress capabilities (ARCH §20).
 *
 * WordPress answers "who is the user"; this class answers "what may they do"
 * by filtering `user_has_cap`, so every check in the plugin is a plain
 * current_user_can( 'review.approve' ) (ARCH §15, §25).
 *
 * The DMS tables are the only source of truth for registered permissions:
 * a registered key granted directly on a WordPress role is ignored.
 *
 * Rules, in order:
 *   1. Guests and disabled users have no application permissions.
 *   2. Reserved permissions are never granted (e.g. approved.edit — V1 locks approved records).
 *   3. Members of an active system role hold every other registered permission
 *      (implementation decision ID-02: lockout-proof bootstrap Administrator).
 *   4. Otherwise: union of permissions stored on the user's active roles (ARCH §19).
 *
 * @package DMS
 */

namespace DMS\Permissions;

use DMS\Users\UserProfileRepository;

defined( 'ABSPATH' ) || exit;

final class CapabilityManager {

	/** @var array<int,array{permissions:array<string,true>,all:bool}> */
	private array $cache = array();

	public function __construct(
		private PermissionRegistry $registry,
		private RoleRepository $roles,
		private UserProfileRepository $profiles,
	) {
	}

	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_filter( 'wp_authenticate_user', array( $this, 'block_disabled_login' ), 10, 1 );
		add_action( 'dms_permissions_changed', array( $this, 'flush' ) );
	}

	/**
	 * @param array<string,bool> $allcaps
	 * @param array<int,string>  $caps
	 * @param array<int,mixed>   $args
	 * @return array<string,bool>
	 */
	public function filter_user_has_cap( array $allcaps, array $caps, array $args, $user ): array {
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;
		foreach ( $caps as $cap ) {
			if ( $this->registry->has( $cap ) ) {
				$allcaps[ $cap ] = $this->user_has_permission( $user_id, $cap );
			}
		}
		return $allcaps;
	}

	public function user_has_permission( int $user_id, string $permission ): bool {
		$definition = $this->registry->get( $permission );
		if ( null === $definition || $definition->reserved ) {
			return false;
		}
		if ( $user_id <= 0 || ! $this->profiles->is_active( $user_id ) ) {
			return false;
		}
		$effective = $this->effective( $user_id );
		return $effective['all'] || isset( $effective['permissions'][ $permission ] );
	}

	/** @return list<string> Effective, grantable permission keys for the user. */
	public function permissions_for( int $user_id ): array {
		return array_values(
			array_filter(
				$this->registry->keys(),
				fn( string $key ): bool => $this->user_has_permission( $user_id, $key )
			)
		);
	}

	/**
	 * Refuses password login for disabled accounts.
	 *
	 * @param \WP_User|\WP_Error $user
	 * @return \WP_User|\WP_Error
	 */
	public function block_disabled_login( $user ) {
		if ( $user instanceof \WP_User && ! $this->profiles->is_active( (int) $user->ID ) ) {
			return new \WP_Error( 'dms_account_disabled', __( 'This account has been disabled. Please contact your administrator.', 'dms' ) );
		}
		return $user;
	}

	public function flush( ?int $user_id = null ): void {
		if ( null === $user_id ) {
			$this->cache = array();
		} else {
			unset( $this->cache[ $user_id ] );
		}
		$this->profiles->flush( $user_id );
	}

	/** @return array{permissions:array<string,true>,all:bool} */
	private function effective( int $user_id ): array {
		if ( ! isset( $this->cache[ $user_id ] ) ) {
			$stored                  = $this->roles->effective_for_user( $user_id );
			$this->cache[ $user_id ] = array(
				'permissions' => array_fill_keys( $stored['permissions'], true ),
				'all'         => $stored['has_system_role'],
			);
		}
		return $this->cache[ $user_id ];
	}
}
