<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

/**
 * `WriteResult::$warnings` に積む警告コード定数。`"{code}:{detail}"` 形式の文字列にする
 * （F1-6のdry-run CSV・結果レポートが`:`で分解できる契約）。コード自体はi18nしない安定キーで、
 * 表示文言はUI側で `__()` する。
 */
final class WarningCode {

	private function __construct() {}

	public const ENTITY_NOT_SUPPORTED = 'entity_not_supported';

	/**
	 * `Sync\Importer::process_items()`の汎用catch-allが`EntityWriter::write()`/`validate()`の
	 * 例外を拾った際に積む固定コード（F1-6のdry-run結果レポート用）。`Support\Logger`と同じ
	 * 個人情報禁止ルールのため、例外メッセージ自体は含めない。
	 */
	public const VALIDATION_EXCEPTION = 'validation_exception';

	public const PRICES_INCLUDE_TAX_DISABLED = 'prices_include_tax_disabled';
	public const CURRENCY_MISMATCH           = 'currency_mismatch';

	public const SKU_DUPLICATE                 = 'sku_duplicate';
	public const TAX_CLASS_MISSING             = 'tax_class_missing';
	public const TAX_RATES_NOT_CONFIGURED      = 'tax_rates_not_configured';
	public const IMAGE_DOWNLOAD_FAILED         = 'image_download_failed';
	public const ATTRIBUTE_NAME_COLLISION      = 'attribute_name_collision';
	public const VARIATION_REMOVED             = 'variation_removed';
	public const VARIATION_PRICE_INVALID       = 'variation_price_invalid';
	public const VARIATION_SNAPSHOT_INCOMPLETE = 'variation_snapshot_incomplete';
	public const PRODUCT_PRICE_INVALID         = 'product_price_invalid';
	public const SALE_PRICE_INVALID            = 'sale_price_invalid';

	public const CATEGORY_PARENT_UNRESOLVED = 'category_parent_unresolved';
	public const CATEGORY_REF_UNRESOLVED    = 'category_ref_unresolved';
	public const TAG_REF_UNRESOLVED         = 'tag_ref_unresolved';
	public const TERM_REUSED_EXISTING       = 'term_reused_existing';
	public const TERM_NAME_CONFLICT         = 'term_name_conflict';
	public const TERM_UPDATE_FAILED         = 'term_update_failed';
	public const TERM_CREATE_FAILED         = 'term_create_failed';

	/**
	 * エクスポート時、Wooカテゴリに対応する `category_map`（Woo側カテゴリID→ASP側カテゴリID）
	 * のエントリが無い（`Woo\Reader\ProductReader`）。ユーザーが後からマッピング設定を追加すれば
	 * 解決しうるため `indicates_unresolved_reference()` の対象に含める。
	 */
	public const CATEGORY_MAP_UNRESOLVED = 'category_map_unresolved';

	public const CUSTOMER_REUSED_EXISTING   = 'customer_reused_existing';
	public const CUSTOMER_ACCOUNT_PROTECTED = 'customer_account_protected';
	public const CUSTOMER_EMAIL_CONFLICT    = 'customer_email_conflict';
	public const CUSTOMER_CREATE_FAILED     = 'customer_create_failed';
	public const ADDRESS_OVERSEAS           = 'address_overseas';

	public const ORDER_LINE_PRODUCT_UNRESOLVED = 'order_line_product_unresolved';
	public const ORDER_LINE_QUANTITY_INVALID   = 'order_line_quantity_invalid';
	public const ORDER_CUSTOMER_UNRESOLVED     = 'order_customer_unresolved';
	public const PAYMENT_METHOD_UNMAPPED       = 'payment_method_unmapped';
	public const SHIPPING_METHOD_UNMAPPED      = 'shipping_method_unmapped';
	public const ORDER_STATUS_UNKNOWN          = 'order_status_unknown';
	public const ORDER_TOTAL_RESIDUAL          = 'order_total_residual';
	public const ORDER_SPLIT_TAX_UNKNOWN       = 'order_split_tax_unknown';
	public const ORDER_TAX_SPLIT_UNAVAILABLE   = 'order_tax_split_unavailable';
	public const ORDER_TAX_TOTAL_INCOMPLETE    = 'order_tax_total_incomplete';
	public const ORDER_CREATE_FAILED           = 'order_create_failed';
	public const ORDER_LINE_TAX_INCONSISTENT   = 'order_line_tax_inconsistent';
	public const ORDER_TOTALS_INVALID          = 'order_totals_invalid';
	public const ORDER_LINE_AMOUNT_INVALID     = 'order_line_amount_invalid';

	public const STOCK_PRODUCT_UNRESOLVED = 'stock_product_unresolved';
	public const STOCK_PARENT_OF_VARIABLE = 'stock_parent_of_variable';

	/**
	 * エクスポート時、バリエーションの在庫が親レベルで一括管理されている
	 * （`WC_Product_Variation::get_manage_stock()`が`'parent'`を返す）。ASP側にはバリエーションを
	 * またぐ共有在庫プールという概念が無いため、親の数量をそのまま各バリエーションへ複製すると
	 * 実在庫のバリエーション数倍を販売可能数量として申告してしまう。`Woo\Reader\ProductReader`は
	 * このケースを`STOCK_PARENT_OF_VARIABLE`（インポート時に変数親へ在庫を書き込もうとして
	 * 拒否する別の状況を指す）とは区別し、在庫切れ（0）にフェイルクローズしたうえでこの警告を積む。
	 */
	public const VARIATION_STOCK_SHARED_WITH_PARENT = 'variation_stock_shared_with_parent';

