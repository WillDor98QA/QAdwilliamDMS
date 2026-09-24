<?php
/**
 * In-memory SMS gateway for tests: records sends, can be made unconfigured or failing.
 */

namespace DMS\Tests\Support;

use DMS\Sms\SmsGateway;
use DMS\Sms\SmsResult;

final class FakeSmsGateway implements SmsGateway {

	public const ID = 'fake';

	/** @var list<array{to:string,message:string}> */
	public array $sent = array();

	public bool $configured = true;

	public bool $fail = false;

	/** Simulated provider round trip, in milliseconds. */
	public int $latency_ms = 0;

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'Fake (tests)';
	}

	public function fields(): array {
		return array( array( 'key' => 'fake_api_key', 'label' => 'API key', 'secret' => true, 'required' => true ) );
	}

	public function configuration_problems(): array {
		return $this->configured ? array() : array( 'Fake gateway API key is missing.' );
	}

	public function send( string $to, string $message ): SmsResult {
		if ( $this->latency_ms > 0 ) {
			usleep( $this->latency_ms * 1000 );
		}
		if ( $this->fail ) {
			return SmsResult::failed( 'Provider rejected the request (HTTP 401).' );
		}
		$this->sent[] = array( 'to' => $to, 'message' => $message );
		return SmsResult::sent( 'fake-' . count( $this->sent ) );
	}

	/** The 6-digit code in the most recent message. */
	public function last_code(): ?string {
		$last = end( $this->sent );
		return ( $last && preg_match( '/\b(\d{6})\b/', $last['message'], $m ) ) ? $m[1] : null;
	}
}
