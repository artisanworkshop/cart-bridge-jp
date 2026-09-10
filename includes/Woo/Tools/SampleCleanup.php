<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Support\Logger;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\SampleSelector;
use CartBridgeJP\Woo\Support\PlatformOwnership;
use CartBridgeJP\Woo\Support\SideEffectGuard;
use CartBridgeJP\Woo\Writer\CustomerWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;
use WC_Coupon;
use WC_Order;
use WC_Product;
use WC_Product_Variation;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * 無料版サンプルデータの一括削除ツール（D16 / `docs/03-design-decisions.md` §10.3）。
 *
 * 対象は `cbjp_mappings`（platform単位）の記録に基づき、指す先のローカル実体が
 * `_cbjp_platform` メタで自プラットフォーム所有と確認できるものだけを削除する。
 * 所有権が無い・実体が既に無い行は mapping 行だけ外す（`unlinked`）。email突合で採用した
 * 既存WPユーザー（`CustomerWriter`）は `_cbjp_created_by_import` マーカーが無いため削除せず、
 * リンク用メタを外して残す。取り込んだ画像（添付）は、取り込み時の親商品が無くなり、既存タームの
 * `thumbnail_id` でもない孤児だけを消す（`deletable_attachment_ids()`。同一 URL の画像を複数商品が
 * 共有している場合の他商品からの参照までは追跡しない）。
 *
 * 1回の `run()` は予算（`$budget` 実体）まで削除して `has_more` を返し、呼び出し側（管理画面）が
 * 完了までループする（Action Schedulerジョブにはしない: 削除済み行は消えるので cursor 不要で、
 * 各リクエストが短く収まる）。全て消え切った呼び出しで残骸の mappings とサンプルセット
 * （`cbjp_sample_{platform}`）を削除し、次回 import でサンプルが再選定される（§10.2 #7）。
 */
final class SampleCleanup {

	public const DEFAULT_BUDGET = 100;

	/**
	 * 集計キー（削除順ではなく表示順）。`variant` は商品削除に伴う削除数、`attachment` は所有添付。
	 *
	 * @var array<int,string>
	 */
	public const RESULT_KEYS = [ 'category', 'tag', 'product', 'variant', 'customer', 'order', 'stock', 'coupon', 'review', 'attachment' ];

	/**
	 * 削除順。受注→商品（バリエーション含む）→クーポン→顧客→タグ→カテゴリの順に、参照される側を
	 * 後にする。stock/review は独立した実体を持たない（stock は商品の在庫値、review は v1.0 に
	 * Writer が無い）ため mapping 行のみ外す。
	 *
	 * @var array<int,string>
	 */
	private const ENTITY_ORDER = [ 'order', 'stock', 'review', 'product', 'variant', 'coupon', 'customer', 'tag', 'category' ];

	private const LINK_USER_META_KEYS = [ '_cbjp_platform', '_cbjp_remote_id', CustomerWriter::CREATED_BY_IMPORT_META ];

	private const IMAGE_TAXONOMIES = [ 'product_cat', 'product_tag' ];

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * 実行前の確認表示用（§10.3「実行前に削除件数を表示して確認を取る」）。
	 *
	 * @return array{counts:array<string,int>,attachments:int,customers:array{delete:int,unlink:int}}
	 */
	public function preview( string $platform ): array {
		$counts = [];

		foreach ( self::RESULT_KEYS as $key ) {
			if ( 'attachment' !== $key ) {
				$counts[ $key ] = $this->mappings->count( $platform, $key );
			}
		}

		$delete = 0;
		$unlink = 0;

		foreach ( $this->mappings->local_ids( $platform, 'customer' ) as $user_id ) {
			if ( $this->can_delete_user( $user_id, $platform ) ) {
				++$delete;
			} else {
				++$unlink;
			}
		}

		// 削除対象の画像: 既に孤児のものに加え、mapping 経由で消える商品・タームが使っているもの。
		$doomed_terms = array_merge( $this->mappings->local_ids( $platform, 'category' ), $this->mappings->local_ids( $platform, 'tag' ) );

		return [
			'counts'      => $counts,
			'attachments' => count(
				$this->deletable_attachment_ids(
					$platform,
					$this->mappings->local_ids( $platform, 'product' ),
					$this->term_thumbnail_ids( $doomed_terms )
				)
			),
			'customers'   => [
				'delete' => $delete,
				'unlink' => $unlink,
			],
		];
	}

