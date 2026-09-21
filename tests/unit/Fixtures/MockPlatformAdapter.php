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
	 * fetch_products()/fetch_categories() の呼び出し回数。
	 * paused時にJobManagerが空回りで再フェッチしていないことの検証に使う。
	 */
	public int $fetch_calls = 0;

	/**
	 * `fetch_customer_by_remote_id()`/`fetch_order_by_remote_id()`の呼び出し履歴（`[entity, remote_id]`）。
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	public array $fetched_by_id = [];

	/**
	 * `push_product()`に渡された`(CanonicalProduct, ?remote_id)`の記録
	 * （`Sync\Exporter`のテストで実際にpushされた内容を検証する用）。
	 *
	 * @var array<int,array{0:CanonicalProduct,1:?string}>
	 */
	public array $pushed_products = [];

	/**
	 * 次にpush_product()が発行するremote_idの採番カウンタ（新規作成時のみ使用）。
	 */
	private int $next_pushed_remote_id = 1;

	/**
	 * `push_customer()`/`push_order()`/`push_stock()`/`push_coupon()`に渡された引数の記録
	 * （`JobManagerExportTest`でPR-Bのexport正常系を検証する用。`$pushed_products`と同じ役割）。
	 *
	 * @var array<int,array{0:CanonicalCustomer,1:?string}>
	 */
	public array $pushed_customers = [];

	/**
	 * @var array<int,array{0:CanonicalOrder,1:?string}>
	 */
	public array $pushed_orders = [];

	/**
	 * @var array<int,CanonicalStock>
	 */
	public array $pushed_stocks = [];

	/**
	 * @var array<int,array{0:CanonicalCoupon,1:?string}>
	 */
	public array $pushed_coupons = [];

	/**
	 * `$next_pushed_remote_id`と同じ役割のcustomer/coupon向け採番カウンタ
	 * （product用と衝突しないよう別カウンタにする）。
	 */
	private int $next_pushed_other_remote_id = 1;

	/**
	 * @param array<int,CanonicalProduct>  $products
	 * @param array<int,CanonicalCustomer> $customers
	 * @param array<int,CanonicalOrder>    $orders
	 * @param array<int,CanonicalCategory> $categories
	 * @param \Throwable|null              $fetch_failure 指定すると全fetch系メソッドがこの例外を投げる（障害シナリオのテスト用）。
	 * @param array<int,mixed>|null        $connection_fields_override 指定すると connection_fields() がこの値をそのまま返す
	 *   （ConnectionField以外の混入など、契約違反アダプタのシナリオのテスト用）。
	 * @param ?Capabilities                $capabilities_override 指定すると capabilities() がこの値をそのまま返す
	 *   （BASE等、特定capabilityがfalseのアダプタのシナリオのテスト用）。
	 * @param ?CanonicalProduct             $product_by_remote_id_override 指定すると
	 *   fetch_product_by_remote_id() が要求IDを無視してこの商品をそのまま返す
	 *   （要求IDと異なる商品を返す契約違反アダプタのシナリオのテスト用）。
	 * @param ?array<string,mixed>          $mapping_candidates_override 指定すると
	 *   mapping_candidates() がこの値をそのまま返す（`RestController`の候補集約ロジックの
	 *   テスト用）。
	 * @param bool                           $push_products_supported 指定するとpush_product()が
	 *   `UnsupportedOperationException`を投げず成功を返す（`Sync\Exporter`のテスト用。
	 *   ColorMe実装（E2-3）が無いPR-A時点でexportの正常系を検証するために必要）。
	 * @param bool                           $push_others_supported 指定するとpush_customer()/
	 *   push_order()/push_stock()/push_coupon()が`UnsupportedOperationException`を投げず成功を
	 *   返す（`push_products_supported`のPR-B版。customer/order/stock/couponをまとめて1フラグで
	 *   制御する。4エンティティを個別に無効化するテストは`capabilities_override`で行う）。
	 * @param \Throwable|null                $fetch_by_id_failure 指定すると`fetch_customer_by_remote_id()`/
	 *   `fetch_order_by_remote_id()`だけがこの例外を投げる（`$fetch_failure`と違い既存のID指定取得の
	 *   テストへ影響しない。県コード修復ツールの障害シナリオのテスト用）。
	 * @param ?CanonicalCustomer              $customer_by_remote_id_override 指定すると
	 *   fetch_customer_by_remote_id() が要求IDを無視してこの顧客をそのまま返す
	 *   （要求IDと異なる顧客を返す契約違反アダプタのシナリオのテスト用。`$product_by_remote_id_override`と同じ）。
	 * @param ?CanonicalOrder                 $order_by_remote_id_override 同上（fetch_order_by_remote_id()）。
	 * @param string                          $platform_id id() が返す値（既定 'mock'）。`Importer`/`Exporter`/`JobManager` は
	 *   mapping・上限・サンプルのキーを登録キーではなく `$adapter->id()` から決めるため、`cbjp/adapters/register` に
	 *   別のキー（例: `colorme`）で登録する手動検証（`verify-with-mock-adapter` スキル）では、そのキーと同じ値を渡す。
	 */
	public function __construct(
		private readonly array $products = [],
		private readonly array $customers = [],
		private readonly array $orders = [],
		private readonly array $categories = [],
		private readonly ?\Throwable $fetch_failure = null,
		private readonly ?array $connection_fields_override = null,
		private readonly ?Capabilities $capabilities_override = null,
		private readonly ?CanonicalProduct $product_by_remote_id_override = null,
		private readonly ?array $mapping_candidates_override = null,
		private readonly bool $push_products_supported = false,
		private readonly bool $push_others_supported = false,
		private readonly ?\Throwable $fetch_by_id_failure = null,
		private readonly ?CanonicalCustomer $customer_by_remote_id_override = null,
		private readonly ?CanonicalOrder $order_by_remote_id_override = null,
		private readonly string $platform_id = 'mock'
	) {}

	public function id(): string {
		return $this->platform_id;
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
		return $this->capabilities_override ?? new Capabilities( true, true, true, true, true, true, true, true, true, true, 600 );
	}

	public function test_connection(): ConnectionResult {
		return ConnectionResult::success( 'Mock Shop' );
	}

	public function connection_fields(): array {
		return $this->connection_fields_override ?? [];
	}

	public function mapping_candidates(): array {
		return $this->mapping_candidates_override ?? [];
	}

	public function fetch_products( Cursor $cursor ): Page {
		++$this->fetch_calls;
		$this->maybe_fail();

		return $this->paginate( $this->products, $cursor );
	}

	public function fetch_categories(): array {
		++$this->fetch_calls;
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
		if ( null !== $this->product_by_remote_id_override ) {
			return $this->product_by_remote_id_override;
		}

		foreach ( $this->products as $product ) {
			if ( (string) $product->extras['remote_id'] === $remote_id ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * ID指定取得の呼び出しを記録し、`$fetch_by_id_failure`が指定されていれば投げる
	 * （県コード修復ツールの「事前フィルタでAPIを呼ばない」「障害で中断しcursorを返す」のテスト用）。
	 */
	private function record_fetch_by_id( string $entity, string $remote_id ): void {
		$this->fetched_by_id[] = [ $entity, $remote_id ];

		if ( null !== $this->fetch_by_id_failure ) {
			throw $this->fetch_by_id_failure;
		}
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		$this->record_fetch_by_id( 'customer', $remote_id );

		if ( null !== $this->customer_by_remote_id_override ) {
			return $this->customer_by_remote_id_override;
		}

		foreach ( $this->customers as $customer ) {
			if ( (string) $customer->extras['remote_id'] === $remote_id ) {
				return $customer;
			}
		}

		return null;
	}

	public function fetch_order_by_remote_id( string $remote_id ): ?CanonicalOrder {
		$this->record_fetch_by_id( 'order', $remote_id );

		if ( null !== $this->order_by_remote_id_override ) {
			return $this->order_by_remote_id_override;
		}

		foreach ( $this->orders as $order ) {
			if ( $order->remote_id() === $remote_id ) {
				return $order;
			}
		}

		return null;
	}

	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult {
		if ( ! $this->push_products_supported ) {
			throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
		}

		$this->pushed_products[] = [ $product, $remote_id ];

		if ( null !== $remote_id ) {
			return new PushResult( $remote_id, PushResult::OPERATION_UPDATED );
		}

		return new PushResult( (string) $this->next_pushed_remote_id++, PushResult::OPERATION_CREATED );
	}

	public function push_category( CanonicalCategory $category ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		if ( ! $this->push_others_supported ) {
			throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
		}

		$this->pushed_customers[] = [ $customer, $remote_id ];

		if ( null !== $remote_id ) {
			return new PushResult( $remote_id, PushResult::OPERATION_UPDATED );
		}

		return new PushResult( (string) $this->next_pushed_other_remote_id++, PushResult::OPERATION_CREATED );
	}

	public function push_order( CanonicalOrder $order, ?string $remote_id ): PushResult {
		if ( ! $this->push_others_supported ) {
			throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
		}

		$this->pushed_orders[] = [ $order, $remote_id ];

		if ( null !== $remote_id ) {
			return new PushResult( $remote_id, PushResult::OPERATION_UPDATED );
		}

		return new PushResult( (string) $this->next_pushed_other_remote_id++, PushResult::OPERATION_CREATED );
	}

	public function push_stock( CanonicalStock $stock ): PushResult {
		if ( ! $this->push_others_supported ) {
			throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
		}

		$this->pushed_stocks[] = $stock;

		return new PushResult( $stock->remote_id(), PushResult::OPERATION_UPDATED );
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		if ( ! $this->push_others_supported ) {
			throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
		}

		$this->pushed_coupons[] = [ $coupon, $remote_id ];

		if ( null !== $remote_id ) {
			return new PushResult( $remote_id, PushResult::OPERATION_UPDATED );
		}

		return new PushResult( (string) $this->next_pushed_other_remote_id++, PushResult::OPERATION_CREATED );
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
