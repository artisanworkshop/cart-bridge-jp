<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Adapters\CommerceAdapter;
use CartBridgeJP\Adapters\CommerceCapabilities;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;

/**
 * テスト用の顧客・受注・クーポンのアダプタ（R3-6c1 で `MockPlatformAdapter` から分けた）。固定フィクスチャをカーソル（offset 方式）で
 * ページングして返し、送信を記録する。`RegistersCommerceAdapters` で接続先（既定 `mock`）に登録する。
 */
final class MockCommerceAdapter extends CommerceAdapter {

	private const PAGE_SIZE = 2;

	/**
	 * @var array<int,array{0:CanonicalCustomer,1:?string}>
	 */
	public array $pushed_customers = [];

	/**
	 * @var array<int,array{0:CanonicalOrder,1:?string}>
	 */
	public array $pushed_orders = [];

	/**
	 * @var array<int,array{0:CanonicalCoupon,1:?string}>
	 */
	public array $pushed_coupons = [];

	private int $next_pushed_remote_id = 1;

	/**
	 * @param array<int,CanonicalCustomer> $customers
	 * @param array<int,CanonicalOrder>    $orders
	 * @param array<int,CanonicalCoupon>   $coupons
	 * @param bool                         $push_supported 真にすると送信が成功を返す（偽なら `UnsupportedOperationException`）。
	 * @param ?CommerceCapabilities        $capabilities_override 指定すると `capabilities()` がこの値を返す（能力が欠けた接続先のシナリオ）。
	 * @param \Throwable|null              $fetch_by_id_failure 指定すると ID 指定取得がこの例外を投げる（push intent の解除の障害シナリオ）。
	 * @param ?CanonicalCustomer           $customer_by_remote_id_override 指定すると顧客の ID 指定取得が要求 ID を無視してこれを返す。
	 * @param ?CanonicalOrder              $order_by_remote_id_override 同上（受注）。
	 * @param \Throwable|null              $create_push_failure 指定すると送信の**作成経路**（`$remote_id === null`）だけがこの例外を投げる（D21-B）。
	 * @param string                       $platform_id `id()`。接続先の `PlatformAdapter::id()` と同じ値にする（`CommerceAdapters` が照合する）。
	 * @param array<string,mixed>          $candidates `payment`・`shipping`・`status` の候補（キーが無ければ空）。
	 */
	public function __construct(
		private readonly array $customers = [],
		private readonly array $orders = [],
		private readonly array $coupons = [],
		private readonly bool $push_supported = false,
		private readonly ?CommerceCapabilities $capabilities_override = null,
		private readonly ?\Throwable $fetch_by_id_failure = null,
		private readonly ?CanonicalCustomer $customer_by_remote_id_override = null,
		private readonly ?CanonicalOrder $order_by_remote_id_override = null,
		private readonly ?\Throwable $create_push_failure = null,
		private readonly string $platform_id = 'mock',
		private readonly array $candidates = []
	) {}

	public function id(): string {
		return $this->platform_id;
	}

	public function capabilities(): CommerceCapabilities {
		return $this->capabilities_override ?? new CommerceCapabilities(
			can_fetch_customers: true,
			can_update_customer: true,
			can_create_order: true,
			has_coupons: true,
			can_create_coupon: true
		);
	}

	public function fetch_customers( Cursor $cursor ): Page {
		return $this->paginate( $this->customers, $cursor );
	}

	public function fetch_orders( Cursor $cursor ): Page {
		return $this->paginate( $this->orders, $cursor );
	}

	public function fetch_coupons( Cursor $cursor ): Page {
		return $this->paginate( $this->coupons, $cursor );
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		$this->maybe_fail_fetch_by_id();

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
		$this->maybe_fail_fetch_by_id();

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

	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		$this->guard_push( __FUNCTION__, $remote_id );
		$this->pushed_customers[] = [ $customer, $remote_id ];

		return $this->result( $remote_id );
	}

	public function push_order( CanonicalOrder $order, ?string $remote_id ): PushResult {
		$this->guard_push( __FUNCTION__, $remote_id );
		$this->pushed_orders[] = [ $order, $remote_id ];

		return $this->result( $remote_id );
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		$this->guard_push( __FUNCTION__, $remote_id );
		$this->pushed_coupons[] = [ $coupon, $remote_id ];

		return $this->result( $remote_id );
	}

	public function payment_candidates(): array {
		return $this->candidates['payment'] ?? [];
	}

	public function shipping_candidates(): array {
		return $this->candidates['shipping'] ?? [];
	}

	public function status_candidates(): array {
		return $this->candidates['status'] ?? [];
	}

	private function maybe_fail_fetch_by_id(): void {
		if ( null !== $this->fetch_by_id_failure ) {
			throw $this->fetch_by_id_failure;
		}
	}

	private function guard_push( string $operation, ?string $remote_id ): void {
		if ( ! $this->push_supported ) {
			throw new UnsupportedOperationException( $this->id(), $operation );
		}

		if ( null !== $this->create_push_failure && null === $remote_id ) {
			throw $this->create_push_failure;
		}
	}

	private function result( ?string $remote_id ): PushResult {
		if ( null !== $remote_id ) {
			return new PushResult( $remote_id, PushResult::OPERATION_UPDATED );
		}

		return new PushResult( (string) $this->next_pushed_remote_id++, PushResult::OPERATION_CREATED );
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
