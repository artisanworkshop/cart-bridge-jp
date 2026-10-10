<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Entities\WarningFlag;

/**
 * `WriteResult::$warnings` に積む警告コード定数。`"{code}:{detail}"` 形式の文字列にする
 * （F1-6のdry-run CSV・結果レポートが`:`で分解できる契約）。コード自体はi18nしない安定キーで、
 * 店舗向けの説明（重大度・原因・対処）は `Woo\WarningCatalog` に書く（R3-0k）。定数を足したら、取込み・エクスポートの
 * 両方の向きの説明をカタログに足すこと（`WarningCatalogTest` が全定数について強制する）。顧客・受注・クーポンのコードは
 * Pro アドオンの `CommerceWarningCode`（R3-6c1）で、説明と判定の印は Pro の実体の種類が持つ（`Entities\*Warnings`。R3-6b1）。
 */
final class WarningCode {

	private function __construct() {}

	public const ENTITY_NOT_SUPPORTED = 'entity_not_supported';

	/**
	 * `Sync\Importer::process_items()`の汎用catch-allが`EntityWriter::write()`/`validate()`の
	 * 例外を拾った際に積む固定コード（F1-6のdry-run結果レポート用）。`Support\Logger`と同じ
	 * 個人情報禁止ルールのため、例外メッセージ自体は含めない。`Sync\Exporter::process_items()`も
	 * `PlatformWriter::write()`の例外で同じコードを積む（dry-run の書き手は例外を投げないので、エクスポートの CSV には実質出ない）。
	 */
	public const VALIDATION_EXCEPTION = 'validation_exception';

	/**
	 * `Woo\Writer\ProductWriter`（インポート方向）専用。`wc_prices_include_tax()`が偽のため、
	 * ASPの税込価格をそのまま書くとWoo側でチェックアウト時に税が上乗せされうる（警告のみ・自動変更しない。
	 * docs/03 §5「税の扱い」）。`wc_prices_include_tax()`は税計算 OFF でも偽なので、税計算 OFF の店舗でも付く（その場合は
	 * 税が上乗せされず、取り込んだ価格がそのまま支払額になる）。店舗全体の設定なので、Writer のインスタンス（ページ）につき最初の 1 商品にだけ付く。
	 * エクスポート方向は`PRICES_CONVERTED_TO_TAX_INCLUSIVE`。
	 */
	public const PRICES_INCLUDE_TAX_DISABLED = 'prices_include_tax_disabled';

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

	public const SKU_DUPLICATE = 'sku_duplicate';

	/**
	 * インポート（`Woo\Support\TaxClass::resolve()`）: アダプタが渡した Woo の税区分スラッグ（正規化モデルの軽減税率の記号以外）が
	 * Woo に無い。標準の税区分に倒して保存した。detail はそのスラッグ。
	 */
	public const TAX_CLASS_MISSING = 'tax_class_missing';

	/**
	 * インポート（`Woo\Support\TaxClass::resolve()`）: 入れた税区分に税率が 1 件も無い（WooCommerce はその商品・明細を課税しない）。
	 * 軽減税率の商品では、JP の税率が 8% の税区分が無いときに既定の軽減税率の税区分（`reduced-rate`／`軽減税`）へ入れた場合に付く。
	 * その税区分に日本の税率を足せば、取り込み直さなくても正しくなる（checksum は保存する）。detail は税区分のスラッグ。
	 * dry-run の CSV の `note` は `tax_setup_required`。
	 */
	public const TAX_RATES_NOT_CONFIGURED = 'tax_rates_not_configured';

	/**
	 * インポート（`Woo\Support\TaxClass::resolve()`。D26、issue #102）: 軽減税率（8%）の商品・明細を入れる税区分が Woo に無い
	 * （JP の税率が 8% の税区分も、税率の無い既定の軽減税率の税区分も無い）。標準の税区分に倒して保存した（本実行は止めない。
	 * 2026-10-06 ユーザー決定）。WooCommerce の設定の「税」で、軽減税率の税区分に JP の 8% の税率を作ってから取り込み直す。
	 * 商品は checksum を保存しない（`indicates_reduced_tax_class_fallback()`。作ってから取り込み直せば直る）。受注は保存する
	 * （取込みのたびに明細を作り直さないため。受注の金額は ASP の値を明示しており、変わるのは税区分の名前だけ）。
	 * dry-run の CSV の `note` は `tax_setup_required`。
	 */
	public const REDUCED_TAX_CLASS_NOT_FOUND = 'reduced_tax_class_not_found';

