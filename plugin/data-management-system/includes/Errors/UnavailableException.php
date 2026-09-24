<?php
/**
 * A required dependency (e.g. the SMS gateway) is not available. The action
 * fails closed rather than skipping a control (CR-01).
 *
 * @package DMS
 */

namespace DMS\Errors;

defined( 'ABSPATH' ) || exit;

final class UnavailableException extends DmsException {

	public function __construct( string $message = '' ) {
		parent::__construct( '' !== $message ? $message : __( 'This service is temporarily unavailable. Please try again later.', 'dms' ) );
	}

	public function http_status(): int {
		return 503;
	}

	public function error_code(): string {
		return 'dms_unavailable';
	}
}
