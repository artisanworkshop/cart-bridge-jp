<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\OrderItemBuilder;
use CartBridgeJP\Woo\Writer\OrderWriter;
use WC_Product_Simple;
use WC_Product_Variable;

final class OrderWriterTest extends WooTestCase {

	private function make_writer(): OrderWriter {
		$resolver = new ProductResolver( 'colorme', $this->mappings );

		return new OrderWriter( 'colorme', $this->mappings, new OrderItemBuilder( $resolver ), new MethodMap( 'colorme' ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $line_items
	 * @param array<string,mixed>            $shipping
	 * @param array<string,mixed>            $payment
	 * @param array<string,mixed>            $totals
	 * @param array<string,mixed>            $extras
	 */
	private function make_order(
		string $number = '1',
		string $status = 'processing',
		?string $customer_ref = null,
		array $line_items = [],
		array $shipping = [],
		array $payment = [],
		array $totals = [
			'total'        => '1000',
			'tax'          => '0',
			'shipping_fee' => '0',
			'discount'     => '0',
		],
		array $extras = []
	): CanonicalOrder {
		return new CanonicalOrder( $number, $status, $customer_ref, $line_items, $shipping, $payment, $totals, '2026-01-01T00:00:00+00:00', null, $extras );
	}

	public function test_resolves_line_item_by_sku(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product->set_sku( 'WIDGET-1' );
		// SKUフォールバックのownershipガード（`PlatformOwnership`）を通すために必要
		// （`VariationWriter`/`ProductWriter`が通常のsync時に付与するメタを模擬している）。
		$product->update_meta_data( '_cbjp_platform', 'colorme' );
		$product_id = $product->save();

		$order = $this->make_order(
			'1001',
			'processing',
			null,
			[
				[
					'sku'                 => 'WIDGET-1',
					'remote_product_id'   => 'p1',
					'name'                => 'Widget (at purchase)',
					'price'               => '1100',
					'unit_price_excl_tax' => '1000',
					'subtotal'            => '1100',
					'quantity'            => 1,
				],
			]
		);

		$result = $this->make_writer()->write( $order, null );
		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );

		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );
		$this->assertCount( 1, $items );
		$this->assertSame( $product_id, $items[0]->get_product_id() );
		// 注文時点の商品名（現在の商品名ではなく）が使われる。
		$this->assertSame( 'Widget (at purchase)', $items[0]->get_name() );
		$this->assertSame( '1000', $items[0]->get_total() );
		$this->assertSame( '100', $items[0]->get_total_tax() );
	}

	public function test_line_item_sku_match_on_foreign_platform_product_is_treated_as_unresolved(): void {
		// `ProductResolver::resolve_stock_target()`のSKUフォールバックと同じownershipガード
		// （`PlatformOwnership`）を`resolve_by_sku_or_remote_id()`にも適用済み。偶然SKUが
		// 一致しただけの別プラットフォーム由来（または店舗手動作成）の商品に、注文明細を
		// 誤って紐付けてはならない（誤った商品・価格・統計が注文に付いてしまう）。
		$foreign = new WC_Product_Simple();
		$foreign->set_name( 'Foreign' );
		$foreign->set_sku( 'SHARED-SKU' );
		$foreign->update_meta_data( '_cbjp_platform', 'makeshop' );
		$foreign->save();

		$order = $this->make_order(
			'1017',
			'processing',
			null,
			[
				[
					'sku'                 => 'SHARED-SKU',
					'remote_product_id'   => 'p-unmapped',
					'name'                => 'Widget (at purchase)',
					'price'               => '1100',
					'unit_price_excl_tax' => '1000',
					'subtotal'            => '1100',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'p-unmapped' ), $result->warnings );
	}

	public function test_resolves_line_item_by_mapping_when_sku_missing(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', 'p1', $product_id );

		$order = $this->make_order(
			'1002',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'p1',
					'name'                => 'Widget',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( $product_id, $items[0]->get_product_id() );
	}

	public function test_unresolved_line_item_creates_custom_row_with_meta(): void {
		$order = $this->make_order(
			'1003',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'gone',
					'name'                => 'Deleted product',
					'price'               => '500',
					'unit_price_excl_tax' => '500',
					'subtotal'            => '500',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		$this->assertSame( 'gone', $items[0]->get_meta( '_cbjp_remote_product_id' ) );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'gone' ), $result->warnings );
	}

	public function test_line_item_resolving_to_variable_parent_is_treated_as_unresolved(): void {
		// ColorMeの受注明細は`remote_product_id`として常に親商品のIDしか持たない
		// （どのvariationかはoption1/2の値でしか特定できない）。SKU解決に失敗した場合、
		// remote_idフォールバックが親のvariable商品そのものに解決してしまうと、
		// variable商品は単体では購入対象にならないため不整合な注文行になる。
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable parent' );
		$parent->set_status( 'publish' );
		$parent_id = $parent->save();
		$this->seed_mapping( 'colorme', 'product', 'vp1', $parent_id );

		$order = $this->make_order(
			'3005',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'vp1',
					'name'                => 'Some variant',
					'price'               => '500',
					'unit_price_excl_tax' => '500',
					'subtotal'            => '500',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'vp1' ), $result->warnings );
	}

	public function test_missing_line_item_quantity_falls_back_to_one_with_warning(): void {
		// 数量欠損を1個として黙って捏造すると実際の購入数と食い違う出荷指示になりうる
		// （CLAUDE.md参照）。行自体は注文履歴のため残しつつ、数量が不確かである旨を
		// 警告として可視化することを確認する。
		$order = $this->make_order(
			'3009',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'no-qty',
					'name'                => 'Mystery quantity',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => null,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 1, (int) $items[0]->get_quantity() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, 'no-qty' ), $result->warnings );
	}

