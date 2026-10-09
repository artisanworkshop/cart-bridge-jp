<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Adapters\AbstractPlatformAdapter;
use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\ConnectionResult;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use Throwable;

/**
 * 別のアダプタへ委譲し、`capabilities()` だけを失敗させられるテスト用のアダプタ（R3-6b1。アダプタの能力の読み取りの失敗が
 * run の開始を止めることの確認用）。
 */
final class DelegatingPlatformAdapter extends AbstractPlatformAdapter {

	public function __construct(
		private readonly PlatformAdapter $inner,
		private readonly ?Throwable $capabilities_failure = null
	) {}

	public function id(): string {
		return $this->inner->id();
	}

	public function label(): string {
		return $this->inner->label();
	}

	public function capabilities(): Capabilities {
		if ( null !== $this->capabilities_failure ) {
			throw $this->capabilities_failure;
		}

		return $this->inner->capabilities();
	}

	public function test_connection(): ConnectionResult {
		return $this->inner->test_connection();
	}

	public function connection_fields(): array {
		return $this->inner->connection_fields();
	}

	public function mapping_candidates(): array {
		return $this->inner->mapping_candidates();
	}

	public function fetch_products( Cursor $cursor ): Page {
		return $this->inner->fetch_products( $cursor );
	}

	public function fetch_categories(): array {
		return $this->inner->fetch_categories();
	}

	public function fetch_tags(): array {
		return $this->inner->fetch_tags();
	}

	public function fetch_customers( Cursor $cursor ): Page {
		return $this->inner->fetch_customers( $cursor );
	}

	public function fetch_orders( Cursor $cursor ): Page {
		return $this->inner->fetch_orders( $cursor );
	}

	public function fetch_stocks( Cursor $cursor ): Page {
		return $this->inner->fetch_stocks( $cursor );
	}

	public function fetch_coupons( Cursor $cursor ): Page {
		return $this->inner->fetch_coupons( $cursor );
	}

	public function fetch_reviews( Cursor $cursor ): Page {
		return $this->inner->fetch_reviews( $cursor );
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		return $this->inner->fetch_product_by_remote_id( $remote_id );
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		return $this->inner->fetch_customer_by_remote_id( $remote_id );
	}

	public function fetch_order_by_remote_id( string $remote_id ): ?CanonicalOrder {
		return $this->inner->fetch_order_by_remote_id( $remote_id );
	}

	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult {
		return $this->inner->push_product( $product, $remote_id );
	}

	public function push_category( CanonicalCategory $category ): PushResult {
		return $this->inner->push_category( $category );
	}

	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		return $this->inner->push_customer( $customer, $remote_id );
	}

	public function push_order( CanonicalOrder $order, ?string $remote_id ): PushResult {
		return $this->inner->push_order( $order, $remote_id );
	}

	public function push_stock( CanonicalStock $stock ): PushResult {
		return $this->inner->push_stock( $stock );
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		return $this->inner->push_coupon( $coupon, $remote_id );
	}
}
