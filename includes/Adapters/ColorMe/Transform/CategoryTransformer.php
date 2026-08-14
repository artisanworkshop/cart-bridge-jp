<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalCategory;

/**
 * `GET /v1/categories.json` の1要素（大カテゴリー、`children[]` に小カテゴリーを持つ）を
 * `CanonicalCategory` のフラットな配列へ変換する。
 */
final class CategoryTransformer {

	/**
	 * @param array<string,mixed> $raw `categories.json` の `categories[]` の1要素。
	 * @return array<int,CanonicalCategory> 大カテゴリー1件 + 小カテゴリーN件。
	 */
	public function transform( array $raw ): array {
		$id = Cast::category_ref( $raw['id_big'] ?? null, 0 );

		if ( null === $id ) {
			return [];
		}

		$categories   = [];
		$categories[] = new CanonicalCategory(
			$id,
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
			null,
			null
		);

		$children = $raw['children'] ?? [];

		if ( is_array( $children ) ) {
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) ) {
					continue;
				}

				$child_id = Cast::category_ref( $raw['id_big'] ?? null, $child['id_small'] ?? null );

				if ( null === $child_id || $child_id === $id ) {
					continue;
				}

				$categories[] = new CanonicalCategory(
					$child_id,
					Cast::to_string_or_null( $child['name'] ?? null ) ?? '',
					$id,
					null
				);
			}
		}

		return $categories;
	}
}
