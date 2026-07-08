<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Canonical\CanonicalTag;
use RuntimeException;

/**
 * fetch → 書込 → mappings upsert のパイプライン。
 *
 * remote_id の取り方は本クラスの規約: category/tagは`id`、orderは`number`、
 * stockは`variant_ref ?? product_ref`、それ以外（product/customer/coupon/review）は
 * `extras['remote_id']`（アダプタ実装がここに格納する。D5のextras退避規約に準拠）。
 *
 * 無料版サンプル選定（D15）: product/customer はID指定取得（`run_sample_page`）、
 * それ以外はカーソル走査（`run_page`）で、stock/reviewはサンプル商品への紐付けで絞り込む。
 */
final class Importer {

	public function __construct( private readonly MappingRepository $mappings ) {}

	/**
	 * カーソル走査エンティティ（category/tag/order/coupon/stock/review）を1ページ処理する。
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

		$totals = $this->process_items(
			$adapter,
			$writer,
			$entity,
			$items,
			$is_dry_run,
			$limit_policy,
			$this->needs_sample_membership_filter( $entity ) ? $sample : null
		);

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
				'product'  => $adapter->fetch_product_by_remote_id( $remote_id ),
				'customer' => $adapter->fetch_customer_by_remote_id( $remote_id ),
				default    => throw new RuntimeException( "Entity \"{$entity}\" does not support sample ID fetch." ),
			};

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return [ 'totals' => $this->process_items( $adapter, $writer, $entity, $items, $is_dry_run, null, null ) ];
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

		foreach ( $items as $item ) {
			++$totals['processed'];

			if ( null !== $sample && ! in_array( $this->sample_match_key( $entity, $item ), $sample->product_remote_ids, true ) ) {
				++$totals['skipped'];
				continue;
			}

			if ( ! $is_dry_run && null !== $limit_policy && $limit_policy->is_exceeded( $platform, $entity ) ) {
				++$totals['skipped'];
				continue;
			}

			$remote_id         = $this->remote_id_of( $entity, $item );
			$existing_local_id = $is_dry_run ? null : $this->mappings->find_local_id( $platform, $entity, $remote_id );

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

	private function needs_sample_membership_filter( string $entity ): bool {
		return in_array( $entity, [ 'stock', 'review' ], true );
	}

	private function sample_match_key( string $entity, CanonicalModel $item ): string {
		if ( $item instanceof CanonicalStock ) {
			return $item->product_ref;
		}

		return (string) ( $item->extras['product_ref'] ?? '' );
	}

	private function remote_id_of( string $entity, CanonicalModel $item ): string {
		if ( $item instanceof CanonicalCategory || $item instanceof CanonicalTag ) {
			return $item->id;
		}

		if ( $item instanceof CanonicalOrder ) {
			return $item->number;
		}

		if ( $item instanceof CanonicalStock ) {
			return $item->variant_ref ?? $item->product_ref;
		}

		$remote_id = $item->extras['remote_id'] ?? null;

		if ( null === $remote_id || '' === $remote_id ) {
			throw new RuntimeException( "Missing extras['remote_id'] for entity \"{$entity}\"." );
		}

		return (string) $remote_id;
	}
}
