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
	 * プラットフォーム単位で共有されるレート制限バケット（{@see RateLimiter}）を使う
	 * インスタンスを生成する。現状ColorMe接続は同時に1つのみ保持するため、この
	 * バケットは実質的にアクセストークン単位でもある。
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
	 * multipart/form-data のPOSTリクエスト（`POST /products/{id}/images`専用）。カラーミーの画像
	 * アップロードはバイナリを`multipart/form-data`で送る必要があり、`request()`のJSON body契約とは
	 * 別経路になる。`Support\HttpClient::request()`はメソッド非依存に`headers`/`body`をそのまま
	 * `wp_remote_request()`へ渡すため、boundary付きのbody文字列を手組みしてheadersの
	 * `Content-Type`を差し替えるだけで足りる（`HttpClient`側の改修は不要）。
	 *
	 * @param array<string,int|string> $fields 画像バイナリと一緒に送るスカラーフィールド（例: position）。
	 * @return array<string,mixed>
	 *
	 * @throws ApiException
	 */
	public function post_multipart( string $path, string $field_name, string $filename, string $binary, array $fields = [] ): array {
		$boundary = wp_generate_password( 32, false );
		$args     = [
			'headers' => [
				'Authorization' => 'Bearer ' . $this->access_token,
				'Accept'        => 'application/json',
				'Content-Type'  => "multipart/form-data; boundary={$boundary}",
			],
			'body'    => self::build_multipart_body( $boundary, $field_name, $filename, $binary, $fields ),
		];

		try {
			$response = $this->http_client->request( 'POST', $this->build_url( $path, [] ), $args );
		} catch ( ApiException $exception ) {
			throw $this->translate_exception( $exception );
		}

		return $this->decode_body( $response['body'] );
	}

	/**
	 * @param array<string,int|string> $fields
	 */
	private static function build_multipart_body( string $boundary, string $field_name, string $filename, string $binary, array $fields ): string {
		$body = '';

		foreach ( $fields as $name => $value ) {
			$body .= "--{$boundary}\r\n";
			$body .= 'Content-Disposition: form-data; name="' . self::escape_multipart_value( (string) $name ) . "\"\r\n\r\n";
			$body .= $value . "\r\n";
		}

		$body .= "--{$boundary}\r\n";
		$body .= 'Content-Disposition: form-data; name="' . self::escape_multipart_value( $field_name ) . '"; filename="' . self::escape_multipart_value( $filename ) . "\"\r\n";
		$body .= "Content-Type: application/octet-stream\r\n\r\n";
		$body .= $binary . "\r\n";
		$body .= "--{$boundary}--\r\n";

		return $body;
	}

	/**
	 * multipartヘッダー値（`Content-Disposition`のname/filename）にCR/LF/二重引用符が混入すると
	 * リクエストが壊れる（ヘッダーインジェクション）。ファイル名はWordPressのメディアライブラリ
	 * 由来だが、防御的に除去する。
	 */
	private static function escape_multipart_value( string $value ): string {
		return str_replace( [ '"', "\r", "\n" ], [ '\\"', '', '' ], $value );
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
