<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Fixtures;

use WP_Error;

/**
 * ライセンスの要求を `pre_http_request` で受けて、積んだ応答を順に返すテスト用の販売サイト（R3-8a）。
 * 応答の本文は実測のフィクスチャ（`tests/fixtures/api-manager/`）か任意の値。受けた要求は `$requests` に残す。
 * 積んだ応答が尽きた後の要求には `WP_Error` を返す（送らないはずの要求をテストが見逃さないよう、`$requests` でも数える）。
 */
final class FakeLicenseServer {

	/**
	 * @var array<int,array{url:string,args:array<string,mixed>}>
	 */
	public array $requests = [];

	/**
	 * @var array<int,array<string,mixed>|WP_Error>
	 */
	private array $responses = [];

	public static function install(): self {
		$server = new self();
		add_filter( 'pre_http_request', [ $server, 'handle' ], 10, 3 );

		return $server;
	}

	public function fixture( string $name ): self {
		return $this->json( FixtureLoader::load( 'api-manager', $name ) );
	}

	public function json( mixed $body, int $code = 200 ): self {
		return $this->raw( (string) wp_json_encode( $body ), $code );
	}

	public function raw( string $body, int $code = 200 ): self {
		$this->responses[] = [
			'headers'  => [ 'content-type' => 'application/json; charset=UTF-8' ],
			'body'     => $body,
			'response' => [
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			],
			'cookies'  => [],
			'filename' => null,
		];

		return $this;
	}

	public function error( string $message ): self {
		$this->responses[] = new WP_Error( 'http_request_failed', $message );

		return $this;
	}

	/**
	 * @param false|array<string,mixed>|WP_Error $preempt
	 * @param array<string,mixed>                $args
	 * @return array<string,mixed>|WP_Error
	 */
	public function handle( $preempt, array $args, string $url ): array|WP_Error {
		unset( $preempt );
		$this->requests[] = [
			'url'  => $url,
			'args' => $args,
		];

		return array_shift( $this->responses ) ?? new WP_Error( 'unexpected_request', 'No response is queued.' );
	}

	/**
	 * 最後の要求の本文。
	 *
	 * @return array<string,mixed>
	 */
	public function last_body(): array {
		$last = end( $this->requests );
		$body = false === $last ? null : ( $last['args']['body'] ?? null );

		return is_array( $body ) ? $body : [];
	}
}
