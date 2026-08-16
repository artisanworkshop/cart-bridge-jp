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

		$warnings = [];
		$coupon   = null !== $existing_local_id ? new WC_Coupon( $existing_local_id ) : new WC_Coupon();

		if ( 0 === $coupon->get_id() ) {
			// クーポンコードはWooCommerce内で一意である必要があるため（他商品と違いSKUのような
			// 「奪わない」選択肢が無い）、既存の同コードクーポンがあれば再利用する。
			$conflict_id = wc_get_coupon_id_by_code( $item->code );

			if ( 0 !== $conflict_id ) {
				$coupon     = new WC_Coupon( $conflict_id );
				$warnings[] = WarningCode::with_detail( WarningCode::COUPON_REUSED_EXISTING, (string) $conflict_id );
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

		$group_limit_type = Value::string( $item->extras['group_limit_type'] ?? null );

		if ( null !== $group_limit_type && 'none' !== $group_limit_type ) {
			$warnings[] = WarningCode::COUPON_GROUP_LIMIT_UNSUPPORTED;
		}

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
