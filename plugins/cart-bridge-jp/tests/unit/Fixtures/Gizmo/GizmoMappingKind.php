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
		// 外部の種類は正規化されていない値を返しうる（保存時の正規化と同じ形に揃うことの確認用）。
		return [
			[
				'id'   => 'red',
				'name' => 'Red',
			],
			[
				'id'   => " blue\n",
				'name' => ' Blue ',
			],
			[
				'id'   => "\t",
				'name' => 'Tab only',
			],
		];
	}

	public function import_notice(): bool {
		return true;
	}
}