	/**
	 * @return array{deleted:array<string,int>,unlinked:array<string,int>,has_more:bool}
	 */
	public function run( string $platform, int $budget = self::DEFAULT_BUDGET ): array {
		// 削除に伴うメール（アカウント削除通知等）・在庫復元をインポート時と同様に抑止する（D10）。
		return ( new SideEffectGuard() )->run( fn (): array => $this->run_batch( $platform, max( 1, $budget ) ) );
	}

	/**
	 * @return array{deleted:array<string,int>,unlinked:array<string,int>,has_more:bool}
	 */
	private function run_batch( string $platform, int $budget ): array {
		$deleted    = array_fill_keys( self::RESULT_KEYS, 0 );
		$unlinked   = array_fill_keys( self::RESULT_KEYS, 0 );
		$remaining  = $budget;
		$variations = new VariationWriter( $platform, $this->mappings );

		foreach ( self::ENTITY_ORDER as $entity ) {
			while ( $remaining > 0 ) {
				$page = $this->mappings->find_page( $platform, $entity, $remaining );

				if ( [] === $page ) {
					break;
				}

				foreach ( $page as $remote_id => $local_id ) {
					$variants_before = $deleted['variant'];
					$outcome         = $this->remove_entity( $platform, $entity, $local_id, $variations, $deleted );
					// 商品削除に伴う variation の削除数（`remove_entity()` が `$deleted['variant']` に加算する分）。
					// この行自身の `++$deleted[ $entity ]` より前に確定させないと、variant エンティティの行で
					// 二重に数えてしまう。
					$cascaded_variants = $deleted['variant'] - $variants_before;
					// `delete_one()` が行を消すため、次の `find_page()` は同じ行を返さない（ループは
					// `$remaining` の減算で必ず終わる）。
					$this->mappings->delete_one( $platform, $entity, (string) $remote_id );

					if ( 'deleted' === $outcome ) {
						++$deleted[ $entity ];
					} else {
						++$unlinked[ $entity ];
					}

					// 商品削除に伴う variation の削除も予算に数える（軸の多い商品が並ぶと1リクエストの
					// 実削除数が予算を大きく超えるため）。
					$remaining -= 1 + $cascaded_variants;
				}
			}

			if ( $remaining <= 0 ) {
				return $this->result( $platform, $deleted, $unlinked, true );
			}
		}

		// 所有添付（`MediaImporter` が取り込んだ商品画像・カテゴリ画像）は、それを使っていた商品・タームが
		// 無くなって孤児になったものだけを消す（mappings を失った店舗でクリーンアップを実行しても、
		// 残っている商品・タームの画像を道連れにしない）。候補は毎回全件を判定し直し、予算分だけ消す。
		$attachment_ids = $this->deletable_attachment_ids( $platform );

		foreach ( array_slice( $attachment_ids, 0, $remaining ) as $attachment_id ) {
			if ( ! wp_delete_attachment( $attachment_id, true ) instanceof WP_Post ) {
				// 削除できない添付を所有メタ付きのまま残すと次のバッチで再び拾い続けて終わらないため、
				// 所有メタだけ外して対象から除外する。
				delete_post_meta( $attachment_id, '_cbjp_platform' );
				++$unlinked['attachment'];
				continue;
			}

			++$deleted['attachment'];
		}

		if ( count( $attachment_ids ) > $remaining ) {
			return $this->result( $platform, $deleted, $unlinked, true );
		}

		// 最終処理: 上の順序で拾い切れなかった行（親を失った variant 等）を掃除し、サンプルセットを消す。
		$this->mappings->delete_for_platform( $platform );
		SampleSelector::clear( $platform );

		return $this->result( $platform, $deleted, $unlinked, false );
	}

