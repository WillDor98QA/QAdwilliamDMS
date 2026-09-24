<?php
/**
 * The action conflicts with current state: locked record, stale status,
 * duplicate, or a dependency that must not be broken.
 *
 * @package DMS
 */

namespace DMS\Errors;

defined( 'ABSPATH' ) || exit;

final class ConflictException extends DmsException {

	public function __construct( string $message, private string $code_suffix = 'conflict' ) {
		parent::__construct( $message );
	}

	public function http_status(): int {
		return 409;
	}

	public function error_code(): string {
		return 'dms_' . $this->code_suffix;
	}
}
