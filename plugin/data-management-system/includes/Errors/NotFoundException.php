<?php
/**
 * @package DMS
 */

namespace DMS\Errors;

defined( 'ABSPATH' ) || exit;

final class NotFoundException extends DmsException {

	public function __construct( string $message = '' ) {
		parent::__construct( '' !== $message ? $message : __( 'The requested record was not found.', 'dms' ) );
	}

	public function http_status(): int {
		return 404;
	}

	public function error_code(): string {
		return 'dms_not_found';
	}
}
