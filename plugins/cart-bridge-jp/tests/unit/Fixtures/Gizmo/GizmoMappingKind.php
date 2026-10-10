<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures\Gizmo;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Entities\MappingKind;
use Throwable;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `MappingKind` のシグネチャに合わせる。

/**
 * gizmo の色のマッピング（`gizmo_map`。ASP の色 → Woo の色）。
 */
final class GizmoMappingKind extends MappingKind {

	/**
	 * @param array<int,mixed>|Throwable|null $own_candidates `platform_candidates()` が返す値（null はアダプタの候補を使う）。例外は投げる。
	 */
	public function __construct( private readonly array|Throwable|null $own_candidates = null ) {}

	public function key(): string {
		return 'gizmo';
	}

	public function label(): string {
		return 'Gizmo colour mapping';
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

	public function platform_candidates( PlatformAdapter $adapter ): ?array {
		if ( $this->own_candidates instanceof Throwable ) {
			throw $this->own_candidates;
		}

		return $this->own_candidates;
	}

	public function import_notice(): bool {
		return true;
	}

	public function description(): string {
		return 'Maps platform colours to WooCommerce colours.';
	}

	public function source_heading(): string {
		return 'Platform colour';
	}
}
