<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe;

use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\HttpClient;
use CartBridgeJP\Support\RateLimiter;

/**
 * カラーミーショップ REST API（`https://api.shop-pro.jp/v1/`）の薄いラッパー。
 * 認証ヘッダー付与・JSONエンコード/デコード・エラーレスポンス
 * （`{"errors":[{"code":int,"message":string,"status":int}]}`）の {@see ApiException} 変換のみを担い、
 * リトライ・レート制限待機は内部の {@see HttpClient} に委譲する。
 */
final class ColorMeClient {

	private const DEFAULT_BASE_URL = 'https://api.shop-pro.jp/v1/';

	/**
	 * カラーミーの公称上限は120req/分/トークンだが、CLAUDE.md の方針どおり100/分に抑える。
	 */
	private const RATE_LIMIT_PER_MINUTE = 100;

	public function __construct(
		private readonly HttpClient $http_client,
		private readonly string $access_token,
		private readonly string $base_url = self::DEFAULT_BASE_URL
	) {}

	/**
	 * 接続ごとに独立したレート制限バケットを持つインスタンスを生成する。
	 */
	public static function for_access_token( string $access_token, string $base_url = self::DEFAULT_BASE_URL ): self {
		return new self(
			new HttpClient( new RateLimiter( 'colorme', self::RATE_LIMIT_PER_MINUTE ) ),
			$access_token,
			$base_url
		);
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 *
	 * @throws ApiException
	 */
	public function get( string $path, array $query = [] ): array {
		return $this->request( 'GET', $path, $query );
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 *
	 * @throws ApiException
	 */
	public function post( string $path, array $body = [] ): array {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 *
	 * @throws ApiException
	 */
	public function put( string $path, array $body = [] ): array {
		return $this->request( 'PUT', $path, $body );
	}

	/**
	 * @param array<string,mixed> $params GETはクエリパラメータ、POST/PUTはJSONボディとして使う。
	 * @return array<string,mixed>
	 *
	 * @throws ApiException
	 */
	private function request( string $method, string $path, array $params ): array {
		$is_write = in_array( $method, [ 'POST', 'PUT' ], true );

		$url  = $this->build_url( $path, $is_write ? [] : $params );
		$args = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->access_token,
				'Accept'        => 'application/json',
			],
		];

		if ( $is_write ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $params );
		}

		try {
			$response = $this->http_client->request( $method, $url, $args );
		} catch ( ApiException $exception ) {
			throw $this->translate_exception( $exception );
		}

		return $this->decode_body( $response['body'] );
	}

	/**
	 * @param array<string,mixed> $query
	 */
	private function build_url( string $path, array $query ): string {
		$url = rtrim( $this->base_url, '/' ) . '/' . ltrim( $path, '/' );

		if ( [] !== $query ) {
			// セパレータは明示（ini設定 arg_separator.output=&amp; の環境で
			// `amp;offset` のような壊れたパラメータ名になるのを防ぐ）。
			$url .= '?' . http_build_query( $query, '', '&' );
		}

		return $url;
	}

	/**
	 * @return array<string,mixed>
	 *
	 * @throws ApiException JSONとしてデコードできない場合。
	 */
	private function decode_body( string $body ): array {
		if ( '' === trim( $body ) ) {
			return [];
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			throw new ApiException(
				'Failed to decode ColorMe API response as JSON.',
				0,
				[ 'body' => $body ]
			);
		}

		return $decoded;
	}

	/**
	 * カラーミーのエラー形式をパースし、メッセージ・エラーコードを持つ {@see ApiException} に変換する。
	 * ボディが無い、あるいは期待した形式でない場合は元の例外をそのまま返す
	 * （429リトライ上限到達時など、HttpClient側でボディを保持していないケースを含む）。
	 */
	private function translate_exception( ApiException $exception ): ApiException {
		$body    = $exception->context()['body'] ?? null;
		$decoded = is_string( $body ) ? json_decode( $body, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['errors'][0] ) || ! is_array( $decoded['errors'][0] ) ) {
			return $exception;
		}

		$first = $decoded['errors'][0];

		return new ApiException(
			isset( $first['message'] ) ? (string) $first['message'] : $exception->getMessage(),
			$exception->status_code(),
			array_merge(
				$exception->context(),
				[
					'colorme_error_code' => isset( $first['code'] ) ? (int) $first['code'] : 0,
					'colorme_errors'     => $decoded['errors'],
				]
			),
			$exception
		);
	}
}
