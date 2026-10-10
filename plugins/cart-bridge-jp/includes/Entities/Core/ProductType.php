<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Core;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Support\EntityOrigin;
use CartBridgeJP\Woo\Support\HtmlText;
use CartBridgeJP\Woo\Tools\Link\PostLinkSource;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\ProductWriter;
use RuntimeException;
use WC_Product;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * 商品（バリエーションを含む）。取込み・エクスポートの両方。バリエーションの mapping（`variant`）はこの種類が書く。
 */
final class ProductType extends EntityType {

	public function key(): string {
		return 'product';
	}

	public function label(): string {
		return __( 'Products', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 30;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return $adapter->fetch_products( $cursor );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new ProductWriter( $platform, $services->mappings(), $services->variations(), $services->media() );
	}

	public function supports_export( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function reader( string $platform, WooServices $services ): EntityReader {
		return new ProductReader( $platform, $services->method_map(), $services->mappings() );
	}

	public function push( PlatformAdapter $adapter, CanonicalModel $item, ?string $remote_id ): PushResult {
		if ( ! $item instanceof CanonicalProduct ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "product".' );
		}

		return $adapter->push_product( $item, $remote_id );
	}

	public function records_push_intent(): bool {
		return true;
	}

	public function fetch_by_remote_id( PlatformAdapter $adapter, string $remote_id ): ?CanonicalModel {
		return $adapter->fetch_product_by_remote_id( $remote_id );
	}

	public function describe_local( int $local_id ): array {
		$product = wc_get_product( $local_id );

		if ( ! $product instanceof WC_Product ) {
			return parent::describe_local( $local_id );
		}

		// Woo の名前は HTML。画面は文字として出すので平文へ戻す（issue #99）。
		$name = HtmlText::to_plain( $product->get_name() );
		$sku  = $product->get_sku();

		return [
			'exists'   => true,
			'edit_url' => get_edit_post_link( $local_id, 'raw' ),
			'summary'  => '' !== $sku
				/* translators: 1: product name, 2: SKU */
				? sprintf( __( '%1$s (SKU: %2$s)', 'cart-bridge-jp' ), $name, $sku )
				: $name,
			'details'  => [
				'name' => $name,
				'sku'  => '' !== $sku ? $sku : null,
			],
		];
	}

	public function is_linked_by_export( string $platform, int $local_id ): bool {
		return EntityOrigin::is_product_post( $local_id ) && ! EntityOrigin::post_linked_by_import( $local_id, $platform );
	}

	public function link_sources(): array {
		return [
			new PostLinkSource( 'product', 30, $this->label(), 'product' ),
			new PostLinkSource( 'variant', 40, __( 'Variations', 'cart-bridge-jp' ), 'product_variation' ),
		];
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_posts( [ 'product' ], $local_ids );
	}

	public function dry_run_label( CanonicalModel $item ): string {
		return $item instanceof CanonicalProduct ? $item->name : '';
	}

	public function mapping_kinds(): array {
		return [ new CategoryMappingKind() ];
	}
}
