<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\OrderReader;
use CartBridgeJP\Woo\WarningCode;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

final class OrderReaderTest extends WooTestCase {

	private const PLATFORM = 'colorme';

	/**
	 * テスト環境の既定ストア通貨はUSD。本テストの意図と無関係な`CURRENCY_MISMATCH`警告が
	 * 全テストに付いてしまうため、対応ASP（ColorMe）の前提通貨JPYに揃える
	 * （`Woo\Writer\OrderWriter::PLATFORM_CURRENCY`）。警告自体の検証は
	 * `test_non_jpy_order_currency_warns`が専用に行う。
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'woocommerce_currency', 'JPY' );
	}

	private function make_reader(): OrderReader {
		return new OrderReader( self::PLATFORM, $this->mappings );
	}

	private function create_product( string $name = 'Widget' ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '1000' );

		return $product->save();
	}

	private function add_line_item( WC_Order $order, int $product_id, int $quantity, string $total, string $total_tax = '0', ?int $variation_id = null ): void {
		$item = new WC_Order_Item_Product();
		$item->set_product_id( $product_id );

		if ( null !== $variation_id ) {
			$item->set_variation_id( $variation_id );
		}

		$item->set_name( 'Line item' );
		$item->set_quantity( $quantity );
		$item->set_subtotal( $total );
		$item->set_total( $total );
		$item->set_taxes(
			[
				'total'    => [ 0 => $total_tax ],
				'subtotal' => [ 0 => $total_tax ],
			]
		);
		$order->add_item( $item );
	}

	public function test_reads_line_item_amounts_payment_shipping_and_status(): void {
		$product_id = $this->create_product();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-100', $product_id );

		$order = wc_create_order( [ 'status' => 'pending' ] );
		$this->add_line_item( $order, $product_id, 2, '2000', '200' );

		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_instance_id( '5' );
		$shipping->set_total( '500' );
		$order->add_item( $shipping );

		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Bank transfer' );
		$order->set_status( 'processing' );
		$order->set_shipping_address(
			[
				'first_name' => 'Taro',
				'last_name'  => 'Yamada',
				'address_1'  => '1-2-3 Marunouchi',
				'city'       => 'Chiyoda',
				'state'      => 'JP13',
				'postcode'   => '100-0001',
				'country'    => 'JP',
			]
		);
		$order->set_shipping_phone( '0312345678' );
		$order->calculate_totals( false );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$this->assertCount( 1, $page->items );

		$item      = $page->items[0];
		$canonical = $item->item;

		$this->assertSame( $order->get_id(), $item->local_id );
		$this->assertSame( 'processing', $canonical->status );

		$line = $canonical->line_items[0];
		$this->assertSame( 'p-100', $line['remote_product_id'] );
		$this->assertSame( 2, $line['quantity'] );
		$this->assertSame( 1100.0, (float) $line['price'] );
		$this->assertSame( 2200.0, (float) $line['subtotal'] );
		$this->assertSame( 1000.0, (float) $line['unit_price_excl_tax'] );

		$this->assertSame( 'bacs', $canonical->payment['method_id'] );
		$this->assertSame( 'Bank transfer', $canonical->payment['method_name'] );

		$this->assertSame( 'flat_rate:5', $canonical->shipping['method_id'] );
		$this->assertSame( 'Flat rate', $canonical->shipping['method_name'] );
		$this->assertSame( 500.0, (float) $canonical->shipping['fee'] );
		// 姓名は「姓 名」（日本語順）で組み直される（レビュー指摘: ColorMe由来の分割規約と対称）。
		$this->assertSame( 'Yamada Taro', $canonical->shipping['name'] );
		$this->assertSame( '1-2-3 Marunouchi', $canonical->shipping['address_1'] );
		$this->assertSame( 'Chiyoda', $canonical->shipping['city'] );
		$this->assertSame( 'JP13', $canonical->shipping['state'] );
		$this->assertSame( '100-0001', $canonical->shipping['postcode'] );
		$this->assertSame( '0312345678', $canonical->shipping['tel'] );
	}

	public function test_customer_ref_resolves_via_mapping(): void {
		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$this->seed_mapping( self::PLATFORM, 'customer', 'cust-1', $customer_id );

		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$this->assertSame( 'cust-1', $page->items[0]->item->customer_ref );
	}

	public function test_customer_without_mapping_warns_and_marks_unresolved(): void {
		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );

		$order = wc_create_order();
		$order->set_customer_id( $customer_id );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertNull( $read_item->item->customer_ref );
		$this->assertFalse( $read_item->fully_resolved );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_NOT_EXPORTED, (string) $customer_id ), $read_item->warnings );
	}

	public function test_guest_order_has_null_customer_ref_without_warning(): void {
		$order = wc_create_order();
		$order->set_customer_id( 0 );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertNull( $read_item->item->customer_ref );
		$this->assertTrue( $read_item->fully_resolved );
	}

	public function test_line_item_without_product_mapping_has_null_remote_product_id_and_warns(): void {
		$product_id = $this->create_product();

		$order = wc_create_order();
		$this->add_line_item( $order, $product_id, 1, '1000' );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertNull( $read_item->item->line_items[0]['remote_product_id'] );
		$this->assertFalse( $read_item->fully_resolved );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED, (string) $product_id ), $read_item->warnings );
	}

	/**
	 * `_line_total`/`_line_tax`は`WC_Order_Item_Product::set_total()`/`set_taxes()`自身が符号を
	 * 検証しないため、他プラグイン・直接のメタ編集で負値になりうる（`ProductReader`の
	 * `_regular_price`と同じ壊れ方の構造）。無警告でASP側へ転記すると実質的な値引きとして
	 * 扱われうるため0円へフェイルクローズする。
	 */
	public function test_negative_line_item_total_is_treated_as_invalid(): void {
		$product_id = $this->create_product();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-neg', $product_id );

		$order = wc_create_order();
		$this->add_line_item( $order, $product_id, 1, '-500' );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];
		$line      = $read_item->item->line_items[0];

