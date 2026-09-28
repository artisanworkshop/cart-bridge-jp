<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Tests\Fixtures\VariableProductFactory;
use CartBridgeJP\Woo\Support\StockDerivation;
use WP_UnitTestCase;

final class StockDerivationTest extends WP_UnitTestCase {

	/**
	 * @return array<string,array{0:array<int,int|null>,1:bool}>
	 */
	public static function mixed_management_cases(): array {
		return [
			'managed and unmanaged in stock'  => [ [ 5, null ], true ],
			'unmanaged out of stock (0) and unmanaged in stock' => [ [ 0, null ], true ],
			'unmanaged in stock listed first' => [ [ null, 3, null ], true ],
			'all managed'                     => [ [ 5, 3, 0 ], false ],
			'all unmanaged in stock'          => [ [ null, null ], false ],
			'single managed'                  => [ [ 5 ], false ],
			'single unmanaged'                => [ [ null ], false ],
			'no variations'                   => [ [], false ],
		];
	}

	/**
	 * D22: 整数（管理中、または管理外の在庫切れ）と`null`（管理外の在庫あり）が両方あるときだけ混在。
	 * 「管理外の在庫切れ（0）」と「管理外の在庫あり（`null`）」の混在も混在として扱う。
	 *
	 * @dataProvider mixed_management_cases
	 * @param array<int,int|null> $quantities
	 */
	public function test_has_mixed_variation_management( array $quantities, bool $expected ): void {
		$this->assertSame( $expected, StockDerivation::has_mixed_variation_management( $quantities ) );
	}

	/**
	 * 判定の入力（`for_variation()['quantity']`）が想定どおりの値になること: 管理外の在庫切れは`0`（整数）、
	 * 管理外の在庫ありは`null`、親で一括管理は`0`（整数。共有プールをフェイルクローズ）。この対応が
	 * 混在判定の前提のため、ここで固定する。
	 */
	public function test_for_variation_quantities_feed_the_mixed_judgement_as_documented(): void {
		$parent_id = VariableProductFactory::create_parent( 'Stock shapes', [ 'Size' => [ 'S', 'M', 'L' ] ] );

		$managed          = wc_get_product(
			VariableProductFactory::add_variation(
				$parent_id,
				[ 'size' => 'S' ],
				[
					'manage_stock'   => true,
					'stock_quantity' => 4,
				]
			)
		);
		$unmanaged        = wc_get_product( VariableProductFactory::add_variation( $parent_id, [ 'size' => 'M' ] ) );
		$unmanaged_no_qty = wc_get_product( VariableProductFactory::add_variation( $parent_id, [ 'size' => 'L' ], [ 'stock_status' => 'outofstock' ] ) );

		$this->assertSame( 4, StockDerivation::for_variation( $managed )['quantity'] );
		$this->assertNull( StockDerivation::for_variation( $unmanaged )['quantity'] );
		$this->assertSame( 0, StockDerivation::for_variation( $unmanaged_no_qty )['quantity'] );
	}
}
