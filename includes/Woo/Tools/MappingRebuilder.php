<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Support\Logger;
use CartBridgeJP\Sync\MappingRepository;
use InvalidArgumentException;
use WC_Order;

/**
 * リンク再構築ツール（D16 / `docs/03-design-decisions.md` §10.3）。
 *
 * 再インストール・DB移設等で `cbjp_mappings` が失われた場合に、各 Writer が Woo 側の実体へ必ず
 * 書き込む `_cbjp_platform` + `_cbjp_remote_id`（受注は `_cbjp_remote_order_number` =
 * `CanonicalOrder::remote_id()`）メタを走査して mappings を復元する。SKU / email による突合は
 * 「本プラグイン外で作られた Woo データを ASP に紐付ける」動作になり誤リンクのリスクがあるため
 * 行わない（メタが無いデータは対象外）。
 *
 * checksum は null で upsert する（`Sync\Importer` が次回 import 時に必ず再検証する）。
 * stock は product/variant の mapping から次回 import 時に再解決され、review は v1.0 に Writer が
 * 無いため、どちらも再構築の対象外。
 *
 * 1回の `run()` は予算（`$budget` 件）まで走査して cursor を返し、呼び出し側がループする。
 * upsert は走査結果を変えないため offset ページングで安定に継続できる。
 */
final class MappingRebuilder {

	public const DEFAULT_BUDGET = 200;

	/**
	 * 走査順（cursor の `entity` に使う）。
	 *
	 * @var array<int,string>
	 */
	public const SOURCES = [ 'category', 'tag', 'product', 'variant', 'coupon', 'customer', 'order' ];

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * @return array{counts:array<string,int>,cursor:?string} `cursor` が null なら完了。
	 *
	 * @throws InvalidArgumentException cursor が不正な場合。
	 */
	public function run( string $platform, ?string $cursor = null, int $budget = self::DEFAULT_BUDGET ): array {
		[ $index, $offset ] = $this->decode_cursor( $cursor );

		$counts    = array_fill_keys( self::SOURCES, 0 );
		$remaining = max( 1, $budget );
		$sources   = count( self::SOURCES );

		while ( $index < $sources && $remaining > 0 ) {
			$entity = self::SOURCES[ $index ];
			$rows   = $this->scan( $platform, $entity, $offset, $remaining );

			foreach ( $rows as $local_id => $remote_id ) {
				if ( '' === $remote_id ) {
					continue;
				}

				$this->mappings->upsert( $platform, $entity, $remote_id, $local_id, null );
				++$counts[ $entity ];
			}

			$fetched = count( $rows );

			if ( $fetched < $remaining ) {
				// 要求件数に満たない＝このエンティティは走査し切った。
				++$index;
				$offset = 0;
			} else {
				$offset += $fetched;
			}

			$remaining -= $fetched;
		}

		$next_cursor = $index < $sources ? $this->encode_cursor( $index, $offset ) : null;

		$this->logger->info(
			'Mapping rebuild batch finished.',
			[
				'platform' => $platform,
				'counts'   => $counts,
				'done'     => null === $next_cursor,
			]
		);

		return [
			'counts' => $counts,
			'cursor' => $next_cursor,
		];
	}

	/**
	 * @return array<int,string> local_id => remote_id（未設定は ''）。
	 */
	private function scan( string $platform, string $entity, int $offset, int $limit ): array {
		return match ( $entity ) {
			'category' => $this->scan_terms( 'product_cat', $platform, $offset, $limit ),
			'tag'      => $this->scan_terms( 'product_tag', $platform, $offset, $limit ),
			'product'  => $this->scan_posts( 'product', $platform, $offset, $limit ),
			'variant'  => $this->scan_posts( 'product_variation', $platform, $offset, $limit ),
			'coupon'   => $this->scan_posts( 'shop_coupon', $platform, $offset, $limit ),
			'customer' => $this->scan_users( $platform, $offset, $limit ),
			'order'    => $this->scan_orders( $platform, $offset, $limit ),
			default    => [],
		};
	}

