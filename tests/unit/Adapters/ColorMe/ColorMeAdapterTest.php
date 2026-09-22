<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WP_Error;
use WP_UnitTestCase;

final class ColorMeAdapterTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'cbjp_settings_colorme' );
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

	public function test_capabilities_defaults_can_create_order_to_false_when_plan_unknown(): void {
		[ $adapter ] = $this->make_adapter();

		$this->assertFalse( $adapter->capabilities()->can_create_order );
	}

	public function test_capabilities_allows_order_creation_only_on_premium_contract_plan(): void {
		[ $adapter, $token_store ] = $this->make_adapter();

		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->assertTrue( $adapter->capabilities()->can_create_order );
	}

	public function test_capabilities_static_values_match_01_plan_colorme(): void {
		[ $adapter ]  = $this->make_adapter();
		$capabilities = $adapter->capabilities();

		$this->assertFalse( $capabilities->can_create_category );
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

	public function test_connection_reports_failure_and_does_not_restore_a_stale_token_when_reauthorized_mid_test(): void {
		[ $adapter, $token_store, $platform ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'old-token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		// /shop.json の応答待ちの間にOAuthコールバックが再認可を完了し、新しい
		// トークンを保存したケースをHTTPモック内で再現する。本番のコールバックは
		// 別リクエスト＝別TokenStoreインスタンスで保存するため、ここでも同一
		// インスタンスのキャッシュを経由しない別インスタンスで保存する（同一
		// インスタンスのsave()はキャッシュも更新してしまい、バグを検出できない）。
		// テスト開始時に読んだ古いペイロードの丸ごと書き戻しで再認可を
		// 巻き戻さないこと、かつ古いトークンでのテスト結果を成功として
		// 報告しないことを検証する。
		add_filter(
			'pre_http_request',
			static function () use ( $platform ) {
				( new TokenStore( $platform ) )->save( [ 'access_token' => 'newly-issued-token' ] );

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

		$this->assertFalse( $result->ok );
		// 検証も新しいインスタンスで行う（アダプタ側インスタンスのキャッシュに
		// 影響されず、実際に永続化されている値を見る）。
		$this->assertSame( 'newly-issued-token', ( new TokenStore( $platform ) )->get()['access_token'] );
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

	public function test_push_methods_are_not_yet_implemented(): void {
		[ $adapter ] = $this->make_adapter();

		$this->expectException( UnsupportedOperationException::class );

		$adapter->push_category( CanonicalFactory::category( '1', 'Category' ) );
	}

	/**
	 * R3レビュー指摘（Codex）: 税込→税抜の逆算（`ProductTransformer::divide_with_rounding()`）は
	 * `round_down`/`round_up`で税込→税込の順方向（`round_tax()`）と**逆方向**の丸めを使わないと
	 * 往復で元の税込額に戻らない（`php -r`で6000通りのnet/rate組を検証し、順方向と同じ丸めでは
	 * 93%が不一致だった）。税込100円・税率10%・`round_down`設定で、正しい税抜額（91円。
	 * `floor(91×110/100)=100`で往復一致）が送られることを確認する。
	 */
	public function test_push_product_inverts_rounding_direction_for_round_down(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [
					[
						'body' => [
							'shop' => [
								'tax_type'            => 'excluded',
								'tax'                 => 10,
								'reduce_tax_rate'     => 8,
								'tax_rounding_method' => 'round_down',
							],
						],
					],
				],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 503 ] ] ] ],
				'PUT products/503.json' => [ [ 'body' => [ 'product' => [ 'id' => 503 ] ] ] ],
			],
			$captured
		);

		$product = new CanonicalProduct( 'Test Product', 'SKU-1', '100', null, null, [], [], [], [], 5, 'publish' );
		$adapter->push_product( $product, null );

		$create_request = $this->find_captured( $captured, 'POST', 'products.json' );
		$this->assertNotNull( $create_request );
		$this->assertSame( 91, $create_request['body']['product']['sales_price'] );
	}

	public function test_push_product_creates_simple_product(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
				'PUT products/501.json' => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_product( $this->simple_product(), null );

		$this->assertSame( '501', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( [], $result->warnings );
		$this->assertSame( [], $result->variant_remote_ids );

		$create_request = $this->find_captured( $captured, 'POST', 'products.json' );
		$this->assertNotNull( $create_request );
		$this->assertSame( 'Test Product', $create_request['body']['product']['name'] );
		$this->assertSame( 'SKU-1', $create_request['body']['product']['model_number'] );
		$this->assertSame( 1100, $create_request['body']['product']['sales_price'] );
		$this->assertSame( 'showing', $create_request['body']['product']['display_state'] );
		// POSTのペイロードにはcategory_id_small/group_ids/stocksを含めない（swagger実測。
		// 計画参照）。
		$this->assertArrayNotHasKey( 'stocks', $create_request['body']['product'] );

		$this->assertNotNull( $this->find_captured( $captured, 'PUT', 'products/501.json' ) );
	}

	/**
	 * R1レビュー指摘（CLAUDE.mdアーキテクチャ原則9）: 税設定不明で価格を1件も解決できなかった
	 * 場合、無価格のまま`showing`で公開すると実質無料で購入可能になりうる。`display_state`を
	 * `hidden`へ強制し、`PRODUCT_DETAILS_PUSH_INCOMPLETE`（retry対象）を積むことを確認する。
	 */
	public function test_push_product_forces_hidden_when_price_cannot_be_resolved(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				// tax_type未設定＝税設定不明。税込→ColorMe基準の価格換算が常に不能になる。
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 502 ] ] ] ],
				'PUT products/502.json' => [ [ 'body' => [ 'product' => [ 'id' => 502 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_product( $this->simple_product(), null );

		$this->assertSame( [ WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE ], $result->warnings );

		$create_request = $this->find_captured( $captured, 'POST', 'products.json' );
		$this->assertNotNull( $create_request );
		$this->assertSame( 'hidden', $create_request['body']['product']['display_state'] );
		$this->assertArrayNotHasKey( 'price', $create_request['body']['product'] );
		$this->assertArrayNotHasKey( 'sales_price', $create_request['body']['product'] );

		// R3レビュー指摘（Copilot/Codex）: 作成直後の追いPUT（category_id_small/group_ids/stocks
		// 反映用）が`to_update_payload()`の素のshowingでこの安全策を即座に上書きしていた。
		// 追いPUTでもhiddenが維持されることを確認する。
		$follow_up_request = $this->find_captured( $captured, 'PUT', 'products/502.json' );
		$this->assertNotNull( $follow_up_request );
		$this->assertSame( 'hidden', $follow_up_request['body']['product']['display_state'] );
	}

	/**
	 * G2レビュー指摘（Copilot Suppressed comments）: `tax_class`が`null`/`'reduced-rate'`以外
	 * （店舗独自の税区分スラッグ、例: `zero-rate`）の場合、`base_payload()`は`tax_reduced=false`
	 * （標準税率）へフェイルクローズするが、実際には非標準の税区分かもしれない。価格が正しく
	 * 解決できていても、hidden強制と`PRODUCT_DETAILS_PUSH_INCOMPLETE`が働くことを確認する。
	 */
	public function test_push_product_forces_hidden_when_tax_class_is_unsupported(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 505 ] ] ] ],
				'PUT products/505.json' => [ [ 'body' => [ 'product' => [ 'id' => 505 ] ] ] ],
			],
			$captured
		);

		$product = new CanonicalProduct(
			'Zero Rate Product',
			'SKU-Z',
			'1000',
			null,
			null,
			[],
			[],
			[],
			[],
			5,
			'publish',
			[],
			true,
			[],
			null,
			'zero-rate'
		);
		$result  = $adapter->push_product( $product, null );

		$this->assertSame( [ WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE ], $result->warnings );

		$create_request = $this->find_captured( $captured, 'POST', 'products.json' );
		$this->assertNotNull( $create_request );
		$this->assertSame( 'hidden', $create_request['body']['product']['display_state'] );
		// 価格自体は解決できているため送られる（税区分だけが未対応）。
		$this->assertSame( 1000, $create_request['body']['product']['sales_price'] );
	}

	/**
	 * G3レビュー指摘（Copilot Suppressed comments）: 更新（PUT）は新規作成のような
	 * hidden安全策が無いため、`tax_class`が未対応のまま`tax_reduced=false`を送ると、
	 * 既に（恐らく正しく）設定されているColorMe側の税区分を毎回標準税率へ上書きしてしまう。
	 * 更新時は`tax_reduced`フィールド自体を省略し、ColorMe側の既存値を保持することを確認する。
	 */
	public function test_push_product_omits_tax_reduced_on_update_when_tax_class_is_unsupported(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'PUT products/506.json' => [ [ 'body' => [ 'product' => [ 'id' => 506 ] ] ] ],
			],
			$captured
		);

		$product = new CanonicalProduct(
			'Zero Rate Product',
			'SKU-Z',
			'1000',
			null,
			null,
			[],
			[],
			[],
			[],
			5,
			'publish',
			[],
			true,
			[],
			null,
			'zero-rate'
		);
		$result  = $adapter->push_product( $product, '506' );

		// 更新でも警告は積む（checksumをキャッシュさせず再試行対象にする）が、既存の
		// ColorMe側税区分を上書きしない。
		$this->assertSame( [ WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE ], $result->warnings );

		$update_request = $this->find_captured( $captured, 'PUT', 'products/506.json' );
		$this->assertNotNull( $update_request );
		$this->assertArrayNotHasKey( 'tax_reduced', $update_request['body']['product'] );
	}

	public function test_push_product_updates_existing_simple_product_with_a_single_put(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'PUT products/777.json' => [ [ 'body' => [ 'product' => [ 'id' => 777 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_product( $this->simple_product(), '777' );

		$this->assertSame( '777', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_UPDATED, $result->operation );
		$this->assertSame( [], $result->warnings );

		// 更新は1回のPUTのみ（作成時のような追いPUTは行わない）。POSTは一切呼ばれない。
		$put_requests = array_filter( $captured, static fn ( array $request ): bool => 'PUT' === $request['method'] );
		$this->assertCount( 1, $put_requests );
		$this->assertNull( $this->find_captured( $captured, 'POST', 'products.json' ) );
	}

	/**
	 * R2レビュー指摘: 価格を1件も解決できない場合の`display_state=hidden`強制
	 * （`ProductTransformer::to_create_payload()`）は**新規作成のみ**に適用する。更新時にも
	 * 同じ判定を適用すると、既に公開・販売中の商品が価格未解決のたびに（税設定取得の一時的な
	 * 失敗等でも）毎回非公開化されてしまい、安全上の利得が無いまま機会損失だけが生じる。
	 */
	public function test_push_product_does_not_force_hidden_on_update_when_price_cannot_be_resolved(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [] ] ] ],
				'PUT products/778.json' => [ [ 'body' => [ 'product' => [ 'id' => 778 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_product( $this->simple_product(), '778' );

		$this->assertSame( [ WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE ], $result->warnings );

		$update_request = $this->find_captured( $captured, 'PUT', 'products/778.json' );
		$this->assertNotNull( $update_request );
		// Wooの商品ステータスが`publish`のため、価格未解決でも`showing`のまま送る
		// （既存のColorMe側公開状態を毎回非公開へ落とさない）。
		$this->assertSame( 'showing', $update_request['body']['product']['display_state'] );
		$this->assertArrayNotHasKey( 'price', $update_request['body']['product'] );
		$this->assertArrayNotHasKey( 'sales_price', $update_request['body']['product'] );
	}

	public function test_push_product_creates_variable_product_and_syncs_variant_remote_ids(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'                       => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'                  => [ [ 'body' => [ 'product' => [ 'id' => 900 ] ] ] ],
				'PUT products/900.json'               => [ [ 'body' => [ 'product' => [ 'id' => 900 ] ] ] ],
				// 1回目: まだ軸/バリエーションが存在しない状態。2回目: 軸追加後に自動生成された状態。
				'GET products/900.json'               => [
					[
						'body' => [
							'product' => [
								'id'       => 900,
								'options'  => [],
								'variants' => [],
							],
						],
					],
					[
						'body' => [
							'product' => [
								'id'       => 900,
								'options'  => [],
								'variants' => [
									[
										'id'            => 9001,
										'option1_value' => 'Red',
										'option2_value' => null,
										'option1'       => [
											'id'    => 1,
											'name'  => 'Color',
											'value' => 'Red',
										],
										'option2'       => null,
									],
									[
										'id'            => 9002,
										'option1_value' => 'Blue',
										'option2_value' => null,
										'option1'       => [
											'id'    => 1,
											'name'  => 'Color',
											'value' => 'Blue',
										],
										'option2'       => null,
									],
								],
							],
						],
					],
				],
				'POST products/900/options.json'      => [
					[
						'body'   => [ 'option' => [ 'id' => 1 ] ],
						'status' => 201,
					],
				],
				'PUT products/900/variants/9001.json' => [ [ 'body' => [ 'variant' => [ 'id' => 9001 ] ] ] ],
				'PUT products/900/variants/9002.json' => [ [ 'body' => [ 'variant' => [ 'id' => 9002 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_product( $this->variable_product(), null );

		$this->assertSame( '900', $result->remote_id );
		$this->assertSame( [], $result->warnings );
		$this->assertSame( [ '9001', '9002' ], $result->variant_remote_ids );

		// swagger実測: POST /options の values はオブジェクトの配列（GET応答側の文字列配列とは
		// リクエスト/レスポンスでスキーマが異なる）。
		$option_request = $this->find_captured( $captured, 'POST', 'products/900/options.json' );
		$this->assertNotNull( $option_request );
		$this->assertSame( 'Color', $option_request['body']['option']['name'] );
		$this->assertSame( [ [ 'name' => 'Red' ], [ 'name' => 'Blue' ] ], $option_request['body']['option']['values'] );

		$variant1_request = $this->find_captured( $captured, 'PUT', 'products/900/variants/9001.json' );
		$this->assertNotNull( $variant1_request );
		$this->assertSame( 'VAR-RED', $variant1_request['body']['variant']['model_number'] );
	}

	/**
	 * R1レビュー指摘: `ensure_option_values()`は軸を名前で解決するため、ColorMe側の既存
	 * オプションのスロット割当（作成順で決まる）とWoo側の軸抽出順が一致する保証は無い。
	 * ここではリモート側で軸のスロットが逆（option1=Size, option2=Color）になっている状態を
	 * 用意し、`option1_value`/`option2_value`のスロット位置ではなく`{軸名: 値}`で正しく
	 * 突合できることを確認する。
	 */
	public function test_push_product_matches_variants_by_axis_name_even_when_remote_slot_order_differs(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product = new CanonicalProduct(
			'Two Axis Product',
			null,
			'2200',
			null,
			null,
			[],
			[
				[
					'remote_id'     => '',
					'sku'           => 'VAR-RED-S',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'option2_name'  => 'Size',
					'option2_value' => 'S',
					'price'         => '2200',
					'stock'         => 3,
					'weight'        => null,
				],
			],
			[],
			[],
			null,
			'publish'
		);

		$this->mock_push_requests(
			[
				'GET shop.json'                       => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'                  => [ [ 'body' => [ 'product' => [ 'id' => 950 ] ] ] ],
				'PUT products/950.json'               => [ [ 'body' => [ 'product' => [ 'id' => 950 ] ] ] ],
				'GET products/950.json'               => [
					[
						'body' => [
							'product' => [
								'id'       => 950,
								'options'  => [],
								'variants' => [],
							],
						],
					],
					[
						'body' => [
							'product' => [
								'id'       => 950,
								'options'  => [],
								'variants' => [
									[
										// リモート側はoption1=Size, option2=Colorという逆順で自動生成された想定。
										'id'            => 9101,
										'option1_value' => 'S',
										'option2_value' => 'Red',
										'option1'       => [
											'id'    => 2,
											'name'  => 'Size',
											'value' => 'S',
										],
										'option2'       => [
											'id'    => 1,
											'name'  => 'Color',
											'value' => 'Red',
										],
									],
								],
							],
						],
					],
				],
				'POST products/950/options.json'      => [
					[
						'body'   => [ 'option' => [ 'id' => 1 ] ],
						'status' => 201,
					],
				],
				'PUT products/950/variants/9101.json' => [ [ 'body' => [ 'variant' => [ 'id' => 9101 ] ] ] ],
			]
		);

		$result = $adapter->push_product( $product, null );

		$this->assertSame( [], $result->warnings );
		$this->assertSame( [ '9101' ], $result->variant_remote_ids );
	}

	/**
	 * R1レビュー指摘: ColorMeはオプション追加で全組み合わせ（直積）を自動生成するため、
	 * Woo側に対応するバリエーションが無い組み合わせがリモートに残りうる（原則4により
	 * こちらから削除できない）。無警告のままだと店舗オーナーが気付けないため、
	 * `PRODUCT_VARIANT_SURPLUS_ON_REMOTE`（retry対象外の情報提供のみ）を積むことを確認する。
	 */
	public function test_push_product_warns_when_remote_has_surplus_variant_combinations(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'GET shop.json'                       => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'                  => [ [ 'body' => [ 'product' => [ 'id' => 960 ] ] ] ],
				'PUT products/960.json'               => [ [ 'body' => [ 'product' => [ 'id' => 960 ] ] ] ],
				'GET products/960.json'               => [
					[
						'body' => [
							'product' => [
								'id'       => 960,
								'options'  => [],
								'variants' => [],
							],
						],
					],
					[
						'body' => [
							'product' => [
								'id'       => 960,
								'options'  => [],
								// Wooはvariants=[Red]のみだが、ColorMe側は直積でRed/Blueの2件が
								// 自動生成された想定（Blueは対応するWooバリエーションが無いまま残る）。
								'variants' => [
									[
										'id'            => 9201,
										'option1_value' => 'Red',
										'option2_value' => null,
										'option1'       => [
											'id'    => 1,
											'name'  => 'Color',
											'value' => 'Red',
										],
										'option2'       => null,
									],
									[
										'id'            => 9202,
										'option1_value' => 'Blue',
										'option2_value' => null,
										'option1'       => [
											'id'    => 1,
											'name'  => 'Color',
											'value' => 'Blue',
										],
										'option2'       => null,
									],
								],
							],
						],
					],
				],
				'POST products/960/options.json'      => [
					[
						'body'   => [ 'option' => [ 'id' => 1 ] ],
						'status' => 201,
					],
				],
				'PUT products/960/variants/9201.json' => [ [ 'body' => [ 'variant' => [ 'id' => 9201 ] ] ] ],
			]
		);

		$product = new CanonicalProduct(
			'Single Variant Product',
			null,
			'2200',
			null,
			null,
			[],
			[
				[
					'remote_id'     => '',
					'sku'           => 'VAR-RED',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'option2_name'  => null,
					'option2_value' => null,
					'price'         => '2200',
					'stock'         => 3,
					'weight'        => null,
				],
			],
			[],
			[],
			null,
			'publish'
		);

		$result = $adapter->push_product( $product, null );

		$this->assertSame( [ WarningCode::PRODUCT_VARIANT_SURPLUS_ON_REMOTE ], $result->warnings );
		$this->assertSame( [ '9201' ], $result->variant_remote_ids );
	}

	/**
	 * R2/G3レビュー指摘: 2軸が同じラベルを持つ場合（`Woo\Support\VariationAxisResolver::
	 * attribute_label()`はラベル重複を排除しない。例: グローバル属性とローカル属性が両方
	 * 「Color」）、`{軸名: 値}`マップの素朴な組み立てだと後勝ちで片方の軸が消え、異なる値を持つ
	 * 複数バリエーションが同じキーに衝突しうる。誤対応付け（SKU/価格/在庫が別バリエーションへ
	 * 入れ替わってpushされる）を避けるため衝突検出時は突合を諦める。さらにG3では、最終突合の
	 * 時点まで検出を遅らせるとColorMe側に`POST /options`で余剰なオプション・直積バリエーション
	 * を作成してしまう（原則4により削除不可）ため、軸名一致は`ensure_option_values()`を呼ぶ
	 * **前**に検出し、リモートを一切変更しないことを確認する（`POST /options.json`が
	 * 1回も呼ばれない）。
	 */
	public function test_push_product_fails_closed_when_two_axes_share_the_same_name(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product = new CanonicalProduct(
			'Colliding Axis Product',
			null,
			'2200',
			null,
			null,
			[],
			[
				[
					'remote_id'     => '',
					'sku'           => 'VAR-A',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'option2_name'  => 'Color',
					'option2_value' => 'S',
					'price'         => '2200',
					'stock'         => 3,
					'weight'        => null,
				],
				[
					'remote_id'     => '',
					'sku'           => 'VAR-B',
					'option1_name'  => 'Color',
					'option1_value' => 'Blue',
					'option2_name'  => 'Color',
					'option2_value' => 'S',
					'price'         => '2200',
					'stock'         => 3,
					'weight'        => null,
				],
			],
			[],
			[],
			null,
			'publish'
		);

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 970 ] ] ] ],
				'PUT products/970.json' => [ [ 'body' => [ 'product' => [ 'id' => 970 ] ] ] ],
				'GET products/970.json' => [
					[
						'body' => [
							'product' => [
								'id'       => 970,
								'options'  => [],
								'variants' => [],
							],
						],
					],
				],
			],
			$captured
		);

		$result = $adapter->push_product( $product, null );

		// 軸名の衝突はWoo側の属性ラベル設定を直さない限り再試行しても解決しない終端状態のため
		// `PRODUCT_VARIANT_PUSH_FAILED`（retry対象外）になる。
		$this->assertSame( [ WarningCode::PRODUCT_VARIANT_PUSH_FAILED ], $result->warnings );
		$this->assertSame( [ '', '' ], $result->variant_remote_ids );
		// `ensure_option_values()`を呼ぶ前に検出するため、`POST /options`でColorMe側へ
		// 余剰なオプション・直積バリエーションを作成すること自体が起きない。
		$this->assertNull( $this->find_captured( $captured, 'POST', 'products/970/options.json' ) );
	}

	/**
	 * G2レビュー指摘（Copilot）: Wooの「Any <属性>」ワイルドカード（値が空文字列→`option2_value`
	 * がnullに変換される。CLAUDE.md既知の変換）は、属性自体は割り当てられているため
	 * `option2_name`は非nullのまま残る「部分指定」になる。従来はこの部分指定を無視して
	 * `{Color: Red}`のような1軸相当のキーに潰しており、option2の値が異なる複数の
	 * バリエーションが同じキーに衝突しうる。フェイルクローズ（未確定のまま）することを確認する。
	 */
	public function test_push_product_fails_closed_when_axis_has_a_name_without_a_value(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product = new CanonicalProduct(
			'Any Size Product',
			null,
			'2200',
			null,
			null,
			[],
			[
				[
					'remote_id'     => '',
					'sku'           => 'VAR-A',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					// Wooの「Any サイズ」ワイルドカード: 属性(option2_name)は割り当てられているが
					// 値(option2_value)は空文字列→nullに変換される（部分指定）。
					'option2_name'  => 'Size',
					'option2_value' => null,
					'price'         => '2200',
					'stock'         => 3,
					'weight'        => null,
				],
			],
			[],
			[],
			null,
			'publish'
		);

		$this->mock_push_requests(
			[
				'GET shop.json'                  => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'             => [ [ 'body' => [ 'product' => [ 'id' => 971 ] ] ] ],
				'PUT products/971.json'          => [ [ 'body' => [ 'product' => [ 'id' => 971 ] ] ] ],
				'GET products/971.json'          => [
					[
						'body' => [
							'product' => [
								'id'       => 971,
								'options'  => [],
								'variants' => [],
							],
						],
					],
				],
				'POST products/971/options.json' => [
					[
						'body'   => [ 'option' => [ 'id' => 1 ] ],
						'status' => 201,
					],
				],
			]
		);

		$result = $adapter->push_product( $product, null );

		$this->assertSame( [ WarningCode::PRODUCT_VARIANT_PUSH_FAILED ], $result->warnings );
		$this->assertSame( [ '' ], $result->variant_remote_ids );
	}

	/**
	 * 422（入力エラー）は再試行しても解決しない終端状態のため`PRODUCT_VARIANT_PUSH_FAILED`
	 * （retry対象外）になる。R1レビュー指摘: 4xxもretry対象に含めると恒久的な失敗が毎回
	 * 同じ無駄なリクエスト列を繰り返してしまう。
	 */
	public function test_push_product_marks_variant_sync_failed_when_option_creation_gets_a_terminal_error(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'GET shop.json'                  => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'             => [ [ 'body' => [ 'product' => [ 'id' => 901 ] ] ] ],
				'PUT products/901.json'          => [ [ 'body' => [ 'product' => [ 'id' => 901 ] ] ] ],
				'GET products/901.json'          => [
					[
						'body' => [
							'product' => [
								'id'       => 901,
								'options'  => [],
								'variants' => [],
							],
						],
					],
				],
				'POST products/901/options.json' => [
					[
						'body'   => [
							'errors' => [
								[
									'code'    => 422043,
									'message' => 'invalid',
									'status'  => 422,
								],
							],
						],
						'status' => 422,
					],
				],
			]
		);

		$result = $adapter->push_product( $this->variable_product(), null );

		// 商品本体は作成済み（remote_id確定）のまま、バリエーションだけが未確定で返る。
		$this->assertSame( '901', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( [ '', '' ], $result->variant_remote_ids );
		$this->assertSame( [ WarningCode::PRODUCT_VARIANT_PUSH_FAILED ], $result->warnings );
	}

	/**
	 * 5xx（サーバー側の一時的な障害）は再試行対象の`PRODUCT_VARIANT_PUSH_INCOMPLETE`になる
	 * （`Exporter`がchecksumをキャッシュせず次回exportで自動的に再試行する）。
	 */
	public function test_push_product_marks_variant_sync_incomplete_when_option_creation_gets_a_retryable_error(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'GET shop.json'                  => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'             => [ [ 'body' => [ 'product' => [ 'id' => 903 ] ] ] ],
				'PUT products/903.json'          => [ [ 'body' => [ 'product' => [ 'id' => 903 ] ] ] ],
				'GET products/903.json'          => [
					[
						'body' => [
							'product' => [
								'id'       => 903,
								'options'  => [],
								'variants' => [],
							],
						],
					],
				],
				'POST products/903/options.json' => [
					[
						'body'   => [],
						'status' => 500,
					],
				],
			]
		);

		$result = $adapter->push_product( $this->variable_product(), null );

		$this->assertSame( '903', $result->remote_id );
		$this->assertSame( [ WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE ], $result->warnings );
	}

	/**
	 * R3レビュー指摘（Codex）: `GET /products/{id}`が200応答でも`product`エンベロープが
	 * 欠損・非配列（スキーマ崩壊）の場合、従来は`$failure`に何も記録せず
	 * `sync_variants()`が警告を一切積まないまま親のchecksumだけがキャッシュされ、
	 * バリエーション未同期が恒久的に再試行されなくなっていた。retryableな警告
	 * （`PRODUCT_VARIANT_PUSH_INCOMPLETE`）が積まれることを確認する。
	 */
	public function test_push_product_marks_variant_sync_incomplete_when_detail_response_is_malformed(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 904 ] ] ] ],
				'PUT products/904.json' => [ [ 'body' => [ 'product' => [ 'id' => 904 ] ] ] ],
				// product情報自体が欠損した200応答（スキーマ崩壊を模す）。
				'GET products/904.json' => [ [ 'body' => [ 'unexpected' => true ] ] ],
			]
		);

		$result = $adapter->push_product( $this->variable_product(), null );

		$this->assertSame( '904', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( [ WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE ], $result->warnings );
		$this->assertSame( [ '', '' ], $result->variant_remote_ids );
	}

	/**
	 * G2レビュー指摘（Copilot Suppressed comments）: `fetch_product_detail()`は`product`
	 * エンベロープの欠損は検証するが、ネストされた`variants`フィールド自体の欠損・非配列は
	 * 検証していなかった。正当な「バリエーション0件」（`variants: []`）と区別できず、
	 * スキーマ崩壊時も無警告で空配列扱いになり、全バリエーションが終端警告
	 * （`PRODUCT_VARIANT_PUSH_FAILED`）のまま親のchecksumがキャッシュされてしまっていた。
	 * retryableな警告（`PRODUCT_VARIANT_PUSH_INCOMPLETE`）になることを確認する。
	 */
	public function test_push_product_marks_variant_sync_incomplete_when_variants_field_is_missing(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'GET shop.json'                  => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'             => [ [ 'body' => [ 'product' => [ 'id' => 905 ] ] ] ],
				'PUT products/905.json'          => [ [ 'body' => [ 'product' => [ 'id' => 905 ] ] ] ],
				'GET products/905.json'          => [
					// 1回目: 正常な「バリエーション0件」状態。
					[
						'body' => [
							'product' => [
								'id'       => 905,
								'options'  => [],
								'variants' => [],
							],
						],
					],
					// 2回目: `variants`キー自体が欠損（スキーマ崩壊を模す）。
					[
						'body' => [
							'product' => [
								'id'      => 905,
								'options' => [],
							],
						],
					],
				],
				'POST products/905/options.json' => [
					[
						'body'   => [ 'option' => [ 'id' => 1 ] ],
						'status' => 201,
					],
				],
			]
		);

		$result = $adapter->push_product( $this->variable_product(), null );

		$this->assertSame( [ WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE ], $result->warnings );
		$this->assertSame( [ '', '' ], $result->variant_remote_ids );
	}

	public function test_push_product_pushes_images_when_premium_plan(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'                          => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'                     => [ [ 'body' => [ 'product' => [ 'id' => 600 ] ] ] ],
				'PUT products/600.json'                  => [ [ 'body' => [ 'product' => [ 'id' => 600 ] ] ] ],
				'GET https://cdn.example.test/photo.jpg' => [ [ 'raw_body' => 'FAKE-JPEG-BYTES' ] ],
				'POST products/600/images.json'          => [
					[
						'body'   => [
							'product_image' => [
								'position' => 0,
								'url'      => 'https://cdn.example.test/photo.jpg',
							],
						],
						'status' => 201,
					],
				],
			],
			$captured
		);

		$product = $this->simple_product(
			[
				[
					'src'      => 'https://cdn.example.test/photo.jpg',
					'position' => 0,
				],
			]
		);
		$result  = $adapter->push_product( $product, null );

		$this->assertSame( [], $result->warnings );

		$image_request = $this->find_captured( $captured, 'POST', 'products/600/images.json' );
		$this->assertNotNull( $image_request );
		$this->assertStringContainsString( 'FAKE-JPEG-BYTES', (string) $image_request['raw'] );
		$this->assertStringContainsString( 'name="position"', (string) $image_request['raw'] );
	}

	public function test_push_product_marks_images_not_pushed_when_plan_is_not_premium(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 601 ] ] ] ],
				'PUT products/601.json' => [ [ 'body' => [ 'product' => [ 'id' => 601 ] ] ] ],
			]
		);

		$product = $this->simple_product(
			[
				[
					'src'      => 'https://cdn.example.test/photo.jpg',
					'position' => 0,
				],
			]
		);
		$result  = $adapter->push_product( $product, null );

		$this->assertSame( [ WarningCode::PRODUCT_IMAGES_NOT_PUSHED ], $result->warnings );
	}

	/**
	 * 404（Wooサイト自身から添付が削除された等）は再試行しても解決しない終端状態のため
	 * `PRODUCT_IMAGE_PUSH_FAILED`（retry対象外）になる（R3レビュー指摘, Copilot Suppressed
	 * comments）。
	 */
	public function test_push_product_marks_image_push_failed_when_download_gets_a_terminal_error(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->mock_push_requests(
			[
				'GET shop.json'                            => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'                       => [ [ 'body' => [ 'product' => [ 'id' => 602 ] ] ] ],
				'PUT products/602.json'                    => [ [ 'body' => [ 'product' => [ 'id' => 602 ] ] ] ],
				'GET https://cdn.example.test/missing.jpg' => [
					[
						'body'   => [],
						'status' => 404,
					],
				],
			]
		);

		$product = $this->simple_product(
			[
				[
					'src'      => 'https://cdn.example.test/missing.jpg',
					'position' => 0,
				],
			]
		);
		$result  = $adapter->push_product( $product, null );

		$this->assertSame( [ WarningCode::PRODUCT_IMAGE_PUSH_FAILED ], $result->warnings );
	}

	/**
	 * 5xx（Wooサイト側の一時的な障害）は再試行対象の`PRODUCT_IMAGE_PUSH_INCOMPLETE`になる。
	 */
	public function test_push_product_marks_image_push_incomplete_when_download_gets_a_retryable_error(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->mock_push_requests(
			[
				'GET shop.json'                          => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'                     => [ [ 'body' => [ 'product' => [ 'id' => 603 ] ] ] ],
				'PUT products/603.json'                  => [ [ 'body' => [ 'product' => [ 'id' => 603 ] ] ] ],
				'GET https://cdn.example.test/flaky.jpg' => [
					[
						'body'   => [],
						'status' => 503,
					],
				],
			]
		);

		$product = $this->simple_product(
			[
				[
					'src'      => 'https://cdn.example.test/flaky.jpg',
					'position' => 0,
				],
			]
		);
		$result  = $adapter->push_product( $product, null );

		$this->assertSame( [ WarningCode::PRODUCT_IMAGE_PUSH_INCOMPLETE ], $result->warnings );
	}

	/**
	 * G2レビュー指摘（Codex/Copilot）: 200応答でも本文が空（プロキシ異常等）の場合、従来は
	 * `\$failure`へ何も記録せずnullを返していたため、警告が一切積まれず親商品のchecksumが
	 * キャッシュされ画像が永久にpushされなくなっていた。retryableな警告が積まれることを確認する。
	 */
	public function test_push_product_marks_image_push_incomplete_when_body_is_empty(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->mock_push_requests(
			[
				'GET shop.json'                          => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'                     => [ [ 'body' => [ 'product' => [ 'id' => 604 ] ] ] ],
				'PUT products/604.json'                  => [ [ 'body' => [ 'product' => [ 'id' => 604 ] ] ] ],
				'GET https://cdn.example.test/empty.jpg' => [
					[
						'raw_body' => '',
						'status'   => 200,
					],
				],
			]
		);

		$product = $this->simple_product(
			[
				[
					'src'      => 'https://cdn.example.test/empty.jpg',
					'position' => 0,
				],
			]
		);
		$result  = $adapter->push_product( $product, null );

		$this->assertSame( [ WarningCode::PRODUCT_IMAGE_PUSH_INCOMPLETE ], $result->warnings );
	}

	public function test_push_customer_creates_new_customer_with_a_single_post(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'POST customers.json' => [ [ 'body' => [ 'customer' => [ 'id' => 701 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_customer( $this->exported_customer(), null );

		$this->assertSame( '701', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( [], $result->warnings );

		$create_request = $this->find_captured( $captured, 'POST', 'customers.json' );
		$this->assertNotNull( $create_request );
		$this->assertSame( 'taro@example.com', $create_request['body']['customer']['mail'] );
		$this->assertSame( 13, $create_request['body']['customer']['pref_id'] );
		// `city`（WCのJPロケールでは`address_1`と別の必須項目）が連結されていることを確認する
		// （R1レビューで判明: 無視すると市区町村がまるごと欠落する）。
		$this->assertSame( '千代田区千代田1-1-1', $create_request['body']['customer']['address1'] );
		$this->assertTrue( $create_request['body']['customer']['add_member'] );
	}

	public function test_push_customer_updates_existing_customer_with_a_single_put(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'PUT customers/701.json' => [ [ 'body' => [ 'customer' => [ 'id' => 701 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_customer( $this->exported_customer(), '701' );

		$this->assertSame( '701', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_UPDATED, $result->operation );

		$update_request = $this->find_captured( $captured, 'PUT', 'customers/701.json' );
		$this->assertNotNull( $update_request );
		$this->assertArrayNotHasKey( 'add_member', $update_request['body']['customer'] );
	}

	/**
	 * 新規作成に必須の`pref_id`/`postal`/`address1`/`tel`をWoo顧客の請求先情報から解決できない場合、
	 * 送信すると確実に422になるためAPIを一切呼ばずスキップする（フェイルクローズ）。
	 */
	public function test_push_customer_skips_creation_when_required_fields_are_missing(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests( [], $captured );

		$result = $adapter->push_customer( CanonicalFactory::customer( '999', 'taro@example.com' ), null );

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING ], $result->warnings );
		$this->assertSame( [], $captured );
	}

	public function test_push_customer_throws_when_response_is_missing_the_customer_id(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'POST customers.json' => [ [ 'body' => [ 'customer' => [] ] ] ],
			]
		);

		$this->expectException( RuntimeException::class );

		$adapter->push_customer( $this->exported_customer(), null );
	}

	/**
	 * ColorMeの`PUT /sales/{id}`は入金状態・配送情報の一部しか更新できず、明細・決済/配送方法の
	 * 変更はできない。再`POST /sales`すると重複した受注が作成されてしまうため、既に
	 * エクスポート済み（`$remote_id`が非null）の受注はAPIを一切呼ばずスキップすることを確認する。
	 */
	public function test_push_order_skips_when_already_exported(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests( [], $captured );

		$result = $adapter->push_order( $this->exported_order(), '12345' );

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ WarningCode::ORDER_UPDATE_NOT_SUPPORTED ], $result->warnings );
		$this->assertSame( [], $captured );
	}

	/**
	 * 既にエクスポート済みの受注に割引が付いている場合も、`ORDER_UPDATE_NOT_SUPPORTED`だけでなく
	 * `ORDER_DISCOUNT_NOT_PUSHED`も積むことを確認する（Copilotレビュー指摘: 当初はこの早期return
	 * 経路で割引情報が一切伝わらなかった）。`OrderTransformer::has_discount()`はAPIを呼ばない
	 * 純粋な判定のため、APIが一切呼ばれないことも合わせて確認する。
	 */
	public function test_push_order_flags_discount_not_pushed_when_already_exported(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests( [], $captured );

		$result = $adapter->push_order( $this->exported_order( '500' ), '12345' );

		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame(
			[ WarningCode::ORDER_UPDATE_NOT_SUPPORTED, WarningCode::ORDER_DISCOUNT_NOT_PUSHED ],
			$result->warnings
		);
		$this->assertSame( [], $captured );
	}

	/**
	 * 割引と同様、既にエクスポート済みの受注に決済手数料・送料が付いている場合も
	 * `ORDER_FEE_NOT_PUSHED`を積むことを確認する（Codexレビュー指摘）。
	 */
	public function test_push_order_flags_fee_not_pushed_when_already_exported(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests( [], $captured );

		$result = $adapter->push_order( $this->exported_order( '0', '500' ), '12345' );

		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame(
			[ WarningCode::ORDER_UPDATE_NOT_SUPPORTED, WarningCode::ORDER_FEE_NOT_PUSHED ],
			$result->warnings
		);
		$this->assertSame( [], $captured );
	}

	/**
	 * 決済/配送方法が一意に解決でき、配送先住所も揃っている正常系。過去のWoo受注を複製する
	 * のであって新規注文ではないため`reserve_stocks=false`を指定することも確認する。
	 */
	public function test_push_order_creates_new_order_with_resolved_payment_and_shipping(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		update_option(
			'cbjp_settings_colorme',
			[
				'payment_map'  => [ '751' => 'bacs' ],
				'shipping_map' => [ '640580' => 'flat_rate:6' ],
			]
		);

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'   => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'excluded' ] ] ] ],
				'POST sales.json' => [ [ 'body' => [ 'sale' => [ 'id' => 88001 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_order( $this->exported_order(), null );

		$this->assertSame( '88001', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		// `POST /v1/sales`に受注日時を指定するフィールドが無いため、新規作成成功時は常に
		// `ORDER_PLACED_AT_NOT_PRESERVED`が付く。
		$this->assertSame( [ WarningCode::ORDER_PLACED_AT_NOT_PRESERVED ], $result->warnings );

		$create_request = $this->find_captured( $captured, 'POST', 'sales.json' );
		$this->assertNotNull( $create_request );
		$this->assertStringContainsString( 'reserve_stocks=false', $create_request['url'] );
		$this->assertSame( 751, $create_request['body']['sale']['payment_id'] );
		$this->assertSame( 640580, $create_request['body']['sale']['sale_deliveries'][0]['delivery_id'] );
		$this->assertSame( 5001, $create_request['body']['sale']['details'][0]['product_id'] );
		$this->assertSame( [ 'id' => 9001 ], $create_request['body']['sale']['customer'] );

		// push方向はimport専用の名称マップ取得（payments.json/deliveries.json）を一切叩かないことを
		// 確認する（共有order_transformer()経由だと無駄な2リクエストが発生していた）。
		$this->assertNull( $this->find_captured( $captured, 'GET', 'payments.json' ) );
		$this->assertNull( $this->find_captured( $captured, 'GET', 'deliveries.json' ) );
	}

	/**
	 * `POST /v1/sales`のリクエストスキーマに割引・クーポン額を運ぶフィールドが無いため、
	 * 作成自体は成功させつつ`ORDER_DISCOUNT_NOT_PUSHED`を情報提供として積むことを確認する。
	 */
	public function test_push_order_flags_discount_not_pushed_on_successful_create(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		update_option(
			'cbjp_settings_colorme',
			[
				'payment_map'  => [ '751' => 'bacs' ],
				'shipping_map' => [ '640580' => 'flat_rate:6' ],
			]
		);

		$this->mock_push_requests(
			[
				'GET shop.json'   => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'excluded' ] ] ] ],
				'POST sales.json' => [ [ 'body' => [ 'sale' => [ 'id' => 88002 ] ] ] ],
			]
		);

		$result = $adapter->push_order( $this->exported_order( '500' ), null );

		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		$this->assertSame(
			[ WarningCode::ORDER_DISCOUNT_NOT_PUSHED, WarningCode::ORDER_PLACED_AT_NOT_PRESERVED ],
			$result->warnings
		);
	}

	/**
	 * `POST /v1/sales`のリクエストスキーマに決済手数料・送料を運ぶフィールドが無いため、作成自体は
	 * 成功させつつ`ORDER_FEE_NOT_PUSHED`を情報提供として積むことを確認する。
	 */
	public function test_push_order_flags_fee_not_pushed_on_successful_create(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		update_option(
			'cbjp_settings_colorme',
			[
				'payment_map'  => [ '751' => 'bacs' ],
				'shipping_map' => [ '640580' => 'flat_rate:6' ],
			]
		);

		$this->mock_push_requests(
			[
				'GET shop.json'   => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'excluded' ] ] ] ],
				'POST sales.json' => [ [ 'body' => [ 'sale' => [ 'id' => 88003 ] ] ] ],
			]
		);

		$result = $adapter->push_order( $this->exported_order( '0', '500' ), null );

		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		$this->assertSame(
			[ WarningCode::ORDER_FEE_NOT_PUSHED, WarningCode::ORDER_PLACED_AT_NOT_PRESERVED ],
			$result->warnings
		);
	}

	/**
	 * `payment_map`/`shipping_map`にWoo側IDへ一意に対応するASP側IDが無い場合、`sale.payment_id`/
	 * `sale.sale_deliveries[].delivery_id`必須のため受注全体をpushしない（D19）。
	 */
	public function test_push_order_skips_when_payment_and_shipping_are_unmapped(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json' => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'excluded' ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_order( $this->exported_order(), null );

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame(
			[
				WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, 'bacs' ),
				WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, 'flat_rate:6' ),
			],
			$result->warnings
		);
		$this->assertNull( $this->find_captured( $captured, 'POST', 'sales.json' ) );
	}

	public function test_push_order_throws_when_response_is_missing_the_sale_id(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		update_option(
			'cbjp_settings_colorme',
			[
				'payment_map'  => [ '751' => 'bacs' ],
				'shipping_map' => [ '640580' => 'flat_rate:6' ],
			]
		);

		$this->mock_push_requests(
			[
				'GET shop.json'   => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'excluded' ] ] ] ],
				'POST sales.json' => [ [ 'body' => [ 'sale' => [] ] ] ],
			]
		);

		$this->expectException( RuntimeException::class );

		$adapter->push_order( $this->exported_order(), null );
	}

	public function test_push_stock_updates_simple_product_when_quantity_is_managed(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'PUT products/501.json' => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_stock( new CanonicalStock( '501', null, 'SKU-1', 12, true ) );

		$this->assertSame( '501', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_UPDATED, $result->operation );
		$this->assertSame( [], $result->warnings );

		$request = $this->find_captured( $captured, 'PUT', 'products/501.json' );
		$this->assertNotNull( $request );
		$this->assertSame(
			[
				'stock_managed' => true,
				'stocks'        => 12,
			],
			$request['body']['product']
		);
	}

	public function test_push_stock_clears_stock_managed_on_simple_product_when_quantity_is_unmanaged(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'PUT products/501.json' => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
			],
			$captured
		);

		$adapter->push_stock( new CanonicalStock( '501', null, 'SKU-1', null, true ) );

		$request = $this->find_captured( $captured, 'PUT', 'products/501.json' );
		$this->assertNotNull( $request );
		$this->assertSame( [ 'stock_managed' => false ], $request['body']['product'] );
	}

	public function test_push_stock_updates_variant_when_quantity_is_managed(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'PUT products/501/variants/701.json' => [ [ 'body' => [ 'variant' => [ 'id' => 701 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_stock( new CanonicalStock( '501', '701', 'SKU-1-RED', 3, true ) );

		$this->assertSame( '701', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_UPDATED, $result->operation );

		$request = $this->find_captured( $captured, 'PUT', 'products/501/variants/701.json' );
		$this->assertNotNull( $request );
		$this->assertSame( [ 'stocks' => 3 ], $request['body']['variant'] );
	}

	/**
	 * カラーミーのバリエーション更新スキーマ（`productVariantUpdateRequest`）には商品レベルの
	 * `stock_managed`に相当するフィールドが無いため、「個別管理しない」状態を送る手段が無い。
	 * 未確認の挙動に賭けず、APIを一切呼ばずフェイルクローズすることを確認する。
	 */
	public function test_push_stock_skips_variant_without_calling_api_when_quantity_is_unmanaged(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests( [], $captured );

		$result = $adapter->push_stock( new CanonicalStock( '501', '701', 'SKU-1-RED', null, true ) );

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ WarningCode::STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE ], $result->warnings );
		$this->assertSame( [], $captured );
	}

	private function exported_order( string $discount = '0', string $shipping_fee = '0' ): CanonicalOrder {
		return new CanonicalOrder(
			'1001',
			'processing',
			'9001',
			[
				[
					'sku'                   => 'SKU-1',
					'remote_product_id'     => '5001',
					'option1_value_current' => null,
					'option2_value_current' => null,
					'name'                  => 'Sample product',
					'quantity'              => 2,
					'price'                 => '1100.00',
					'subtotal'              => '2200.00',
					'unit_price_excl_tax'   => '1000.00',
					'tax_reduced'           => false,
				],
			],
			[
				'method_id'   => 'flat_rate:6',
				'method_name' => 'Flat rate',
				'fee'         => $shipping_fee,
				'name'        => '山田 太郎',
				'tel'         => '03-1234-5678',
				'company'     => null,
				'address_1'   => '千代田1-1-1',
				'address_2'   => null,
				'city'        => '千代田区',
				'state'       => 'JP13',
				'postcode'    => '1000001',
				'country'     => 'JP',
			],
			[
				'method_id'   => 'bacs',
				'method_name' => 'Bank transfer',
				'fee'         => '0',
			],
			[
				'discount'     => $discount,
				'shipping_fee' => '500',
				'tax'          => '300',
				'total'        => '3000',
			],
			'2026-01-01T00:00:00+00:00',
			null,
			[
				'customer_snapshot' => [
					'name'      => '山田 太郎',
					'email'     => 'taro@example.com',
					'phone'     => '03-1234-5678',
					'company'   => null,
					'address_1' => '千代田1-1-1',
					'address_2' => null,
					'city'      => '千代田区',
					'state'     => 'JP13',
					'postcode'  => '1000001',
					'country'   => 'JP',
				],
				'paid'              => true,
				'currency'          => 'JPY',
			]
		);
	}

	private function exported_customer(): CanonicalCustomer {
		return new CanonicalCustomer(
			'taro@example.com',
			'山田 太郎',
			'ヤマダ タロウ',
			null,
			null,
			[
				'city'      => '千代田区',
				'address_1' => '千代田1-1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			],
			'0300000001',
			null,
			null,
			null
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $images
	 */
	private function simple_product( array $images = [] ): CanonicalProduct {
		return new CanonicalProduct(
			'Test Product',
			'SKU-1',
			'1100',
			null,
			'Product description',
			$images,
			[],
			[],
			[],
			5,
			'publish',
			[ 'short_description' => 'Short desc' ]
		);
	}

	private function variable_product(): CanonicalProduct {
		return new CanonicalProduct(
			'Variable Product',
			null,
			'2200',
			null,
			null,
			[],
			[
				[
					'remote_id'     => '',
					'sku'           => 'VAR-RED',
					'option1_name'  => 'Color',
					'option1_value' => 'Red',
					'option2_name'  => null,
					'option2_value' => null,
					'price'         => '2200',
					'stock'         => 3,
					'weight'        => null,
				],
				[
					'remote_id'     => '',
					'sku'           => 'VAR-BLUE',
					'option1_name'  => 'Color',
					'option1_value' => 'Blue',
					'option2_name'  => null,
					'option2_value' => null,
					'price'         => '2200',
					'stock'         => 3,
					'weight'        => null,
				],
			],
			[],
			[],
			null,
			'publish'
		);
	}

	/**
	 * `push_product()`用のHTTPモック。`$handlers`のキーは`"{METHOD} {URLに含まれる文字列}"`で、
	 * 値は呼び出し順に消費されるレスポンス列（尽きたら最後の要素を繰り返す）。レスポンスは
	 * `body`（JSONエンコードして返す）または`raw_body`（文字列をそのまま返す。画像バイナリ取得用）
	 * のいずれかを持つ連想配列。
	 *
	 * @param array<string,array<int,array<string,mixed>>>                          $handlers
	 * @param array<int,array{method:string,url:string,body:?array<string,mixed>,raw:?string}> $captured 呼び出し元へ書き戻す実リクエスト履歴。
	 */
	private function mock_push_requests( array $handlers, array &$captured = [] ): void {
		$counts = [];

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( $handlers, &$counts, &$captured ) {
				$method       = strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) );
				$raw_body     = $parsed_args['body'] ?? null;
				$content_type = (string) ( $parsed_args['headers']['Content-Type'] ?? '' );
				$decoded      = ( is_string( $raw_body ) && str_starts_with( $content_type, 'application/json' ) )
					? json_decode( $raw_body, true )
					: null;

				$captured[] = [
					'method' => $method,
					'url'    => $url,
					'body'   => $decoded,
					'raw'    => is_string( $raw_body ) ? $raw_body : null,
				];

				foreach ( $handlers as $needle => $sequence ) {
					$space = strpos( $needle, ' ' );

					if ( false === $space
						|| strtoupper( substr( $needle, 0, $space ) ) !== $method
						|| ! str_contains( $url, substr( $needle, $space + 1 ) ) ) {
						continue;
					}

					$index             = $counts[ $needle ] ?? 0;
					$counts[ $needle ] = $index + 1;
					$response          = $sequence[ $index ] ?? $sequence[ count( $sequence ) - 1 ];

					if ( array_key_exists( 'raw_body', $response ) ) {
						return [
							'response' => [ 'code' => $response['status'] ?? 200 ],
							'headers'  => [],
							'body'     => (string) $response['raw_body'],
						];
					}

					return $this->json_response( $response['body'] ?? [], $response['status'] ?? 200 );
				}

				return new WP_Error( 'unexpected_request', "Unhandled ColorMe request: {$method} {$url}" );
			},
			10,
			3
		);
	}

	/**
	 * @param array<int,array{method:string,url:string,body:?array<string,mixed>,raw:?string}> $captured
	 * @return ?array{method:string,url:string,body:?array<string,mixed>,raw:?string}
	 */
	private function find_captured( array $captured, string $method, string $url_needle ): ?array {
		foreach ( $captured as $request ) {
			if ( $method === $request['method'] && str_contains( $request['url'], $url_needle ) ) {
				return $request;
			}
		}

		return null;
	}

	public function test_fetch_reviews_is_not_supported(): void {
		[ $adapter ] = $this->make_adapter();

		$this->expectException( UnsupportedOperationException::class );

		$adapter->fetch_reviews( Cursor::start() );
	}

	public function test_fetch_products_reads_envelope_and_does_not_report_meta_total_as_the_progress_denominator(): void {
		// customer/order/stockと同じ理由: `meta.total`は生の行数であり、`list_from()`の非配列行
		// フィルタや`ProductTransformer::transform()`の変換失敗（`variants`欠損等）で`items`件数が
		// それと1:1対応するとは限らない。ページング終端の判定にだけ使い、`Page::$total`には
		// 報告しない。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		// `product_transformer()`が定価換算用に`shop.json`も叩くため、products.jsonへの
		// リクエストURLだけを捕捉する（`$captured`を毎回上書きすると最後に叩かれた
		// shop.jsonのURLで検証してしまう）。他の`respond_from_map()`利用テストと同じく、
		// 想定外のURLは`WP_Error`で失敗させ、意図しないHTTPリクエストの混入を検出できるようにする。
		$captured = null;
		$fixture  = FixtureLoader::load( 'colorme', 'products' );
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured, $fixture ) {
				if ( str_contains( $url, 'products.json' ) ) {
					$captured = $url;

					return $this->json_response( $fixture );
				}

				if ( str_contains( $url, 'shop.json' ) ) {
					return $this->json_response( [ 'shop' => [] ] );
				}

				return new WP_Error( 'unexpected_request', "Unhandled ColorMe API request: {$url}" );
			},
			10,
			3
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertCount( 4, $page->items );
		$this->assertNull( $page->total );
		$this->assertNull( $page->next_cursor );
		$this->assertStringContainsString( 'limit=50', (string) $captured );
		$this->assertStringContainsString( 'offset=0', (string) $captured );
	}

	public function test_fetch_products_returns_a_next_cursor_when_meta_total_exceeds_the_page(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$all_products = FixtureLoader::load( 'colorme', 'products' )['products'];

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [
						'products' => array_slice( $all_products, 0, 2 ),
						'meta'     => [
							'total'  => 4,
							'limit'  => 2,
							'offset' => 0,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertCount( 2, $page->items );
		$this->assertNotNull( $page->next_cursor );
		$this->assertSame( 2, $page->next_cursor->get( 'offset' ) );
	}

	public function test_fetch_products_throws_when_the_products_envelope_is_missing(): void {
		// スキーマ崩壊等で`products`キー自体が欠損した200応答は、正当な0件（`[]`）と区別し
		// ページ終端と誤認させず例外で失敗させる（JobManagerがリトライ可能な失敗として扱えるように）。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [ 'meta' => [ 'total' => 0 ] ],
				],
			]
		);

		$this->expectException( RuntimeException::class );

		$adapter->fetch_products( Cursor::start() );
	}

	public function test_fetch_products_advances_the_cursor_by_the_raw_row_count_not_the_filtered_count(): void {
		// 非配列要素はフィルタで除去されるため、フィルタ後の件数でoffsetを進めると次ページのoffsetが
		// APIの実際のページ内位置より手前にずれ、除外された行を含むページと次ページが重複してしまう。
		// offsetの計算にはフィルタ前の生の行数を使うべきであることを確認する。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$all_products = FixtureLoader::load( 'colorme', 'products' )['products'];

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [
						// 先頭の要素は非配列の壊れた行。フィルタで除去されるため有効な行は2件になる。
						'products' => [ 'not-an-array', $all_products[0], $all_products[1] ],
						'meta'     => [
							'total'  => 4,
							'limit'  => 3,
							'offset' => 0,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertCount( 2, $page->items );
		$this->assertNotNull( $page->next_cursor );
		$this->assertSame( 3, $page->next_cursor->get( 'offset' ) );
	}

	public function test_fetch_products_continues_paging_when_meta_total_is_negative(): void {
		// meta.totalが負値等の不整合な値（スキーマ崩壊・プロキシ異常等）の場合、そのまま
		// 終端判定に使うと、まだ残っているはずの行を含むフルページを「完了」と誤認し、
		// 静かな部分移行を招く。totalを信頼できないとみなし、空ページに達するまで継続すべき。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$all_products = FixtureLoader::load( 'colorme', 'products' )['products'];

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [
						'products' => $all_products,
						'meta'     => [
							'total'  => -1,
							'limit'  => 50,
							'offset' => 0,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertNotNull( $page->next_cursor );
		$this->assertSame( count( $all_products ), $page->next_cursor->get( 'offset' ) );
	}

	public function test_fetch_products_continues_paging_when_meta_total_is_smaller_than_rows_already_fetched(): void {
		// totalが「これまでの累計取得件数」にも満たない不整合な値の場合も同様に、totalを
		// 信頼せず継続する（この時点で既にtotal件以上を取得済みという矛盾が生じているため）。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$all_products = FixtureLoader::load( 'colorme', 'products' )['products'];

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [
						'products' => $all_products,
						'meta'     => [
							'total'  => 1,
							'limit'  => 50,
							'offset' => 0,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertNotNull( $page->next_cursor );
		$this->assertSame( count( $all_products ), $page->next_cursor->get( 'offset' ) );
	}

	public function test_fetch_products_continues_paging_when_meta_total_is_fractional(): void {
		// `Cast::to_int_or_null()`は小数を暗黙に切り捨てる（例: 4.5→4）。ページ内の実際の行数
		// （$next_offset）とちょうど一致する切り捨て後の値をそのまま終端判定に使うと、本来
		// 小数という時点で信頼できないtotalなのに「ちょうど最終ページ」と誤認してしまう。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$all_products = FixtureLoader::load( 'colorme', 'products' )['products'];

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [
						'products' => $all_products,
						'meta'     => [
							'total'  => count( $all_products ) + 0.5,
							'limit'  => 50,
							'offset' => 0,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertNotNull( $page->next_cursor );
		$this->assertSame( count( $all_products ), $page->next_cursor->get( 'offset' ) );
	}

	public function test_fetch_products_terminates_when_meta_total_disagrees_with_zero_fetched_items(): void {
		// meta.totalがoffsetより大きい値を報告していても、実際に0件しか取れなかった場合
		// （並行削除等）はoffsetを進めるすべが無い。offset不変のCursorを返すと同じページを
		// 無限に再エンキューし続けてしまうため、無条件に終端（null）を返すべき。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [
						'products' => [],
						'meta'     => [
							'total'  => 4,
							'limit'  => 50,
							'offset' => 0,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}

	public function test_fetch_products_keeps_paging_when_meta_total_is_unavailable_even_below_page_size(): void {
		// meta.totalが得られない場合、取得件数がページサイズ未満でも「最終ページ」と推測しない。
		// list_from()が非配列要素を除去するため、APIが実際にはページサイズ分返していても
		// フィルタ後の件数はページサイズ未満になり得る。安全側（=継続）に倒すことを検証する。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [ 'products' => [ $this->product_fixture( 192616831 ) ] ],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertCount( 1, $page->items );
		$this->assertNotNull( $page->next_cursor );
		$this->assertSame( 1, $page->next_cursor->get( 'offset' ) );
	}

	public function test_mapping_candidates_returns_category_payment_shipping_and_status(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'categories.json' => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'categories' ),
				],
				'payments.json'   => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'payments' ),
				],
				'deliveries.json' => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'deliveries' ),
				],
			]
		);

		$candidates = $adapter->mapping_candidates();

		$this->assertSame( [ '2993030', '2993032' ], array_column( $candidates['category'], 'id' ) );
		$this->assertSame( [ '1094475', '1094978' ], array_column( $candidates['payment'], 'id' ) );
		$this->assertSame( [ '640580' ], array_column( $candidates['shipping'], 'id' ) );
		// APIを叩かない固定4値（`OrderTransformer::status()`が返しうるcanonicalステータス）。
		$this->assertSame( [ 'pending', 'processing', 'completed', 'cancelled' ], array_column( $candidates['status'], 'id' ) );
	}

	public function test_fetch_categories_flattens_and_filters_by_display_state(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'categories.json' => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'categories' ),
				],
			]
		);

		$categories = $adapter->fetch_categories();

		$this->assertCount( 2, $categories );
		$this->assertSame( '2993030', $categories[0]->id );
		$this->assertSame( '2993032', $categories[1]->id );
	}

	public function test_fetch_tags_excludes_hidden_groups(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		// フィクスチャの唯一のグループは display_state=hidden のため、全て除外される。
		$this->respond_from_map(
			[
				'groups.json' => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'groups' ),
				],
			]
		);

		$this->assertSame( [], $adapter->fetch_tags() );
	}

	public function test_fetch_tags_includes_showing_groups(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$groups                               = FixtureLoader::load( 'colorme', 'groups' );
		$groups['groups'][0]['display_state'] = 'showing';

		$this->respond_from_map(
			[
				'groups.json' => [
					'status' => 200,
					'body'   => $groups,
				],
			]
		);

		$tags = $adapter->fetch_tags();

		$this->assertCount( 1, $tags );
		$this->assertSame( (string) $groups['groups'][0]['id'], $tags[0]->id );
	}

	public function test_fetch_tags_skips_a_row_with_a_transform_error_without_failing_the_page(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$groups                 = FixtureLoader::load( 'colorme', 'groups' );
		$valid                  = $groups['groups'][0];
		$valid['display_state'] = 'showing';
		$broken                 = $valid;
		unset( $broken['id'] ); // TagTransformerはid欠損でRuntimeExceptionを投げる。

		$this->respond_from_map(
			[
				'groups.json' => [
					'status' => 200,
					'body'   => [ 'groups' => [ $broken, $valid ] ],
				],
			]
		);

		$tags = $adapter->fetch_tags();

		$this->assertCount( 1, $tags );
		$this->assertSame( (string) $valid['id'], $tags[0]->id );
	}

	public function test_fetch_customers_filters_out_non_member_rows(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		// フィクスチャの顧客は全て member=false（ゲスト）のため除外される。
		$this->respond_from_map(
			[
				'customers.json' => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'customers' ),
				],
			]
		);

		$page = $adapter->fetch_customers( Cursor::start() );

		$this->assertSame( [], $page->items );
		// meta.totalは生の顧客件数（5）でありitems件数（0、非会員除外後）と一致しないため、
		// 進捗率の分母として誤報告しない（totalはnull）。
		$this->assertNull( $page->total );
	}

	public function test_fetch_customers_includes_members_with_mail(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$customers                           = FixtureLoader::load( 'colorme', 'customers' );
		$customers['customers'][0]['member'] = true;

		$this->respond_from_map(
			[
				'customers.json' => [
					'status' => 200,
					'body'   => $customers,
				],
			]
		);

		$page = $adapter->fetch_customers( Cursor::start() );

		$this->assertCount( 1, $page->items );
		$this->assertSame( $customers['customers'][0]['mail'], $page->items[0]->email );
	}

	/**
	 * `fetch_order_by_remote_id()` が単一取得の前提とする、支払・配送方法の名称マップ用レスポンス
	 * （`order_transformer()` が初回に取得する）。
	 *
	 * @return array<string,array{status:int,body:array<string,mixed>}>
	 */
	private function order_lookup_stubs(): array {
		return [
			'payments.json'   => [
				'status' => 200,
				'body'   => FixtureLoader::load( 'colorme', 'payments' ),
			],
			'deliveries.json' => [
				'status' => 200,
				'body'   => FixtureLoader::load( 'colorme', 'deliveries' ),
			],
		];
	}

	public function test_an_unconnected_adapter_marks_the_failure_as_not_connected(): void {
		// ステータス 0 は通信断・JSON 破損でも使われるため、呼び出し側（県コード修復ツール等）が
		// 「再接続が必要」と一時的な通信断を区別できるよう、未接続は文脈で明示される。
		[ $adapter ] = $this->make_adapter();

		try {
			$adapter->fetch_order_by_remote_id( '1' );
			$this->fail( 'ApiException was not thrown' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 0, $exception->status_code() );
			$this->assertTrue( $exception->context()['not_connected'] );
		}
	}

	public function test_fetch_order_by_remote_id_returns_null_on_404(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'sales/999.json' => [
					'status' => 404,
					'body'   => [
						'errors' => [
							[
								'code'    => 404100,
								'message' => 'Not Found',
								'status'  => 404,
							],
						],
					],
				],
			]
		);

		$this->assertNull( $adapter->fetch_order_by_remote_id( '999' ) );
	}

	public function test_fetch_order_by_remote_id_fails_when_the_envelope_is_malformed(): void {
		// 200応答でも`sale`envelopeが配列でない場合を404と同じnullにすると、呼び出し側が
		// 「ASP側で削除済み」と区別できないまま静かに読み飛ばしてしまう。例外で失敗させる。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'sales/999.json' => [
					'status' => 200,
					'body'   => [ 'sale' => 'unexpected-string' ],
				],
			]
		);

		$this->expectException( RuntimeException::class );

		$adapter->fetch_order_by_remote_id( '999' );
	}

	public function test_fetch_order_by_remote_id_uses_the_detail_endpoint_without_a_date_window(): void {
		// 一覧の `sales.json` は `after` 未指定だと直近7日にしか効かない（03 §9 #14）。ID指定の単一取得は
		// 日付窓の影響を受けない `sales/{id}.json` を使い、古い受注でも取得できる。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
				$captured[] = $url;

				if ( str_contains( $url, 'payments.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'payments' ) );
				}

				if ( str_contains( $url, 'deliveries.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'deliveries' ) );
				}

				if ( str_contains( $url, 'sales/219293424.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'sale_bank_detail' ) );
				}

				return new WP_Error( 'unexpected_request', "Unhandled request: {$url}" );
			},
			10,
			3
		);

		$order = $adapter->fetch_order_by_remote_id( '219293424' );

		$this->assertNotNull( $order );
		$this->assertSame( '219293424', $order->remote_id() );

		$sale_urls = array_values( array_filter( $captured, static fn ( string $url ): bool => str_contains( $url, '/sales' ) ) );
		$this->assertCount( 1, $sale_urls );
		$this->assertStringNotContainsString( 'after=', $sale_urls[0] );
		$this->assertStringNotContainsString( 'ids=', $sale_urls[0] );
	}

	public function test_fetch_order_by_remote_id_carries_the_billing_and_shipping_pref_ids_for_state_repair(): void {
		// 県コード修復（issue #46）は請求先（customer_snapshot）と配送先（shipping）の生の `pref_id` を
		// Canonical 経由で受け取る。フィクスチャの `pref_id=13`（東京）は表の固定点で層間のズレを
		// 検出できないため、固定点でない値（4=秋田・5=宮城）へ差し替えて通過することを確認する。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$detail                                = FixtureLoader::load( 'colorme', 'sale_bank_detail' );
		$detail['sale']['customer']['pref_id'] = 4;
		$detail['sale']['sale_deliveries'][0]['pref_id'] = 5;

		$this->respond_from_map(
			array_merge(
				$this->order_lookup_stubs(),
				[
					'sales/219293424.json' => [
						'status' => 200,
						'body'   => $detail,
					],
				]
			)
		);

		$order = $adapter->fetch_order_by_remote_id( '219293424' );

		$this->assertNotNull( $order );
		$this->assertSame( 4, $order->extras['customer_snapshot']['pref_id'] );
		$this->assertSame( 5, $order->shipping['pref_id'] );
	}

	public function test_fetch_order_by_remote_id_propagates_lookup_failures_instead_of_swallowing_them(): void {
		// `payments.json` の取得失敗（認証切れ等の基盤障害）を、この受注「1件」の変換失敗と同じ扱いで
		// nullに握り潰すと、呼び出し側（県コード修復ツール等）が「ASP側で削除済み」と誤解して
		// 障害に気付けない。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'sales/219293424.json' => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'sale_bank_detail' ),
				],
				'payments.json'        => [
					'status' => 401,
					'body'   => [
						'errors' => [
							[
								'code'    => 401001,
								'message' => 'アクセストークンが無効です。',
								'status'  => 401,
							],
						],
					],
				],
			]
		);

		$this->expectException( ApiException::class );

		$adapter->fetch_order_by_remote_id( '219293424' );
	}

	public function test_fetch_orders_walks_full_history_with_an_explicit_after_floor(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
				if ( str_contains( $url, 'payments.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'payments' ) );
				}

				if ( str_contains( $url, 'deliveries.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'deliveries' ) );
				}

				if ( str_contains( $url, 'sales.json' ) ) {
					$captured = $url;

					return $this->json_response( FixtureLoader::load( 'colorme', 'sales' ) );
				}

				return new WP_Error( 'unexpected_request', "Unhandled request: {$url}" );
			},
			10,
			3
		);

		$page = $adapter->fetch_orders( Cursor::start() );

		$this->assertCount( 2, $page->items );
		$this->assertStringContainsString( 'after=2000-01-01', (string) $captured );
		// meta.totalは変換失敗行を含みうる生の受注件数のため、進捗率の分母として誤報告しない。
		$this->assertNull( $page->total );
	}

	public function test_fetch_stocks_derives_from_products_and_flattens_variants(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'products' ),
				],
			]
		);

		$page = $adapter->fetch_stocks( Cursor::start() );

		// フィクスチャ4商品のバリエーション数合計（3+9+2+1）。
		$this->assertCount( 15, $page->items );
		// meta.totalは商品件数（4）でitems件数（15、バリエーション展開後）と一致しないため、
		// 進捗率の分母として誤報告しない（totalはnull）。
		$this->assertNull( $page->total );
	}

	public function test_fetch_coupons_has_no_pagination_and_filters_null_rows(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$now = time();

		$this->respond_from_map(
			[
				'shop_coupons.json' => [
					'status' => 200,
					'body'   => [
						'shop_coupons' => [
							[
								'id'                => 1,
								'code'              => 'VALID500',
								'coupon_type'       => 'amount',
								'discount_amount'   => 500,
								'minimum_amount'    => 0,
								'total_usage_limit' => 100,
								'group_limit_type'  => 'none',
								'usage_limit'       => 'indisposable',
								'starts_at'         => $now - DAY_IN_SECONDS,
								'ends_at'           => $now + DAY_IN_SECONDS,
								'status'            => 'available',
							],
							[
								'id'                => 2,
								'code'              => 'DISABLED500',
								'coupon_type'       => 'amount',
								'discount_amount'   => 500,
								'minimum_amount'    => 0,
								'total_usage_limit' => 100,
								'group_limit_type'  => 'none',
								'usage_limit'       => 'indisposable',
								'starts_at'         => $now - DAY_IN_SECONDS,
								'ends_at'           => $now + DAY_IN_SECONDS,
								'status'            => 'unavailable',
							],
						],
					],
				],
			]
		);

		$page = $adapter->fetch_coupons( Cursor::start() );

		$this->assertCount( 1, $page->items );
		$this->assertNull( $page->next_cursor );
	}

	public function test_fetch_product_by_remote_id_returns_null_on_404(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'products/999.json' => [
					'status' => 404,
					'body'   => [
						'errors' => [
							[
								'code'    => 404100,
								'message' => 'Not Found',
								'status'  => 404,
							],
						],
					],
				],
			]
		);

		$this->assertNull( $adapter->fetch_product_by_remote_id( '999' ) );
	}

	public function test_fetch_product_by_remote_id_fails_when_the_envelope_is_malformed(): void {
		// 200応答でも`product`envelopeの中身が配列でない場合（スキーマ変更等）を404と同じnullに
		// フェイルクローズすると、`run_sample_page()`がサンプル対象を診断もリトライも無く
		// 静かに欠落させたままジョブを「完了」させてしまう。例外を投げてジョブを失敗させる。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'products/999.json' => [
					'status' => 200,
					'body'   => [ 'product' => 'unexpected-string' ],
				],
			]
		);

		$this->expectException( RuntimeException::class );

		$adapter->fetch_product_by_remote_id( '999' );
	}

	public function test_fetch_product_by_remote_id_fails_when_the_envelope_key_is_missing_entirely(): void {
		// envelopeキー自体が欠損した200応答も、非配列値の場合と同じくスキーマ崩壊として扱い、
		// 404と区別なくnullを返して黙ってスキップしない。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'products/999.json' => [
					'status' => 200,
					'body'   => [],
				],
			]
		);

		$this->expectException( RuntimeException::class );

		$adapter->fetch_product_by_remote_id( '999' );
	}

	public function test_fetch_product_by_remote_id_returns_the_transformed_product(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product = $this->product_fixture( 192616831 );

		$this->respond_from_map(
			[
				'products/192616831.json' => [
					'status' => 200,
					'body'   => [ 'product' => $product ],
				],
			]
		);

		$result = $adapter->fetch_product_by_remote_id( '192616831' );

		$this->assertNotNull( $result );
		$this->assertSame( '192616831', $result->extras['remote_id'] );
	}

	public function test_fetch_product_by_remote_id_propagates_shop_json_failures_instead_of_swallowing_them(): void {
		// `shop.json`の取得失敗（認証切れ等の基盤障害）を、この商品「1件」だけのtransform失敗
		// （`RuntimeException`等）と同じ扱いでnullに握り潰すと、実際にはAPI接続自体が壊れている
		// のに「この商品は解決できなかった」という個別行の欠落として静かにスキップされてしまう。
		// ジョブ全体の失敗としてリトライに委ねるべきであることを検証する。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product = $this->product_fixture( 192616831 );

		$this->respond_from_map(
			[
				'products/192616831.json' => [
					'status' => 200,
					'body'   => [ 'product' => $product ],
				],
				'shop.json'               => [
					'status' => 401,
					'body'   => [
						'errors' => [
							[
								'code'    => 401001,
								'message' => 'アクセストークンが無効です。',
								'status'  => 401,
							],
						],
					],
				],
			]
		);

		$this->expectException( ApiException::class );

		$adapter->fetch_product_by_remote_id( '192616831' );
	}

	public function test_fetch_product_by_remote_id_converts_list_price_using_shop_tax_settings(): void {
		// `product_transformer()`が`shop.json`の税設定を実際にProductTransformerへ注入することを
		// 結合レベルで検証する（換算式自体の網羅はProductTransformerTestの責務）。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product                              = $this->product_fixture( 192616831 );
		$product['price']                     = 8000;
		$product['sales_price_including_tax'] = 6600;

		$this->respond_from_map(
			[
				'products/192616831.json' => [
					'status' => 200,
					'body'   => [ 'product' => $product ],
				],
				'shop.json'               => [
					'status' => 200,
					'body'   => [
						'shop' => [
							'id'                  => 'PA000001',
							'tax_type'            => 'excluded',
							'tax'                 => 10,
							'tax_rounding_method' => 'round_off',
							'reduce_tax_rate'     => 8,
						],
					],
				],
			]
		);

		$result = $adapter->fetch_product_by_remote_id( '192616831' );

		$this->assertNotNull( $result );
		$this->assertSame( '8800', $result->price );
		$this->assertSame( '6600', $result->sale_price );
	}

	public function test_fetch_products_converts_list_price_using_shop_tax_settings(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product                              = $this->product_fixture( 192616831 );
		$product['price']                     = 8000;
		$product['sales_price_including_tax'] = 6600;

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [ 'products' => [ $product ] ],
				],
				'shop.json'     => [
					'status' => 200,
					'body'   => [
						'shop' => [
							'id'                  => 'PA000001',
							'tax_type'            => 'excluded',
							'tax'                 => 10,
							'tax_rounding_method' => 'round_off',
							'reduce_tax_rate'     => 8,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertCount( 1, $page->items );
		$this->assertSame( '8800', $page->items[0]->price );
		$this->assertSame( '6600', $page->items[0]->sale_price );
	}

	public function test_fetch_products_ignores_a_non_integer_shop_tax_rate(): void {
		// `shop.tax`はswagger上integerだが、スキーマ崩壊等で`8.9`のような小数が返った場合、
		// `(int)`丸めで黙って`8`として通すと、実際には不正な税率でもっともらしいが誤った
		// 定価を計算してしまう（レビュー指摘: PR #24）。換算不可としてフォールバックすることを検証する。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$product                              = $this->product_fixture( 192616831 );
		$product['price']                     = 8000;
		$product['sales_price_including_tax'] = 6600;

		$this->respond_from_map(
			[
				'products.json' => [
					'status' => 200,
					'body'   => [ 'products' => [ $product ] ],
				],
				'shop.json'     => [
					'status' => 200,
					'body'   => [
						'shop' => [
							'id'                  => 'PA000001',
							'tax_type'            => 'excluded',
							'tax'                 => 8.9,
							'tax_rounding_method' => 'round_off',
							'reduce_tax_rate'     => 8,
						],
					],
				],
			]
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertCount( 1, $page->items );
		$this->assertSame( '6600', $page->items[0]->price );
		$this->assertNull( $page->items[0]->sale_price );
	}

	public function test_product_transformer_fetches_shop_json_only_once_per_adapter_instance(): void {
		// `order_transformer()`と同じキャッシュ規約: 同一アダプタインスタンスでの複数回の
		// fetch_products呼び出しでも`shop.json`は1回しか叩かない。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$shop_requests = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$shop_requests ) {
				if ( str_contains( $url, 'shop.json' ) ) {
					++$shop_requests;

					return $this->json_response( [ 'shop' => [] ] );
				}

				if ( str_contains( $url, 'products.json' ) ) {
					return $this->json_response( [ 'products' => [] ] );
				}

				return new WP_Error( 'unexpected_request', "Unhandled ColorMe API request: {$url}" );
			},
			10,
			3
		);

		$adapter->fetch_products( Cursor::start() );
		$adapter->fetch_products( Cursor::start() );

		$this->assertSame( 1, $shop_requests );
	}

	public function test_fetch_customer_by_remote_id_returns_null_on_404(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'customers/999.json' => [
					'status' => 404,
					'body'   => [
						'errors' => [
							[
								'code'    => 404100,
								'message' => 'Not Found',
								'status'  => 404,
							],
						],
					],
				],
			]
		);

		$this->assertNull( $adapter->fetch_customer_by_remote_id( '999' ) );
	}

	public function test_fetch_latest_orders_widens_the_search_window_until_history_floor(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
				if ( str_contains( $url, 'payments.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'payments' ) );
				}

				if ( str_contains( $url, 'deliveries.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'deliveries' ) );
				}

				if ( str_contains( $url, 'sales.json' ) ) {
					$captured[] = $url;

					// フィクスチャは常に2件のみ。limit=3を要求し続けるため探索窓が
					// history floorまで広がりきることを検証する。
					return $this->json_response( FixtureLoader::load( 'colorme', 'sales' ) );
				}

				return new WP_Error( 'unexpected_request', "Unhandled request: {$url}" );
			},
			10,
			3
		);

		$orders = $adapter->fetch_latest_orders( 3 );

		$this->assertCount( 2, $orders );
		$this->assertGreaterThan( 1, count( $captured ) );
		$this->assertStringNotContainsString( 'after=', $captured[0] );
		$this->assertStringContainsString( 'after=2000-01-01', end( $captured ) );
		// makeDate降順（新しい順）で並んでいること。
		$this->assertGreaterThanOrEqual( $orders[1]->placed_at, $orders[0]->placed_at );
	}

	public function test_fetch_latest_orders_keeps_widening_when_rows_fail_transformation(): void {
		// 1回目のレスポンスは取得件数こそ$limit(2)を満たすが、1件はid欠損で変換に失敗する。
		// 取得件数だけで判定すると探索を打ち切ってしまうため、有効件数（1件）を見て
		// 2回目のリクエストに進むことを検証する。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$sales  = FixtureLoader::load( 'colorme', 'sales' );
		$valid  = $sales['sales'][0];
		$broken = $sales['sales'][1];
		unset( $broken['id'] );
		$first_response  = [ 'sales' => [ $valid, $broken ] ];
		$second_response = [ 'sales' => [ $valid, $sales['sales'][1] ] ];

		$requests = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$requests, $first_response, $second_response ) {
				if ( str_contains( $url, 'payments.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'payments' ) );
				}

				if ( str_contains( $url, 'deliveries.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'deliveries' ) );
				}

				if ( str_contains( $url, 'sales.json' ) ) {
					++$requests;

					return $this->json_response( 1 === $requests ? $first_response : $second_response );
				}

				return new WP_Error( 'unexpected_request', "Unhandled request: {$url}" );
			},
			10,
			3
		);

		$orders = $adapter->fetch_latest_orders( 2 );

		$this->assertGreaterThan( 1, $requests );
		$this->assertCount( 2, $orders );
	}

	public function test_fetch_latest_orders_widens_the_requested_limit_when_a_row_is_permanently_broken(): void {
		// 上位N件（新しい順）に恒久的に壊れた行が1件混ざっている場合、探索窓（after）を
		// どれだけ過去へ広げても同じ上位集合が返り続け、有効件数は増えない
		// （壊れた行がどの窓でも同じ順位を占め続けるため）。要求件数（limit）自体を
		// 広げないと候補が増えず、有効な受注を追加で拾えないことを検証する。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$sales  = FixtureLoader::load( 'colorme', 'sales' );
		$valid  = $sales['sales'][0];
		$broken = $sales['sales'][1];
		unset( $broken['id'] );

		$captured_limits = [];
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured_limits, $valid, $broken ) {
				if ( str_contains( $url, 'payments.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'payments' ) );
				}

				if ( str_contains( $url, 'deliveries.json' ) ) {
					return $this->json_response( FixtureLoader::load( 'colorme', 'deliveries' ) );
				}

				if ( str_contains( $url, 'sales.json' ) ) {
					wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
					$limit             = (int) $query['limit'];
					$captured_limits[] = $limit;

					// APIは常に「新しい順の上位limit件」を返す。壊れた行は常に2番目に位置し続ける
					// ため、limitを広げない限り有効行は1件（$valid）のまま増えない。
					$rows = array_fill( 0, max( 0, $limit - 1 ), $valid );
					array_splice( $rows, 1, 0, [ $broken ] );

					return $this->json_response( [ 'sales' => array_slice( $rows, 0, $limit ) ] );
				}

				return new WP_Error( 'unexpected_request', "Unhandled request: {$url}" );
			},
			10,
			3
		);

		$orders = $adapter->fetch_latest_orders( 2 );

		$this->assertCount( 2, $orders );
		$this->assertGreaterThan( 2, max( $captured_limits ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function product_fixture( int $id ): array {
		foreach ( FixtureLoader::load( 'colorme', 'products' )['products'] as $product ) {
			if ( $id === $product['id'] ) {
				return $product;
			}
		}

		$this->fail( "Fixture product {$id} not found." );
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function json_response( array $body, int $status = 200 ): array {
		return [
			'response' => [ 'code' => $status ],
			'headers'  => [],
			'body'     => (string) wp_json_encode( $body ),
		];
	}

	/**
	 * URLに含まれる文字列をキーに、モックする応答を振り分ける。
	 * 各エントリは `['status' => int, 'body' => array]`。
	 * `product_transformer()`が定価の税込換算用に`shop.json`を叩くため、呼び出し元が
	 * 明示的に指定しない限り空のshop応答（税設定なし＝従来どおりのフォールバック）を
	 * デフォルトで用意する。
	 *
	 * @param array<string,array<string,mixed>> $map
	 */
	private function respond_from_map( array $map ): void {
		$map += [
			'shop.json' => [
				'status' => 200,
				'body'   => [ 'shop' => [] ],
			],
		];

		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( $map ) {
				foreach ( $map as $needle => $response ) {
					if ( str_contains( $url, $needle ) ) {
						return $this->json_response( $response['body'], $response['status'] );
					}
				}

				return new WP_Error( 'unexpected_request', "Unhandled ColorMe API request: {$url}" );
			},
			10,
			3
		);
	}
}
