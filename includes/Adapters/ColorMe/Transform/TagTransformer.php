<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalTag;
use RuntimeException;

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
		$id = Cast::to_string_or_null( $raw['id'] ?? null );

		if ( null === $id ) {
			// `id` は `remote_id()` としてmappingsのUNIQUEキーに使われる。空文字のまま
			// 通すと欠損IDのグループが全て同一remote_idに衝突するため、ここで必須として弾く。
			throw new RuntimeException( 'ColorMe group is missing id; cannot determine tag remote id.' );
		}

		return new CanonicalTag(
			$id,
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? ''
		);
	}
}
