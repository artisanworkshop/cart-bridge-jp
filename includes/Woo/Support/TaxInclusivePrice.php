<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use Automattic\WooCommerce\Utilities\NumberUtil;
use WC_Product;
use WC_Tax;

/**
 * Wooの商品価格を、`CanonicalProduct`の価格契約（消費者が実際に支払う税込金額）へ正規化する。
 *
 * 換算が必要なのは「税計算ON・税抜入力・課税商品」だけ。税計算OFF（`woocommerce_calc_taxes=no`。
 * フレッシュなWCの既定）では入力価格がそのまま消費者の支払額＝税込なので無変換で正しい。
 *
 * `wc_get_price_including_tax()`は使わない: 税抜モードでは`WC_Tax::get_rates()`（顧客ロケーション依存）を
 * 使い、Action Scheduler等の顧客が居ない文脈（および`WC()->customer`のロケーションが空の文脈）では
 * 税率0件になり**価格が無変換で返る**（実測: 基準所在地JP・税率10%登録済みでも`wc_get_price_including_tax(999)`
 * が`999.0`）。決定的な店舗の基準所在地の税率（`WC_Tax::get_base_tax_rates()`）で、`wc_get_price_including_tax()`
 * の税抜分岐と同じ計算・丸めを行う。
 */
final class TaxInclusivePrice {

	private function __construct() {}

	/**
	 * @param string $amount 検証済みの数値文字列（`get_regular_price()`/`get_sale_price()`の値）。
	 * @return array{0:?string,1:bool} [税込金額（換算不能ならnull）, 換算を適用したか]
	 */
	public static function from_product_price( WC_Product $product, string $amount ): array {
		if ( ! is_numeric( $amount ) ) {
			return [ null, false ];
		}

		if ( ! $product->is_taxable() || wc_prices_include_tax() ) {
			return [ $amount, false ];
		}

		$tax_class = $product->get_tax_class();
		$rates     = WC_Tax::get_base_tax_rates( $tax_class );

		if ( [] === $rates ) {
			// その税区分に税率が1件も登録されていなければ、Wooはそもそも課税しない（入力価格＝支払額）。
			// 税率は登録済みだが基準所在地に合致するものが無い場合は、どの税率で課税されるか
			// （顧客の配送先次第）を決められないため、誤った売価を本番へ送らないよう換算不能とする
			// （CLAUDE.mdアーキテクチャ原則9）。
			return [] === WC_Tax::get_rates_for_tax_class( $tax_class ) ? [ $amount, false ] : [ null, false ];
		}

		$price = (float) $amount;
		$taxes = WC_Tax::calc_tax( $price, $rates, false );

		// WC本体の税抜分岐と同じ丸め方（小計時に丸める設定かどうかで分かれる）に揃える。
		$tax_total = 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' )
			? array_sum( $taxes )
			: array_sum( array_map( 'wc_round_tax_total', $taxes ) );

		if ( $tax_total <= 0.0 ) {
			// 0%税率のみ（zero-rate等）。金額は変わらないため文字列表現も保つ。
			return [ $amount, false ];
		}

		$decimals = wc_get_price_decimals();

		return [ wc_format_decimal( NumberUtil::round( $price + $tax_total, $decimals ), $decimals ), true ];
	}
}