	/**
	 * @param array<string,int> $deleted `variant` の削除数を加算するため参照渡し。
	 * @return 'deleted'|'unlinked'
	 */
	private function remove_entity( string $platform, string $entity, int $local_id, VariationWriter $variations, array &$deleted ): string {
		switch ( $entity ) {
			case 'order':
				$order = wc_get_order( $local_id );

				if ( $order instanceof WC_Order && $order->get_meta( '_cbjp_platform' ) === $platform ) {
					$order->delete( true );

					return 'deleted';
				}

				return 'unlinked';

			case 'product':
				$product = wc_get_product( $local_id );

				if ( $product instanceof WC_Product && ! $product instanceof WC_Product_Variation && PlatformOwnership::owns_post( $local_id, $platform ) ) {
					// `product` 投稿型は非階層のため親の削除で variation はカスケードしない
					// （`ProductWriter::write()` の失敗時掃除と同じ理由）。所有 variation を先に消す。
					$deleted['variant'] += count( $variations->remove_all( $variations->find_owned_variation_remote_ids( $local_id ) ) );
					$product->delete( true );

					return 'deleted';
				}

				return 'unlinked';

			case 'variant':
				$variation = wc_get_product( $local_id );

				if ( $variation instanceof WC_Product_Variation && PlatformOwnership::owns_post( $local_id, $platform ) ) {
					$variation->delete( true );

					return 'deleted';
				}

				return 'unlinked';

			case 'coupon':
				if ( 'shop_coupon' === get_post_type( $local_id ) && PlatformOwnership::owns_post( $local_id, $platform ) ) {
					( new WC_Coupon( $local_id ) )->delete( true );

					return 'deleted';
				}

				return 'unlinked';

			case 'customer':
				return $this->remove_customer( $local_id, $platform );

			case 'category':
				return $this->remove_term( $local_id, 'product_cat', $platform );

			case 'tag':
				return $this->remove_term( $local_id, 'product_tag', $platform );

			default:
				// stock / review: mapping 行のみ。
				return 'unlinked';
		}
	}

