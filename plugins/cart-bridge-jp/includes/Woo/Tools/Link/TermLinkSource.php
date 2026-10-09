<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools\Link;

use CartBridgeJP\Entities\LinkSource;

/**
 * リンク再構築: タクソノミーのターム（カテゴリ・タグ）。取込みがタームに書く `_cbjp_platform`・`_cbjp_remote_id` から復元する。
 */
final class TermLinkSource extends LinkSource {

	public function __construct(
		private readonly string $key,
		private readonly int $position,
		private readonly string $label,
		private readonly string $taxonomy
	) {}

	public function key(): string {
		return $this->key;
	}

	public function label(): string {
		return $this->label;
	}

	public function position(): int {
		return $this->position;
	}

	public function scan( string $platform, int $offset, int $limit ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $this->taxonomy,
				'hide_empty' => false,
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
				'meta_query' => self::ownership_meta_query( $platform ),
			]
		);

		if ( ! is_array( $terms ) ) {
			return [
				'scanned' => 0,
				'rows'    => [],
			];
		}

		$ids = array_map( 'intval', $terms );
		update_termmeta_cache( $ids );

		$rows = [];

		foreach ( $ids as $term_id ) {
			$rows[ $term_id ] = self::meta_string( get_term_meta( $term_id, '_cbjp_remote_id', true ) );
		}

		return [
			'scanned' => count( $ids ),
			'rows'    => $rows,
		];
	}
}
