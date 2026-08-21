<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

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

/**
 * `Capabilities::can_fetch_customers` をfalseに固定するデコレータ（BASE等、顧客一覧APIを
 * 持たないアダプタのシナリオをテストするため）。それ以外は全て内側のアダプタへ委譲する。
 */
final class CustomerFetchDisabledAdapter implements PlatformAdapter {

	public function __construct( private readonly PlatformAdapter $inner ) {}

	public function id(): string {
		return $this->inner->id();
	}

	public function label(): string {
		return $this->inner->label();
	}

	public function capabilities(): Capabilities {
		$c = $this->inner->capabilities();

		return new Capabilities(
			$c->can_create_category,
			$c->can_create_order,
			false,
			$c->can_update_customer,
			$c->can_push_images,
			$c->can_create_coupon,
			$c->has_coupons,
			$c->has_tags,
			$c->has_reviews,
			$c->has_variants,
			$c->rate_limit_per_minute
		);
	}

	public function test_connection(): ConnectionResult {
		return $this->inner->test_connection();
	}

	public function connection_fields(): array {
		return $this->inner->connection_fields();
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

	public function fetch_latest_orders( int $limit ): array {
		return $this->inner->fetch_latest_orders( $limit );
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		return $this->inner->fetch_product_by_remote_id( $remote_id );
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		return $this->inner->fetch_customer_by_remote_id( $remote_id );
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

	public function push_order( CanonicalOrder $order ): PushResult {
		return $this->inner->push_order( $order );
	}

	public function push_stock( CanonicalStock $stock ): PushResult {
		return $this->inner->push_stock( $stock );
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		return $this->inner->push_coupon( $coupon, $remote_id );
	}
}
