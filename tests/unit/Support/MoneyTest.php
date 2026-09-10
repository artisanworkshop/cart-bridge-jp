<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Support;

use CartBridgeJP\Support\Money;
use WP_UnitTestCase;

final class MoneyTest extends WP_UnitTestCase {

	/**
	 * @dataProvider amount_provider
	 */
	public function test_to_minor_units( mixed $input, ?int $expected ): void {
		$this->assertSame( $expected, Money::to_minor_units( $input ) );
	}

	/**
	 * @return array<string,array{0:mixed,1:?int}>
	 */
	public function amount_provider(): array {
		return [
			'integer string'                    => [ '1000', 100000 ],
			'one decimal'                       => [ '1234.5', 123450 ],
			'two decimals'                      => [ '12.34', 1234 ],
			'rounds half up at third decimal'   => [ '12.345', 1235 ],
			'ignores digits after the third'    => [ '12.3449', 1234 ],
			'negative'                          => [ '-12.345', -1235 ],
			'trailing dot'                      => [ '7.', 700 ],
			'surrounding whitespace'            => [ ' 3.00 ', 300 ],
			'int'                               => [ 5, 500 ],
			'float'                             => [ 10.5, 1050 ],
			'non numeric'                       => [ 'abc', null ],
			'thousands separator is not money'  => [ '1,000', null ],
			'empty'                             => [ '', null ],
			'null'                              => [ null, null ],
			'array'                             => [ [ '1' ], null ],
			'16 digit integer part is accepted' => [ '9999999999999999', 999999999999999900 ],
			'17 digit integer part overflows'   => [ '10000000000000000', null ],
			'huge int overflows'                => [ PHP_INT_MAX, null ],
		];
	}

	public function test_format_minor_units_round_trips(): void {
		$this->assertSame( '1234.56', Money::format_minor_units( 123456 ) );
		$this->assertSame( '0.05', Money::format_minor_units( 5 ) );
		$this->assertSame( '-12.35', Money::format_minor_units( -1235 ) );
		$this->assertSame( '1000.00', Money::format_minor_units( 100000 ) );
		$this->assertSame( '0.00', Money::format_minor_units( 0 ) );
		$this->assertSame( 123456, Money::to_minor_units( Money::format_minor_units( 123456 ) ) );
	}
}
