<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Woo;

/**
 * 顧客・受注・クーポンの警告コード（R3-6c1 で `Woo\WarningCode` から移した。書式・規約は `WarningCode` と同じ）。
 * 店舗向けの説明と判定の印は実体の種類が持つ（`Entities\*Warnings`）。無料版の判定関数（`WarningCode::indicates_*()`）は、
 * これらのコードを種類が付けた印（`Entities\WarningFlag`）で判定する。
 */
final class CommerceWarningCode {

	private function __construct() {}

	public const CURRENCY_MISMATCH = 'currency_mismatch';

	public const CUSTOMER_REUSED_EXISTING = 'customer_reused_existing';

	public const CUSTOMER_ACCOUNT_PROTECTED = 'customer_account_protected';

	public const CUSTOMER_EMAIL_CONFLICT = 'customer_email_conflict';

	public const CUSTOMER_CREATE_FAILED = 'customer_create_failed';

	public const ADDRESS_OVERSEAS = 'address_overseas';

	/**
	 * エクスポート時、`ColorMeCommerceAdapter::push_customer()`が新規作成（`POST /v1/customers`）に
	 * 必須の`pref_id`/`postal`/`address1`/`tel`のいずれかをWoo顧客の請求先住所・電話番号から
	 * 解決できなかった、または`name`がswaggerの`maxLength: 50`を超えている。送信すると確実に
	 * 422になるため、事前にフェイルクローズしてスキップする
	 * （`remote_id`が空文字列のため`Sync\Exporter`はmappingsへupsertせず、店舗がWoo側の顧客情報を
	 * 補完すれば次回exportで自動的に再試行される）。`PushResult`からのみ発生するため、
	 * `DryRunPlatformWriter`はアダプタを呼ばないdry-runでは検出されない
	 * （`PRODUCT_DETAILS_PUSH_INCOMPLETE`等と同じ既知の限界）。更新（`PUT`）も対象: swagger は必須項目を
	 * 書かないが、実際は名前と住所が無いと422になる（テストショップで実測）ので、名前（空白だけ・50文字超）と
	 * 住所3点（`pref_id`/`postal`/`address1`）を解決できない顧客の更新も送らずに同じ警告でスキップする
	 * （issue #100。既存の mapping はそのまま残る）。
	 */
	public const CUSTOMER_REQUIRED_FIELD_MISSING = 'customer_required_field_missing';

	/**
	 * `ColorMeCommerceAdapter::push_order()`: 受注が既にASP側へエクスポート済み（`$remote_id`が非null）
	 * のため、再pushせずスキップした。ColorMeの`PUT /sales/{id}`は入金状態・配送情報の一部しか
	 * 更新できず、明細・決済方法・配送方法の変更はできない（swagger実測）ため、内容が変わった
	 * 受注を`POST /sales`で再送すると重複した受注が作成されてしまう。checksumはキャッシュされない
	 * （`Sync\Exporter`の`did_push`判定で空remote_idのため常にfalse）ため、この警告は解消される
	 * 見込みがない終端状態として毎回の再エクスポートで出続ける（`CUSTOMER_ACCOUNT_PROTECTED`と
	 * 同じ位置づけ）。
	 */
	public const ORDER_UPDATE_NOT_SUPPORTED = 'order_update_not_supported';

	/**
	 * `ColorMeCommerceAdapter::push_order()`: 受注作成に必要な配送先住所（`sale_deliveries`。
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
	 * `ColorMeCommerceAdapter::push_order()`: `sale.details`が0行（Wooの受注が商品明細を1件も持たない）。
	 * `sale.details`は必須のためColorMeでは表現不能な受注として恒久的にスキップする。
	 * `Woo\Reader\OrderReader`は明細0行そのものには警告を積まないため（未解決の明細行が無い
	 * ため`ORDER_LINE_PRODUCT_*`系警告の対象外）、この状態を無警告のまま結果から消さないよう
	 * 専用コードで警告する（E2-3 PR-Cレビュー指摘）。
	 */
	public const ORDER_LINE_ITEMS_EMPTY = 'order_line_items_empty';

