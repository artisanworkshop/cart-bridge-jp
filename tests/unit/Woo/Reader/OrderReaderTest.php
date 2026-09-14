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
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

final class OrderReaderTest extends WooTestCase {

	private const PLATFORM = 'colorme';

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
		$this->assertSame( 'Taro Yamada', $canonical->shipping['name'] );
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

	public function test_variation_line_item_resolves_via_variant_mapping_not_parent(): void {
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
		$this->assertSame( 'v-child', $page->items[0]->item->line_items[0]['remote_product_id'] );
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
