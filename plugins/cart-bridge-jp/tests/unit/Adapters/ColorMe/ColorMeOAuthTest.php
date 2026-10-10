<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeOAuth;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\HttpClient;
use CartBridgeJP\Support\RateLimiter;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\LogRepository;
use WP_UnitTestCase;

final class ColorMeOAuthTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( ColorMeOAuth::SCOPES_FILTER );
		parent::tear_down();
	}

	/**
	 * @return array{0:ColorMeOAuth,1:TokenStore,2:string}
	 */
	private function make_oauth(): array {
		$platform    = 'test-colorme-oauth-' . wp_generate_uuid4();
		$token_store = new TokenStore( $platform );
		$http_client = new HttpClient( new RateLimiter( $platform, 1000 ) );

		return [ new ColorMeOAuth( $token_store, $http_client ), $token_store, $platform ];
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
		// R3-6c2: 無料版だけ（拡張がスコープを足さない）では商品のスコープだけを要求する（受注・顧客・クーポンの権限を求めない）。
		$this->assertSame( 'read_products write_products', $this->query_of( $url )['scope'] );
		$this->assertStringContainsString( 'state=', $url );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function query_of( string $url ): array {
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
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

	public function test_exchange_code_does_not_recreate_credentials_deleted_mid_exchange(): void {
		[ $oauth, , $platform ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		// トークンPOSTの応答待ちの間に、管理者が別リクエストで資格情報を削除した
		// ケースをHTTPモック内で再現する（本番同様、別TokenStoreインスタンス経由）。
		// 交換開始時のスナップショットの書き戻しで削除済み資格情報が復活しない
		// ことを検証する。
		add_filter(
			'pre_http_request',
			static function () use ( $platform ) {
				( new TokenStore( $platform ) )->delete();

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => wp_json_encode( [ 'access_token' => 'issued-token' ] ),
				];
			},
			10,
			3
		);

		try {
			$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'changed or removed', $exception->getMessage() );
		}

		$this->assertNull( ( new TokenStore( $platform ) )->get() );
	}

	public function test_exchange_code_does_not_clobber_credentials_replaced_mid_exchange(): void {
		[ $oauth, , $platform ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		// トークンPOSTの応答待ちの間に、管理者が別の資格情報を保存し直したケース。
		// 発行済みトークンは古いclient_id/secretに紐づくため、新しい設定へ
		// マージ保存せず、交換をエラーとして扱うことを検証する。
		add_filter(
			'pre_http_request',
			static function () use ( $platform ) {
				( new TokenStore( $platform ) )->save_settings(
					[
						'client_id'     => 'replacement-client-id',
						'client_secret' => 'replacement-client-secret',
					]
				);

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => wp_json_encode( [ 'access_token' => 'issued-token' ] ),
				];
			},
			10,
			3
		);

		try {
			$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );
			$this->fail( 'Expected RuntimeException was not thrown.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertStringContainsString( 'changed or removed', $exception->getMessage() );
		}

		$payload = ( new TokenStore( $platform ) )->get();
		$this->assertSame( '', $payload['access_token'] );
		$this->assertSame( 'replacement-client-id', $payload['settings']['client_id'] );
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

	/**
	 * トークン応答（`scope` は省略可）を返す HTTP のモック。
	 *
	 * @param array<string,mixed> $response
	 */
	private function respond_with_token( array $response ): void {
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => wp_json_encode( $response ),
			],
			10,
			3
		);
	}

	/**
	 * @return array<int,mixed> 記録した理由（`Logger` の context の `reason`）。
	 */
	private function logged_reasons(): array {
		return array_map(
			static fn ( array $log ): mixed => json_decode( (string) $log['context_json'], true )['reason'] ?? null,
			( new LogRepository() )->list( null, 'warning' )
		);
	}

	/**
	 * R3-6c2: 拡張が足したスコープを、既知のものだけ `KNOWN_SCOPES` の順に、重複を除いて要求する（Pro が足す 3 つで、R3-6c2 より前と同じ文字列になる）。
	 */
	public function test_extensions_add_known_scopes_in_a_fixed_order(): void {
		Activator::activate();
		add_filter(
			ColorMeOAuth::SCOPES_FILTER,
			static fn ( array $scopes, string $platform ): array => 'colorme' === $platform
				? array_merge( [ 'read_shop_coupons', 'write_sales' ], $scopes, [ 'read_sales', 'write_sales' ] )
				: $scopes,
			10,
			2
		);

		$this->assertSame( ColorMeOAuth::LEGACY_SCOPES, ColorMeOAuth::scopes() );
		// 既知のスコープだけなら記録しない（`GET /connections` のたびに書かない。R3-6c2 review-loop R2-1）。
		$this->assertSame( [], $this->logged_reasons() );

		[ $oauth ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		$this->assertSame(
			'read_products write_products read_sales write_sales read_shop_coupons',
			$this->query_of( $oauth->authorize_url( 'https://example.test/callback', 42 ) )['scope']
		);
	}

	/**
	 * 拡張は無料版の商品のスコープを外せない（戻り値に無くても要求する）。
	 */
	public function test_extensions_cannot_remove_the_base_scopes(): void {
		add_filter( ColorMeOAuth::SCOPES_FILTER, static fn (): array => [ 'read_sales' ] );

		$this->assertSame( [ 'read_products', 'write_products', 'read_sales' ], ColorMeOAuth::scopes() );
	}

	/**
	 * 既知でない値（ColorMe に無いスコープ・文字列でない値）はその値だけ捨てて記録する（1 回の呼び出しで 1 行。R3-6c2 review-loop R1-4）。
	 * 正しく足されたスコープは残す。
	 */
	public function test_unknown_scopes_from_extensions_are_dropped_and_logged(): void {
		Activator::activate();
		add_filter( ColorMeOAuth::SCOPES_FILTER, static fn ( array $scopes ): array => array_merge( $scopes, [ 'read_sales', 'admin', 42, [ 'write_sales' ], 'READ_SALES' ] ) );

		$this->assertSame( [ 'read_products', 'write_products', 'read_sales' ], ColorMeOAuth::scopes() );
		$this->assertSame( [ 'unknown_scope' ], $this->logged_reasons() );
		$this->assertSame( 4, json_decode( (string) ( new LogRepository() )->list( null, 'warning' )[0]['context_json'], true )['count'] );
	}

	/**
	 * @return array<string,array{0:callable,1:string}>
	 */
	public static function broken_scope_filters(): array {
		return [
			'not an array' => [ static fn (): string => 'read_sales', 'not_an_array' ],
			'throws'       => [ static fn () => throw new \LogicException( 'boom' ), 'LogicException' ],
		];
	}

	/**
	 * 配列でない戻り値・例外は拡張の分を捨てて商品のスコープだけを要求し、記録する（`/connections`・認可を落とさない）。
	 *
	 * @dataProvider broken_scope_filters
	 *
	 * @param callable $filter フィルターのコールバック。
	 * @param string   $reason 記録する理由。
	 */
	public function test_a_broken_scope_filter_falls_back_to_the_base_scopes( callable $filter, string $reason ): void {
		Activator::activate();
		add_filter( ColorMeOAuth::SCOPES_FILTER, $filter );

		$this->assertSame( ColorMeOAuth::BASE_SCOPES, ColorMeOAuth::scopes() );
		$this->assertSame( [ $reason ], $this->logged_reasons() );
	}

	/**
	 * 交換は応答の `scope`（付与されたスコープ）をトークンと一緒に記録する。
	 */
	public function test_exchange_code_records_the_granted_scopes_from_the_response(): void {
		[ $oauth, $token_store ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );
		$this->respond_with_token(
			[
				'access_token' => 'issued-access-token',
				'scope'        => " read_products  write_products\tread_sales read_products ",
			]
		);

		$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );

		$this->assertSame( [ 'read_products', 'write_products', 'read_sales' ], $token_store->granted_scopes() );
	}

	/**
	 * 応答に `scope` が無ければ要求したとおりに付与されている（RFC 6749 §5.1）。要求は交換の時点の `scopes()`。
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function responses_without_scope(): array {
		return [
			'missing' => [ [ 'access_token' => 'issued-access-token' ] ],
			'null'    => [
				[
					'access_token' => 'issued-access-token',
					'scope'        => null,
				],
			],
		];
	}

	/**
	 * @dataProvider responses_without_scope
	 *
	 * @param array<string,mixed> $response トークン応答。
	 */
	public function test_exchange_code_records_the_requested_scopes_when_the_response_has_none( array $response ): void {
		add_filter( ColorMeOAuth::SCOPES_FILTER, static fn ( array $scopes ): array => array_merge( $scopes, [ 'read_sales' ] ) );
		[ $oauth, $token_store ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );
		$this->respond_with_token( $response );

		$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );

		$this->assertSame( [ 'read_products', 'write_products', 'read_sales' ], $token_store->granted_scopes() );
		$this->assertSame( [], $oauth->missing_scopes() );
	}

	/**
	 * 読めない `scope`（文字列でない）は何も付与されていない扱い（フェイルクローズ）。要求するスコープがすべて足りないとして再接続を促す。
	 */
	public function test_exchange_code_records_no_scopes_when_the_response_scope_is_unreadable(): void {
		[ $oauth, $token_store ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );
		$this->respond_with_token(
			[
				'access_token' => 'issued-access-token',
				'scope'        => [ 'read_products', 'write_products' ],
			]
		);

		$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );

		$this->assertTrue( $token_store->is_connected() );
		$this->assertSame( [], $token_store->granted_scopes() );
		$this->assertSame( ColorMeOAuth::BASE_SCOPES, $oauth->missing_scopes() );
	}

	/**
	 * 未接続は「足りない」と言わない（未接続・要再接続の案内は別にある）。
	 */
	public function test_missing_scopes_is_empty_when_not_connected(): void {
		add_filter( ColorMeOAuth::SCOPES_FILTER, static fn ( array $scopes ): array => array_merge( $scopes, [ 'read_sales' ] ) );
		[ $oauth, $token_store ] = $this->make_oauth();

		$this->assertNull( ColorMeOAuth::granted_scopes_in( $token_store ) );
		$this->assertSame( [], $oauth->missing_scopes() );

		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );

		$this->assertNull( ColorMeOAuth::granted_scopes_in( $token_store ), 'client_id/secret だけでは未接続' );
		$this->assertSame( [], $oauth->missing_scopes() );
	}

	/**
	 * 付与されたスコープを記録していないトークン（R3-6c2 より前の版が保存した）は、その版が要求した 5 つを持つ。Pro のスコープを足しても足りている。
	 */
	public function test_a_token_without_a_record_has_the_legacy_scopes(): void {
		add_filter( ColorMeOAuth::SCOPES_FILTER, static fn ( array $scopes ): array => array_merge( $scopes, [ 'read_sales', 'write_sales', 'read_shop_coupons' ] ) );
		[ $oauth, $token_store ] = $this->make_oauth();
		$token_store->save( [ 'access_token' => 'token-saved-before-r3-6c2' ] );

		$this->assertSame( ColorMeOAuth::LEGACY_SCOPES, ColorMeOAuth::granted_scopes_in( $token_store ) );
		$this->assertSame( [], $oauth->missing_scopes() );
	}

	/**
	 * 無料版だけで接続した（商品のスコープだけの）トークンに、拡張が足したスコープは足りない。
	 */
	public function test_missing_scopes_lists_the_scopes_an_extension_added_after_connecting(): void {
		[ $oauth, $token_store ] = $this->make_oauth();
		$oauth->save_credentials( 'my-client-id', 'my-client-secret' );
		$this->respond_with_token(
			[
				'access_token' => 'issued-access-token',
				'scope'        => 'read_products write_products',
			]
		);
		$oauth->exchange_code( 'auth-code', ColorMeOAuth::OOB_REDIRECT_URI );

		$this->assertSame( [], $oauth->missing_scopes() );

		add_filter( ColorMeOAuth::SCOPES_FILTER, static fn ( array $scopes ): array => array_merge( $scopes, [ 'read_sales', 'write_sales', 'read_shop_coupons' ] ) );

		$this->assertSame( [ 'read_sales', 'write_sales', 'read_shop_coupons' ], $oauth->missing_scopes() );
	}
}
