<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Entities;

/**
 * 顧客・受注・クーポンの種類を `cbjp/entity_types/register` に登録する（R3-6b1）。
 *
 * D27 で顧客・受注・クーポンの移行は Pro アドオンの機能になった。R3-6c1 でこの登録ごと無料版から移し、Pro の `Core\Plugin::boot()` が
 * 登録する（無料版の外部の種類と同じ公開の口）。
 */
final class CommerceEntityTypes {

	private function __construct() {}

	/**
	 * `cbjp/entity_types/register` のコールバック。先行する外部フィルターが配列以外を返していても落ちないよう、型を宣言せず受けて正規化する。
	 *
	 * @param mixed $types
	 * @return array<int|string,mixed>
	 */
	public static function register( mixed $types ): array {
		if ( ! is_array( $types ) ) {
			$types = [];
		}

		$types[] = new CustomerType();
		$types[] = new OrderType();
		$types[] = new CouponType();

		return $types;
	}
}
