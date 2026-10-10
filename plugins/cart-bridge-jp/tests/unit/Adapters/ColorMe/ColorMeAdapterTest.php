<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PartialPushException;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\ExportOptions;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\Exporter;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\FixedWooReader;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use CartBridgeJP\Woo\Export\AdapterPlatformWriter;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_UnitTestCase;

final class ColorMeAdapterTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'cbjp_settings_colorme' );
		delete_option( ExportOptions::option_name( ColorMeAdapter::ID ) );
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

	/**
	 * D24: 画像アップロードは既定オフ。画像を実際に送るテストは、Export タブの「商品画像をアップロードする（Beta）」に
	 * 当たる設定を明示的にオンにする（設定は`ColorMeAdapter::ID`単位で、`make_adapter()`のTokenStoreとは無関係）。
	 */
	private function enable_image_upload(): void {
		ExportOptions::save_push_images( ColorMeAdapter::ID, true );
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
		$this->assertTrue( $capabilities->has_tags );
		$this->assertFalse( $capabilities->has_reviews );
		$this->assertTrue( $capabilities->has_variants );
		$this->assertSame( 100, $capabilities->rate_limit_per_minute );
		// 在庫管理は商品単位のみでバリエーションに相当する項目が無い（D22）。混在した商品はExporterが止める。
		$this->assertFalse( $capabilities->supports_per_variant_stock_management );
	}

	/**
	 * D24: プレミアム限定の画像アップロードはベータ版。プラン（＝能力の有無）に関わらず静的に宣言し、
	 * 項目を出すかどうかは`can_push_images`が決める（受注のエクスポートのベータは `ColorMeCommerceAdapter`。R3-6c1）。
	 */
	public function test_capabilities_declare_the_premium_only_features_as_beta_regardless_of_plan(): void {
		[ $adapter, $token_store ] = $this->make_adapter();

		$this->assertSame( [ Capabilities::BETA_IMAGE_PUSH ], $adapter->capabilities()->beta_features, '未接続（プラン不明）' );

		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->assertSame( [ Capabilities::BETA_IMAGE_PUSH ], $adapter->capabilities()->beta_features );
	}

	/**
	 * `capabilities()->can_push_images`は能力（プラン）を表し、画像アップロードの設定では変わらない。設定で変わると、
	 * オフのとき UI が「この店舗で画像をアップロードできる」ことを判別できず、オンにする手段が無くなる。
	 */
	public function test_capabilities_can_push_images_does_not_depend_on_the_image_upload_setting(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->assertTrue( $adapter->capabilities()->can_push_images, '設定オフ（既定）でも能力は true' );

		$this->enable_image_upload();

		$this->assertTrue( $adapter->capabilities()->can_push_images );

		// 非プレミアムでは設定がオンでも能力は false のまま（設定が能力を作らない）。
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->assertFalse( $adapter->capabilities()->can_push_images );
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
	 * R3-1d（issue #78）: 税設定不明で価格を1件も換算できない商品は、作成しない（以前は作成時だけ`display_state=hidden`に
	 * 倒していたが、次の更新で公開されていた）。POST も追いPUT も送らず、remote_id の空の`skipped`で返す。
	 */
	public function test_push_product_does_not_create_when_price_cannot_be_resolved(): void {
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

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ WarningCode::PRODUCT_PRICE_NOT_CONVERTIBLE ], $result->warnings );
		$this->assert_no_product_writes( $captured );
	}

	/**
	 * R3-1d（issue #78）: 価格を換算できない商品は更新もしない（以前は`showing`のまま価格を省いて PUT していた）。
	 * ColorMe 側の既存の商品はそのまま残る。
	 */
	public function test_push_product_does_not_update_when_price_cannot_be_resolved(): void {
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

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ WarningCode::PRODUCT_PRICE_NOT_CONVERTIBLE ], $result->warnings );
		$this->assert_no_product_writes( $captured );
	}

	/**
	 * R3-1d: 正規化モデルの税区分が記号（null／`reduced-rate`）以外の商品は、作成も更新もしない
	 * （`Woo\Reader\ProductReader`が止める警告を積み`Sync\Exporter`が先に止めるので、ここは多重防御）。
	 * 以前は作成時だけ hidden、更新では`tax_reduced`を省いて`showing`のまま送っていた。
	 *
	 * @dataProvider provide_remote_ids
	 */
	public function test_push_product_does_not_send_an_unsupported_tax_class( ?string $remote_id ): void {
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
		$result  = $adapter->push_product( $product, $remote_id );

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ WarningCode::with_detail( WarningCode::TAX_CLASS_UNSUPPORTED, 'zero-rate' ) ], $result->warnings );
		$this->assert_no_product_writes( $captured );
	}

	/**
	 * @return array<string,array{0:?string}>
	 */
	public static function provide_remote_ids(): array {
		return [
			'create' => [ null ],
			'update' => [ '505' ],
		];
	}

	/**
	 * 軽減税率の記号の商品は`tax_reduced=true`で、標準（null）は`false`で送る（作成・追いPUT とも）。
	 */
	public function test_push_product_sends_tax_reduced_for_the_reduced_rate_token(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 507 ] ] ] ],
				'PUT products/507.json' => [ [ 'body' => [ 'product' => [ 'id' => 507 ] ] ] ],
			],
			$captured
		);

		$product = new CanonicalProduct( 'Reduced', 'SKU-R', '1080', null, null, [], [], [], [], 5, 'publish', [], true, [], null, CanonicalProduct::TAX_CLASS_REDUCED );
		$result  = $adapter->push_product( $product, null );

		$this->assertSame( PushResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( true, $this->find_captured( $captured, 'POST', 'products.json' )['body']['product']['tax_reduced'] ?? null );
		$this->assertSame( true, $this->find_captured( $captured, 'PUT', 'products/507.json' )['body']['product']['tax_reduced'] ?? null );
		$this->assertSame( 'showing', $this->find_captured( $captured, 'POST', 'products.json' )['body']['product']['display_state'] ?? null );
	}

	/**
	 * 商品の作成・更新のリクエスト（POST/PUT）が 1 件も無い（`shop.json`の GET だけ）。
	 *
	 * @param array<int,array<string,mixed>> $captured
	 */
	private function assert_no_product_writes( array $captured ): void {
		$writes = array_filter( $captured, static fn ( array $request ): bool => 'GET' !== $request['method'] );

		$this->assertSame( [], array_values( $writes ) );
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
	 * Color/Red・Color/Blueの2バリエーションを持つ新規variable商品をpushし、送信された全リクエストを返す
	 * （`GET shop.json`は`$shop`を返す）。
	 *
	 * @param array<string,mixed> $shop
	 * @param array<int,array<string,mixed>> $variants `variable_product()`と同じ形の2要素（Red, Blue）。
	 * @return array<int,array{method:string,url:string,body:?array<string,mixed>,raw:?string}>
	 */
	private function push_two_variants( array $shop, array $variants ): array {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'                       => [ [ 'body' => [ 'shop' => $shop ] ] ],
				'POST products.json'                  => [ [ 'body' => [ 'product' => [ 'id' => 900 ] ] ] ],
				'PUT products/900.json'               => [ [ 'body' => [ 'product' => [ 'id' => 900 ] ] ] ],
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

		$product = $this->variable_product();
		$adapter->push_product(
			new CanonicalProduct(
				$product->name,
				null,
				$product->price,
				null,
				null,
				[],
				$variants,
				[],
				[],
				null,
				'publish'
			),
			null
		);

		return $captured;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function color_variant( string $value, string $price, ?string $sale_price = null ): array {
		$variant = [
			'remote_id'     => '',
			'sku'           => 'VAR-' . strtoupper( $value ),
			'option1_name'  => 'Color',
			'option1_value' => $value,
			'option2_name'  => null,
			'option2_value' => null,
			'price'         => $price,
			'stock'         => 3,
			'weight'        => null,
		];

		if ( null !== $sale_price ) {
			$variant['sale_price'] = $sale_price;
		}

		return $variant;
	}

	/**
	 * issue #60: セール中のバリエーションは`option_price`（販売価格）＝実売価格、`option_market_price`
	 * （定価）＝通常価格で送る（商品レベルの`sales_price`/`price`と同じ意味論）。セール外のバリエーションは
	 * 従来どおり通常価格を`option_price`として送り、`option_market_price`は送らない。
	 */
	public function test_push_product_pushes_sale_price_of_on_sale_variants_as_option_price(): void {
		$captured = $this->push_two_variants(
			[ 'tax_type' => 'included' ],
			[ $this->color_variant( 'Red', '2200', '1980' ), $this->color_variant( 'Blue', '2200' ) ]
		);

		$red = $this->find_captured( $captured, 'PUT', 'products/900/variants/9001.json' );
		$this->assertNotNull( $red );
		$this->assertSame( 1980, $red['body']['variant']['option_price'] );
		$this->assertSame( 2200, $red['body']['variant']['option_market_price'] );

		$blue = $this->find_captured( $captured, 'PUT', 'products/900/variants/9002.json' );
		$this->assertNotNull( $blue );
		$this->assertSame( 2200, $blue['body']['variant']['option_price'] );
		$this->assertArrayNotHasKey( 'option_market_price', $blue['body']['variant'] );
	}

	public function test_push_product_converts_variant_sale_and_list_prices_for_tax_exclusive_shops(): void {
		$captured = $this->push_two_variants(
			[
				'tax_type'            => 'excluded',
				'tax'                 => 10,
				'reduce_tax_rate'     => 8,
				'tax_rounding_method' => 'round_off',
			],
			[ $this->color_variant( 'Red', '2200', '1100' ), $this->color_variant( 'Blue', '2200' ) ]
		);

		$red = $this->find_captured( $captured, 'PUT', 'products/900/variants/9001.json' );
		$this->assertNotNull( $red );
		// 税込1100→税抜1000、税込2200→税抜2000。
		$this->assertSame( 1000, $red['body']['variant']['option_price'] );
		$this->assertSame( 2000, $red['body']['variant']['option_market_price'] );
	}

	/**
	 * 実売価格だけが換算できない（通常価格は換算できる）場合、通常価格を販売価格として送ってしまわない
	 * よう価格フィールドを両方省く（フェイルクローズ）。`cbjp/adapters/register`経由のCanonicalは外部境界
	 * のため`sale_price`が数値でない値でありうる。`tax_type=included`なら通常価格は必ず換算できるので、
	 * 「販売価格が換算できず通常価格は換算できる」分岐を確実に通す（`excluded`で税率欠損の店舗では
	 * 通常価格も換算できず、この分岐に入らない）。
	 */
	public function test_push_product_does_not_send_the_regular_price_as_option_price_when_only_the_sale_price_is_unusable(): void {
		$captured = $this->push_two_variants(
			[ 'tax_type' => 'included' ],
			[ $this->color_variant( 'Red', '2200', 'abc' ), $this->color_variant( 'Blue', '2200' ) ]
		);

		$red = $this->find_captured( $captured, 'PUT', 'products/900/variants/9001.json' );
		$this->assertNotNull( $red );
		$this->assertArrayNotHasKey( 'option_price', $red['body']['variant'] );
		$this->assertArrayNotHasKey( 'option_market_price', $red['body']['variant'] );
		$this->assertSame( 'VAR-RED', $red['body']['variant']['model_number'] );
	}

	/**
	 * `CanonicalProduct::$variants`は外部アダプタ境界のため、`sale_price`が`ProductReader`の検証を
	 * 通っている保証が無い。0円・負値・通常価格超えの販売価格をそのまま`option_price`として送ると、
	 * バリエーションが無料・不正な価格になる（`to_push_amount()`はこれらをそのまま通す）ため、
	 * 価格フィールドを両方省く（PR #61 Copilot G3-1）。
	 *
	 * @dataProvider provide_unusable_sale_prices
	 */
	public function test_push_product_omits_variant_prices_when_the_sale_price_is_zero_negative_or_above_the_regular_price( string $sale_price ): void {
		$captured = $this->push_two_variants(
			[ 'tax_type' => 'included' ],
			[ $this->color_variant( 'Red', '2200', $sale_price ), $this->color_variant( 'Blue', '2200' ) ]
		);

		$red = $this->find_captured( $captured, 'PUT', 'products/900/variants/9001.json' );
		$this->assertNotNull( $red );
		$this->assertArrayNotHasKey( 'option_price', $red['body']['variant'] );
		$this->assertArrayNotHasKey( 'option_market_price', $red['body']['variant'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provide_unusable_sale_prices(): array {
		return [
			'zero'          => [ '0' ],
			'negative'      => [ '-10' ],
			'above regular' => [ '2500' ],
		];
	}

	/**
	 * 販売価格と定価が同額（換算の丸めで等しくなりうる境界）は有効な組として送る。
	 */
	public function test_push_product_accepts_a_sale_price_equal_to_the_regular_price(): void {
		$captured = $this->push_two_variants(
			[ 'tax_type' => 'included' ],
			[ $this->color_variant( 'Red', '2200', '2200' ), $this->color_variant( 'Blue', '2200' ) ]
		);

		$red = $this->find_captured( $captured, 'PUT', 'products/900/variants/9001.json' );
		$this->assertNotNull( $red );
		$this->assertSame( 2200, $red['body']['variant']['option_price'] );
		$this->assertSame( 2200, $red['body']['variant']['option_market_price'] );
	}

	/**
	 * バリエーションの通常価格を換算できない（数値でない。`CanonicalProduct::$variants`は外部アダプタ境界）場合は、
	 * 実売価格と比べられないため価格フィールドを両方省く（フェイルクローズ）。店舗の税設定が読めない場合は、商品の価格も
	 * 換算できず商品ごと送らない（R3-1d。`test_push_product_does_not_create_when_price_cannot_be_resolved`）。
	 */
	public function test_push_product_omits_variant_prices_when_the_variant_price_cannot_be_converted(): void {
		$captured = $this->push_two_variants(
			[ 'tax_type' => 'included' ],
			[ $this->color_variant( 'Red', 'n/a', '1980' ), $this->color_variant( 'Blue', '2200' ) ]
		);

		$red = $this->find_captured( $captured, 'PUT', 'products/900/variants/9001.json' );
		$this->assertNotNull( $red );
		$this->assertArrayNotHasKey( 'option_price', $red['body']['variant'] );
		$this->assertArrayNotHasKey( 'option_market_price', $red['body']['variant'] );
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

	public function test_push_product_pushes_images_when_premium_plan_and_image_upload_is_on(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);
		$this->enable_image_upload();

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
	 * D24: プレミアムプランでも画像アップロードは既定オフ。画像を一切送らず（ダウンロードも画像POSTもしない）、
	 * 従来の`PRODUCT_IMAGES_NOT_PUSHED`（情報提供の警告）を積む。商品自体は通常どおり作成される。
	 */
	public function test_push_product_does_not_push_images_on_premium_plan_while_the_setting_is_off(): void {
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
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 605 ] ] ] ],
				'PUT products/605.json' => [ [ 'body' => [ 'product' => [ 'id' => 605 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_product(
			$this->simple_product(
				[
					[
						'src'      => 'https://cdn.example.test/photo.jpg',
						'position' => 0,
					],
				]
			),
			null
		);

		$this->assertSame( '605', $result->remote_id );
		$this->assertSame( [ WarningCode::PRODUCT_IMAGES_NOT_PUSHED ], $result->warnings );
		$this->assertNull( $this->find_captured( $captured, 'POST', 'products/605/images.json' ) );
		$this->assertNull( $this->find_captured( $captured, 'GET', 'https://cdn.example.test/photo.jpg' ), 'オフのときは画像のダウンロードもしない' );
	}

	/**
	 * 設定は能力を作らない: 非プレミアム（画像POSTがプランで使えない）では、設定がオンでも画像を送らない。
	 */
	public function test_push_product_does_not_push_images_on_a_non_premium_plan_even_if_the_setting_is_on(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		$this->enable_image_upload();

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 606 ] ] ] ],
				'PUT products/606.json' => [ [ 'body' => [ 'product' => [ 'id' => 606 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_product(
			$this->simple_product(
				[
					[
						'src'      => 'https://cdn.example.test/photo.jpg',
						'position' => 0,
					],
				]
			),
			null
		);

		$this->assertSame( [ WarningCode::PRODUCT_IMAGES_NOT_PUSHED ], $result->warnings );
		$this->assertNull( $this->find_captured( $captured, 'POST', 'products/606/images.json' ) );
	}

	/**
	 * 壊れた設定値（`'true'`・`1`・配列等の型違い）は「オン」と解釈しない（フェイルクローズ。`(bool)`キャストだと
	 * `'false'`が true になり、明示的に選んでいない店舗で画像が送られる）。
	 */
	public function test_push_product_treats_a_malformed_image_setting_as_off(): void {
		foreach ( [ 'true', '1', 1, [ 'x' ] ] as $bad_value ) {
			remove_all_filters( 'pre_http_request' );
			update_option( ExportOptions::option_name( ColorMeAdapter::ID ), [ 'push_images' => $bad_value ], false );

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
					'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
					'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 607 ] ] ] ],
					'PUT products/607.json' => [ [ 'body' => [ 'product' => [ 'id' => 607 ] ] ] ],
				],
				$captured
			);

			$result = $adapter->push_product(
				$this->simple_product(
					[
						[
							'src'      => 'https://cdn.example.test/photo.jpg',
							'position' => 0,
						],
					]
				),
				null
			);

			$this->assertSame( [ WarningCode::PRODUCT_IMAGES_NOT_PUSHED ], $result->warnings, wp_json_encode( $bad_value ) );
			$this->assertNull( $this->find_captured( $captured, 'POST', 'products/607/images.json' ) );
		}
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
		$this->enable_image_upload();

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
		$this->enable_image_upload();

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
		$this->enable_image_upload();

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

	/**
	 * D21-A（issue #72）用: 作成POST（`POST products.json` → id=501）が成功した後、`$scenario`の
	 * リクエストで`$cause`が投げられる状況を組み立てて`push_product()`を呼ぶ。作成確定後の例外は
	 * 素のまま出さず`PartialPushException`（remote_id=501）に包まれる。
	 *
	 * @param 'followup_put'|'variant_detail'|'option_create'|'variant_put'|'image_download'|'image_upload' $scenario
	 */
	private function push_and_catch_partial( string $scenario, Throwable $cause ): PartialPushException {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		if ( str_starts_with( $scenario, 'image_' ) ) {
			$this->enable_image_upload();
		}

		$ok            = [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ];
		$image         = [
			[
				'src'      => 'https://cdn.example.test/photo.jpg',
				'position' => 0,
			],
		];
		$empty_detail  = [
			[
				'body' => [
					'product' => [
						'id'       => 501,
						'options'  => [],
						'variants' => [],
					],
				],
			],
		];
		$with_variants = [
			'body' => [
				'product' => [
					'id'       => 501,
					'options'  => [],
					'variants' => [
						[
							'id'            => 5011,
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
							'id'            => 5012,
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
		];

		$handlers = [
			'GET shop.json'      => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
			'POST products.json' => $ok,
		];
		$product  = $this->simple_product();

		switch ( $scenario ) {
			case 'followup_put':
				$handlers['PUT products/501.json'] = [ [ 'throw' => $cause ] ];
				break;
			case 'variant_detail':
				$product                           = $this->variable_product();
				$handlers['PUT products/501.json'] = $ok;
				$handlers['GET products/501.json'] = [ [ 'throw' => $cause ] ];
				break;
			case 'option_create':
				$product                                    = $this->variable_product();
				$handlers['PUT products/501.json']          = $ok;
				$handlers['GET products/501.json']          = $empty_detail;
				$handlers['POST products/501/options.json'] = [ [ 'throw' => $cause ] ];
				break;
			case 'variant_put':
				$product                                    = $this->variable_product();
				$handlers['PUT products/501.json']          = $ok;
				$handlers['GET products/501.json']          = [ $empty_detail[0], $with_variants ];
				$handlers['POST products/501/options.json'] = [
					[
						'body'   => [ 'option' => [ 'id' => 1 ] ],
						'status' => 201,
					],
				];
				$handlers['PUT products/501/variants/5011.json'] = [ [ 'throw' => $cause ] ];
				break;
			case 'image_download':
				$product                           = $this->simple_product( $image );
				$handlers['PUT products/501.json'] = $ok;
				$handlers['GET https://cdn.example.test/photo.jpg'] = [ [ 'throw' => $cause ] ];
				break;
			case 'image_upload':
				$product                           = $this->simple_product( $image );
				$handlers['PUT products/501.json'] = $ok;
				$handlers['GET https://cdn.example.test/photo.jpg'] = [ [ 'raw_body' => 'FAKE-JPEG-BYTES' ] ];
				$handlers['POST products/501/images.json']          = [ [ 'throw' => $cause ] ];
				break;
		}

		$this->mock_push_requests( $handlers );

		try {
			$adapter->push_product( $product, null );
		} catch ( PartialPushException $partial ) {
			return $partial;
		}

		$this->fail( "push_product() should have thrown a PartialPushException in the '{$scenario}' scenario." );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function interrupted_create_scenarios(): array {
		return [
			'follow-up PUT after the create POST' => [ 'followup_put' ],
			'variant detail GET'                  => [ 'variant_detail' ],
			'option creation POST'                => [ 'option_create' ],
			'variant PUT'                         => [ 'variant_put' ],
			'image upload POST'                   => [ 'image_upload' ],
		];
	}

	/**
	 * D21-A（issue #72）: 新規作成（POST）が成功してremote_idが確定した後、追いPUT・バリエーション・
	 * 画像のどこで`RateLimitExhaustedException`が出ても、素のまま出さず`PartialPushException`
	 * （作成された商品のremote_id付き）に包む。素のまま出すと`Sync\Exporter`がmappingを書けず、
	 * 再開時に同じ商品がもう一度POSTされて重複する。
	 *
	 * @dataProvider interrupted_create_scenarios
	 */
	public function test_push_product_wraps_a_rate_limit_after_creation_in_a_partial_push_exception( string $scenario ): void {
		$cause   = new RateLimitExhaustedException( 'colorme' );
		$partial = $this->push_and_catch_partial( $scenario, $cause );

		$this->assertSame( '501', $partial->remote_id() );
		$this->assertSame( $cause, $partial->getPrevious() );
	}

	/**
	 * RateLimit以外の予期しない例外（`try`の外側にあった画像バイナリの取得等）も、作成確定後なら
	 * 同じく包む。個別catchの取りこぼしで素の例外が出て重複する経路を残さない。
	 */
	public function test_push_product_wraps_an_unexpected_exception_after_creation_in_a_partial_push_exception(): void {
		$cause   = new RuntimeException( 'boom' );
		$partial = $this->push_and_catch_partial( 'image_download', $cause );

		$this->assertSame( '501', $partial->remote_id() );
		$this->assertSame( $cause, $partial->getPrevious() );
	}

	/**
	 * 更新（既存remote_idへのPUT）は包まない: mappingが既にあり、次回も同じPUTになるため重複しない
	 * （`RateLimitExhaustedException`は従来どおり素のまま伝播し、`JobManager`がジョブを一時停止する）。
	 */
	public function test_push_product_does_not_wrap_a_rate_limit_on_the_update_path(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'PUT products/501.json' => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
				'GET products/501.json' => [ [ 'throw' => new RateLimitExhaustedException( 'colorme' ) ] ],
			]
		);

		$this->expectException( RateLimitExhaustedException::class );
		$adapter->push_product( $this->variable_product(), '501' );
	}

	/**
	 * D21-A（issue #72）の結合確認（`Exporter` + `AdapterPlatformWriter` + `ColorMeAdapter`）:
	 * 作成POSTの直後の追いPUTでレート制限が出て中断した後、同じ商品を再度exportしても
	 * `POST products.json`は増えず、既存remote_idへのPUTになる（ColorMeに同じ商品が2つできない）。
	 */
	public function test_export_resumed_after_a_rate_limit_interruption_puts_the_created_product_instead_of_posting_it_again(): void {
		Activator::activate();

		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'GET shop.json'         => [ [ 'body' => [ 'shop' => [ 'tax_type' => 'included' ] ] ] ],
				'POST products.json'    => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
				// 1回目: 作成直後の追いPUTがレート制限で中断。2回目（再開後）: 更新PUTが成功する。
				'PUT products/501.json' => [
					[ 'throw' => new RateLimitExhaustedException( 'colorme' ) ],
					[ 'body' => [ 'product' => [ 'id' => 501 ] ] ],
				],
			],
			$captured
		);

		$mappings = new MappingRepository();
		$exporter = new Exporter( $mappings );
		$writer   = new AdapterPlatformWriter( $adapter );
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->simple_product() ) ] );

		try {
			$exporter->run_page( $adapter, $writer, $reader, 'product', Cursor::start(), false );
			$this->fail( 'RateLimitExhaustedException should propagate so that JobManager pauses the job.' );
		} catch ( RateLimitExhaustedException $caught ) {
			// 期待どおり: ジョブは一時停止し、後で同じページが再開される。
			$this->assertSame( ColorMeAdapter::ID, $caught->platform() );
		}

		$this->assertSame( '501', $mappings->find_remote_id( ColorMeAdapter::ID, 'product', 101 ) );
		$this->assertNull( $mappings->find_checksum( ColorMeAdapter::ID, 'product', '501' ) );

		$result = $exporter->run_page( $adapter, $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 0, $result['totals']['created'] );

		$posts = array_filter(
			$captured,
			static fn ( array $request ): bool => 'POST' === $request['method'] && str_contains( $request['url'], 'products.json' )
		);
		$this->assertCount( 1, $posts, '再開時に同じ商品を作成し直していないこと（POSTは最初の1回だけ）' );
		$this->assertSame( 1, $mappings->count( ColorMeAdapter::ID, 'product' ) );
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

	public function test_push_stock_sends_zero_stocks_when_managed_quantity_is_zero(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'PUT products/501.json' => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
			],
			$captured
		);

		$adapter->push_stock( new CanonicalStock( '501', null, 'SKU-1', 0, false ) );

		$request = $this->find_captured( $captured, 'PUT', 'products/501.json' );
		$this->assertNotNull( $request );
		$this->assertSame(
			[
				'stock_managed' => true,
				'stocks'        => 0,
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

	/**
	 * G1ゲート指摘（Codex）: バリエーション更新スキーマに`stock_managed`相当のフィールドが
	 * 無いため、商品全体が`stock_managed=false`のまま`variant.stocks`だけ送っても反映される
	 * 保証が無い（要検証#19）。反映されない場合の恒久的な在庫未同期を避けるため、バリエーション
	 * pushの前に商品側を明示的に`stock_managed:true`へ更新することを確認する。
	 */
	public function test_push_stock_updates_variant_when_quantity_is_managed(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests(
			[
				'PUT products/501.json'              => [ [ 'body' => [ 'product' => [ 'id' => 501 ] ] ] ],
				'PUT products/501/variants/701.json' => [ [ 'body' => [ 'variant' => [ 'id' => 701 ] ] ] ],
			],
			$captured
		);

		$result = $adapter->push_stock( new CanonicalStock( '501', '701', 'SKU-1-RED', 3, true ) );

		$this->assertSame( '701', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_UPDATED, $result->operation );

		$product_request = $this->find_captured( $captured, 'PUT', 'products/501.json' );
		$this->assertNotNull( $product_request );
		$this->assertSame( [ 'stock_managed' => true ], $product_request['body']['product'] );

		$variant_request = $this->find_captured( $captured, 'PUT', 'products/501/variants/701.json' );
		$this->assertNotNull( $variant_request );
		$this->assertSame( [ 'stocks' => 3 ], $variant_request['body']['variant'] );

		// 商品側の更新がバリエーション更新より先に送られていることを確認する
		// （順序を誤るとColorMe側が在庫管理をまだ認識しないままバリエーション数量を送ることになる）。
		$product_index = array_search( $product_request, $captured, true );
		$variant_index = array_search( $variant_request, $captured, true );
		$this->assertLessThan( $variant_index, $product_index );
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
	 * のいずれかを持つ連想配列。`throw`（`Throwable`）を持つレスポンスはHTTPを返さずその例外を
	 * 投げる（`RateLimiter::wait()`の枯渇〔`RateLimitExhaustedException`〕のように、リクエストが
	 * 出る前後に呼び出し元へ届く例外の再現用）。
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

					if ( ( $response['throw'] ?? null ) instanceof Throwable ) {
						throw $response['throw'];
					}

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

	/**
	 * 無料版の候補はカテゴリだけ（決済・配送・注文ステータスは `ColorMeCommerceAdapter`。R3-6c1）。決済・配送の API を呼ばない。
	 */
	public function test_mapping_candidates_returns_only_categories(): void {
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

		$candidates = $adapter->mapping_candidates();

		$this->assertSame( [ 'category' ], array_keys( $candidates ) );
		$this->assertSame( [ '2993030', '2993032' ], array_column( $candidates['category'], 'id' ) );
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

	public function test_an_unconnected_adapter_marks_the_failure_as_not_connected(): void {
		// ステータス 0 は通信断・JSON 破損でも使われるため、呼び出し側（push intent の解除・`Sync\Exporter` 等）が
		// 「再接続が必要」と一時的な通信断を区別できるよう、未接続は文脈で明示される。
		[ $adapter ] = $this->make_adapter();

		try {
			$adapter->fetch_product_by_remote_id( '1' );
			$this->fail( 'ApiException was not thrown' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 0, $exception->status_code() );
			$this->assertTrue( $exception->context()['not_connected'] );
		}
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
		// フェイルクローズすると、呼び出し側（`PushIntentResolver`）が実在する実体を「無い」と
		// 扱ってしまう。例外を投げて失敗として知らせる。
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
