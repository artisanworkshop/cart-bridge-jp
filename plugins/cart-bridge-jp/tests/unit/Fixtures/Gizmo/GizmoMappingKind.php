<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures\Gizmo;

use CartBridgeJP\Entities\MappingKind;

/**
 * gizmo の色のマッピング（`gizmo_map`。ASP の色 → Woo の色）。
 */
final class GizmoMappingKind extends MappingKind {

	public function key(): string {
		return 'gizmo';
	}

	public function position(): int {
		return 10;
	}

	public function source_side(): string {
		return self::SOURCE_ASP;
	}

	public function woo_candidates(): array {
		return [
			[
				'id'   => 'red',
				'name' => 'Red',
			],
		];
	}

	public function import_notice(): bool {
		return true;
	}
}
