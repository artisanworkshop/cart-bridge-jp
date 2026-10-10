<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Support;

use CartBridgeJP\Support\TokenStore;
use WP_UnitTestCase;

final class TokenStoreTest extends WP_UnitTestCase {

	private function make_store(): array {
		$platform = 'test-' . wp_generate_uuid4();

		return [ new TokenStore( $platform ), $platform ];
	}

	public function test_save_and_get_round_trips_structured_payload(): void {
		[ $store ] = $this->make_store();

		$store->save(
			[
				'access_token'  => 'abcd1234',
				'refresh_token' => 'refresh-xyz',
				'expires_at'    => time() + 3600,
				'extras'        => [ 'foo' => 'bar' ],
			]
		);

		$payload = $store->get();

		$this->assertSame( 'abcd1234', $payload['access_token'] );
		$this->assertSame( 'refresh-xyz', $payload['refresh_token'] );
		$this->assertSame( [ 'foo' => 'bar' ], $payload['extras'] );
	}

	public function test_masked_access_token_returns_last_four_characters(): void {
		[ $store ] = $this->make_store();
		$store->save( [ 'access_token' => 'abcd1234' ] );

		$this->assertSame( '****1234', $store->masked_access_token() );
	}

	public function test_is_connected_is_false_before_any_save(): void {
		[ $store ] = $this->make_store();

		$this->assertFalse( $store->is_connected() );
		$this->assertNull( $store->get() );
		$this->assertNull( $store->masked_access_token() );
	}

	public function test_needs_reconnect_when_stored_value_cannot_be_decrypted(): void {
		[ $store, $platform ] = $this->make_store();
		$store->save( [ 'access_token' => 'abcd1234' ] );

		update_option( 'cbjp_token_' . $platform, 'not-a-valid-ciphertext' );

		// 復号失敗は別リクエスト（別インスタンス）で顕在化するシナリオのため、
		// インスタンス内キャッシュを持たない新しいストアで検証する。
		$fresh_store = new TokenStore( $platform );

		$this->assertTrue( $fresh_store->needs_reconnect() );
		$this->assertNull( $fresh_store->get() );
	}

	public function test_is_expired_reflects_expires_at(): void {
		[ $store ] = $this->make_store();

		$store->save(
			[
				'access_token' => 'x',
				'expires_at'   => time() - 10,
			]
		);
		$this->assertTrue( $store->is_expired() );

		$store->save(
			[
				'access_token' => 'x',
				'expires_at'   => time() + 10,
			]
		);
		$this->assertFalse( $store->is_expired() );
	}

	public function test_refresh_lock_is_exclusive_until_released(): void {
		[ $store ] = $this->make_store();

		$this->assertTrue( $store->acquire_refresh_lock() );
		$this->assertFalse( $store->acquire_refresh_lock() );

		$store->release_refresh_lock();

		$this->assertTrue( $store->acquire_refresh_lock() );
	}

	public function test_delete_removes_token_and_lock(): void {
		[ $store, $platform ] = $this->make_store();
		$store->save( [ 'access_token' => 'abcd1234' ] );
		$store->acquire_refresh_lock();

		$store->delete();

		$this->assertFalse( $store->is_connected() );
		$this->assertFalse( get_option( 'cbjp_token_lock_' . $platform ) );
	}

	/**
	 * R3-6c2: 付与されたスコープはトークンと同じ書込みで保存し、設定の保存・extras の更新（別の CAS）で消えない。
	 */
	public function test_granted_scopes_are_saved_with_the_token_and_survive_other_writes(): void {
		[ $store, $platform ] = $this->make_store();
		$store->save_settings(
			[
				'client_id'     => 'id',
				'client_secret' => 'secret',
			]
		);

		$this->assertNull( $store->granted_scopes(), '未接続' );
		$this->assertTrue(
			$store->save_token_if_credentials_match(
				'id',
				'secret',
				'token',
				[
					1 => 'read_products',
					3 => 'write_products',
				]
			)
		);
		$this->assertSame( [ 'read_products', 'write_products' ], $store->granted_scopes() );

		$store->save_settings( [ 'client_id' => 'id' ] );
		$store->update_extras_if_token_matches( 'token', static fn (): array => [ 'contract_plan' => 'premium' ] );

		$this->assertSame( [ 'read_products', 'write_products' ], ( new TokenStore( $platform ) )->granted_scopes() );
	}

	/**
	 * 資格情報が変わっていれば、トークンと一緒にスコープも書かない。
	 */
	public function test_granted_scopes_are_not_saved_when_the_credentials_changed(): void {
		[ $store ] = $this->make_store();
		$store->save_settings(
			[
				'client_id'     => 'new-id',
				'client_secret' => 'secret',
			]
		);

		$this->assertFalse( $store->save_token_if_credentials_match( 'old-id', 'secret', 'token', [ 'read_products' ] ) );
		$this->assertArrayNotHasKey( 'scopes', (array) $store->get() );
	}

	/**
	 * 記録の無いトークン（R3-6c2 より前に保存した）は null。どう読むかは呼び出し側（`ColorMeOAuth::granted_scopes_in()`）が決める。
	 */
	public function test_granted_scopes_is_null_for_a_token_without_a_record(): void {
		[ $store ] = $this->make_store();
		$store->save( [ 'access_token' => 'token' ] );

		$this->assertNull( $store->granted_scopes() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function broken_scope_records(): array {
		return [
			'string'            => [ 'read_products write_products' ],
			'null'              => [ null ],
			'map'               => [ [ 'a' => 'read_products' ] ],
			'non-string member' => [ [ 'read_products', 7 ] ],
		];
	}

	/**
	 * 壊れた記録は何も付与されていない扱い（要素を選り分けない。呼び出し側は再接続を促す側に倒れる）。
	 *
	 * @dataProvider broken_scope_records
	 *
	 * @param mixed $record 保存されている `scopes`。
	 */
	public function test_a_broken_scope_record_grants_nothing( mixed $record ): void {
		[ $store ] = $this->make_store();
		$store->save(
			[
				'access_token' => 'token',
				'scopes'       => $record,
			]
		);

		$this->assertSame( [], $store->granted_scopes() );
	}
}
