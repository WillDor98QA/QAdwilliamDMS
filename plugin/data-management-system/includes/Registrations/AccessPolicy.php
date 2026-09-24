<?php
/**
 * Object-level access to registrations (Decisions §17; ARCH §16, §25, §73).
 *
 * A user may see a registration when any of these holds:
 *   registrations.view                          → any record
 *   registrations.view_assigned                 → records currently assigned to them
 *   registrations.view_holding                  → PENDING / ASSIGNED / UNDER_REVIEW
 *   approved.view or registrations.view_approved → APPROVED
 *   bin.view or registrations.view_bin          → DISAPPROVED
 * Tombstones (DELETED) are never shown as registrations; their history lives in the audit log.
 *
 * The same rules produce search scopes, so lists, detail views, edits,
 * exports and bulk actions can't disagree (implementation decision ID-27).
 *
 * @package DMS
 */

namespace DMS\Registrations;

use DMS\Errors\NotFoundException;
use DMS\Workflow\Status;

defined( 'ABSPATH' ) || exit;

class AccessPolicy {

	public function can_view( int $user_id, object $registration ): bool {
		$status = Status::tryFrom( (string) $registration->status );
		if ( null === $status || Status::DELETED === $status || $user_id <= 0 ) {
			return false;
		}
		if ( user_can( $user_id, 'registrations.view' ) ) {
			return true;
		}
		if ( user_can( $user_id, 'registrations.view_assigned' ) && (int) $registration->assigned_officer_id === $user_id ) {
			return true;
		}
		foreach ( $this->status_permissions() as $permission => $statuses ) {
			if ( in_array( $status, $statuses, true ) && user_can( $user_id, $permission ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Throws NotFound (not Forbidden) so the existence of records outside the
	 * user's scope is not revealed (MP §33 object-level authorization).
	 *
	 * @throws NotFoundException
	 */
	public function assert_can_view( int $user_id, object $registration ): void {
		if ( ! $this->can_view( $user_id, $registration ) ) {
			throw new NotFoundException();
		}
	}

	/**
	 * Statuses the user can see in full, and whether they can additionally see
	 * their own assignments in any status. Used to build list/export scopes.
	 *
	 * @return array{all:bool,statuses:list<Status>,own_assigned:bool}
	 */
	public function scope( int $user_id ): array {
		if ( $user_id > 0 && user_can( $user_id, 'registrations.view' ) ) {
			return array(
				'all'          => true,
				'statuses'     => array(),
				'own_assigned' => false,
			);
		}
		$statuses = array();
		foreach ( $this->status_permissions() as $permission => $granted ) {
			if ( $user_id > 0 && user_can( $user_id, $permission ) ) {
				$statuses = array_merge( $statuses, $granted );
			}
		}
		return array(
			'all'          => false,
			'statuses'     => array_values( array_unique( $statuses, SORT_REGULAR ) ),
			'own_assigned' => $user_id > 0 && user_can( $user_id, 'registrations.view_assigned' ),
		);
	}

	/** @return array<string,list<Status>> */
	private function status_permissions(): array {
		return array(
			'registrations.view_holding'  => Status::holding_area(),
			'registrations.view_approved' => array( Status::APPROVED ),
			'approved.view'               => array( Status::APPROVED ),
			'registrations.view_bin'      => array( Status::DISAPPROVED ),
			'bin.view'                    => array( Status::DISAPPROVED ),
		);
	}
}
