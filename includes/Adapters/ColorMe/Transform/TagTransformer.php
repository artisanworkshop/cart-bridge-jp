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
 * `parent_group_id`（グループの階層）は `CanonicalTag` が `(id, name)` のみのため
 * 表現できない。Woo側では常にフラットなタグとして扱われる。
 */
final class TagTransformer {

	/**
	 * `display_state: hidden` のグループ（例: 実フィクスチャの「なし」既定グループ）は
	 * 内部管理用でショップ非公開のものが多い。`CanonicalTag` は可視性を表現できないため、
	 * ここで除外して null を返す（呼び出し側=F1-5のColorMeAdapterはnullをスキップする）。
	 * こうすることで、内部用グループが誰にでも見えるWooタグとして作成されるのを防ぐ。
	 *
	 * @param array<string,mixed> $raw `groups.json` の `groups[]` の1要素。
	 */
	public function transform( array $raw ): ?CanonicalTag {
		if ( 'hidden' === ( $raw['display_state'] ?? null ) ) {
			return null;
		}

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
