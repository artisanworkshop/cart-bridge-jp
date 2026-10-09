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
