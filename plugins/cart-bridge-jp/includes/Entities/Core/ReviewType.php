<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities\Core;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;

/**
 * 商品レビュー。取得できる ASP（MakeShop）のための枠で、v1.0 には Writer が無い（取り込むと `ENTITY_NOT_SUPPORTED` でスキップする）。
 * dry-run の CSV のラベルは個人情報を含みうるため空（既定）。
 */
final class ReviewType extends EntityType {

	public function key(): string {
		return 'review';
	}

	public function label(): string {
		return __( 'Reviews', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 80;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return $adapter->capabilities()->has_reviews;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return $adapter->fetch_reviews( $cursor );
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_comments( $local_ids );
	}
}
