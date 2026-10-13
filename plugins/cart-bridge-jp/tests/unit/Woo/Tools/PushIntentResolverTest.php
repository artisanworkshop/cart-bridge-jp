<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Tools;

use CartBridgeJP\Adapters\RemoteEntityUnreadableException;
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
 * D21-B（issue #73）: `resolve_link()`が`fetch_*_by_remote_id()`からの例外を、理由コード
 * （`PushIntentResolutionException`）へ変換することを確認する
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

	private function begin_product_intent(): int {
		$this->intents->begin( 'mock', 'product', 101, null, null );

		return $this->intents->find_unresolved( 'mock' )[0]['id'];
	}

	public function test_resolve_not_created_deletes_the_intent(): void {
		$id = $this->begin_product_intent();

		$this->resolver->resolve_not_created( 'mock', $id );

		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'product', 101 ) );
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
		$id      = $this->begin_product_intent();
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'remote-c1', 'SKU-1' ) ] );

		$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );

		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertSame( 101, $this->mappings->find_local_id( 'mock', 'product', 'remote-c1' ) );
		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', 'remote-c1' ) );
	}

	/**
	 * レビュー指摘（Copilot, G3）: crash等でintentが消えないまま残った後、この実体
	 * （intentのlocal_id）に別のremote_idで`link`すると、`upsert()`のユニークキー
	 * （platform, entity_type, remote_id）は新remote_idで別行をINSERTするだけになり、
	 * 古いremote_idの行が孤児として残る。`find_remote_id()`はid昇順の最初の行（＝古い方）を
	 * 採用するため、以後のexportが今回linkした新remote_idを無視してしまう。旧行が消え、
	 * local_id当たり1行だけが残ることを確認する。
	 */
	public function test_resolve_link_replaces_a_stale_mapping_row_for_the_same_local_id(): void {
		$id = $this->begin_product_intent();
		$this->mappings->upsert( 'mock', 'product', 'stale-remote-id', 101, 'stale-checksum' );
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'remote-c1', 'SKU-1' ) ] );

		$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );

		$this->assertSame( 101, $this->mappings->find_local_id( 'mock', 'product', 'remote-c1' ) );
		$this->assertNull( $this->mappings->find_local_id( 'mock', 'product', 'stale-remote-id' ), '旧remote_idの行が孤児として残っていないこと' );
	}

	/**
	 * レビュー指摘（Copilot/Codex）: 外部アダプタの戻り値は信用しない（アーキテクチャ原則8）。
	 * 契約違反アダプタが要求と異なる実体を返した場合、
	 * 実在確認をすり抜けて無関係な別のローカル実体へ紐付けてしまわないことを確認する。
	 * R3-11: 接続先は何かを返しているので「存在しない」（REMOTE_NOT_FOUND）ではなく REMOTE_UNREADABLE で断る（以前は REMOTE_NOT_FOUND）。
	 */
	public function test_resolve_link_rejects_a_remote_entity_whose_remote_id_does_not_match_the_request(): void {
		$this->intents->begin( 'mock', 'product', 101, null, null );
		$id = $this->intents->find_unresolved( 'mock' )[0]['id'];
		// 要求IDを無視し、別のremote_idを持つ商品を返す契約違反アダプタ（`product_by_remote_id_override`）。
		$adapter = new MockPlatformAdapter( product_by_remote_id_override: CanonicalFactory::product( 'different-remote-id', 'SKU-X' ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'requested-remote-id' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_UNREADABLE, $exception->reason() );
			$this->assertSame( PushIntentResolutionException::CAUSE_ID_MISMATCH, $exception->cause() );

			throw $exception;
		} finally {
			$this->assertNull( $this->mappings->find_local_id( 'mock', 'product', 'requested-remote-id' ) );
			$this->assertNull( $this->mappings->find_local_id( 'mock', 'product', 'different-remote-id' ) );
			$this->assertTrue( $this->intents->has_unresolved( 'mock', 'product', 101 ) );
		}
	}

	/**
	 * R3-11: ID 指定取得の null は「接続先に無い」（404）の意味なので、REMOTE_NOT_FOUND のまま。印は残し、mapping も書かない。
	 */
	public function test_resolve_link_reports_remote_not_found_only_when_the_lookup_returns_null(): void {
		$id = $this->begin_product_intent();

		try {
			$this->resolver->resolve_link( 'mock', $id, new MockPlatformAdapter(), 'absent-remote-id' );
			$this->fail( 'PushIntentResolutionException was not thrown' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_NOT_FOUND, $exception->reason() );
		}

		$this->assertNull( $this->mappings->find_local_id( 'mock', 'product', 'absent-remote-id' ) );
		$this->assertTrue( $this->intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	/**
	 * R3-11（backlog `r3-0b-push-intent-backend/R1-X1`）: 接続先は項目を返したが読めない（変換の失敗・取り込まない種類の行）ときは、
	 * 「存在しない」と答えない。答えると、それを信じた「未作成として解除」の次のエクスポートが実在する実体をもう一度作る。
	 */
	public function test_resolve_link_reports_an_unreadable_remote_entity_instead_of_not_found(): void {
		$id      = $this->begin_product_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new RemoteEntityUnreadableException( 'mock', 'product', new RuntimeException( 'broken row' ) ) );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
			$this->fail( 'PushIntentResolutionException was not thrown' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_UNREADABLE, $exception->reason() );
			// 変換の例外があれば、原因は「変換の失敗」（ログの context。review-loop R1-L2）。
			$this->assertSame( PushIntentResolutionException::CAUSE_TRANSFORM_FAILED, $exception->cause() );
		}

		$this->assertNull( $this->mappings->find_local_id( 'mock', 'product', 'remote-c1' ) );
		$this->assertTrue( $this->intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	/**
	 * 変換の例外の無い「読めない」（取り込まない種類の行。Pro の顧客の非会員など）は、原因を「取り込まない行」にする（review-loop R1-L2）。
	 */
	public function test_resolve_link_reports_an_unreadable_row_without_a_transform_failure_as_its_cause(): void {
		$id      = $this->begin_product_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new RemoteEntityUnreadableException( 'mock', 'product' ) );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
			$this->fail( 'PushIntentResolutionException was not thrown' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_UNREADABLE, $exception->reason() );
			$this->assertSame( PushIntentResolutionException::CAUSE_UNREADABLE_ROW, $exception->cause() );
		}
	}

	/**
	 * R3-11: 登録の無い種類（拡張を停止した後に残った intent）は ASP に問い合わせずに TYPE_UNAVAILABLE で断る（以前は REMOTE_NOT_FOUND）。
	 * 「無い」と答えると、それを信じた「未作成として解除」が拡張を戻した後の重複を招く。
	 */
	public function test_resolve_link_rejects_an_unregistered_type_without_calling_the_adapter(): void {
		$this->intents->begin( 'mock', 'zzv_gone', 303, null, null );
		$id      = $this->intents->find_unresolved( 'mock' )[0]['id'];
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new RuntimeException( 'should not be called' ) );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'anything' );
			$this->fail( 'PushIntentResolutionException was not thrown' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::TYPE_UNAVAILABLE, $exception->reason() );
		}

		$this->assertNull( $this->mappings->find_local_id( 'mock', 'zzv_gone', 'anything' ) );
		$this->assertTrue( $this->intents->has_unresolved( 'mock', 'zzv_gone', 303 ) );
	}

	/**
	 * R3-11（ユーザー決定〔案 C〕）: 登録の無い種類は「未作成として解除」も断る。消すと拡張を戻した後のエクスポートが、作成済みだった実体をもう一度作りうる。
	 */
	public function test_resolve_not_created_rejects_an_unregistered_type_and_keeps_the_intent(): void {
		$this->intents->begin( 'mock', 'zzv_gone', 303, null, null );
		$id = $this->intents->find_unresolved( 'mock' )[0]['id'];

		try {
			$this->resolver->resolve_not_created( 'mock', $id );
			$this->fail( 'PushIntentResolutionException was not thrown' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::TYPE_UNAVAILABLE, $exception->reason() );
		}

		$this->assertTrue( $this->intents->has_unresolved( 'mock', 'zzv_gone', 303 ) );
	}

	public function test_resolve_link_rejects_a_remote_id_already_linked_to_a_different_local_id(): void {
		$id      = $this->begin_product_intent();
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'remote-c1', 'SKU-1' ) ] );
		$this->mappings->upsert( 'mock', 'product', 'remote-c1', 555, null );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_ID_IN_USE, $exception->reason() );

			throw $exception;
		}
	}

	/**
	 * ID で取得しない種類（在庫。`EntityType::fetch_by_remote_id()` の既定）は、アダプタを呼ばずに LINK_UNSUPPORTED。
	 */
	public function test_resolve_link_rejects_a_type_without_a_lookup_as_unsupported_without_calling_the_adapter(): void {
		$this->intents->begin( 'mock', 'stock', 202, null, null );
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
		$id      = $this->begin_product_intent();
		$adapter = new MockPlatformAdapter( fetch_by_id_failure: new UnsupportedOperationException( 'mock', 'fetch_product_by_remote_id' ) );

		$this->expectException( PushIntentResolutionException::class );

		try {
			$this->resolver->resolve_link( 'mock', $id, $adapter, 'remote-c1' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::LINK_UNSUPPORTED, $exception->reason() );

			throw $exception;
		}
	}

	public function test_resolve_link_maps_a_plain_rate_limit_exception_to_rate_limited(): void {
		$id      = $this->begin_product_intent();
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
		$id      = $this->begin_product_intent();
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
		$id      = $this->begin_product_intent();
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
		$id      = $this->begin_product_intent();
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
		$id      = $this->begin_product_intent();
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
		$id      = $this->begin_product_intent();
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
