<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Woo\WarningCode;
use WC_Tax;

/**
 * 未知のtax_classを`WC_Tax::get_tax_class_slugs()`に対して検証し、標準税率へフェイルクローズする。
 * 未設定のtax_classをそのまま`WC_Product::set_tax_class()`/`WC_Order_Item_Product::set_tax_class()`に
 * 渡すと`WC_Data_Exception`を投げる（日本の軽減税率クラスを設定していないストアでは、軽減税率対象の
 * 商品・注文明細が全て失敗してしまう）。`ProductWriter`（商品）と`OrderItemBuilder`（注文明細）の
 * 両方が同じ検証・フェイルクローズを必要とするため、ここに集約する。
 */
final class TaxClass {

	/**
	 * @return array{0:string,1:array<int,string>} 適用すべきtax_class（フェイルクローズ済み）とwarnings。
	 */
	public static function resolve( ?string $tax_class ): array {
		if ( null === $tax_class || '' === $tax_class ) {
			return [ '', [] ];
		}

		if ( in_array( $tax_class, WC_Tax::get_tax_class_slugs(), true ) ) {
			return [ $tax_class, [] ];
		}

		return [ '', [ WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, $tax_class ) ] ];
	}
}
