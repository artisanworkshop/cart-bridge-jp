<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Support\Logger;
use Automattic\WooCommerce\Utilities\OrderUtil;
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
			$entity                                    = self::SOURCES[ $index ];
			[ 'scanned' => $scanned, 'rows' => $rows ] = $this->scan( $platform, $entity, $offset, $remaining );

			foreach ( $rows as $local_id => $remote_id ) {
				if ( '' === $remote_id ) {
					continue;
				}

				$this->mappings->upsert( $platform, $entity, $remote_id, $local_id, null );
				++$counts[ $entity ];
			}

			// cursor の前進判定は「クエリが返した件数」（所有権フィルタで除外した分を含む）で行う。
			// upsert 対象の件数で判定すると、除外行が多いページで走査し切ったと誤認して残りを飛ばす。
			if ( $scanned < $remaining ) {
				// 要求件数に満たない＝このエンティティは走査し切った。
				++$index;
				$offset = 0;
			} else {
				$offset += $scanned;
			}

			$remaining -= $scanned;
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
	 * @return array{scanned:int,rows:array<int,string>} `scanned` はクエリが返した件数（cursor 用）、
	 *   `rows` は所有権を確認できた local_id => remote_id（未設定は ''）。
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
			default    => [
				'scanned' => 0,
				'rows'    => [],
			],
		};
	}

	/**
	 * @return array{scanned:int,rows:array<int,string>}
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
			return [
				'scanned' => 0,
				'rows'    => [],
			];
		}

		$ids = array_map( 'intval', $terms );
		update_termmeta_cache( $ids );

		$rows = [];

		foreach ( $ids as $term_id ) {
			$rows[ $term_id ] = $this->meta_string( get_term_meta( $term_id, '_cbjp_remote_id', true ) );
		}

		return [
			'scanned' => count( $ids ),
			'rows'    => $rows,
		];
	}

	/**
	 * @return array{scanned:int,rows:array<int,string>}
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

		return [
			'scanned' => count( $ids ),
			'rows'    => $rows,
		];
	}

	/**
	 * @return array{scanned:int,rows:array<int,string>}
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

		return [
			'scanned' => count( $ids ),
			'rows'    => $rows,
		];
	}

	/**
	 * 受注は要件どおり WooCommerce CRUD 経由。`meta_query` を解釈するのは HPOS の `OrdersTableQuery` だけで、
	 * レガシー（投稿型）ストレージでは WC 9.2+ が「非対応引数」として無視し `doing_it_wrong` を出す
	 * （`WC_Order_Data_Store_CPT::query()`）。そのため絞り込みは HPOS 有効時の最適化としてのみ付け、
	 * どちらの構成でも取得後に `_cbjp_platform` を検証したものだけを upsert 対象にする（レガシー構成では
	 * 全受注を offset ページングで走査することになるが、他プラットフォーム由来の受注を誤って紐付けない）。
	 *
	 * @return array{scanned:int,rows:array<int,string>}
	 */
	private function scan_orders( string $platform, int $offset, int $limit ): array {
		$args = [
			'type'    => 'shop_order',
			'limit'   => $limit,
			'offset'  => $offset,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'objects',
		];

		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に出自を判定する手段が無い。
			$args['meta_query'] = $this->ownership_meta_query( $platform );
		}

		$orders  = wc_get_orders( $args );
		$scanned = 0;
		$rows    = [];

		foreach ( (array) $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			++$scanned;

			if ( $order->get_meta( '_cbjp_platform' ) === $platform ) {
				$rows[ $order->get_id() ] = $this->meta_string( $order->get_meta( '_cbjp_remote_order_number' ) );
			}
		}

		return [
			'scanned' => $scanned,
			'rows'    => $rows,
		];
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