	/**
	 * `ColorMeCommerceAdapter::push_order()`: 明細の単価を復元できない（ショップの`tax_type`が不明、
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
	 * `ColorMeCommerceAdapter::push_order()`: 受注にWooクーポン等の割引額（`totals.discount`）が付いて
	 * いるが、`POST /v1/sales`のリクエストスキーマ（`customer`/`sale_deliveries`/`details`/
	 * `payment_id`）には割引・クーポン額を運ぶフィールドが存在しない（swagger確認済み）。
	 * 受注が実際にpushされた場合、明細は定価のまま送信される（決済/配送方法未マッピング等の
	 * 別理由でskipされた場合はこの警告だけが単独で付き、その回では何も送信されない）。
	 *
	 * `ORDER_REFUNDED`（「ColorMe側の受注金額が実際の回収額より高くなる」という構造上同型の
	 * 金銭的懸念）はexport blockingの対象だが、こちらは意図的にblockingへ含めない: 割引・
	 * クーポンを一切運べない設計上の制約そのものであり、保留しても解決する見込みが無い。
	 * blocking化するとクーポンを使った受注が一切移行できなくなり、
	 * `PRICES_INCLUDE_TAX_DISABLED`をblockingへ含めなかった理由（多くの実店舗の既定設定で
	 * 発火し、挙動確認自体ができなくなる）と同種の弊害が生じるため、情報提供の警告に留める
	 * （E2-3 PR-Cレビュー指摘）。
	 */
	public const ORDER_DISCOUNT_NOT_PUSHED = 'order_discount_not_pushed';

	/**
	 * `ColorMeCommerceAdapter::push_order()`: 受注に決済手数料（`payment.fee`）または送料
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
	 * `ColorMeCommerceAdapter::push_order()`: 新規作成が成功した場合に常に付与する。`POST /v1/sales`の
	 * リクエストスキーマに受注日時を指定するフィールドが存在しない（swagger確認済み）ため、
	 * ColorMe側の受注日時は`CanonicalOrder::$placed_at`（Woo側の実際の注文日時）ではなく
	 * pushを実行した時刻になる。過去の受注を移行する用途では日付ベースの売上集計・レポートが
	 * 実際の購入時期と食い違うことをオペレーターへ常に知らせる（`PRODUCT_IMAGES_NOT_PUSHED`
	 * （非プレミアムプランで常に付く）と同種の、ストア/受注の性質上恒久的に解消しない情報提供
	 * 警告。Codexレビュー指摘）。
	 */
	public const ORDER_PLACED_AT_NOT_PRESERVED = 'order_placed_at_not_preserved';

	public const ORDER_LINE_PRODUCT_UNRESOLVED = 'order_line_product_unresolved';

	public const ORDER_LINE_QUANTITY_INVALID = 'order_line_quantity_invalid';

	public const ORDER_CUSTOMER_UNRESOLVED = 'order_customer_unresolved';

	public const PAYMENT_METHOD_UNMAPPED = 'payment_method_unmapped';

	public const SHIPPING_METHOD_UNMAPPED = 'shipping_method_unmapped';

	public const ORDER_STATUS_UNKNOWN = 'order_status_unknown';

	public const ORDER_TOTAL_RESIDUAL = 'order_total_residual';

	public const ORDER_SPLIT_TAX_UNKNOWN = 'order_split_tax_unknown';

	public const ORDER_TAX_SPLIT_UNAVAILABLE = 'order_tax_split_unavailable';

	public const ORDER_TAX_TOTAL_INCOMPLETE = 'order_tax_total_incomplete';

	public const ORDER_CREATE_FAILED = 'order_create_failed';

	public const ORDER_LINE_TAX_INCONSISTENT = 'order_line_tax_inconsistent';

	public const ORDER_TOTALS_INVALID = 'order_totals_invalid';

	public const ORDER_LINE_AMOUNT_INVALID = 'order_line_amount_invalid';

