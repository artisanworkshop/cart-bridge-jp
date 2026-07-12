<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalReview;
use CartBridgeJP\Canonical\CanonicalStock;
use RuntimeException;

/**
 * fetch → 書込 → mappings upsert のパイプライン。
 *
 * リモートIDは `CanonicalModel::remote_id()` から取得する（Product/Customer/Coupon/Review は
 * アダプタが `extras['remote_id']` に格納する契約。null の場合はアダプタ実装バグとして例外）。
 *
 * 無料版サンプル選定（D15）: product/customer はID指定取得（`run_sample_page`）、
 * stock はサンプル商品のID指定取得結果から導出（`run_sample_stock_page`、§10.2 #4）、
 * review はカーソル走査＋サンプル商品メンバーシップで絞り込む（`run_page` の $sample）。
 */
final class Importer {

	public function __construct( private readonly MappingRepository $mappings ) {}

	/**
	 * カーソル走査エンティティを1ページ処理する。$sample が渡された場合は
	 * サンプル商品に紐づくアイテムのみを取り込む（呼び出し側=JobManagerが対象エンティティを判断する）。
	 *
	 * @return array{next_cursor:?Cursor,total:?int,totals:array<string,int>}
	 */
	public function run_page(
		PlatformAdapter $adapter,
		WooWriter $writer,
		string $entity,
		Cursor $cursor,
		bool $is_dry_run,
		?LimitPolicy $limit_policy = null,
		?SampleSet $sample = null
	): array {
		[ $items, $next_cursor, $total ] = $this->fetch_page( $adapter, $entity, $cursor );

		$totals = $this->process_items( $adapter, $writer, $entity, $items, $is_dry_run, $limit_policy, $sample );

		return [
			'next_cursor' => $next_cursor,
			'total'       => $total,
			'totals'      => $totals,
		];
	}