	/**
	 * エクスポート（`Woo\Reader\ProductReader`。R3-1d、issue #78）: 課税商品の税区分が、JP の税率で標準（10%）・軽減（8%）の
	 * どちらとも判定できない（`Woo\Support\TaxClass::classify()` が `unsupported`＝ゼロ税率などそれ以外の税率、または
	 * `unconfigured`＝JP の税率が無い）。正規化モデルは標準・軽減しか運べず、ColorMe も商品単位の軽減税率フラグしか持たないため、
	 * 送ると誤った税区分で販売される。`indicates_export_blocking()` の対象（作成も更新もしない）。detail は Woo の税区分の名前
	 * （標準の税区分は `Standard`。標準に JP 8%・10% 以外の税率〔0% など〕を入れた店舗で付く。review-loop R1-2）。
	 * 案内: 商品の税区分を標準か軽減税率に変える、または税区分に日本の税率（10%・8%）を設定する。
	 * `ColorMeAdapter::push_product()` も、正規化モデルの税区分が記号以外のときに多重防御として同じコードで送らない
	 * （その detail は正規化モデルの値〔`Woo\Support\TaxClass::to_canonical()` の `woo:` 付きのスラッグ〕）。
	 */
	public const TAX_CLASS_UNSUPPORTED = 'tax_class_unsupported';

	/**
	 * エクスポート（`Woo\Reader\ProductReader`。R3-1d）: 公開バリエーションの税区分（親と同じ設定なら親の税区分）が、標準・軽減の
	 * どちらとも判定できない（`TAX_CLASS_UNSUPPORTED` と同じ判定）。ColorMe の税区分は商品単位のため、ゼロ税率などのバリエーションは
	 * 親の税区分で課税されてしまう。`indicates_export_blocking()` の対象。detail はバリエーションの ID。
	 * 標準の親の下の軽減税率のバリエーション（支払額は一致する）は対象外。
	 */
	public const VARIATION_TAX_CLASS_UNSUPPORTED = 'variation_tax_class_unsupported';

	/**
	 * `ColorMeAdapter::push_product()`（R3-1d）: 価格を 1 件も ColorMe の基準へ換算できない（`shop.json` の税設定〔内税・外税、
	 * 税率、端数処理〕が読めない等）ため、商品を作成も更新もしなかった（以前は作成時だけ非公開にしていたが、更新で公開されていた。
	 * issue #78）。ColorMe の店舗設定で決まるため dry-run には出ない（dry-run はアダプタを呼ばない。既知の限界）。
	 */
	public const PRODUCT_PRICE_NOT_CONVERTIBLE = 'product_price_not_convertible';

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
	 * （`CanonicalProduct::$variants`のoption1/2規約は2軸まで。`Woo\Support\VariationAxisResolver`）。
	 * 異なる3軸目の値を持つバリエーション同士が同じoption1/2の組に潰れるため、`indicates_export_blocking()`の対象にして
	 * 商品ごと送らない（先頭2軸で送るのではない）。
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
	 * エクスポート時、軸属性のいずれかを「Any（すべての）」にした公開バリエーションがある
	 * （`Woo\Reader\ProductReader`。バリエーションIDが`:{variation_id}`のdetailで付く）。Wooはこの場合
	 * 属性値を空文字列で保存し（ローカル属性・taxonomy属性とも実測確認済み）、購入時に選ばれた値は受注明細の
	 * メタにだけ残る。`CanonicalProduct::$variants`の`option*_value`は具体的な値しか表現できず、ColorMeにも
	 * 相当する仕組みが無いため、このまま送ると商品はAnyのバリエーションだけ欠けた状態で作られる（D23）。
	 * プラットフォーム非依存で`indicates_export_blocking()`の対象にする（`ALL_VARIATIONS_EXCLUDED`と同じ）。
	 * Anyを具体的な値のバリエーションに分けると移行できる。全組み合わせへの展開はv1.xで要望を見て検討する。
	 * 受注明細側は既存の`ORDER_LINE_VARIATION_UNRESOLVED`で止まる（Pro アドオンの`Woo\Reader\OrderReader`）。
	 */
	public const VARIATION_ANY_ATTRIBUTE_UNSUPPORTED = 'variation_any_attribute_unsupported';

