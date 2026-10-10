<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Pro\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WC_Coupon;
use WC_Order;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * 顧客・受注・クーポンの種類が無料版の REST に出すもの（R3-6c1 で `RestControllerTest` から分けた）: 接続先の選択肢・マッピングの種類と
 * ASP 側の候補（`CommerceAdapter` から）・push intent の一覧と解除。
 */
final class CommerceRestControllerTest extends WP_UnitTestCase {

	use RegistersCommerceAdapters;

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->forget_commerce_adapters();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		parent::tear_down();
		$this->forget_commerce_adapters();
	}

	private function register_mock_adapter( ?MockCommerceAdapter $commerce = null ): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		if ( null !== $commerce ) {
			$this->register_commerce_adapter( $commerce );
		}
	}

	/**
	 * R3-6c2: 無料版だけで接続した（商品のスコープだけの）トークンでは、顧客・受注・クーポンを選択肢に出さず、足りないスコープを返す
	 * （画面が再接続を促す）。5 つを持つトークンは今までどおり。
	 */
	public function test_a_products_only_colorme_token_hides_the_commerce_entities_until_reconnected(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters[ ColorMeAdapter::ID ] = new ColorMeAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$connection = function ( array $scopes ): array {
			( new TokenStore( ColorMeAdapter::ID ) )->save(
				[
					'access_token' => 'token',
					'scopes'       => $scopes,
				]
			);
			AdapterRegistry::reset_cache();
			$this->forget_commerce_adapters();

			$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data()[0];

			return [
				'missing' => $data['missing_scopes'],
				'import'  => array_column( $data['entities']['import'], 'key' ),
				'export'  => array_column( $data['entities']['export'], 'key' ),
			];
		};

		$products_only = $connection( [ 'read_products', 'write_products' ] );

		$this->assertSame( [ 'read_sales', 'write_sales', 'read_shop_coupons' ], $products_only['missing'] );
		$this->assertSame( [], array_values( array_intersect( [ 'customer', 'order', 'coupon' ], $products_only['import'] ) ) );
		$this->assertSame( [], array_values( array_intersect( [ 'customer', 'order', 'coupon' ], $products_only['export'] ) ) );

		$all = $connection( [ 'read_products', 'write_products', 'read_sales', 'write_sales', 'read_shop_coupons' ] );

		$this->assertSame( [], $all['missing'] );
		$this->assertSame( [ 'customer', 'order', 'coupon' ], array_values( array_intersect( $all['import'], [ 'customer', 'order', 'coupon' ] ) ) );
		$this->assertContains( 'customer', $all['export'] );
	}

	/**
	 * 選択肢の説明と取込みの案内の印（R3-6b2）: 受注だけが説明を持ち、決済・配送の案内が要る。
	 */
	public function test_get_connections_describes_the_order_option_for_the_screen(): void {
		$this->register_mock_adapter( new MockCommerceAdapter() );

		$entities = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data()[0]['entities'];
		$export   = array_column( $entities['export'], 'description', 'key' );
		$import   = array_column( $entities['import'], 'mapping_notice', 'key' );

		$this->assertSame( 'Creates orders (sales) in the connected shop.', $export['order'] );
		$this->assertSame( '', $export['customer'] );
		$this->assertTrue( $import['order'] );
		$this->assertFalse( $import['customer'] );
	}

	/**
	 * 接続先に `CommerceAdapter` が無ければ、顧客・受注・クーポンは選択肢に出ない（R3-6c1）。
	 */
	public function test_get_connections_leaves_out_commerce_entities_without_a_commerce_adapter(): void {
		$this->register_mock_adapter();

		$entities = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data()[0]['entities'];

		foreach ( [ 'import', 'export' ] as $direction ) {
			$keys = array_column( $entities[ $direction ], 'key' );
			$this->assertNotContains( 'customer', $keys, $direction );
			$this->assertNotContains( 'order', $keys, $direction );
			$this->assertNotContains( 'coupon', $keys, $direction );
		}
	}

	/**
	 * 画面がマッピングの節を組み立てる文言（R3-6b2）。決済・配送・注文ステータスは受注の種類が持ち、`CommerceAdapter` のある接続先でだけ使う。
	 */
	public function test_get_settings_mappings_describes_the_order_kinds_for_the_screen(): void {
		$this->register_mock_adapter( new MockCommerceAdapter() );

		$kinds = array_column( $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/mock' ) )->get_data()['kinds'], null, 'key' );

		$this->assertSame( [ 'category', 'payment', 'shipping', 'status' ], array_keys( $kinds ) );
		$this->assertSame( 'order', $kinds['payment']['entity'] );
		$this->assertSame( 'Payment method mapping', $kinds['payment']['label'] );
		$this->assertSame( 'Platform payment method', $kinds['payment']['source_heading'] );
		$this->assertSame( '— Unmapped —', $kinds['payment']['unmapped_label'] );
		$this->assertTrue( $kinds['payment']['import_notice'] );
		$this->assertTrue( $kinds['payment']['applies'] );
		$this->assertSame( 'Shipping method mapping', $kinds['shipping']['label'] );
		$this->assertTrue( $kinds['shipping']['import_notice'] );
		$this->assertSame( '— Default —', $kinds['status']['unmapped_label'] );
		$this->assertFalse( $kinds['status']['import_notice'] );

		foreach ( [ 'payment', 'shipping', 'status' ] as $key ) {
			$this->assertNotSame( '', $kinds[ $key ]['description'], "{$key} の説明" );
			$this->assertNotSame( '', $kinds[ $key ]['no_targets_help'], "{$key} の候補が無いときの案内" );
		}
	}

	public function test_order_kinds_do_not_apply_without_a_commerce_adapter(): void {
		$this->register_mock_adapter();

		$data  = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/mock' ) )->get_data();
		$kinds = array_column( $data['kinds'], null, 'key' );

		foreach ( [ 'payment', 'shipping', 'status' ] as $key ) {
			$this->assertFalse( $kinds[ $key ]['applies'], $key );
			$this->assertSame( [], $data['asp_candidates'][ $key ], $key );
		}
	}

	/**
	 * 決済・配送・注文ステータスの ASP 側の候補は `CommerceAdapter` から取り（`MappingKind::platform_candidates()`。R3-6c1）、
	 * 保存時と同じ正規化を通す。アダプタの `mapping_candidates()` に同じキーがあっても使わない。
	 */
	public function test_get_settings_mappings_takes_the_order_candidates_from_the_commerce_adapter(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter(
					mapping_candidates_override: [
						'payment' => [
							[
								'id'   => '99',
								'name' => 'From the free adapter',
							],
						],
					]
				);

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
		$this->register_commerce_adapter(
			new MockCommerceAdapter(
				candidates: [
					'payment' => [
						[
							'id'   => '3',
							'name' => 'Bank transfer',
						],
						'not-an-array-item',
						[ 'id' => '4' ],
						[
							'id'   => ' 5 ',
							'name' => ' Card ',
						],
					],
					'status'  => [
						[
							'id'   => 'pending',
							'name' => 'Unpaid',
						],
					],
				]
			)
		);

		$candidates = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/mock' ) )->get_data()['asp_candidates'];

		$this->assertSame(
			[
				[
					'id'   => '3',
					'name' => 'Bank transfer',
				],
				[
					'id'   => '5',
					'name' => 'Card',
				],
			],
			$candidates['payment']
		);
		$this->assertSame( [], $candidates['shipping'] );
		$this->assertSame(
			[
				[
					'id'   => 'pending',
					'name' => 'Unpaid',
				],
			],
			$candidates['status']
		);
	}

	/**
	 * 未接続の ColorMe: 決済・配送の候補（API）は空に倒し、注文ステータスの候補（API を呼ばない固定値）は出す。以前は 1 つの
	 * `mapping_candidates()` の失敗で 4 つとも空になっていた（R3-6c1 で種類ごとに取るようにした）。
	 */
	public function test_colorme_order_candidates_without_a_connection(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters[ ColorMeAdapter::ID ] = new ColorMeAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$candidates = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/colorme' ) )->get_data()['asp_candidates'];

		$this->assertSame( [], $candidates['category'] );
		$this->assertSame( [], $candidates['payment'] );
		$this->assertSame( [], $candidates['shipping'] );
		$this->assertSame( [ 'pending', 'processing', 'completed', 'cancelled' ], array_column( $candidates['status'], 'id' ) );
	}

	public function test_list_push_intents_describes_customer_order_and_coupon_entities(): void {
		$this->register_mock_adapter( new MockCommerceAdapter() );
		// 受注の要約の日時は WooCommerce の書式とサイトのタイムゾーンで書く（R3-6b2）。
		update_option( 'timezone_string', 'Asia/Tokyo' );
		update_option( 'date_format', 'Y-m-d' );
		update_option( 'time_format', 'H:i' );

		$customer_id = self::factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'buyer@example.test',
			]
		);

		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->set_currency( 'JPY' );
		$order->set_total( '1234' );
		$order->set_date_created( '2026-01-02T03:04:05+00:00' );
		$order->save();
		$order_id = $order->get_id();

		$coupon = new WC_Coupon();
		$coupon->set_code( 'spring10' );
		$coupon_id = $coupon->save();

		$intents = new PushIntentRepository();
		$intents->begin( 'mock', 'customer', $customer_id, 'run-1', 11 );
		$intents->begin( 'mock', 'order', $order_id, 'run-1', 12 );
		$intents->begin( 'mock', 'coupon', $coupon_id, 'run-1', 13 );

		$response = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/push-intents/mock' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $data['intents'] );

		$by_entity = array_column( $data['intents'], null, 'entity_type' );

		$this->assertTrue( $by_entity['customer']['exists'] );
		$this->assertSame( 'buyer@example.test', $by_entity['customer']['details']['email'] );
		$this->assertTrue( $by_entity['order']['exists'] );
		$this->assertArrayHasKey( 'number', $by_entity['order']['details'] );
		$this->assertArrayHasKey( 'total', $by_entity['order']['details'] );
		$this->assertSame( 'buyer@example.test', $by_entity['customer']['summary'] );
		$this->assertSame(
			sprintf( '#%s — %s JPY (2026-01-02 12:04)', wc_get_order( $order_id )->get_order_number(), wc_get_order( $order_id )->get_total() ),
			$by_entity['order']['summary']
		);
		$this->assertSame( 'spring10', $by_entity['coupon']['summary'] );
	}

	/**
	 * クーポンはリモートの ID 指定取得が無いので、「リンクして解除」はアダプタを呼ばずに 422（LINK_UNSUPPORTED）。
	 */
	public function test_resolve_push_intent_link_is_unsupported_for_coupons(): void {
		$this->register_mock_adapter( new MockCommerceAdapter( fetch_by_id_failure: new \RuntimeException( 'should not be called' ) ) );

		$coupon = new WC_Coupon();
		$coupon->set_code( 'link-me' );
		$coupon_id = $coupon->save();

		$intents = new PushIntentRepository();
		$intents->begin( 'mock', 'coupon', $coupon_id, null, null );
		$id = $intents->find_unresolved( 'mock' )[0]['id'];

		$request = new WP_REST_Request( 'POST', "/cbjp/v1/push-intents/mock/{$id}/resolve" );
		$request->set_body_params(
			[
				'action'    => 'link',
				'remote_id' => 'anything',
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'cbjp_link_unsupported', $response->as_error()->get_error_code() );
		$this->assertTrue( $intents->has_unresolved( 'mock', 'coupon', $coupon_id ) );
	}

	/**
	 * 決済・配送・注文ステータスのマップ（受注の種類が持つ）も、空なら JSON オブジェクトで返し、保存・読み戻しできる（R3-6c1 で
	 * 無料版の `RestControllerTest` から分けた）。配送方法インスタンス ID のコロンは壊さない。
	 */
	public function test_order_maps_are_saved_and_read_back(): void {
		$this->register_mock_adapter( new MockCommerceAdapter() );

		$empty = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/mock' ) )->get_data();
		$this->assertSame(
			[
				'payment_map'  => [],
				'shipping_map' => [],
				'status_map'   => [],
			],
			array_intersect_key( json_decode( (string) wp_json_encode( $empty ), true ), array_flip( [ 'payment_map', 'shipping_map', 'status_map' ] ) )
		);
		$this->assertSame( '{}', wp_json_encode( $empty['payment_map'] ) );

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/mock' );
		$request->set_body_params(
			[
				'payment_map'  => [ '3' => 'bacs' ],
				'shipping_map' => [ '5' => 'flat_rate:1' ],
				'status_map'   => [ 'pending' => 'on-hold' ],
			]
		);
		$this->assertSame( 200, $this->server->dispatch( $request )->get_status() );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/mock' ) )->get_data();
		$this->assertEquals( (object) [ '3' => 'bacs' ], $data['payment_map'] );
		$this->assertEquals( (object) [ '5' => 'flat_rate:1' ], $data['shipping_map'] );
		$this->assertEquals( (object) [ 'pending' => 'on-hold' ], $data['status_map'] );

		delete_option( 'cbjp_settings_mock' );
	}

	public function test_order_kinds_list_the_woo_payment_gateways(): void {
		$this->register_mock_adapter( new MockCommerceAdapter() );

		$woo_candidates = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/mock' ) )->get_data()['woo_candidates'];

		$this->assertSame( [ 'category', 'payment', 'shipping', 'status' ], array_keys( $woo_candidates ) );
		// テスト環境のWooCommerceはデフォルトゲートウェイ（bacs等）を登録済み。
		$this->assertContains( 'bacs', array_column( $woo_candidates['payment'], 'id' ) );
	}
}
