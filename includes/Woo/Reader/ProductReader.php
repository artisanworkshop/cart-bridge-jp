<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\WeightUnit;
use CartBridgeJP\Woo\WarningCode;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Term;

/**
 * `WC_Product`（+バリエーション）を `CanonicalProduct` へ変換する（`Woo\Writer\ProductWriter` の
 * 読出側対称形）。カテゴリはこのプラグインの`cbjp_mappings`（ASP→Woo作成時の紐付け）ではなく
 * `category_map`（Woo側カテゴリID→ASP側カテゴリID。D19）で解決する: カラーミーは
 * カテゴリ作成不可（`can_create_category=false`）なため、エクスポートは常に既存ASPカテゴリへの
 * 紐付けであり、`cbjp_mappings`の`category`entity自体がColorMeでは作られない。
 */
final class ProductReader implements EntityReader {

	private const PAGE_SIZE = 20;

	public function __construct(
		private readonly string $platform,
		private readonly MethodMap $method_map,
		private readonly MappingRepository $mappings
	) {}

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$args = [
			'status'   => [ 'publish', 'private', 'draft' ],
			'type'     => [ 'simple', 'variable' ],
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'objects',
			'paginate' => true,
			'limit'    => self::PAGE_SIZE,
			'page'     => (int) $cursor->get( 'page', 1 ),
		];

		if ( null !== $only_local_ids ) {
			if ( [] === $only_local_ids ) {
				return new ReadPage( [], null, 0 );
			}

			$args['include'] = $only_local_ids;
			// サンプルモードはID指定取得であり `include` がそのまま対象総数を決めるため、
			// ページングは不要（1ページで確定する）。
			unset( $args['page'] );
			$args['limit'] = count( $only_local_ids );
		}

		/** @var object{products:array<int,WC_Product>,total:int,max_num_pages:int} $result */
		$result = wc_get_products( $args );

		// Wooの商品データストアは復元に失敗した投稿をarray_filterで除外するため、
		// $result->products のキーが飛び番になりうる。array_values() で
		// ReadPage::$items の array<int,ReadItem> 契約（0始まり連番）に揃える。
		$items = array_values( array_map( fn ( WC_Product $product ): ReadItem => $this->to_read_item( $product ), $result->products ) );

