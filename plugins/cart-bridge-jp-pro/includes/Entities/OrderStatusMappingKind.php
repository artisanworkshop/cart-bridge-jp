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
 * 注文ステータス（`status_map`: ASP の受注ステータス → Woo の注文ステータス）。受注の取込みが使う。未設定は既定のステータスに落ちる（警告にならないので取込み前の案内はしない）。
 *
 * **R3-6c で Pro アドオンへ移す**（受注の種類と一緒に）。
 */
final class OrderStatusMappingKind extends MappingKind {

	public function key(): string {
		return 'status';
	}

	public function label(): string {
		return __( 'Order status mapping', 'cart-bridge-jp-pro' );
	}

	public function description(): string {
		return __( 'Overrides the WooCommerce status an imported order gets for each platform status. Leave it at Default to use the standard status.', 'cart-bridge-jp-pro' );
	}

	public function source_heading(): string {
		return __( 'Platform order status', 'cart-bridge-jp-pro' );
	}

	public function target_heading(): string {
		return __( 'WooCommerce order status', 'cart-bridge-jp-pro' );
	}

	public function unmapped_label(): string {
		return __( '— Default —', 'cart-bridge-jp-pro' );
	}

	public function no_targets_help(): string {
		return __( 'No WooCommerce order statuses are available.', 'cart-bridge-jp-pro' );
	}

	public function position(): int {
		return 30;
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
	 * ASP 側の注文ステータスの候補は接続先の `CommerceAdapter` が持つ（R3-6c1 で `PlatformAdapter::mapping_candidates()` から移した）。
	 * 無い接続先は空（アダプタの `mapping_candidates()` には無いので、null を返して探させない）。
	 */
	public function platform_candidates( PlatformAdapter $adapter ): array {
		return CommerceAdapters::get( $adapter )?->status_candidates() ?? [];
	}

	public function woo_candidates(): array {
		return OrderMappingCandidates::order_statuses();
	}
}