	/**
	 * サンプルID指定取得エンティティ（product/customer）をまとめて処理する（D15 #4）。
	 * ページングは不要（サンプル件数は上限で有界）。
	 *
	 * @param array<int,string> $remote_ids
	 * @return array{totals:array<string,int>}
	 */
	public function run_sample_page( PlatformAdapter $adapter, WooWriter $writer, string $entity, array $remote_ids, bool $is_dry_run ): array {
		$items = [];

		foreach ( $remote_ids as $remote_id ) {
			$item = match ( $entity ) {
				'product'  => $adapter->fetch_product_by_remote_id( (string) $remote_id ),
				'customer' => $adapter->fetch_customer_by_remote_id( (string) $remote_id ),
				default    => throw new RuntimeException( "Entity \"{$entity}\" does not support sample ID fetch." ),
			};

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return [ 'totals' => $this->process_items( $adapter, $writer, $entity, $items, $is_dry_run, null, null ) ];
	}

	/**
	 * 無料版の在庫取込（§10.2 #4）: `fetchStocks` の全量走査はレート制限を浪費するため使わず、
	 * サンプル商品のID指定取得結果（CanonicalProduct.stock）から在庫を導出して書き込む。
	 *
	 * @param array<int,string> $product_remote_ids
	 * @return array{totals:array<string,int>}
	 */
	public function run_sample_stock_page( PlatformAdapter $adapter, WooWriter $writer, array $product_remote_ids, bool $is_dry_run ): array {
		$items = [];

		foreach ( $product_remote_ids as $remote_id ) {
			$product = $adapter->fetch_product_by_remote_id( (string) $remote_id );

			if ( null === $product ) {
				continue;
			}

			$items[] = new CanonicalStock(
				(string) $remote_id,
				null,
				$product->sku,
				$product->stock,
				null === $product->stock || $product->stock > 0
			);
		}

		return [ 'totals' => $this->process_items( $adapter, $writer, 'stock', $items, $is_dry_run, null, null ) ];
	}

	/**
	 * @param array<int,CanonicalModel> $items
	 * @return array<string,int>
	 */
	private function process_items(
		PlatformAdapter $adapter,
		WooWriter $writer,
		string $entity,
		array $items,
		bool $is_dry_run,
		?LimitPolicy $limit_policy,
		?SampleSet $sample
	): array {
		$totals = [
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'warned'    => 0,
		];

		$platform = $adapter->id();

		// ページ内アイテムの既存mapping（local_id/checksum）を一括プリロードし、
		// アイテム毎のSELECTを避ける。dry-runでも読み取り専用で使い、新規/更新/スキップを分類する（D16）。
		$remote_ids = array_map(
			fn( CanonicalModel $item ): string => $this->remote_id_of( $entity, $item ),
			$items
		);
		$existing   = $this->mappings->find_many( $platform, $entity, $remote_ids );

		// 残枠はページ開始時に一度だけ解決する（アイテム毎のCOUNT(*)を避ける）。
		// 上限は新規作成のみを対象とし、既存mappingの更新は阻まない（D16の上書きポリシー前提）。
		$remaining = ( null !== $limit_policy ) ? $limit_policy->remaining( $platform, $entity ) : null;

		foreach ( $items as $index => $item ) {
			++$totals['processed'];

			if ( null !== $sample && ! in_array( $this->sample_match_key( $item ), $sample->product_remote_ids, true ) ) {
				++$totals['skipped'];
				continue;
			}

			$remote_id         = $remote_ids[ $index ];
			$row               = $existing[ $remote_id ] ?? null;
			$existing_local_id = $row['local_id'] ?? null;

			// checksum一致＝変更なしはスキップする（03 §5 冪等性）。
			if ( null !== $row && null !== $row['checksum'] && $row['checksum'] === $item->checksum() ) {
				++$totals['skipped'];
				continue;
			}

			if ( ! $is_dry_run && null === $existing_local_id && null !== $remaining ) {
				if ( $remaining <= 0 ) {
					++$totals['skipped'];
					continue;
				}

				--$remaining;
			}

			$result = $writer->write( $entity, $item, $existing_local_id );

			if ( ! $is_dry_run ) {
				$this->mappings->upsert( $platform, $entity, $remote_id, $result->local_id, $item->checksum() );
			}

			++$totals[ $result->operation ];

			if ( [] !== $result->warnings ) {
				++$totals['warned'];
			}
		}

		return $totals;
	}

	/**
	 * @return array{0:array<int,CanonicalModel>,1:?Cursor,2:?int}
	 */
	private function fetch_page( PlatformAdapter $adapter, string $entity, Cursor $cursor ): array {
		return match ( $entity ) {
			'category' => [ $adapter->fetch_categories(), null, null ],
			'tag'      => [ $adapter->fetch_tags(), null, null ],
			// product/customer は無料版ではサンプルID指定取得（run_sample_page）を使うが、
			// Pro版（上限解除時）は全量カーソル走査でここを通る。
			'product'  => $this->unwrap_page( $adapter->fetch_products( $cursor ) ),
			'customer' => $this->unwrap_page( $adapter->fetch_customers( $cursor ) ),
			'order'    => $this->unwrap_page( $adapter->fetch_orders( $cursor ) ),
			'stock'    => $this->unwrap_page( $adapter->fetch_stocks( $cursor ) ),
			'coupon'   => $this->unwrap_page( $adapter->fetch_coupons( $cursor ) ),
			'review'   => $this->unwrap_page( $adapter->fetch_reviews( $cursor ) ),
			default    => throw new RuntimeException( "Entity \"{$entity}\" is not a cursor-walk entity." ),
		};
	}

	/**
	 * @return array{0:array<int,CanonicalModel>,1:?Cursor,2:?int}
	 */
	private function unwrap_page( Page $page ): array {
		return [ $page->items, $page->next_cursor, $page->total ];
	}

	/**
	 * サンプル商品メンバーシップの照合キー（stock/review用）。
	 */
	private function sample_match_key( CanonicalModel $item ): string {
		if ( $item instanceof CanonicalStock || $item instanceof CanonicalReview ) {
			return $item->product_ref;
		}

		return '';
	}

	private function remote_id_of( string $entity, CanonicalModel $item ): string {
		$remote_id = $item->remote_id();

		if ( null === $remote_id ) {
			throw new RuntimeException( "Adapter returned a \"{$entity}\" item without a remote id (extras['remote_id'] is required)." );
		}

		return $remote_id;
	}
}
