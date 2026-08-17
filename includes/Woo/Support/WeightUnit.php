<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * ストアの重量単位設定（`woocommerce_weight_unit`）を解決する。`Writer\ProductWriter`
 * （商品）・`Writer\VariationWriter`（バリエーション）の2箇所で同一ロジックが必要なため共用する。
 */
final class WeightUnit {

	private function __construct() {}

	/**
	 * `woocommerce_weight_unit`オプションが未設定・空文字列・非文字列の場合は`kg`にフォールバックする。
	 */
	public static function resolve(): string {
		$unit = get_option( 'woocommerce_weight_unit', 'kg' );

		return is_string( $unit ) && '' !== $unit ? $unit : 'kg';
	}
}
