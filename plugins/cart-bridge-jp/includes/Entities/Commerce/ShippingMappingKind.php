<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Commerce;

use CartBridgeJP\Adapters\CommerceAdapters;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Woo\Support\OrderMappingCandidates;

/**
 * 配送方法（`shipping_map`: ASP の配送方法 → Woo の配送方法〔ゾーンのインスタンス〕）。受注の取込みとエクスポート（逆引き）が使う。
 *
 * **R3-6c で Pro アドオンへ移す**（受注の種類と一緒に）。
 */
final class ShippingMappingKind extends MappingKind {

	public function key(): string {
		return 'shipping';
	}

	public function label(): string {
		return __( 'Shipping method mapping', 'cart-bridge-jp' );
	}

	public function description(): string {
		return __( 'Maps each platform shipping method to a shipping method in a WooCommerce shipping zone. Imported orders with an unmapped shipping method keep only the platform’s name on the shipping line and get a warning. When exporting orders, the same mapping is used, where possible, to choose the platform shipping method.', 'cart-bridge-jp' );
	}

	public function source_heading(): string {
		return __( 'Platform shipping method', 'cart-bridge-jp' );
	}

	public function target_heading(): string {
		return __( 'WooCommerce shipping method', 'cart-bridge-jp' );
	}

	public function no_targets_help(): string {
		return __( 'No WooCommerce shipping methods are available. Add a shipping zone with a shipping method in WooCommerce > Settings > Shipping first.', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 20;
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
	 * ASP 側の配送方法の候補は接続先の `CommerceAdapter` が持つ（R3-6c1 で `PlatformAdapter::mapping_candidates()` から移した）。
	 * 無い接続先は空（アダプタの `mapping_candidates()` には無いので、null を返して探させない）。
	 */
	public function platform_candidates( PlatformAdapter $adapter ): array {
		return CommerceAdapters::get( $adapter )?->shipping_candidates() ?? [];
	}

	public function woo_candidates(): array {
		return OrderMappingCandidates::shipping_methods();
	}

	/**
	 * 未設定の受注は空の値と警告で取り込まれるので、取込みの前に未設定の数を案内する（R3-0m）。
	 */
	public function import_notice(): bool {
		return true;
	}
}
