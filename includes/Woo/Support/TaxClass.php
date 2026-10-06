<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Woo\WarningCode;
use WC_Cache_Helper;
use WC_Tax;

/**
 * Woo の税区分と正規化モデルの税区分（`CanonicalProduct::$tax_class`＝`null` が標準・`CANONICAL_REDUCED` が軽減。
 * `CanonicalOrder` の明細は `tax_reduced`）の相互変換を 1 か所に集める（D26、issue #102）。
 *
 * WooCommerce は既定の税区分を有効化したときの言語の名前から作る（英語 `Reduced rate`＝`reduced-rate`、
 * 日本語 `軽減税`＝URL エンコードのスラッグ）ため、スラッグの決め打ちでは日本語でインストールした店舗の軽減税率を
 * 見失う。**税区分は JP の税率で見分ける**: 税率が日本の標準税率（10%）なら標準、軽減税率（8%）なら軽減とみなす。
 *
 * 取込み（`resolve()`）は、未設定の税区分をそのまま `WC_Product::set_tax_class()`/`WC_Order_Item_Product::set_tax_class()`
 * に渡すと `WC_Data_Exception` になるため、存在する税区分へフェイルクローズする（`ProductWriter`・`OrderItemBuilder` が共有）。
 * エクスポート（`classify()`/`to_canonical()`）は `ProductReader`・`OrderReader` が使い、標準・軽減のどちらでもない税区分は
 * 止める警告とセットで扱う（R3-1d、issue #78）。
 */
final class TaxClass {

	/** `classify()` の結果: 日本の標準税率（10%）、または JP の税率が無い標準の税区分（`''`）。 */
	public const STANDARD = 'standard';

	/** `classify()` の結果: 日本の軽減税率（8%）。 */
	public const REDUCED = 'reduced';

	/** `classify()` の結果: JP の税率が標準・軽減のどちらでもない（0% を含む）。 */
	public const UNSUPPORTED = 'unsupported';

	/**
	 * `classify()` の結果: JP の税率が分からない（標準以外で税率が他の地域にしか無い、税率が 1 件も無い既定の軽減税率以外の税区分、
	 * 存在しない税区分、フィルターが壊れた値を返した、税区分が文字列でない）。
	 */
	public const UNCONFIGURED = 'unconfigured';

	/** 正規化モデルの「軽減税率」の記号（`CanonicalProduct::TAX_CLASS_REDUCED`。Woo のスラッグではない）。 */
	public const CANONICAL_REDUCED = CanonicalProduct::TAX_CLASS_REDUCED;

	/**
	 * 日本の消費税率（%）。判定は税率を 100 倍した整数で比べる（D26 では `shop.json` の値としていたが、Woo 層は
	 * プラットフォームの店舗設定を持たないため法定税率の定数にした。2026-10-06 ユーザー決定）。
	 */
	public const STANDARD_RATE = 10;
	public const REDUCED_RATE  = 8;

	/**
	 * WooCommerce が既定で作る軽減税率の税区分のスラッグ（優先順）。英語 `Reduced rate`、日本語 `軽減税`
	 * （WooCommerce の日本語訳。古い訳の `軽減税率` も含める）を `sanitize_title()` したもの。`__( 'Reduced rate', 'woocommerce' )`
	 * を実行時に引かない: 既定の税区分は有効化したときの言語で作られ、実行時の言語（REST はユーザーの言語、WP-CLI・
	 * Action Scheduler はサイトの言語）と一致する保証が無いため。
	 *
	 * @var array<int,string>
	 */
	public const KNOWN_REDUCED_SLUGS = [
		'reduced-rate',
		'%e8%bb%bd%e6%b8%9b%e7%a8%8e',
		'%e8%bb%bd%e6%b8%9b%e7%a8%8e%e7%8e%87',
	];

	/**
	 * `to_canonical()` が標準・軽減以外の税区分に付ける接頭辞（正規化モデルの記号 null・`CANONICAL_REDUCED` と衝突させない）。
	 */
	public const UNSUPPORTED_PREFIX = 'woo:';

	private const CACHE_GROUP = 'cbjp_tax_class';

	private function __construct() {}

