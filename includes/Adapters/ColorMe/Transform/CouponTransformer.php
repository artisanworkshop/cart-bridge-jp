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
	 * F1-4で制限方法が定まれば再検討できる）。`group_limit_type`はレスポンススキーマ上必須では
	 * なく、欠損・不正値・想定外の新enum値もありうるため、`none`（無制限）と明示された場合のみ
	 * 変換する（欠損時にnone扱いへ倒れると、実は商品グループ制限がある割引が全商品対象の
	 * クーポンとして作られてしまう）。
	 *
	 * `starts_at`（利用開始日時）が未来のクーポンも除外する。WooCommerceのネイティブなクーポンには
	 * 開始日時の概念が無く（有効期限＝expires_atのみ）、`CanonicalCoupon` にも開始日時フィールドが
	 * 無いため、そのまま変換すると本来まだ使えないはずのコードがWoo側では作成直後から即座に
	 * 使用可能になってしまう（`starts_at`はextrasに保持済みなので開始日時を表現する仕組みが
	 * 整えばF1-4で再検討できる）。
	 *
	 * `ends_at`（利用終了日）が欠損・非数値の場合も除外する。`CanonicalCoupon::expires_at`は
	 * 欠損時にnull（無期限）になり、レスポンススキーマ上必須ではないため、期限付きクーポンの
	 * 部分的なレスポンスから無期限のWooクーポンが作られてしまうと金銭的リスクがある。
	 *
	 * いずれの場合も `null` を返し、呼び出し側でスキップさせる。
	 *
	 * @param array<string,mixed> $raw `shop_coupons[]` の1要素。
	 */
	public function transform( array $raw ): ?CanonicalCoupon {
		if ( 'available' !== ( $raw['status'] ?? null ) ) {
			return null;
		}

		if ( 'none' !== ( $raw['group_limit_type'] ?? null ) ) {
			return null;
		}

		$starts_at = Cast::to_int_or_null( $raw['starts_at'] ?? null );

		if ( null !== $starts_at && $starts_at > time() ) {
			return null;
		}

		$coupon_type = Cast::to_string_or_null( $raw['coupon_type'] ?? null );

		// `coupon_type`はレスポンススキーマ上必須ではなく、欠損・不正値・想定外の新enum値も
		// ありうる。未知の値を`fixed`（対応外の折り返し）に丸めると、例えば定率(`rate`)の
		// フィールド欠損レスポンスが定額割引として誤って再現されうるため、既知の3値のみ処理する。
		if ( ! in_array( $coupon_type, [ 'amount', 'rate', 'delivery_charge' ], true ) ) {
			return null;
		}

		$usage_limit = $raw['usage_limit'] ?? null;

		// `usage_limit`もレスポンススキーマ上必須ではない。`disposable`以外を無条件に`indisposable`
		// （無制限）扱いする三項演算子だと、欠損・不正値・想定外の新enum値のレスポンスから
		// 1人1回制限が失われたクーポンが作られてしまうため、既知の2値のみ処理する。
		if ( ! in_array( $usage_limit, [ 'disposable', 'indisposable' ], true ) ) {
			return null;
		}

		$expires_at = Cast::unix_to_iso( $raw['ends_at'] ?? null );

		// `ends_at`が欠損・非数値の場合、`CanonicalCoupon::expires_at`はnull（無期限）になる。
		// レスポンススキーマ上必須ではないが、期限付きクーポンの部分的なレスポンスから
		// 無期限のWooクーポンが作られてしまうと金銭的リスクがあるため、欠損時は除外する。
		if ( null === $expires_at ) {
			return null;
		}

		$remote_id        = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';
		$is_free_shipping = 'delivery_charge' === $coupon_type;

		return new CanonicalCoupon(
			Cast::to_string_or_null( $raw['code'] ?? null ) ?? '',
			'rate' === $coupon_type ? 'percent' : 'fixed',
			$is_free_shipping ? '0' : Cast::money( $raw['discount_amount'] ?? null ),
			Cast::to_string_or_null( $raw['minimum_amount'] ?? null ),
			$expires_at,
			// `total_usage_limit` が発行総数（int）。ColorMeの `usage_limit` フィールドは
			// `indisposable`/`disposable` の1ユーザーあたり上限を表す列挙文字列であり別物
			// （`(int) 'indisposable' === 0` になる罠のため混同しないこと）。
			Cast::to_int_or_null( $raw['total_usage_limit'] ?? null ),
			$is_free_shipping,
			// `disposable`はWooの usage_limit_per_user=1 に、`indisposable`は無制限（null）に対応する。
			'disposable' === $usage_limit ? 1 : null,
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
