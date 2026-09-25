<?php
/**
 * Application user management through native WordPress users
 * (ARCH §12, §13, §72.1; MP §30; Decisions §4).
 *
 * - WordPress keeps authentication and password hashing: users are created
 *   with wp_insert_user() and never get a second password store.
 * - Users are disabled, not deleted, so audit attribution stays intact.
 * - A user whose (non-system) roles grant assignment.receive is an officer
 *   and must be linked to at least one active Region (ARCH §72.1, ID-14).
 * - Privilege-escalation guards (ID-13): an actor may only assign roles whose
 *   permissions they hold, and may only manage users whose permissions are a
 *   subset of their own (and who are not WordPress user administrators unless
 *   the actor is too).
 * - The last active member of the system Administrator role cannot be
 *   disabled or lose that role.
 *
 * @package DMS
 */

namespace DMS\Users;

use DMS\Audit\AuditAction;
use DMS\Audit\AuditService;
use DMS\Database\Migrations\M004AddStaffWordPressRole;
use DMS\Database\Transaction;
use DMS\Electoral\ElectoralLevel;
use DMS\Electoral\ElectoralRepository;
use DMS\Errors\AuthorizationException;
use DMS\Errors\ConflictException;
use DMS\Errors\NotFoundException;
use DMS\Errors\ValidationException;
use DMS\Permissions\CapabilityManager;
use DMS\Permissions\RoleRepository;
use DMS\Support\Authorizer;
use DMS\Support\RequestContext;

defined( 'ABSPATH' ) || exit;

class UserService {

	public const MIN_PASSWORD_LENGTH = 10;

	public function __construct(
		private \wpdb $db,
		private RoleRepository $roles,
		private UserProfileRepository $profiles,
		private OfficerRegionRepository $officer_regions,
		private ElectoralRepository $electoral,
		private CapabilityManager $capabilities,
		private AuditService $audit,
		private Authorizer $authorizer,
		private RequestContext $context,
	) {
	}

