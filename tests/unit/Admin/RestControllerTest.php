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
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
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
}
