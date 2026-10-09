<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

use Exception;

/**
 * ASP APIへのリクエストが失敗した際にHttpClientおよびアダプタClientが投げる例外。
 */
final class ApiException extends Exception {

	/**
	 * @param array<string,mixed> $context platform/status_code/body 等の付随情報。個人情報・トークンは含めないこと。
	 */
	public function __construct(
		string $message,
		private readonly int $status_code = 0,
		private readonly array $context = [],
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function status_code(): int {
		return $this->status_code;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function context(): array {
		return $this->context;
	}

	public function is_rate_limited(): bool {
		return (bool) ( $this->context['rate_limited'] ?? false );
	}

	public function retry_after_seconds(): ?int {
		$value = $this->context['retry_after'] ?? null;

		return null === $value ? null : (int) $value;
	}
}
