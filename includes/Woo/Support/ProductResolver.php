<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Sync\MappingRepository;
use WC_Product;

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
	 */
	public function resolve_by_sku_or_remote_id( ?string $sku, ?string $remote_id ): ?WC_Product {
		if ( null !== $sku ) {
			$product_id = wc_get_product_id_by_sku( $sku );

			if ( 0 !== $product_id ) {
				$product = wc_get_product( $product_id );

				if ( $product instanceof WC_Product ) {
					return $product;
				}
			}
		}

		if ( null !== $remote_id ) {
			$local_id = $this->mappings->find_local_id( $this->platform, 'product', $remote_id );

			if ( null !== $local_id ) {
				$product = wc_get_product( $local_id );

				if ( $product instanceof WC_Product ) {
					return $product;
				}
			}
		}

		return null;
	}

	/**
	 * 在庫更新の対象解決: variant_refがあればmappings（'variant'）優先、無ければproduct_refの
	 * mappings、それも空振りならSKUで解決する。
	 */
	public function resolve_stock_target( ?string $variant_ref, string $product_ref, ?string $sku ): ?WC_Product {
		if ( null !== $variant_ref ) {
			$local_id = $this->mappings->find_local_id( $this->platform, 'variant', $variant_ref );

			return null !== $local_id ? $this->as_product( $local_id ) : null;
		}

		$local_id = $this->mappings->find_local_id( $this->platform, 'product', $product_ref );

		if ( null !== $local_id ) {
			$product = $this->as_product( $local_id );

			if ( null !== $product ) {
				return $product;
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
