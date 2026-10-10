<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Adapters;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Pro\Adapters\ColorMe\ColorMeCommerceAdapter;
use CartBridgeJP\Pro\Adapters\CommerceAdapter;
use CartBridgeJP\Pro\Adapters\CommerceCapabilities;
use CartBridgeJP\Pro\Canonical\CanonicalCoupon;
use CartBridgeJP\Pro\Tests\Fixtures\CommerceFactory;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * `CommerceAdapter` の既定実装の契約（R3-6c1。無料版の `AbstractPlatformAdapterTest` と同じ考え方）: 実装しない操作は
 * `UnsupportedOperationException` を投げ、空の一覧・null（正常な「0 件」「404」と区別できない値）を返さない。候補は空の配列。
 */
final class CommerceAdapterContractTest extends WP_UnitTestCase {

	/**
	 * 既定で例外を投げる操作（抽象でも候補でもない公開メソッドの全部。足したらここにも足す）。
	 *
	 * @var array<int,string>
	 */
	private const UNSUPPORTED_BY_DEFAULT = [
		'fetch_customers',
		'fetch_orders',
		'fetch_coupons',
		'fetch_customer_by_remote_id',
		'fetch_order_by_remote_id',
		'push_customer',
		'push_order',
		'push_coupon',
	];

	private const CANDIDATES = [ 'payment_candidates', 'shipping_candidates', 'status_candidates' ];

	public function test_every_public_method_is_classified(): void {
		$methods = array_map(
			static fn ( ReflectionMethod $method ): string => $method->getName(),
			( new ReflectionClass( CommerceAdapter::class ) )->getMethods( ReflectionMethod::IS_PUBLIC )
		);
		sort( $methods );

		$expected = array_merge( [ 'capabilities', 'id' ], self::UNSUPPORTED_BY_DEFAULT, self::CANDIDATES );
		sort( $expected );

		$this->assertSame( $expected, $methods );
	}

	public function test_unimplemented_operations_throw_unsupported(): void {
		$adapter = $this->bare_adapter();
		$calls   = [
			'fetch_customers'             => static fn () => $adapter->fetch_customers( Cursor::start() ),
			'fetch_orders'                => static fn () => $adapter->fetch_orders( Cursor::start() ),
			'fetch_coupons'               => static fn () => $adapter->fetch_coupons( Cursor::start() ),
			'fetch_customer_by_remote_id' => static fn () => $adapter->fetch_customer_by_remote_id( '1' ),
			'fetch_order_by_remote_id'    => static fn () => $adapter->fetch_order_by_remote_id( '1' ),
			'push_customer'               => static fn () => $adapter->push_customer( CommerceFactory::customer( '1', 'buyer@example.com' ), null ),
			'push_order'                  => static fn () => $adapter->push_order( CommerceFactory::order( 'O-1', null, [] ), null ),
			'push_coupon'                 => static fn () => $adapter->push_coupon( new CanonicalCoupon( 'C1', 'fixed', '100', null, null, null, has_unsupported_restrictions: false ), null ),
		];

		$this->assertSame( self::UNSUPPORTED_BY_DEFAULT, array_keys( $calls ) );

		foreach ( $calls as $method => $call ) {
			try {
				$call();
				$this->fail( $method . ' did not throw' );
			} catch ( UnsupportedOperationException $e ) {
				$this->assertStringContainsString( $method, $e->getMessage(), $method );
			}
		}
	}

	public function test_candidates_default_to_empty(): void {
		$adapter = $this->bare_adapter();

		foreach ( self::CANDIDATES as $method ) {
			$this->assertSame( [], $adapter->{$method}(), $method );
		}
	}

	public function test_bundled_and_test_adapters_extend_the_base(): void {
		$this->assertTrue( is_subclass_of( ColorMeCommerceAdapter::class, CommerceAdapter::class ) );
		$this->assertTrue( is_subclass_of( MockCommerceAdapter::class, CommerceAdapter::class ) );
	}

	private function bare_adapter(): CommerceAdapter {
		return new class() extends CommerceAdapter {
			public function id(): string {
				return 'bare';
			}

			public function capabilities(): CommerceCapabilities {
				return new CommerceCapabilities(
					can_fetch_customers: false,
					can_update_customer: false,
					can_create_order: false,
					has_coupons: false,
					can_create_coupon: false
				);
			}
		};
	}
}
