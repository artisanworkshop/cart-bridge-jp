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
	 * 呼び出す可能性があるため、`extras`より後の引数（`free_shipping`/`usage_limit_per_user`/
	 * `has_unsupported_restrictions`を含む）はすべて`extras`より後ろに追加すること。`extras`を
	 * 追い越す位置に新しい引数を挿入すると、型不一致（配列がbool/int引数に渡る等）で外部
	 * 呼び出し元がTypeErrorになる。
	 *
	 * @param 'fixed'|'percent'    $type
	 * @param array<string,mixed>  $extras ASP固有フィールドの退避先。
	 * @param ?int                 $usage_limit_per_user 1ユーザーあたりの利用可能回数。WooCommerceの
	 *   ネイティブなクーポン設定（usage_limit_per_user）に対応する。
	 * @param ?bool                $has_unsupported_restrictions Wooのネイティブなクーポン設定では
	 *   表現できない利用制限（特定の商品グループ・会員グループ限定等）がASP側に設定されているか。
	 *   ASP固有のキー名・enum値を知るのはアダプタだけなので、判定は各アダプタのTransformerが行い、
	 *   `Woo\Writer\CouponWriter` はこの正規化フィールドだけを見る（アーキテクチャ原則1）。
	 *   三値の意味:
	 *   - `false`: 制限は無い（またはWooで表現できる範囲に収まる）。そのまま保存してよい
	 *   - `true`:  Wooで表現できない制限がある。無視して保存すると実質「全顧客・全商品に効く
	 *     無制限クーポン」として機能してしまうため、`CouponWriter` が保存を見送る
	 *   - `null`:  アダプタが宣言していない（不明）。楽観的に「制限なし」へ倒すと金銭的リスクに
	 *     直結する（アーキテクチャ原則9）ため、`CouponWriter` は同じく保存を見送る。
	 *     **クーポンを供給するアダプタは必ず `true`/`false` を明示的に渡すこと**
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
		public ?int $usage_limit_per_user = null,
		public ?bool $has_unsupported_restrictions = null
	) {}

	public function to_array(): array {
		return [
			'code'                         => $this->code,
			'type'                         => $this->type,
			'amount'                       => $this->amount,
			'min_amount'                   => $this->min_amount,
			'expires_at'                   => $this->expires_at,
			'usage_limit'                  => $this->usage_limit,
			'extras'                       => $this->extras,
			'free_shipping'                => $this->free_shipping,
			'usage_limit_per_user'         => $this->usage_limit_per_user,
			'has_unsupported_restrictions' => $this->has_unsupported_restrictions,
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
			isset( $data['usage_limit_per_user'] ) ? (int) $data['usage_limit_per_user'] : null,
			// キー自体が無い（＝宣言されていない）場合はnullのまま保ち、`CouponWriter`の
			// フェイルクローズ対象にする。`false`へ倒すと復元経路だけが楽観的になってしまう。
			isset( $data['has_unsupported_restrictions'] ) ? (bool) $data['has_unsupported_restrictions'] : null
		);
	}
}
