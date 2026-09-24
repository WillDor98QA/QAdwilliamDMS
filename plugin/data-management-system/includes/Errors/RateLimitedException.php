<?php
/**
 * Too many requests for a rate-limited public action (Decisions §39).
 *
 * @package DMS
 */

namespace DMS\Errors;

defined( 'ABSPATH' ) || exit;

final class RateLimitedException extends DmsException {

	public function __construct( public readonly int $retry_after, string $message = '' ) {
		parent::__construct( '' !== $message ? $message : __( 'Too many attempts. Please wait a moment and try again.', 'dms' ) );
	}

	public function http_status(): int {
		return 429;
	}

	public function error_code(): string {
		return 'dms_rate_limited';
	}
}
