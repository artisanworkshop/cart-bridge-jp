<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WooWriterFactory;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\Support\SideEffectGuard;
use CartBridgeJP\Woo\Writer\CouponWriter;
use CartBridgeJP\Woo\Writer\CustomerWriter;
use CartBridgeJP\Woo\Writer\OrderItemBuilder;
use CartBridgeJP\Woo\Writer\OrderWriter;
use CartBridgeJP\Woo\Writer\ProductWriter;
use CartBridgeJP\Woo\Writer\StockWriter;
use CartBridgeJP\Woo\Writer\TermWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;

/**
 * platformごとに `WooRepository`（実移行のwriter）を組み立てる既定のファクトリ。
 */
final class WooRepositoryFactory implements WooWriterFactory {

	public function for_platform( string $platform ): WooWriter {
		$mappings   = new MappingRepository();
		$media      = new MediaImporter( $platform );
		$resolver   = new ProductResolver( $platform, $mappings );
		$variations = new VariationWriter( $platform, $mappings );
		$methods    = new MethodMap( $platform );

		$writers = [
			'category' => new TermWriter( 'product_cat', $platform, $mappings, $media ),
			'tag'      => new TermWriter( 'product_tag', $platform, $mappings, $media ),
			'product'  => new ProductWriter( $platform, $mappings, $variations, $media ),
			'customer' => new CustomerWriter( $platform ),
			'order'    => new OrderWriter( $platform, $mappings, new OrderItemBuilder( $resolver ), $methods ),
			'stock'    => new StockWriter( $resolver ),
			'coupon'   => new CouponWriter( $platform ),
		];

		return new WooRepository( new SideEffectGuard(), $writers );
	}
}
