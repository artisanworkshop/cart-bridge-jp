<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Fixtures;

use CartBridgeJP\Pro\Canonical\CanonicalCustomer;
use CartBridgeJP\Pro\Canonical\CanonicalOrder;

/**
 * テスト用の顧客・受注の Canonical モデル（R3-6c1 で無料版のテストの `CanonicalFactory` から分けた。商品・カテゴリはそちら）。
 * extras['remote_id'] 規約（Importer 参照）に従う。
 */
final class CommerceFactory {

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
}
