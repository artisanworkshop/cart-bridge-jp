<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\TaxInclusivePrice;
use WC_Product_Simple;
use WC_Tax;
use WP_UnitTestCase;

final class TaxInclusivePriceTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		update_option( 'woocommerce_currency', 'JPY' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		update_option( 'woocommerce_default_country', 'JP' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
	}

	private function register_rate( string $country, string $rate, string $tax_class = '' ): void {
		WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => $country,
				'tax_rate_state'    => '',
				'tax_rate'          => $rate,
				'tax_rate_name'     => 'Test ' . $rate,
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => $tax_class,
			]
		);
	}

	private function product( string $tax_class = '', string $tax_status = 'taxable' ): WC_Product_Simple {
		$product = new WC_Product_Simple();
		$product->set_name( 'Tax test' );
		$product->set_regular_price( '1000' );
		$product->set_tax_class( $tax_class );
		$product->set_tax_status( $tax_status );
		$product->save();

		return $product;
	}

	public function test_tax_calculation_disabled_returns_the_amount_unchanged(): void {
		// 税計算OFF（フレッシュなWCの既定）では入力価格がそのまま消費者の支払額＝税込。
		update_option( 'woocommerce_calc_taxes', 'no' );
		$this->register_rate( 'JP', '10.0000' );

		$this->assertSame( [ '1000', false ], TaxInclusivePrice::from_product_price( $this->product(), '1000' ) );
	}

	public function test_prices_already_including_tax_are_returned_unchanged(): void {
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		$this->register_rate( 'JP', '10.0000' );

		$this->assertSame( [ '1000', false ], TaxInclusivePrice::from_product_price( $this->product(), '1000' ) );
	}

	public function test_non_taxable_product_is_returned_unchanged(): void {
		$this->register_rate( 'JP', '10.0000' );

		$this->assertSame( [ '1000', false ], TaxInclusivePrice::from_product_price( $this->product( '', 'none' ), '1000' ) );
	}

	public function test_converts_tax_exclusive_price_using_the_base_location_rate(): void {
		$this->register_rate( 'JP', '10.0000' );

		$this->assertSame( [ '1100', true ], TaxInclusivePrice::from_product_price( $this->product(), '1000' ) );
		// 税額99.9→100（`wc_round_tax_total`）。
		$this->assertSame( [ '1099', true ], TaxInclusivePrice::from_product_price( $this->product(), '999' ) );
	}

	public function test_reduced_rate_class_uses_its_own_rate(): void {
		$this->register_rate( 'JP', '10.0000' );
		$this->register_rate( 'JP', '8.0000', 'reduced-rate' );

		$this->assertSame( [ '1080', true ], TaxInclusivePrice::from_product_price( $this->product( 'reduced-rate' ), '1000' ) );
	}

	public function test_keeps_price_decimals_of_the_store(): void {
		update_option( 'woocommerce_price_num_decimals', '2' );
		$this->register_rate( 'JP', '10.0000' );

		$this->assertSame( [ '1100.00', true ], TaxInclusivePrice::from_product_price( $this->product(), '1000' ) );
	}

	/**
	 * `wc_get_price_including_tax()`の税抜分岐と同じ結果になること（丸めのドリフト検出）。
	 * 顧客ロケーションが無い文脈では`wc_get_price_including_tax()`は税率0件で価格を無変換で返すため
	 * （本クラスが`WC_Tax::get_base_tax_rates()`を使う理由）、ロケーションを基準所在地へ固定して比較する。
	 */
	public function test_matches_wc_get_price_including_tax_when_the_location_resolves_to_the_base(): void {
		$this->register_rate( 'JP', '10.0000' );
		add_filter(
			'woocommerce_get_tax_location',
			static fn (): array => [ 'JP', '', '', '' ]
		);

		$product = $this->product();

		foreach ( [ '1', '999', '1000', '1234', '4980', '10000' ] as $amount ) {
			[ $inclusive ] = TaxInclusivePrice::from_product_price( $product, $amount );

			$this->assertEqualsWithDelta(
				(float) wc_get_price_including_tax( $product, [ 'price' => (float) $amount ] ),
				(float) $inclusive,
				0.0001,
				"amount {$amount}"
			);
		}
	}

	/**
	 * 税率は登録済みだが店舗の基準所在地に合致しない場合、どの税率で課税されるか（顧客の配送先次第）を
	 * 決められない。誤った売価を送らないよう換算不能（null）にする。
	 */
	public function test_rates_registered_but_none_matching_the_base_location_is_unresolved(): void {
		$this->register_rate( 'US', '10.0000' );

		$this->assertSame( [ null, false ], TaxInclusivePrice::from_product_price( $this->product(), '1000' ) );
	}

	public function test_no_rates_for_the_tax_class_means_no_tax_is_charged(): void {
		// 標準税率は登録済みだが、軽減税率クラスには1件も無い場合、軽減税率クラスの商品は課税されない。
		$this->register_rate( 'JP', '10.0000' );

		$this->assertSame( [ '1000', false ], TaxInclusivePrice::from_product_price( $this->product( 'reduced-rate' ), '1000' ) );
	}

	public function test_no_rates_at_all_means_no_tax_is_charged(): void {
		$this->assertSame( [ '1000', false ], TaxInclusivePrice::from_product_price( $this->product(), '1000' ) );
	}

	public function test_zero_percent_rate_leaves_the_amount_unchanged(): void {
		$this->register_rate( 'JP', '0.0000' );

		$this->assertSame( [ '1000', false ], TaxInclusivePrice::from_product_price( $this->product(), '1000' ) );
	}

	public function test_non_numeric_amount_is_unresolved(): void {
		$this->register_rate( 'JP', '10.0000' );

		$this->assertSame( [ null, false ], TaxInclusivePrice::from_product_price( $this->product(), 'abc' ) );
	}
}