	/**
	 * エクスポート時、variable商品の公開バリエーションの在庫管理が混在している（管理中または管理外の在庫切れ＝
	 * 整数と、管理外の在庫あり＝`null`が両方ある。`Woo\Support\StockDerivation::has_mixed_variation_management()`）。
	 * `Woo\Reader\ProductReader`は商品に、`Woo\Reader\StockReader`はその商品の各バリエーションの在庫行に積む
	 * （判定は同じ関数を共有する）。在庫管理を商品単位でしか持たないASP（ColorMe）では表現できず、管理外の
	 * バリエーションが売り切れ表示のまま戻らなくなる（D22）。Woo側で全バリエーションの在庫管理を有効にする、
	 * または全バリエーションで無効にしたうえで在庫状況（在庫あり／在庫切れ）も揃えると移行できる
	 * （管理外の在庫切れは`0`、在庫ありは`null`のため、全て管理外でも両者が混ざっていれば混在のまま止まる）。
	 * 止めるかどうかはプラットフォームの能力（`Adapters\Capabilities::$supports_per_variant_stock_management`）で
	 * `Sync\Exporter`が決めるため、`indicates_export_blocking()`には**登録しない**（登録すると
	 * バリエーション単位で在庫管理できるASPでも止まる）。判定は`indicates_variation_stock_mixed()`。
	 */
	public const VARIATION_STOCK_MANAGEMENT_MIXED = 'variation_stock_management_mixed';

	/**
	 * エクスポート時、Wooの`tax_status`が`taxable`以外（`shipping`/`none`）。`CanonicalProduct`は
	 * `tax_status`を運ぶフィールドを持たず（`tax_class`のみ）、無警告のまま変換先へ渡すと
	 * 「送料のみ課税」「非課税」の商品が通常課税として扱われうる（`Woo\Reader\ProductReader`）。
	 * `indicates_export_blocking()`の対象で、商品を送らない（店舗の税計算が OFF でも止まる）。
	 */
	public const TAX_STATUS_NOT_TAXABLE = 'tax_status_not_taxable';

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

	public const VARIATION_SAVE_FAILED = 'variation_save_failed';
	public const PRODUCT_SAVE_FAILED   = 'product_save_failed';

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
	 * `ColorMeAdapter::push_product()`: 画像を一切pushしなかった。原因は2つ: 非プレミアムプラン契約
	 * （`capabilities()->can_push_images`が false）、またはプレミアムだがExportタブの「商品画像をアップロード
	 * する（Beta）」がオフ（既定。D24）。前者はプラン変更しない限り、後者は設定をオンにするまで解決しない
	 * 終端状態のため`indicates_unresolved_reference()`には含めない（画像URL一覧の集約UIはE2-4スコープ。
	 * 本コードは警告としてのみ結果に残す）。
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
	 * `Sync\Exporter`: 作成経路（`existing_remote_id === null`）でこの実体を送信する前に、
	 * `Sync\PushIntentRepository`に未解決の印（前回以前の試行で結果が不明のまま残ったもの）が
	 * 見つかったため、今回はpushせずスキップした（D21-B。`docs/03-design-decisions.md` §10.2
	 * 「B: 作成結果が不明な実体を自動では再送しない」）。印は`Woo\Tools\PushIntentPresenter`/
	 * REST（`GET/POST /push-intents/{platform}`）経由で店舗がColorMe側を確認してから解除するまで
	 * 残り続ける。`ReadItem::$warnings`起点の`indicates_export_blocking()`/
	 * `indicates_unresolved_reference()`とは独立した判定（`Exporter`自身がintentの有無で決める）
	 * のため、どちらにも登録しない。
	 */
	public const PUSH_OUTCOME_UNCONFIRMED = 'push_outcome_unconfirmed';

