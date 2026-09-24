<?php
/**
 * Named workflow actions. Each maps to exactly one allowed state change in StateMachine.
 *
 * @package DMS
 */

namespace DMS\Workflow;

defined( 'ABSPATH' ) || exit;

enum Transition: string {

	case ASSIGN       = 'ASSIGN';
	case REASSIGN     = 'REASSIGN';
	case START_REVIEW = 'START_REVIEW';
	case APPROVE      = 'APPROVE';
	case DISAPPROVE   = 'DISAPPROVE';
	case RESTORE      = 'RESTORE';
	case DELETE       = 'DELETE';
}
