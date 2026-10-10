<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Entities\EntityTypeRegistry;

/**
 * mappings が指すローカルID群のうち、Woo側に実在するものを確認する（移行後検証レポート=D17）。
 * 商品/クーポン/ターム/コメントはコアのクエリ関数でチャンク単位に確認する。顧客（ユーザー）・受注は
 * `CommerceLookup`（R3-6c1 でこのクラスから分けた）。
 */
final class LocalEntityLookup {

	private const CHUNK_SIZE = 200;

	/**
	 * 実体の種類ごとの実在確認（種類の `EntityType::existing_local_ids()`。登録の無い種類・確かめられない種類は空）。
	 *
	 * @param array<int,int> $ids
	 * @return array<int,int> 実在するIDのみ（重複除去）。
	 */
	public function existing_ids( string $entity, array $ids ): array {
		return EntityTypeRegistry::get( $entity )?->existing_local_ids( $ids ) ?? [];
	}

	/**
	 * 投稿型の実体のうち実在するもの（ゴミ箱は含まない）。
	 *
	 * @param array<int,string> $post_types
	 * @param array<int,int>    $ids
	 * @return array<int,int>
	 */
	public function existing_posts( array $post_types, array $ids ): array {
		return $this->in_chunks( $ids, fn ( array $chunk ): array => $this->existing_post_ids( $post_types, $chunk ) );
	}

	/**
	 * @param array<int,int> $ids
	 * @return array<int,int>
	 */
	public function existing_terms( string $taxonomy, array $ids ): array {
		return $this->in_chunks( $ids, fn ( array $chunk ): array => $this->existing_term_ids( $taxonomy, $chunk ) );
	}

	/**
	 * @param array<int,int> $ids
	 * @return array<int,int>
	 */
	public function existing_comments( array $ids ): array {
		return $this->in_chunks( $ids, fn ( array $chunk ): array => $this->existing_comment_ids( $chunk ) );
	}

	/**
	 * @param array<int,int> $ids
	 * @return array<int,int>
	 */
	private function normalize_ids( array $ids ): array {
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * 重複を除いた ID を `CHUNK_SIZE` 件ずつ `$lookup` に渡し、結果をつなぐ。
	 *
	 * @param array<int,int>                       $ids
	 * @param callable(array<int,int>):array<int,int> $lookup
	 * @return array<int,int>
	 */
	private function in_chunks( array $ids, callable $lookup ): array {
		$existing = [];

		foreach ( array_chunk( $this->normalize_ids( $ids ), self::CHUNK_SIZE ) as $chunk ) {
			$existing = array_merge( $existing, $lookup( $chunk ) );
		}

		return $existing;
	}

	/**
	 * @param array<int,string> $post_types
	 * @param array<int,int>    $chunk
	 * @return array<int,int>
	 */
	private function existing_post_ids( array $post_types, array $chunk ): array {
		$ids = get_posts(
			[
				'post_type'      => $post_types,
				'post__in'       => $chunk,
				'post_status'    => 'any',
				'posts_per_page' => count( $chunk ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @param array<int,int> $chunk
	 * @return array<int,int>
	 */
	private function existing_term_ids( string $taxonomy, array $chunk ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'include'    => $chunk,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
	}

	/**
	 * @param array<int,int> $chunk
	 * @return array<int,int>
	 */
	private function existing_comment_ids( array $chunk ): array {
		$ids = get_comments(
			[
				'comment__in' => $chunk,
				'fields'      => 'ids',
				'number'      => count( $chunk ),
			]
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}
}
