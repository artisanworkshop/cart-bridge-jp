<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Support\Money;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\VariationAxisResolver;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\OrderWriter;
use WC_DateTime;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * `WC_Order` を `CanonicalOrder` へ変換する（`Woo\Writer\OrderWriter` の読出側対称形）。
 *
 * 決済/配送方法・受注ステータスはWooの生コード（`get_payment_method()`/`get_status()`等）を
 * そのまま`payment`/`shipping`/`status`へ載せ、ASP側コードへの変換はE2-3の`push_order()`
 * （ColorMeアダプタ）へ委ねる（D19の申し送り。`docs/03-design-decisions.md` §10.2）。
 * 配送先・請求先住所も同じ理由でWooネイティブのキーのまま運ぶ（`Woo\Reader\CustomerReader`と
 * 同じ判断）。一方、明細の商品参照（`remote_product_id`）と購入者（`customer_ref`）は
 * `cbjp_mappings`によるプラットフォーム非依存の解決が可能なため、ここで解決する。
 * `remote_product_id`はバリエーション明細でも常に**親商品**のremote_idを使う: ColorMeの
 * `POST /v1/sales`は`details[].product_id`に親商品IDを要求し、バリエーションは
 * `option1_value_current`/`option2_value_current`で識別する契約のため（`variant`entityの
 * remote_idではない。`Woo\Support\VariationAxisResolver`で`Woo\Reader\ProductReader`と
 * 軸属性解決ロジックを共有する）。
 * ページ内の全注文をスキャンしてから`MappingRepository::find_many_by_local_ids()`で一括解決する
 * （アイテム毎のSELECTを避けるため。`ProductReader::variants()`と同じ理由）。
 */
final class OrderReader implements EntityReader {

	private const PAGE_SIZE = 20;

	/**
	 * @var array<int,array{remote_id:string,checksum:?string}>
	 */
	private array $product_refs = [];

