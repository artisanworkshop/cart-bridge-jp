<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Writer\CustomerWriter;
use WC_Product_Simple;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class RestControllerTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		// Plugin::boot()が実プロセスの`plugins_loaded`で登録する本物のColorMeAdapterを含め、
		// 前のテストの残留状態から独立させる（各テストが必要な分だけ明示的に登録し直す）。
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		parent::tear_down();
	}

	public function test_get_connections_is_empty_when_no_adapters_registered(): void {
		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
	}

	public function test_get_connections_lists_registered_adapters(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'mock', $data[0]['platform'] );
		$this->assertFalse( $data[0]['connected'] );
	}

	public function test_get_connections_omits_callback_url_for_non_oauth_platforms(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertNull( $data[0]['callback_url'] );
	}

	/**
	 * ASP側のアプリ登録フォームに入力するコールバックURIは、client_id/secretの有無に
	 * 関わらず算出できる静的な値のため、認証情報保存前（アプリ登録段階）から取得できる
	 * ことを検証する（認可URL取得エンドポイントは認証情報必須のため、そちらでは提示できない）。
	 */
	public function test_get_connections_includes_callback_url_for_oauth_platforms_before_credentials_are_saved(): void {
		$this->register_colorme_adapter();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertStringContainsString( 'cbjp/v1/connect/colorme/callback', urldecode( (string) $data[0]['callback_url'] ) );
	}

	public function test_get_connections_ignores_non_connection_field_entries_from_a_misbehaving_adapter(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				// 外部フィルター経由で登録されるアダプタ（Pro拡張含む）の契約違反シナリオ:
				// connection_fields()がConnectionField以外を混入させても、エンドポイント全体を
				// 落とさず、不正な要素だけを除外できることを検証する。
				$adapters['mock'] = new MockPlatformAdapter( connection_fields_override: [ 'not-a-connection-field', null ] );

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $data[0]['connection_fields'] );
	}

	public function test_get_connections_reindexes_connection_fields_after_filtering(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				// 不正要素が有効なConnectionFieldの間に混在するケース。array_filter()が
				// キーを保持したまま（0と2など）返すと、wp_json_encode()がJSON配列では
				// なくオブジェクトとして直列化し、UI側のconnection_fields.filter()が
				// クラッシュするため、連番へ詰め直されることを検証する。
				$adapters['mock'] = new MockPlatformAdapter(
					connection_fields_override: [
						'not-a-connection-field',
						new ConnectionField( 'api_token', 'API Token', 'password', true ),
						null,
					]
				);

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 0 ], array_keys( $data[0]['connection_fields'] ) );
		$this->assertSame( 'api_token', $data[0]['connection_fields'][0]['key'] );
	}

	public function test_unauthenticated_request_is_forbidden(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_dry_run_start_and_poll_round_trip(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'dry_run',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$run_id = $response->get_data()['run_id'];
		$this->assertIsString( $run_id );

		$poll_request  = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}" );
		$poll_response = $this->server->dispatch( $poll_request );

		$this->assertSame( 200, $poll_response->get_status() );
		$this->assertSame( $run_id, $poll_response->get_data()['run_id'] );
	}

	public function test_import_type_starts_a_run(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'import',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertIsString( $response->get_data()['run_id'] );
	}

	public function test_export_type_is_not_yet_implemented(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'export',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 501, $response->get_status() );
	}

	public function test_start_run_rejects_an_unknown_type_as_a_bad_request_not_export(): void {
		// typoや不正な値は「エクスポート未実装」(501)ではなく、JobManagerの型検証による
		// 400として扱われるべき（実際にはエクスポートを要求していないため）。
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'not-a-real-type',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_start_run_returns_404_for_an_unknown_platform(): void {
		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'dry_run',
				'platform' => 'not-a-real-platform',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'cbjp_unknown_platform', $response->as_error()->get_error_code() );
	}

	public function test_start_run_treats_an_array_valued_type_as_a_bad_request_not_a_literal_array_string(): void {
		// このルートにはargsスキーマ（type検証）が無いため`?type[]=import`のように配列でも
		// 渡り得る。配列を(string)キャストすると"Array to string conversion"警告付きで
		// リテラル文字列"Array"になり、意図しない404/400を誤答してしまう（CLAUDE.md参照）。
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => [ 'import' ],
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_start_run_treats_an_array_valued_platform_as_unknown_not_a_literal_array_string(): void {
		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'dry_run',
				'platform' => [ 'mock' ],
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_start_run_filters_non_scalar_entities_instead_of_stringifying_them(): void {
		// `entities`の各要素も同じ理由でスカラーのみ受け付ける。`array_map('strval', ...)`に
		// ネストした配列要素をそのまま渡すと同じ警告・誤変換が起きる。
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'import',
				'platform' => 'mock',
				'entities' => [ 'category', [ 'nested' ] ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_list_logs_treats_array_valued_query_params_as_no_filter(): void {
		$logger = new \CartBridgeJP\Support\Logger();
		$logger->info( 'first log entry' );
		$logger->warning( 'second log entry' );

		// job_id/level にargsスキーマの型検証がないため、`?job_id[]=1&job_id[]=2` のような
		// 配列値が渡り得る。配列を(int)/(string)キャストして誤ったフィルタになっていないか検証する。
		$request = new WP_REST_Request( 'GET', '/cbjp/v1/logs' );
		$request->set_query_params(
			[
				'job_id' => [ '1', '2' ],
				'level'  => [ 'info' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data() );
	}

	private function register_colorme_adapter(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters[ ColorMeAdapter::ID ] = new ColorMeAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
	}

	public function test_save_connection_persists_only_recognized_fields(): void {
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/connections/colorme' );
		$request->set_body_params(
			[
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
				'unknown_field' => 'should-be-ignored',
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$settings = ( new TokenStore( ColorMeAdapter::ID ) )->settings();
		$this->assertSame( 'my-client-id', $settings['client_id'] );
		$this->assertSame( 'my-client-secret', $settings['client_secret'] );
		$this->assertArrayNotHasKey( 'unknown_field', $settings );
	}

	public function test_save_connection_preserves_opaque_credential_values(): void {
		$this->register_colorme_adapter();

		// 資格情報はプロバイダ発行の不透明な値であり、%エンコード列やHTML風の
		// 文字列を含み得る。sanitize_text_field()はこれらを除去して正しい値を
		// 壊すため、貼り付けた値がそのまま保存されることを検証する
		// （前後空白のtrimのみ許容）。
		$secret = 'ab%3Dcd<ef>&"quote"+/=';

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/connections/colorme' );
		$request->set_body_params(
			[
				'client_id'     => ' my-client-id ',
				'client_secret' => $secret,
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$settings = ( new TokenStore( ColorMeAdapter::ID ) )->settings();
		$this->assertSame( 'my-client-id', $settings['client_id'] );
		$this->assertSame( $secret, $settings['client_secret'] );
	}

	public function test_get_connections_reports_saved_settings_before_oauth_completes(): void {
		$this->register_colorme_adapter();

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data();
		$this->assertFalse( $data[0]['has_settings'] );

		// client_id/secretを保存したがOAuthを完了していない状態。UIが資格情報の
		// 削除操作を出せるよう、未接続でもhas_settingsで区別できることを検証する。
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings( [ 'client_id' => 'my-client-id' ] );

		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data();
		$this->assertTrue( $data[0]['has_settings'] );
		$this->assertFalse( $data[0]['connected'] );
	}

	public function test_save_connection_returns_404_for_unknown_platform(): void {
		$request  = new WP_REST_Request( 'PUT', '/cbjp/v1/connections/not-a-real-platform' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_save_connection_ignores_conflicting_scalar_body_param(): void {
		// save_settings_mappings()と同じ理由（PUT/DELETE等はボディがURLパスより優先して
		// マージされる）で、save_connection()もボディに紛れ込んだ`platform`に惑わされず
		// URLパス（colorme）へ保存することを検証する（platform_param()参照）。
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/connections/colorme' );
		$request->set_body_params(
			[
				'platform'      => 'not-a-real-platform',
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'my-client-id', ( new TokenStore( ColorMeAdapter::ID ) )->settings()['client_id'] );
	}

	public function test_delete_connection_ignores_conflicting_scalar_body_param(): void {
		$this->register_colorme_adapter();
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings( [ 'client_id' => 'my-client-id' ] );

		$request = new WP_REST_Request( 'DELETE', '/cbjp/v1/connections/colorme' );
		$request->set_body_params( [ 'platform' => 'not-a-real-platform' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], ( new TokenStore( ColorMeAdapter::ID ) )->settings() );
	}

	public function test_get_settings_mappings_defaults_to_empty_maps(): void {
		$this->register_colorme_adapter();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/colorme' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		// 空マップも常にJSONオブジェクトとして返す（`test_get_settings_mappings_serializes_empty_maps_as_json_objects`
		// 参照）ため、レスポンスデータ自体も`array`ではなく`stdClass`。
		$this->assertEquals( (object) [], $data['payment_map'] );
		$this->assertEquals( (object) [], $data['shipping_map'] );
		$this->assertEquals( (object) [], $data['status_map'] );
	}

	public function test_get_settings_mappings_returns_404_for_unknown_platform(): void {
		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/not-a-real-platform' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_save_settings_mappings_persists_and_is_read_back(): void {
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_body_params(
			[
				'payment_map'  => [ '3' => 'bacs' ],
				'shipping_map' => [ '5' => 'flat_rate:1' ],
				'status_map'   => [ 'pending' => 'on-hold' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'bacs', $data['payment_map']->{'3'} );
		// Woo配送方法インスタンスIDのコロンが破壊されず保持されることを確認する
		// （`sanitize_key()`はコロンを除去するため使っていない）。
		$this->assertSame( 'flat_rate:1', $data['shipping_map']->{'5'} );

		$get_data = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/colorme' ) )->get_data();
		$this->assertEquals( (object) [ '3' => 'bacs' ], $get_data['payment_map'] );
		$this->assertEquals( (object) [ '5' => 'flat_rate:1' ], $get_data['shipping_map'] );
		$this->assertEquals( (object) [ 'pending' => 'on-hold' ], $get_data['status_map'] );
	}

	public function test_save_settings_mappings_omitted_key_preserves_existing_value(): void {
		$this->register_colorme_adapter();

		$first = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$first->set_body_params( [ 'payment_map' => [ '3' => 'bacs' ] ] );
		$this->server->dispatch( $first );

		// shipping_mapを省略した2回目の保存が、1回目に保存したpayment_mapを消さないこと
		// （UIが1種類だけ編集した場合に他方を意図せず消さないための仕様）を確認する。
		$second = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$second->set_body_params( [ 'shipping_map' => [ '5' => 'flat_rate:1' ] ] );
		$response = $this->server->dispatch( $second );

		$data = $response->get_data();
		$this->assertSame( 'bacs', $data['payment_map']->{'3'} );
		$this->assertSame( 'flat_rate:1', $data['shipping_map']->{'5'} );
	}

	public function test_save_settings_mappings_explicit_empty_map_clears_existing_value(): void {
		$this->register_colorme_adapter();

		$first = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$first->set_body_params( [ 'payment_map' => [ '3' => 'bacs' ] ] );
		$this->server->dispatch( $first );

		// キーを省略した場合は既存値を保持する（上のテスト）のに対し、キーを明示的に
		// 空配列で送った場合は実際にクリアされることを確認する（両方向の経路を検証）。
		$second = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$second->set_body_params( [ 'payment_map' => [] ] );
		$response = $this->server->dispatch( $second );

		$this->assertEquals( (object) [], $response->get_data()['payment_map'] );

		$get_response = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/colorme' ) );
		$this->assertEquals( (object) [], $get_response->get_data()['payment_map'] );
	}

	public function test_save_settings_mappings_rejects_non_object_map(): void {
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_body_params( [ 'payment_map' => 'not-an-object' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_save_settings_mappings_rejects_entry_with_non_scalar_value_instead_of_dropping_it(): void {
		// 読取専用の`sanitize_settings_map()`を書込みにも流用していた際、非スカラー値の
		// エントリ（例: `{"1094475":[]}`）だけを黙って読み飛ばし、送信したマップが1件しか
		// 無ければ結果は空マップでの全置換となり、200が返るのに正当な既存マッピングが
		// 消えてしまっていた（Codexレビュー指摘）。書込み側は1件でも不正なエントリが
		// あればリクエスト全体を拒否し、既存の正当なマッピングを消さないことを確認する。
		$this->register_colorme_adapter();
		update_option( 'cbjp_settings_colorme', [ 'payment_map' => [ '3' => 'bacs' ] ] );

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_body_params( [ 'payment_map' => [ '1094475' => [] ] ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'bacs', get_option( 'cbjp_settings_colorme' )['payment_map']['3'] );

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_save_settings_mappings_rejects_json_list(): void {
		// JSONリスト（例: `["bacs","cod"]`）は`is_array()`だけでは弾けず、連番インデックスを
		// キーとする無意味なマッピング（`{"0":"bacs","1":"cod"}`）として保存されてしまう
		// ため、明示的に拒否することを確認する（空配列＝全クリアの意図は許容する。
		// `test_save_settings_mappings_explicit_empty_map_clears_existing_value`参照）。
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_body_params( [ 'payment_map' => [ 'bacs', 'cod' ] ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_save_settings_mappings_returns_404_for_unknown_platform(): void {
		$request  = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/not-a-real-platform' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_settings_mappings_ignores_array_query_param(): void {
		// このルートは`args`スキーマを定義していないため、`WP_REST_Request::get_params()`は
		// GETリクエストでクエリ文字列をURLパスより優先してマージする
		// （`get_parameter_order()`参照）。`platform_param()`はURLキャプチャのみを見るため、
		// `?platform[]=x`のような配列値のクエリパラメータがあっても影響を受けず、
		// URLパスが指すリソース（`colorme`）がそのまま使われることを確認する
		// （Codexレビュー指摘。以前の`(string)`キャストは配列に対して警告付きで
		// `'Array'`という誤ったプラットフォームIDになっていた）。
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_query_params( [ 'platform' => [ 'not-a-real-platform' ] ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_get_settings_mappings_ignores_conflicting_scalar_query_param(): void {
		// `platform`が配列でなくスカラーの別プラットフォーム名であっても、クエリ文字列は
		// GETリクエストでURLパスより優先してマージされるため、以前は上書きが通ってしまい
		// URLが名指ししたリソースとは異なる`cbjp_settings_{platform}`を読んでしまっていた
		// （Codexレビュー指摘）。URLキャプチャのみを見ることでこれを構造的に防ぐ。
		$this->register_colorme_adapter();
		update_option( 'cbjp_settings_colorme', [ 'payment_map' => [ '3' => 'bacs' ] ] );

		$request = new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_query_params( [ 'platform' => 'not-a-real-platform' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'bacs', $response->get_data()['payment_map']->{'3'} );

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_save_settings_mappings_ignores_array_body_param(): void {
		// PUT/POST等ではボディがURLパスより優先してマージされるため、上と同じ問題が
		// ボディ側の`platform`キーでも起こりうる。URLキャプチャのみを見ることで
		// URLパスが指すリソース（`colorme`）へ正しく保存されることを確認する。
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_body_params(
			[
				'platform'    => [ 'not-a-real-platform' ],
				'payment_map' => [ '3' => 'bacs' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'bacs', get_option( 'cbjp_settings_colorme' )['payment_map']['3'] );
		$this->assertFalse( get_option( 'cbjp_settings_not-a-real-platform' ) );

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_save_settings_mappings_ignores_conflicting_scalar_body_param(): void {
		// ボディに`platform`という別プラットフォーム名のスカラー値を混ぜても、PUT/POST等では
		// ボディがURLパスより優先してマージされるため、以前は上書きが通ってしまい
		// URLが名指ししたのとは異なる`cbjp_settings_{platform}`を書き換えてしまっていた
		// （Codexレビュー指摘）。URLキャプチャのみを見ることでこれを構造的に防ぐ。
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/colorme' );
		$request->set_body_params(
			[
				'platform'    => 'not-a-real-platform',
				'payment_map' => [ '3' => 'bacs' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'bacs', get_option( 'cbjp_settings_colorme' )['payment_map']['3'] );
		$this->assertFalse( get_option( 'cbjp_settings_not-a-real-platform' ) );

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_get_settings_mappings_serializes_empty_maps_as_json_objects(): void {
		// PHPの空配列`[]`は`wp_json_encode()`でJSON配列`[]`になり、値がある場合の
		// JSONオブジェクト`{"3":"bacs"}`と型が食い違う（クライアント側が
		// `Record<string,string>`として一貫した型を期待できない。Codexレビュー指摘）。
		$this->register_colorme_adapter();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/colorme' );
		$response = $this->server->dispatch( $request );

		$json = wp_json_encode( $response->get_data() );
		$this->assertSame( '{"payment_map":{},"shipping_map":{},"status_map":{}}', $json );
	}

	public function test_get_authorize_url_requires_credentials_to_be_saved_first(): void {
		$this->register_colorme_adapter();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections/colorme/authorize-url' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_get_authorize_url_returns_a_url_once_credentials_are_saved(): void {
		$this->register_colorme_adapter();
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings(
			[
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
			]
		);

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections/colorme/authorize-url' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'client_id=my-client-id', $data['url'] );
		// テスト環境はデフォルトパーマリンク（`index.php?rest_route=...`）のため、pretty permalink
		// 前提の `/wp-json/...` 形式では書けない。urldecode後にルートパスが含まれることだけ検証する。
		$this->assertStringContainsString( 'cbjp/v1/connect/colorme/callback', urldecode( $data['redirect_uri'] ) );
	}

	public function test_get_authorize_url_oob_mode_uses_the_oob_redirect_uri(): void {
		$this->register_colorme_adapter();
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings(
			[
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
			]
		);

		$request = new WP_REST_Request( 'GET', '/cbjp/v1/connections/colorme/authorize-url' );
		$request->set_query_params( [ 'mode' => 'oob' ] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'urn:ietf:wg:oauth:2.0:oob', $data['redirect_uri'] );
		$this->assertStringNotContainsString( 'state=', $data['url'] );
	}

	public function test_exchange_code_endpoint_completes_the_connection(): void {
		$this->register_colorme_adapter();
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings(
			[
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
			]
		);

		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode( [ 'access_token' => 'issued-token' ] ),
			],
			10,
			3
		);

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/connections/colorme/exchange-code' );
		$request->set_body_params( [ 'code' => 'https://api.shop-pro.jp/oauth/authorize/AUTHCODE' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( ( new TokenStore( ColorMeAdapter::ID ) )->is_connected() );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_exchange_code_rejects_an_array_valued_code_param(): void {
		$this->register_colorme_adapter();
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings(
			[
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
			]
		);

		// このルートにはargsスキーマがないため、`code[]=x` のような配列値が渡り得る。
		// (string)キャストでの「Array to string conversion」警告や"Array"という文字列の
		// 送信を起こさず、「未指定」として400を返すことを検証する。
		$request = new WP_REST_Request( 'POST', '/cbjp/v1/connections/colorme/exchange-code' );
		$request->set_body_params( [ 'code' => [ 'x' ] ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_oauth_callback_redirects_with_an_error_when_state_is_invalid(): void {
		$this->register_colorme_adapter();

		$request = new WP_REST_Request( 'GET', '/cbjp/v1/connect/colorme/callback' );
		$request->set_query_params(
			[
				'code'  => 'some-code',
				'state' => 'never-issued',
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 302, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertStringContainsString( '#/connections', $headers['Location'] );
		$this->assertStringContainsString( 'cbjp_connect_error', $headers['Location'] );
	}

	public function test_oauth_callback_treats_array_valued_query_params_as_missing(): void {
		$this->register_colorme_adapter();

		// このルートは`__return_true`+argsスキーマ未定義の公開エンドポイントのため、
		// `?code[]=x&state[]=y` のような配列値が渡り得る。(string)キャストでの
		// 「Array to string conversion」警告を起こさず、単に未指定として扱われることを検証する。
		$request = new WP_REST_Request( 'GET', '/cbjp/v1/connect/colorme/callback' );
		$request->set_query_params(
			[
				'code'  => [ 'some-code' ],
				'state' => [ 'some-state' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 302, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertStringContainsString( 'cbjp_connect_error', $headers['Location'] );
	}

	public function test_oauth_callback_consumes_the_state_even_when_the_code_is_missing(): void {
		$this->register_colorme_adapter();
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings(
			[
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
			]
		);

		$authorize_request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections/colorme/authorize-url' );
		$authorize_response = $this->server->dispatch( $authorize_request );
		wp_parse_str( (string) wp_parse_url( $authorize_response->get_data()['url'], PHP_URL_QUERY ), $params );

		// ユーザーが認可を拒否した等でcode無しのコールバックが届いたケース。
		// エラーにはなるが、このときstateがワンタイム消費されずTTLいっぱい
		// 再利用可能なまま残らないことを検証する。
		$denied_request = new WP_REST_Request( 'GET', '/cbjp/v1/connect/colorme/callback' );
		$denied_request->set_query_params( [ 'state' => $params['state'] ] );
		$denied_response = $this->server->dispatch( $denied_request );

		$this->assertSame( 302, $denied_response->get_status() );
		$this->assertStringContainsString( 'cbjp_connect_error', $denied_response->get_headers()['Location'] );

		// 同じstateをcode付きで再送しても、既に消費済みのため接続は成立しない。
		$replay_request = new WP_REST_Request( 'GET', '/cbjp/v1/connect/colorme/callback' );
		$replay_request->set_query_params(
			[
				'code'  => 'some-code',
				'state' => $params['state'],
			]
		);
		$replay_response = $this->server->dispatch( $replay_request );

		$this->assertSame( 302, $replay_response->get_status() );
		$this->assertStringContainsString( 'cbjp_connect_error', $replay_response->get_headers()['Location'] );
		$this->assertFalse( ( new TokenStore( ColorMeAdapter::ID ) )->is_connected() );
	}

	public function test_oauth_callback_completes_the_connection_with_a_valid_state(): void {
		$this->register_colorme_adapter();
		( new TokenStore( ColorMeAdapter::ID ) )->save_settings(
			[
				'client_id'     => 'my-client-id',
				'client_secret' => 'my-client-secret',
			]
		);

		$authorize_request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections/colorme/authorize-url' );
		$authorize_response = $this->server->dispatch( $authorize_request );
		wp_parse_str( (string) wp_parse_url( $authorize_response->get_data()['url'], PHP_URL_QUERY ), $params );

		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode( [ 'access_token' => 'issued-token' ] ),
			],
			10,
			3
		);

		$callback_request = new WP_REST_Request( 'GET', '/cbjp/v1/connect/colorme/callback' );
		$callback_request->set_query_params(
			[
				'code'  => 'some-code',
				'state' => $params['state'],
			]
		);
		$response = $this->server->dispatch( $callback_request );

		$this->assertSame( 302, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertStringContainsString( 'cbjp_connected=colorme', $headers['Location'] );
		$this->assertTrue( ( new TokenStore( ColorMeAdapter::ID ) )->is_connected() );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_get_run_report_returns_404_for_unknown_run(): void {
		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/runs/does-not-exist/report' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_run_report_requires_permission(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/runs/does-not-exist/report' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_get_run_report_rejects_unknown_entity(): void {
		$run_id = $this->start_mock_dry_run();

		$request = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/report" );
		$request->set_query_params( [ 'entity' => 'not-a-real-entity' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_get_run_report_returns_csv_headers_for_a_known_run(): void {
		$run_id = $this->start_mock_dry_run();

		$request  = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/report" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$headers = $response->get_headers();
		$this->assertSame( 'text/csv; charset=utf-8', $headers['Content-Type'] );
		$this->assertStringContainsString( "cart-bridge-jp-dry-run-{$run_id}.csv", $headers['Content-Disposition'] );
	}

	/**
	 * PRレビュー指摘: `rest_pre_serve_request`は`WP_REST_Server::serve_request()`経由の
	 * リクエストでのみ発火し、`$server->dispatch()`直接呼び出し（このテストクラスが常に
	 * 使う経路）では発火しない。そのため`get_run_report()`が登録したコールバックは
	 * `remove_filter()`されないまま残留する。この残留コールバックが、後から実際に
	 * `rest_pre_serve_request`が発火した際に無関係なレスポンスまでCSVにすり替えないことを
	 * 確認する（オブジェクト同一性で自分宛のレスポンスかどうかを判定するガード）。
	 * 応答オブジェクトはコア側のフィルター契約通り`WP_HTTP_Response`（`WP_REST_Response`の
	 * 親クラス）を使う: `rest_post_dispatch`は他ルート/プラグインが素の`WP_HTTP_Response`を
	 * 返しうる汎用フィルターのため、コールバックの型宣言を`WP_REST_Response`のままにすると
	 * この残留コールバックがここで`TypeError`を投げていた（別のPRレビュー指摘で修正済み）。
	 */
	public function test_stale_report_filter_does_not_hijack_an_unrelated_response(): void {
		$run_id = $this->start_mock_dry_run();

		$request = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/report" );
		$this->server->dispatch( $request );

		$unrelated_response = new WP_HTTP_Response( [ 'ok' => true ], 200 );
		$unrelated_request  = new WP_REST_Request( 'GET', '/cbjp/v1/unrelated' );

		ob_start();
		$served = apply_filters( 'rest_pre_serve_request', false, $unrelated_response, $unrelated_request, $this->server );
		$output = ob_get_clean();

		$this->assertFalse( $served );
		$this->assertSame( '', $output );

		remove_all_filters( 'rest_pre_serve_request' );
	}

	private function register_mock_adapter(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
	}

	public function test_preview_sample_cleanup_returns_404_for_unknown_platform(): void {
		$request = new WP_REST_Request( 'GET', '/cbjp/v1/tools/sample-cleanup' );
		$request->set_query_params( [ 'platform' => 'not-a-real-platform' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'cbjp_unknown_platform', $response->as_error()->get_error_code() );
	}

	public function test_preview_sample_cleanup_rejects_an_array_valued_platform(): void {
		// ツール系ルートは `args` スキーマ（type=string）で REST 層に配列を弾かせる（CLAUDE.md）。
		$this->register_mock_adapter();

		$request = new WP_REST_Request( 'GET', '/cbjp/v1/tools/sample-cleanup' );
		$request->set_query_params( [ 'platform' => [ 'mock' ] ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_preview_sample_cleanup_reports_linked_counts(): void {
		$this->register_mock_adapter();
		( new MappingRepository() )->upsert( 'mock', 'category', 'c1', 123456, null );

		$request = new WP_REST_Request( 'GET', '/cbjp/v1/tools/sample-cleanup' );
		$request->set_query_params( [ 'platform' => 'mock' ] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'mock', $data['platform'] );
		$this->assertFalse( $data['run_in_progress'] );
		// 実体が無い mapping は「削除」ではなく「unlink」として数える。
		$this->assertSame( 0, $data['delete']['category'] );
		$this->assertSame( 1, $data['unlink']['category'] );
		$this->assertSame( 0, $data['delete']['attachment'] );
		$this->assertFalse( $data['requires_delete_users'] );
		$this->assertTrue( $data['can_delete_users'] );
		$this->assertFalse( $data['sample_selected'] );
	}

	public function test_run_sample_cleanup_is_forbidden_for_users_who_cannot_delete_users(): void {
		$this->register_mock_adapter();
		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		update_user_meta( $customer_id, '_cbjp_platform', 'mock' );
		update_user_meta( $customer_id, '_cbjp_remote_id', 'cu1' );
		update_user_meta( $customer_id, CustomerWriter::CREATED_BY_IMPORT_META, 'mock' );
		( new MappingRepository() )->upsert( 'mock', 'customer', 'cu1', $customer_id, null );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/sample-cleanup' );
		$request->set_body_params( [ 'platform' => 'mock' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'cbjp_cleanup_forbidden', $response->as_error()->get_error_code() );
		$this->assertSame( 1, ( new MappingRepository() )->count( 'mock', 'customer' ) );
	}

	public function test_run_sample_cleanup_is_rejected_while_a_run_is_active(): void {
		// テスト環境では Action Scheduler が実行されないため、開始した run のジョブは running のまま残る。
		$this->start_mock_dry_run();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/sample-cleanup' );
		$request->set_body_params( [ 'platform' => 'mock' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'cbjp_run_in_progress', $response->as_error()->get_error_code() );
	}

	public function test_run_sample_cleanup_removes_links_and_reports_counts(): void {
		$this->register_mock_adapter();
		$mappings = new MappingRepository();
		$mappings->upsert( 'mock', 'product', 'p1', 999999, null );

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/sample-cleanup' );
		$request->set_body_params( [ 'platform' => 'mock' ] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['has_more'] );
		$this->assertSame( 1, $data['unlinked']['product'] );
		$this->assertSame( 0, $mappings->count( 'mock', 'product' ) );
	}

	public function test_rebuild_mappings_restores_links_from_ownership_meta(): void {
		$this->register_mock_adapter();

		$product = new WC_Product_Simple();
		$product->set_name( 'Owned' );
		$product->update_meta_data( '_cbjp_platform', 'mock' );
		$product->update_meta_data( '_cbjp_remote_id', 'p1' );
		$product_id = $product->save();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/rebuild-mappings' );
		$request->set_body_params( [ 'platform' => 'mock' ] );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $data['cursor'] );
		$this->assertSame( 1, $data['counts']['product'] );
		$this->assertSame( $product_id, ( new MappingRepository() )->find_local_id( 'mock', 'product', 'p1' ) );
	}

	public function test_rebuild_mappings_accepts_a_null_cursor(): void {
		// 管理画面は初回リクエストで `cursor: null` を送る。`type: string` のみの args スキーマだと
		// `rest_parse_request_arg` が null を型エラー（400）にしてしまう。
		$this->register_mock_adapter();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/rebuild-mappings' );
		$request->set_body_params(
			[
				'platform' => 'mock',
				'cursor'   => null,
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['cursor'] );
	}

	public function test_rebuild_mappings_rejects_an_invalid_cursor(): void {
		$this->register_mock_adapter();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/tools/rebuild-mappings' );
		$request->set_body_params(
			[
				'platform' => 'mock',
				'cursor'   => '{"entity":"nope","offset":0}',
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'cbjp_invalid_cursor', $response->as_error()->get_error_code() );
	}

	public function test_get_run_verification_returns_404_for_unknown_run(): void {
		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/runs/no-such-run/verification' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_get_run_verification_rejects_dry_runs(): void {
		$run_id = $this->start_mock_dry_run();

		$request  = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/verification" );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'cbjp_verification_unavailable', $response->as_error()->get_error_code() );
	}

	public function test_run_routes_read_the_run_id_from_the_path_not_the_query_string(): void {
		// `get_param()` は GET でもクエリ文字列の同名値をパスより優先する（CLAUDE.md）。
		// `?run_id=...` で別の run を名指しされても、パスの run を返すこと。
		$run_id = $this->start_mock_dry_run();

		$request = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}" );
		$request->set_query_params( [ 'run_id' => 'no-such-run' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $run_id, $response->get_data()['run_id'] );

		$request = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/verification" );
		$request->set_query_params( [ 'run_id' => 'no-such-run' ] );
		$response = $this->server->dispatch( $request );

		// verification は dry-run に対して 400（run 自体は見つかっている）。404 ならクエリ側を読んでいる。
		$this->assertSame( 400, $response->get_status() );

		// POST はボディがパスより優先されるため、cancel はボディで別 run を名指しされてもパスの run を止める。
		$request = new WP_REST_Request( 'POST', "/cbjp/v1/runs/{$run_id}/cancel" );
		$request->set_body_params( [ 'run_id' => 'no-such-run' ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( JobRepository::STATUS_CANCELLED, ( new JobRepository() )->find_by_run( $run_id )[0]['status'] );
	}

	public function test_retry_job_reads_the_job_id_from_the_path_not_the_body(): void {
		$this->register_mock_adapter();
		$jobs   = new JobRepository();
		$job_a  = $jobs->create( 'run-a', 'import', 'mock', 'category' );
		$job_b  = $jobs->create( 'run-b', 'import', 'mock', 'category' );
		$failed = [
			'code'    => 'exception',
			'message' => 'boom',
		];
		$jobs->mark_failed( $job_a, $failed );
		$jobs->mark_failed( $job_b, $failed );

		$request = new WP_REST_Request( 'POST', "/cbjp/v1/jobs/{$job_a}/retry" );
		$request->set_body_params( [ 'id' => $job_b ] );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $job_a, $response->get_data()['id'] );
		$this->assertSame( JobRepository::STATUS_PENDING, $jobs->find( $job_a )['status'] );
		$this->assertSame( JobRepository::STATUS_FAILED, $jobs->find( $job_b )['status'] );
	}

	public function test_get_run_verification_returns_the_report_for_an_import_run(): void {
		$this->register_mock_adapter();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'import',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$run_id = (string) $this->server->dispatch( $request )->get_data()['run_id'];
		JobManager::create()->run_to_completion( $run_id );

		$request  = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/verification" );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $run_id, $data['run_id'] );
		$this->assertSame( 'import', $data['type'] );
		$this->assertSame( 'category', $data['entities'][0]['entity'] );
		$this->assertSame( 0, $data['entities'][0]['missing'] );
	}

	private function start_mock_dry_run(): string {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'dry_run',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		return (string) $response->get_data()['run_id'];
	}
}
