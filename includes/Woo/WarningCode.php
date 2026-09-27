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

	/**
	 * `Woo\Writer\ProductWriter`（インポート方向）専用。`woocommerce_prices_include_tax`が偽のため、
	 * ASPの税込価格をそのまま書くとWoo側でチェックアウト時に税が上乗せされうる（警告のみ・自動変更しない。
	 * docs/03 §5「税の扱い」）。エクスポート方向は`PRICES_CONVERTED_TO_TAX_INCLUSIVE`。
	 */
	public const PRICES_INCLUDE_TAX_DISABLED = 'prices_include_tax_disabled';
	public const CURRENCY_MISMATCH           = 'currency_mismatch';

	/**
	 * `Woo\Reader\ProductReader`（エクスポート方向）: 税計算ON・税抜入力の店舗の価格を、店舗の基準所在地の税率で
	 * 税込価格へ換算した（`Woo\Support\TaxInclusivePrice`）。情報のみ。エクスポート結果の売価が
	 * Wooの入力値と異なる理由をdry-runレポートで説明する。ページ（Readerインスタンス）につき1回だけ付く。
	 */
	public const PRICES_CONVERTED_TO_TAX_INCLUSIVE = 'prices_converted_to_tax_inclusive';

	/**
	 * `Woo\Reader\ProductReader`: セール終了日（`date_on_sale_to`）付きのセール価格を、終了日を運べないまま
	 * ASPへ販売価格として送る（Canonicalは終了日を持たず、エクスポートは継続同期しない。D14）。Woo側でセールが
	 * 終わってもASP側は値引き価格のまま残るため、dry-runレポートで知らせる。情報のみ（blocking化すると
	 * 期間限定セール中の商品を一切エクスポートできない）。バリエーションは`:{variation_id}`のdetail付き。
	 */
	public const SALE_END_DATE_NOT_PUSHED = 'sale_end_date_not_pushed';

	/**
	 * `Woo\Reader\ProductReader`: 税込価格へ換算できない。`PRODUCT_PRICE_INVALID`/`VARIATION_PRICE_INVALID`と
	 * 併せて積み、`indicates_export_blocking()`の対象にする（理由の説明用コード）。
	 */
	public const PRICE_TAX_BASIS_UNRESOLVED = 'price_tax_basis_unresolved';

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

	/**
	 * エクスポート時、`ColorMeAdapter::push_customer()`が新規作成（`POST /v1/customers`）に
	 * 必須の`pref_id`/`postal`/`address1`/`tel`のいずれかをWoo顧客の請求先住所・電話番号から
	 * 解決できなかった、または`name`がswaggerの`maxLength: 50`を超えている。送信すると確実に
	 * 422になるため、事前にフェイルクローズしてスキップする
	 * （`remote_id`が空文字列のため`Sync\Exporter`はmappingsへupsertせず、店舗がWoo側の顧客情報を
	 * 補完すれば次回exportで自動的に再試行される）。`PushResult`からのみ発生するため、
	 * `DryRunPlatformWriter`はアダプタを呼ばないdry-runでは検出されない
	 * （`PRODUCT_DETAILS_PUSH_INCOMPLETE`等と同じ既知の限界）。更新（`PUT`）には必須項目が
	 * 無いため対象外（ただし`name`が50文字を超えたまま更新すると422になりうる点は未対応。
	 * `docs/review-backlog.md` `e2-3-push-customer/G1-name-length-on-update`参照）。
	 */
	public const CUSTOMER_REQUIRED_FIELD_MISSING = 'customer_required_field_missing';

	/**
	 * `ColorMeAdapter::push_order()`: 受注が既にASP側へエクスポート済み（`$remote_id`が非null）
	 * のため、再pushせずスキップした。ColorMeの`PUT /sales/{id}`は入金状態・配送情報の一部しか
	 * 更新できず、明細・決済方法・配送方法の変更はできない（swagger実測）ため、内容が変わった
	 * 受注を`POST /sales`で再送すると重複した受注が作成されてしまう。checksumはキャッシュされない
	 * （`Sync\Exporter`の`did_push`判定で空remote_idのため常にfalse）ため、この警告は解消される
	 * 見込みがない終端状態として毎回の再エクスポートで出続ける（`CUSTOMER_ACCOUNT_PROTECTED`と
	 * 同じ位置づけ）。
	 */
	public const ORDER_UPDATE_NOT_SUPPORTED = 'order_update_not_supported';

	/**
	 * `ColorMeAdapter::push_order()`: 受注作成に必要な配送先住所（`sale_deliveries`。
	 * `postal`/`pref_id`/`address1`/`tel`/`name`が必須）をWoo受注の配送先・請求先いずれからも
	 * 解決できなかった（例: 会員の請求先自体が未入力）。送信すると確実に422になるため、事前に
	 * フェイルクローズしてスキップする（`CUSTOMER_REQUIRED_FIELD_MISSING`と同じ思想）。
	 * 配送不要な仮想商品のみの受注（Woo側に配送方法が一切設定されない）は、`CanonicalOrder`が
	 * 配送要否を運ぶフィールドを持たないため`sale_deliveries`自体を省略する対応（swagger:
	 * 「配送不要商品を含む場合を除き必須」）はしておらず、この警告ではなく
	 * `SHIPPING_METHOD_UNMAPPED`（配送方法自体が無いため`shipping_map`のキーを引けない）で
	 * スキップされる（既知の制限。`OrderTransformer::to_create_payload()`docblock参照）。
	 */
	public const ORDER_SHIPPING_ADDRESS_INCOMPLETE = 'order_shipping_address_incomplete';

	/**
	 * `ColorMeAdapter::push_order()`: `sale.details`が0行（Wooの受注が商品明細を1件も持たない）。
	 * `sale.details`は必須のためColorMeでは表現不能な受注として恒久的にスキップする。
	 * `Woo\Reader\OrderReader`は明細0行そのものには警告を積まないため（未解決の明細行が無い
	 * ため`ORDER_LINE_PRODUCT_*`系警告の対象外）、この状態を無警告のまま結果から消さないよう
	 * 専用コードで警告する（E2-3 PR-Cレビュー指摘）。
	 */
	public const ORDER_LINE_ITEMS_EMPTY = 'order_line_items_empty';

	/**
	 * `ColorMeAdapter::push_order()`: 明細の単価を復元できない（ショップの`tax_type`が不明、
	 * または明細合計が数量で割り切れない）ため受注全体をスキップする。`price`を省略したまま
	 * pushするとColorMeが明細行に現在のカタログ価格を無警告で適用してしまい、価格改定後の商品
	 * では実際の受注額と大きく乖離した金額が恒久的な受注記録として作成されるため
	 * （`PRODUCT_PRICE_INVALID`と同じ金銭的リスクの構図。`Adapters\ColorMe\Transform\
	 * OrderTransformer::line_price()`docblock参照。Codexレビュー指摘）。`Woo\Reader\OrderReader`
	 * はこの状態に対応する警告を持たないため（tax_typeも数量割り切れ判定もpush時点でのみ
	 * 分かる情報）、`ORDER_LINE_ITEMS_EMPTY`と同じ理由で専用コードにしてある。
	 */
	public const ORDER_LINE_PRICE_UNRESOLVED = 'order_line_price_unresolved';

	/**
	 * `ColorMeAdapter::push_order()`: 受注にWooクーポン等の割引額（`totals.discount`）が付いて
	 * いるが、`POST /v1/sales`のリクエストスキーマ（`customer`/`sale_deliveries`/`details`/
	 * `payment_id`）には割引・クーポン額を運ぶフィールドが存在しない（swagger確認済み）。
	 * 受注が実際にpushされた場合、明細は定価のまま送信される（決済/配送方法未マッピング等の
	 * 別理由でskipされた場合はこの警告だけが単独で付き、その回では何も送信されない）。
	 *
	 * `ORDER_REFUNDED`（「ColorMe側の受注金額が実際の回収額より高くなる」という構造上同型の
	 * 金銭的懸念）はexport blockingの対象だが、こちらは意図的にblockingへ含めない: 割引・
	 * クーポンを一切運べない設計上の制約そのものであり、保留しても解決する見込みが無い。
	 * blocking化するとクーポンを使った受注が無料版のサンプル移行で一切確認できなくなり、
	 * `PRICES_INCLUDE_TAX_DISABLED`をblockingへ含めなかった理由（多くの実店舗の既定設定で
	 * 発火し、挙動確認自体ができなくなる）と同種の弊害が生じるため、情報提供の警告に留める
	 * （E2-3 PR-Cレビュー指摘）。
	 */
	public const ORDER_DISCOUNT_NOT_PUSHED = 'order_discount_not_pushed';

	/**
	 * `ColorMeAdapter::push_order()`: 受注に決済手数料（`payment.fee`）または送料
	 * （`shipping.fee`）が付いているが、`POST /v1/sales`のリクエストスキーマ
	 * （`sale_deliveries[]`は`delivery_id`/住所/`preferred_date`等のみ、`details[]`は商品行のみ）
	 * には手数料・送料の実額を運ぶフィールドが存在しない（swagger確認済み）。ColorMeは
	 * `payment_id`/`delivery_id`ごとに自身で設定された手数料・送料を独自に適用するため、
	 * Woo側の実際の手数料・送料と一致するとは限らない。`ORDER_DISCOUNT_NOT_PUSHED`と同じ理由
	 * （運ぶ手段自体が無く保留しても解決しない・blocking化すると送料の付くほぼ全ての受注が
	 * 移行できなくなる）で情報提供の警告に留める（Codexレビュー指摘）。
	 */
	public const ORDER_FEE_NOT_PUSHED = 'order_fee_not_pushed';

	/**
	 * `ColorMeAdapter::push_order()`: 新規作成が成功した場合に常に付与する。`POST /v1/sales`の
	 * リクエストスキーマに受注日時を指定するフィールドが存在しない（swagger確認済み）ため、
	 * ColorMe側の受注日時は`CanonicalOrder::$placed_at`（Woo側の実際の注文日時）ではなく
	 * pushを実行した時刻になる。過去の受注を移行する用途では日付ベースの売上集計・レポートが
	 * 実際の購入時期と食い違うことをオペレーターへ常に知らせる（`PRODUCT_IMAGES_NOT_PUSHED`
	 * （非プレミアムプランで常に付く）と同種の、ストア/受注の性質上恒久的に解消しない情報提供
	 * 警告。Codexレビュー指摘）。
	 */
	public const ORDER_PLACED_AT_NOT_PRESERVED = 'order_placed_at_not_preserved';

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

	/**
	 * `ColorMeAdapter::push_stock()`: バリエーションの`CanonicalStock::$quantity`が`null`
	 * （Wooがそのバリエーションを個別管理していない）。ColorMeのバリエーション更新スキーマ
	 * （`productVariantUpdateRequest`）には商品レベルの`stock_managed`に相当するフィールドが無く、
	 * 「個別管理しない」状態を明示的に伝える手段が無いため、APIを呼ばずスキップする
	 * （`Adapters\ColorMe\Transform\StockTransformer::to_variant_payload()`docblock参照）。
	 * merchantがWoo側でそのバリエーションの個別在庫管理をONにすれば`quantity`が具体的な数値に
	 * なり自然に解消するが、確実に解消する保証は無い終端寄りの警告のため`indicates_export_
	 * blocking()`/`indicates_unresolved_reference()`のいずれにも含めない
	 * （`PRODUCT_IMAGES_NOT_PUSHED`と同じ位置づけ）。**既知の限界**:
	 * (1) `CUSTOMER_REQUIRED_FIELD_MISSING`と同じく`PushResult`からのみ発生するため
	 * `DryRunPlatformWriter`（アダプタを呼ばない）では検出されず、dry-runでは「updated」と
	 * 表示されるのに実行では警告付きでskipされる食い違いが起こりうる。
	 * (2) ColorMeから往復インポートした「元々管理外」のバリエーション（Woo側も
	 * `manage_stock=false`）は両者が一致した正常な状態でも再エクスポートのたびにこの警告が
	 * 繰り返し発火し続ける（実害は無いノイズ。review-loop R1で判明、issue #47）。
	 */
	public const STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE = 'stock_variant_unmanaged_not_pushable';

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
	 * `ColorMeAdapter::push_product()`: 新規作成（`POST /products`）自体は成功したが、
	 * 作成リクエストが受け付けない項目（`category_id_small`/`group_ids`/`stocks`）を
	 * 反映するための追いPUT（`PUT /products/{id}`）が失敗した。商品自体は`remote_id`確定済みの
	 * ため`indicates_unresolved_reference()`の対象にして次回exportで再試行させる。
	 */
	public const PRODUCT_DETAILS_PUSH_INCOMPLETE = 'product_details_push_incomplete';

	/**
	 * `ColorMeAdapter::push_product()`: 商品本体（POST/PUT products）は成功したが、
	 * バリエーションのサブリクエスト（`POST /products/{id}/options`によるオプション作成、
	 * `PUT /products/{id}/variants/{id}`による価格/型番/在庫設定）の一部が失敗した。
	 * 商品自体は`remote_id`確定＝created/updatedとして扱うが、`indicates_unresolved_reference()`
	 * の対象にしてchecksumをキャッシュせず、次回exportで自動的に再試行させる
	 * （`docs/03-design-decisions.md` §10.2「E2-3への申し送り」の部分完了契約）。
	 */
	public const PRODUCT_VARIANT_PUSH_INCOMPLETE = 'product_variant_push_incomplete';

	/**
	 * `ColorMeAdapter::push_product()`: 画像push（`POST /products/{id}/images`、
	 * プレミアムプラン契約時のみ試行）の一部が失敗した。`is_retryable_failure()`が429/5xx/
	 * 通信断のみをこの対象に分類する（422等その他の4xxは終端の`PRODUCT_IMAGE_PUSH_FAILED`）。
	 * リトライで解決しうるため`indicates_unresolved_reference()`の対象に含める。
	 */
	public const PRODUCT_IMAGE_PUSH_INCOMPLETE = 'product_image_push_incomplete';

	/**
	 * `ColorMeAdapter::push_product()`: `capabilities()->can_push_images`が false
	 * （非プレミアムプラン契約）のため画像を一切pushしなかった。プラン変更しない限り
	 * 解決しない終端状態のため`indicates_unresolved_reference()`には含めない
	 * （画像URL一覧の集約UIはE2-4スコープ。本コードは警告としてのみ結果に残す）。
	 */
	public const PRODUCT_IMAGES_NOT_PUSHED = 'product_images_not_pushed';

	/**
	 * `ColorMeAdapter::push_product()`: `PRODUCT_DETAILS_PUSH_INCOMPLETE`の終端版。
	 * 追いPUTの失敗が429/5xx/通信断ではなく4xx（422の入力エラー等）だった場合に積む。
	 * 再試行しても解決しない終端状態のため`indicates_unresolved_reference()`には含めない
	 * （R1レビュー指摘: 4xxもretry対象に含めると恒久的な失敗が毎回同じ無駄なリクエスト列を
	 * 繰り返す）。
	 */
	public const PRODUCT_DETAILS_PUSH_FAILED = 'product_details_push_failed';

	/**
	 * `ColorMeAdapter::push_product()`: `PRODUCT_VARIANT_PUSH_INCOMPLETE`の終端版
	 * （`PRODUCT_DETAILS_PUSH_FAILED`と同じ理由）。
	 */
	public const PRODUCT_VARIANT_PUSH_FAILED = 'product_variant_push_failed';

	/**
	 * `ColorMeAdapter::push_product()`: `PRODUCT_IMAGE_PUSH_INCOMPLETE`の終端版
	 * （`PRODUCT_DETAILS_PUSH_FAILED`と同じ理由）。
	 */
	public const PRODUCT_IMAGE_PUSH_FAILED = 'product_image_push_failed';

	/**
	 * `Sync\Exporter`: リモートへの作成は確定した（`Adapters\PartialPushException`でremote_idが
	 * 届いた）が、後続の処理（追加項目の反映・バリエーション・画像等）が途中で止まった。
	 * mappingはremote_idをchecksum=nullで書き、次回のexportは作成ではなく更新（PUT）になって
	 * 後続処理をやり直す（D21-A）。次回再試行させるため`indicates_unresolved_reference()`の対象に
	 * 含める（simple商品には`PRODUCT_*_PUSH_INCOMPLETE`のような別の未完了印が無く、この登録が
	 * checksumをキャッシュしない唯一の根拠になる）。
	 */
	public const PUSH_INTERRUPTED_AFTER_CREATE = 'push_interrupted_after_create';

	/**
	 * `ColorMeAdapter::push_product()`: ColorMeはオプション追加で全組み合わせ（直積）を
	 * 自動生成するため、Woo側に対応するバリエーションが無い組み合わせがリモートに残ることがある
	 * （原則4「破壊的操作の禁止」によりこちらから削除できない）。再試行しても消えるとは限らない
	 * ため`indicates_unresolved_reference()`には含めない、純粋な情報提供の警告。
	 */
	public const PRODUCT_VARIANT_SURPLUS_ON_REMOTE = 'product_variant_surplus_on_remote';

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
			// `Woo\Reader\OrderReader`: 明細の`tax_class`が標準/軽減税率以外（非課税・送料のみ
			// 課税・zero-rate・カスタム税区分）。`CanonicalOrder::$line_items[].tax_reduced`は
			// bool（標準/軽減税率の2値）しか運べずこの状態自体を伝えられないため、無警告のまま
			// pushすると`ColorMeAdapter::push_order()`がマップ先商品の現在の税設定（標準/軽減の
			// いずれか）で課税された受注を恒久的に作成してしまう（例: 実際は非課税だった受注が
			// 通常課税として記録される。Codexレビュー指摘、金銭的リスク）。
			self::ORDER_LINE_TAX_CLASS_UNSUPPORTED,
			// `Woo\Reader\ProductReader`: 価格を復元できない（単純商品の価格未設定・variable商品の
			// 可視バリエーション0件）ため`price='0'`にフェイルクローズ済み。`CanonicalProduct`は
			// 「価格0円（正規の無料商品）」と「価格を復元できない」を区別するフィールドを持たない
			// ため、無警告でpushすると価格未設定の商品がColorMe側に0円商品として恒久的に作成・
			// 公開されてしまう（R3レビュー指摘、Codex/Copilot。金銭的リスク。CLAUDE.mdアーキ
			// テクチャ原則9）。
			self::PRODUCT_PRICE_INVALID,
			// `Woo\Reader\ProductReader`: `tax_status`（`shipping`/`none`）が`taxable`以外。
			// `CanonicalProduct`は`tax_status`を運ぶフィールドを持たないため、無警告でpushすると
			// 送料のみ課税・非課税の商品がColorMe側で通常課税として扱われてしまう
			// （R3レビュー指摘, Copilot）。
			self::TAX_STATUS_NOT_TAXABLE,
			// `Woo\Reader\ProductReader`: 税計算ON・税抜入力の店舗で、その税区分に税率は登録済みだが
			// 店舗の基準所在地に合致するものが無く、税込価格へ換算できない（`Woo\Support\
			// TaxInclusivePrice`）。税抜のまま`ProductTransformer::to_push_amount()`（入力は税込前提）へ
			// 渡すと税分だけ低い売価がColorMeへ恒久的に登録されるため止める（issue #59。金銭的リスク）。
			// なお`PRICES_INCLUDE_TAX_DISABLED`（`Woo\Writer\ProductWriter`のインポート方向）は
			// エクスポートの対象外で、`ProductReader`は税込へ正規化するようになったため、税計算OFFの
			// 既定環境でもここには含めなくてよい（blocking化すると無料版の挙動確認ができなくなる）。
			self::PRICE_TAX_BASIS_UNRESOLVED,
			// `Woo\Support\VariationAxisResolver`: バリエーション軸が3つ以上あり、`CanonicalProduct::
			// $variants`のoption1/2規約（2軸まで）に合わせ3軸目以降を切り捨てている。
			// `ColorMeAdapter::sync_variants()`は`option1/2`の組のみで突合するため、3軸目の値だけが
			// 異なる複数のバリエーションが同じ組に潰れ、誤ったSKU/価格/在庫が別バリエーションへ
			// 入れ替わってpushされうる（R3レビュー指摘, Copilot）。
			self::VARIATION_AXIS_LIMIT_EXCEEDED,
			// `Woo\Reader\OrderReader::line_item_amounts()`: 明細の小計/税額が数値として不正
			// （非数値・負値）なため`0`へフェイルクローズ済み。`ColorMeAdapter::push_order()`の
			// `sale.details[].price`は明示指定するとColorMeに実際の金額として恒久的に記録される
			// ため、`PRODUCT_PRICE_INVALID`と同じ理由（金銭的リスク。CLAUDE.mdアーキテクチャ
			// 原則9）で無警告のままpushしない（E2-3 PR-Cレビュー指摘）。
			self::ORDER_LINE_AMOUNT_INVALID,
			// `Woo\Reader\OrderReader::line_items()`: 明細の数量が欠損・非整数・0以下のため
			// `max(1, ...)`で捏造した数量にフェイルクローズ済み（import方向の`OrderTransformer::
			// transform()`は同じ状況を例外で弾く、より厳しい既存方針と対称）。捏造した数量を
			// ColorMeへ恒久的な受注数量として送らない（E2-3 PR-Cレビュー指摘）。
			self::ORDER_LINE_QUANTITY_INVALID,
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
			// `ColorMeAdapter::push_product()`: 商品本体は作成済みだが追加詳細/バリエーション/画像の
			// サブリクエストが未完了。次回exportで自動的に再試行される（定数のdocblock参照）。
			self::PRODUCT_DETAILS_PUSH_INCOMPLETE,
			self::PRODUCT_VARIANT_PUSH_INCOMPLETE,
			self::PRODUCT_IMAGE_PUSH_INCOMPLETE,
			// 作成が確定した後に処理が止まった場合の警告（Exporterが部分完了の例外から組み立てる）。
			self::PUSH_INTERRUPTED_AFTER_CREATE,
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
