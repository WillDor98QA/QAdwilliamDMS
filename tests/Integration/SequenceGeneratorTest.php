<?php
/**
 * REQ-REG-010 REG-YYYY-NNNNNN numbering (Decisions §7); REQ-IMPORT-010 IMPORT-YYYY-NNNNN (ARCH §56).
 */

namespace DMS\Tests\Integration;

use DMS\Database\SequenceGenerator;
use DMS\Database\Transaction;
use DMS\Plugin;
use DMS\Support\Clock;

final class SequenceGeneratorTest extends \WP_UnitTestCase {

	private function generator( string $at ): SequenceGenerator {
		global $wpdb;
		$clock = new Clock();
		$clock->freeze( new \DateTimeImmutable( $at, new \DateTimeZone( 'UTC' ) ) );
		return new SequenceGenerator( $wpdb, $clock );
	}

	public function test_registration_numbers_are_sequential_and_padded(): void {
		$gen = $this->generator( '2031-05-01 00:00:00' );
		$this->assertSame( 'REG-2031-000001', $gen->next_registration_number() );
		$this->assertSame( 'REG-2031-000002', $gen->next_registration_number() );
	}

	public function test_sequence_restarts_each_year(): void {
		$this->generator( '2032-12-31 23:59:59' )->next_registration_number();
		$this->assertSame( 'REG-2033-000001', $this->generator( '2033-01-01 00:00:00' )->next_registration_number() );
	}

	public function test_import_reference_format(): void {
		$this->assertSame( 'IMPORT-2034-00001', $this->generator( '2034-03-03 00:00:00' )->next_import_reference() );
	}

	public function test_rolled_back_transaction_does_not_consume_a_number(): void {
		global $wpdb;
		$gen = $this->generator( '2035-01-01 00:00:00' );
		try {
			Transaction::run(
				$wpdb,
				static function () use ( $gen ): void {
					$gen->next_registration_number();
					throw new \RuntimeException( 'abort' );
				}
			);
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$this->assertSame( 'REG-2035-000001', $gen->next_registration_number() );
	}

	public function test_numbers_are_separate_per_sequence_name(): void {
		$gen = $this->generator( '2036-01-01 00:00:00' );
		$gen->next_registration_number();
		$this->assertSame( 'IMPORT-2036-00001', $gen->next_import_reference() );
		unset( $gen );
		$this->assertInstanceOf( SequenceGenerator::class, Plugin::instance()->sequences() );
	}
}
