<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

use WP_Error;

/**
 * `wp_remote_request()` のラッパー。リトライ（指数バックオフ+ジッター）、
 * Retry-After優先、レート制限判定の拡張ポイントを提供する。
 */
final class HttpClient {

	private const DEFAULT_TIMEOUT_SECONDS = 30;
	private const MAX_RETRIES             = 3;

	/**
	 * BASEのHTTP400レート制限のように、標準の429判定では検知できないケースを
	 * アダプタ側Clientが差し替えるための拡張ポイント。
	 *
	 * @var (callable(int, array<string,string>, string): bool)|null
	 */
	private $rate_limit_detector = null;

	public function __construct( private readonly RateLimiter $rate_limiter ) {}

	/**
	 * @param callable(int $status_code, array<string,string> $headers, string $body): bool $detector
	 */
	public function set_rate_limit_detector( callable $detector ): void {
		$this->rate_limit_detector = $detector;
	}

	/**
	 * @param array<string,mixed> $args wp_remote_request() に渡す追加引数（headers/body等）。
	 * @return array{status:int,headers:array<string,string>,body:string}
	 *
	 * @throws ApiException リトライ上限到達、または4xx（429以外）の場合。
	 */
	public function request( string $method, string $url, array $args = [] ): array {
		$attempt = 0;

		while ( true ) {
			$this->rate_limiter->wait();

			$response = wp_remote_request(
				$url,
				array_merge(
					[
						'method'     => $method,
						'timeout'    => self::DEFAULT_TIMEOUT_SECONDS,
						'user-agent' => 'CartBridgeJP/' . CBJP_VERSION,
					],
					$args
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt < self::MAX_RETRIES && $this->is_retryable_wp_error( $response ) ) {
					$this->sleep_with_backoff( $attempt, null );
					++$attempt;
					continue;
				}

				throw new ApiException(
					$response->get_error_message(),
					0,
					[ 'wp_error_code' => $response->get_error_code() ]
				);
			}

			$status  = (int) wp_remote_retrieve_response_code( $response );
			$headers = $this->normalize_headers( wp_remote_retrieve_headers( $response ) );
			$body    = (string) wp_remote_retrieve_body( $response );

			$is_rate_limited = $this->is_rate_limited( $status, $headers, $body );

			if ( $is_rate_limited || $status >= 500 ) {
				if ( $attempt < self::MAX_RETRIES ) {
					$this->sleep_with_backoff( $attempt, $this->retry_after_seconds( $headers ) );
					++$attempt;
					continue;
				}

				throw new ApiException(
					sprintf( 'Request failed with status %d after %d retries.', $status, $attempt ),
					$status,
					[
						'rate_limited' => $is_rate_limited,
						'retry_after'  => $this->retry_after_seconds( $headers ),
					]
				);
			}

			if ( $status >= 400 ) {
				throw new ApiException(
					sprintf( 'Request failed with status %d.', $status ),
					$status,
					[ 'body' => $body ]
				);
			}

			return [
				'status'  => $status,
				'headers' => $headers,
				'body'    => $body,
			];
		}
	}

	/**
	 * @param array<string,string> $headers
	 */
	private function is_rate_limited( int $status, array $headers, string $body ): bool {
		if ( null !== $this->rate_limit_detector ) {
			return ( $this->rate_limit_detector )( $status, $headers, $body );
		}

		return 429 === $status;
	}

	/**
	 * @param array<string,string> $headers
	 */
	private function retry_after_seconds( array $headers ): ?int {
		if ( ! isset( $headers['retry-after'] ) ) {
			return null;
		}

		return max( 0, (int) $headers['retry-after'] );
	}

	private function is_retryable_wp_error( WP_Error $error ): bool {
		return in_array( $error->get_error_code(), [ 'http_request_failed', 'http_request_timeout' ], true );
	}

	private function sleep_with_backoff( int $attempt, ?int $retry_after_seconds ): void {
		if ( null !== $retry_after_seconds ) {
			usleep( $retry_after_seconds * 1_000_000 );
			return;
		}

		$base_delay_ms = (int) ( 1000 * ( 2 ** $attempt ) );
		$jitter_ms     = wp_rand( 0, (int) ( $base_delay_ms * 0.2 ) );

		usleep( ( $base_delay_ms + $jitter_ms ) * 1000 );
	}

	/**
	 * @param mixed $headers wp_remote_retrieve_headers() の戻り値。
	 * @return array<string,string>
	 */
	private function normalize_headers( $headers ): array {
		if ( is_array( $headers ) ) {
			$raw = $headers;
		} elseif ( $headers instanceof \Traversable ) {
			$raw = iterator_to_array( $headers );
		} else {
			$raw = [];
		}

		$normalized = [];

		foreach ( $raw as $key => $value ) {
			$normalized[ strtolower( (string) $key ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return $normalized;
	}
}
