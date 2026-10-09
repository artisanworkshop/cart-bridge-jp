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

	public function label(): string {
		return __( 'Category mapping', 'cart-bridge-jp' );
	}

	public function description(): string {
		return __( 'Used when exporting products. This platform cannot create new categories, so pick an existing platform category for each WooCommerce category you plan to export.', 'cart-bridge-jp' );
	}

	public function source_heading(): string {
		return __( 'WooCommerce category', 'cart-bridge-jp' );
	}

	public function target_heading(): string {
		return __( 'Platform category', 'cart-bridge-jp' );
	}

	public function unmapped_label(): string {
		return __( '— No category —', 'cart-bridge-jp' );
	}

	public function no_targets_help(): string {
		return __( 'No platform categories are available to choose from. Check the connection, or create the categories on the platform first.', 'cart-bridge-jp' );
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
