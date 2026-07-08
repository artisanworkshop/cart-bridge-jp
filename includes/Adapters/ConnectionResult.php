<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * `testConnection()` の結果。失敗理由にトークン等の機密情報を含めないこと。
 */
final class ConnectionResult {

	public function __construct(
		public readonly bool $ok,
		public readonly ?string $shop_name = null,
		public readonly ?string $message = null
	) {}

	public static function success( ?string $shop_name = null ): self {
		return new self( true, $shop_name );
	}

	public static function failure( string $message ): self {
		return new self( false, null, $message );
	}
}
