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
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use RuntimeException;
use WP_Error;
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

	public function test_fetch_reviews_is_not_supported(): void {
		[ $adapter ] = $this->make_adapter();

		$this->expectException( UnsupportedOperationException::class );

		$adapter->fetch_reviews( Cursor::start() );
	}

	public function test_fetch_products_reads_envelope_and_reports_total_from_meta(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = null;
		$fixture  = FixtureLoader::load( 'colorme', 'products' );
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args, $url ) use ( &$captured, $fixture ) {
				$captured = $url;

				return $this->json_response( $fixture );
			},
			10,
			3
		);

		$page = $adapter->fetch_products( Cursor::start() );

		$this->assertCount( 4, $page->items );
		$this->assertSame( 4, $page->total );
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
	 *
	 * @param array<string,array<string,mixed>> $map
	 */
	private function respond_from_map( array $map ): void {
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
