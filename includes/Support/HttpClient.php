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
	 * Retry-After指示を尊重する上限秒数。これを超える指示はリトライせず即例外にする
	 * （Action Schedulerワーカーを長時間ブロックしない）。
	 */
	private const MAX_RETRY_AFTER_SECONDS = 60;

	/**
	 * $rate_limit_detector: BASEのHTTP400レート制限のように、標準の429判定では検知できない
	 * ケースをアダプタ側Clientが差し替えるための拡張ポイント。プラットフォーム固有の設定のため
	 * コンストラクタで固定する（クライアント共有時に他プラットフォームへ漏れる事故を防ぐ）。
	 *
	 * @param (\Closure(int, array<string,string>, string): bool)|null $rate_limit_detector
	 */
	public function __construct(
		private readonly RateLimiter $rate_limiter,
		private readonly ?\Closure $rate_limit_detector = null
	) {}

	/**
	 * @param array<string,mixed> $args wp_remote_request() に渡す追加引数（headers/body等）。
	 * @return array{status:int,headers:array<string,string>,body:string}
	 *
	 * @throws ApiException リトライ上限到達、または4xx（429以外）の場合。
	 */
	public function request( string $method, string $url, array $args = [] ): array {
		$attempt = 0;

		// POSTは「新規作成」であり、対象APIはべき等キーを提供しない。5xxや接続タイムアウトは
		// リクエストが実際にサーバー側で処理済みかどうか判別できないため、盲目的にリトライすると
		// リモート側にレコードが重複作成されうる。429（サーバーが未処理のまま拒否したことが確定）
		// はPOSTでも安全にリトライしてよいため、この判定は5xx/タイムアウト系のみに適用する。
		$retry_ambiguous_failures = 'POST' !== $method;

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
				if ( $retry_ambiguous_failures && $attempt < self::MAX_RETRIES && $this->is_retryable_wp_error( $response ) ) {
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

			if ( $is_rate_limited || ( $retry_ambiguous_failures && $status >= 500 ) ) {
				$retry_after = $this->retry_after_seconds( $headers );

				if ( $attempt < self::MAX_RETRIES && ( null === $retry_after || $retry_after <= self::MAX_RETRY_AFTER_SECONDS ) ) {
					$this->sleep_with_backoff( $attempt, $retry_after );
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
	 * Retry-After ヘッダーを秒数に解釈する。RFC 9110 は秒数と HTTP-date の両形式を許容する。
	 * 解釈できない値は null（指数バックオフにフォールバック）。
	 *
	 * @param array<string,string> $headers
	 */
	private function retry_after_seconds( array $headers ): ?int {
		if ( ! isset( $headers['retry-after'] ) ) {
			return null;
		}

		$value = trim( $headers['retry-after'] );

		if ( is_numeric( $value ) ) {
			return max( 0, (int) $value );
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return null;
		}

		return max( 0, $timestamp - time() );
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
