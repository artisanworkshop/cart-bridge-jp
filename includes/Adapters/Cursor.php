<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * 不透明なページングカーソル。プラットフォームごとにペイロード内容は異なる
 * （colorme/base: `['offset' => int]`、makeshopはページング仕様確定後に定義）。
 * `cbjp_jobs.cursor_json` に永続化する。
 */
final class Cursor {

	/**
	 * @param array<string,mixed> $payload
	 */
	public function __construct( public readonly array $payload = [] ) {}

	public static function start(): self {
		return new self();
	}

	public function is_start(): bool {
		return [] === $this->payload;
	}

	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->payload[ $key ] ?? $fallback;
	}

	public function to_json(): string {
		return (string) wp_json_encode( $this->payload );
	}

	public static function from_json( ?string $json ): self {
		if ( null === $json || '' === $json ) {
			return self::start();
		}

		$decoded = json_decode( $json, true );

		return new self( is_array( $decoded ) ? $decoded : [] );
	}
}