	/**
	 * 取込み: 正規化モデルの税区分を、Woo に保存する税区分へ解決する。
	 *
	 * - `null`/`''` は標準（`''`）。
	 * - `CANONICAL_REDUCED` は JP の税率が 8% の税区分（`reduced_class()`）。
	 * - それ以外（外部アダプタが Woo のスラッグを渡した場合）は、存在しなければ標準へ倒して `TAX_CLASS_MISSING`、
	 *   税率が 1 件も無ければそのまま使って `TAX_RATES_NOT_CONFIGURED`（標準へ倒すと税額を丸ごと変えてしまうため）。
	 *
	 * @return array{0:string,1:array<int,string>} 適用すべき税区分（フェイルクローズ済み）と warnings。
	 */
	public static function resolve( ?string $tax_class ): array {
		if ( null === $tax_class || '' === $tax_class ) {
			return [ '', [] ];
		}

		if ( self::CANONICAL_REDUCED === $tax_class ) {
			return self::reduced_class();
		}

		if ( ! in_array( $tax_class, WC_Tax::get_tax_class_slugs(), true ) ) {
			return [ '', [ WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, $tax_class ) ] ];
		}

		if ( [] === WC_Tax::get_rates_for_tax_class( $tax_class ) ) {
			return [ $tax_class, [ WarningCode::with_detail( WarningCode::TAX_RATES_NOT_CONFIGURED, $tax_class ) ] ];
		}

