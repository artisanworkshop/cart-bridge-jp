<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

use CartBridgeJP\Canonical\Concerns\ChecksumTrait;
use CartBridgeJP\Canonical\Concerns\RemoteIdFromExtrasTrait;

/**
 * 正規化されたクーポンモデル。
 */
final readonly class CanonicalCoupon implements CanonicalModel {

	use ChecksumTrait;
	use RemoteIdFromExtrasTrait;

	/**
	 * @param 'fixed'|'percent'    $type
	 * @param ?int                 $usage_limit_per_user 1ユーザーあたりの利用可能回数。WooCommerceの
	 *   ネイティブなクーポン設定（usage_limit_per_user）に対応する。
	 * @param array<string,mixed>  $extras ASP固有フィールドの退避先。
	 */
	public function __construct(
		public string $code,
		public string $type,
		public string $amount,
		public ?string $min_amount,
		public ?string $expires_at,
		public ?int $usage_limit,
		public bool $free_shipping = false,
		public ?int $usage_limit_per_user = null,
		public array $extras = []
	) {}

	public function to_array(): array {
		return [
			'code'                 => $this->code,
			'type'                 => $this->type,
			'amount'               => $this->amount,
			'min_amount'           => $this->min_amount,
			'expires_at'           => $this->expires_at,
			'usage_limit'          => $this->usage_limit,
			'free_shipping'        => $this->free_shipping,
			'usage_limit_per_user' => $this->usage_limit_per_user,
			'extras'               => $this->extras,
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
			(bool) ( $data['free_shipping'] ?? false ),
			isset( $data['usage_limit_per_user'] ) ? (int) $data['usage_limit_per_user'] : null,
			(array) ( $data['extras'] ?? [] )
		);
	}
}
