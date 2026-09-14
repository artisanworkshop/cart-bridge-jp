<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WC_Product;
use WC_Product_Variation;

/**
 * `WC_Product`/`WC_Product_Variation`から在庫数量を導出する（`Woo\Reader\ProductReader`の
 * インポート向け在庫解決と`Woo\Reader\StockReader`の両方が同じ規約を共有するための切り出し）。
 * 在庫管理外（`null`）または在庫管理中で数量が不明な場合は0（CLAUDE.md: `null`は「在庫管理外」を
 * 意味するため、誤って「在庫あり」と解釈されないようフェイルクローズする）。
 */
final class StockDerivation {

	private function __construct() {}

	/**
	 * 単純商品/variable親の在庫数量。数量管理していない商品も`_stock_status`で明示的に
	 * 「在庫切れ」にされている場合は0にする（`null`＝在庫ありへの誤変換を避ける）。
	 */
	public static function for_product( WC_Product $product ): ?int {
		if ( ! $product->get_manage_stock() ) {
			return $product->is_in_stock() ? null : 0;
		}

		$quantity = $product->get_stock_quantity();

		return null !== $quantity ? (int) $quantity : 0;
	}

	/**
	 * バリエーションの在庫数量。親レベルで一括管理される在庫（`get_manage_stock()`が`'parent'`）は
	 * 複数バリエーションで共有する単一プールであり、`get_stock_quantity()`はこの場合も親の数量を
	 * そのまま返す。ASP側にバリエーションをまたぐ共有プールの概念が無い以上、親の数量を各
	 * バリエーションへ複製すると実在庫のバリエーション数倍を販売可能数量として申告してしまう
	 * （金銭的リスク）ため、在庫切れ（0）にフェイルクローズしたうえで`shared_with_parent`を
	 * 返す（呼び出し元が`WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT`を積む）。
	 *
	 * @return array{quantity:?int,shared_with_parent:bool}
	 */
	public static function for_variation( WC_Product_Variation $variation ): array {
		$manage_stock = $variation->get_manage_stock();

		if ( 'parent' === $manage_stock ) {
			return [
				'quantity'           => 0,
				'shared_with_parent' => true,
			];
		}

		if ( false === $manage_stock ) {
			return [
				'quantity'           => $variation->is_in_stock() ? null : 0,
				'shared_with_parent' => false,
			];
		}

		$quantity = $variation->get_stock_quantity();

		return [
			'quantity'           => null !== $quantity ? (int) $quantity : 0,
			'shared_with_parent' => false,
		];
	}
}
