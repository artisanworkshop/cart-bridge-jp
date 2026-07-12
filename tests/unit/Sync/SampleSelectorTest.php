<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Sync\SampleSelector;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_UnitTestCase;

final class SampleSelectorTest extends WP_UnitTestCase {

	public function test_selects_products_and_customers_referenced_by_the_latest_orders(): void {
		$orders  = [
			CanonicalFactory::order( '1001', 'cust-1', [ 'p1', 'p2' ] ),
			CanonicalFactory::order( '1002', 'cust-2', [ 'p2' ] ),
			CanonicalFactory::order( '1003', null, [ 'p3' ] ), // ゲスト購入は顧客枠にカウントしない。
		];
		$adapter = new MockPlatformAdapter( orders: $orders );

		$sample = ( new SampleSelector( $adapter ) )->select_or_load( 'mock' );

		$this->assertSame( [ '1001', '1002', '1003' ], $sample->order_remote_ids );
		$this->assertSame( [ 'p1', 'p2', 'p3' ], $sample->product_remote_ids );
		$this->assertSame( [ 'cust-1', 'cust-2' ], $sample->customer_refs );
	}

	public function test_falls_back_when_the_shop_has_no_orders(): void {
		$adapter = new MockPlatformAdapter( orders: [] );

		$sample = ( new SampleSelector( $adapter ) )->select_or_load( 'mock' );

		$this->assertTrue( $sample->used_fallback );
		$this->assertSame( [], $sample->order_remote_ids );
		$this->assertSame( [], $sample->product_remote_ids );
	}

	public function test_empty_sample_is_not_persisted_so_a_later_run_reselects(): void {
		$selector_without_orders = new SampleSelector( new MockPlatformAdapter( orders: [] ) );
		$selector_without_orders->select_or_load( 'mock' );

		// 空セットが固定されず、受注が入った後の実行では再選定されること。
		$selector_with_orders = new SampleSelector(
			new MockPlatformAdapter( orders: [ CanonicalFactory::order( '1001', 'cust-1', [ 'p1' ] ) ] )
		);
		$sample               = $selector_with_orders->select_or_load( 'mock' );

		$this->assertNotSame( [], $sample->order_remote_ids );
		$this->assertSame( [ 'p1' ], $sample->product_remote_ids );
	}

	public function test_numeric_remote_ids_are_normalized_to_strings(): void {
		// PHPは数値文字列の配列キーをintに正規化するため、明示的なstring化を検証する
		// （strict_types下の fetch_product_by_remote_id(string) へ int が渡ると TypeError になる）。
		$orders  = [ CanonicalFactory::order( '1001', '2002', [ '101', '102' ] ) ];
		$adapter = new MockPlatformAdapter( orders: $orders );

		$sample = ( new SampleSelector( $adapter ) )->select_or_load( 'mock' );

		$this->assertSame( [ '101', '102' ], $sample->product_remote_ids );
		$this->assertSame( [ '2002' ], $sample->customer_refs );

		foreach ( $sample->product_remote_ids as $id ) {
			$this->assertIsString( $id );
		}
	}

	public function test_reselecting_reuses_the_persisted_sample_instead_of_calling_the_adapter_again(): void {
		$orders   = [ CanonicalFactory::order( '1001', 'cust-1', [ 'p1' ] ) ];
		$adapter  = new MockPlatformAdapter( orders: $orders );
		$selector = new SampleSelector( $adapter );

		$first  = $selector->select_or_load( 'mock' );
		$second = $selector->select_or_load( 'mock' );

		$this->assertEquals( $first, $second );
	}

	public function test_clear_allows_reselection(): void {
		$adapter  = new MockPlatformAdapter( orders: [ CanonicalFactory::order( '1001', 'cust-1', [ 'p1' ] ) ] );
		$selector = new SampleSelector( $adapter );

		$selector->select_or_load( 'mock' );
		$selector->clear( 'mock' );

		$this->assertNull( $selector->load( 'mock' ) );
	}
}
