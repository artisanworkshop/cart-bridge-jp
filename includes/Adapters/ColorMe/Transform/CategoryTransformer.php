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
	 * `hidden`/`members_only` のカテゴリーは `CanonicalCategory` に可視性を表すフィールドが
	 * 無いため、ここで除外する（`showing`のみを保持）。Wooの商品カテゴリーは常に公開の
	 * タクソノミーであり、可視性を保ったまま作成する手段が無いため、除外が最も安全な既定挙動。
	 * 大カテゴリーが除外された場合、可視な小カテゴリーは親なし（トップレベル）として扱う
	 * （存在しない大カテゴリーを指す`parent_id`を残すと、mappings経由で親を解決するF1-4側の
	 * 処理が不整合を起こすため）。
	 *
	 * @param array<string,mixed> $raw `categories.json` の `categories[]` の1要素。
	 * @return array<int,CanonicalCategory> 大カテゴリー0〜1件 + 小カテゴリーN件。
	 */
	public function transform( array $raw ): array {
		$id = Cast::category_ref( $raw['id_big'] ?? null, 0 );

		if ( null === $id ) {
			return [];
		}

		$categories      = [];
		$parent_included = self::is_visible( $raw['display_state'] ?? null );

		if ( $parent_included ) {
			$categories[] = new CanonicalCategory(
				$id,
				Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
				null,
				null
			);
		}

		$children = $raw['children'] ?? [];

		if ( is_array( $children ) ) {
			foreach ( $children as $child ) {
				if ( ! is_array( $child ) || ! self::is_visible( $child['display_state'] ?? null ) ) {
					continue;
				}

				$child_id = Cast::category_ref( $raw['id_big'] ?? null, $child['id_small'] ?? null );

				if ( null === $child_id || $child_id === $id ) {
					continue;
				}

				$categories[] = new CanonicalCategory(
					$child_id,
					Cast::to_string_or_null( $child['name'] ?? null ) ?? '',
					$parent_included ? $id : null,
					null
				);
			}
		}

		return $categories;
	}

	private static function is_visible( mixed $display_state ): bool {
		return ! in_array( $display_state, [ 'hidden', 'members_only' ], true );
	}
}
