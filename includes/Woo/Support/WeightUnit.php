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

	/**
	 * `CanonicalProduct::weight`/`variant['weight']`はグラム単位。`WC_Product::set_weight()`は
	 * ストア設定単位の文字列を期待するため、`Writer\ProductWriter`（商品）・
	 * `Writer\VariationWriter`（バリエーション）の両方で必要な変換をここに集約する。
	 */
	public static function convert_from_grams( int $grams ): string {
		return (string) wc_get_weight( $grams, self::resolve(), 'g' );
	}
}
