<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;

/**
 * テスト用のCanonicalモデル生成ヘルパー。extras['remote_id']規約（Importer参照）に従う。
 */
final class CanonicalFactory {

	public static function product( string $remote_id, string $sku, int $stock = 5 ): CanonicalProduct {
		return new CanonicalProduct(
			"Product {$remote_id}",
			$sku,
			'1000',
			null,
			null,
			[],
			[],
			[],
			[],
			$stock,
			'publish',
			true,
			[ 'remote_id' => $remote_id ]
		);
	}

	public static function customer( string $remote_id, string $email ): CanonicalCustomer {
		return new CanonicalCustomer(
			$email,
			"Customer {$remote_id}",
			null,
			null,
			null,
			[],
			null,
			null,
			null,
			null,
			[ 'remote_id' => $remote_id ]
		);
	}

	/**
	 * @param array<int,string> $product_remote_ids
	 */
	public static function order( string $number, ?string $customer_ref, array $product_remote_ids ): CanonicalOrder {
		$line_items = array_map(
			static fn( string $id ): array => [
				'remote_product_id' => $id,
				'quantity'          => 1,
			],
			$product_remote_ids
		);

		return new CanonicalOrder(
			$number,
			'processing',
			$customer_ref,
			$line_items,
			[],
			[],
			[ 'total' => '1000' ],
			'2026-07-01 00:00:00',
			null
		);
	}

	public static function category( string $id, string $name ): CanonicalCategory {
		return new CanonicalCategory( $id, $name, null, null );
	}
}
