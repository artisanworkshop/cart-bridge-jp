<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\Support\PlatformOwnership;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WC_Coupon;

/**
 * `CanonicalCoupon` をWooのクーポン（`WC_Coupon`）として書き込む。
 */
final class CouponWriter implements EntityWriter {

	public function __construct( private readonly string $platform ) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalCoupon ) {
			throw new RuntimeException( 'CouponWriter received an unsupported Canonical model.' );
		}

		$group_limit_type = Value::string( $item->extras['group_limit_type'] ?? null );

		if ( null !== $group_limit_type && 'none' !== $group_limit_type ) {
			// 特定会員グループ限定のクーポンはWooに対応機能が無い。制限を無視して保存すると
			// 実質「全顧客が使える無制限クーポン」として機能してしまい金銭的リスクに直結するため
			// （ColorMeの`CouponTransformer`はこのケースを既に除外しているが、`extras`経由で
			// 直接構築されうる外部アダプタは信頼境界のため、ここでも警告だけでなく保存自体を
			// 見送るフェイルクローズにする）。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::COUPON_GROUP_LIMIT_UNSUPPORTED ] );
		}

		$warnings = [];
		$coupon   = null !== $existing_local_id ? new WC_Coupon( $existing_local_id ) : new WC_Coupon();

		if ( 0 === $coupon->get_id() ) {
			// クーポンコードはWooCommerce内で一意である必要があるため（他商品と違いSKUのような
			// 「奪わない」選択肢が無い）、既存の同コードクーポンがあれば再利用する。
			// ただし、`_cbjp_platform`が自分自身と一致する場合のみ再利用する
			// （VariationWriterの他プラットフォーム保護と同じ理由）。一致しない場合は
			// 店舗独自クーポン・別プラットフォーム由来のクーポンを誤って上書きしてしまう
			// リスクがあり、かつコード重複のまま新規作成するとWoo側でどちらが適用されるか
			// 不定になる別の金銭的リスクを生むため、保存自体を見送る。
			$conflict_id = wc_get_coupon_id_by_code( $item->code );

			if ( 0 !== $conflict_id ) {
				if ( PlatformOwnership::owns_post( $conflict_id, $this->platform ) ) {
					$coupon     = new WC_Coupon( $conflict_id );
					$warnings[] = WarningCode::with_detail( WarningCode::COUPON_REUSED_EXISTING, (string) $conflict_id );
				} else {
					return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::COUPON_CODE_CONFLICT, (string) $conflict_id ) ] );
				}
			}
		}

		$operation = 0 === $coupon->get_id() ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		$coupon->set_code( $item->code );
		// Wooのdiscount_typeスラッグは fixed_cart/fixed_product/percent（Canonicalの'fixed'は
		// そのままでは不正値になるため fixed_cart に対応付ける。カート全体への定額割引が
		// ColorMeの割引クーポンの意味に最も近い）。
		$coupon->set_discount_type( 'percent' === $item->type ? 'percent' : 'fixed_cart' );
		$coupon->set_amount( $item->amount );
		$coupon->set_minimum_amount( $item->min_amount ?? '' );
		$coupon->set_date_expires( $item->expires_at );
		$coupon->set_usage_limit( $item->usage_limit );
		$coupon->set_usage_limit_per_user( $item->usage_limit_per_user );
		$coupon->set_free_shipping( $item->free_shipping );
		$coupon->set_description( Value::string( $item->extras['name'] ?? null ) ?? '' );

		ExtrasMeta::apply( $coupon, $this->meta_extras( $item->extras ) );
		$coupon->update_meta_data( '_cbjp_platform', $this->platform );
		$coupon->update_meta_data( '_cbjp_remote_id', $item->remote_id() ?? '' );

		$coupon_id = $coupon->save();

		return new WriteResult( $coupon_id, $operation, $warnings );
	}

	/**
	 * @param array<string,mixed> $extras
	 * @return array<string,mixed>
	 */
	private function meta_extras( array $extras ): array {
		unset( $extras['remote_id'], $extras['name'] );

		return $extras;
	}
}
