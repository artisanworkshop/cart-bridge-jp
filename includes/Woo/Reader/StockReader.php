<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\StockDerivation;
use CartBridgeJP\Woo\WarningCode;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Woo商品/バリエーションを`CanonicalStock`へ変換する（`Woo\Writer\StockWriter`の読出側対称形）。
 * `Woo\Reader\ProductReader`と同じ商品ステータス/タイプ条件で商品を読み、variable商品は
 * `get_children()`＋`publish`ステータスの明示チェック（`ProductReader::variants()`と同じ方針。
 * `get_visible_children()`は在庫切れバリエーションも除外しうるため使わない。実装ノート参照）で
 * 公開バリエーションのみを1件ずつ展開する。1商品が0〜N件の`CanonicalStock`に展開されるため
 * `ReadPage::$total`は常にnull（CLAUDE.md: Transformerが行を展開・除外しうるエンティティは
 * totalをnullにする規約。`Sync\Importer::stocks_for_sample_product()`と同じ理由）。
 * ページ内の全商品をスキャンしてから`MappingRepository::find_many_by_local_ids()`で一括解決する
 * （アイテム毎のSELECTを避けるため。`ProductReader::variants()`と同じ理由）。
 */
final class StockReader implements EntityReader {

	private const PAGE_SIZE = 20;

	/**
	 * @var array<int,array{remote_id:string,checksum:?string}>
	 */
	private array $product_refs = [];

	/**
	 * @var array<int,array{remote_id:string,checksum:?string}>
	 */
	private array $variant_refs = [];

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings
	) {}

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$args = [
			'status'   => ProductReader::EXPORTABLE_STATUSES,
			'type'     => ProductReader::EXPORTABLE_TYPES,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'objects',
			'paginate' => true,
			'limit'    => self::PAGE_SIZE,
			'page'     => (int) $cursor->get( 'page', 1 ),
		];

		if ( null !== $only_local_ids ) {
			if ( [] === $only_local_ids ) {
				return new ReadPage( [], null, 0 );
			}

			$args['include'] = $only_local_ids;
			unset( $args['page'] );
			$args['limit'] = count( $only_local_ids );
		}

		/** @var object{products:array<int,WC_Product>,total:int,max_num_pages:int} $result */
		$result = wc_get_products( $args );

		$this->preload_mappings( $result->products );

		$items = [];

		foreach ( $result->products as $product ) {
			array_push( $items, ...$this->items_for_product( $product ) );
		}

		$page          = (int) ( $args['page'] ?? 1 );
		$has_next_page = null === $only_local_ids && $page < $result->max_num_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, null );
	}

	/**
	 * ページ内の全商品をスキャンし、商品/バリエーションのremote_idを1回のクエリずつで
	 * 一括解決する（`ProductReader::variants()`の`find_many_by_local_ids()`一括先読みと同じ理由）。
	 *
	 * @param array<int,WC_Product> $products
	 */
	private function preload_mappings( array $products ): void {
		$product_ids   = [];
		$variation_ids = [];

		foreach ( $products as $product ) {
			$product_ids[] = $product->get_id();

			if ( $product instanceof WC_Product_Variable ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation_ids[] = (int) $variation_id;
				}
			}
		}

		$this->product_refs = $this->mappings->find_many_by_local_ids( $this->platform, 'product', $product_ids );
		$this->variant_refs = $this->mappings->find_many_by_local_ids( $this->platform, 'variant', $variation_ids );
	}

	/**
	 * @return array<int,ReadItem>
	 */
	private function items_for_product( WC_Product $product ): array {
		if ( $product instanceof WC_Product_Variable ) {
			return $this->items_for_variable( $product );
		}

		$product_ref = $this->product_refs[ $product->get_id() ]['remote_id'] ?? null;

		if ( null === $product_ref ) {
			return [ $this->unresolved_item( $product->get_id(), (string) $product->get_id() ) ];
		}

		$quantity = StockDerivation::for_product( $product );

		$stock = new CanonicalStock(
			$product_ref,
			null,
			'' !== $product->get_sku() ? $product->get_sku() : null,
			$quantity,
			CanonicalStock::is_in_stock( $quantity )
		);

		return [ new ReadItem( $product->get_id(), $stock ) ];
	}

	/**
	 * @return array<int,ReadItem>
	 */
	private function items_for_variable( WC_Product_Variable $product ): array {
		$product_ref = $this->product_refs[ $product->get_id() ]['remote_id'] ?? null;
		$items       = [];

		// `get_children()`（**`get_visible_children()`ではない**）を使う: 後者は
		// `woocommerce_hide_out_of_stock_items`設定次第で在庫切れバリエーションも除外するため、
		// 在庫がゼロになった瞬間にそのバリエーションが行ごと消え、ASP側へ「在庫切れ」を伝える
		// 手段が無くなってしまう（`ProductReader::variants()`が`CanonicalProduct::$variants`の
		// 構築で行っている`publish`ステータスのみの明示チェックと同じ方針に揃える）。
		foreach ( $product->get_children() as $variation_id ) {
			// `instanceof`だけでは削除済みバリエーションを弾けない: `wc_get_product()`は削除済み
			// variation IDに対して`false`ではなく中身の無い`WC_Product_Variation`を返しうる
			// （投稿欠損で例外を投げず商品種別キャッシュも残るため。CLAUDE.md参照）。`get_post()`で
			// 実在確認してから読み込む。
			if ( null === get_post( $variation_id ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			// Codex/Copilot指摘（PR #40）由来の方針と同じ: 非公開バリエーションを含めると
			// マーチャントが意図的に隠した在庫がASP側で「販売可能」として復活しうる。この行自体を
			// 生成しない（`CanonicalStock::remote_id()`はvariant_refが無いとproduct_refへ
			// フォールバックするため、警告付きでも行を出すと親商品の在庫を誤って更新しかねない）。
			// 'product'エンティティ側の`ProductReader::variants()`が同じ商品に対して
			// `VARIATION_UNPUBLISHED`警告を既に出すため、情報としては失われない。
			if ( 'publish' !== $variation->get_status() ) {
				continue;
			}

			if ( null === $product_ref ) {
				$items[] = $this->unresolved_item( $variation_id, (string) $variation_id );
				continue;
			}

			$variant_ref = $this->variant_refs[ $variation_id ]['remote_id'] ?? null;

			if ( null === $variant_ref ) {
				$items[] = $this->unresolved_item( $variation_id, (string) $variation_id );
				continue;
			}

			$warnings = [];
			$derived  = StockDerivation::for_variation( $variation );

			if ( $derived['shared_with_parent'] ) {
				$warnings[] = WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT;
			}

			$stock = new CanonicalStock(
				$product_ref,
				$variant_ref,
				'' !== $variation->get_sku() ? $variation->get_sku() : null,
				$derived['quantity'],
				CanonicalStock::is_in_stock( $derived['quantity'] )
			);

			$items[] = new ReadItem( $variation_id, $stock, $warnings );
		}

		return $items;
	}

	private function unresolved_item( int $local_id, string $detail ): ReadItem {
		$stock = new CanonicalStock( '', null, null, null, true );

		return new ReadItem(
			$local_id,
			$stock,
			[ WarningCode::with_detail( WarningCode::STOCK_PRODUCT_NOT_EXPORTED, $detail ) ],
			false
		);
	}
}