	/**
	 * `ColorMeAdapter::push_product()`: ColorMeはオプション追加で全組み合わせ（直積）を
	 * 自動生成するため、Woo側に対応するバリエーションが無い組み合わせがリモートに残ることがある
	 * （原則4「破壊的操作の禁止」によりこちらから削除できない）。再試行しても消えるとは限らない
	 * ため`indicates_unresolved_reference()`には含めない、純粋な情報提供の警告。
	 */
	public const PRODUCT_VARIANT_SURPLUS_ON_REMOTE = 'product_variant_surplus_on_remote';

	/**
	 * `Sync\Exporter`: この Woo の実体は書き出し先と同じプラットフォームからの取込みで結ばれている
	 * （`Woo\Support\EntityOrigin`。`Woo\Reader\ReadItem::$linked_by_import`）ため送らなかった（D25「実体は作られた向きにだけ
	 * 更新する」）。情報のみ。送らないことが終端の状態なので、`indicates_export_blocking()`/
	 * `indicates_unresolved_reference()`/CSV の`note`のどれにも登録しない。
	 */
	public const LINKED_BY_IMPORT_NOT_EXPORTED = 'linked_by_import_not_exported';

	/**
	 * `Woo\WooRepository`/`Woo\DryRunRepository`/`Woo\Writer\StockWriter`: mapping が指す Woo の実体（在庫は対象の商品・
	 * バリエーション）はエクスポートで結ばれた（取込みで結ばれていない）ため、取込みで上書きしなかった（D25）。mapping はそのまま残す。
	 * 情報のみ。`LINKED_BY_IMPORT_NOT_EXPORTED`と同じ理由でどの判定関数にも登録しない（`Sync\Importer`の`unchanged`の数え方は
	 * {@see indicates_kept_by_link_direction()}）。
	 */
	public const LINKED_BY_EXPORT_NOT_IMPORTED = 'linked_by_export_not_imported';

