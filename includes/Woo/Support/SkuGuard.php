<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Woo\WarningCode;
use WC_Data_Exception;
use WC_Product;

/**
 * 商品/バリエーション共通のSKU重複回避ロジック。他商品/バリエーションが既に保持している
 * SKUは奪わず、元SKUを`_cbjp_original_sku`メタへ退避してSKUを空で登録する
 * （フェイルクローズ: 実体自体の作成/更新は継続する）。`WC_Product_Variation`は
 * `WC_Product_Simple`のサブクラスであり`WC_Product`の型で扱えるため、
 * `Writer\ProductWriter`（商品）と`Writer\VariationWriter`（バリエーション）の両方で共用する。
 */
final class SkuGuard {

	private function __construct() {}

	/**
	 * @return array<int,string> 警告
	 */
	public static function apply( WC_Product $target, ?string $sku ): array {
		$sku = $sku ?? '';

		if ( '' !== $sku ) {
			$conflict = wc_get_product_id_by_sku( $sku );

			if ( 0 !== $conflict && $conflict !== $target->get_id() ) {
				$target->update_meta_data( '_cbjp_original_sku', $sku );

				return self::set_or_warn( $target, '', WarningCode::with_detail( WarningCode::SKU_DUPLICATE, $sku ) );
			}
		}

		return self::set_or_warn( $target, $sku, null );
	}

	/**
	 * @return array<int,string>
	 */
	private static function set_or_warn( WC_Product $target, string $sku, ?string $duplicate_warning ): array {
		try {
			$target->set_sku( $sku );
		} catch ( WC_Data_Exception ) {
			$target->update_meta_data( '_cbjp_original_sku', $sku );
			$target->set_sku( '' );

			return [ WarningCode::with_detail( WarningCode::SKU_DUPLICATE, $sku ) ];
		}

		return null !== $duplicate_warning ? [ $duplicate_warning ] : [];
	}
}
