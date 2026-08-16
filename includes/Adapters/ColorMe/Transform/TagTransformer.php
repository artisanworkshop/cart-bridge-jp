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
 * `parent_group_id`（グループの階層）は `CanonicalTag` の主要フィールドに対応が無いため
 * 表現できない。Woo側では常にフラットなタグとして扱われる。
 */
final class TagTransformer {

	/**
	 * このTransformerが読む `GET /v1/groups.json` `GET /v1/groups/{id}.json` の**レスポンス**
	 * スキーマでは、`display_state` は `showing`/`hidden`/`showing_for_members`/`sale_for_members`
	 * の4値（商品の `display_state` と同じenum。swagger:
	 * `tests/fixtures/colorme/swagger.json:13611-13618`、`:13945-13953`）。`members_only`は
	 * `POST /v1/groups`（グループ作成）の**リクエスト**スキーマ側のみの値（同ファイル:13702-13709）
	 * であり、GETレスポンスには出現しないため混同しないこと。
	 *
	 * `hidden`（非掲載）と `showing_for_members`（会員にのみ掲載）のグループは一般公開されていない
	 * （例: 実フィクスチャの「なし」既定グループは `hidden`）。`CanonicalTag` は可視性を
	 * 表現できないため、ここで除外して null を返す（呼び出し側=F1-5のColorMeAdapterは
	 * nullをスキップする）。こうすることで、非公開グループが誰にでも見えるWooタグとして
	 * 作成されるのを防ぐ。`sale_for_members`（掲載状態だが購入は会員のみ可能）は一般公開の
	 * 掲載状態のため除外しない（ProductTransformer::status()と同じ区別）。
	 *
	 * `display_state`はレスポンススキーマ上必須ではなく、欠損・不正値・想定外の新enum値も
	 * ありうるため、既知の非公開値の否定ではなく、明示的に公開状態と分かる値（`showing`/
	 * `sale_for_members`）の肯定的な許可リストとして判定する（欠損時に公開扱いへ倒れると、
	 * 可視性不明のグループ由来のタグが`tag_refs`経由で商品にも公開状態で紐付いてしまう）。
	 *
	 * @param array<string,mixed> $raw `groups.json` の `groups[]` の1要素。
	 */
	public function transform( array $raw ): ?CanonicalTag {
		if ( ! in_array( $raw['display_state'] ?? null, [ 'showing', 'sale_for_members' ], true ) ) {
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
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
			[
				'description' => Cast::sanitize_html( $raw['expl'] ?? null ),
				'image_url'   => Cast::to_string_or_null( $raw['image_url'] ?? null ),
				'sort'        => Cast::to_int_or_null( $raw['sort'] ?? null ),
				'meta_tag'    => Cast::meta_tag( $raw['meta_tag'] ?? null ),
			]
		);
	}
}