		// 金額のフォーマットは常に小数2桁になる。
		$this->assertSame( '0.00', $line['price'] );
		$this->assertSame( '0.00', $line['subtotal'] );
		$this->assertSame( '0.00', $line['unit_price_excl_tax'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_AMOUNT_INVALID, 'p-neg' ), $read_item->warnings );
	}

	/**
	 * 対応ASP（ColorMe）の金額は全てJPY前提（`Woo\Writer\OrderWriter::PLATFORM_CURRENCY`）。
	 * 店舗通貨が異なる注文をそのままexportすると、E2-3の`push_order()`が誤って外貨額をJPYとして
	 * 送信しうるため警告する（レビュー指摘）。
	 */
	public function test_non_jpy_order_currency_warns(): void {
		$order = wc_create_order();
		$order->set_currency( 'USD' );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertContains( WarningCode::with_detail( WarningCode::CURRENCY_MISMATCH, 'USD' ), $read_item->warnings );
		$this->assertSame( 'USD', $read_item->item->extras['currency'] );
		// JPY以外の金額をそのままpushすると、ASP側がJPYとして誤って解釈しうる（例: USD 100が
		// JPY 100として送信される）ため、importと異なりexportではblockingへ倒す（レビュー指摘）。
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * `CanonicalOrder::$line_items[].tax_reduced`はbool（標準/軽減税率の2値）のみで、
	 * `zero-rate`等その他の税区分・非課税/送料のみ課税を表現できない（レビュー指摘）。
	 */
	public function test_line_item_with_unsupported_tax_class_warns(): void {
		$product_id = $this->create_product();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-tax', $product_id );

		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product_id( $product_id );
		$item->set_name( 'Zero rate item' );
		$item->set_quantity( 1 );
		$item->set_subtotal( '1000' );
		$item->set_total( '1000' );
		$item->set_tax_class( 'zero-rate' );
		$order->add_item( $item );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertFalse( $read_item->item->line_items[0]['tax_reduced'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_TAX_CLASS_UNSUPPORTED, 'p-tax' ), $read_item->warnings );
	}

	/**
	 * 数量が0以下（破損メタ等）の明細をそのままexportすると、実際の購入数と食い違う
	 * 出荷指示になりうる。`Woo\Writer\OrderItemBuilder`（インポート方向）と同じ基準で
	 * 1個へフェイルクローズし警告する（レビュー指摘）。
	 */
	public function test_zero_quantity_line_item_is_normalized_to_one_with_warning(): void {
		$product_id = $this->create_product();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-qty', $product_id );

		$order = wc_create_order();
		$this->add_line_item( $order, $product_id, 0, '1000' );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertSame( 1, $read_item->item->line_items[0]['quantity'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, 'p-qty' ), $read_item->warnings );
	}

	/**
	 * `WC_Order_Item_Product::get_quantity()`は内部で`wc_stock_amount()`（`woocommerce_stock_amount`
	 * フィルター経由で量り売り等の小数量拡張が介入しうる。docblockが`int|float`を宣言）を通すため
	 * `int`型を保証しない。`declare(strict_types=1)`下で非整数値をそのまま`divide_minor_units_
	 * rounded(int $minor, int $divisor)`へ渡すと`TypeError`でページ全体の処理を落とす
	 * （Codex指摘, PR #41 #10）。整数へ丸めたうえで警告することを確認する。
	 */
	public function test_fractional_quantity_line_item_is_rounded_with_warning(): void {
		$product_id = $this->create_product();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-frac', $product_id );

		// WC本体（`wc-core-functions.php`）は既定で`add_filter('woocommerce_stock_amount',
		// 'intval')`を登録し、数量読み書きの両方（`set_quantity()`だけでなく、データストアの
		// `read()`が呼ぶ`set_props()`経由の再読込時も）で常にintへ丸める。量り売り等の小数量拡張は
		// この既定フィルターを外して独自のフィルターに差し替えるため、ここでも作成〜
		// `OrderReader::query()`（DBからの再読込を含む）の間ずっと外して再現する
		// （実測確認済み: 保存前だけ外して保存直後に戻すと、再読込時の`set_props()`が
		// 標準フィルターで丸め直してしまい小数量を再現できない）。
		remove_filter( 'woocommerce_stock_amount', 'intval' );

		try {
			$order = wc_create_order();
			$item  = new WC_Order_Item_Product();
			$item->set_product_id( $product_id );
			$item->set_name( 'Bulk item' );
			$item->set_quantity( 1.6 );
			$item->set_subtotal( '1000' );
			$item->set_total( '1000' );
			$item->set_taxes(
				[
					'total'    => [ 0 => '0' ],
					'subtotal' => [ 0 => '0' ],
				]
			);
			$order->add_item( $item );
			$order->save();

			$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
			$read_item = $page->items[0];
		} finally {
			add_filter( 'woocommerce_stock_amount', 'intval' );
		}

		$this->assertSame( 2, $read_item->item->line_items[0]['quantity'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, 'p-frac' ), $read_item->warnings );
	}

	/**
	 * 一部/全額返金済みの受注は`get_total()`等が返金前の金額のまま変わらない（返金額は
	 * `WC_Order_Refund`という別オブジェクトに記録される）。`CanonicalOrder`は返金額を運ぶ
	 * フィールドを持たないため、無警告でpushすると実際には回収していない金額を全額回収済みとして
	 * ASP側に作成してしまう（Codex指摘, PR #41 #12: 返金の有無を確認していなかった）。
	 */
	public function test_refunded_order_blocks_export(): void {
		$product_id = $this->create_product();

		$order = wc_create_order();
		$this->add_line_item( $order, $product_id, 1, '1000' );
		$order->calculate_totals( false );
		$order->save();

		wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => '400',
				'reason'   => 'Smoke test refund',
			]
		);

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertContains( WarningCode::ORDER_REFUNDED, $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * `discount_total`/`shipping_total`/`total_tax`/`total`も`WC_Order`の型付きgetterだが
	 * `set_*()`自身は符号を検証しない。壊れた注文合計をそのまま転記すると実際の決済額と
	 * 食い違う金銭的リスクがあるため、`OrderWriter::validate_totals()`（インポート方向）と
	 * 同じ基準でフェイルクローズする。
	 */
	public function test_negative_order_total_is_treated_as_invalid(): void {
		$order = wc_create_order();
		$order->set_total( '-1000' );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertSame( '0', $read_item->item->totals['total'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, 'total' ), $read_item->warnings );
	}

	/**
	 * 明細金額は`get_subtotal()`/`get_subtotal_tax()`（割引前）を使う必要がある。
	 * `get_total()`/`get_total_tax()`（Wooのクーポン計算後＝割引後）を使うと、`totals.discount`
	 * （`get_discount_total()`）で別途控除される割引が明細側にも織り込み済みになり、二重に
	 * 割引が効いてしまう（実装計画・レビュー指摘）。
	 */
	public function test_line_item_amount_uses_subtotal_not_discounted_total(): void {
		$product_id = $this->create_product();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-disc', $product_id );

		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_product_id( $product_id );
		$item->set_name( 'Discounted line' );
		$item->set_quantity( 1 );
		// 割引前の金額をsubtotalへ、200円引き後の金額をtotalへ設定する。税は割引後の金額のみに掛かる。
		$item->set_subtotal( '2000' );
		$item->set_total( '1800' );
		$item->set_taxes(
			[
				'total'    => [ 0 => '180' ],
				'subtotal' => [ 0 => '200' ],
			]
		);
		$order->add_item( $item );
		$order->set_discount_total( '200' );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$line = $page->items[0]->item->line_items[0];

		// 割引前の税込金額を明細に載せる（割引後の金額ではない）。
		$this->assertSame( 2200.0, (float) $line['subtotal'] );
		$this->assertSame( 2200.0, (float) $line['price'] );
		$this->assertSame( 2000.0, (float) $line['unit_price_excl_tax'] );
	}

	/**
	 * `extras['customer_snapshot']`/`extras['paid']`はE2-3の`push_order()`が請求先情報・
	 * 支払済み状態をASP側リクエストへ載せるための情報源。空の`extras`のままだと
	 * push_order()に購入者情報が一切渡らなくなる（レビュー指摘）。
	 */
	public function test_billing_address_and_paid_status_are_exported_as_extras(): void {
		$order = wc_create_order();
		$order->set_billing_first_name( 'Taro' );
		$order->set_billing_last_name( 'Yamada' );
		$order->set_billing_email( 'taro@example.com' );
		$order->set_billing_phone( '0312345678' );
		$order->set_billing_address_1( '1-2-3 Marunouchi' );
		$order->set_billing_city( 'Chiyoda' );
		$order->set_billing_state( 'JP13' );
		$order->set_billing_postcode( '100-0001' );
		$order->set_billing_country( 'JP' );
		$order->set_date_paid( time() );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$canonical = $page->items[0]->item;

		$snapshot = $canonical->extras['customer_snapshot'];
		// 姓名は「姓 名」（日本語順）で組み直される（レビュー指摘）。
		$this->assertSame( 'Yamada Taro', $snapshot['name'] );
		$this->assertSame( 'taro@example.com', $snapshot['email'] );
		$this->assertSame( '0312345678', $snapshot['phone'] );
		$this->assertSame( '1-2-3 Marunouchi', $snapshot['address_1'] );
		$this->assertSame( 'Chiyoda', $snapshot['city'] );
		$this->assertTrue( $canonical->extras['paid'] );
	}

	public function test_unpaid_order_exports_paid_false(): void {
		$order = wc_create_order();
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$canonical = $page->items[0]->item;

		$this->assertFalse( $canonical->extras['paid'] );
	}

	/**
	 * `WC_Order_Item_Fee`（決済手数料・ギフト包装料等）は`$order->get_items()`（既定=line_item）に
	 * 含まれないが、`totals.total`（`get_total()`）には合算済みで反映される。無視すると
	 * 明細合計と注文合計が食い違う（レビュー指摘）。
	 */
	public function test_fee_line_items_are_aggregated_into_payment_fee(): void {
		$order = wc_create_order();
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Payment fee' );
		$fee->set_amount( '150' );
		$fee->set_total( '150' );
		$order->add_item( $fee );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$this->assertSame( 150.0, (float) $page->items[0]->item->payment['fee'] );
	}

	/**
	 * `WC_Order_Item_Fee::set_total()`自身は符号を検証しないため、負の手数料が実質的な
	 * 値引きとして作用しうる（`line_item_amounts()`/`totals()`と同じ理由でフェイルクローズ）。
	 */
	public function test_negative_fee_line_item_does_not_reduce_the_payment_fee_total(): void {
		$order = wc_create_order();
		$fee   = new WC_Order_Item_Fee();
		$fee->set_name( 'Bad fee' );
		$fee->set_amount( '-50' );
		$fee->set_total( '-50' );
		$order->add_item( $fee );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$this->assertSame( 0.0, (float) $page->items[0]->item->payment['fee'] );
	}

	/**
	 * `Woo\Writer\OrderWriter::validate_totals()`（インポート方向）は不正な合計を持つ注文全体を
	 * 保存せずskipする。exportも対称に、壊れた合計を実際の明細と一緒に「¥0の注文」として
	 * pushしないよう`indicates_export_blocking()`の対象にする（レビュー指摘）。
	 */
	public function test_invalid_order_totals_block_export(): void {
		$order = wc_create_order();
		$order->set_total( '-1000' );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	public function test_note_prefers_cbjp_memo_over_customer_note(): void {
		$order = wc_create_order();
		$order->set_customer_note( 'Native checkout note' );
		$order->update_meta_data( '_cbjp_memo', 'ColorMe備考' );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$this->assertSame( 'ColorMe備考', $page->items[0]->item->note );
	}

	/**
	 * `_cbjp_memo`（ColorMeインポート時のみ保存される）が無いネイティブなWoo受注では、標準の
	 * チェックアウト備考欄（`get_customer_note()`）へフォールバックする。これが無いと、ColorMeを
	 * 経由しない受注の顧客記入備考が常に失われる（Codex指摘, PR #41 #13）。
	 */
	public function test_note_falls_back_to_customer_note_when_no_cbjp_memo(): void {
		$order = wc_create_order();
		$order->set_customer_note( 'Native checkout note' );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$this->assertSame( 'Native checkout note', $page->items[0]->item->note );
	}

	/**
	 * 参照先の商品が削除済み（`get_post()`が投稿を見つけられない）の場合、再エクスポートを待っても
	 * `remote_product_id`は恒久的に解決しない終端状態のため`ORDER_LINE_PRODUCT_NOT_EXPORTED`
	 * （再試行可能）は積まない（`indicates_unresolved_reference()`対象外）が、対応ASPの受注作成APIが
	 * 明細ごとに商品参照を必須とするため、参照を持たない正当なカスタム行と区別して
	 * `ORDER_LINE_PRODUCT_DELETED`でpush自体をblockする（Codex指摘, PR #41 #11: 当初は無警告・
	 * fully_resolved=trueのまま黙って商品参照が失われていた）。
	 */
	public function test_line_item_referencing_deleted_product_blocks_export(): void {
		$product_id = $this->create_product();

		$order = wc_create_order();
		$this->add_line_item( $order, $product_id, 1, '1000' );
		$order->save();

		wp_delete_post( $product_id, true );

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertNull( $read_item->item->line_items[0]['remote_product_id'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_DELETED, (string) $product_id ), $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
		$this->assertFalse( WarningCode::indicates_unresolved_reference( $read_item->warnings ) );
	}

	public function test_order_with_no_line_items_reads_successfully(): void {
		$order = wc_create_order();
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertSame( [], $read_item->item->line_items );
		$this->assertSame( [], $read_item->warnings );
	}

	/**
	 * `WC_Order_Item_Product`が一度も商品リンクを持たない（`product_id`が既定値`0`のまま）場合、
	 * 対応ASPの受注作成APIが明細ごとに必須とする商品参照を欠いたままpushされうるため
	 * `ORDER_LINE_PRODUCT_MISSING`でexport blockingにする（Copilot指摘, PR #41 G2: 当初は
	 * 「正当なカスタム行」として無警告で扱っていた）。
	 */
	public function test_line_item_without_any_product_link_blocks_export(): void {
		$order = wc_create_order();
		$item  = new WC_Order_Item_Product();
		$item->set_name( 'Custom line without product link' );
		$item->set_quantity( 1 );
		$item->set_subtotal( '1000' );
		$item->set_total( '1000' );
		$item->set_taxes(
			[
				'total'    => [ 0 => '0' ],
				'subtotal' => [ 0 => '0' ],
			]
		);
		$order->add_item( $item );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertNull( $read_item->item->line_items[0]['remote_product_id'] );
		$this->assertContains( WarningCode::ORDER_LINE_PRODUCT_MISSING, $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * 親商品は解決できてもバリエーション自体が削除済みだと、option1/2値でどのバリエーションかを
	 * 特定できない。親商品だけを指す不明瞭な明細を無警告でpushしない（Copilot指摘, PR #41 G2）。
	 */
	public function test_variation_line_item_with_deleted_variation_blocks_export(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '1000' );
		$variation_id = $variation->save();

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );

		$order = wc_create_order();
		$this->add_line_item( $order, $parent_id, 1, '1000', '0', $variation_id );
		$order->save();

		wp_delete_post( $variation_id, true );

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNRESOLVED, (string) $variation_id ), $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * 親商品の軸が3つ以上ある場合、`VariationAxisResolver::axis_attributes()`は3軸目以降を
	 * 切り捨てる（`ProductReader`と共有するロジック）。受注明細でoption1/2だけを頼りに
	 * バリエーションを識別すると、3軸目の値が異なる複数のバリエーション同士を区別できず
	 * 誤った商品を受注として記録しうるため、export blockingにする（Copilot指摘, PR #41 G2）。
	 */
	public function test_variation_line_item_with_three_axes_blocks_export(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );

		$attributes = [];
		foreach ( [ 'Size', 'Color', 'Material' ] as $index => $name ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( [ 'A' ] );
			$attribute->set_position( $index );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$parent->set_attributes( $attributes );
		$parent_id = $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes(
			[
				'size'     => 'A',
				'color'    => 'A',
				'material' => 'A',
			]
		);
		$variation->set_regular_price( '1000' );
		$variation_id = $variation->save();

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent-3axis', $parent_id );

		$order = wc_create_order();
		$this->add_line_item( $order, $parent_id, 1, '1000', '0', $variation_id );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNRESOLVED, (string) $variation_id ), $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * ColorMeの`POST /v1/sales`は`details[].product_id`に**親商品**のremote_idを要求し、
	 * バリエーションは`option1_value_current`で識別する契約（Codex指摘 #6）。`variant`mapping
	 * （`v-child`）ではなく`product`mapping（`p-parent`）が使われることを確認する。
	 */
	public function test_variation_line_item_resolves_parent_product_id_and_option_values(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '1000' );
		$variation_id = $variation->save();

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-child', $variation_id );

		$order = wc_create_order();
		$this->add_line_item( $order, $parent_id, 1, '1000', '0', $variation_id );
		$order->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$line = $page->items[0]->item->line_items[0];

		$this->assertSame( 'p-parent', $line['remote_product_id'] );
		$this->assertSame( 'S', $line['option1_value_current'] );
		$this->assertNull( $line['option2_value_current'] );
	}

	/**
	 * 親商品が未エクスポート（`product`mappingが無い）場合、`variant`mappingが存在していても
	 * 明細は未解決＝`ORDER_LINE_PRODUCT_NOT_EXPORTED`扱いになる（親IDでの解決に一本化したため）。
	 */
	public function test_variation_line_item_with_only_variant_mapping_is_unresolved(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( [ 'S' ] );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->set_regular_price( '1000' );
		$variation_id = $variation->save();

		$this->seed_mapping( self::PLATFORM, 'variant', 'v-child', $variation_id );

		$order = wc_create_order();
		$this->add_line_item( $order, $parent_id, 1, '1000', '0', $variation_id );
		$order->save();

		$page      = $this->make_reader()->query( Cursor::start(), [ $order->get_id() ] );
		$read_item = $page->items[0];

		$this->assertNull( $read_item->item->line_items[0]['remote_product_id'] );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_NOT_EXPORTED, (string) $parent_id ), $read_item->warnings );
	}

	public function test_checkout_draft_orders_are_excluded_from_cursor_walk(): void {
		$draft = wc_create_order( [ 'status' => 'checkout-draft' ] );
		$draft->save();

		$page = $this->make_reader()->query( Cursor::start(), null );
		$ids  = array_map( static fn ( $item ) => $item->local_id, $page->items );

		$this->assertNotContains( $draft->get_id(), $ids );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}

	public function test_query_walks_multiple_pages(): void {
		$ids = [];

		for ( $i = 0; $i < 22; $i++ ) {
			$order = wc_create_order();
			$order->save();
			$ids[] = $order->get_id();
		}

		$reader     = $this->make_reader();
		$first_page = $reader->query( Cursor::start(), null );

		$this->assertCount( 20, $first_page->items );
		$this->assertNotNull( $first_page->next_cursor );

		$second_page = $reader->query( $first_page->next_cursor, null );

		$this->assertCount( 2, $second_page->items );
		$this->assertNull( $second_page->next_cursor );

		$all_ids = array_merge(
			array_map( static fn ( $item ) => $item->local_id, $first_page->items ),
			array_map( static fn ( $item ) => $item->local_id, $second_page->items )
		);
		sort( $all_ids );
		sort( $ids );
		$this->assertSame( $ids, $all_ids );
	}
}
