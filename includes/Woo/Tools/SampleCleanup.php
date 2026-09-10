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
 * リンク用メタを外して残す。
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

		return [
			'counts'      => $counts,
			'attachments' => count( $this->owned_attachment_ids( $platform, -1 ) ),
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
					$outcome = $this->remove_entity( $platform, $entity, $local_id, $variations, $deleted );
					// `delete_one()` が行を消すため、次の `find_page()` は同じ行を返さない（ループは
					// `$remaining` の減算で必ず終わる）。
					$this->mappings->delete_one( $platform, $entity, (string) $remote_id );

					if ( 'deleted' === $outcome ) {
						++$deleted[ $entity ];
					} else {
						++$unlinked[ $entity ];
					}

					--$remaining;
				}
			}

			if ( $remaining <= 0 ) {
				return $this->result( $platform, $deleted, $unlinked, true );
			}
		}

		// 所有添付（`MediaImporter` が取り込んだ商品画像・カテゴリ画像）。商品・タームを消した後に消す。
		$attachment_ids = $this->owned_attachment_ids( $platform, $remaining );

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! wp_delete_attachment( $attachment_id, true ) instanceof WP_Post ) {
				// 削除できない添付を所有メタ付きのまま残すと次のバッチで再び拾い続けて終わらないため、
				// 所有メタだけ外して対象から除外する。
				delete_post_meta( $attachment_id, '_cbjp_platform' );
				++$unlinked['attachment'];
				continue;
			}

			++$deleted['attachment'];
		}

		if ( count( $attachment_ids ) >= $remaining ) {
			return $this->result( $platform, $deleted, $unlinked, true );
		}

		// 最終処理: 上の順序で拾い切れなかった行（親を失った variant 等）を掃除し、サンプルセットを消す。
		$this->mappings->delete_for_platform( $platform );
		delete_option( SampleSelector::option_name_for( $platform ) );

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
	 * 管理者自身でもないアカウントのみ削除できる。マーカー導入前に作成されたアカウントは
	 * 安全側に倒して unlink 扱いにする。
	 */
	private function can_delete_user( int $user_id, string $platform ): bool {
		return get_userdata( $user_id ) instanceof WP_User
			&& get_user_meta( $user_id, '_cbjp_platform', true ) === $platform
			&& '1' === get_user_meta( $user_id, CustomerWriter::CREATED_BY_IMPORT_META, true )
			&& ! CustomerWriter::has_protected_role( $user_id )
			&& get_current_user_id() !== $user_id;
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
	 * `_cbjp_platform` メタは `Woo\Support\MediaImporter`（と `ProductWriter::apply_images()`）が
	 * 取り込んだ添付にしか書かないため、これだけで「このプラットフォーム由来の画像」と判定できる。
	 *
	 * @param int $limit -1 で全件。
	 * @return array<int,int>
	 */
	private function owned_attachment_ids( string $platform, int $limit ): array {
		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => $limit,
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
				],
			]
		);

		return array_map( 'intval', $ids );
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
