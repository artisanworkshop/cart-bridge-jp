<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WC_Product;

/**
 * 「在庫管理外＝在庫あり／数量指定＝在庫管理オン」という在庫状態の反映ロジックを共有する。
 * `Writer\ProductWriter`（商品）・`Writer\VariationWriter`（バリエーション）・
 * `Writer\StockWriter`（在庫単体更新）の3箇所でほぼ同一のロジックが必要なため、
 * `WC_Product`型（`WC_Product_Variation`もこの型で扱える）で共用する。
 */
final class StockApplier {

	private function __construct() {}

	/**
	 * @param bool $in_stock_when_unmanaged `$quantity`がnull（在庫管理外）の場合に
	 *   在庫あり扱いにするかどうか。`CanonicalProduct::stock`はnullを常に「在庫管理外＝
	 *   在庫あり」の意味で使う契約（CLAUDE.md）のためProductWriter/VariationWriterは
	 *   常にtrueで呼ぶ。`CanonicalStock`は在庫管理外の場合でも明示的な`in_stock`値を
	 *   持つため、StockWriterはその値を渡す。
	 */
	public static function apply( WC_Product $target, ?int $quantity, bool $in_stock_when_unmanaged = true ): void {
		if ( null === $quantity ) {
			$target->set_manage_stock( false );
			$target->set_stock_status( $in_stock_when_unmanaged ? 'instock' : 'outofstock' );

			return;
		}

		$target->set_manage_stock( true );
		$target->set_stock_quantity( $quantity );
		$target->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
	}
}
