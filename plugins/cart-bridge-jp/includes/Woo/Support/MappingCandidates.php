<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WP_Term;

/**
 * `/settings/mappings/{platform}` UI向けのWoo側マッピング候補一覧（D19）。プラットフォーム非依存
 * （`Adapters\*`を一切importしない）のため、アダプタを介さず`RestController`から直接呼べる
 * （アーキテクチャ原則1に抵触しない。ASP側の候補は各アダプタの`mapping_candidates()`が持つ）。
 * 無料版はカテゴリだけ。決済・配送・注文ステータスは `OrderMappingCandidates`（R3-6c1 でこのクラスから分けた）。
 */
final class MappingCandidates {

	private function __construct() {}

	/**
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function categories(): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$candidates = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$candidates[] = [
				'id'   => (string) $term->term_id,
				// `$term->name`はDB保存時にエンティティ化された生の値（例: `Men &amp; Women`）を
				// そのまま返す。HTML出力ならブラウザが1回だけデコードするが、このJSON APIはReactの
				// テキストノードへそのまま渡るため、Reactが再度エスケープして「Men &amp; Women」が
				// 文字どおり表示されてしまう。ここでデコードしておく（G3指摘）。
				'name' => wp_specialchars_decode( $term->name, ENT_QUOTES ),
			];
		}

		return $candidates;
	}
}
