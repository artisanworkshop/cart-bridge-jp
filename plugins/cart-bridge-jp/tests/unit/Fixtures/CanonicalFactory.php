<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalProduct;

/**
 * テスト用のCanonicalモデル生成ヘルパー。extras['remote_id']規約（Importer参照）に従う。顧客・受注は Pro の `CommerceFactory`（R3-6c1）。
 */
final class CanonicalFactory {

	/**
	 * @param array<int,array<string,mixed>> $variants `remote_id`/`sku`/`stock`キー規約は
	 *   `Woo\Writer\VariationWriter` 参照。
	 */
	public static function product( string $remote_id, string $sku, int $stock = 5, array $variants = [] ): CanonicalProduct {
		return new CanonicalProduct(
			"Product {$remote_id}",
			$sku,
			'1000',
			null,
			null,
			[],
			$variants,
			[],
			[],
			$stock,
			'publish',
			[ 'remote_id' => $remote_id ]
		);
	}

	public static function category( string $id, string $name ): CanonicalCategory {
		return new CanonicalCategory( $id, $name, null, null );
	}
}
