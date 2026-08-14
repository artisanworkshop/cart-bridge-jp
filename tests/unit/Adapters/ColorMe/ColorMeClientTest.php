<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeClient;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\HttpClient;
use CartBridgeJP\Support\RateLimiter;
use WP_UnitTestCase;

final class ColorMeClientTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function make_client(): ColorMeClient {
		return new ColorMeClient(
			new HttpClient( new RateLimiter( 'test-colorme-' . wp_generate_uuid4(), 1000 ) ),
			'test-access-token'
		);
	}

	public function test_get_sends_bearer_auth_and_query_string(): void {
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
					'body'     => '{"products":[]}',
				];
			},
			10,
			3
		);

		$result = $this->make_client()->get( 'products.json', [ 'limit' => 50 ] );

		$this->assertSame( [ 'products' => [] ], $result );
		$this->assertSame( 'https://api.shop-pro.jp/v1/products.json?limit=50', $captured['url'] );
		$this->assertSame( 'GET', $captured['args']['method'] );
		$this->assertSame( 'Bearer test-access-token', $captured['args']['headers']['Authorization'] );
		$this->assertNull( $captured['args']['body'] );
	}

	public function test_get_query_separator_ignores_the_arg_separator_ini_setting(): void {
		$captured = null;

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args, $url ) use ( &$captured ) {
				$captured = $url;

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => '{"products":[]}',
				];
			},
			10,
			3
		);

		// arg_separator.output=&amp; の環境では、セパレータ未指定のhttp_build_query()が
		// `limit=50&amp;offset=50` を生成し、ColorMe側に `amp;offset` という壊れた
		// パラメータ名が届いてしまう。iniに依存せず常に `&` を使うことを検証する。
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- ホスティング側ini設定の再現（finallyで復元）。
		$previous = ini_set( 'arg_separator.output', '&amp;' );

		try {
			$this->make_client()->get(
				'products.json',
				[
					'limit'  => 50,
					'offset' => 50,
				]
			);
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- テスト前の値へ復元。
			ini_set( 'arg_separator.output', (string) $previous );
		}

		$this->assertSame( 'https://api.shop-pro.jp/v1/products.json?limit=50&offset=50', $captured );
	}

	public function test_post_sends_json_encoded_body_with_content_type(): void {
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
					'body'     => '{"id":123}',
				];
			},
			10,
			3
		);

		$result = $this->make_client()->post( 'products.json', [ 'name' => 'Test Product' ] );

		$this->assertSame( [ 'id' => 123 ], $result );
		$this->assertSame( 'https://api.shop-pro.jp/v1/products.json', $captured['url'] );
		$this->assertSame( 'POST', $captured['args']['method'] );
		$this->assertSame( 'application/json', $captured['args']['headers']['Content-Type'] );
		$this->assertSame( '{"name":"Test Product"}', $captured['args']['body'] );
	}

	public function test_put_sends_json_encoded_body(): void {
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
					'body'     => '{"id":123,"name":"Updated"}',
				];
			},
			10,
			3
		);

		$result = $this->make_client()->put( 'products/123.json', [ 'name' => 'Updated' ] );

		$this->assertSame(
			[
				'id'   => 123,
				'name' => 'Updated',
			],
			$result
		);
		$this->assertSame( 'https://api.shop-pro.jp/v1/products/123.json', $captured['url'] );
		$this->assertSame( 'PUT', $captured['args']['method'] );
		$this->assertSame( '{"name":"Updated"}', $captured['args']['body'] );
	}

	public function test_empty_response_body_decodes_to_empty_array(): void {
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => '',
			],
			10,
			3
		);

		$this->assertSame( [], $this->make_client()->get( 'shop.json' ) );
	}

	public function test_non_json_response_body_throws_api_exception(): void {
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => 'not json',
			],
			10,
			3
		);

		$this->expectException( ApiException::class );

		$this->make_client()->get( 'shop.json' );
	}

	public function test_error_response_is_translated_into_api_exception_with_colorme_message(): void {
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 404 ],
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'errors' => [
							[
								'code'    => 404100,
								'message' => '指定した商品が見つかりません。',
								'status'  => 404,
							],
						],
					]
				),
			],
			10,
			3
		);

		try {
			$this->make_client()->get( 'products/999999.json' );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( '指定した商品が見つかりません。', $exception->getMessage() );
			$this->assertSame( 404, $exception->status_code() );
			$this->assertSame( 404100, $exception->context()['colorme_error_code'] );
		}
	}

	public function test_error_response_without_parseable_errors_falls_back_to_original_exception(): void {
		// 4xx（429以外）はHttpClient側でリトライされず即座に例外化されるため、backoff待機なしでテストできる。
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 422 ],
				'headers'  => [],
				'body'     => 'Unprocessable Entity',
			],
			10,
			3
		);

		try {
			$this->make_client()->get( 'shop.json' );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 422, $exception->status_code() );
			$this->assertArrayNotHasKey( 'colorme_error_code', $exception->context() );
		}
	}

	public function test_for_access_token_builds_a_working_client(): void {
		add_filter(
			'pre_http_request',
			static fn() => [
				'response' => [ 'code' => 200 ],
				'headers'  => [],
				'body'     => '{"shop_name":"Test Shop"}',
			],
			10,
			3
		);

		$result = ColorMeClient::for_access_token( 'test-access-token' )->get( 'shop.json' );

		$this->assertSame( [ 'shop_name' => 'Test Shop' ], $result );
	}
}
