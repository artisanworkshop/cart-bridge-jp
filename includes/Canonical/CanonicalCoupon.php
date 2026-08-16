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
	 * `extras`は元々このモデルの最終（7番目）の位置指定引数だった。`cbjp/adapters/register`
	 * 経由の外部アダプタ/アドオンが位置引数で `new CanonicalCoupon(..., $extras)` のように
	 * 呼び出す可能性があるため、`extras`より後の引数（`free_shipping`/`usage_limit_per_user`を
	 * 含む）はすべて`extras`より後ろに追加すること。`extras`を追い越す位置に新しい引数を
	 * 挿入すると、型不一致（配列がbool/int引数に渡る等）で外部呼び出し元がTypeErrorになる。
	 *
	 * @param 'fixed'|'percent'    $type
	 * @param array<string,mixed>  $extras ASP固有フィールドの退避先。
	 * @param ?int                 $usage_limit_per_user 1ユーザーあたりの利用可能回数。WooCommerceの
	 *   ネイティブなクーポン設定（usage_limit_per_user）に対応する。
	 */
	public function __construct(
		public string $code,
		public string $type,
		public string $amount,
		public ?string $min_amount,
		public ?string $expires_at,
		public ?int $usage_limit,
		public array $extras = [],
		public bool $free_shipping = false,
		public ?int $usage_limit_per_user = null
	) {}

	public function to_array(): array {
		return [
			'code'                 => $this->code,
			'type'                 => $this->type,
			'amount'               => $this->amount,
			'min_amount'           => $this->min_amount,
			'expires_at'           => $this->expires_at,
			'usage_limit'          => $this->usage_limit,
			'extras'               => $this->extras,
			'free_shipping'        => $this->free_shipping,
			'usage_limit_per_user' => $this->usage_limit_per_user,
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
			(array) ( $data['extras'] ?? [] ),
			(bool) ( $data['free_shipping'] ?? false ),
			isset( $data['usage_limit_per_user'] ) ? (int) $data['usage_limit_per_user'] : null
		);
	}
}
