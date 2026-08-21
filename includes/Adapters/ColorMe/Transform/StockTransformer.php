<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalStock;

/**
 * `GET /v1/products.json` `GET /v1/products/{id}.json` の1要素を `CanonicalStock` の配列へ変換する。
 * 無料版のサンプル在庫取込・`fetch_stocks()`（全量走査）双方の共通データ源。
 *
 * カラーミーの `GET /v1/stocks.json` はバリエーションIDを返さない（`option1_value`/
 * `variant_model_number` のみ）ため、同一商品の複数バリエーションで `CanonicalStock::remote_id()`
 * （`variant_ref ?? product_ref`）が衝突してしまう。`products.json` の `variants[].id` を使うことで
 * `VariationWriter` が書き込む `'variant'` マッピングと正確に突合できるため、こちらを在庫の取得元にする
 * （`docs/01-plan-colorme.md` §2）。
 *
 * SKU・在庫管理判定のルールは `ProductTransformer` と共有し、在庫が別の商品・バリエーションに
 * 誤って当たらないようにする。
 */
final class StockTransformer {

	/**
	 * @param array<string,mixed> $raw `products[]` の1要素、または `product` 単体。
	 * @return array<int,CanonicalStock>
	 */
	public function transform( array $raw ): array {
		$remote_id = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';
		$variants  = $raw['variants'] ?? [];

		if ( ! is_array( $variants ) || [] === $variants ) {
			return [ $this->stock_for_product( $raw, $remote_id ) ];
		}

		$result = [];

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$result[] = $this->stock_for_variant( $raw, $remote_id, $variant );
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function stock_for_product( array $raw, string $remote_id ): CanonicalStock {
		$quantity = ProductTransformer::stock( $raw );

		return new CanonicalStock(
			$remote_id,
			null,
			ProductTransformer::sku( $raw, $remote_id ),
			$quantity,
			null === $quantity || $quantity > 0
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<string,mixed> $variant
	 */
	private function stock_for_variant( array $raw, string $remote_id, array $variant ): CanonicalStock {
		$variant_remote_id = Cast::to_string_or_null( $variant['id'] ?? null ) ?? '';
		$quantity          = ProductTransformer::variant_stock( $variant, ProductTransformer::is_stock_managed( $raw ) );

		return new CanonicalStock(
			$remote_id,
			$variant_remote_id,
			ProductTransformer::variant_sku( $variant, $remote_id ),
			$quantity,
			null === $quantity || $quantity > 0
		);
	}
}
