<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Woo\Support\TaxClass;
use CartBridgeJP\Woo\WarningCode;
use WC_Cache_Helper;
use WC_Tax;
use WP_UnitTestCase;

/**
 * D26（issue #102）: 税区分を JP の税率で見分ける。テストスイートの WooCommerce は英語でインストールされ、
 * `reduced-rate`・`zero-rate`（税率なし）を持つ。日本語でインストールした店舗は `reduced-rate` を消して「軽減税」を作って再現する。
 */
final class TaxClassTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		update_option( 'woocommerce_default_country', 'JP:JP13' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
	}

	public function tear_down(): void {
		remove_all_filters( 'woocommerce_find_rates' );
		remove_all_filters( 'woocommerce_calc_tax' );

		parent::tear_down();
	}

	private function add_rate( string $tax_class, string $rate, string $country = 'JP', int $priority = 1, int $compound = 0 ): void {
		WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => $country,
				'tax_rate_state'    => '',
				'tax_rate'          => $rate,
				'tax_rate_name'     => 'Test ' . $rate,
				'tax_rate_priority' => $priority,
				'tax_rate_compound' => $compound,
				'tax_rate_shipping' => 0,
				'tax_rate_class'    => $tax_class,
			]
		);
	}

	/**
	 * 日本語でインストールした店舗の状態（`reduced-rate` が無く「軽減税」がある）を作り、そのスラッグを返す。
	 */
	private function japanese_reduced_class(): string {
		WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		return $this->create_class( '軽減税' );
	}

	private function create_class( string $name ): string {
		$created = WC_Tax::create_tax_class( $name );
		$this->assertIsArray( $created );

		return $created['slug'];
	}

	public function test_known_reduced_slugs_are_the_sanitized_default_names(): void {
		// スラッグは`sanitize_title()`の結果を固定値で持つ（実行時の言語に左右されない）。値が WooCommerce の作り方と一致することを固定する。
		$this->assertSame(
			[ 'reduced-rate', sanitize_title( '軽減税' ), sanitize_title( '軽減税率' ) ],
			TaxClass::KNOWN_REDUCED_SLUGS
		);
		$this->assertSame( CanonicalProduct::TAX_CLASS_REDUCED, TaxClass::CANONICAL_REDUCED );
	}

	public function test_the_standard_class_is_always_standard(): void {
		// 税率が 1 件も無くても、8% でも、標準の税区分は判定しない（基準所在地を設定していない新しい店舗で全商品を止めない）。
		$this->assertSame( TaxClass::STANDARD, TaxClass::classify( '' ) );

		$this->add_rate( '', '8.0000' );

		$this->assertSame( TaxClass::STANDARD, TaxClass::classify( '' ) );
		$this->assertNull( TaxClass::to_canonical( '' ) );
	}

	public function test_classifies_by_the_jp_rate(): void {
		$this->add_rate( 'reduced-rate', '8.0000' );
		$this->add_rate( 'zero-rate', '0.0000' );
		$other = $this->create_class( 'Other rate' );
		$this->add_rate( $other, '5.0000' );
		$standard_like = $this->create_class( 'Standard 10' );
		$this->add_rate( $standard_like, '10.0000' );

		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( 'reduced-rate' ) );
		$this->assertSame( TaxClass::UNSUPPORTED, TaxClass::classify( 'zero-rate' ) );
		$this->assertSame( TaxClass::UNSUPPORTED, TaxClass::classify( $other ) );
		$this->assertSame( TaxClass::STANDARD, TaxClass::classify( $standard_like ) );

		$this->assertSame( CanonicalProduct::TAX_CLASS_REDUCED, TaxClass::to_canonical( 'reduced-rate' ) );
		$this->assertNull( TaxClass::to_canonical( $standard_like ) );
		$this->assertSame( 'zero-rate', TaxClass::to_canonical( 'zero-rate' ) );
	}

	public function test_japanese_install_reduced_class_is_detected_by_its_rate(): void {
		$slug = $this->japanese_reduced_class();
		$this->add_rate( $slug, '8.0000' );

		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( $slug ) );
		$this->assertSame( CanonicalProduct::TAX_CLASS_REDUCED, TaxClass::to_canonical( $slug ) );
		$this->assertSame( [ $slug, [] ], TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED ) );
	}

	public function test_split_and_wildcard_rates_are_summed(): void {
		// 7.8%＋2.2%（国税・地方税の分割）＝10%、6.24%＋1.76%＝8%。国 `''` のワイルドカード行も JP の税率に含まれる。
		$standard = $this->create_class( 'Split standard' );
		$this->add_rate( $standard, '7.8000', 'JP', 1 );
		$this->add_rate( $standard, '2.2000', 'JP', 2 );
		$reduced = $this->create_class( 'Split reduced' );
		$this->add_rate( $reduced, '6.2400', 'JP', 1 );
		$this->add_rate( $reduced, '1.7600', 'JP', 2 );
		$this->add_rate( 'reduced-rate', '8.0000', '' );

		$this->assertSame( TaxClass::STANDARD, TaxClass::classify( $standard ) );
		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( $reduced ) );
		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( 'reduced-rate' ) );
	}

	public function test_uses_the_jp_rate_even_when_the_base_country_is_not_japan(): void {
		// WooCommerce の既定の基準所在地は US:CA。基準国を設定していない店舗でも JP の税率で判定する（D26「JP の税率」）。
		update_option( 'woocommerce_default_country', 'US:CA' );
		$this->add_rate( 'reduced-rate', '8.0000' );
		$this->add_rate( 'zero-rate', '8.0000', 'US' );

		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( 'reduced-rate' ) );
		// JP 以外の税率しか無い税区分は分からない。
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'zero-rate' ) );
	}

	public function test_rates_only_outside_japan_are_unconfigured_even_for_a_known_reduced_slug(): void {
		$this->add_rate( 'reduced-rate', '8.0000', 'US' );

		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'reduced-rate' ) );
	}

	public function test_a_class_without_any_rate_is_reduced_only_for_known_default_names(): void {
		// 税計算をしていない店舗の典型: 既定の税区分はあるが税率が 1 件も無い（Q3: 既定の名前なら軽減とみなす）。
		$japanese = $this->create_class( '軽減税' );
		$custom   = $this->create_class( 'No rate custom' );

		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( 'reduced-rate' ) );
		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( $japanese ) );
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'zero-rate' ) );
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( $custom ) );
	}

	public function test_a_missing_class_is_unconfigured(): void {
		WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'reduced-rate' ) );
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'never-existed' ) );
	}

	public function test_broken_filter_results_are_unconfigured(): void {
		$this->add_rate( 'reduced-rate', '8.0000' );

		// 税率が数値でない（`compound` はある）、`compound` が無い（税率は数値）。どちらかだけを壊して、それぞれの検査を固定する。
		add_filter( 'woocommerce_find_rates', static fn (): array => [ [ 'rate' => 'eight', 'compound' => 'no' ] ] );
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'reduced-rate' ) );

		remove_all_filters( 'woocommerce_find_rates' );
		add_filter( 'woocommerce_find_rates', static fn (): array => [ [ 'rate' => 8.0 ] ] );
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'reduced-rate' ) );

		// 判定はメモ化されるので、フィルターを差し替えるたびに WooCommerce の税のキャッシュを捨てて判定し直させる。
		remove_all_filters( 'woocommerce_find_rates' );
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( 'reduced-rate' ) );

		add_filter( 'woocommerce_find_rates', static fn (): string => 'broken' );
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'reduced-rate' ) );

		remove_all_filters( 'woocommerce_find_rates' );
		add_filter( 'woocommerce_calc_tax', static fn (): array => [ 1 => '8' ] );
		WC_Cache_Helper::invalidate_cache_group( 'taxes' );
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'reduced-rate' ) );
	}

	public function test_classification_follows_rate_and_class_changes(): void {
		// 1 リクエスト内のメモ化は WooCommerce の税のキャッシュの接頭辞と税区分の一覧をキーに含めるので、変更に追従する。
		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( 'zero-rate' ) );

		$this->add_rate( 'zero-rate', '10.0000' );
		$this->assertSame( TaxClass::STANDARD, TaxClass::classify( 'zero-rate' ) );

		$this->assertSame( TaxClass::UNCONFIGURED, TaxClass::classify( sanitize_title( '軽減税' ) ) );
		$this->create_class( '軽減税' );
		$this->assertSame( TaxClass::REDUCED, TaxClass::classify( sanitize_title( '軽減税' ) ) );
	}

	public function test_resolve_prefers_a_class_with_the_jp_reduced_rate(): void {
		// `reduced-rate` に 10% が入っていて（設定の誤り）、別の税区分に 8% がある: 税率で選ぶ。
		$this->add_rate( 'reduced-rate', '10.0000' );
		$food = $this->create_class( 'Food' );
		$this->add_rate( $food, '8.0000' );

		$this->assertSame( [ $food, [] ], TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED ) );
	}

	public function test_resolve_breaks_ties_by_known_default_names(): void {
		// 8% の税区分が複数: 既定の名前（`reduced-rate` → 軽減税）を先に選ぶ（どれも 8% で税額は同じ）。
		$first = $this->create_class( 'Aaa reduced' );
		$this->add_rate( $first, '8.0000' );
		$this->add_rate( 'reduced-rate', '8.0000' );

		$this->assertSame( [ 'reduced-rate', [] ], TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED ) );

		$japanese = $this->japanese_reduced_class();
		$this->add_rate( $japanese, '8.0000' );

		$this->assertSame( [ $japanese, [] ], TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED ) );
	}

	public function test_resolve_uses_the_first_class_in_woocommerce_order_without_known_names(): void {
		WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );
		$later   = $this->create_class( 'Zzz reduced' );
		$earlier = $this->create_class( 'Bbb reduced' );
		$this->add_rate( $later, '8.0000' );
		$this->add_rate( $earlier, '8.0000' );

		// `WC_Tax::get_tax_class_slugs()` は名前順。
		$this->assertSame( [ $earlier, [] ], TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED ) );
	}

	public function test_resolve_falls_back_to_a_default_class_without_rates(): void {
		// 新しい店舗・税計算をしていない店舗: 8% の税区分は無いが、既定の軽減税率の税区分（税率なし）がある。
		$this->assertSame(
			[ 'reduced-rate', [ WarningCode::with_detail( WarningCode::TAX_RATES_NOT_CONFIGURED, 'reduced-rate' ) ] ],
			TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED )
		);

		$japanese = $this->japanese_reduced_class();

		$this->assertSame(
			[ $japanese, [ WarningCode::with_detail( WarningCode::TAX_RATES_NOT_CONFIGURED, $japanese ) ] ],
			TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED )
		);
	}

	public function test_resolve_falls_back_to_standard_when_no_reduced_class_exists(): void {
		WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		$this->assertSame( [ '', [ WarningCode::REDUCED_TAX_CLASS_NOT_FOUND ] ], TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED ) );

		// 既定の名前の税区分に 8% 以外の税率がある（10% を入れた）場合も、税率の無いフォールバックには使わない。
		$this->create_class( 'Reduced rate' );
		$this->add_rate( 'reduced-rate', '10.0000' );

		$this->assertSame( [ '', [ WarningCode::REDUCED_TAX_CLASS_NOT_FOUND ] ], TaxClass::resolve( CanonicalProduct::TAX_CLASS_REDUCED ) );
	}

	public function test_resolve_keeps_the_existing_rules_for_other_slugs(): void {
		$this->assertSame( [ '', [] ], TaxClass::resolve( null ) );
		$this->assertSame( [ '', [] ], TaxClass::resolve( '' ) );
		$this->assertSame( [ '', [ WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, 'nope' ) ] ], TaxClass::resolve( 'nope' ) );
		$this->assertSame( [ 'zero-rate', [ WarningCode::with_detail( WarningCode::TAX_RATES_NOT_CONFIGURED, 'zero-rate' ) ] ], TaxClass::resolve( 'zero-rate' ) );

		$this->add_rate( 'zero-rate', '0.0000' );

		$this->assertSame( [ 'zero-rate', [] ], TaxClass::resolve( 'zero-rate' ) );
	}
}
