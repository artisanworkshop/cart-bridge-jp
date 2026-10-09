<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Core;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalTag;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Tools\Link\TermLinkSource;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\TermWriter;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * 商品タグ（`product_tag`。ColorMe はグループ）。取込みだけ。
 */
final class TagType extends EntityType {

	public function key(): string {
		return 'tag';
	}

	public function label(): string {
		return __( 'Tags', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 20;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return $adapter->capabilities()->has_tags;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return new Page( $adapter->fetch_tags(), null, null );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new TermWriter( 'product_tag', $platform, $services->mappings(), $services->media() );
	}

	public function link_sources(): array {
		return [ new TermLinkSource( 'tag', 20, $this->label(), 'product_tag' ) ];
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_terms( 'product_tag', $local_ids );
	}

	public function dry_run_label( CanonicalModel $item ): string {
		return $item instanceof CanonicalTag ? $item->name : '';
	}
}
