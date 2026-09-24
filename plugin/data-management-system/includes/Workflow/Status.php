<?php
/**
 * Registration lifecycle states (ARCH §2, Decisions §5).
 *
 * @package DMS
 */

namespace DMS\Workflow;

defined( 'ABSPATH' ) || exit;

enum Status: string {

	case PENDING      = 'PENDING';
	case ASSIGNED     = 'ASSIGNED';
	case UNDER_REVIEW = 'UNDER_REVIEW';
	case APPROVED     = 'APPROVED';
	case DISAPPROVED  = 'DISAPPROVED';
	case DELETED      = 'DELETED';

	public function label(): string {
		return match ( $this ) {
			self::PENDING      => __( 'Pending', 'dms' ),
			self::ASSIGNED     => __( 'Assigned', 'dms' ),
			self::UNDER_REVIEW => __( 'Under Review', 'dms' ),
			self::APPROVED     => __( 'Approved', 'dms' ),
			self::DISAPPROVED  => __( 'Disapproved', 'dms' ),
			self::DELETED      => __( 'Deleted', 'dms' ),
		};
	}

	/** Holding Area = PENDING + ASSIGNED + UNDER_REVIEW (ARCH §2). */
	public function in_holding_area(): bool {
		return in_array( $this, self::holding_area(), true );
	}

	/** Editable only before approval (ARCH §71.2, Decisions §6). */
	public function is_editable(): bool {
		return $this->in_holding_area();
	}

	/** Counts toward an officer's active workload (ARCH §72.2). */
	public function counts_as_workload(): bool {
		return self::ASSIGNED === $this || self::UNDER_REVIEW === $this;
	}

	/** @return list<self> */
	public static function holding_area(): array {
		return array( self::PENDING, self::ASSIGNED, self::UNDER_REVIEW );
	}

	/** @return list<self> */
	public static function workload(): array {
		return array( self::ASSIGNED, self::UNDER_REVIEW );
	}
}
