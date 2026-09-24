<?php
/**
 * REQ-SEC-001 secrets never reach logs/audit metadata (MP §10 "No OTP values in logs").
 */

namespace DMS\Tests\Unit;

use DMS\Audit\AuditService;
use DMS\Support\Logger;
use DMS\Support\Redactor;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase {

	public function test_masks_secrets_recursively_and_keeps_ordinary_fields(): void {
		$out = Redactor::redact(
			array(
				'otp'         => '123456',
				'code'        => '654321',
				'password'    => 'hunter2',
				'user_pass'   => 'x',
				'api_key'     => 'k',
				'region_code' => 'GAR',
				'phone'       => '+233241234567',
				'nested'      => array( 'sms_api_token' => 't', 'name' => 'Ama' ),
			)
		);
		$this->assertSame( Redactor::MASK, $out['otp'] );
		$this->assertSame( Redactor::MASK, $out['code'] );
		$this->assertSame( Redactor::MASK, $out['password'] );
		$this->assertSame( Redactor::MASK, $out['user_pass'] );
		$this->assertSame( Redactor::MASK, $out['api_key'] );
		$this->assertSame( Redactor::MASK, $out['nested']['sms_api_token'] );
		$this->assertSame( 'GAR', $out['region_code'] );
		$this->assertSame( 'Ama', $out['nested']['name'] );
	}

	public function test_logger_writes_reference_and_never_the_otp(): void {
		$lines  = array();
		$logger = new Logger( static function ( string $line ) use ( &$lines ): void {
			$lines[] = $line;
		} );
		$ref = $logger->error( 'OTP send failed', array( 'otp' => '987654', 'phone_last4' => '4567' ) );

		$this->assertMatchesRegularExpression( '/^REF-[0-9A-F]{8}$/', $ref );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( $ref, $lines[0] );
		$this->assertStringNotContainsString( '987654', $lines[0] );
	}

	public function test_audit_scrub_replaces_pii_values_but_keeps_field_names(): void {
		$metadata = array(
			'changes' => array(
				'first_name'   => array( 'old' => 'Kofi', 'new' => 'Kwame' ),
				'organization' => array( 'old' => 'A', 'new' => 'B' ),
			),
			'phone'   => '+233241234567',
			'method'  => 'AUTOMATIC',
		);
		$out = AuditService::scrub( $metadata, array( 'first_name', 'organization', 'phone' ) );

		$this->assertSame( AuditService::SCRUBBED, $out['changes']['first_name'] );
		$this->assertSame( AuditService::SCRUBBED, $out['changes']['organization'] );
		$this->assertSame( AuditService::SCRUBBED, $out['phone'] );
		$this->assertSame( 'AUTOMATIC', $out['method'] );
		$this->assertSame( $out, AuditService::scrub( $out, array( 'first_name', 'organization', 'phone' ) ), 'Scrub is idempotent' );
	}
}
