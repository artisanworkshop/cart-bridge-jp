<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Support;

use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\HttpClient;
use CartBridgeJP\Support\RateLimiter;
use WP_UnitTestCase;

final class HttpClientTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function make_client(): HttpClient {
		return new HttpClient( new RateLimiter( 'test-http-' . wp_generate_uuid4(), 1000 ) );
	}

	public function test_retries_on_429_and_honours_retry_after_header(): void {
		$call_count = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				if ( 1 === $call_count ) {
					return [
						'response' => [ 'code' => 429 ],
						'headers'  => [ 'retry-after' => '0' ],
						'body'     => '',
					];
				}

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => '{"ok":true}',
				];
			},
			10,
			3
		);

		$result = $this->make_client()->request( 'GET', 'https://example.test/api' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '{"ok":true}', $result['body'] );
		$this->assertSame( 2, $call_count );
	}

	public function test_throws_api_exception_on_4xx_without_retrying(): void {
		$call_count = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				return [
					'response' => [ 'code' => 404 ],
					'headers'  => [],
					'body'     => 'not found',
				];
			},
			10,
			3
		);

		try {
			$this->make_client()->request( 'GET', 'https://example.test/api' );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 404, $exception->status_code() );
		}

		$this->assertSame( 1, $call_count );
	}

	public function test_custom_rate_limit_detector_triggers_retry_on_http_400(): void {
		$call_count = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				if ( 1 === $call_count ) {
					return [
						'response' => [ 'code' => 400 ],
						'headers'  => [],
						'body'     => '{"code":"hour_api_limit"}',
					];
				}

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => '{}',
				];
			},
			10,
			3
		);

		$client = new HttpClient(
			new RateLimiter( 'test-http-' . wp_generate_uuid4(), 1000 ),
			static fn( int $status, array $headers, string $body ): bool =>
				400 === $status && str_contains( $body, 'hour_api_limit' )
		);

		$result = $client->request( 'GET', 'https://example.test/api' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 2, $call_count );
	}

	public function test_http_date_retry_after_falls_back_gracefully(): void {
		$call_count = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				if ( 1 === $call_count ) {
					return [
						'response' => [ 'code' => 429 ],
						// RFC 9110 HTTP-date形式（過去日時 → 待機0秒として解釈される）。
						'headers'  => [ 'retry-after' => gmdate( 'D, d M Y H:i:s', time() - 10 ) . ' GMT' ],
						'body'     => '',
					];
				}

				return [
					'response' => [ 'code' => 200 ],
					'headers'  => [],
					'body'     => '{}',
				];
			},
			10,
			3
		);

		$result = $this->make_client()->request( 'GET', 'https://example.test/api' );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 2, $call_count );
	}

	public function test_excessive_retry_after_throws_instead_of_blocking_the_worker(): void {
		$call_count = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				return [
					'response' => [ 'code' => 429 ],
					'headers'  => [ 'retry-after' => '86400' ],
					'body'     => '',
				];
			},
			10,
			3
		);

		$started = microtime( true );

		try {
			$this->make_client()->request( 'GET', 'https://example.test/api' );
			$this->fail( 'Expected ApiException was not thrown.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 429, $exception->status_code() );
			$this->assertTrue( $exception->is_rate_limited() );
		}

		// 上限超のRetry-After指示ではリトライ待機せず即座に例外になること。
		$this->assertLessThan( 2.0, microtime( true ) - $started );
		$this->assertSame( 1, $call_count );
	}
}
