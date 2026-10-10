<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Woo\Support\MappingCandidates;

/**
 * 決済方法（`payment_map`: ASP の決済方法 → Woo の決済ゲートウェイ）。受注の取込み（`OrderWriter`）とエクスポート（逆引き）が使う。
 *
 * **R3-6c で Pro アドオンへ移す**（受注の種類と一緒に）。
 */
final class PaymentMappingKind extends MappingKind {

	public function key(): string {
		return 'payment';
	}

	public function label(): string {
		return __( 'Payment method mapping', 'cart-bridge-jp' );
	}

	public function description(): string {
		return __( 'Maps each platform payment method to a WooCommerce payment method. Imported orders with an unmapped payment method get an empty WooCommerce payment method (the platform’s name is kept as the title) and a warning. When exporting orders, the same mapping is used, where possible, to choose the platform payment method.', 'cart-bridge-jp' );
	}

	public function source_heading(): string {
		return __( 'Platform payment method', 'cart-bridge-jp' );
	}

	public function target_heading(): string {
		return __( 'WooCommerce payment method', 'cart-bridge-jp' );
	}

	public function no_targets_help(): string {
		return __( 'No WooCommerce payment methods are available. Set them up in WooCommerce > Settings > Payments first.', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 10;
	}

	public function source_side(): string {
		return self::SOURCE_ASP;
	}

	public function woo_candidates(): array {
		return MappingCandidates::payment_gateways();
	}

	/**
	 * 未設定の受注は空の値と警告で取り込まれるので、取込みの前に未設定の数を案内する（R3-0m）。
	 */
	public function import_notice(): bool {
		return true;
	}
}
