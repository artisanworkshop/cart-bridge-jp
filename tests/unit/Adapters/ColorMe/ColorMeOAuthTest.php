<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeOAuth;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\HttpClient;
use CartBridgeJP\Support\RateLimiter;
use CartBridgeJP\Support\TokenStore;
use WP_UnitTestCase;

final class ColorMeOAuthTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * @return array{0:ColorMeOAuth,1:TokenStore}
	 */
	private function make_oauth(): array {
		$platform    = 'test-colorme-oauth-' . wp_generate_uuid4();
		$token_store = new TokenStore( $platform );
		$http_client = new HttpClient( new RateLimiter( $platform, 1000 ) );

		return [ new ColorMeOAuth( $token_store, $http_client ), $token_store ];
	}

	public function test_authorize_url_throws_when_client_id_missing(): void {
		[ $oauth ] = $this->make_oauth();

		$this->expectException( \RuntimeException::class );

		$oauth->authorize_url( 'https://example.test/callback' );
	}

	public function test_authorize_url_throws_when_client_secret_missing(): void {
		[ $oauth, $token_store ] = $this->make_oauth();
		$token_store->save_settings( [ 'client_id' => 'my-client-id' ] );

		$this->expectException( \RuntimeException::class );

		$oauth->authorize_url( 'https://example.test/callback' );
	}

	public function test_authorize_url_includes_client_id_scope_and_state(): void {
		[ $oauth ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		$url = $oauth->authorize_url( 'https://example.test/callback', 42 );

		$this->assertStringContainsString( 'https://api.shop-pro.jp/oauth/authorize?', $url );
		$this->assertStringContainsString( 'client_id=my-client-id', $url );
		$this->assertStringContainsString( 'response_type=code', $url );
		$this->assertStringContainsString( 'redirect_uri=https%3A%2F%2Fexample.test%2Fcallback', $url );
		$this->assertStringContainsString( 'scope=read_products+write_products+read_sales+write_sales+read_shop_coupons', $url );
		$this->assertStringContainsString( 'state=', $url );
	}

	public function test_authorize_url_separator_ignores_the_arg_separator_ini_setting(): void {
		[ $oauth ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		// arg_separator.output=&amp; の環境では、セパレータ未指定のhttp_build_query()が
		// `response_type=code&amp;client_id=...` を生成する。このURLはHTMLとしてでは
		// なくJSのlocation.href経由でそのまま使われるため、`amp;client_id` という
		// 壊れたパラメータ名で認可が必ず失敗する。iniに依存しないことを検証する。
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- ホスティング側ini設定の再現（finallyで復元）。
		$previous = ini_set( 'arg_separator.output', '&amp;' );

		try {
			$url = $oauth->authorize_url( 'https://example.test/callback', 42 );
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- テスト前の値へ復元。
			ini_set( 'arg_separator.output', (string) $previous );
		}

		$this->assertStringNotContainsString( '&amp;', $url );
		$this->assertStringContainsString( '&client_id=my-client-id', $url );
	}

	public function test_authorize_url_omits_state_when_no_user_id_given(): void {
		[ $oauth ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		$url = $oauth->authorize_url( ColorMeOAuth::OOB_REDIRECT_URI );

		$this->assertStringNotContainsString( 'state=', $url );
	}

	public function test_verify_state_succeeds_once_then_fails_on_reuse(): void {
		[ $oauth ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		$url = $oauth->authorize_url( 'https://example.test/callback', 7 );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );
		$state = $params['state'];

		$this->assertTrue( $oauth->verify_state( $state ) );
		// 一度きりの使い切りトークンのため、2回目は失敗する。
		$this->assertFalse( $oauth->verify_state( $state ) );
	}

	/**
	 * 外部ASPからのコールバックはREST cookie認証のnonceを持たないため、
	 * WordPressは`get_current_user_id()`を0にリセットする。stateの検証を
	 * 「発行時の管理ユーザーID」に依存させると本番のコールバックが必ず失敗するため、
	 * 検証はREST上のcurrent userとは独立に行う（stateトークンの存在確認のみ）。
	 */
	public function test_verify_state_succeeds_regardless_of_the_current_rest_user(): void {
		[ $oauth ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		$url = $oauth->authorize_url( 'https://example.test/callback', 7 );
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertTrue( $oauth->verify_state( $params['state'] ) );
	}

	public function test_verify_state_fails_for_unknown_state(): void {
		[ $oauth ] = $this->make_oauth();

		$this->assertFalse( $oauth->verify_state( 'never-issued' ) );
	}

	public function test_extract_code_from_input_accepts_a_raw_code(): void {
		[ $oauth ] = $this->make_oauth();

		$this->assertSame( 'ABC123', $oauth->extract_code_from_input( '  ABC123  ' ) );
	}

	public function test_extract_code_from_input_accepts_the_colorme_oob_redirect_url(): void {
		[ $oauth ] = $this->make_oauth();

		$this->assertSame(
			'ABC123',
			$oauth->extract_code_from_input( 'https://api.shop-pro.jp/oauth/authorize/ABC123' )
		);
	}

	public function test_exchange_code_throws_when_credentials_are_not_configured(): void {
		[ $oauth ] = $this->make_oauth();

		$this->expectException( \RuntimeException::class );

		$oauth->exchange_code( 'some-code', ColorMeOAuth::OOB_REDIRECT_URI );
	}

	public function test_exchange_code_saves_access_token_and_preserves_settings(): void {
		[ $oauth, $token_store ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		$captured = null;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
				$captured = [
					'url'  => $url,
					'args' => $parsed_args,
				];

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => wp_json_encode(
						[
							'access_token' => 'issued-access-token',
							'token_type'   => 'bearer',
							'scope'        => 'read_products',
						]
					),
				];
			},
			10,
			3
		);

		$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );

		$this->assertSame( 'https://api.shop-pro.jp/oauth/token', $captured['url'] );
		$this->assertSame( 'POST', $captured['args']['method'] );
		$this->assertStringContainsString( 'grant_type=authorization_code', $captured['args']['body'] );
		$this->assertStringContainsString( 'client_secret=my-client-secret', $captured['args']['body'] );
		$this->assertStringContainsString( 'code=auth-code', $captured['args']['body'] );

		$payload = $token_store->get();
		$this->assertSame( 'issued-access-token', $payload['access_token'] );
		// client_id/secretはaccess_token取得後も保持され続けること（再接続用）。
		$this->assertSame( 'my-client-id', $payload['settings']['client_id'] );
		$this->assertSame( 'my-client-secret', $payload['settings']['client_secret'] );
	}

	public function test_exchange_code_clears_stale_extras_from_a_previous_shop(): void {
		[ $oauth, $token_store ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );
		$token_store->save(
			[
				'access_token' => 'old-token',
				'extras'       => [ 'contract_plan' => 'premium' ],
				'settings'     => [
					'client_id'     => 'my-client-id',
					'client_secret' => 'my-client-secret',
				],
			]
		);

		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode( [ 'access_token' => 'new-shop-token' ] ),
			],
			10,
			3
		);

		$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );

		$payload = $token_store->get();
		$this->assertSame( 'new-shop-token', $payload['access_token'] );
		$this->assertArrayNotHasKey( 'extras', $payload );
	}

	public function test_exchange_code_translates_colorme_error_response(): void {
		[ $oauth ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'wrong-secret' );

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
								'message' => 'クライアントシークレットが正しくありません。',
								'status'  => 401,
							],
						],
					]
				),
			],
			10,
			3
		);

		try {
			$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 'クライアントシークレットが正しくありません。', $exception->getMessage() );
			$this->assertSame( 401, $exception->status_code() );
		}
	}
}