	/**
	 * @param array{first_name?:string,last_name?:string,email?:string,username?:string,password?:string,role_ids?:list<int>,region_ids?:list<int>,status?:string} $input
	 * @return int New WordPress user ID.
	 */
	public function create( array $input ): int {
		$this->authorizer->require( 'users.create' );

		$errors     = array();
		$first_name = $this->clean_name( $input['first_name'] ?? '', 'first_name', $errors );
		$last_name  = $this->clean_name( $input['last_name'] ?? '', 'last_name', $errors );
		$email      = sanitize_email( (string) ( $input['email'] ?? '' ) );
		$username   = sanitize_user( (string) ( $input['username'] ?? '' ), true );
		$password   = (string) ( $input['password'] ?? '' );
		$status     = (string) ( $input['status'] ?? UserProfileRepository::STATUS_ACTIVE );
		$role_ids   = array_map( 'intval', (array) ( $input['role_ids'] ?? array() ) );
		$region_ids = array_map( 'intval', (array) ( $input['region_ids'] ?? array() ) );

		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'dms' );
		} elseif ( email_exists( $email ) ) {
			$errors['email'] = __( 'This email address is already in use.', 'dms' );
		}
		if ( '' === $username || ! validate_username( $username ) ) {
			$errors['username'] = __( 'Enter a valid username.', 'dms' );
		} elseif ( username_exists( $username ) ) {
			$errors['username'] = __( 'This username is already taken.', 'dms' );
		}
		if ( mb_strlen( $password ) < self::MIN_PASSWORD_LENGTH ) {
			/* translators: %d: minimum password length */
			$errors['password'] = sprintf( __( 'Password must be at least %d characters.', 'dms' ), self::MIN_PASSWORD_LENGTH );
		}
		if ( ! in_array( $status, array( UserProfileRepository::STATUS_ACTIVE, UserProfileRepository::STATUS_DISABLED ), true ) ) {
			$errors['status'] = __( 'Invalid status.', 'dms' );
		}
		$roles = $this->validate_roles( $role_ids, $errors );
		$this->validate_regions( $roles, $region_ids, $errors );
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}
		$this->assert_can_assign_roles( $roles );

		$user_id = Transaction::run(
			$this->db,
			function () use ( $first_name, $last_name, $email, $username, $password, $status, $roles, $region_ids ): int {
				$user_id = wp_insert_user(
					array(
						'user_login'   => $username,
						'user_email'   => $email,
						'user_pass'    => $password,
						'first_name'   => $first_name,
						'last_name'    => $last_name,
						'display_name' => trim( "{$first_name} {$last_name}" ),
						'role'         => M004AddStaffWordPressRole::WP_ROLE,
					)
				);
				if ( is_wp_error( $user_id ) ) {
					throw new ValidationException( array( 'username' => $user_id->get_error_message() ) );
				}

				$this->profiles->ensure( $user_id );
				if ( UserProfileRepository::STATUS_DISABLED === $status ) {
					$this->profiles->set_status( $user_id, $status, $this->context->user_id() );
				}
				foreach ( array_keys( $roles ) as $role_id ) {
					$this->roles->assign_user( $user_id, $role_id );
				}
				$this->officer_regions->set_regions( $user_id, $region_ids, $this->context->user_id() );

				$this->audit->record(
					AuditAction::USER_CREATED,
					array(
						'object_type' => 'user',
						'object_id'   => $user_id,
						'metadata'    => array(
							'username'   => $username,
							'email'      => $email,
							'status'     => $status,
							'roles'      => array_values( array_map( static fn( object $r ): string => $r->slug, $roles ) ),
							'region_ids' => array_values( $region_ids ),
						),
					)
				);
				return $user_id;
			}
		);
		do_action( 'dms_officer_availability_changed', $user_id );
		return $user_id;
	}

	/**
	 * Updates profile fields, roles and/or regions. Keys that are absent are left unchanged.
	 *
	 * @param array{first_name?:string,last_name?:string,email?:string,role_ids?:list<int>,region_ids?:list<int>} $input
	 */
	public function update( int $user_id, array $input ): void {
		$this->authorizer->require( 'users.edit' );
		$user = $this->manageable_user( $user_id );

		$errors  = array();
		$profile = array();
		if ( array_key_exists( 'first_name', $input ) ) {
			$profile['first_name'] = $this->clean_name( $input['first_name'], 'first_name', $errors );
		}
		if ( array_key_exists( 'last_name', $input ) ) {
			$profile['last_name'] = $this->clean_name( $input['last_name'], 'last_name', $errors );
		}
		if ( array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( (string) $input['email'] );
			$owner = is_email( $email ) ? email_exists( $email ) : false;
			if ( ! is_email( $email ) ) {
				$errors['email'] = __( 'Enter a valid email address.', 'dms' );
			} elseif ( $owner && (int) $owner !== $user_id ) {
				$errors['email'] = __( 'This email address is already in use.', 'dms' );
			} else {
				$profile['user_email'] = $email;
			}
		}

		$current_role_ids = $this->roles->role_ids_for_user( $user_id );
		$new_role_ids     = array_key_exists( 'role_ids', $input ) ? array_values( array_unique( array_map( 'intval', (array) $input['role_ids'] ) ) ) : $current_role_ids;
		$roles            = $this->validate_roles( $new_role_ids, $errors, array_diff( $new_role_ids, $current_role_ids ) );
		$region_ids       = array_key_exists( 'region_ids', $input ) ? array_map( 'intval', (array) $input['region_ids'] ) : $this->officer_regions->active_region_ids( $user_id );
		$this->validate_regions( $roles, $region_ids, $errors );
		if ( array() !== $errors ) {
			throw new ValidationException( $errors );
		}

		$added_roles   = array_values( array_diff( $new_role_ids, $current_role_ids ) );
		$removed_roles = array_values( array_diff( $current_role_ids, $new_role_ids ) );
		if ( array() !== $added_roles || array() !== $removed_roles ) {
			$touched = $this->roles->find_many( array_merge( $added_roles, $removed_roles ) );
			$this->assert_can_assign_roles( $touched );
			$this->assert_not_last_admin_removal( $removed_roles );
		}

		Transaction::run(
			$this->db,
			function () use ( $user, $user_id, $profile, $added_roles, $removed_roles, $input, $region_ids ): void {
				$changes = array();
				foreach ( $profile as $key => $value ) {
					$old = 'user_email' === $key ? $user->user_email : get_user_meta( $user_id, $key, true );
					if ( (string) $old !== (string) $value ) {
						$changes[ $key ] = array(
							'old' => $old,
							'new' => $value,
						);
					}
				}
				if ( array() !== $changes ) {
					$result = wp_update_user( array_merge( array( 'ID' => $user_id ), array_map( static fn( array $c ): mixed => $c['new'], $changes ) ) );
					if ( is_wp_error( $result ) ) {
						throw new ValidationException( array( 'email' => $result->get_error_message() ) );
					}
					$this->audit->record(
						AuditAction::USER_UPDATED,
						array(
							'object_type' => 'user',
							'object_id'   => $user_id,
							'metadata'    => array( 'changes' => $changes ),
						)
					);
				}

				if ( array() !== $added_roles || array() !== $removed_roles ) {
					foreach ( $added_roles as $role_id ) {
						$this->roles->assign_user( $user_id, $role_id );
					}
					if ( array() !== $added_roles && array() === $user->roles ) {
						// Added back after removal: restore the staff role DMS gives its users, so they can open wp-admin.
						$user->add_role( M004AddStaffWordPressRole::WP_ROLE );
					}
					foreach ( $removed_roles as $role_id ) {
						$this->roles->remove_user( $user_id, $role_id );
					}
					$this->audit->record(
						AuditAction::USER_ROLES_CHANGED,
						array(
							'object_type' => 'user',
							'object_id'   => $user_id,
							'metadata'    => array(
								'added_role_ids'   => $added_roles,
								'removed_role_ids' => $removed_roles,
							),
						)
					);
				}

				if ( array_key_exists( 'region_ids', $input ) ) {
					$region_change = $this->officer_regions->set_regions( $user_id, $region_ids, $this->context->user_id() );
					if ( array() !== $region_change['added'] || array() !== $region_change['removed'] ) {
						$this->audit->record(
							AuditAction::USER_REGIONS_CHANGED,
							array(
								'object_type' => 'user',
								'object_id'   => $user_id,
								'metadata'    => array(
									'added_region_ids'   => $region_change['added'],
									'removed_region_ids' => $region_change['removed'],
								),
							)
						);
					}
				}
			}
		);
		do_action( 'dms_officer_availability_changed', $user_id );
	}

	/**
	 * Disables the account: no permissions, no login, sessions ended, no new
	 * assignments. Outstanding work is reported for reassignment (ARCH §72.5).
	 *
	 * @return int Number of outstanding ASSIGNED/UNDER_REVIEW registrations to reassign.
	 */
	public function disable( int $user_id, string $reason = '' ): int {
		$this->authorizer->require( 'users.disable' );
		if ( $user_id === $this->context->user_id() ) {
			throw new ConflictException( __( 'You cannot disable your own account.', 'dms' ), 'self_disable' );
		}
		$this->manageable_user( $user_id );
		if ( ! $this->profiles->is_active( $user_id ) ) {
			return $this->officer_regions->outstanding_count( $user_id );
		}
		$this->assert_not_last_admin_removal( $this->system_role_ids_of( $user_id ) );

		return Transaction::run(
			$this->db,
			function () use ( $user_id, $reason ): int {
				$this->profiles->set_status( $user_id, UserProfileRepository::STATUS_DISABLED, $this->context->user_id() );
				$outstanding = $this->officer_regions->outstanding_count( $user_id );
				$this->audit->record(
					AuditAction::USER_DISABLED,
					array(
						'object_type' => 'user',
						'object_id'   => $user_id,
						'reason'      => '' !== $reason ? sanitize_textarea_field( $reason ) : null,
						'metadata'    => array( 'outstanding_registrations' => $outstanding ),
					)
				);
				\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
				return $outstanding;
			}
		);
	}

	public function enable( int $user_id ): void {
		$this->authorizer->require( 'users.disable' );
		$this->manageable_user( $user_id );
		if ( $this->profiles->is_active( $user_id ) ) {
			return;
		}
		Transaction::run(
			$this->db,
			function () use ( $user_id ): void {
				$this->profiles->set_status( $user_id, UserProfileRepository::STATUS_ACTIVE, $this->context->user_id() );
				$this->audit->record(
					AuditAction::USER_ENABLED,
					array(
						'object_type' => 'user',
						'object_id'   => $user_id,
					)
				);
			}
		);
		do_action( 'dms_officer_availability_changed', $user_id );
	}

	/**
	 * Removes the user from DMS: every DMS role, region and the DMS profile are
	 * removed and their sessions end. The WordPress account and all history
	 * (registrations, approvals, audit) are kept, so past records keep their
	 * name; the user can be given roles again later (user decision 2026-09-25).
	 *
	 * @throws ConflictException Own account, open work still assigned, or last active Administrator.
	 */
	public function remove( int $user_id, string $reason = '' ): void {
		$this->authorizer->require( 'users.delete' );
		if ( $user_id === $this->context->user_id() ) {
			throw new ConflictException( __( 'You cannot remove your own account.', 'dms' ), 'self_remove' );
		}
		$this->manageable_user( $user_id );
		$outstanding = $this->officer_regions->outstanding_count( $user_id );
		if ( $outstanding > 0 ) {
			throw new ConflictException(
				/* translators: %d: number of registrations */
				sprintf( _n( 'This user still has %d open registration. Reassign it first, then remove the user.', 'This user still has %d open registrations. Reassign them first, then remove the user.', $outstanding, 'dms' ), $outstanding ),
				'open_work'
			);
		}
		$role_ids = $this->roles->role_ids_for_user( $user_id );
		if ( $this->profiles->is_active( $user_id ) ) {
			// Only an active Administrator counts towards "at least one must remain" (same rule as disable).
			$this->assert_not_last_admin_removal( $role_ids );
		}
		$region_ids = $this->officer_regions->active_region_ids( $user_id );

		Transaction::run(
			$this->db,
			function () use ( $user_id, $reason, $role_ids, $region_ids ): void {
				foreach ( $role_ids as $role_id ) {
					$this->roles->remove_user( $user_id, (int) $role_id );
				}
				$this->officer_regions->set_regions( $user_id, array(), $this->context->user_id() );
				$this->profiles->forget( $user_id );
				$user = get_user_by( 'id', $user_id );
				if ( $user ) {
					$user->remove_role( M004AddStaffWordPressRole::WP_ROLE ); // Only the role DMS itself gave.
				}
				$this->audit->record(
					AuditAction::USER_REMOVED,
					array(
						'object_type' => 'user',
						'object_id'   => $user_id,
						'reason'      => '' !== $reason ? sanitize_textarea_field( $reason ) : null,
						'metadata'    => array(
							'removed_role_ids'   => array_values( array_map( 'intval', $role_ids ) ),
							'removed_region_ids' => array_values( array_map( 'intval', $region_ids ) ),
						),
					)
				);
				\WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			}
		);
		do_action( 'dms_officer_availability_changed', $user_id );
	}

	/**
	 * The actor may manage the target only if the target's permissions are a
	 * subset of the actor's, and only a WordPress user administrator may
	 * manage another one (prevents account takeover via email change).
	 */
	private function manageable_user( int $user_id ): \WP_User {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			throw new NotFoundException();
		}
		$actor_id = $this->context->user_id();
		if ( user_can( $user, 'edit_users' ) && ! user_can( $actor_id, 'edit_users' ) ) {
			throw new AuthorizationException();
		}
		// Compare stored role grants, so a disabled target is still protected.
		$target = $this->roles->effective_for_user( $user_id );
		if ( $target['has_system_role'] ) {
			if ( ! $this->actor_is_administrator() ) {
				throw new AuthorizationException( __( 'Only an Administrator can manage an Administrator.', 'dms' ) );
			}
			return $user;
		}
		if ( array() !== array_diff( $target['permissions'], $this->capabilities->permissions_for( $actor_id ) ) ) {
			throw new AuthorizationException( __( 'You cannot manage a user who has permissions you do not hold.', 'dms' ) );
		}
		return $user;
	}

	private function actor_is_administrator(): bool {
		$actor_id = $this->context->user_id();
		return $this->profiles->is_active( $actor_id ) && $this->roles->effective_for_user( $actor_id )['has_system_role'];
	}

	/**
	 * @param array<int,object> $roles role_id => row
	 * @throws AuthorizationException
	 */
	private function assert_can_assign_roles( array $roles ): void {
		$actor_perms = $this->capabilities->permissions_for( $this->context->user_id() );
		$actor_admin = $this->actor_is_administrator();
		foreach ( $roles as $role ) {
			if ( (int) $role->is_system && ! $actor_admin ) {
				throw new AuthorizationException( __( 'Only an Administrator can assign the Administrator role.', 'dms' ) );
			}
			if ( array() !== array_diff( $this->roles->permissions( (int) $role->id ), $actor_perms ) ) {
				throw new AuthorizationException( __( 'You cannot assign a role with permissions you do not hold.', 'dms' ) );
			}
		}
	}

	/** @param list<int> $removed_role_ids */
	private function assert_not_last_admin_removal( array $removed_role_ids ): void {
		$removed = $this->roles->find_many( $removed_role_ids );
		$touches = array_filter( $removed, static fn( object $r ): bool => (bool) (int) $r->is_system );
		if ( array() !== $touches && $this->roles->count_system_role_members() <= 1 ) {
			throw new ConflictException( __( 'At least one active Administrator must remain.', 'dms' ), 'last_administrator' );
		}
	}

	/** @return list<int> */
	private function system_role_ids_of( int $user_id ): array {
		return array_values(
			array_map(
				static fn( object $r ): int => (int) $r->id,
				array_filter( $this->roles->roles_for_user( $user_id ), static fn( object $r ): bool => (bool) (int) $r->is_system )
			)
		);
	}

	/**
	 * @param list<int>             $role_ids
	 * @param array<string,string>  $errors
	 * @param list<int>|null        $newly_added Only these must be active (null = all). A role that was
	 *                                           deactivated after assignment must not block unrelated edits.
	 * @return array<int,object> role_id => row
	 */
	private function validate_roles( array $role_ids, array &$errors, ?array $newly_added = null ): array {
		$roles = $this->roles->find_many( $role_ids );
		if ( count( $roles ) !== count( array_unique( array_filter( $role_ids ) ) ) ) {
			$errors['role_ids'] = __( 'One or more selected roles do not exist.', 'dms' );
		}
		foreach ( $roles as $role_id => $role ) {
			if ( null !== $newly_added && ! in_array( $role_id, array_map( 'intval', $newly_added ), true ) ) {
				continue;
			}
			if ( RoleRepository::STATUS_ACTIVE !== $role->status ) {
				$errors['role_ids'] = __( 'Inactive roles cannot be assigned.', 'dms' );
			}
		}
		return $roles;
	}

	/**
	 * ARCH §72.1: Region is required for officers. An officer is a user whose
	 * non-system roles grant assignment.receive (ID-14).
	 *
	 * @param array<int,object>    $roles
	 * @param list<int>            $region_ids
	 * @param array<string,string> $errors
	 */
	private function validate_regions( array $roles, array $region_ids, array &$errors ): void {
		foreach ( $region_ids as $region_id ) {
			$region = $this->electoral->find( ElectoralLevel::REGION, $region_id );
			if ( null === $region || ElectoralRepository::STATUS_ACTIVE !== $region->status ) {
				$errors['region_ids'] = __( 'One or more selected regions are not available.', 'dms' );
				return;
			}
		}
		$is_officer = false;
		foreach ( $roles as $role ) {
			if ( ! (int) $role->is_system && in_array( 'assignment.receive', $this->roles->permissions( (int) $role->id ), true ) ) {
				$is_officer = true;
				break;
			}
		}
		if ( $is_officer && array() === $region_ids ) {
			$errors['region_ids'] = __( 'Select at least one Region for an officer.', 'dms' );
		}
	}

	/** @param array<string,string> $errors */
	private function clean_name( mixed $value, string $field, array &$errors ): string {
		$name = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $name || mb_strlen( $name ) > 100 ) {
			$errors[ $field ] = __( 'This field is required (up to 100 characters).', 'dms' );
		}
		return $name;
	}
}
