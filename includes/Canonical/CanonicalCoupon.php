<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;

/**
 * 正規化されたクーポンモデル。
 */
final class CanonicalCoupon implements CanonicalModel {

	use ChecksumTrait;

	/**
	 * @param 'fixed'|'percent'    $type
	 * @param array<string,mixed> $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $type,
		public readonly string $amount,
		public readonly ?string $min_amount,
		public readonly ?string $expires_at,
		public readonly ?int $usage_limit,
		public readonly array $extras = []
	) {}

	public function to_array(): array {
		return [
			'code'        => $this->code,
			'type'        => $this->type,
			'amount'      => $this->amount,
			'min_amount'  => $this->min_amount,
			'expires_at'  => $this->expires_at,
			'usage_limit' => $this->usage_limit,
			'extras'      => $this->extras,
		];
	}

	public static function from_array( array $data ): self {
		$type = (string) ( $data['type'] ?? 'fixed' );

		return new self(
			(string) ( $data['code'] ?? '' ),
			'percent' === $type ? 'percent' : 'fixed',
			(string) ( $data['amount'] ?? '0' ),
			isset( $data['min_amount'] ) ? (string) $data['min_amount'] : null,
			isset( $data['expires_at'] ) ? (string) $data['expires_at'] : null,
			isset( $data['usage_limit'] ) ? (int) $data['usage_limit'] : null,
			(array) ( $data['extras'] ?? [] )
		);
	}
}
