<?php
/**
 * Backend permission gate used by every service action (ARCH §25:
 * "Menu hidden ≠ Permission denied"). Uses current_user_can() so the
 * CapabilityManager's role resolution is the single source of truth.
 *
 * @package DMS
 */

namespace DMS\Support;

use DMS\Errors\AuthorizationException;

defined( 'ABSPATH' ) || exit;

class Authorizer {

	public function can( string $permission ): bool {
		return current_user_can( $permission );
	}

	/** @throws AuthorizationException */
	public function require( string ...$permissions ): void {
		foreach ( $permissions as $permission ) {
			if ( ! $this->can( $permission ) ) {
				throw new AuthorizationException();
			}
		}
	}
}
