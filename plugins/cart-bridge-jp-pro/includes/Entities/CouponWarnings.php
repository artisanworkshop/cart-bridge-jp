<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Entities;

use CartBridgeJP\Entities\WarningFlag;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Pro\Woo\CommerceWarningCode;
use CartBridgeJP\Woo\WarningCatalog;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- 向き・行の種類を使わない説明もある（`describe()` のシグネチャは共通）。

/**
 * クーポンの警告コードの判定の印と店舗向けの説明（`CouponType`。R3-6b1 で `Woo\WarningCode`・`Woo\WarningCatalog` から移した）。
 * **R3-6c で Pro アドオンへ移す**（`Entities/Commerce/` ごと）。文言を変えたら dry-run の CSV の説明も変わる（`WarningCatalogTest`）。
 */
final class CouponWarnings {

	private function __construct() {}

	/**
	 * コード => 判定の印（`EntityType::warning_flags()`）。印の無いコードも、説明があるので載せる。
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function flags(): array {
		return [
			CommerceWarningCode::COUPON_REUSED_EXISTING    => [],
			// `Woo\Reader\CouponReader`: ASP側へ運べないWooネイティブのクーポン制限
			// （商品/カテゴリ/メールアドレス制限・maximum_amount・fixed_product型）が残っている。
			// `has_unsupported_restrictions=true`のまま`push_coupon()`（E2-3）へ渡すと、制限が
			// 落ちた無制限クーポンとして保存されうる金銭的リスクがあるため、pushせずフェイル
			// クローズする（`Canonical\CanonicalCoupon`のdocblockが定める契約、importの
			// `Woo\Writer\CouponWriter`と同じ判断をexport側でも読出時点から適用する）。
			CommerceWarningCode::COUPON_RESTRICTIONS_UNSUPPORTED => [ WarningFlag::EXPORT_BLOCKING ],
			CommerceWarningCode::COUPON_RESTRICTIONS_UNKNOWN => [],
			CommerceWarningCode::COUPON_CODE_CONFLICT      => [],
			CommerceWarningCode::COUPON_TYPE_UNKNOWN       => [],
			CommerceWarningCode::COUPON_AMOUNT_INVALID     => [],
			CommerceWarningCode::COUPON_EXPIRES_AT_INVALID => [],
			CommerceWarningCode::COUPON_MIN_AMOUNT_INVALID => [],
			CommerceWarningCode::COUPON_SAVE_FAILED        => [],
			// `Woo\Reader\CouponReader`: 金額が JPY でない（受注と同じ理由で送らない。説明は `OrderWarnings`）。
			CommerceWarningCode::CURRENCY_MISMATCH         => [ WarningFlag::EXPORT_BLOCKING ],
		];
	}

	/**
	 * 文言の書き方は `Woo\WarningCatalog::entry()` と同じ（detail を差し込む文言は `%` を `%%` と書く）。
	 */
	public static function describe( string $code, bool $import, string $row_entity ): ?WarningText {
		$blocking = WarningCatalog::SEVERITY_BLOCKING;
		$info     = WarningCatalog::SEVERITY_INFO;

		return match ( $code ) {
			CommerceWarningCode::COUPON_REUSED_EXISTING => self::make(
				$info,
				__( 'A coupon with the same code that was imported from this platform before already exists, so it is linked and updated.', 'cart-bridge-jp-pro' ),
				'',
				/* translators: %s: the WooCommerce ID of a coupon. */
				__( 'A coupon with the same code that was imported from this platform before already exists (coupon ID %s), so it is linked and updated.', 'cart-bridge-jp-pro' )
			),
			CommerceWarningCode::COUPON_RESTRICTIONS_UNSUPPORTED => $import
				? self::make(
					$blocking,
					__( 'The coupon has usage restrictions that WooCommerce cannot represent, so it is not imported or updated (without them, it could be used more widely). A coupon that was imported before is not changed or disabled, so it stays usable without these restrictions.', 'cart-bridge-jp-pro' ),
					__( 'Check the coupon’s restrictions on the platform and create the coupon in WooCommerce by hand with equivalent restrictions. If it was imported before, add the restrictions to it or disable it.', 'cart-bridge-jp-pro' )
				)
				: self::make(
					$blocking,
					__( 'The coupon has settings the platform cannot represent (such as product, category or email restrictions, a maximum spend, “Individual use only”, or a fixed product discount), or it has already been used, so it is not exported.', 'cart-bridge-jp-pro' ),
					__( 'Remove those settings, or create the coupon on the platform by hand. For a coupon that has been used, create a new coupon.', 'cart-bridge-jp-pro' )
				),
			CommerceWarningCode::COUPON_RESTRICTIONS_UNKNOWN => self::make(
				$blocking,
				__( 'The platform’s connector did not say whether the coupon has usage restrictions, so it is not imported or updated (to avoid creating it without them). A coupon that was imported before is not changed or disabled, so it stays usable as it was.', 'cart-bridge-jp-pro' ),
				__( 'Check the coupon’s restrictions on the platform and create the coupon in WooCommerce by hand. If it was imported before, check its restrictions, or disable it.', 'cart-bridge-jp-pro' )
			),
			CommerceWarningCode::COUPON_CODE_CONFLICT => self::make(
				$blocking,
				__( 'Another WooCommerce coupon already uses the same code, so this coupon is not imported or updated.', 'cart-bridge-jp-pro' ),
				__( 'Rename or delete the existing WooCommerce coupon, or change the code on the platform, then import again.', 'cart-bridge-jp-pro' ),
				/* translators: %s: the WooCommerce ID of a coupon. */
				__( 'Another WooCommerce coupon (coupon ID %s) already uses the same code, so this coupon is not imported or updated.', 'cart-bridge-jp-pro' )
			),
			CommerceWarningCode::COUPON_TYPE_UNKNOWN => self::make(
				$blocking,
				__( 'The coupon’s discount type is not supported (only a fixed amount or a percentage), so it is not imported.', 'cart-bridge-jp-pro' ),
				__( 'Create the coupon in WooCommerce by hand.', 'cart-bridge-jp-pro' ),
				/* translators: %s: the discount type received from the platform. */
				__( 'The coupon’s discount type “%s” is not supported (only a fixed amount or a percentage), so it is not imported.', 'cart-bridge-jp-pro' )
			),
			CommerceWarningCode::COUPON_AMOUNT_INVALID => self::make(
				$blocking,
				__( 'The coupon’s discount is not valid (not a number, negative, or a percentage over 100), so it is not imported.', 'cart-bridge-jp-pro' ),
				__( 'Correct the discount on the platform, or create the coupon in WooCommerce by hand.', 'cart-bridge-jp-pro' ),
				/* translators: %s: the discount value received from the platform. */
				__( 'The coupon’s discount (%s) is not valid (not a number, negative, or a percentage over 100), so it is not imported.', 'cart-bridge-jp-pro' )
			),
			CommerceWarningCode::COUPON_EXPIRES_AT_INVALID => self::make(
				$blocking,
				__( 'The coupon’s expiry date cannot be read, so it is not imported.', 'cart-bridge-jp-pro' ),
				__( 'Create the coupon in WooCommerce by hand.', 'cart-bridge-jp-pro' ),
				/* translators: %s: the expiry date received from the platform. */
				__( 'The coupon’s expiry date (%s) cannot be read, so it is not imported.', 'cart-bridge-jp-pro' )
			),
			CommerceWarningCode::COUPON_MIN_AMOUNT_INVALID => self::make(
				$blocking,
				__( 'The coupon’s minimum spend is not a number or is negative, so it is not imported.', 'cart-bridge-jp-pro' ),
				__( 'Correct the minimum spend on the platform, then import again.', 'cart-bridge-jp-pro' ),
				/* translators: %s: the minimum spend received from the platform. */
				__( 'The coupon’s minimum spend (%s) is not a number or is negative, so it is not imported.', 'cart-bridge-jp-pro' )
			),
			CommerceWarningCode::COUPON_SAVE_FAILED => self::make(
				$blocking,
				__( 'WooCommerce could not save the coupon, so it is not imported.', 'cart-bridge-jp-pro' ),
				__( 'Check the PHP error log for the cause, then import again.', 'cart-bridge-jp-pro' )
			),
			default => null,
		};
	}

	private static function make( string $severity, string $message, string $action = '', string $detail_message = '' ): WarningText {
		return new WarningText( $severity, $message, $action, $detail_message );
	}
}
