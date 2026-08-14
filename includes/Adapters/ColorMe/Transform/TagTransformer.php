<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalTag;

/**
 * `GET /v1/groups.json` の1要素をWooのタグに対応する `CanonicalTag` へ変換する。
 * `parent_group_id`（グループの階層）と `display_state`（非表示グループ）は
 * `CanonicalTag` が `(id, name)` のみのため表現できない。Woo側では常にフラットな
 * タグとして扱われる。
 */
final class TagTransformer {

	/**
	 * @param array<string,mixed> $raw `groups.json` の `groups[]` の1要素。
	 */
	public function transform( array $raw ): CanonicalTag {
		return new CanonicalTag(
			Cast::to_string_or_null( $raw['id'] ?? null ) ?? '',
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? ''
		);
	}
}