	/**
	 * @return 'deleted'|'unlinked'
	 */
	private function remove_customer( int $user_id, string $platform ): string {
		if ( ! get_userdata( $user_id ) instanceof WP_User || get_user_meta( $user_id, '_cbjp_platform', true ) !== $platform ) {
			return 'unlinked';
		}

		if ( $this->can_delete_user( $user_id, $platform ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';

			if ( wp_delete_user( $user_id ) ) {
				return 'deleted';
			}
		}

		// email突合で採用した既存アカウント（または削除できなかったアカウント）はリンク用メタだけ外して残す。
		foreach ( self::LINK_USER_META_KEYS as $meta_key ) {
			delete_user_meta( $user_id, $meta_key );
		}

		return 'unlinked';
	}

	/**
	 * 本プラグインが作成し（`_cbjp_created_by_import`）、店舗スタッフ権限を持たず、実行中の
	 * 管理者自身でもないアカウントを、**実行者がユーザー削除権限（`delete_user`）を持つ場合のみ**
	 * 削除できる（`wp_delete_user()` 自体は capability を見ない。ルートの `manage_woocommerce` だけでは
	 * shop_manager が WP 管理画面でできないアカウント削除をここ経由で行えてしまう）。
	 * 条件を満たさないアカウント（マーカー導入前に作成されたものを含む）は安全側に倒して unlink 扱いにする。
	 */
	private function can_delete_user( int $user_id, string $platform ): bool {
		return get_userdata( $user_id ) instanceof WP_User
			&& get_user_meta( $user_id, '_cbjp_platform', true ) === $platform
			&& '1' === get_user_meta( $user_id, CustomerWriter::CREATED_BY_IMPORT_META, true )
			&& ! CustomerWriter::has_protected_role( $user_id )
			&& get_current_user_id() !== $user_id
			&& current_user_can( 'delete_user', $user_id );
	}

	/**
	 * @return 'deleted'|'unlinked'
	 */
	private function remove_term( int $term_id, string $taxonomy, string $platform ): string {
		if ( get_term( $term_id, $taxonomy ) instanceof WP_Term && PlatformOwnership::owns_term( $term_id, $platform ) && true === wp_delete_term( $term_id, $taxonomy ) ) {
			return 'deleted';
		}

		return 'unlinked';
	}

	/**
	 * 削除してよい所有添付の一覧。所有の判定は `ProductWriter::set_images()` の所有権述語と同じ
	 * 「`_cbjp_source_url` あり かつ `_cbjp_platform` が自プラットフォーム」（＝`MediaImporter` が
	 * 取り込んだ画像）。そのうち次のいずれかに該当するものだけを返す:
	 * - 親投稿（`media_sideload_image()` の紐付け先＝商品）が無い、または `$doomed_product_ids` に含まれる
	 * - タームの `thumbnail_id` として参照されていない、または参照元が `$doomed_term_thumbnail_ids` に含まれる
	 *
	 * @param array<int,int> $doomed_product_ids        これから削除される（mapping 経由の）商品ID。
	 * @param array<int,int> $doomed_term_thumbnail_ids これから削除されるタームの thumbnail_id。
	 * @return array<int,int>
	 */
	private function deletable_attachment_ids( string $platform, array $doomed_product_ids = [], array $doomed_term_thumbnail_ids = [] ): array {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツールで、所有メタ以外に添付の出自を判定する手段が無い。
				'meta_query'     => [
					[
						'key'   => '_cbjp_platform',
						'value' => $platform,
					],
					[
						'key'     => '_cbjp_source_url',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		$ids = array_map( 'intval', $ids );

		if ( [] === $ids ) {
			return [];
		}

		// `fields => 'ids'` は投稿オブジェクトをキャッシュしないため、親IDの参照前に一括プリロードする
		// （添付ごとの個別 SELECT を避ける）。親投稿の実在確認も同様に一括で温める。
		_prime_post_caches( $ids, false, false );
		$parent_ids = [];

		foreach ( $ids as $attachment_id ) {
			$parent_ids[ $attachment_id ] = (int) get_post_field( 'post_parent', $attachment_id );
		}

		_prime_post_caches( array_values( array_filter( $parent_ids ) ), false, false );

		$referencing_thumbnails = $this->term_thumbnail_ids( $this->term_ids_with_thumbnail() );
		$doomed_products        = array_flip( $doomed_product_ids );
		$doomed_thumbnails      = array_flip( $doomed_term_thumbnail_ids );
		$deletable              = [];

		foreach ( $ids as $attachment_id ) {
			$parent_id = $parent_ids[ $attachment_id ];

			if ( $parent_id > 0 && ! isset( $doomed_products[ $parent_id ] ) && get_post( $parent_id ) instanceof WP_Post ) {
				continue;
			}

			if ( in_array( $attachment_id, $referencing_thumbnails, true ) && ! isset( $doomed_thumbnails[ $attachment_id ] ) ) {
				continue;
			}

			$deletable[] = $attachment_id;
		}

		return $deletable;
	}

	/**
	 * @return array<int,int> `thumbnail_id` を持つ商品カテゴリ／タグの term ID。
	 */
	private function term_ids_with_thumbnail(): array {
		$term_ids = get_terms(
			[
				'taxonomy'   => self::IMAGE_TAXONOMIES,
				'hide_empty' => false,
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 管理者が明示的に実行する一回限りのツール。タームの画像参照は termmeta にしか無い。
				'meta_query' => [
					[
						'key'     => 'thumbnail_id',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		return is_array( $term_ids ) ? array_map( 'intval', $term_ids ) : [];
	}

	/**
	 * @param array<int,int> $term_ids
	 * @return array<int,int> 各タームの `thumbnail_id`（未設定は除外）。
	 */
	private function term_thumbnail_ids( array $term_ids ): array {
		$term_ids = array_values( array_unique( array_map( 'intval', $term_ids ) ) );

		if ( [] === $term_ids ) {
			return [];
		}

		update_termmeta_cache( $term_ids );
		$thumbnail_ids = [];

		foreach ( $term_ids as $term_id ) {
			$thumbnail_id = (int) get_term_meta( $term_id, 'thumbnail_id', true );

			if ( $thumbnail_id > 0 ) {
				$thumbnail_ids[] = $thumbnail_id;
			}
		}

		return array_values( array_unique( $thumbnail_ids ) );
	}

	/**
	 * @param array<string,int> $deleted
	 * @param array<string,int> $unlinked
	 * @return array{deleted:array<string,int>,unlinked:array<string,int>,has_more:bool}
	 */
	private function result( string $platform, array $deleted, array $unlinked, bool $has_more ): array {
		$this->logger->info(
			'Sample cleanup batch finished.',
			[
				'platform' => $platform,
				'deleted'  => $deleted,
				'unlinked' => $unlinked,
				'has_more' => $has_more,
			]
		);

		return [
			'deleted'  => $deleted,
			'unlinked' => $unlinked,
			'has_more' => $has_more,
		];
	}
}
