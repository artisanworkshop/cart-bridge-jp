<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Core;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Woo\Support\MappingCandidates;

/**
 * カテゴリのマッピング（`category_map`: Woo のカテゴリ → ASP のカテゴリ）。商品のエクスポートが使う（`Woo\Reader\ProductReader`）。
 * カテゴリを作れない接続先（ColorMe）でだけ使う。
 */
final class CategoryMappingKind extends MappingKind {

	public function key(): string {
		return 'category';
	}

	public function position(): int {
		return 10;
	}

	public function source_side(): string {
		return self::SOURCE_WOO;
	}

	public function woo_candidates(): array {
		return MappingCandidates::categories();
	}

	public function applies_to( PlatformAdapter $adapter ): bool {
		return ! $adapter->capabilities()->can_create_category;
	}
}
