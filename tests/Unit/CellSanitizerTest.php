<?php
/**
 * REQ-SEC-020 spreadsheet formula injection guard (OWASP CSV injection).
 */

namespace DMS\Tests\Unit;

use DMS\Export\CellSanitizer;
use PHPUnit\Framework\TestCase;

final class CellSanitizerTest extends TestCase {

	/** @return iterable<string,array{string}> */
	public static function dangerous(): iterable {
		foreach ( array( '=HYPERLINK("http://evil","x")', '+cmd|calc', '-2+3', '@SUM(A1)', "\tTAB", "\rCR" ) as $v ) {
			yield $v => array( $v );
		}
	}

	/** @dataProvider dangerous */
	public function test_formula_starts_are_neutralised( string $value ): void {
		$this->assertSame( "'" . $value, CellSanitizer::csv( $value ) );
	}

	public function test_ordinary_values_and_numbers_untouched(): void {
		$this->assertSame( 'Ama Mensah', CellSanitizer::csv( 'Ama Mensah' ) );
		$this->assertSame( '-5', CellSanitizer::csv( '-5' ) );
		$this->assertSame( '+233241234567', CellSanitizer::csv( '+233241234567' ), 'Phone numbers are numeric-looking and stay readable' );
		$this->assertSame( '', CellSanitizer::csv( null ) );
	}
}