		$page          = (int) ( $args['page'] ?? 1 );
		$has_next_page = null === $only_local_ids && $page < $result->max_num_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, $result->total );
	}

	private function to_read_item( WC_Product $product ): ReadItem {
		$warnings                              = [];
		$is_variable                           = $product instanceof WC_Product_Variable;
		$axis_names                            = $is_variable ? $this->variation_axis_attributes( $product ) : [];
		$variants                              = $is_variable ? $this->variants( $product, $axis_names, $warnings ) : [];
		$images                                = $this->images( $product );
		[ $options, $option_warnings ]         = $this->options( $product, $axis_names );
		$warnings                              = array_merge( $warnings, $option_warnings );
		[ $category_refs, $category_warnings ] = $this->category_refs( $product );
		$warnings                              = array_merge( $warnings, $category_warnings );

		$regular_price = $product->get_regular_price();
		$sale_price    = $product->get_sale_price();
		$weight        = WeightUnit::convert_to_grams( (string) $product->get_weight() );
		$tax_class     = $product->get_tax_class();

		$canonical = new CanonicalProduct(
			$product->get_name(),
			'' !== $product->get_sku() ? $product->get_sku() : null,
			'' !== $regular_price ? $regular_price : '0',
			'' !== $sale_price ? $sale_price : null,
			'' !== $product->get_description() ? $product->get_description() : null,
			$images,
			$variants,
			$options,
			$category_refs,
			$is_variable ? null : $this->stock( $product ),
			'publish' === $product->get_status() ? 'publish' : 'private',
			[],
			! $product->is_virtual(),
			[],
			$weight,
			'' !== $tax_class ? $tax_class : null
		);

		return new ReadItem(
			$product->get_id(),
			$canonical,
			$warnings,
			! WarningCode::indicates_unresolved_reference( $warnings )
		);
	}

	/**
	 * 在庫管理対象なのに数量が不明な場合は0（CLAUDE.md: `null`は「在庫管理外」を意味するため、
	 * 誤って「在庫あり」と解釈されないようフェイルクローズする）。
	 */
	private function stock( WC_Product $product ): ?int {
		if ( ! $product->get_manage_stock() ) {
			return null;
		}

		$quantity = $product->get_stock_quantity();

		return null !== $quantity ? (int) $quantity : 0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function images( WC_Product $product ): array {
		$attachment_ids = array_filter( array_merge( [ $product->get_image_id() ], $product->get_gallery_image_ids() ) );
		$images         = [];

		foreach ( array_values( $attachment_ids ) as $position => $attachment_id ) {
			$src = wp_get_attachment_url( (int) $attachment_id );

			if ( false === $src ) {
				continue;
			}

			$images[] = [
				'src'      => $src,
				'position' => $position,
			];
		}

		return $images;
	}

	/**
	 * variation属性（軸）の名前一覧。`ProductWriter::variation_axis_names()`と同じくキー0=軸1、
	 * キー1=軸2のスロット規約を保持する（詰め直さない）。
	 *
	 * @return array<int,WC_Product_Attribute>
	 */
	private function variation_axis_attributes( WC_Product_Variable $product ): array {
		$axis = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
				$axis[] = $attribute;

				if ( 2 === count( $axis ) ) {
					break;
				}
			}
		}

		return $axis;
	}

	/**
	 * @param array<int,WC_Product_Attribute> $axis_attributes
	 * @param array<int,string>               $warnings 呼び出し元と共有する警告配列（参照渡しの代わりに戻り値で反映）。
	 * @return array<int,array<string,mixed>>
	 */
	private function variants( WC_Product_Variable $product, array $axis_attributes, array &$warnings ): array {
		$variation_ids = array_map( 'intval', $product->get_children() );
		// アイテム（variation）毎のSELECTを避けるため一括プリロードする
		// （`VariationWriter::sync()`の逆方向、同じ理由）。
		$existing_remote_ids = $this->mappings->find_many_by_local_ids( $this->platform, 'variant', $variation_ids );

		$variants = [];

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$remote_id = $existing_remote_ids[ $variation_id ]['remote_id'] ?? null;

			$price = $variation->get_regular_price();

			if ( '' === $price ) {
				// 価格未設定のバリエーションを0円等で書き出すと、`VariationWriter::sync()`側の
				// 価格検証（`VARIATION_PRICE_INVALID`）に必ず引っかかるだけでなく、CanonicalProduct
				// のchecksum上は「価格が空文字」という不正な状態を運ぶことになる。読出時点で
				// スキップし、原因をレポートで追跡できるようにする。
				$warnings[] = WarningCode::with_detail( WarningCode::VARIATION_PRICE_INVALID, (string) $variation_id );
				continue;
			}

			$variant = [
				'remote_id' => $remote_id ?? '',
				'sku'       => '' !== $variation->get_sku() ? $variation->get_sku() : null,
				'price'     => $price,
				'stock'     => $this->variation_stock( $variation ),
				'weight'    => WeightUnit::convert_to_grams( (string) $variation->get_weight() ),
			];

			$this->apply_axis_values( $variant, $variation, $axis_attributes );

			$variants[] = $variant;
		}

		return $variants;
	}

	private function variation_stock( WC_Product_Variation $variation ): ?int {
		if ( false === $variation->get_manage_stock() ) {
			return null;
		}

		$quantity = $variation->get_stock_quantity();

		return null !== $quantity ? (int) $quantity : 0;
	}

	/**
	 * @param array<int,WC_Product_Attribute> $axis_attributes
	 * @param array<string,mixed>             $variant
	 */
	private function apply_axis_values( array &$variant, WC_Product_Variation $variation, array $axis_attributes ): void {
		$raw_attributes = $variation->get_attributes();

		foreach ( [ 0, 1 ] as $index ) {
			$attribute = $axis_attributes[ $index ] ?? null;
			$name_key  = 'option' . ( $index + 1 ) . '_name';
			$value_key = 'option' . ( $index + 1 ) . '_value';

			if ( null === $attribute ) {
				$variant[ $name_key ]  = null;
				$variant[ $value_key ] = null;
				continue;
			}

			$variant[ $name_key ]  = $this->attribute_label( $attribute );
			$variant[ $value_key ] = $this->variation_attribute_value( $attribute, $raw_attributes );
		}
	}

	private function attribute_label( WC_Product_Attribute $attribute ): string {
		return $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();
	}

	/**
	 * @param array<string,string> $raw_attributes `WC_Product_Variation::get_attributes()`
	 *   （taxonomy属性はterm slug、ローカル属性は生値）。
	 */
	private function variation_attribute_value( WC_Product_Attribute $attribute, array $raw_attributes ): ?string {
		$key       = $attribute->is_taxonomy() ? $attribute->get_name() : sanitize_title( $attribute->get_name() );
		$raw_value = $raw_attributes[ $key ] ?? '';

		if ( '' === $raw_value ) {
			return null;
		}

		if ( $attribute->is_taxonomy() ) {
			$term = get_term_by( 'slug', $raw_value, $attribute->get_name() );

			return ( $term instanceof WP_Term ) ? $term->name : $raw_value;
		}

		return $raw_value;
	}

	/**
	 * 非バリエーション属性（`CanonicalProduct::$options`）。軸属性は`ProductWriter`側で
	 * `ATTRIBUTE_NAME_COLLISION`として除外されるため、ここでも軸と同名のものは含めない
	 * （エクスポート→再インポートの往復で無駄な衝突警告を発生させないため）。
	 *
	 * @param array<int,WC_Product_Attribute> $axis_attributes
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,string>}
	 */
	private function options( WC_Product $product, array $axis_attributes ): array {
		$axis_names = array_map( fn ( WC_Product_Attribute $a ): string => $this->attribute_label( $a ), $axis_attributes );
		$options    = [];
		$warnings   = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute || $attribute->get_variation() ) {
				continue;
			}

			$name = $this->attribute_label( $attribute );

			if ( in_array( $name, $axis_names, true ) ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$terms  = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
				$values = array_values( array_filter( $terms, 'is_string' ) );
			} else {
				$values = array_values( array_filter( $attribute->get_options(), 'is_string' ) );
			}

			$options[] = [
				'name'   => $name,
				'values' => $values,
			];
		}

		return [ $options, $warnings ];
	}

	/**
	 * `category_map`（Woo側カテゴリID→ASP側カテゴリID。D19）でASP側カテゴリIDへ解決する。
	 * マッピング未設定のカテゴリは警告付きで除外する（`ProductWriter::resolve_refs()`の
	 * エクスポート版対称形。ただし解決先が`cbjp_mappings`ではなくユーザー設定のため
	 * `MethodMap`を使う）。
	 *
	 * @return array{0:array<int,string>,1:array<int,string>}
	 */
	private function category_refs( WC_Product $product ): array {
		$refs     = [];
		$warnings = [];

		foreach ( $product->get_category_ids() as $term_id ) {
			$asp_category_id = $this->method_map->mapped_asp_category_id( (string) $term_id );

			if ( null === $asp_category_id ) {
				$warnings[] = WarningCode::with_detail( WarningCode::CATEGORY_MAP_UNRESOLVED, (string) $term_id );
				continue;
			}

			$refs[] = $asp_category_id;
		}

		return [ $refs, $warnings ];
	}
}
