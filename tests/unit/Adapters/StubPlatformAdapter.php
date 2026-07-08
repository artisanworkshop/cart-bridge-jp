<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters;

use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\ConnectionResult;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;

/**
 * テスト用の最小実装。fetch/push系はUnsupportedOperationExceptionを投げる。
 */
final class StubPlatformAdapter implements PlatformAdapter {

	public function id(): string {
		return 'stub';
	}

	public function label(): string {
		return 'Stub';
	}

	public function capabilities(): Capabilities {
		return new Capabilities( false, false, false, false, false, false, false, false, false, false, 60 );
	}

	public function test_connection(): ConnectionResult {
		return ConnectionResult::success( 'Stub Shop' );
	}

	public function connection_fields(): array {
		return [];
	}

	public function fetch_products( Cursor $cursor ): Page {
		return new Page( [], null, 0 );
	}

	public function fetch_categories(): array {
		return [];
	}

	public function fetch_tags(): array {
		return [];
	}

	public function fetch_customers( Cursor $cursor ): Page {
		return new Page( [], null, 0 );
	}

	public function fetch_orders( Cursor $cursor ): Page {
		return new Page( [], null, 0 );
	}

	public function fetch_stocks( Cursor $cursor ): Page {
		return new Page( [], null, 0 );
	}

	public function fetch_coupons( Cursor $cursor ): Page {
		return new Page( [], null, 0 );
	}

	public function fetch_reviews( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function fetch_latest_orders( int $limit ): array {
		return [];
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		return null;
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		return null;
	}

	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_category( CanonicalCategory $category ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_order( CanonicalOrder $order ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_stock( CanonicalStock $stock ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}
}