	public function test_non_numeric_line_item_price_with_no_subtotal_fails_closed(): void {
		// `subtotal`欠損時のフォールバック計算に使う`price`自体を検証しないと、桁区切り付き
		// 文字列（`"1,200"`）が`(float)`キャストで`1.0`へ静かに切り詰められ、誤った金額
		// （数量2なら合計¥2）が無警告で確定してしまっていた。
		$order = $this->make_order(
			'3020',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'bad-price',
					'name'                => 'Malformed price',
					'price'               => '1,200',
					'unit_price_excl_tax' => null,
					'subtotal'            => null,
					'quantity'            => 2,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( '0', $items[0]->get_total() );
		// detailは金額の値自体ではなくremote_product_id（同メソッド内の他の警告・
		// F1-6の結果レポートがどの明細か特定できるようにする契約と揃える）。
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_AMOUNT_INVALID, 'bad-price' ), $result->warnings );
	}

	public function test_zero_or_negative_line_item_quantity_falls_back_to_one_with_warning(): void {
		// 0以下の数量をそのまま`set_quantity()`に渡すと負/ゼロ数量の明細行になり、
		// 注文の集計・返金計算が破綻しうるため、欠損時と同じフェイルクローズ扱いにする。
		$order = $this->make_order(
			'3014',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'negative-qty',
					'name'                => 'Negative quantity',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => -1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 1, (int) $items[0]->get_quantity() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, 'negative-qty' ), $result->warnings );
	}

	public function test_reduced_rate_tax_class_not_configured_falls_back_to_standard(): void {
		// ProductWriterと同じ理由: 未設定のtax_classをそのまま`set_tax_class()`に渡すと
		// `WC_Order_Item_Product`は`WC_Data_Exception`を投げる。WooCommerceは標準で
		// 'reduced-rate'を用意しているため、未設定ストアを模擬するため明示的に削除する。
		\WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		$order = $this->make_order(
			'3010',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'reduced-item',
					'name'                => 'Reduced rate item',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => 1,
					'tax_reduced'         => true,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( '', $items[0]->get_tax_class() );
		$this->assertContains( WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, 'reduced-rate' ), $result->warnings );
	}

	public function test_inconsistent_tax_split_clamps_to_zero_with_warning(): void {
		// `subtotal`（税込線合計の情報源）と`unit_price_excl_tax`×数量は別々のASPフィールドから
		// 独立に導出されるため、行割引・端数処理の都合で整合しないことがある。税抜側が
		// 税込側を上回ると減算結果が負になり、そのまま`set_taxes()`へ書き込むと注文の税合計が
		// 破綻するため、税額0へフェイルクローズし警告で可視化することを確認する。
		$order = $this->make_order(
			'3012',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'bad-split',
					'name'                => 'Inconsistent split',
					'price'               => '100',
					'unit_price_excl_tax' => '150',
					'subtotal'            => '100',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( '0', $items[0]->get_total_tax() );
		$this->assertContains( WarningCode::ORDER_LINE_TAX_INCONSISTENT, $result->warnings );
	}

	public function test_customer_ref_pointing_to_deleted_user_is_treated_as_unresolved(): void {
		$user_id = wp_insert_user(
			[
				'user_login' => 'cust2',
				'user_email' => 'cust2@example.com',
				'user_pass'  => 'x',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', 'c-gone', $user_id );
		wp_delete_user( $user_id );

		$order    = $this->make_order( '3011', 'processing', 'c-gone' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 0, $wc_order->get_customer_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, 'c-gone' ), $result->warnings );
	}

	public function test_discount_point_meta_is_deleted_when_no_longer_present(): void {
		// ポイント利用等が取り消されてtotalsから値が消えた場合、更新のみで削除しないと
		// 古い金額のメタが残り、実際の割引内容と食い違ったまま残り続けてしまう。
		$with_point = $this->make_order(
			'3013',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'          => '1000',
				'tax'            => '0',
				'shipping_fee'   => '0',
				'discount'       => '0',
				'discount_point' => '500',
			]
		);
		$first      = $this->make_writer()->write( $with_point, null );
		$this->assertSame( '500', wc_get_order( $first->local_id )->get_meta( '_cbjp_discount_point' ) );

		$without_point = $this->make_order( '3013', 'processing' );
		$this->make_writer()->write( $without_point, $first->local_id );

		$this->assertSame( '', wc_get_order( $first->local_id )->get_meta( '_cbjp_discount_point' ) );
	}

	public function test_stale_existing_local_id_falls_back_to_create(): void {
		// mappingsが指す注文が手動削除等で既に存在しない場合を模擬する
		// （実在しない注文IDを直接existing_local_idとして渡す）。
		$order  = $this->make_order( '3006', 'processing' );
		$result = $this->make_writer()->write( $order, 999999 );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( 999999, $result->local_id );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $result->local_id ) );
	}

	public function test_unknown_status_falls_back_to_on_hold_with_warning(): void {
		$order    = $this->make_order( '3007', 'some-unknown-status' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 'on-hold', $wc_order->get_status() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_STATUS_UNKNOWN, 'some-unknown-status' ), $result->warnings );
	}

	public function test_tax_total_incomplete_source_warns(): void {
		$order = $this->make_order(
			'3008',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '80',
				'shipping_fee' => '0',
				'discount'     => '0',
				'tax_source'   => 'sale.tax_incomplete_excludes_shipping_tax',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::ORDER_TAX_TOTAL_INCOMPLETE, $result->warnings );
	}

	public function test_totals_are_set_from_asp_values_without_recalculation(): void {
		$order = $this->make_order(
			'1004',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '9999',
				'tax'          => '111',
				'shipping_fee' => '300',
				'discount'     => '50',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( '9999.00', $wc_order->get_total() );
		$this->assertSame( '300', $wc_order->get_shipping_total() );
		$this->assertSame( '50', $wc_order->get_discount_total() );
		$this->assertSame( '111', $wc_order->get_total_tax() );
	}

	public function test_negative_total_is_rejected_before_creating_the_order(): void {
		// `合計はASP側の値をそのまま設定`する契約上、合計自体が壊れていると実際に決済
		// された金額と一致しない注文になる金銭的リスクがある。`WC_Order`に一切触れず
		// 注文全体を見送ることを確認する（受注そのものが作られない）。
		$order = $this->make_order(
			'1015',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '-100',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, 'total' ), $result->warnings );
		$this->assertCount( 0, wc_get_orders( [ 'limit' => -1 ] ) );
	}

	public function test_missing_total_key_is_rejected_before_creating_the_order(): void {
		// `total`キー自体が欠損している場合、`apply_totals()`は`Value::string(...) ?? '0'`で
		// 無警告のまま0円にフォールバックしてしまう。他の3キー（discount/shipping_fee/tax）は
		// 正当に欠損しうるが`total`だけは必須であることを確認する。
		$order = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[],
			[],
			[
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, 'total' ), $result->warnings );
		$this->assertCount( 0, wc_get_orders( [ 'limit' => -1 ] ) );
	}

	public function test_date_paid_is_cleared_when_paid_flag_reverts_to_false(): void {
		// 更新のみで削除しないと、再実行時に返金・注文取消等でASP側のpaidフラグが
		// falseへ戻っても古いdate_paidが残り続け、WooCommerce側の会計・エクスポートで
		// 支払済みのまま扱われてしまう。
		$paid  = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => true ]
		);
		$first = $this->make_writer()->write( $paid, null );
		$this->assertNotNull( wc_get_order( $first->local_id )->get_date_paid() );

		$unpaid = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => false ]
		);
		$this->make_writer()->write( $unpaid, $first->local_id );

		$this->assertNull( wc_get_order( $first->local_id )->get_date_paid() );
		// `paid`は`date_paid`として既に反映済みのため、汎用extras passthrough
		// （`ExtrasMeta::apply()`）には渡らない。渡ると`false`が空文字列として書き込まれ、
		// 未設定と区別できなくなる。
		$this->assertSame( '', wc_get_order( $first->local_id )->get_meta( '_cbjp_paid' ) );
	}

	public function test_date_paid_is_preserved_when_paid_flag_is_absent(): void {
		// `paid`キーが欠損/null（未対応ASP、または値を解釈できなかった場合）は
		// 「未払いに変わった」という明示的なシグナルではないため、falseと同一視して
		// 既存のdate_paidを消してはいけない。
		$paid  = $this->make_order(
			'1017',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => true ]
		);
		$first = $this->make_writer()->write( $paid, null );
		$this->assertNotNull( wc_get_order( $first->local_id )->get_date_paid() );

		$resynced = $this->make_order(
			'1017',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[]
		);
		$this->make_writer()->write( $resynced, $first->local_id );

		$this->assertNotNull( wc_get_order( $first->local_id )->get_date_paid() );
	}

	public function test_new_completed_order_with_ambiguous_paid_flag_does_not_get_migration_run_time_as_paid_date(): void {
		// `apply_status()`（直前に呼ばれる）の`WC_Order::set_status()`は、pending→completedの
		// ステータス遷移時に`maybe_set_date_paid()`/`maybe_set_date_completed()`を発火させ、
		// `date_paid`へ移行実行時刻（`time()`）を自動的に焼き込む（WooCommerce本体の仕様）。
		// `paid`フラグが欠損/nullの新規注文でこれを放置すると、実際にはASP側で何年も前に
		// 支払われたか不明な注文が「移行を実行した今日」支払われたことになってしまう。
		// 一方`date_completed`はステータス（completed）自体から導かれる事実であり、
		// `paid`の有無に関わらずASP側の受注日時（`placed_at`）で確定してよい。
		$order  = $this->make_order(
			'1021',
			'completed',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[]
		);
		$result = $this->make_writer()->write( $order, null );

		$wc_order = wc_get_order( $result->local_id );
		$this->assertNull( $wc_order->get_date_paid() );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $wc_order->get_date_completed()->date( 'c' ) );
	}

	public function test_completed_order_with_explicit_paid_flag_gets_placed_at_as_completed_and_paid_dates(): void {
		$order  = $this->make_order(
			'1022',
			'completed',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => true ]
		);
		$result = $this->make_writer()->write( $order, null );

		$wc_order = wc_get_order( $result->local_id );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $wc_order->get_date_paid()->date( 'c' ) );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $wc_order->get_date_completed()->date( 'c' ) );
	}

	/**
	 * @dataProvider status_provider
	 */
	public function test_status_mapping( string $canonical_status, string $expected_woo_status ): void {
		$order    = $this->make_order( '1005', $canonical_status );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( $expected_woo_status, $wc_order->get_status() );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function status_provider(): array {
		return [
			'pending'    => [ 'pending', 'pending' ],
			'processing' => [ 'processing', 'processing' ],
			'completed'  => [ 'completed', 'completed' ],
			'cancelled'  => [ 'cancelled', 'cancelled' ],
		];
	}

	public function test_stock_reduced_flag_reflects_status(): void {
		$processing = $this->make_writer()->write( $this->make_order( '1006', 'processing' ), null );
		$this->assertTrue( wc_get_order( $processing->local_id )->get_data_store()->get_stock_reduced( wc_get_order( $processing->local_id ) ) );

		$pending = $this->make_writer()->write( $this->make_order( '1007', 'pending' ), null );
		$this->assertFalse( wc_get_order( $pending->local_id )->get_data_store()->get_stock_reduced( wc_get_order( $pending->local_id ) ) );
	}

	public function test_sales_and_download_permission_flags_reflect_status(): void {
		// pending/on-hold等の未処理注文にまで無条件でtrueを立てると、この注文が後に本当に
		// processing/completedへ遷移した際、WooCommerce標準フック
		// （`wc_update_total_sales_counts()`/`wc_update_coupon_usage_counts()`/
		// ダウンロード権限付与）が「既に処理済み」と誤認して発火しなくなる。
		$completed       = $this->make_writer()->write( $this->make_order( '1018', 'completed' ), null );
		$completed_order = wc_get_order( $completed->local_id );
		$this->assertTrue( $completed_order->get_recorded_sales() );
		$this->assertTrue( $completed_order->get_recorded_coupon_usage_counts() );
		$this->assertTrue( $completed_order->get_download_permissions_granted() );

		$pending       = $this->make_writer()->write( $this->make_order( '1019', 'pending' ), null );
		$pending_order = wc_get_order( $pending->local_id );
		$this->assertFalse( $pending_order->get_recorded_sales() );
		$this->assertFalse( $pending_order->get_recorded_coupon_usage_counts() );
		$this->assertFalse( $pending_order->get_download_permissions_granted() );
	}

	public function test_customer_resolved_via_mapping(): void {
		$user_id = wp_insert_user(
			[
				'user_login' => 'cust',
				'user_email' => 'cust@example.com',
				'user_pass'  => 'x',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', 'c1', $user_id );

		$order    = $this->make_order( '1008', 'processing', 'c1' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( $user_id, $wc_order->get_customer_id() );
	}

	public function test_customer_mapping_pointing_to_protected_role_account_is_treated_as_unresolved(): void {
		// ASP側顧客のメールが店舗の管理者・スタッフアカウントと偶然一致した場合、
		// `CustomerWriter::write()`はプロフィールを上書きしないままmappingだけを維持する
		// （`CustomerWriter::PROTECTED_ROLES`参照）。このmappingを無条件に信用すると、
		// 見ず知らずのASP顧客の注文が管理者アカウントに紐付いてしまうため、注文側でも
		// 再検証してゲスト注文として扱うことを確認する。
		$admin_id = wp_insert_user(
			[
				'user_login' => 'store-admin',
				'user_email' => 'admin@example.com',
				'user_pass'  => 'x',
				'role'       => 'administrator',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', 'c-admin', $admin_id );

		$order    = $this->make_order( '1020', 'processing', 'c-admin' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 0, $wc_order->get_customer_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, 'c-admin' ), $result->warnings );
		// 管理者アカウントとの衝突は解決される見込みが無い終端状態のため（再試行しても
		// 保護は解除されない）、`ORDER_CUSTOMER_UNRESOLVED`と異なりfully_resolvedはtrueのまま
		// （falseにすると、解決される可能性が無いのに毎回無駄に再処理されてしまう）。
		$this->assertTrue( $result->fully_resolved );
	}

	public function test_unresolved_customer_ref_warns_and_is_guest(): void {
		$order    = $this->make_order( '1009', 'processing', 'missing-customer' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 0, $wc_order->get_customer_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, 'missing-customer' ), $result->warnings );
		// `Importer::process_items()`はfully_resolved=falseの結果に対してchecksumを
		// キャッシュしない（顧客参照が後から解決可能になった場合に再試行するため）。
		$this->assertFalse( $result->fully_resolved );
	}

	public function test_residual_and_split_tax_warnings(): void {
		$order = $this->make_order(
			'1010',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
				'residual'     => '5',
				'tax_source'   => 'unavailable_for_split_order',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTAL_RESIDUAL, '5' ), $result->warnings );
		$this->assertContains( WarningCode::ORDER_SPLIT_TAX_UNKNOWN, $result->warnings );
	}

	public function test_re_run_does_not_duplicate_line_items(): void {
		$order = $this->make_order(
			'1011',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'x',
					'name'                => 'X',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => 1,
				],
			]
		);

		$first  = $this->make_writer()->write( $order, null );
		$second = $this->make_writer()->write( $order, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$wc_order = wc_get_order( $second->local_id );
		$this->assertCount( 1, $wc_order->get_items() );
	}

	public function test_extras_not_on_the_hardcoded_whitelist_are_still_persisted(): void {
		// `other_discount_name`/`product_tax`はapply_meta()の旧ホワイトリストに含まれておらず
		// 静かに欠落していたキー。ExtrasMeta経由になったことで、明示的に扱っていないASP固有の
		// extrasキーも往復移行のために保存されることを検証する。
		$order = $this->make_order(
			'2001',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[
				'other_discount_name' => '会員割引',
				'shop_coupon'         => [
					'code'   => 'SUMMER',
					'amount' => 100,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( '会員割引', $wc_order->get_meta( '_cbjp_other_discount_name' ) );
		$this->assertSame( '{"code":"SUMMER","amount":100}', $wc_order->get_meta( '_cbjp_shop_coupon' ) );
	}

	public function test_explicit_meta_fields_win_over_colliding_extras_keys(): void {
		// `cbjp/adapters/register`経由の外部アダプタは信頼境界のため、extrasに
		// '_cbjp_platform'等の予約済みメタと同名のキー（'platform'等）が偶然/意図的に
		// 含まれていても、`ExtrasMeta::apply()`より後に明示フィールドを設定することで
		// 正しい値が優先されることを確認する（他writer=ProductWriter/TermWriter/
		// CouponWriterと同じ順序規約）。
		$order = $this->make_order(
			'2003',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[
				'platform'            => 'malicious-override',
				'remote_order_number' => 'malicious-override',
				'remote_order_id'     => 'malicious-override',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 'colorme', $wc_order->get_meta( '_cbjp_platform' ) );
		$this->assertSame( '2003', $wc_order->get_meta( '_cbjp_remote_order_number' ) );
		$this->assertSame( '2003', $wc_order->get_meta( '_cbjp_remote_order_id' ) );
	}

	public function test_customer_snapshot_extras_key_is_not_persisted_as_meta(): void {
		$order = $this->make_order(
			'2002',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[
				'customer_snapshot' => [
					'name'  => 'Guest',
					'email' => 'guest@example.com',
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( '', $wc_order->get_meta( '_cbjp_customer_snapshot' ) );
	}

	public function test_unmapped_payment_and_shipping_methods_warn(): void {
		$order = $this->make_order(
			'1012',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			],
			[
				'method_id'   => 'pay-1',
				'method_name' => '銀行振込',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, 'pay-1' ), $result->warnings );
		$this->assertContains( WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, 'ship-1' ), $result->warnings );

		$wc_order = wc_get_order( $result->local_id );
		$this->assertSame( '銀行振込', $wc_order->get_payment_method_title() );
	}

	public function test_mapped_payment_method_sets_woo_gateway_id_not_the_asp_raw_id(): void {
		update_option( 'cbjp_settings_colorme', [ 'payment_map' => [ 'pay-1' => 'bacs' ] ] );

		$order = $this->make_order(
			'1013',
			'processing',
			null,
			[],
			[],
			[
				'method_id'   => 'pay-1',
				'method_name' => '銀行振込',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		// `payment_method`（決済ゲートウェイID）にはマッピング済みのWoo ID（'bacs'）が入り、
		// ASP側の生ID（'pay-1'）がそのまま入ってはならない。
		$this->assertSame( 'bacs', $wc_order->get_payment_method() );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::PAYMENT_METHOD_UNMAPPED ) )
		);

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_mapped_shipping_method_sets_woo_method_id_not_the_asp_raw_id(): void {
		update_option( 'cbjp_settings_colorme', [ 'shipping_map' => [ 'ship-1' => 'flat_rate' ] ] );

		$order = $this->make_order(
			'1014',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$shipping_items = array_values( $wc_order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping_items );
		// shipping item の method_id にはマッピング済みのWoo ID（'flat_rate'）が入り、
		// ASP側の生ID（'ship-1'）がそのまま入ってはならない。
		$this->assertSame( 'flat_rate', $shipping_items[0]->get_method_id() );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::SHIPPING_METHOD_UNMAPPED ) )
		);

		delete_option( 'cbjp_settings_colorme' );
	}
}
