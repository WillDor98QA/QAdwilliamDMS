<?php
/**
 * REQ-DB-006 — atomic units of work roll back fully on failure (MP §38, ARCH §64).
 */

namespace DMS\Tests\Integration;

use DMS\Database\Tables;
use DMS\Database\Transaction;

final class TransactionTest extends \WP_UnitTestCase {

	private function region_count( string $code ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::name( Tables::REGIONS ) . ' WHERE code = %s', $code ) );
	}

	private function insert_region( string $code ): void {
		global $wpdb;
		$wpdb->insert( Tables::name( Tables::REGIONS ), array( 'code' => $code, 'name' => $code, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00' ) );
	}

	public function test_failure_rolls_back_everything_in_the_unit(): void {
		global $wpdb;
		try {
			Transaction::run(
				$wpdb,
				function (): void {
					$this->insert_region( 'TX1' );
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'Exception expected' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertSame( 0, $this->region_count( 'TX1' ) );
	}

	public function test_inner_failure_does_not_undo_outer_work_when_caught(): void {
		global $wpdb;
		Transaction::run(
			$wpdb,
			function () use ( $wpdb ): void {
				$this->insert_region( 'OUTER' );
				try {
					Transaction::run(
						$wpdb,
						function (): void {
							$this->insert_region( 'INNER' );
							throw new \RuntimeException( 'inner' );
						}
					);
				} catch ( \RuntimeException $e ) {
					// Swallowed deliberately for this test.
					unset( $e );
				}
			}
		);
		$this->assertSame( 1, $this->region_count( 'OUTER' ) );
		$this->assertSame( 0, $this->region_count( 'INNER' ) );
	}
}
