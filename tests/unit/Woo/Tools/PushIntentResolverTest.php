<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Tools;

use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Tools\PushIntentResolutionException;
use CartBridgeJP\Woo\Tools\PushIntentResolver;
use RuntimeException;
use WP_UnitTestCase;

/**
 * D21-B（issue #73）: `resolve_link()`が`fetch_*_by_remote_id()`からの例外を、`Woo\Tools\
 * PrefStateRepair::classify_api_failure()`と同じ理由コードへ変換することを確認する
 * （レビュー指摘: `UnsupportedOperationException`しか捕まえておらず、他の例外がREST層の
 * 外へ抜けてPHPの致命的エラーになりうる問題への対応）。
 */
final class PushIntentResolverTest extends WP_UnitTestCase {

	private PushIntentRepository $intents;
	private MappingRepository $mappings;
	private PushIntentResolver $resolver;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->intents  = new PushIntentRepository();
		$this->mappings = new MappingRepository();
		$this->resolver = new PushIntentResolver( $this->intents, $this->mappings );
	}

	private function begin_customer_intent(): int {
		$this->intents->begin( 'mock', 'customer', 101, null, null );

		return $this->intents->find_unresolved( 'mock' )[0]['id'];
	}

	public function test_resolve_not_created_deletes_the_intent(): void {
		$id = $this->begin_customer_intent();

		$this->resolver->resolve_not_created( 'mock', $id );

		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'customer', 101 ) );
	}

	public function test_resolve_not_created_throws_not_found_for_an_unknown_id(): void {
		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_not_created( 'mock', 999999 );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::NOT_FOUND, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_writes_a_mapping_with_a_null_checksum(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( customers: [ CanonicalFactory::customer( 'remote-c1', 'buyer@example.test' ) ] );

		$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );

		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'customer', 101 ) );
		$this->assertSame( 101, $this->mappings->find_local_id( 'mock', 'customer', 'remote-c1' ) );
		$this->assertNull( $this->mappings->find_checksum( 'mock', 'customer', 'remote-c1' ) );
	}

	public function test_resolve_link_rejects_a_remote_id_already_linked_to_a_different_local_id(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( customers: [ CanonicalFactory::customer( 'remote-c1', 'buyer@example.test' ) ] );
		$this->mappings->upsert( 'mock', 'customer', 'remote-c1', 555, null );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_ID_IN_USE, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_rejects_coupons_as_unsupported_without_calling_the_adapter(): void {
		$this->intents->begin( 'mock', 'coupon', 202, null, null );
		$id      = $this->intents->find_unresolved( 'mock' )[0]['id'];
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new RuntimeException( 'should not be called' ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'anything' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::LINK_UNSUPPORTED, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_maps_unsupported_operation_exception_to_link_unsupported(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new UnsupportedOperationException( 'mock', 'fetch_customer_by_remote_id' ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::LINK_UNSUPPORTED, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_maps_a_plain_rate_limit_exception_to_rate_limited(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new RateLimitExhaustedException( 'mock' ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::RATE_LIMITED, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_maps_an_explicit_not_connected_api_exception_to_not_connected(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new ApiException( 'not connected', 0, [ 'not_connected' => true ] ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::NOT_CONNECTED, $exception->reason() );

			throw $exception;
		}
	}

	/**
	 * status 0だけでは「未接続」と判定しない（`.claude/rules/adapters-colorme.md`）:
	 * `not_connected`が明示されていない通信断/JSON破損は`REMOTE_UNAVAILABLE`（502）にする。
	 */
	public function test_resolve_link_maps_an_unspecified_status_zero_api_exception_to_remote_unavailable(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new ApiException( 'no response', 0 ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_UNAVAILABLE, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_maps_a_5xx_api_exception_to_remote_unavailable(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new ApiException( 'server error', 500 ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_UNAVAILABLE, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_maps_a_platform_429_api_exception_to_rate_limited(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new ApiException( 'too many requests', 429 ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::RATE_LIMITED, $exception->reason() );

			throw $exception;
		}
	}

	/**
	 * `fetch_*_by_remote_id()`が想定外の生の`Throwable`（アダプタの契約違反）を投げても
	 * REST層の外まで伝播させず、`REMOTE_UNAVAILABLE`へ変換する。
	 */
	public function test_resolve_link_maps_an_unexpected_exception_to_remote_unavailable(): void {
		$id      = $this->begin_customer_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new RuntimeException( 'unexpected' ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_UNAVAILABLE, $exception->reason() );

			throw $exception;
		}
	}
}