	public const COUPON_REUSED_EXISTING = 'coupon_reused_existing';

	/**
	 * `CanonicalCoupon::$has_unsupported_restrictions` が `true`: ASP側の利用制限のうちWooの
	 * クーポン設定へ写せないものが残っているため保存を見送った。
	 */
	public const COUPON_RESTRICTIONS_UNSUPPORTED = 'coupon_restrictions_unsupported';

	/**
	 * `CanonicalCoupon::$has_unsupported_restrictions` が `null`: アダプタが制限の有無を宣言して
	 * いないため、不明として保存を見送った（楽観的に「制限なし」へ倒さない）。
	 */
	public const COUPON_RESTRICTIONS_UNKNOWN = 'coupon_restrictions_unknown';

	public const COUPON_CODE_CONFLICT      = 'coupon_code_conflict';
	public const COUPON_TYPE_UNKNOWN       = 'coupon_type_unknown';
	public const COUPON_AMOUNT_INVALID     = 'coupon_amount_invalid';
	public const VARIATION_SAVE_FAILED     = 'variation_save_failed';
	public const PRODUCT_SAVE_FAILED       = 'product_save_failed';
	public const COUPON_SAVE_FAILED        = 'coupon_save_failed';
	public const COUPON_EXPIRES_AT_INVALID = 'coupon_expires_at_invalid';
	public const COUPON_MIN_AMOUNT_INVALID = 'coupon_min_amount_invalid';

	/**
	 * `"{code}:{detail}"` 形式の警告文字列を組み立てる。
	 */
	public static function with_detail( string $code, string $detail ): string {
		return '' === $detail ? $code : "{$code}:{$detail}";
	}

	/**
	 * `with_detail()` で組み立てた文字列をcode/detailに分解する。detail自体（画像URL・ASP側の
	 * 任意文字列等）に`:`が含まれることがあるため、素朴な`explode(':', $warning)`（limit無し）
	 * は誤分割する。必ず最初の`:`でのみ分割する（`explode(..., 2)`）ため、F1-6のdry-run CSV・
	 * 結果レポートはこのメソッドを使うこと。
	 *
	 * @return array{0:string,1:?string} [code, detail]
	 */
	public static function split( string $warning ): array {
		$parts = explode( ':', $warning, 2 );

		return [ $parts[0], $parts[1] ?? null ];
	}

	/**
	 * `Sync\Importer::process_items()`がchecksumをキャッシュしてよいか（`WriteResult::$fully_resolved`）
	 * の判定に使う。ここに列挙するのは「参照先が後から解決可能になりうる」警告のみ:
	 * category/tag/親カテゴリ・顧客参照・注文明細の商品参照が未解決のまま実体自体は保存された
	 * ケース。`CUSTOMER_ACCOUNT_PROTECTED`（管理者アカウントとの衝突）のように解決される見込みが
	 * ない終端状態はここに含めない（含めると、解決される可能性が無いのに毎回無駄に再処理される）。
	 *
	 * @param array<int,string> $warnings
	 */
	public static function indicates_unresolved_reference( array $warnings ): bool {
		$retry_worthy_codes = [
			self::CATEGORY_PARENT_UNRESOLVED,
			self::CATEGORY_REF_UNRESOLVED,
			self::TAG_REF_UNRESOLVED,
			self::ORDER_CUSTOMER_UNRESOLVED,
			self::ORDER_LINE_PRODUCT_UNRESOLVED,
			self::CATEGORY_MAP_UNRESOLVED,
		];

		foreach ( $warnings as $warning ) {
			if ( in_array( self::split( $warning )[0], $retry_worthy_codes, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * dry-runレポート（`Admin\DryRunReportCsv`の`note`列）用: この警告が「参照先がまだ
	 * インポートされていないこと」だけに起因し、参照先を先にインポートすれば消える見込みか。
	 *
	 * {@see indicates_unresolved_reference()} の集合に `STOCK_PRODUCT_UNRESOLVED` を加えたもの。
	 * 在庫は親商品が未解決だとアイテム自体を保存しない（＝mappings/checksumを持たない）ため
	 * checksumキャッシュ判定の対象外だが、レポート上は「初回dry-runで商品より前に判定される
	 * 未インポート起因の未解決」であり、他の参照未解決と同じ注記で区別されるべき
	 * （テストショップの実機dry-runでは在庫全件がこの警告になり、注記無しだと実際の不整合と
	 * 見分けが付かなかった）。
	 */
	public static function indicates_pending_import( string $warning ): bool {
		return ! self::indicates_mapping_required( $warning )
			&& ( self::indicates_unresolved_reference( [ $warning ] )
				|| self::STOCK_PRODUCT_UNRESOLVED === self::split( $warning )[0] );
	}

	/**
	 * dry-runレポート（`Admin\DryRunReportCsv`の`note`列）用: この警告が「ASP側に対応する実体を
	 * 先にインポートすれば消える」のではなく「マッピング設定（`/settings/mappings/{platform}`）を
	 * 追加すれば消える」ものか。`CATEGORY_MAP_UNRESOLVED`（エクスポート方向、`category_map`未設定）は
	 * `indicates_unresolved_reference()`（checksumキャッシュ判定）の対象ではあるが、
	 * 「参照先を先にインポートする」という`indicates_pending_import()`の案内は的外れ
	 * （インポート方向の概念が無いエクスポートに「インポートしてください」と出てしまう）
	 * なため専用の判定を分ける。
	 */
	public static function indicates_mapping_required( string $warning ): bool {
		return self::CATEGORY_MAP_UNRESOLVED === self::split( $warning )[0];
	}
}
