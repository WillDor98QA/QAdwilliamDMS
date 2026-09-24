<?php
/**
 * The actor lacks the permission, or object-level access, for this action.
 *
 * @package DMS
 */

namespace DMS\Errors;

defined( 'ABSPATH' ) || exit;

final class AuthorizationException extends DmsException {

	public function __construct( string $message = '' ) {
		parent::__construct( '' !== $message ? $message : __( 'You do not have permission to perform this action.', 'dms' ) );
	}

	public function http_status(): int {
		return 403;
	}

	public function error_code(): string {
		return 'dms_forbidden';
	}
}
