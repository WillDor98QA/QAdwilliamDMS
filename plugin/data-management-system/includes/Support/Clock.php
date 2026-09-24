<?php
/**
 * Time source. All persisted timestamps are UTC ('Y-m-d H:i:s').
 *
 * Injected so retention, OTP expiry and tie-breaking can be tested deterministically.
 *
 * @package DMS
 */

namespace DMS\Support;

defined( 'ABSPATH' ) || exit;

class Clock {

	private ?\DateTimeImmutable $frozen = null;

	public function now(): \DateTimeImmutable {
		return $this->frozen ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function now_mysql(): string {
		return $this->now()->format( 'Y-m-d H:i:s' );
	}

	/** Microsecond precision, for ordering events that can share a second (R-04). */
	public function now_mysql_precise(): string {
		return $this->now()->format( 'Y-m-d H:i:s.u' );
	}

	/** Test helper: freeze time at a given UTC moment. Pass null to unfreeze. */
	public function freeze( ?\DateTimeImmutable $at ): void {
		$this->frozen = $at?->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
