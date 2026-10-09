<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Core;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Tools\Link\TermLinkSource;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\TermWriter;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * 商品カテゴリ（`product_cat`）。取込みだけ（エクスポートは商品の `category_map` で紐づける。ColorMe はカテゴリを作れない）。
 */
final class CategoryType extends EntityType {

	public function key(): string {
		return 'category';
	}

	public function label(): string {
		return __( 'Categories', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 10;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return new Page( $adapter->fetch_categories(), null, null );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new TermWriter( 'product_cat', $platform, $services->mappings(), $services->media() );
	}

	public function link_sources(): array {
		return [ new TermLinkSource( 'category', 10, $this->label(), 'product_cat' ) ];
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_terms( 'product_cat', $local_ids );
	}

	public function dry_run_label( CanonicalModel $item ): string {
		return $item instanceof CanonicalCategory ? $item->name : '';
	}
}
