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
	 * @param bool $in_stock ASP側の明示的な在庫あり/なしフラグ。`$quantity`がnull（在庫管理外）
	 *   の場合はこの値がそのままstock_statusになる。`CanonicalProduct::stock`はnullを常に
	 *   「在庫管理外＝在庫あり」の意味で使う契約（CLAUDE.md）のためProductWriter/
	 *   VariationWriterは常にtrueで呼ぶ。`CanonicalStock`はquantityとin_stockを独立した
	 *   フィールドとして持ち、両者が矛盾する組み合わせ（例: quantityは残っているがASP側で
	 *   個別に「取り扱い停止」等の理由でin_stock=false）を否定していない。
	 *
	 *   `$quantity`が非null（在庫管理オン）の場合、`WC_Product::validate_props()`が
	 *   `save()`の度に`stock_status`を`stock_quantity`（とストアのbackorders設定）から
	 *   必ず再計算し直すため（WooCommerce自身の仕様。ここで`set_stock_status()`に何を
	 *   渡しても`stock_quantity > 0`であれば`save()`後は'instock'に上書きされてしまう）、
	 *   `set_stock_status()`単体では在庫管理オンのまま「数量はあるが販売不可」を表現できない。
	 *   ASP側が明示的にin_stock=falseとした場合は数量を0として書き込み、WooCommerce自身の
	 *   再計算でも確実にoutofstockになるようにする（正確な残数よりも販売不可を優先する）。
	 */
	public static function apply( WC_Product $target, ?int $quantity, bool $in_stock = true ): void {
		if ( null === $quantity ) {
			$target->set_manage_stock( false );
			$target->set_stock_status( $in_stock ? 'instock' : 'outofstock' );

			return;
		}

		$target->set_manage_stock( true );
		$target->set_stock_quantity( $in_stock ? $quantity : 0 );
		$target->set_stock_status( $quantity > 0 && $in_stock ? 'instock' : 'outofstock' );
	}
}
