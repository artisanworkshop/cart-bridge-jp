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

	/**
	 * エクスポート時、非公開（`private`等、`publish`以外）のバリエーションを検出した
	 * （`Woo\Reader\ProductReader`）。除外し警告する（含めるとマーチャントが意図的に
	 * 非公開にした在庫がASP側で販売可能な状態として復活しうる）。
	 */
	public const VARIATION_UNPUBLISHED = 'variation_unpublished';

	/**
	 * エクスポート時、WooCommerceの3軸以上のバリエーション属性のうち3軸目以降を検出した
	 * （`CanonicalProduct::$variants`のoption1/2規約は2軸まで）。先頭2軸のみを使い、
	 * 異なる3軸目の値を持つバリエーション同士が同じoption1/2の組に潰れうることを警告する。
	 */
	public const VARIATION_AXIS_LIMIT_EXCEEDED = 'variation_axis_limit_exceeded';

	/**
	 * エクスポート時、variable商品の全バリエーションが除外された（価格無効・非公開等）ため
	 * `CanonicalProduct::$variants`が空になった。`Woo\Writer\ProductWriter::prepare()`は
	 * 空の`$variants`を「simple商品」の判定に使うため、無警告のままだと変換先で
	 * variable商品がsimpleとして扱われうることを警告する。
	 */
	public const ALL_VARIATIONS_EXCLUDED = 'all_variations_excluded';

	/**
	 * エクスポート時、Wooの`tax_status`が`taxable`以外（`shipping`/`none`）。`CanonicalProduct`は
	 * `tax_status`を運ぶフィールドを持たず（`tax_class`のみ）、無警告のまま変換先へ渡すと
	 * 「送料のみ課税」「非課税」の商品が通常課税として扱われうることを警告する
	 * （`Woo\Reader\ProductReader`）。
	 */
	public const TAX_STATUS_NOT_TAXABLE = 'tax_status_not_taxable';

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

	/**
	 * エクスポート時、受注明細の商品がまだASP側へエクスポートされておらず（`cbjp_mappings`に
	 * product/variantのremote_idが無い）remote_product_idを特定できない（`Woo\Reader\OrderReader`）。
	 * 商品を先にエクスポートすれば解決しうるため`indicates_unresolved_reference()`の対象に含める。
	 * importの`ORDER_LINE_PRODUCT_UNRESOLVED`（ASP側受注明細のWoo商品参照が解決できない）とは
	 * 向きが逆の別概念のため区別する。
	 */
	public const ORDER_LINE_PRODUCT_NOT_EXPORTED = 'order_line_product_not_exported';

	/**
	 * エクスポート時、受注の購入者（Wooの顧客ID）がまだASP側へエクスポートされておらず
	 * （`cbjp_mappings`にcustomerのremote_idが無い）customer_refを特定できない
	 * （`Woo\Reader\OrderReader`）。顧客を先にエクスポートすれば解決しうるため
	 * `indicates_unresolved_reference()`の対象に含める。ゲスト購入（customer_id=0）はそもそも
	 * この警告の対象外（customer_refはnullのまま警告なし）。
	 */
	public const ORDER_CUSTOMER_NOT_EXPORTED = 'order_customer_not_exported';

	/**
	 * エクスポート時、受注明細が参照していた商品が削除済みで`remote_product_id`を恒久的に
	 * 特定できない（`Woo\Reader\OrderReader`）。`ORDER_LINE_PRODUCT_NOT_EXPORTED`（商品は実在
	 * するがまだエクスポートされていないだけ＝再エクスポートで解決しうる）とは異なり、削除済みは
	 * 再試行しても解決しない終端状態のため`indicates_unresolved_reference()`には含めない。
	 *
	 * 削除の検出は`get_post()`では行えない（実測確認済み）: `WC_Order_Item_Product::
	 * set_product_id()`は投稿タイプ検証を持ち、参照先が削除済みだと`WC_Data_Exception`を投げる。
	 * データストアの`read()`が呼ぶ`WC_Data::set_props()`はこれをプロパティ毎にcatchするため、
	 * `get_product_id()`自体が既定値`0`を返してしまい（`WC_Coupon::set_amount()`と同じ
	 * パターン）、削除済み商品への参照と「一度も商品リンクを持たない行」（`ORDER_LINE_PRODUCT_
	 * MISSING`）がCRUD層では区別できなくなる。`Woo\Reader\OrderReader::remote_product_id()`は
	 * 生のorder-item-meta（`_product_id`。CRUD層の検証を経ないため削除後も元のIDのまま残る）を
	 * 直接読んで区別する。
	 *
	 * 対応ASP（ColorMe）の受注作成APIは明細ごとに商品参照を必須とするため、`remote_product_id=null`
	 * のまま無警告でpushすると、push先で拒否される・または実際には存在した商品の参照が黙って
	 * 失われた注文として作成されてしまう（Codex指摘, PR #41 #11: 当初は無警告で
	 * `remote_product_id=null`を返していた）。`indicates_export_blocking()`の対象にして
	 * push自体を止める。
	 */
	public const ORDER_LINE_PRODUCT_DELETED = 'order_line_product_deleted';

	/**
	 * エクスポート時、受注明細（`WC_Order_Item_Product`）が一度も商品リンクを持たない
	 * （`get_product_id()`が`0`、かつ生のorder-item-meta（`_product_id`）も`0`＝
	 * `ORDER_LINE_PRODUCT_DELETED`の「削除済み」には該当しない）（`Woo\Reader\OrderReader`）。
	 * 対応ASP（ColorMe）の受注作成APIは明細ごとに商品参照を必須とするため、このような行を
	 * 「商品リンクを持たない正当なカスタム行」として無警告でpushすると、`ORDER_LINE_PRODUCT_
	 * DELETED`と同じ理由でpush先に拒否・または不正な明細として扱われうる（Copilot指摘, PR #41
	 * G2: 当初は「正当なカスタム行」として無警告のまま扱っていた）。`indicates_export_blocking()`
	 * の対象にしてpush自体を止める。
	 */
	public const ORDER_LINE_PRODUCT_MISSING = 'order_line_product_missing';

	/**
	 * エクスポート時、バリエーション明細の親商品自体は解決できたが、バリエーションの識別に
	 * 失敗した（`Woo\Reader\OrderReader::remote_product_id()`/`variation_option_values()`）。
	 * 3パターンある: (1) `get_variation_id()`が既定値`0`にリセットされている＝バリエーション
	 * 自体が削除済み（`get_product_id()`と同じ「`WC_Order_Item_Product::set_variation_id()`の
	 * 投稿タイプ検証失敗→`WC_Data_Exception`→`set_props()`がプロパティ毎にcatch」パターン。
	 * 生のorder-item-meta（`_variation_id`）で判別する。実測確認済み）、(2) `get_variation_id()`は
	 * 非0だが対応する`WC_Product_Variation`自体が取得できない、(3) 親商品の軸属性が3つ以上
	 * （`Woo\Support\VariationAxisResolver::axis_attributes()`が`VARIATION_AXIS_LIMIT_EXCEEDED`
	 * を積む。`CanonicalProduct::$variants`のoption1/2規約は2軸までのため3軸目以降を切り捨てる）。
	 * `Woo\Reader\ProductReader`はこの(3)を無警告（切り捨てるだけ）で扱うが、受注明細では3軸目の
	 * 値が異なる複数のバリエーションがoption1/2の組だけでは区別できず、誤った商品を受注として
	 * 記録しうる（Copilot指摘, PR #41 G2: 当初は`variation_option_values()`内で
	 * `VARIATION_AXIS_LIMIT_EXCEEDED`警告を破棄しており受注側へ伝播していなかった）。
	 * `indicates_export_blocking()`の対象にしてpush自体を止める。
	 */
	public const ORDER_LINE_VARIATION_UNRESOLVED = 'order_line_variation_unresolved';

	/**
	 * エクスポート時、受注が一部/全額返金済み（`WC_Order::get_total_refunded() > 0`）
	 * （`Woo\Reader\OrderReader`）。返金は`WC_Order_Refund`という別オブジェクトに記録され、
	 * `get_total()`/明細の`get_subtotal()`等は返金前の金額のまま変わらない。`CanonicalOrder`は
	 * 返金額を運ぶフィールドを持たないため、無警告でpushすると実際には回収していない金額を
	 * 全額回収済みとしてASP側に作成してしまう（金銭的リスク）。`indicates_export_blocking()`の
	 * 対象にしてpush自体を止める（返金状態の表現・E2-3での取扱いは将来課題）。
	 */
	public const ORDER_REFUNDED = 'order_refunded';

	/**
	 * エクスポート時、受注明細の`tax_class`が`''`（標準）/`'reduced-rate'`（軽減税率）以外
	 * （`zero-rate`・カスタム税区分等）、または`WC_Order_Item_Product::get_tax_status()`が
	 * `'taxable'`以外（送料のみ課税・非課税）。`CanonicalOrder::$line_items[].tax_reduced`は
	 * bool（標準/軽減税率の2値）しか表現できないため、それ以外の税区分・非課税状態を無警告で
	 * 標準課税として扱うと税額が誤って計算されうる（`Woo\Reader\ProductReader`の
	 * `TAX_STATUS_NOT_TAXABLE`と同じ理由。CanonicalOrderに区分自体を運ぶフィールドが無いため
	 * 警告のみで、値自体は`tax_reduced=false`にフェイルクローズする）。
	 */
	public const ORDER_LINE_TAX_CLASS_UNSUPPORTED = 'order_line_tax_class_unsupported';

	public const STOCK_PRODUCT_UNRESOLVED = 'stock_product_unresolved';
	public const STOCK_PARENT_OF_VARIABLE = 'stock_parent_of_variable';

	/**
	 * エクスポート時、在庫を書き込む対象商品/バリエーションがまだASP側へエクスポートされておらず
	 * （`cbjp_mappings`にproduct/variantのremote_idが無い）、対象を特定できない
	 * （`Woo\Reader\StockReader`）。`CanonicalStock::$product_ref`は非nullable stringのため
	 * 有効な値を作れず、`indicates_export_blocking()`の対象にしてpush自体を止める
	 * （checksumはキャッシュされないため商品エクスポート後に自動再試行される）。importの
	 * `STOCK_PRODUCT_UNRESOLVED`（ASP側商品がまだWooへインポートされていない）とは向きが逆の
	 * 別概念のため区別する。
	 */
	public const STOCK_PRODUCT_NOT_EXPORTED = 'stock_product_not_exported';

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
	 * `Sync\Exporter::process_items()`用: この警告が示す状態のまま`PlatformWriter::write()`へ
	 * 渡すと、`CanonicalModel`が実体を正しく表現できずpush先で構造を破壊しうる（例:
	 * `ALL_VARIATIONS_EXCLUDED`＝`variants=[]`のvariable商品がsimple商品としてpushされ、
	 * remote側の既存バリエーションが失われる。Copilot指摘, PR #40 G3）。該当時はpushせず
	 * フェイルクローズでskipped扱いにする。
	 *
	 * @param array<int,string> $warnings
	 */
	public static function indicates_export_blocking( array $warnings ): bool {
		$blocking_codes = [
			self::ALL_VARIATIONS_EXCLUDED,
			// `Woo\Reader\CouponReader`: ASP側へ運べないWooネイティブのクーポン制限
			// （商品/カテゴリ/メールアドレス制限・maximum_amount・fixed_product型）が残っている。
			// `has_unsupported_restrictions=true`のまま`push_coupon()`（E2-3）へ渡すと、制限が
			// 落ちた無制限クーポンとして保存されうる金銭的リスクがあるため、pushせずフェイル
			// クローズする（`Canonical\CanonicalCoupon`のdocblockが定める契約、importの
			// `Woo\Writer\CouponWriter`と同じ判断をexport側でも読出時点から適用する）。
			self::COUPON_RESTRICTIONS_UNSUPPORTED,
			// `Woo\Reader\StockReader`: 対象商品/バリエーションがまだASP側へエクスポートされて
			// おらず、`CanonicalStock::$product_ref`（非nullable string）へ入れる有効な値が無い。
			// `CanonicalOrder::$line_items[].remote_product_id`/`$customer_ref`（いずれもnullable）
			// とは異なり必須フィールドのため、空文字列のまま`push_stock()`（E2-3）へ渡さずここで
			// 止める。checksumは`continue`で未到達のままキャッシュされないため、商品が後から
			// エクスポートされ次第この行は自動的に再試行される（`indicates_unresolved_reference()`
			// への追加は不要）。
			self::STOCK_PRODUCT_NOT_EXPORTED,
			// `Woo\Reader\OrderReader`: 注文全体の合計（discount/shipping_fee/tax/total）が
			// 数値として不正（非数値・負値）なため`0`へフェイルクローズ済み。importの
			// `Woo\Writer\OrderWriter::validate_totals()`が同じ状況で注文全体の書込みを見送る
			// （`WC_Order`に一切触れる前に注文自体をskipする）のと対称に、exportも壊れた合計を
			// 実際の明細と一緒に「¥0の注文」としてpushしない。
			self::ORDER_TOTALS_INVALID,
			// `Woo\Reader\OrderReader`: 注文の通貨（`WC_Order::get_currency()`）が対応ASPの前提
			// 通貨（`Woo\Writer\OrderWriter::PLATFORM_CURRENCY`=JPY）と異なる。importのCURRENCY_
			// MISMATCH（店舗通貨とASP前提通貨が異なる場合の警告のみ、注文自体は保存する）とは
			// 非対称にblockingへ倒す: import方向の不一致は内部記録上のズレに留まるのに対し、
			// export方向でJPY以外の金額をそのままpushすると、ASP側がその数値をJPYとして解釈し
			// 実際の金額と大きく乖離した注文が作成されてしまう（例: USD 100の注文がJPY 100として
			// 送信される）金銭的リスクが質的に異なるため。
			self::CURRENCY_MISMATCH,
			// `Woo\Reader\OrderReader`: 受注明細が参照していた商品/バリエーションが削除済みで
			// `remote_product_id`を恒久的に特定できない。対応ASPの受注作成APIは明細ごとの商品参照を
			// 必須とするため、参照を持たない行と区別せずpushしない（詳細は定数のdocblock参照）。
			self::ORDER_LINE_PRODUCT_DELETED,
			// `Woo\Reader\OrderReader`: 受注明細が一度も商品リンクを持たない（詳細は定数の
			// docblock参照）。
			self::ORDER_LINE_PRODUCT_MISSING,
			// `Woo\Reader\OrderReader`: バリエーション明細の親商品は解決できたが、バリエーション
			// 自体の識別に失敗した（削除済み、または軸3つ以上でoption1/2だけでは区別不能。詳細は
			// 定数のdocblock参照）。
			self::ORDER_LINE_VARIATION_UNRESOLVED,
			// `Woo\Reader\OrderReader`: 受注が一部/全額返金済み。返金額を運ぶフィールドが無い
			// ため、返金前の金額のまま全額回収済みとしてpushしない（詳細は定数のdocblock参照）。
			self::ORDER_REFUNDED,
		];

		foreach ( $warnings as $warning ) {
			if ( in_array( self::split( $warning )[0], $blocking_codes, true ) ) {
				return true;
			}
		}

		return false;
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
			self::ORDER_LINE_PRODUCT_NOT_EXPORTED,
			self::ORDER_CUSTOMER_NOT_EXPORTED,
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
			&& ! self::indicates_pending_export( $warning )
			&& ( self::indicates_unresolved_reference( [ $warning ] )
				|| self::STOCK_PRODUCT_UNRESOLVED === self::split( $warning )[0] );
	}

	/**
	 * dry-runレポート（`Admin\DryRunReportCsv`の`note`列）用: この警告が「参照先（Wooローカル
	 * 実体）がまだASP側へエクスポートされていないこと」だけに起因し、参照先を先にエクスポート
	 * すれば消える見込みか。`indicates_pending_import()`のエクスポート方向対称形
	 * （`ORDER_LINE_PRODUCT_NOT_EXPORTED`/`ORDER_CUSTOMER_NOT_EXPORTED`/`STOCK_PRODUCT_NOT_EXPORTED`は
	 * `indicates_pending_import()`に混ざると「参照先を先にインポートしてください」という向きの
	 * 逆な誤った案内になるため区別する。`STOCK_PRODUCT_NOT_EXPORTED`は`indicates_unresolved_reference()`
	 * の対象外（`indicates_export_blocking()`のみ）だが、レポート上は他の未解決参照と同じ注記で
	 * 区別されるべき理由は`indicates_pending_import()`の同種コメントと同じ）。
	 */
	public static function indicates_pending_export( string $warning ): bool {
		$codes = [
			self::ORDER_LINE_PRODUCT_NOT_EXPORTED,
			self::ORDER_CUSTOMER_NOT_EXPORTED,
			self::STOCK_PRODUCT_NOT_EXPORTED,
		];

		return in_array( self::split( $warning )[0], $codes, true );
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
