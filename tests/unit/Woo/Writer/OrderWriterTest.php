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

	public function test_unresolved_customer_ref_warns_and_is_guest(): void {
		$order    = $this->make_order( '1009', 'processing', 'missing-customer' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 0, $wc_order->get_customer_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, 'missing-customer' ), $result->warnings );
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
}
