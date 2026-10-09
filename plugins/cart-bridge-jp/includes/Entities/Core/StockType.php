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
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Reader\StockReader;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\StockWriter;
use RuntimeException;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * 在庫（商品・バリエーションの在庫数）。取込み・エクスポートの両方。local_id は在庫を書いた商品かバリエーション。
 * D25 の判定は書き込む対象を解決する `Woo\Writer\StockWriter` が持つ（在庫の mapping が無くても届くため。この種類では判定しない）。
 * 送信は既存の実体の更新だけで冪等なので push intent を残さない。
 */
final class StockType extends EntityType {

	public function key(): string {
		return 'stock';
	}

	public function label(): string {
		return __( 'Stock', 'cart-bridge-jp' );
	}

	public function position(): int {
		return 60;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function fetch_page( PlatformAdapter $adapter, Cursor $cursor ): Page {
		return $adapter->fetch_stocks( $cursor );
	}

	public function writer( string $platform, WooServices $services ): EntityWriter {
		return new StockWriter( $platform, $services->product_resolver() );
	}

	public function supports_export( PlatformAdapter $adapter ): bool {
		return true;
	}

	public function reader( string $platform, WooServices $services ): EntityReader {
		return new StockReader( $platform, $services->mappings() );
	}

	public function push( PlatformAdapter $adapter, CanonicalModel $item, ?string $remote_id ): PushResult {
		if ( ! $item instanceof CanonicalStock ) {
			throw new RuntimeException( 'AdapterPlatformWriter received an unsupported Canonical model for "stock".' );
		}

		return $adapter->push_stock( $item );
	}

	public function existing_local_ids( array $local_ids ): array {
		return ( new LocalEntityLookup() )->existing_posts( [ 'product', 'product_variation' ], $local_ids );
	}

	public function dry_run_label( CanonicalModel $item ): string {
		return $item instanceof CanonicalStock ? ( $item->sku ?? '' ) : '';
	}
}
