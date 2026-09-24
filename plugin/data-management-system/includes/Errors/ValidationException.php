<?php
/**
 * Input failed validation. Carries per-field messages.
 *
 * @package DMS
 */

namespace DMS\Errors;

defined( 'ABSPATH' ) || exit;

final class ValidationException extends DmsException {

	/** @param array<string,string> $errors Field key => safe message. */
	public function __construct( public readonly array $errors, string $message = '' ) {
		parent::__construct( '' !== $message ? $message : __( 'Please correct the highlighted fields.', 'dms' ) );
	}

	public function http_status(): int {
		return 422;
	}

	public function error_code(): string {
		return 'dms_validation_failed';
	}
}
