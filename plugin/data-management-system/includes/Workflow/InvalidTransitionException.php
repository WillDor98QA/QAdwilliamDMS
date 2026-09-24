<?php
/**
 * Thrown when a transition is not permitted from the record's current state.
 * Maps to HTTP 409 with a user-safe message.
 *
 * @package DMS
 */

namespace DMS\Workflow;

use DMS\Errors\DmsException;

defined( 'ABSPATH' ) || exit;

final class InvalidTransitionException extends DmsException {

	public function __construct( public readonly Status $from, public readonly Transition $transition ) {
		parent::__construct(
			sprintf(
				/* translators: %s: current status label */
				__( 'This action is not available for a registration that is %s.', 'dms' ),
				$from->label()
			)
		);
	}

	public function http_status(): int {
		return 409;
	}

	public function error_code(): string {
		return 'dms_invalid_transition';
	}
}
