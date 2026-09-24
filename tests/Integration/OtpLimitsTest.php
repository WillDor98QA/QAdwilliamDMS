<?php
/**
 * Decisions §11 OTP send limits (per phone/hour, per phone/day, per IP/hour) and
 * rate limiter behaviour.
 */

namespace DMS\Tests\Integration;

use DMS\Errors\RateLimitedException;
use DMS\Plugin;
use DMS\Security\RateLimiter;

final class OtpLimitsTest extends \WP_UnitTestCase {

	use PublicFlowFixtures;

	private \DateTimeImmutable $t0;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_public_flow();
		$this->otp_on();
		$this->t0 = new \DateTimeImmutable( '2026-11-02 08:00:00', new \DateTimeZone( 'UTC' ) );
		Plugin::instance()->clock()->freeze( $this->t0 );
	}

	public function tear_down(): void {
		$this->tear_down_public_flow();
		parent::tear_down();
	}

	private function advance_to( int $seconds ): void {
		Plugin::instance()->clock()->freeze( $this->t0->modify( "+{$seconds} seconds" ) );
	}

	public function test_max_three_codes_per_phone_per_hour(): void {
		$otp = Plugin::instance()->otp();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->advance_to( $i * 61 );
			$otp->start( '+233241111111', false );
		}
		$this->advance_to( 3 * 61 );
		$this->expectException( RateLimitedException::class );
		$otp->start( '+233241111111', false );
	}

	public function test_max_ten_codes_per_phone_per_day(): void {
		$otp = Plugin::instance()->otp();
		for ( $i = 0; $i < 10; $i++ ) {
			$this->advance_to( $i * 3601 ); // A new hour each time, so only the daily limit applies.
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( $i + 1 );
			$otp->start( '+233242222222', false );
		}
		$this->advance_to( 10 * 3601 );
		try {
			$otp->start( '+233242222222', false );
			$this->fail( 'Daily limit must apply' );
		} catch ( RateLimitedException $e ) {
			$this->assertGreaterThan( 0, $e->retry_after );
		}
		$this->assertCount( 10, $this->sms->sent );
	}

	public function test_max_ten_codes_per_ip_per_hour(): void {
		$otp                    = Plugin::instance()->otp();
		$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
		for ( $i = 0; $i < 10; $i++ ) {
			$otp->start( '+2332430000' . sprintf( '%02d', $i ), false );
		}
		$this->expectException( RateLimitedException::class );
		$otp->start( '+233243000099', false );
	}

	public function test_limits_count_duplicate_numbers_too(): void {
		$otp = Plugin::instance()->otp();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->advance_to( $i * 61 );
			$otp->start( '+233244444444', true );
		}
		$this->assertCount( 0, $this->sms->sent, 'No SMS for duplicates' );
		$this->advance_to( 3 * 61 );
		$this->expectException( RateLimitedException::class );
		$otp->start( '+233244444444', true );
	}

	public function test_new_code_supersedes_the_old_request(): void {
		$otp   = Plugin::instance()->otp();
		$first = $otp->start( '+233245555555', false );
		$this->advance_to( 61 );
		$otp->start( '+233245555555', false );
		$this->assertSame( 'SUPERSEDED', $otp->find_by_request_id( $first->request_id )->status );
	}

	public function test_rate_limiter_window_resets(): void {
		$limiter = Plugin::instance()->rate_limiter();
		$bucket  = RateLimiter::bucket( 'test', 'subject' );
		$limiter->hit( $bucket, 2, 60 );
		$limiter->hit( $bucket, 2, 60 );
		try {
			$limiter->hit( $bucket, 2, 60 );
			$this->fail( 'Third hit must be limited' );
		} catch ( RateLimitedException $e ) {
			$this->assertLessThanOrEqual( 60, $e->retry_after );
		}
		$this->advance_to( 60 );
		$limiter->hit( $bucket, 2, 60 );
		$this->assertSame( 1, $limiter->count( $bucket, 60 ) );
	}

	public function test_buckets_store_hashed_subjects(): void {
		$this->assertStringNotContainsString( '+233246666666', RateLimiter::bucket( 'otp_phone_h', '+233246666666' ) );
	}
}
