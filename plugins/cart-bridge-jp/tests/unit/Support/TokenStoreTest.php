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
}
