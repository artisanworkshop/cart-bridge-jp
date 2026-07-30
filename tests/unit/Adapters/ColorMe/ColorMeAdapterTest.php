<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Support\TokenStore;
use WP_UnitTestCase;

final class ColorMeAdapterTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * @return array{0:ColorMeAdapter,1:TokenStore,2:string}
	 */
	private function make_adapter(): array {
		$platform    = 'test-colorme-adapter-' . wp_generate_uuid4();
		$token_store = new TokenStore( $platform );

		return [ new ColorMeAdapter( $token_store ), $token_store, $platform ];
	}

	public function test_id_and_label(): void {
		[ $adapter ] = $this->make_adapter();

		$this->assertSame( ColorMeAdapter::ID, $adapter->id() );
		$this->assertNotEmpty( $adapter->label() );
	}

	public function test_capabilities_defaults_can_push_images_to_false_when_plan_unknown(): void {
		[ $adapter ] = $this->make_adapter();

		$this->assertFalse( $adapter->capabilities()->can_push_images );
	}

	public function test_capabilities_reflects_cached_premium_contract_plan(): void {
		[ $adapter, $token_store ] = $this->make_adapter();

		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->assertTrue( $adapter->capabilities()->can_push_images );
	}

	public function test_capabilities_static_values_match_01_plan_colorme(): void {
		[ $adapter ]  = $this->make_adapter();
		$capabilities = $adapter->capabilities();

		$this->assertFalse( $capabilities->can_create_category );
		$this->assertTrue( $capabilities->can_create_order );
		$this->assertTrue( $capabilities->can_fetch_customers );
		$this->assertTrue( $capabilities->can_update_customer );
		$this->assertFalse( $capabilities->can_create_coupon );
		$this->assertTrue( $capabilities->has_coupons );
		$this->assertTrue( $capabilities->has_tags );
		$this->assertFalse( $capabilities->has_reviews );
		$this->assertTrue( $capabilities->has_variants );
		$this->assertSame( 100, $capabilities->rate_limit_per_minute );
	}

	public function test_connection_fields_declares_client_credentials_and_oauth_button(): void {
		[ $adapter ] = $this->make_adapter();

		$fields = $adapter->connection_fields();
		$keys   = array_map( static fn( ConnectionField $field ): string => $field->key, $fields );

		$this->assertSame( [ 'client_id', 'client_secret', 'authorize' ], $keys );
		$this->assertSame( 'oauth_button', $fields[2]->type );
	}

	public function test_connection_is_a_failure_before_any_token_is_saved(): void {
		[ $adapter ] = $this->make_adapter();

		$result = $adapter->test_connection();

		$this->assertFalse( $result->ok );
	}

	public function test_connection_succeeds_and_caches_contract_plan(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'a-valid-token' ] );

		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'shop' => [
							'id'            => 'PA000001',
							'title'         => 'sample-shop',
							'contract_plan' => 'premium',
						],
					]
				),
			],
			10,
			3
		);

		$result = $adapter->test_connection();

		$this->assertTrue( $result->ok );
		$this->assertSame( 'sample-shop', $result->shop_name );
		$this->assertTrue( $adapter->capabilities()->can_push_images );
	}

	public function test_connection_does_not_restore_a_stale_token_when_reauthorized_mid_test(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'old-token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		// /shop.json の応答待ちの間にOAuthコールバックが再認可を完了し、新しい
		// トークンを保存したケースをHTTPモック内で再現する。テスト開始時に読んだ
		// 古いペイロードを丸ごと書き戻して再認可を巻き戻さないことを検証する。
		add_filter(
			'pre_http_request',
			static function () use ( $token_store ) {
				$token_store->save( [ 'access_token' => 'newly-issued-token' ] );

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'shop' => [
								'id'            => 'PA000001',
								'contract_plan' => 'premium',
							],
						]
					),
				];
			},
			10,
			3
		);

		$result = $adapter->test_connection();

		$this->assertTrue( $result->ok );
		$this->assertSame( 'newly-issued-token', $token_store->get()['access_token'] );
	}

	public function test_connection_clears_the_cached_plan_when_the_response_omits_it(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'a-valid-token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		// contract_planはShopスキーマ上必須ではない。含まれない成功レスポンスの後も
		// 過去のpremiumキャッシュが残ると、capabilities()が実際には確認できていない
		// 画像アップロード対応を広告し続けるため、キャッシュが破棄されることを検証する。
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'shop' => [
							'id'    => 'PA000001',
							'title' => 'sample-shop',
						],
					]
				),
			],
			10,
			3
		);

		$result = $adapter->test_connection();

		$this->assertTrue( $result->ok );
		$this->assertFalse( $adapter->capabilities()->can_push_images );
	}

	public function test_connection_fails_when_shop_response_is_malformed(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'a-valid-token' ] );

		// プロキシ等がHTTP 200で想定外のJSONを返すケース。`shop.id` を含まない
		// レスポンスを接続成功として扱わないことを検証する。
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => '{}',
			],
			10,
			3
		);

		$result = $adapter->test_connection();

		$this->assertFalse( $result->ok );
	}

	public function test_connection_returns_failure_on_api_error(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'a-revoked-token' ] );

		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 401 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'errors' => [
							[
								'code'    => 401001,
								'message' => 'アクセストークンが無効です。',
								'status'  => 401,
							],
						],
					]
				),
			],
			10,
			3
		);

		$result = $adapter->test_connection();

		$this->assertFalse( $result->ok );
		$this->assertSame( 'アクセストークンが無効です。', $result->message );
	}

	public function test_fetch_and_push_methods_are_not_yet_implemented(): void {
		[ $adapter ] = $this->make_adapter();

		$this->expectException( UnsupportedOperationException::class );

		$adapter->fetch_products( Cursor::start() );
	}
}
