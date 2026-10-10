<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Pro\Adapters\ColorMe\ColorMeCommerceAdapter;
use CartBridgeJP\Pro\Canonical\CanonicalCoupon;
use CartBridgeJP\Pro\Canonical\CanonicalCustomer;
use CartBridgeJP\Pro\Canonical\CanonicalOrder;
use CartBridgeJP\Pro\Tests\Fixtures\CommerceFactory;
use CartBridgeJP\Pro\Tests\Fixtures\FixtureLoader;
use CartBridgeJP\Pro\Woo\CommerceWarningCode;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\Exporter;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\FixedWooReader;
use CartBridgeJP\Woo\Export\AdapterPlatformWriter;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_UnitTestCase;

/**
 * カラーミーショップの顧客・受注・クーポン（R3-6c1 で `ColorMeAdapterTest` から分けた。`ColorMeCommerceAdapter`）。
 */
final class ColorMeCommerceAdapterTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'cbjp_settings_colorme' );
		parent::tear_down();
	}

	/**
	 * @return array{0:ColorMeCommerceAdapter,1:TokenStore,2:string}
	 */
	private function make_adapter(): array {
		$platform    = 'test-colorme-adapter-' . wp_generate_uuid4();
		$token_store = new TokenStore( $platform );

		return [ new ColorMeCommerceAdapter( new ColorMeAdapter( $token_store ) ), $token_store, $platform ];
	}

	public function test_id_is_the_colorme_platform(): void {
		[ $adapter ] = $this->make_adapter();

		$this->assertSame( ColorMeAdapter::ID, $adapter->id() );
	}

	public function test_capabilities_static_values_match_01_plan_colorme(): void {
		[ $adapter ]  = $this->make_adapter();
		$capabilities = $adapter->capabilities();

		$this->assertTrue( $capabilities->can_fetch_customers );
		$this->assertTrue( $capabilities->can_update_customer );
		$this->assertTrue( $capabilities->has_coupons );
		$this->assertFalse( $capabilities->can_create_coupon );
	}

	/**
	 * D24: プレミアム限定の受注エクスポートはベータ版。プラン（＝能力の有無）に関わらず静的に宣言し、項目を出すかどうかは
	 * `can_create_order` が決める。
	 */
	public function test_order_export_is_beta_regardless_of_plan(): void {
		[ $adapter, $token_store ] = $this->make_adapter();

		$this->assertTrue( $adapter->capabilities()->order_export_beta, '未接続（プラン不明）' );

		$token_store->save(
			[
				'access_token' => 'token',
				'extras'       => [ 'contract_plan' => 'premium' ],
			]
		);

		$this->assertTrue( $adapter->capabilities()->order_export_beta );
	}

	public function test_payment_shipping_and_status_candidates(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
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

		$this->assertSame( [ '1094475', '1094978' ], array_column( $adapter->payment_candidates(), 'id' ) );
		$this->assertSame( [ '640580' ], array_column( $adapter->shipping_candidates(), 'id' ) );
		// APIを叩かない固定4値（`OrderTransformer::status()`が返しうるcanonicalステータス）。
		$this->assertSame( [ 'pending', 'processing', 'completed', 'cancelled' ], array_column( $adapter->status_candidates(), 'id' ) );
	}

	public function test_an_unconnected_adapter_marks_the_failure_as_not_connected(): void {
		// ステータス 0 は通信断・JSON 破損でも使われるため、呼び出し側（push intent の解除・`Sync\Exporter` 等）が
		// 「再接続が必要」と一時的な通信断を区別できるよう、未接続は文脈で明示される（無料版の `ColorMeApi` と同じ）。
		[ $adapter ] = $this->make_adapter();

		try {
			$adapter->fetch_order_by_remote_id( '1' );
			$this->fail( 'ApiException was not thrown' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 0, $exception->status_code() );
			$this->assertTrue( $exception->context()['not_connected'] );
		}
	}

	public function test_coupons_cannot_be_pushed(): void {
		[ $adapter ] = $this->make_adapter();

		$this->expectException( UnsupportedOperationException::class );

		$adapter->push_coupon( new CanonicalCoupon( 'C1', 'fixed', '100', null, null, null, [ 'remote_id' => '1' ], has_unsupported_restrictions: false ), null );
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

		$result = $adapter->push_customer( CommerceFactory::customer( '999', 'taro@example.com' ), null );

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ CommerceWarningCode::CUSTOMER_REQUIRED_FIELD_MISSING ], $result->warnings );
		$this->assertSame( [], $captured );
	}

	/**
	 * 更新（PUT）も名前と住所が無いと422になる（swagger と違う。テストショップで実測。issue #100）。住所3点を解決できない顧客
	 * （ここでは郵便番号の無い海外の住所）の更新は、PUT を送らずに作成と同じ警告でスキップする。
	 */
	public function test_push_customer_skips_an_update_when_the_address_cannot_be_resolved(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$captured = [];
		$this->mock_push_requests( [], $captured );

		$customer = new CanonicalCustomer(
			'overseas@example.com',
			'Example Overseas',
			null,
			null,
			null,
			[
				'city'      => 'Hong Kong',
				'address_1' => '1 Example Road',
				'country'   => 'HK',
			],
			'0300000005',
			null,
			null,
			null
		);

		$result = $adapter->push_customer( $customer, '701' );

		$this->assertSame( '', $result->remote_id );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ CommerceWarningCode::CUSTOMER_REQUIRED_FIELD_MISSING ], $result->warnings );
		$this->assertSame( [], $captured );
	}

	/**
	 * issue #100 の結合確認（`Exporter` + `AdapterPlatformWriter` + `ColorMeCommerceAdapter`）: 住所のそろわない顧客の更新は、
	 * 以前のような 422 の 1 件失敗（エラーログ）ではなく、警告つきのスキップになる。既存の mapping（remote_id・checksum）は残る。
	 */
	public function test_exporting_a_customer_update_without_an_address_skips_with_a_warning_and_keeps_the_mapping(): void {
		Activator::activate();

		[ , $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		// エクスポートは無料版のアダプタを渡す（顧客の送信は `CommerceAdapters` が `ColorMeCommerceAdapter` を引く）。
		$adapter = new ColorMeAdapter( $token_store );

		$captured = [];
		$this->mock_push_requests( [], $captured );

		$mappings = new MappingRepository();
		$checksum = str_repeat( 'a', 64 );
		$mappings->upsert( ColorMeAdapter::ID, 'customer', '701', 201, $checksum );

		$customer = new CanonicalCustomer( 'woo@example.com', '山田 花子', null, null, null, [ 'country' => 'JP' ], '0300000001', null, null, null );
		$reader   = new FixedWooReader( [ new ReadItem( 201, $customer ) ] );

		$result = ( new Exporter( $mappings ) )->run_page( $adapter, new AdapterPlatformWriter( $adapter ), $reader, 'customer', Cursor::start(), false, 9301 );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertSame( 0, $result['totals']['updated'] );
		$this->assertSame( [], $captured, 'PUT を送らない' );
		$this->assertSame( '701', $mappings->find_remote_id( ColorMeAdapter::ID, 'customer', 201 ) );
		$this->assertSame( $checksum, $mappings->find_checksum( ColorMeAdapter::ID, 'customer', '701' ) );
		$this->assertSame( [], ( new LogRepository() )->list( 9301 ), '例外の 1 件失敗（エラーログ）にしない' );
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
		$this->assertSame( [ CommerceWarningCode::ORDER_UPDATE_NOT_SUPPORTED ], $result->warnings );
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
			[ CommerceWarningCode::ORDER_UPDATE_NOT_SUPPORTED, CommerceWarningCode::ORDER_DISCOUNT_NOT_PUSHED ],
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
			[ CommerceWarningCode::ORDER_UPDATE_NOT_SUPPORTED, CommerceWarningCode::ORDER_FEE_NOT_PUSHED ],
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
		$this->assertSame( [ CommerceWarningCode::ORDER_PLACED_AT_NOT_PRESERVED ], $result->warnings );

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
			[ CommerceWarningCode::ORDER_DISCOUNT_NOT_PUSHED, CommerceWarningCode::ORDER_PLACED_AT_NOT_PRESERVED ],
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
			[ CommerceWarningCode::ORDER_FEE_NOT_PUSHED, CommerceWarningCode::ORDER_PLACED_AT_NOT_PRESERVED ],
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
				WarningCode::with_detail( CommerceWarningCode::PAYMENT_METHOD_UNMAPPED, 'bacs' ),
				WarningCode::with_detail( CommerceWarningCode::SHIPPING_METHOD_UNMAPPED, 'flat_rate:6' ),
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
		// 受注の請求先（customer_snapshot）と配送先（shipping）の生の `pref_id` は Canonical 経由で Writer に届く
		// （issue #46。R3-6a で削除した県コード修復もこれを使っていた）。フィクスチャの `pref_id=13`（東京）は表の固定点で層間のズレを
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
		// nullに握り潰すと、呼び出し側（push intent の解除等）が「ASP側で削除済み」と誤解して
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

	public function test_fetch_orders_propagates_lookup_failures_instead_of_swallowing_them(): void {
		// `payments.json`の取得失敗（認証切れ等の基盤障害）を、行単位の変換失敗と同じ扱いで
		// 握り潰すと、ページ全体が0件のまま「成功」として完了し受注が黙って取り込まれない
		// （`fetch_order_by_remote_id()`と同じ理由。issue #69）。
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );

		$this->respond_from_map(
			[
				'sales.json'    => [
					'status' => 200,
					'body'   => FixtureLoader::load( 'colorme', 'sales' ),
				],
				'payments.json' => [
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

		try {
			$adapter->fetch_orders( Cursor::start() );
			$this->fail( 'ApiException was not thrown' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 401, $exception->status_code() );
		}
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