	/**
	 * `indicates_unresolved_reference()` の無料版のコード（参照先が後から解決しうる警告）。
	 *
	 * @var array<int,string>
	 */
	private const UNRESOLVED_REFERENCE_CODES = [
		self::CATEGORY_PARENT_UNRESOLVED,
		self::CATEGORY_REF_UNRESOLVED,
		self::TAG_REF_UNRESOLVED,
		self::CATEGORY_MAP_UNRESOLVED,
		// `ColorMeAdapter::push_product()`: 商品本体は作成済みだが追加詳細/バリエーション/画像の
		// サブリクエストが未完了。次回exportで自動的に再試行される（定数のdocblock参照）。
		self::PRODUCT_DETAILS_PUSH_INCOMPLETE,
		self::PRODUCT_VARIANT_PUSH_INCOMPLETE,
		self::PRODUCT_IMAGE_PUSH_INCOMPLETE,
		// 作成が確定した後に処理が止まった場合の警告（Exporterが部分完了の例外から組み立てる）。
		self::PUSH_INTERRUPTED_AFTER_CREATE,
	];

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
	 * 無料版のコードはここの一覧で、登録された実体の種類のコード（顧客・受注・クーポン）は種類が付けた印
	 * （`Entities\WarningFlag::EXPORT_BLOCKING`。R3-6b1）で判定する。
	 *
	 * @param array<int,string> $warnings
	 */
	public static function indicates_export_blocking( array $warnings ): bool {
		$blocking_codes = [
			self::ALL_VARIATIONS_EXCLUDED,
			// `Woo\Reader\ProductReader`: 「Any」バリエーションを含む商品。具体的な値を持たないため
			// `CanonicalProduct::$variants`で表現できず、黙って送るとAnyのバリエーションだけ欠けた商品が
			// 作られる（D23）。プラットフォーム非依存。
			self::VARIATION_ANY_ATTRIBUTE_UNSUPPORTED,
			// `Woo\Reader\StockReader`: 対象商品/バリエーションがまだASP側へエクスポートされて
			// おらず、`CanonicalStock::$product_ref`（非nullable string）へ入れる有効な値が無い。
			// `CanonicalOrder::$line_items[].remote_product_id`/`$customer_ref`（いずれもnullable）
			// とは異なり必須フィールドのため、空文字列のまま`push_stock()`（E2-3）へ渡さずここで
			// 止める。checksumは`continue`で未到達のままキャッシュされないため、商品が後から
			// エクスポートされ次第この行は自動的に再試行される（`indicates_unresolved_reference()`
			// への追加は不要）。
			self::STOCK_PRODUCT_NOT_EXPORTED,
			// `Woo\Reader\ProductReader`: 課税商品・公開バリエーションの税区分が、JP の税率で標準・軽減のどちらとも判定できない
			// （R3-1d、issue #78）。正規化モデルは標準・軽減しか運べないため、送ると誤った税区分で販売される。以前は ColorMe の
			// 作成時だけ非公開にしていたが、更新で課税商品として公開されていた。標準の税区分（`''`）は JP の税率が無ければ標準とみなすので、
			// 新しい WooCommerce の既定（税率なし・全商品が標準）では発火しない（標準に JP 0% などを入れた店舗では標準の商品も止まる。
			// review-loop R1-2）。プラットフォーム非依存（`TAX_STATUS_NOT_TAXABLE`と同じ）。
			self::TAX_CLASS_UNSUPPORTED,
			self::VARIATION_TAX_CLASS_UNSUPPORTED,
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
			// 既定環境でもここには含めなくてよい（blocking化すると税計算OFFの既定環境で商品を送れなくなる）。
			self::PRICE_TAX_BASIS_UNRESOLVED,
			// `Woo\Support\VariationAxisResolver`: バリエーション軸が3つ以上あり、`CanonicalProduct::
			// $variants`のoption1/2規約（2軸まで）に合わせ3軸目以降を切り捨てている。
			// `ColorMeAdapter::sync_variants()`は`option1/2`の組のみで突合するため、3軸目の値だけが
			// 異なる複数のバリエーションが同じ組に潰れ、誤ったSKU/価格/在庫が別バリエーションへ
			// 入れ替わってpushされうる（R3レビュー指摘, Copilot）。
			self::VARIATION_AXIS_LIMIT_EXCEEDED,
		];

		foreach ( $warnings as $warning ) {
			$code = self::split( $warning )[0];

			if ( in_array( $code, $blocking_codes, true ) || self::has_flag( $code, WarningFlag::EXPORT_BLOCKING ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `Sync\Importer::process_items()`用: 取込みがエクスポートで結ばれた実体を上書きしなかった結果か（D25。
	 * `LINKED_BY_EXPORT_NOT_IMPORTED`）。mapping がある実体なら既に結ばれている＝移行済みとして`unchanged`にも数える
	 * （Exporter の取込みで結ばれた実体の扱いと揃える）。
	 *
	 * @param array<int,string> $warnings
	 */
	public static function indicates_kept_by_link_direction( array $warnings ): bool {
		foreach ( $warnings as $warning ) {
			if ( self::LINKED_BY_EXPORT_NOT_IMPORTED === self::split( $warning )[0] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `Sync\Exporter::process_items()`用: 在庫管理が混在するvariable商品（またはその在庫行）か
	 * （D22）。`indicates_export_blocking()`と違い**それだけでは止めない**: アダプタが
	 * `Capabilities::$supports_per_variant_stock_management`を宣言していないときだけ`Exporter`が止める。
	 *
	 * @param array<int,string> $warnings
	 */
	public static function indicates_variation_stock_mixed( array $warnings ): bool {
		foreach ( $warnings as $warning ) {
			if ( self::VARIATION_STOCK_MANAGEMENT_MIXED === self::split( $warning )[0] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * `Sync\Importer::process_items()`がchecksumをキャッシュしてよいか（`WriteResult::$fully_resolved`）
	 * の判定に使う。ここに列挙するのは「参照先が後から解決可能になりうる」警告のみ:
	 * category/tag/親カテゴリ・顧客参照・注文明細の商品参照が未解決のまま実体自体は保存された
	 * ケースと、決済/配送方法のマッピングが後から設定されうるケース（R3-0m）。
	 * `CUSTOMER_ACCOUNT_PROTECTED`（管理者アカウントとの衝突）のように解決される見込みが
	 * ない終端状態はここに含めない（含めると、解決される可能性が無いのに毎回無駄に再処理される）。
	 * 無料版のコードは `UNRESOLVED_REFERENCE_CODES`、登録された種類のコード（受注の商品・顧客の参照、決済/配送方法の未マッピング〔R3-0m〕）は
	 * 種類が付けた印（`Entities\WarningFlag::UNRESOLVED_REFERENCE`）で判定する。
	 *
	 * @param array<int,string> $warnings
	 */
	public static function indicates_unresolved_reference( array $warnings ): bool {

		foreach ( $warnings as $warning ) {
			$code = self::split( $warning )[0];

			if ( in_array( $code, self::UNRESOLVED_REFERENCE_CODES, true ) || self::has_flag( $code, WarningFlag::UNRESOLVED_REFERENCE ) ) {
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
	 *
	 * R3-0n: 受注の商品・顧客の参照（{@see indicates_reference_not_found()}）と
	 * `ORDER_LINE_VARIATION_UNMATCHED`（商品は取り込み済み）は除く。実店舗の受注では、商品・顧客の未解決は
	 * ASP側で削除済みかバリエーションの不一致のどちらかで、どれも先にインポートしても消えなかった。
	 */
	public static function indicates_pending_import( string $warning ): bool {
		$code = self::split( $warning )[0];

		// 無料版のコードは `UNRESOLVED_REFERENCE_CODES` から導く。登録された種類のコードは、`indicates_unresolved_reference()` の印から
		// 導かず `PENDING_IMPORT` の印を付けたものだけにする（受注の商品・顧客の参照と `ORDER_LINE_VARIATION_UNMATCHED` は
		// checksum をキャッシュしないが、先にインポートしても消えない。R3-0n）。
		$candidate = in_array( $code, self::UNRESOLVED_REFERENCE_CODES, true )
			|| self::STOCK_PRODUCT_UNRESOLVED === $code
			|| self::has_flag( $code, WarningFlag::PENDING_IMPORT );

		return $candidate
			&& ! self::indicates_mapping_required( $warning )
			&& ! self::indicates_pending_export( $warning )
			&& ! self::indicates_reference_not_found( $warning );
	}

	/**
	 * dry-runレポート（`Admin\DryRunReportCsv`の`note`列。値は`reference_unresolved`）用: 取り込んだ実体の参照先がローカルに
	 * 見つからず、未取込みか取り込めない（ASP 側で削除済み等）かを区別できない警告か（今は受注の `ORDER_LINE_PRODUCT_UNRESOLVED`/
	 * `ORDER_CUSTOMER_UNRESOLVED`）。R3-6c1 で `indicates_order_reference_unresolved()` から改名した（受注に限らない印の判定のため。
	 * `indicates_unresolved_reference()`〔checksum をキャッシュしない警告〕とは別物）。
	 *
	 * 参照先がまだインポートされていないだけなのか、ASP側で削除済み・インポート対象外なのかは、Woo側の
	 * mappingsだけでは区別できない。実店舗の受注の dry-run（R3-0n）では、この 2 コードの参照先
	 * すべてが ColorMe 側で削除済み（`GET /products/{id}.json`・`/customers/{id}.json` が 404）で、先にインポートしても
	 * 消えないのに`reference_pending_import`（「先にインポートすれば消える」）が付いていた。そのため
	 * `indicates_pending_import()`から外し、両方の可能性を含む中立の注記にする。checksumキャッシュの判定
	 * （{@see indicates_unresolved_reference()}）は変えない（未インポートなら後から解決しうるため）。
	 * どちらのコードも受注の種類が持つので、種類が付けた印（`Entities\WarningFlag::REFERENCE_UNRESOLVED`。R3-6b1）で判定する。
	 */
	public static function indicates_reference_not_found( string $warning ): bool {
		return self::has_flag( self::split( $warning )[0], WarningFlag::REFERENCE_UNRESOLVED );
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
		$code = self::split( $warning )[0];

		// 受注の 2 コードは受注の種類の印（`Entities\WarningFlag::PENDING_EXPORT`。R3-6b1）。
		return self::STOCK_PRODUCT_NOT_EXPORTED === $code || self::has_flag( $code, WarningFlag::PENDING_EXPORT );
	}

	/**
	 * dry-runレポート（`Admin\DryRunReportCsv`の`note`列）用: この警告が「ASP側に対応する実体を
	 * 先にインポートすれば消える」のではなく「マッピング設定（`/settings/mappings/{platform}`。
	 * 管理画面の Mappings タブ）を追加すれば消える」ものか。
	 *
	 * - `CATEGORY_MAP_UNRESOLVED`（エクスポート方向、`category_map`未設定）は
	 *   `indicates_unresolved_reference()`（checksumキャッシュ判定）の対象ではあるが、
	 *   「参照先を先にインポートする」という`indicates_pending_import()`の案内は的外れ
	 *   （インポート方向の概念が無いエクスポートに「インポートしてください」と出てしまう）
	 *   なため専用の判定を分ける。
	 * - `PAYMENT_METHOD_UNMAPPED`/`SHIPPING_METHOD_UNMAPPED`（R3-0m。R3-6c1 から Pro アドオンのコード）: インポート方向（`Woo\Writer\OrderWriter`。
	 *   detail はASP側のID）では`payment_map`/`shipping_map`の未設定、または設定先のWoo決済/配送方法が実在しない
	 *   ときに付き、マップを設定すれば消える。dry-run の CSV に現れるのはこの方向だけ。エクスポート方向
	 *   （`ColorMeCommerceAdapter::push_order()`。detail はWoo側のID）では、Woo受注に決済/配送方法そのものが無い（detail が空）
	 *   ときや、逆引きが曖昧（複数のASP側IDが同じWoo側IDを指す）なときにも付き、マップを足すだけでは消えないことがある。
	 *   ただしエクスポートの dry-run は警告を返さず、実エクスポートは dry-run 明細を書かないため、その注記が CSV に出る経路は無い。
	 *   両コードは`indicates_unresolved_reference()`の対象でもあるが（checksum をキャッシュしない）、`note`は
	 *   `indicates_pending_import()`より先にこちらで決まる。
	 */
	public static function indicates_mapping_required( string $warning ): bool {
		$code = self::split( $warning )[0];

		// 決済/配送方法の 2 コードは受注の種類の印（`Entities\WarningFlag::MAPPING_REQUIRED`。R3-6b1）。
		return self::CATEGORY_MAP_UNRESOLVED === $code || self::has_flag( $code, WarningFlag::MAPPING_REQUIRED );
	}

	/**
	 * dry-runレポート（`Admin\DryRunReportCsv`の`note`列。値は`tax_setup_required`）用: WooCommerce の税の設定
	 * （軽減税率の税区分と JP の 8% の税率）を作れば消える取込みの警告か（D26「dry-run で先に税率を作るよう促す」）。
	 */
	public static function indicates_tax_setup_required( string $warning ): bool {
		return in_array( self::split( $warning )[0], [ self::REDUCED_TAX_CLASS_NOT_FOUND, self::TAX_RATES_NOT_CONFIGURED ], true );
	}

	/**
	 * `Woo\Writer\ProductWriter`用: 軽減税率の商品を、入れる税区分が無いため標準の税区分に倒して保存したか
	 * （`REDUCED_TAX_CLASS_NOT_FOUND`）。商品だけ checksum を保存せず、税区分と税率を作ってから取り込み直せば直るようにする。
	 * `indicates_unresolved_reference()`（受注も共有する）には入れない: 受注は取込みのたびに明細を作り直すことになるため
	 * （定数の docblock 参照）。
	 *
	 * @param array<int,string> $warnings
	 */
	public static function indicates_reduced_tax_class_fallback( array $warnings ): bool {
		foreach ( $warnings as $warning ) {
			if ( self::REDUCED_TAX_CLASS_NOT_FOUND === self::split( $warning )[0] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 登録された実体の種類がこのコードに付けた印か（`Entities\EntityTypeRegistry::warning_flags()`。無料版のコードには付かない）。
	 */
	private static function has_flag( string $code, string $flag ): bool {
		return isset( EntityTypeRegistry::warning_flags( $code )[ $flag ] );
	}
}