	/**
	 * @var array<int,array{remote_id:string,checksum:?string}>
	 */
	private array $customer_refs = [];

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings
	) {}

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$args = [
			'status'   => $this->exportable_statuses(),
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'objects',
			'paginate' => true,
			'limit'    => self::PAGE_SIZE,
			'page'     => (int) $cursor->get( 'page', 1 ),
		];

		if ( null !== $only_local_ids ) {
			if ( [] === $only_local_ids ) {
				return new ReadPage( [], null, 0 );
			}

			$args['post__in'] = $only_local_ids;
			unset( $args['page'] );
			$args['limit'] = count( $only_local_ids );
		}

		/** @var object{orders:array<int,WC_Order>,total:int,max_num_pages:int} $result */
		$result = wc_get_orders( $args );

		$this->preload_mappings( $result->orders );

		$items = array_values( array_map( fn ( WC_Order $order ): ReadItem => $this->to_read_item( $order ), $result->orders ) );

		$page          = (int) ( $args['page'] ?? 1 );
		$has_next_page = null === $only_local_ids && $page < $result->max_num_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, $result->total );
	}

	/**
	 * ページ内の全注文をスキャンし、商品/バリエーション/顧客のremote_idを1回のクエリずつで
	 * 一括解決する（`ProductReader::variants()`の`find_many_by_local_ids()`一括先読みと同じ理由）。
	 *
	 * @param array<int,WC_Order> $orders
	 */
	private function preload_mappings( array $orders ): void {
		$product_ids  = [];
		$customer_ids = [];

		foreach ( $orders as $order ) {
			$customer_id = $order->get_customer_id();

			if ( 0 !== $customer_id ) {
				$customer_ids[ $customer_id ] = true;
			}

			foreach ( $order->get_items() as $order_item ) {
				if ( ! $order_item instanceof WC_Order_Item_Product ) {
					continue;
				}

				// `get_product_id()`はバリエーション明細でも常に親商品IDを返す（CLAUDE.md）。
				// `remote_product_id()`はColorMe `POST /v1/sales`の契約（`details[].product_id`
				// ＝親商品、バリエーションはoption1/2_valueで識別）に合わせ常に親IDで解決するため、
				// バリエーション自体の`variant`mappingは不要（Codex指摘 #6）。
				$product_id = $order_item->get_product_id();

				if ( 0 !== $product_id ) {
					$product_ids[ $product_id ] = true;
				}
			}
		}

		$this->product_refs  = $this->mappings->find_many_by_local_ids( $this->platform, 'product', array_keys( $product_ids ) );
		$this->customer_refs = $this->mappings->find_many_by_local_ids( $this->platform, 'customer', array_keys( $customer_ids ) );
	}

	/**
	 * `wc-checkout-draft`（WooCommerce Blocksのチェックアウト下書き。24時間後に日次cronで完全削除
	 * される）を除く全ステータス（`Sync\ExportSampleSelector::select_and_persist()`と同じ理由・
	 * 同じ式。CLAUDE.md参照）。
	 *
	 * @return array<int,string>
	 */
	private function exportable_statuses(): array {
		return array_values( array_diff( array_keys( wc_get_order_statuses() ), [ 'wc-checkout-draft' ] ) );
	}

	private function to_read_item( WC_Order $order ): ReadItem {
		$warnings = [];

		// 対応ASPの金額はすべて日本円（`Woo\Writer\OrderWriter::PLATFORM_CURRENCY`）。店舗通貨が
		// これと異なる場合、`totals`/`line_items`の金額は換算せずそのまま運ぶ（`OrderWriter`の
		// インポート方向と同じ前提）ため、E2-3の`push_order()`が誤って日本円として送信しないよう
		// ここで検知できるようにする（`extras['currency']`に実際の通貨コードも積む）。
		if ( OrderWriter::PLATFORM_CURRENCY !== $order->get_currency() ) {
			$warnings[] = WarningCode::with_detail( WarningCode::CURRENCY_MISMATCH, $order->get_currency() );
		}

		// 一部/全額返金済みの受注は、`get_total()`等の明細・合計系getterが返金前の金額のまま
		// （返金額は別オブジェクトの`WC_Order_Refund`に分離して記録される。`get_refunds()`/
		// `get_total_refunded()`）。`CanonicalOrder`に返金額を運ぶフィールドが無いため、無警告で
		// pushすると実際には回収していない金額を全額回収済みとしてASP側に作成してしまう
		// （Codex指摘, PR #41 #12: 返金の有無を確認していなかった。金銭的リスク）。
		if ( (float) $order->get_total_refunded() > 0 ) {
			$warnings[] = WarningCode::ORDER_REFUNDED;
		}

		[ $line_items, $line_item_warnings ] = $this->line_items( $order );
		$warnings                            = array_merge( $warnings, $line_item_warnings );

		[ $customer_ref, $customer_warning ] = $this->customer_ref( $order );

		if ( null !== $customer_warning ) {
			$warnings[] = $customer_warning;
		}

		[ $totals, $totals_warning ] = $this->totals( $order );

		if ( null !== $totals_warning ) {
			$warnings[] = $totals_warning;
		}

		$canonical = new CanonicalOrder(
			$order->get_order_number(),
			$order->get_status(),
			$customer_ref,
			$line_items,
			$this->shipping( $order ),
			$this->payment( $order ),
			$totals,
			$order->get_date_created() instanceof WC_DateTime ? $order->get_date_created()->date( DATE_ATOM ) : '',
			$this->note( $order ),
			$this->extras( $order )
		);

		return new ReadItem( $order->get_id(), $canonical, $warnings, ! WarningCode::indicates_unresolved_reference( $warnings ) );
	}

	/**
	 * `extras['customer_snapshot']`/`extras['paid']`はキー自体を`Woo\Writer\OrderWriter::
	 * apply_addresses()`/`apply_dates()`と共有する契約だが、値の中身（住所のキー体系）は
	 * インポート方向（ColorMeの`pref_id`スキーム。`Woo\Support\AddressMapper::to_woo()`が解釈）
	 * とは異なりWooネイティブのキーのまま運ぶ（クラスdocblock参照）ため、この`OrderReader`が
	 * 出力した`CanonicalOrder`を直接`OrderWriter`へ渡しても請求先住所はそのままでは復元されない
	 * （name/email/phone/companyはキー名が一致するため復元される）。本来の消費者はE2-3の
	 * `push_order()`（Woo→ColorMeへ変換する際、ここで積んだWooネイティブの住所からColorMeの
	 * リクエスト形式を組み立てる）であり、空の`extras`のままだと`push_order()`に購入者情報が
	 * 一切渡らなくなるため、Wooの現在の請求先住所と支払済み状態をここへ積む。
	 *
	 * @return array<string,mixed>
	 */
	private function extras( WC_Order $order ): array {
		return [
			'customer_snapshot' => $this->customer_snapshot( $order ),
			'paid'              => null !== $order->get_date_paid(),
			'currency'          => $order->get_currency(),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function customer_snapshot( WC_Order $order ): array {
		$address = $order->get_address( 'billing' );

		return [
			'name'      => $this->full_name( $address ),
			'email'     => '' !== $order->get_billing_email() ? $order->get_billing_email() : null,
			'phone'     => '' !== $order->get_billing_phone() ? $order->get_billing_phone() : null,
			'company'   => '' !== ( $address['company'] ?? '' ) ? $address['company'] : null,
			'address_1' => '' !== ( $address['address_1'] ?? '' ) ? $address['address_1'] : null,
			'address_2' => '' !== ( $address['address_2'] ?? '' ) ? $address['address_2'] : null,
			'city'      => '' !== ( $address['city'] ?? '' ) ? $address['city'] : null,
			'state'     => '' !== ( $address['state'] ?? '' ) ? $address['state'] : null,
			'postcode'  => '' !== ( $address['postcode'] ?? '' ) ? $address['postcode'] : null,
			'country'   => '' !== ( $address['country'] ?? '' ) ? $address['country'] : null,
		];
	}

	/**
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
	 */
	private function line_items( WC_Order $order ): array {
		$items    = [];
		$warnings = [];

		foreach ( $order->get_items() as $order_item ) {
			if ( ! $order_item instanceof WC_Order_Item_Product ) {
				continue;
			}

			[ $remote_product_id, $line_warning, $option1_value, $option2_value ] = $this->remote_product_id( $order_item );

			if ( null !== $line_warning ) {
				$warnings[] = $line_warning;
			}

			// `OrderItemBuilder`（インポート方向）と同じ基準: 数量が欠損・0以下の場合、1個として
			// 捏造すると実際の購入数と食い違う出荷指示になりうる。明細自体は残しつつ
			// `ORDER_LINE_QUANTITY_INVALID`で不確かである旨を警告する。
			//
			// `WC_Order_Item_Product::get_quantity()`は内部で`wc_stock_amount()`
			// （`woocommerce_stock_amount`フィルター経由で量り売り等の小数量拡張が介入しうる。
			// 公式docblockが`int|float`を宣言。手元のstubは`int`のみを宣言しPHPStanは常にint型と
			// 静的に推論するため、`is_int()`での分岐は「常にfalse」と誤検知される）を通すため、
			// 実行時の型を`int`と信用しない。`declare(strict_types=1)`下で非整数値をそのまま
			// `divide_minor_units_rounded(int $minor, int $divisor)`へ渡すと`TypeError`で明細1件
			// どころかページ全体の処理を落とす（Codex指摘 #10）。float経由の数値比較で小数を検出し、
			// 整数量非対応のASP向けに丸めたうえで警告する。
			$raw_quantity      = (float) $order_item->get_quantity();
			$quantity          = (int) round( $raw_quantity );
			$quantity_is_exact = 0.0 === abs( $raw_quantity - $quantity );

			if ( ! $quantity_is_exact || $quantity <= 0 ) {
				$quantity   = max( 1, $quantity );
				$warnings[] = WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, $remote_product_id ?? '' );
			}

			[ $subtotal_minor, $subtotal_tax_minor, $amount_warning ] = $this->line_item_amounts( $order_item, $remote_product_id );

			if ( null !== $amount_warning ) {
				$warnings[] = $amount_warning;
			}

			$line_total_minor      = $subtotal_minor + $subtotal_tax_minor;
			$unit_price_excl_minor = self::divide_minor_units_rounded( $subtotal_minor, $quantity );
			$unit_price_incl_minor = self::divide_minor_units_rounded( $line_total_minor, $quantity );

			$tax_class = $order_item->get_tax_class();

			// `CanonicalOrder::$line_items[].tax_reduced`はbool（標準/軽減税率の2値）のみで、
			// `zero-rate`等のその他税区分・非課税/送料のみ課税を表現できない
			// （`Woo\Reader\ProductReader`の`TAX_STATUS_NOT_TAXABLE`と同じ理由）。
			if ( ! in_array( $tax_class, [ '', 'reduced-rate' ], true ) || 'taxable' !== $order_item->get_tax_status() ) {
				$warnings[] = WarningCode::with_detail( WarningCode::ORDER_LINE_TAX_CLASS_UNSUPPORTED, $remote_product_id ?? '' );
			}

			$items[] = [
				'sku'                   => $this->line_item_sku( $order_item ),
				'remote_product_id'     => $remote_product_id,
				// ColorMeの`POST /v1/sales`は`details[].product_id`に親商品IDを要求し、
				// バリエーションは`option1_value_current`/`option2_value_current`（
				// `Woo\Writer\OrderItemBuilder`がimport方向で読むのと同じフィールド名）で識別する
				// 契約（swagger確認済み、Codex指摘 #6）。
				'option1_value_current' => $option1_value,
				'option2_value_current' => $option2_value,
				'name'                  => $order_item->get_name(),
				'quantity'              => $quantity,
				'price'                 => Money::format_minor_units( $unit_price_incl_minor ),
				'subtotal'              => Money::format_minor_units( $line_total_minor ),
				'unit_price_excl_tax'   => Money::format_minor_units( $unit_price_excl_minor ),
				'tax_reduced'           => 'reduced-rate' === $tax_class,
			];
		}

		return [ $items, $warnings ];
	}

	/**
	 * `2 * $minor + $divisor`を`2 * $divisor`で`intdiv()`することで、float除算を一切使わず
	 * 四捨五入（round-half-up）する（CLAUDE.md: 金額計算はfloat除算を避け、先に乗算してから
	 * `intdiv()`に丸め調整値を足す整数演算で行うこと）。`$minor`/`$divisor`はどちらも
	 * `line_item_amounts()`で非負に検証済み、`$divisor`（数量）は1以上に正規化済みのため
	 * ここでは追加のガードを行わない。
	 */
	private static function divide_minor_units_rounded( int $minor, int $divisor ): int {
		return intdiv( 2 * $minor + $divisor, 2 * $divisor );
	}

	/**
	 * 明細の税抜金額は`get_subtotal()`/`get_subtotal_tax()`（**`get_total()`/`get_total_tax()`
	 * ではない**）を使う。Wooの`total`はWoo自身のクーポン計算（`WC_Order::calculate_totals()`）で
	 * 割引後の金額になるが、`totals['discount']`（`get_discount_total()`）は別フィールドとして
	 * 独立に運ぶ契約（`Woo\Writer\OrderWriter::apply_totals()`が`discount_total`を明細とは無関係な
	 * 注文レベルの控除として`set_discount_total()`する。ColorMeのポイント/GMOポイント値引きは
	 * 明細価格を一切変えずorder-level discountとしてのみ表現される、というimport方向の契約と対称）。
	 * `get_total()`（割引後）を明細価格として使うと、割引が「明細側で織り込み済み」と
	 * 「`totals.discount`で別途控除」の二重に効いてしまい、再取込・E2-3のpush_order()いずれでも
	 * 実際より安い金額として扱われる（Wooネイティブのクーポンを使った注文で顕在化）。
	 *
	 * `_line_subtotal`/`_line_subtotal_tax`postmeta由来のため、`WC_Order_Item_Product::
	 * set_subtotal()`/`set_taxes()`自身は符号・数値妥当性を検証しない（他プラグイン・直接の
	 * メタ編集で壊れうる。`Woo\Reader\ProductReader`の`_regular_price`メタと同じ構造。CLAUDE.md
	 * 参照）。負の明細金額をそのまま次工程へ渡すと実質的な値引き・不正な金額として扱われうる
	 * ため、`Woo\Writer\OrderItemBuilder::split_line_amount()`と同じ基準（数値・0以上）で
	 * フェイルクローズする。
	 *
	 * 金額は`Support\Money`で1/100単位の整数（minor units）へ変換して扱う（CLAUDE.md: 金額計算は
	 * float除算を避け整数演算で行うこと。単価計算（`line_items()`の除算）でのfloat丸め誤差を防ぐ）。
	 *
	 * @return array{0:int,1:int,2:?string}
	 */
	private function line_item_amounts( WC_Order_Item_Product $order_item, ?string $remote_product_id ): array {
		$subtotal_minor     = Money::to_minor_units( $order_item->get_subtotal() );
		$subtotal_tax_minor = Money::to_minor_units( $order_item->get_subtotal_tax() );

		if ( null === $subtotal_minor || $subtotal_minor < 0 || null === $subtotal_tax_minor || $subtotal_tax_minor < 0 ) {
			return [ 0, 0, WarningCode::with_detail( WarningCode::ORDER_LINE_AMOUNT_INVALID, $remote_product_id ?? '' ) ];
		}

		return [ $subtotal_minor, $subtotal_tax_minor, null ];
	}

	private function line_item_sku( WC_Order_Item_Product $order_item ): ?string {
		$product = $order_item->get_product();

		if ( false === $product ) {
			return null;
		}

		return '' !== $product->get_sku() ? $product->get_sku() : null;
	}

	/**
	 * `WC_Order_Item_Product::get_product_id()`はバリエーション明細でも常に親商品IDを返す
	 * （CLAUDE.md）。ColorMeの`POST /v1/sales`は`details[].product_id`に**親商品**のremote_idを
	 * 要求し、バリエーションの識別は`option1_value_current`/`option2_value_current`で行う契約
	 * （swagger確認済み。Codex指摘 #6: 当初`variant`entityでバリエーション自身のremote_idを解決
	 * していたのは誤りだった）。そのため`variant`mappingは使わず、常に`product`entityで
	 * 親IDを解決する。`preload_mappings()`が一括先読みした`$this->product_refs`から引く
	 * （アイテム毎のSELECTを避けるため）。
	 *
	 * `ORDER_LINE_PRODUCT_NOT_EXPORTED`（再試行可能＝checksumをキャッシュしない）は「商品が実在
	 * するがまだエクスポートされていない」場合に積む。参照先が削除済みの場合は再エクスポートを
	 * 待っても解決しない終端状態のため`indicates_unresolved_reference()`には含めないが、
	 * `remote_product_id`を恒久的に特定できない状態を「商品リンクを持たない正当なカスタム行」と
	 * 無警告で同一視すると、対応ASPの受注作成APIが明細ごとに必須とする商品参照を欠いたまま
	 * pushされうる（Codex指摘, PR #41 #11）ため、`ORDER_LINE_PRODUCT_DELETED`
	 * （`indicates_export_blocking()`対象）を積む。
	 *
	 * 削除済みの検出は`get_post( $order_item->get_product_id() )`では**行えない**: 実測確認済みで、
	 * `WC_Order_Item_Product::set_product_id()`は`get_post_type() === 'product'`を検証し、参照先の
	 * 投稿が既に削除されていると`WC_Data_Exception`を投げる。データストアの`read()`が内部で呼ぶ
	 * `WC_Data::set_props()`はこの例外をプロパティ毎にcatchするため、`get_product_id()`自体が
	 * この時点で既定値`0`を返してしまい（`WC_Coupon::set_amount()`と同じ「set_props()がプロパティ毎に
	 * 例外を握りつぶす」パターン。CLAUDE.md参照）、削除済み商品への参照と「一度も商品リンクを
	 * 持たない正当なカスタム行」がCRUD層では区別できなくなる。一方、order-item-metaの生の値
	 * （`_product_id`）はこの検証を経ないため削除後も元のIDのまま残る（実測確認済み）。
	 * `get_metadata()`でWCのCRUD層を経由せず直接読み、区別する。
	 *
	 * @return array{0:?string,1:?string,2:?string,3:?string} [remote_product_id, 警告, option1_value, option2_value]
	 */
	private function remote_product_id( WC_Order_Item_Product $order_item ): array {
		$product_id = $order_item->get_product_id();

		if ( 0 === $product_id ) {
			$deleted_product_id = (int) get_metadata( 'order_item', $order_item->get_id(), '_product_id', true );

			if ( 0 === $deleted_product_id ) {
				// 商品リンクを一度も持たない正当なカスタム行（サービス料等）。解決対象が無いため警告も出さない。
				return [ null, null, null, null ];
			}

			return [ null, WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_DELETED, (string) $deleted_product_id ), null, null ];
		}

		$remote_id = $this->product_refs[ $product_id ]['remote_id'] ?? null;

		if ( null === $remote_id ) {
			return [ null, WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED, (string) $product_id ), null, null ];
		}

		$variation_id = $order_item->get_variation_id();

		if ( 0 === $variation_id ) {
			return [ $remote_id, null, null, null ];
		}

		[ $option1_value, $option2_value ] = $this->variation_option_values( $product_id, $variation_id );

		return [ $remote_id, null, $option1_value, $option2_value ];
	}

	/**
	 * バリエーション明細のoption1/2値を親商品の軸属性から導出する（`Woo\Reader\ProductReader`が
	 * `push_product()`用に組み立てるのと同じ値。`Woo\Support\VariationAxisResolver`で共有）。
	 * 親またはバリエーション自体が取得できない（削除済み等）場合は`null`のまま返す:
	 * この場合でも`remote_product_id`（親商品）自体は解決済みのため明細は残り、単に
	 * どのバリエーションかを識別する情報が欠けるだけに留める。
	 *
	 * @return array{0:?string,1:?string}
	 */
	private function variation_option_values( int $product_id, int $variation_id ): array {
		$parent = wc_get_product( $product_id );

		if ( ! $parent instanceof WC_Product_Variable ) {
			return [ null, null ];
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation instanceof WC_Product_Variation || null === get_post( $variation_id ) ) {
			return [ null, null ];
		}

		$axis_warnings   = [];
		$axis_attributes = VariationAxisResolver::axis_attributes( $parent, $axis_warnings );

		return VariationAxisResolver::option_values( $variation, $axis_attributes );
	}

	/**
	 * `remote_product_id()`と同じ理由: 購入者アカウントが削除済み（`get_userdata()`が`false`）の
	 * 場合は再エクスポートを待っても解決しない終端状態のため警告を積まない。
	 *
	 * @return array{0:?string,1:?string}
	 */
	private function customer_ref( WC_Order $order ): array {
		$customer_id = $order->get_customer_id();

		if ( 0 === $customer_id ) {
			return [ null, null ];
		}

		$remote_id = $this->customer_refs[ $customer_id ]['remote_id'] ?? null;

		if ( null !== $remote_id ) {
			return [ $remote_id, null ];
		}

		if ( false === get_userdata( $customer_id ) ) {
			return [ null, null ];
		}

		return [ null, WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_NOT_EXPORTED, (string) $customer_id ) ];
	}

	/**
	 * Wooネイティブの配送先住所キー＋送料＋Woo生の配送方法ID（クラスdocblock参照）＋
	 * `Woo\Writer\OrderWriter::apply_meta()`が書く`_cbjp_*`配送関連メタの読み戻し。
	 * 複数の配送明細を持つ注文（このプラグイン外で作成された注文等）は先頭の1件を代表として使い、
	 * 送料は全明細の合計にする。
	 *
	 * @return array<string,mixed>
	 */
	private function shipping( WC_Order $order ): array {
		$shipping_items = array_values(
			array_filter(
				$order->get_items( 'shipping' ),
				static fn ( $item ): bool => $item instanceof WC_Order_Item_Shipping
			)
		);

		$fee_total = 0.0;

		foreach ( $shipping_items as $shipping_item ) {
			$fee_total += $this->validated_amount( $shipping_item->get_total() );
		}

		/** @var ?WC_Order_Item_Shipping $primary */
		$primary     = $shipping_items[0] ?? null;
		$method_id   = null;
		$method_name = null;

		if ( null !== $primary ) {
			$bare_method_id = $primary->get_method_id();
			$instance_id    = $primary->get_instance_id();
			$method_id      = '' !== $instance_id && '0' !== $instance_id ? "{$bare_method_id}:{$instance_id}" : $bare_method_id;
			$method_name    = $primary->get_method_title();
		}

		$address = $order->get_address( 'shipping' );

		return array_merge(
			[
				'method_id'   => $method_id,
				'method_name' => $method_name,
				'fee'         => wc_format_decimal( $fee_total ),
				'name'        => $this->full_name( $address ),
				'tel'         => '' !== $order->get_shipping_phone() ? $order->get_shipping_phone() : null,
				'company'     => '' !== ( $address['company'] ?? '' ) ? $address['company'] : null,
				'address_1'   => '' !== ( $address['address_1'] ?? '' ) ? $address['address_1'] : null,
				'address_2'   => '' !== ( $address['address_2'] ?? '' ) ? $address['address_2'] : null,
				'city'        => '' !== ( $address['city'] ?? '' ) ? $address['city'] : null,
				'state'       => '' !== ( $address['state'] ?? '' ) ? $address['state'] : null,
				'postcode'    => '' !== ( $address['postcode'] ?? '' ) ? $address['postcode'] : null,
				'country'     => '' !== ( $address['country'] ?? '' ) ? $address['country'] : null,
			],
			$this->shipping_meta( $order )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function shipping_meta( WC_Order $order ): array {
		return [
			'slip_number'      => $this->meta_string( $order, '_cbjp_slip_number' ),
			'tracking_url'     => $this->meta_string( $order, '_cbjp_tracking_url' ),
			'preferred_date'   => $this->meta_string( $order, '_cbjp_preferred_date' ),
			'preferred_period' => $this->meta_string( $order, '_cbjp_preferred_period' ),
			'noshi_text'       => $this->meta_string( $order, '_cbjp_noshi_text' ),
			'card_name'        => $this->meta_string( $order, '_cbjp_card_name' ),
			'card_text'        => $this->meta_string( $order, '_cbjp_card_text' ),
			'wrapping_name'    => $this->meta_string( $order, '_cbjp_wrapping_name' ),
		];
	}

	/**
	 * `fee`はWooの決済手数料・ギフト包装料等（`WC_Order_Item_Fee`）を区別せず合算した値。
	 * `Woo\Writer\OrderItemBuilder::build_fee_items()`は決済手数料（`payment.fee`）とギフト
	 * 包装料（`totals.gift_charges`）を別名で個別のFee行に組み立てるが、Woo側の汎用Fee明細は
	 * 種別を安定に見分ける手段が無いため、合算して`payment.fee`側にのみ載せる（合計金額の
	 * 整合を優先する簡略化。再取込では1本のFee行に統合される）。
	 *
	 * @return array<string,mixed>
	 */
	private function payment( WC_Order $order ): array {
		return [
			'method_id'   => '' !== $order->get_payment_method() ? $order->get_payment_method() : null,
			'method_name' => '' !== $order->get_payment_method_title() ? $order->get_payment_method_title() : null,
			'fee'         => wc_format_decimal( $this->fee_total( $order ) ),
		];
	}

	private function fee_total( WC_Order $order ): float {
		$total = 0.0;

		foreach ( $order->get_items( 'fee' ) as $fee_item ) {
			if ( $fee_item instanceof WC_Order_Item_Fee ) {
				$total += $this->validated_amount( $fee_item->get_total() );
			}
		}

		return $total;
	}

	/**
	 * 送料明細・Fee明細の`get_total()`は`line_item_amounts()`/`totals()`と同じ理由
	 * （`set_total()`自身が符号を検証しない）で他プラグイン・直接のメタ編集により負値/非数値に
	 * なりうる。負の手数料・送料は実質的な値引きとして作用してしまうため、該当行だけを0円として
	 * 扱い（フェイルクローズ）、注文全体は止めない（R2レビュー指摘: `fee_total()`が
	 * `line_item_amounts()`/`totals()`と非対称に無検証だった）。
	 */
	private function validated_amount( mixed $raw ): float {
		if ( ! is_numeric( $raw ) || (float) $raw < 0.0 ) {
			return 0.0;
		}

		return (float) $raw;
	}

	/**
	 * `get_total_tax()`は`cart_tax`/`shipping_tax`の合算（`Woo\Writer\OrderWriter::apply_totals()`が
	 * 常に`shipping_tax='0'`で`totals.tax`全体を`cart_tax`へ寄せるため、この合算で元の値が
	 * そのまま復元できる）。
	 *
	 * `discount_total`/`shipping_total`/`total_tax`/`total`は`WC_Order`の型付きgetterだが、
	 * `set_*()`自身は`line_item_amounts()`と同じ理由で符号・数値妥当性を検証しない（postmeta
	 * 経由で他プラグイン・直接編集により壊れうる）。`Woo\Writer\OrderWriter::validate_totals()`
	 * （インポート方向）と同じ基準（数値・0以上）でフェイルクローズし、既存の
	 * `ORDER_TOTALS_INVALID`警告（書込方向と同じ意味。`indicates_export_blocking()`の対象＝
	 * 壊れた合計のまま注文をpushしない）を積む。
	 *
	 * @return array{0:array<string,mixed>,1:?string}
	 */
	private function totals( WC_Order $order ): array {
		$raw = [
			'discount'     => $order->get_discount_total(),
			'shipping_fee' => $order->get_shipping_total(),
			'tax'          => $order->get_total_tax(),
			'total'        => $order->get_total(),
		];

		foreach ( $raw as $key => $value ) {
			if ( ! is_numeric( $value ) || (float) $value < 0.0 ) {
				return [
					array_merge( array_fill_keys( array_keys( $raw ), '0' ), $this->totals_meta( $order ) ),
					WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, $key ),
				];
			}
		}

		return [ array_merge( $raw, $this->totals_meta( $order ) ), null ];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function totals_meta( WC_Order $order ): array {
		return [
			'discount_point' => $this->meta_string( $order, '_cbjp_discount_point' ),
			'discount_gmo'   => $this->meta_string( $order, '_cbjp_discount_gmo' ),
			'discount_other' => $this->meta_string( $order, '_cbjp_discount_other' ),
		];
	}

	/**
	 * ColorMe由来の注文は`Woo\Writer\OrderWriter::apply_addresses()`が`customer_snapshot`/
	 * `shipping`の元の氏名（「姓 名」の単一文字列）を`Woo\Support\AddressMapper::split_name()`で
	 * 分割し、最初のトークンを`last_name`（姓）、残りを`first_name`（名）としてWooへ保存する
	 * （`Woo\Reader\CustomerReader`と同じ規約）。`first_name . ' ' . last_name`（Western順）で
	 * 単純に組み直すと姓名が入れ替わって復元される（例:「山田 太郎」→「太郎 山田」）ため、
	 * `last_name . ' ' . first_name`で組み直す。注文の請求先/配送先氏名には`CustomerReader`の
	 * `_cbjp_full_name`に相当する「元の文字列そのもの」を保持するメタが無い
	 * （`OrderWriter::apply_addresses()`は`extras['customer_snapshot']['name']`を分割するのみで
	 * 別途保存しない）ため、v1.0で唯一対応するASP（ColorMe。日本のASPは氏名を「姓 名」順で
	 * 扱うのが標準）に合わせた既定の組み直し順とする。
	 *
	 * @param array<string,mixed> $address `WC_Order::get_address()`の戻り値。
	 */
	private function full_name( array $address ): string {
		return trim( (string) ( $address['last_name'] ?? '' ) . ' ' . (string) ( $address['first_name'] ?? '' ) );
	}

	private function meta_string( WC_Order $order, string $meta_key ): ?string {
		$value = $order->get_meta( $meta_key, true );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * `_cbjp_memo`（ColorMeインポート時に保存された備考。`Woo\Writer\OrderWriter`参照）を優先し、
	 * 無ければ`WC_Order::get_customer_note()`（Woo標準のチェックアウト備考欄。ColorMe経由でない
	 * ネイティブなWoo受注はこちらにしか備考が無い）へフォールバックする。`_cbjp_memo`のみを見ると、
	 * ColorMeを経由していない受注の顧客記入備考が常に失われる（Codex指摘, PR #41 #13）。
	 */
	private function note( WC_Order $order ): ?string {
		$memo = $this->meta_string( $order, '_cbjp_memo' );

		if ( null !== $memo ) {
			return $memo;
		}

		$customer_note = $order->get_customer_note();

		return '' !== $customer_note ? $customer_note : null;
	}
}
