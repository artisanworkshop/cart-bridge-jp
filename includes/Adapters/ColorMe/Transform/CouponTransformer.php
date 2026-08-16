<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalCoupon;

/**
 * `GET /v1/shop_coupons.json` の1要素を `CanonicalCoupon` へ変換する（読取専用。Woo側に再作成）。
 */
final class CouponTransformer {

	/**
	 * `status: 'unavailable'`（店舗側で手動無効化済み）のクーポンは、`CanonicalCoupon` に
	 * 有効/無効を表すフィールドが無いため、そのまま変換すると利用不能だったコードがWoo側で
	 * 再び使用可能になってしまう。`status`はレスポンススキーマ上必須ではなく、`unavailable`/
	 * `available`以外の値（欠損・不正値・想定外の新enum値）もありうるため、既知の除外値の
	 * 否定ではなく`available`の肯定的な許可リストとして判定する（欠損時にavailable扱いへ
	 * 倒れると、可否不明のクーポンを誤って有効化してしまう）。
	 *
	 * `group_limit_type`が`including`/`excluding`（特定商品グループのみ対象/除外）のクーポンも
	 * 除外する。WooCommerceのクーポン制限はタグ単位に対応しておらず、この変換層だけでは
	 * グループ→商品ID一覧の展開もできないため、そのまま作ると意図せず全商品対象の割引券に
	 * なってしまい金銭的リスクがある（`group_limit_type`/`group_ids`はextrasに保持済みなので
	 * F1-4で制限方法が定まれば再検討できる）。
	 *
	 * `starts_at`（利用開始日時）が未来のクーポンも除外する。WooCommerceのネイティブなクーポンには
	 * 開始日時の概念が無く（有効期限＝expires_atのみ）、`CanonicalCoupon` にも開始日時フィールドが
	 * 無いため、そのまま変換すると本来まだ使えないはずのコードがWoo側では作成直後から即座に
	 * 使用可能になってしまう（`starts_at`はextrasに保持済みなので開始日時を表現する仕組みが
	 * 整えばF1-4で再検討できる）。
	 *
	 * いずれの場合も `null` を返し、呼び出し側でスキップさせる。
	 *
	 * @param array<string,mixed> $raw `shop_coupons[]` の1要素。
	 */
	public function transform( array $raw ): ?CanonicalCoupon {
		if ( 'available' !== ( $raw['status'] ?? null ) ) {
			return null;
		}

		if ( in_array( $raw['group_limit_type'] ?? null, [ 'including', 'excluding' ], true ) ) {
			return null;
		}

		$starts_at = Cast::to_int_or_null( $raw['starts_at'] ?? null );

		if ( null !== $starts_at && $starts_at > time() ) {
			return null;
		}

		$remote_id   = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';
		$coupon_type = Cast::to_string_or_null( $raw['coupon_type'] ?? null );

		$is_free_shipping = 'delivery_charge' === $coupon_type;

		return new CanonicalCoupon(
			Cast::to_string_or_null( $raw['code'] ?? null ) ?? '',
			'rate' === $coupon_type ? 'percent' : 'fixed',
			$is_free_shipping ? '0' : Cast::money( $raw['discount_amount'] ?? null ),
			Cast::to_string_or_null( $raw['minimum_amount'] ?? null ),
			Cast::unix_to_iso( $raw['ends_at'] ?? null ),
			// `total_usage_limit` が発行総数（int）。ColorMeの `usage_limit` フィールドは
			// `indisposable`/`disposable` の1ユーザーあたり上限を表す列挙文字列であり別物
			// （`(int) 'indisposable' === 0` になる罠のため混同しないこと）。
			Cast::to_int_or_null( $raw['total_usage_limit'] ?? null ),
			$is_free_shipping,
			// `disposable`はWooの usage_limit_per_user=1 に、`indisposable`は無制限（null）に対応する。
			( 'disposable' === ( $raw['usage_limit'] ?? null ) ) ? 1 : null,
			$this->extras( $raw, $remote_id, $is_free_shipping )
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extras( array $raw, string $remote_id, bool $is_free_shipping ): array {
		return [
			'remote_id'            => $remote_id,
			'name'                 => Cast::to_string_or_null( $raw['name'] ?? null ),
			'free_shipping'        => $is_free_shipping,
			'per_user_usage_limit' => Cast::to_string_or_null( $raw['usage_limit'] ?? null ),
			'starts_at'            => Cast::unix_to_iso( $raw['starts_at'] ?? null ),
			'status'               => Cast::to_string_or_null( $raw['status'] ?? null ),
			'group_limit_type'     => Cast::to_string_or_null( $raw['group_limit_type'] ?? null ),
			'group_ids'            => Cast::strings( is_array( $raw['group_ids'] ?? null ) ? $raw['group_ids'] : [] ),
		];
	}
}