	/**
	 * @return array<int,string>
	 */
	private function scan_terms( string $taxonomy, string $platform, int $offset, int $limit ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
				'meta_query' => $this->ownership_meta_query( $platform ),
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$ids = array_map( 'intval', $terms );
		update_termmeta_cache( $ids );

		$rows = [];

		foreach ( $ids as $term_id ) {
			$rows[ $term_id ] = $this->meta_string( get_term_meta( $term_id, '_cbjp_remote_id', true ) );
		}

		return $rows;
	}

	/**
	 * @return array<int,string>
	 */
	private function scan_posts( string $post_type, string $platform, int $offset, int $limit ): array {
		$ids = get_posts(
			[
				'post_type'      => $post_type,
				// ゴミ箱は対象外（リンクを戻すと次回 import が更新経路でゴミ箱の実体を復活させてしまう）。
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
				'meta_query'     => $this->ownership_meta_query( $platform ),
			]
		);

		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'post', $ids );

		$rows = [];

		foreach ( $ids as $post_id ) {
			$rows[ $post_id ] = $this->meta_string( get_post_meta( $post_id, '_cbjp_remote_id', true ) );
		}

		return $rows;
	}

	/**
	 * @return array<int,string>
	 */
	private function scan_users( string $platform, int $offset, int $limit ): array {
		$ids = get_users(
			[
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
				'meta_key'   => '_cbjp_platform',
				'meta_value' => $platform,
				'number'     => $limit,
				'offset'     => $offset,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'fields'     => 'ID',
			]
		);

		$ids = array_map( 'intval', $ids );
		update_meta_cache( 'user', $ids );

		$rows = [];

		foreach ( $ids as $user_id ) {
			$rows[ $user_id ] = $this->meta_string( get_user_meta( $user_id, '_cbjp_remote_id', true ) );
		}

		return $rows;
	}

	/**
	 * 受注は要件どおり WooCommerce CRUD 経由（HPOS の `OrdersTableQuery` は `meta_query` /
	 * `offset` を解釈する。CPT 構成でも `WP_Query` 経由で同じ引数が通る）。
	 *
	 * @return array<int,string>
	 */
	private function scan_orders( string $platform, int $offset, int $limit ): array {
		$orders = wc_get_orders(
			[
				'type'       => 'shop_order',
				'limit'      => $limit,
				'offset'     => $offset,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'return'     => 'objects',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
				'meta_query' => $this->ownership_meta_query( $platform ),
			]
		);

		$rows = [];

		foreach ( (array) $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$rows[ $order->get_id() ] = $this->meta_string( $order->get_meta( '_cbjp_remote_order_number' ) );
			}
		}

		return $rows;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function ownership_meta_query( string $platform ): array {
		return [
			[
				'key'     => '_cbjp_platform',
				'value'   => $platform,
				'compare' => '=',
			],
		];
	}

	private function meta_string( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @return array{0:int,1:int} [走査順のインデックス, offset]
	 *
	 * @throws InvalidArgumentException
	 */
	private function decode_cursor( ?string $cursor ): array {
		if ( null === $cursor || '' === $cursor ) {
			return [ 0, 0 ];
		}

		$decoded = json_decode( $cursor, true );
		$entity  = is_array( $decoded ) ? ( $decoded['entity'] ?? null ) : null;
		$offset  = is_array( $decoded ) ? ( $decoded['offset'] ?? null ) : null;
		$index   = is_string( $entity ) ? array_search( $entity, self::SOURCES, true ) : false;

		if ( false === $index || ! is_int( $offset ) || $offset < 0 ) {
			throw new InvalidArgumentException( 'Invalid rebuild cursor.' );
		}

		return [ $index, $offset ];
	}

	private function encode_cursor( int $index, int $offset ): string {
		return (string) wp_json_encode(
			[
				'entity' => self::SOURCES[ $index ],
				'offset' => $offset,
			]
		);
	}
}
