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
	 * ASP の型番を Woo の SKU の形にする（R3-5b）。WooCommerce のテンプレート（`single-product/meta.php`・SKU のブロック）は
	 * SKU をエスケープせずに出し、WooCommerce 自身の管理画面・REST は保存の前に `wc_clean()` を掛けている。CRUD の `set_sku()` は
	 * 何もしないので、ここで同じ `wc_clean()` を掛けないと、型番の `<script>` が商品ページでそのまま実行される。空になったら null（SKU なし）。
	 */
	public static function normalize( ?string $sku ): ?string {
		if ( null === $sku ) {
			return null;
		}

		$clean = wc_clean( $sku );

		return is_string( $clean ) && '' !== $clean ? $clean : null;
	}

	/**
	 * SKU で商品を引くときの値（`ProductResolver`）。整形（{@see self::normalize()}）で変わらない型番だけを使い、変わる型番（タグ・
	 * 前後や連続の空白・`%XX` を含む）と空の型番は null（SKU では引かない。mapping で引く）。整形した値で引くと、別々の型番
	 * （`AB` と `AB `）が同じ SKU に当たり、受注の明細・在庫が別の商品に結び付く（R3-5b review-loop R1-L5）。空の SKU で引くと、
	 * SKU の無い商品に当たる（WooCommerce は空の SKU も照合する。R1-2）。
	 */
	public static function lookup_key( ?string $sku ): ?string {
		$clean = self::normalize( $sku );

		return null !== $clean && $clean === $sku ? $clean : null;
	}

	/**
	 * 重複判定は`WC_Product::set_sku()`が内部の`wc_product_has_unique_sku()`経由で既に行い
	 * `WC_Data_Exception`を投げるため、ここで`wc_get_product_id_by_sku()`を重ねて手動チェック
	 * すると同じ問い合わせを二重に行うだけになる。例外を捕捉する1箇所に判定を集約する。
	 *
	 * @return array<int,string> 警告
	 */
	public static function apply( WC_Product $target, ?string $sku ): array {
		$sku = self::normalize( $sku ) ?? '';

		try {
			$target->set_sku( $sku );
		} catch ( WC_Data_Exception ) {
			$target->update_meta_data( '_cbjp_original_sku', $sku );
			$target->set_sku( '' );

			return [ WarningCode::with_detail( WarningCode::SKU_DUPLICATE, $sku ) ];
		}

		// 前回の同期時点では重複していて`_cbjp_original_sku`へ退避していたSKUが、今回は
		// （衝突相手が削除された等で）正常に設定できた場合、退避メタが残り続けないよう
		// 明示的に削除する（weight/low_stock_amount等で確立しているclear-on-nullの規約と
		// 揃える）。残したままだと、SKU_DUPLICATE警告は次回以降出なくなるのに、D16の
		// リンク再構築ツールや店舗側の突合が古い「退避されたSKU」を参照し続けてしまう。
		$target->delete_meta_data( '_cbjp_original_sku' );

		return [];
	}
}