	/**
	 * インポート時、受注明細の商品は取り込み済みのvariable商品に解決したが、明細のオプション値から
	 * variationを1件に特定できない（`Woo\Writer\OrderItemBuilder`。detailはremote_product_id）。
	 * 明細は`ORDER_LINE_PRODUCT_UNRESOLVED`と同じく商品リンクの無いカスタム行になる。
	 * 実店舗の受注（R3-0n）では、受注後に商品のオプションの軸が増えたため（受注時は1軸、現在は2軸）
	 * 軸の数が合わずに特定できなかった。商品は取り込み済みなので「参照先を先にインポートすれば消える」
	 * （`indicates_pending_import()`）ではないが、variationが後から取り込まれれば解決しうるため
	 * `indicates_unresolved_reference()`の対象に含める（checksumをキャッシュしない）。
	 * エクスポート方向の`ORDER_LINE_VARIATION_UNRESOLVED`（Woo受注明細のvariationを読めない。blocking）とは別物。
	 */
	public const ORDER_LINE_VARIATION_UNMATCHED = 'order_line_variation_unmatched';

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
	 * 4パターンある: (1) `get_variation_id()`が既定値`0`にリセットされている＝バリエーション
	 * 自体が削除済み（`get_product_id()`と同じ「`WC_Order_Item_Product::set_variation_id()`の
	 * 投稿タイプ検証失敗→`WC_Data_Exception`→`set_props()`がプロパティ毎にcatch」パターン。
	 * 生のorder-item-meta（`_variation_id`）で判別する。実測確認済み）、(2) `get_variation_id()`は
	 * 非0だが対応する`WC_Product_Variation`自体が取得できない、(3) バリエーションが「Any（すべての）」の軸を持つ
	 * （D23。属性値が空文字列で保存され、購入時に選ばれた値は明細メタにだけ残るため、どの値の注文か特定できない。
	 * Anyを具体的な値のバリエーションに分けると移行できる。`Woo\Support\VariationAxisResolver::has_any_attribute()`）、
	 * (4) 親商品の軸属性が3つ以上
	 * （`Woo\Support\VariationAxisResolver::axis_attributes()`が`VARIATION_AXIS_LIMIT_EXCEEDED`
	 * を積む。`CanonicalProduct::$variants`のoption1/2規約は2軸までのため3軸目以降を切り捨てる）。
	 * `Woo\Reader\ProductReader`はこの(4)の商品を`VARIATION_AXIS_LIMIT_EXCEEDED`で止める。受注明細では3軸目の
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
	 * エクスポート時、受注明細の`tax_class`が JP の税率で標準（10%）・軽減（8%）のどちらとも判定できない
	 * （`Woo\Support\TaxClass::classify()`。ゼロ税率・JP の税率が無いカスタム税区分等。D26 以前はスラッグ
	 * `''`/`'reduced-rate'`の決め打ちだった）、または`WC_Order_Item_Product::get_tax_status()`が
	 * `'taxable'`以外（送料のみ課税・非課税）。`CanonicalOrder::$line_items[].tax_reduced`は
	 * bool（標準/軽減税率の2値）しか表現できないため、それ以外の税区分・非課税状態を無警告で
	 * 標準課税として扱うと税額が誤って計算されうる（`Woo\Reader\ProductReader`の
	 * `TAX_STATUS_NOT_TAXABLE`と同じ理由）。値は`tax_reduced=false`にフェイルクローズしたうえで、
	 * `indicates_export_blocking()`の対象にして受注ごと送らない（issue #45）。`get_tax_status()`は受注時点ではなく
	 * 商品の現在の課税状態を読む。detail は明細の ASP 側の商品 ID（未解決なら無し）。
	 */
	public const ORDER_LINE_TAX_CLASS_UNSUPPORTED = 'order_line_tax_class_unsupported';

	public const COUPON_REUSED_EXISTING = 'coupon_reused_existing';

	/**
	 * `CanonicalCoupon::$has_unsupported_restrictions` が `true`: ASP側の利用制限のうちWooの
	 * クーポン設定へ写せないものが残っているため保存を見送った。エクスポート方向（`Woo\Reader\CouponReader`）では逆に、
	 * ASP へ運べない Woo の設定（商品・カテゴリー・メールアドレスの制限、最大利用額、個別利用のみ、`fixed_product` 型、利用済み等）が
	 * あるため送らない（`indicates_export_blocking()`）。
	 */
	public const COUPON_RESTRICTIONS_UNSUPPORTED = 'coupon_restrictions_unsupported';

	/**
	 * `CanonicalCoupon::$has_unsupported_restrictions` が `null`: アダプタが制限の有無を宣言して
	 * いないため、不明として保存を見送った（楽観的に「制限なし」へ倒さない）。
	 */
	public const COUPON_RESTRICTIONS_UNKNOWN = 'coupon_restrictions_unknown';

	public const COUPON_CODE_CONFLICT = 'coupon_code_conflict';

	public const COUPON_TYPE_UNKNOWN = 'coupon_type_unknown';

	public const COUPON_AMOUNT_INVALID = 'coupon_amount_invalid';

	public const COUPON_SAVE_FAILED = 'coupon_save_failed';

	public const COUPON_EXPIRES_AT_INVALID = 'coupon_expires_at_invalid';

	public const COUPON_MIN_AMOUNT_INVALID = 'coupon_min_amount_invalid';
}
