<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Woo\Support\MappingCandidates;

/**
 * 注文ステータス（`status_map`: ASP の受注ステータス → Woo の注文ステータス）。受注の取込みが使う。未設定は既定のステータスに落ちる（警告にならないので取込み前の案内はしない）。
 *
 * **R3-6c で Pro アドオンへ移す**（受注の種類と一緒に）。
 */
final class OrderStatusMappingKind extends MappingKind {

	public function key(): string {
		return 'status';
	}

	public function label(): string {
		return __( 'Order status mapping', 'cart-bridge-jp' );
	}

	public function description(): string {
		return __( 'Overrides the WooCommerce status an imported order gets for each platform status. Leave it at Default to use the standard status.', 'cart-bridge-jp' );
	}

	public function source_heading(): string {
		return __( 'Platform order status', 'cart-bridge-jp' );
	}

	public function target_heading(): string {
		return __( 'WooCommerce order status', 'cart-bridge-jp' );
	}

	public function unmapped_label(): string {
		return __( '— Default —', 'cart-bridge-jp' );
	}

	public function no_targets_help(): string {
		return __( 'No WooCommerce order statuses are available.', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 30;
	}

	public function source_side(): string {
		return self::SOURCE_ASP;
	}

	public function woo_candidates(): array {
		return MappingCandidates::order_statuses();
	}
}
