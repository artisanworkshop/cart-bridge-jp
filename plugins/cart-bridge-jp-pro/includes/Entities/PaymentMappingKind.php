<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Entities;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Pro\Adapters\CommerceAdapters;
use CartBridgeJP\Pro\Woo\Support\OrderMappingCandidates;

/**
 * 決済方法（`payment_map`: ASP の決済方法 → Woo の決済ゲートウェイ）。受注の取込み（`OrderWriter`）とエクスポート（逆引き）が使う。
 *
 * R3-6c1 で受注の種類と一緒に Pro アドオンへ移した。
 */
final class PaymentMappingKind extends MappingKind {

	public function key(): string {
		return 'payment';
	}

	public function label(): string {
		return __( 'Payment method mapping', 'cart-bridge-jp-pro' );
	}

	public function description(): string {
		return __( 'Maps each platform payment method to a WooCommerce payment method. Imported orders with an unmapped payment method get an empty WooCommerce payment method (the platform’s name is kept as the title) and a warning. When exporting orders, the same mapping is used, where possible, to choose the platform payment method.', 'cart-bridge-jp-pro' );
	}

	public function source_heading(): string {
		return __( 'Platform payment method', 'cart-bridge-jp-pro' );
	}

	public function target_heading(): string {
		return __( 'WooCommerce payment method', 'cart-bridge-jp-pro' );
	}

	public function no_targets_help(): string {
		return __( 'No WooCommerce payment methods are available. Set them up in WooCommerce > Settings > Payments first.', 'cart-bridge-jp-pro' );
	}

	public function position(): int {
		return 10;
	}

	public function source_side(): string {
		return self::SOURCE_ASP;
	}

	/**
	 * 受注を取り込める接続先（`CommerceAdapter` がある）だけで使う（R3-6c1）。
	 */
	public function applies_to( PlatformAdapter $adapter ): bool {
		return null !== CommerceAdapters::get( $adapter );
	}

	/**
	 * ASP 側の決済方法の候補は接続先の `CommerceAdapter` が持つ（R3-6c1 で `PlatformAdapter::mapping_candidates()` から移した）。
	 * 無い接続先は空（アダプタの `mapping_candidates()` には無いので、null を返して探させない）。
	 */
	public function platform_candidates( PlatformAdapter $adapter ): array {
		return CommerceAdapters::get( $adapter )?->payment_candidates() ?? [];
	}

	public function woo_candidates(): array {
		return OrderMappingCandidates::payment_gateways();
	}

	/**
	 * 未設定の受注は空の値と警告で取り込まれるので、取込みの前に未設定の数を案内する（R3-0m）。
	 */
	public function import_notice(): bool {
		return true;
	}
}
