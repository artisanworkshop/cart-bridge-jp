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