		return [ $tax_class, [] ];
	}

	/**
	 * エクスポート: Woo の税区分を正規化モデルの税区分へ。標準は `null`、軽減は `CANONICAL_REDUCED`。どちらでもない
	 * 税区分は `UNSUPPORTED_PREFIX` を付けたスラッグを返す（呼び出し側が止める警告を積む。送られない）。接頭辞を付けるのは、
	 * 軽減でないと判定した `reduced-rate`（例: 5% を入れた）が正規化モデルの軽減税率の記号と同じ文字列になり、アダプタの
	 * 多重防御（`ProductTransformer::push_blocker()`）をすり抜けないようにするため（review-loop R1-1）。
	 * 引数は外部由来（`woocommerce_product_get_tax_class` などのフィルター）なので `mixed` で受け、文字列でなければ判定できないものとして扱う。
	 */
	public static function to_canonical( mixed $woo_tax_class ): ?string {
		return match ( self::classify( $woo_tax_class ) ) {
			self::STANDARD => null,
			self::REDUCED => self::CANONICAL_REDUCED,
			default => self::UNSUPPORTED_PREFIX . ( is_string( $woo_tax_class ) ? $woo_tax_class : '' ),
		};
	}

	/**
	 * Woo の税区分を JP の税率で分類する（`STANDARD`/`REDUCED`/`UNSUPPORTED`/`UNCONFIGURED`）。
	 *
	 * - JP の税率がある: 実効税率が 10% なら `STANDARD`、8% なら `REDUCED`、それ以外（0% を含む）は `UNSUPPORTED`。
	 *   標準の税区分（`''`）も同じ（食品だけの店舗が標準に 8% を入れていれば軽減、0% なら止める。review-loop R1-2・2026-10-06 ユーザー決定）。
	 * - JP の税率が無い: 標準の税区分は `STANDARD`（税率を設定していない新しい店舗・基準所在地が US のままの店舗で全商品を止めないため）。
	 *   それ以外は、他の地域の税率だけがあるなら `UNCONFIGURED`、税率が 1 件も無い既定の軽減税率の税区分
	 *   （`KNOWN_REDUCED_SLUGS`。税計算をしていない店舗の典型）は `REDUCED`、それ以外は `UNCONFIGURED`。
	 * - 文字列でない値（フィルターが壊れた値を返した）は `UNCONFIGURED`（エクスポートを止める側。原則 8・9）。
	 */
	public static function classify( mixed $woo_tax_class ): string {
		if ( ! is_string( $woo_tax_class ) ) {
			return self::UNCONFIGURED;
		}

		$location  = self::jp_location();
		$cache_key = WC_Cache_Helper::get_cache_prefix( 'taxes' ) . md5( implode( '|', array_merge( [ $woo_tax_class ], $location, WC_Tax::get_tax_class_slugs() ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$category = self::classify_uncached( $woo_tax_class, $location );

		wp_cache_set( $cache_key, $category, self::CACHE_GROUP );

		return $category;
	}

	/**
	 * @param array{0:string,1:string,2:string} $location `jp_location()`。
	 */
	private static function classify_uncached( string $woo_tax_class, array $location ): string {
		$is_standard_class = '' === $woo_tax_class;

		// 標準の税区分（空文字）は WooCommerce の税区分の一覧に含まれないので、存在の確認をしない。
		if ( ! $is_standard_class && ! in_array( $woo_tax_class, WC_Tax::get_tax_class_slugs(), true ) ) {
			return self::UNCONFIGURED;
		}

		$rates = WC_Tax::find_rates(
			[
				'country'   => 'JP',
				'state'     => $location[0],
				'postcode'  => $location[1],
				'city'      => $location[2],
				'tax_class' => $woo_tax_class,
			]
		);

		if ( ! is_array( $rates ) ) {
			return self::UNCONFIGURED;
		}

		if ( [] !== $rates ) {
			return match ( self::rate_basis_points( $rates ) ) {
				self::STANDARD_RATE * 100 => self::STANDARD,
				self::REDUCED_RATE * 100 => self::REDUCED,
				null => self::UNCONFIGURED,
				default => self::UNSUPPORTED,
			};
		}

		if ( $is_standard_class ) {
			return self::STANDARD;
		}

		if ( [] !== WC_Tax::get_rates_for_tax_class( $woo_tax_class ) ) {
			return self::UNCONFIGURED;
		}

		return in_array( $woo_tax_class, self::KNOWN_REDUCED_SLUGS, true ) ? self::REDUCED : self::UNCONFIGURED;
	}

	/**
	 * 取込みで軽減税率の商品・明細を入れる税区分。
	 *
	 * 1. JP の実効税率が 8% の税区分（`classify()` が税率で `REDUCED` と判定したもの）。複数あれば `KNOWN_REDUCED_SLUGS` の順、
	 *    次に `WC_Tax::get_tax_class_slugs()` の順で最初のもの（どれも 8% なので税額は同じ。警告なし）。
	 * 2. 無ければ、税率が 1 件も無い既定の軽減税率の税区分（新しい店舗・税計算をしていない店舗）。後から税率を足せば
	 *    そのまま正しくなるので、そこに入れて `TAX_RATES_NOT_CONFIGURED` を積む。
	 * 3. どちらも無ければ標準（`''`）に倒し `REDUCED_TAX_CLASS_NOT_FOUND`（本実行は止めない。2026-10-06 ユーザー決定）。
	 *
	 * @return array{0:string,1:array<int,string>}
	 */
	private static function reduced_class(): array {
		$slugs    = WC_Tax::get_tax_class_slugs();
		$ordered  = array_values( array_unique( array_merge( array_values( array_intersect( self::KNOWN_REDUCED_SLUGS, $slugs ) ), $slugs ) ) );
		$fallback = null;

		foreach ( $ordered as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug || self::REDUCED !== self::classify( $slug ) ) {
				continue;
			}

			// `REDUCED` のうち税率が 1 件も無いもの（既定の名前によるフォールバック）は、税率で見つかる税区分が無いときだけ使う。
			if ( [] === WC_Tax::get_rates_for_tax_class( $slug ) ) {
				$fallback ??= $slug;
				continue;
			}

			return [ $slug, [] ];
		}

		if ( null !== $fallback ) {
			return [ $fallback, [ WarningCode::with_detail( WarningCode::TAX_RATES_NOT_CONFIGURED, $fallback ) ] ];
		}

		return [ '', [ WarningCode::REDUCED_TAX_CLASS_NOT_FOUND ] ];
	}

	/**
	 * 判定に使う所在地（州・郵便番号・市）。国は常に JP（D26「JP の税率」）。店舗の基準国が JP なら基準所在地の
	 * 州・郵便番号・市も使う（`WC_Tax::get_base_tax_rates()` と同じ一致の仕方）。基準国が JP でない店舗
	 * （WooCommerce の既定は US:CA）は、州などを限定しない JP の税率で判定する。
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	private static function jp_location(): array {
		$countries = WC()->countries;

		if ( 'JP' !== $countries->get_base_country() ) {
			return [ '', '', '' ];
		}

		return [ (string) $countries->get_base_state(), (string) $countries->get_base_postcode(), (string) $countries->get_base_city() ];
	}

	/**
	 * 税率の組の実効税率を 100 倍した整数（10% なら 1000）。`TaxInclusivePrice` と同じ `WC_Tax::calc_tax()` で 100 に掛かる税額を
	 * 合計する（7.8%＋2.2% の分割・複合税率も合算される）。フィルター（`woocommerce_find_rates`・`woocommerce_calc_tax`）の戻り値は
	 * 外部由来なので、形が違えば `null`（判定できない）に倒す（原則 8・9）。
	 *
	 * @param array<mixed> $rates
	 */
	private static function rate_basis_points( array $rates ): ?int {
		foreach ( $rates as $rate ) {
			if ( ! is_array( $rate ) || ! is_numeric( $rate['rate'] ?? null ) || ! in_array( $rate['compound'] ?? null, [ 'yes', 'no' ], true ) ) {
				return null;
			}
		}

		$taxes = WC_Tax::calc_tax( 100, $rates, false );

		if ( ! is_array( $taxes ) ) {
			return null;
		}

		$total = 0.0;

		foreach ( $taxes as $tax ) {
			if ( ! is_int( $tax ) && ! is_float( $tax ) ) {
				return null;
			}

			$total += (float) $tax;
		}

		return (int) round( $total * 100 );
	}
}
