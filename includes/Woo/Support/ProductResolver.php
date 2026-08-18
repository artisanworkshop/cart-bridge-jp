<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Sync\MappingRepository;
use WC_Product;
use WC_Product_Variable;

/**
 * SKU/remote_id からWooの商品・バリエーションを解決する（受注明細・在庫更新で共用）。
 */
final class ProductResolver {

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings
	) {}

	/**
	 * 受注明細の商品解決（03 §5 D10 #1）: SKU優先、空振りならremote_idのmappingsで解決する。
	 * variation SKU/IDも解決できる（`wc_get_product_id_by_sku()`/`wc_get_product()`はvariationも引ける）。
	 *
	 * ColorMeの受注明細は`remote_product_id`として常に親商品のIDしか持たない（どのvariationかは
	 * option1/2の値でしか特定できない）ため、mappingsの'product'側は必ず親を指す。SKU解決に
	 * 失敗した場合にこのremote_idフォールバックだけで解決すると、明細が実際にはvariationの
	 * 購入だったとしても親のvariable商品そのものが返ってしまう。variable商品は単体では
	 * 購入対象にならない（実購入は常にどれかのvariation）ため、そのまま明細に結びつけると
	 * 数量・在庫・価格が不整合な注文行になる。呼び出し元が気付けるよう、SKU・remote_idの
	 * どちらの経路でもvariable商品への解決は「未解決」として扱う。
	 */
	public function resolve_by_sku_or_remote_id( ?string $sku, ?string $remote_id ): ?WC_Product {
		if ( null !== $sku ) {
			$product_id = wc_get_product_id_by_sku( $sku );

			if ( 0 !== $product_id ) {
				$product = $this->as_orderable_product( $product_id );

				if ( null !== $product ) {
					return $product;
				}
			}
		}

		if ( null !== $remote_id ) {
			$local_id = $this->mappings->find_local_id( $this->platform, 'product', $remote_id );

			if ( null !== $local_id ) {
				$product = $this->as_orderable_product( $local_id );

				if ( null !== $product ) {
					return $product;
				}
			}
		}

		return null;
	}

	private function as_orderable_product( int $id ): ?WC_Product {
		$product = $this->as_product( $id );

		return null !== $product && ! $product instanceof WC_Product_Variable ? $product : null;
	}

	/**
	 * 在庫更新の対象解決: variant_refがあればmappings（'variant'）優先、無ければproduct_refの
	 * mappings、それも空振りならSKUで解決する。SKUフォールバックはvariant_ref・product_ref
	 * どちらの経路でも空振りだった場合に共通で試す（`wc_get_product_id_by_sku()`は
	 * variationも引けるため、variant側のmapping未整備・stale時にも取りこぼしを防げる）。
	 */
	public function resolve_stock_target( ?string $variant_ref, string $product_ref, ?string $sku ): ?WC_Product {
		if ( null !== $variant_ref ) {
			$local_id = $this->mappings->find_local_id( $this->platform, 'variant', $variant_ref );

			if ( null !== $local_id ) {
				$product = $this->as_product( $local_id );

				if ( null !== $product ) {
					return $product;
				}
			}
		} else {
			$local_id = $this->mappings->find_local_id( $this->platform, 'product', $product_ref );

			if ( null !== $local_id ) {
				$product = $this->as_product( $local_id );

				if ( null !== $product ) {
					return $product;
				}
			}
		}

		if ( null !== $sku ) {
			$product_id = wc_get_product_id_by_sku( $sku );

			if ( 0 !== $product_id ) {
				return $this->as_product( $product_id );
			}
		}

		return null;
	}

	private function as_product( int $id ): ?WC_Product {
		$product = wc_get_product( $id );

		return $product instanceof WC_Product ? $product : null;
	}
}
