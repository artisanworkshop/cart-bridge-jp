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
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Canonical\CanonicalTag;

/**
 * テスト用のモックアダプタ。固定フィクスチャをカーソル（offset方式）でページングして返す。
 * Sync層（JobManager/Importer/LimitPolicy/SampleSelector）のテストに使う。
 */
final class MockPlatformAdapter implements PlatformAdapter {

	private const PAGE_SIZE = 2;

	/**
	 * @param array<int,CanonicalProduct>  $products
	 * @param array<int,CanonicalCustomer> $customers
	 * @param array<int,CanonicalOrder>    $orders
	 * @param array<int,CanonicalCategory> $categories
	 * @param \Throwable|null              $fetch_failure 指定すると全fetch系メソッドがこの例外を投げる（障害シナリオのテスト用）。
	 */
	public function __construct(
		private readonly array $products = [],
		private readonly array $customers = [],
		private readonly array $orders = [],
		private readonly array $categories = [],
		private readonly ?\Throwable $fetch_failure = null
	) {}

	public function id(): string {
		return 'mock';
	}

	private function maybe_fail(): void {
		if ( null !== $this->fetch_failure ) {
			throw $this->fetch_failure;
		}
	}

	public function label(): string {
		return 'Mock Platform';
	}

	public function capabilities(): Capabilities {
		return new Capabilities( true, true, true, true, true, true, true, true, true, true, 600 );
	}

	public function test_connection(): ConnectionResult {
		return ConnectionResult::success( 'Mock Shop' );
	}

	public function connection_fields(): array {
		return [];
	}

	public function fetch_products( Cursor $cursor ): Page {
		$this->maybe_fail();

		return $this->paginate( $this->products, $cursor );
	}

	public function fetch_categories(): array {
		$this->maybe_fail();

		return $this->categories;
	}

	public function fetch_tags(): array {
		return [];
	}

	public function fetch_customers( Cursor $cursor ): Page {
		return $this->paginate( $this->customers, $cursor );
	}

	public function fetch_orders( Cursor $cursor ): Page {
		return $this->paginate( $this->orders, $cursor );
	}

	public function fetch_stocks( Cursor $cursor ): Page {
		$stocks = array_map(
			static fn( CanonicalProduct $product ): CanonicalStock => new CanonicalStock(
				(string) $product->extras['remote_id'],
				null,
				$product->sku,
				$product->stock,
				true
			),
			$this->products
		);

		return $this->paginate( $stocks, $cursor );
	}

	public function fetch_coupons( Cursor $cursor ): Page {
		return new Page( [], null, 0 );
	}

	public function fetch_reviews( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	/**
	 * 新しい順（配列の先頭から）$limit 件を返す。
	 */
	public function fetch_latest_orders( int $limit ): array {
		return array_slice( $this->orders, 0, $limit );
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		foreach ( $this->products as $product ) {
			if ( (string) $product->extras['remote_id'] === $remote_id ) {
				return $product;
			}
		}

		return null;
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		foreach ( $this->customers as $customer ) {
			if ( (string) $customer->extras['remote_id'] === $remote_id ) {
				return $customer;
			}
		}

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

	/**
	 * @param array<int,mixed> $items
	 */
	private function paginate( array $items, Cursor $cursor ): Page {
		$offset = (int) $cursor->get( 'offset', 0 );
		$slice  = array_slice( $items, $offset, self::PAGE_SIZE );
		$next   = ( $offset + self::PAGE_SIZE ) < count( $items ) ? new Cursor( [ 'offset' => $offset + self::PAGE_SIZE ] ) : null;

		return new Page( $slice, $next, count( $items ) );
	}
}
