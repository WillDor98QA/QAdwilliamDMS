<?php
/**
 * Shared setup for public-registration tests: fake SMS gateway, OTP toggle, clean
 * request context, Turnstile HTTP stub, and a valid public payload.
 */

namespace DMS\Tests\Integration;

use DMS\Plugin;
use DMS\Security\TurnstileVerifier;
use DMS\Support\Settings;
use DMS\Tests\Support\FakeSmsGateway;

trait PublicFlowFixtures {

	use Fixtures;

	protected FakeSmsGateway $sms;

	/** @var array{region:int,constituency:int,station:int} */
	protected array $h;

	private static int $phone_seq = 1000000;

	protected function set_up_public_flow(): void {
		$this->sms = new FakeSmsGateway();
		add_filter( 'dms_sms_gateways', fn( array $g ): array => $g + array( FakeSmsGateway::ID => $this->sms ) );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		delete_option( Settings::OPTION );
		$this->h = $this->make_hierarchy();
	}

	protected function tear_down_public_flow(): void {
		Plugin::instance()->clock()->freeze( null );
		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/** Sets settings directly (bypassing SettingsService authorization) for arranging tests. */
	protected function configure( array $values ): void {
		Plugin::instance()->settings()->update( $values );
	}

	protected function otp_on(): void {
		$this->configure( array( 'sms_gateway' => FakeSmsGateway::ID, 'otp_enabled' => true ) );
	}

	protected function otp_off(): void {
		$this->configure( array( 'otp_enabled' => false ) );
	}

	protected function next_phone(): string {
		return '024' . ( ++self::$phone_seq );
	}

	/** A complete, valid public payload. */
	protected function payload( array $overrides = array() ): array {
		return array_merge(
			array(
				'first_name'         => 'Efua',
				'last_name'          => 'Owusu',
				'date_of_birth'      => '1990-04-12',
				'gender'             => 'Female',
				'phone'              => $this->next_phone(),
				'email'              => 'efua' . wp_rand() . '@example.org',
				'organization'       => 'Community Group',
				'region_id'          => $this->h['region'],
				'constituency_id'    => $this->h['constituency'],
				'polling_station_id' => $this->h['station'],
				'consent'            => true,
			),
			$overrides
		);
	}

	/** Answers Turnstile verification: token "good" passes, anything else fails. */
	protected function stub_turnstile(): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				if ( TurnstileVerifier::VERIFY_URL !== $url ) {
					return $pre;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'success' => 'good' === ( $args['body']['response'] ?? '' ) ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	protected function registration_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . \DMS\Database\Tables::name( \DMS\Database\Tables::REGISTRATIONS ) );
	}
}
