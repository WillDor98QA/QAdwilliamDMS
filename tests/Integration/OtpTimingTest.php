<?php
/**
 * R-01: the OTP step must not reveal, through response time, whether a phone
 * number is already registered (Decisions §10 enumeration protection).
 *
 * Bounded: 3 requests per path against the in-memory fake gateway.
 */

namespace DMS\Tests\Integration;

use DMS\Errors\UnavailableException;
use DMS\Otp\OtpService;
use DMS\Plugin;

final class OtpTimingTest extends \WP_UnitTestCase {

	use PublicFlowFixtures;

	private const FLOOR_MS   = 400;
	private const LATENCY_MS = 250;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_public_flow();
		$this->otp_on();
		add_filter( 'dms_otp_min_delivery_ms', static fn(): int => self::FLOOR_MS, 20 );
		$this->sms->latency_ms = self::LATENCY_MS;
	}

	public function tear_down(): void {
		$this->tear_down_public_flow();
		parent::tear_down();
	}

	/** Milliseconds taken by $fn. */
	private function time( callable $fn ): float {
		$t = microtime( true );
		try {
			$fn();
		} catch ( UnavailableException $e ) {
			unset( $e );
		}
		return 1000 * ( microtime( true ) - $t );
	}

	public function test_default_floor_covers_a_provider_round_trip(): void {
		$this->assertSame( 1500, OtpService::MIN_DELIVERY_MS );
	}

	public function test_duplicate_and_new_numbers_take_the_same_minimum_time(): void {
		$otp = Plugin::instance()->otp();
		for ( $i = 0; $i < 3; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( 10 + $i );
			$new                    = $this->time( fn() => $otp->start( '+23324555000' . $i, false ) );
			$dup                    = $this->time( fn() => $otp->start( '+23324666000' . $i, true ) );
			$this->assertGreaterThanOrEqual( self::FLOOR_MS - 5, $new, 'New number, attempt ' . $i );
			$this->assertGreaterThanOrEqual( self::FLOOR_MS - 5, $dup, 'Duplicate number, attempt ' . $i );
			$this->assertLessThan( 150, abs( $new - $dup ), sprintf( 'Timing gap %.0f ms (new %.0f, duplicate %.0f)', abs( $new - $dup ), $new, $dup ) );
		}
		$this->assertCount( 3, $this->sms->sent, 'Only the new numbers were sent an SMS' );
	}

	public function test_resend_is_padded_too(): void {
		$otp = Plugin::instance()->otp();
		$dup = $otp->start( '+233246660099', true );
		Plugin::instance()->clock()->freeze( new \DateTimeImmutable( '+2 minutes' ) );
		$this->assertGreaterThanOrEqual( self::FLOOR_MS - 5, $this->time( fn() => $otp->resend( $dup->request_id, '+233246660099' ) ) );
		Plugin::instance()->clock()->freeze( null );
	}

	public function test_a_provider_failure_is_no_faster_than_a_success(): void {
		$this->sms->fail       = true;
		$this->sms->latency_ms = 0;
		$this->assertGreaterThanOrEqual( self::FLOOR_MS - 5, $this->time( fn() => Plugin::instance()->otp()->start( '+233247770001', false ) ) );
	}
}
