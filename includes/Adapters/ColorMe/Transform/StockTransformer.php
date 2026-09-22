<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalStock;
use RuntimeException;

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
	 * `id`欠損の行は`''`にフォールバックせずスキップする。空文字を通すと
	 * `CanonicalStock::remote_id()`（`variant_ref ?? product_ref`）が空のproduct_refを
	 * そのまま使ってしまい（バリエーション欠損時と異なり`??`のフォールバック元を持たない）、
	 * 無関係なWoo商品に在庫が誤って書き込まれたり、複数の不正行が同一の空remote_idに衝突する
	 * （`variant_remote_id()`と同じ理由。CLAUDE.md フェイルクローズ原則）。
	 *
	 * @param array<string,mixed> $raw `products[]` の1要素、または `product` 単体。
	 * @return array<int,CanonicalStock>
	 */
	public function transform( array $raw ): array {
		$remote_id = Cast::to_string_or_null( $raw['id'] ?? null );

		if ( null === $remote_id ) {
			return [];
		}

		$variants = $raw['variants'] ?? null;

		// `variants`はColorMe APIが常に配列で返すフィールド（バリエーション無しの商品も`[]`）。
		// 欠損・非配列は正当な「バリエーション無し」ではなくスキーマ崩壊等の不完全なレスポンスであり、
		// `ProductTransformer::variants()`と同じ理由で商品レベルの1件に丸めず例外で行全体を弾く
		// （呼び出し側=`ColorMeAdapter::transform_rows_flat()`のper-row catchがこの行だけを
		// ログ＋スキップする。ここで丸めると、実際はバリエーションを持つ商品の在庫が商品レベル
		// 1件として書き込まれ、`StockWriter`が変数商品と判定して黙ってスキップし在庫が更新されない）。
		if ( ! is_array( $variants ) ) {
			throw new RuntimeException( 'ColorMe product row is missing a valid "variants" array.' );
		}

		if ( [] === $variants ) {
			return [ $this->stock_for_product( $raw, $remote_id ) ];
		}

		$result        = [];
		$stock_managed = ProductTransformer::is_stock_managed( $raw );

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$stock = $this->stock_for_variant( $remote_id, $variant, $stock_managed );

			if ( null !== $stock ) {
				$result[] = $stock;
			}
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
			CanonicalStock::is_in_stock( $quantity )
		);
	}

	/**
	 * `variant.id`が欠損しているバリエーションはnullを返し、呼び出し側でスキップさせる。
	 * 空文字をremote_idとして通すと `CanonicalStock::remote_id()` の `variant_ref ?? product_ref`
	 * フォールバックが効かず（`??`はnullのみ未設定とみなす）、id欠損の複数バリエーションが
	 * 同一の空remote_idに衝突してしまう。
	 *
	 * @param array<string,mixed> $variant
	 */
	private function stock_for_variant( string $remote_id, array $variant, bool $stock_managed ): ?CanonicalStock {
		$variant_remote_id = ProductTransformer::variant_remote_id( $variant );

		if ( null === $variant_remote_id ) {
			return null;
		}

		$quantity = ProductTransformer::variant_stock( $variant, $stock_managed );

		return new CanonicalStock(
			$remote_id,
			$variant_remote_id,
			ProductTransformer::variant_sku( $variant, $remote_id ),
			$quantity,
			CanonicalStock::is_in_stock( $quantity )
		);
	}

	/**
	 * `PUT /v1/products/{id}`（単純商品の在庫更新）向けのペイロード。`quantity`は
	 * `CanonicalStock`の規約どおり`null`=在庫管理外、int=管理中の絶対数量（`increment`は使わない。
	 * `push_order()`が`reserve_stocks=false`で二重引き当てを避けるのと同じ理由で、絶対値でなければ
	 * 再送のたびに二重加算されchecksum一致スキップの冪等性と相容れない）。`Adapters\ColorMe\
	 * Transform\ProductTransformer::base_payload()`が確立済みの規約（`stock_managed`は常時送信、
	 * `stocks`は管理中のときのみ送信）に揃える。
	 *
	 * @return array<string,mixed>
	 */
	public static function to_product_payload( CanonicalStock $stock ): array {
		$payload = [ 'stock_managed' => null !== $stock->quantity ];

		if ( null !== $stock->quantity ) {
			$payload['stocks'] = $stock->quantity;
		}

		return $payload;
	}

	/**
	 * `PUT /v1/products/{id}/variants/{id}`（バリエーションの在庫更新）向けのペイロード。
	 * `productVariantUpdateRequest`（swagger）には商品レベルの`stock_managed`に相当する
	 * フィールドが存在しないため、`quantity=null`（Wooがそのバリエーションを個別管理していない）は
	 * ColorMe側へ明示的に伝える手段が無い。`stocks`はnullable整数だが、他のnullableフィールド
	 * （`weight`/`option_price`等）と異なり「nullで未設定に戻る」という記載が無く、逆に
	 * 「全バリエーション未設定の状態で1件でも値を送ると他バリエーションの在庫が0になる」という
	 * 副作用のみ記載されている。未確認の挙動に賭けて`stocks: null`を送るより、pushせずフェイル
	 * クローズする方が安全（CLAUDE.mdアーキテクチャ原則9）。
	 *
	 * @return ?array<string,mixed> null=push不可（呼び出し元が`WarningCode::
	 *   STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE`でフェイルクローズする）。
	 */
	public static function to_variant_payload( CanonicalStock $stock ): ?array {
		if ( null === $stock->quantity ) {
			return null;
		}

		return [ 'stocks' => $stock->quantity ];
	}
}
