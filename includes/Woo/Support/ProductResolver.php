<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Sync\MappingRepository;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * SKU/remote_id からWooの商品・バリエーションを解決する（受注明細・在庫更新で共用）。
 */
final class ProductResolver {

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings
	) {}

	/**
	 * 受注明細の商品解決（03 §5 D10 #1）: SKU優先、空振りならremote_idのmappingsで解決する。
	 * variation SKU/IDも解決できる（`wc_get_product_id_by_sku()`/`wc_get_product()`はvariationも引ける）。
	 *
	 * ColorMeの受注明細は`remote_product_id`として常に親商品のIDしか持たない（どのvariationかは
	 * option1/2の値でしか特定できない）ため、mappingsの'product'側は必ず親を指す。SKU解決に
	 * 失敗しremote_idが親のvariable商品に解決した場合、`$option1_value`/`$option2_value`
	 * （F1-5後続。呼び出し元=`OrderItemBuilder`が明細の`option1_value_current`/
	 * `option2_value_current`を渡す）で子variationの一意特定を試みる（`resolve_variation_by_options()`）。
	 * 一致が0件・複数件（値欠損・重複等で一意に特定できない）の場合は、variable商品単体を
	 * 返さず「未解決」として扱う（variable商品は単体では購入対象にならないため、そのまま
	 * 明細に結びつけると数量・在庫・価格が不整合な注文行になる）。
	 *
	 * `$option1_value`/`$option2_value`はASP側APIの「最新の商品情報」であり注文時点の値では
	 * ない（オプション名変更後の受注では一致しないことがある）ため、一致しない場合も
	 * フェイルクローズで未解決のままにする（捏造した一致を返さない）。
	 */
	public function resolve_by_sku_or_remote_id( ?string $sku, ?string $remote_id, ?string $option1_value = null, ?string $option2_value = null ): ?WC_Product {
		if ( null !== $sku ) {
			$product_id = wc_get_product_id_by_sku( $sku );

			// `resolve_stock_target()`のSKUフォールバックと同じ理由（`PlatformOwnership`の
			// ownershipガード）: 偶然SKUが一致しただけの別プラットフォーム由来・店舗手動作成の
			// 商品に、注文明細を誤って紐付けてはならない（誤った商品・価格・統計が注文に
			// 付いてしまう）。
			if ( 0 !== $product_id && PlatformOwnership::owns_post( $product_id, $this->platform ) ) {
				$product = $this->as_orderable_product( $product_id );

				if ( null !== $product ) {
					return $product;
				}
			}
		}

		if ( null !== $remote_id ) {
			$local_id = $this->mappings->find_local_id( $this->platform, 'product', $remote_id );

			if ( null !== $local_id ) {
				$product = $this->as_product( $local_id );

				if ( $product instanceof WC_Product_Variable ) {
					$variation = $this->resolve_variation_by_options( $product, $option1_value, $option2_value );

					if ( null !== $variation ) {
						return $variation;
					}
				} elseif ( null !== $product ) {
					return $product;
				}
			}
		}

		return null;
	}

	private function as_orderable_product( int $id ): ?WC_Product {
		$product = $this->as_product( $id );

		return null !== $product && ! $product instanceof WC_Product_Variable ? $product : null;
	}

	/**
	 * 親のvariable商品配下から、option1/2の値が全軸で一致するvariationを1件だけ特定する。
	 *
	 * `$option1_value`/`$option2_value`を軸のスロット番号（0=option1, 1=option2）に固定して
	 * 対応付けない。`ProductWriter::variation_axis_names()`はoption1が無くoption2のみ持つ
	 * 商品（ColorMeのoption1/2は独立フィールドで構造的にありうる）も軸の欠番として保持するが、
	 * 保存後のWC属性（`get_attributes()`）にはどちらのスロット由来かを示す情報が残らず、
	 * `get_position()`も単なる出現順（欠番があっても0番から詰められる）でしかない。
	 * そのためスロット番号ではなく、非null値を出現順に並べたリストと軸の「数」だけを
	 * 突き合わせる: 数が一致すれば位置ペアで対応付け、一致しなければ一意に対応付けられない
	 * ため未解決とする（境界データはフェイルクローズで検証する。CLAUDE.md参照）。
	 */
	private function resolve_variation_by_options( WC_Product_Variable $parent_product, ?string $option1_value, ?string $option2_value ): ?WC_Product_Variation {
		$axis_slugs = $this->variation_axis_slugs( $parent_product );

		if ( [] === $axis_slugs ) {
			return null;
		}

		$provided_values = array_values(
			array_filter(
				[ $option1_value, $option2_value ],
				static fn ( ?string $value ): bool => null !== $value
			)
		);

		if ( count( $provided_values ) !== count( $axis_slugs ) ) {
			return null;
		}

		$expected = array_combine( $axis_slugs, $provided_values );

		$matches = [];

		foreach ( $parent_product->get_children() as $variation_id ) {
			// 別プラットフォーム由来・このプラグイン外で作成されたvariationは対象外
			// （`VariationWriter`のstale削除・`ProductResolver::resolve_stock_target()`と同じ
			// ownershipガード）。
			if ( ! PlatformOwnership::owns_post( $variation_id, $this->platform ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$attributes = $variation->get_attributes();
			$is_match   = true;

			foreach ( $expected as $slug => $value ) {
				if ( ( $attributes[ $slug ] ?? null ) !== $value ) {
					$is_match = false;
					break;
				}
			}

			if ( $is_match ) {
				$matches[] = $variation;
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * @return array<int,string> 位置0=option1軸、1=option2軸のスラッグ。
	 */
	private function variation_axis_slugs( WC_Product_Variable $parent_product ): array {
		$axis_attributes = array_values(
			array_filter(
				$parent_product->get_attributes(),
				static fn ( $attribute ): bool => $attribute instanceof WC_Product_Attribute && $attribute->get_variation()
			)
		);

		usort(
			$axis_attributes,
			static fn ( WC_Product_Attribute $a, WC_Product_Attribute $b ): int => $a->get_position() <=> $b->get_position()
		);

		return array_map(
			static fn ( WC_Product_Attribute $attribute ): string => sanitize_title( $attribute->get_name() ),
			$axis_attributes
		);
	}

	/**
	 * 在庫更新の対象解決: variant_refがあればmappings（'variant'）優先、無ければproduct_refの
	 * mappings、それも空振りならSKUで解決する。SKUフォールバックはvariant_ref・product_ref
	 * どちらの経路でも空振りだった場合に共通で試す（`wc_get_product_id_by_sku()`は
	 * variationも引けるため、variant側のmapping未整備・stale時にも取りこぼしを防げる）。
	 *
	 * SKUフォールバックは`wc_get_product_id_by_sku()`でストア全体からSKU一致を探すため、
	 * mappingsが未整備/staleな場合、店舗が手動作成した商品や別プラットフォーム由来の
	 * 商品がたまたま同じSKUを持っていると、それらの在庫を誤って上書きしうる
	 * （TermWriter/CouponWriter/VariationWriterが行っている`_cbjp_platform`ownership検証と
	 * 同じガードをここにも掛け、自プラットフォームが作成したレコード以外には書き込まない）。
	 */
	public function resolve_stock_target( ?string $variant_ref, string $product_ref, ?string $sku ): ?WC_Product {
		if ( null !== $variant_ref ) {
			$local_id = $this->mappings->find_local_id( $this->platform, 'variant', $variant_ref );

			if ( null !== $local_id ) {
				$product = $this->as_product( $local_id );

				if ( null !== $product ) {
					return $product;
				}
			}
		} else {
			$local_id = $this->mappings->find_local_id( $this->platform, 'product', $product_ref );

			if ( null !== $local_id ) {
				$product = $this->as_product( $local_id );

				if ( null !== $product ) {
					return $product;
				}
			}
		}

		if ( null !== $sku ) {
			$product_id = wc_get_product_id_by_sku( $sku );

			if ( 0 !== $product_id && PlatformOwnership::owns_post( $product_id, $this->platform ) ) {
				return $this->as_product( $product_id );
			}
		}

		return null;
	}

	private function as_product( int $id ): ?WC_Product {
		$product = wc_get_product( $id );

		return $product instanceof WC_Product ? $product : null;
	}
}
