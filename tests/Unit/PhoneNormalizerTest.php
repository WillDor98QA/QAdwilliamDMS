<?php
/**
 * REQ-REG-004 phone normalization (MP §11, ARCH §78.4, Decisions §8).
 */

namespace DMS\Tests\Unit;

use DMS\Registrations\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizerTest extends TestCase {

	/** @return iterable<string,array{string}> */
	public static function equivalent_forms(): iterable {
		foreach ( array( '0241234567', '024 123 4567', '024-123-4567', '+233241234567', '233241234567', '00233241234567', '+233 24 123 4567', '+233 (0) 24 123 4567', '(024) 123-4567', ' 0241234567 ' ) as $form ) {
			yield $form => array( $form );
		}
	}

	/** @dataProvider equivalent_forms */
	public function test_all_representations_resolve_to_one_canonical_value( string $input ): void {
		$this->assertSame( '+233241234567', PhoneNormalizer::normalize( $input ) );
	}

	/** @return iterable<string,array{string}> */
	public static function invalid_numbers(): iterable {
		$cases = array(
			'empty'                 => '',
			'letters'               => '024ABC4567',
			'too short'             => '024123456',
			'too long'              => '02412345678',
			'other country'         => '+447911123456',
			'landline range'        => '0302123456',
			'no trunk or country'   => '241234567',
			'double plus'           => '++233241234567',
			'plus in middle'        => '0241+234567',
			'script injection'      => '<script>0241234567',
			'country code only'     => '+233',
		);
		foreach ( $cases as $label => $value ) {
			yield $label => array( $value );
		}
	}

	/** @dataProvider invalid_numbers */
	public function test_invalid_numbers_are_rejected( string $input ): void {
		$this->assertNull( PhoneNormalizer::normalize( $input ) );
		$this->assertFalse( PhoneNormalizer::is_valid( $input ) );
	}

	public function test_accepts_5x_mobile_range(): void {
		$this->assertSame( '+233551234567', PhoneNormalizer::normalize( '055 123 4567' ) );
	}

	public function test_mask_shows_only_last_four_digits(): void {
		$masked = PhoneNormalizer::mask( '+233241234567' );
		$this->assertStringEndsWith( '4567', $masked );
		$this->assertStringNotContainsString( '241234', $masked );
	}
}
