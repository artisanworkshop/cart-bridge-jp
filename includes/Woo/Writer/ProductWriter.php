<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\SkuGuard;
use CartBridgeJP\Woo\Support\StockApplier;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;
use WC_Product;
use WC_Product_Attribute;
use WC_Tax;

/**
 * `CanonicalProduct` をWooの商品として書き込む。バリエーションの有無で `simple`/`variable` を
 * 分岐し、バリエーション本体の同期は `VariationWriter` に委譲する。
 */
final class ProductWriter implements EntityWriter {

	/**
	 * `wc_prices_include_tax()` が false のときの警告は商品ごとに積むと結果が埋もれるため、
	 * このWriterインスタンス内で1度だけ積む。
	 *
	 * 注意: `Sync\WooWriterFactory::for_platform()` は Action Scheduler の1アクション
	 * （=1ページ処理、`JobManager::process_job()`）ごとに新しいwriterインスタンスを
	 * 組み立てるため、実際にデデュープされる範囲は「1ジョブ実行全体」ではなく「1ページ内」
	 * にとどまる（複数ページに跨るアクションは別プロセスで実行されうるため、インスタンス
	 * プロパティでジョブ全体をまたぐ状態を持たせることはできない）。ジョブ全体での
	 * デデュープにはrun_id単位の永続状態（transient等）が必要になるため、現状は
	 * ページ単位の抑制にとどめている。
	 */
	private bool $prices_include_tax_warned = false;

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings,
		private readonly VariationWriter $variations,
		private readonly MediaImporter $media
	) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalProduct ) {
			throw new RuntimeException( 'ProductWriter received an unsupported Canonical model.' );
		}

		$warnings     = [];
		$has_variants = [] !== $item->variants;
		$type         = $has_variants ? 'variable' : 'simple';

		try {
			$product = wc_get_product_object( $type, $existing_local_id ?? 0 );
		} catch ( Throwable ) {
			// mappingsが指す商品が手動削除等で既に存在しない場合、`wc_get_product_object()`は
			// （`wc_get_product()`と異なり）例外を投げる。既存IDを信用せず新規作成へ
			// フォールバックする（TermWriterの同種のstale-ID対応と同じ方針）。
			$existing_local_id = null;
			$product           = wc_get_product_object( $type, 0 );
		}

		$product->set_name( $item->name );
		$product->set_description( $item->description ?? '' );
		$product->set_short_description( Value::string( $item->extras['short_description'] ?? null ) ?? '' );
		$product->set_status( 'publish' === $item->status ? 'publish' : 'private' );
		$product->set_catalog_visibility( true === Value::bool( $item->extras['unlisted'] ?? null ) ? 'hidden' : 'visible' );

		$sort = Value::int( $item->extras['sort'] ?? null );

		if ( null !== $sort ) {
			$product->set_menu_order( $sort );
		}

		$product->set_virtual( ! $item->requires_shipping );

		// バリエーションありの商品は価格・在庫を各variationが持つため親には設定しない
		// （WC_Product_Variable::sync()が子の価格レンジ・在庫状態から親を再計算する）。
		if ( ! $has_variants ) {
			$sale_price = $this->resolve_sale_price( $item->price, $item->sale_price );

			$product->set_regular_price( $item->price );
			$product->set_sale_price( $sale_price ?? '' );
			$product->set_price( $sale_price ?? $item->price );
			StockApplier::apply( $product, $item->stock );
		}

		$few_num = Value::int( $item->extras['few_num'] ?? null );

		if ( null !== $few_num ) {
			$product->set_low_stock_amount( $few_num );
		}

		if ( null !== $item->weight ) {
			$product->set_weight( (string) wc_get_weight( $item->weight, $this->weight_unit(), 'g' ) );
		}

		$warnings = array_merge( $warnings, $this->apply_tax_class( $product, $item->tax_class ) );
		$warnings = array_merge( $warnings, SkuGuard::apply( $product, $item->sku ) );

		[ $category_ids, $category_warnings ] = $this->resolve_refs( $item->category_refs, 'category', WarningCode::CATEGORY_REF_UNRESOLVED );
		$product->set_category_ids( $category_ids );
		$warnings = array_merge( $warnings, $category_warnings );

		[ $tag_ids, $tag_warnings ] = $this->resolve_refs( $item->tag_refs, 'tag', WarningCode::TAG_REF_UNRESOLVED );
		$product->set_tag_ids( $tag_ids );
		$warnings = array_merge( $warnings, $tag_warnings );

		[ $attributes, $variation_axis_names, $attribute_warnings ] = $this->build_attributes( $item );
		$product->set_attributes( $attributes );
		$warnings = array_merge( $warnings, $attribute_warnings );

		if ( ! wc_prices_include_tax() && ! $this->prices_include_tax_warned ) {
			$warnings[]                      = WarningCode::PRICES_INCLUDE_TAX_DISABLED;
			$this->prices_include_tax_warned = true;
		}

		ExtrasMeta::apply( $product, $this->meta_extras( $item->extras ) );
		$product->update_meta_data( '_cbjp_platform', $this->platform );
		$product->update_meta_data( '_cbjp_remote_id', $item->remote_id() ?? '' );

		$operation = null === $existing_local_id ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		// variable→simpleへの型変更は`$product->save()`の中でWooCommerce自身が子variationの
		// 投稿を強制削除する（`WC_Post_Data::product_type_changed()`）。保存後に検索しても
		// 対象は既に消えているため、`cbjp_mappings`の掃除に必要な情報を保存前に確定させる。
		$stale_variations = ( ! $has_variants && null !== $existing_local_id )
			? $this->variations->find_owned_variation_remote_ids( $existing_local_id )
			: [];

		$product_id = $product->save();

		$warnings = array_merge( $warnings, $this->apply_images( $product, $item->images ) );

		if ( $has_variants ) {
			$warnings = array_merge(
				$warnings,
				$this->variations->sync( $product_id, $item->variants, $variation_axis_names )
			);
		} elseif ( [] !== $stale_variations ) {
			$warnings = array_merge( $warnings, $this->variations->remove_all( $stale_variations ) );
		}

		return new WriteResult( $product_id, $operation, $warnings );
	}

	/**
	 * `set_sale_price('')`を無条件に呼ぶ従来実装では`CanonicalProduct::$sale_price`が
	 * 一切参照されず、値があっても常に破棄されていた。セール価格として不正な値
	 * （非数値・通常価格以上）をそのまま適用すると誤って割引が効いてしまう金銭的リスクが
	 * あるため、数値かつ通常価格未満の場合のみ採用し、それ以外は「セールなし」として扱う
	 * （WooCommerce自身のREST/管理画面バリデーションと同じ振る舞い）。
	 */
	private function resolve_sale_price( string $regular_price, ?string $sale_price ): ?string {
		if ( null === $sale_price || '' === $sale_price ) {
			return null;
		}

		if ( ! is_numeric( $sale_price ) || ! is_numeric( $regular_price ) || (float) $sale_price >= (float) $regular_price ) {
			return null;
		}

		return $sale_price;
	}

	/**
	 * @return array<int,string>
	 */
	private function apply_tax_class( WC_Product $product, ?string $tax_class ): array {
		if ( null === $tax_class ) {
			$product->set_tax_class( '' );

			return [];
		}

		if ( in_array( $tax_class, WC_Tax::get_tax_class_slugs(), true ) ) {
			$product->set_tax_class( $tax_class );

			return [];
		}

		$product->set_tax_class( '' );

		return [ WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, $tax_class ) ];
	}


	/**
	 * @param array<int,string> $refs
	 * @return array{0:array<int,int>,1:array<int,string>}
	 */
	private function resolve_refs( array $refs, string $entity_type, string $warning_code ): array {
		$ids      = [];
		$warnings = [];

		foreach ( $refs as $ref ) {
			$local_id = $this->mappings->find_local_id( $this->platform, $entity_type, $ref );

			if ( null === $local_id ) {
				$warnings[] = WarningCode::with_detail( $warning_code, $ref );
				continue;
			}

			$ids[] = $local_id;
		}

		return [ $ids, $warnings ];
	}

	/**
	 * ローカル属性（グローバル属性タクソノミーは作らない）を組み立てる。バリエーション軸
	 * （`variants[].option1_name`/`option2_name`）を先に確定し、`options[]`（非バリエーション属性）と
	 * 名前が衝突する場合は軸を優先する。
	 *
	 * @return array{0:array<int,WC_Product_Attribute>,1:array<int,string>,2:array<int,string>} 属性配列・軸名（順序保持）・警告
	 */
	private function build_attributes( CanonicalProduct $item ): array {
		$attributes = [];
		$warnings   = [];
		$position   = 0;

		$axis_names = $this->variation_axis_names( $item->variants );

		foreach ( $axis_names as $index => $axis_name ) {
			$values = $this->axis_values( $item->variants, $index );

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $axis_name );
			$attribute->set_options( $values );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}

		foreach ( $item->options as $option ) {
			$name = Value::string( $option['name'] ?? null );

			if ( null === $name ) {
				continue;
			}

			if ( in_array( $name, $axis_names, true ) ) {
				$warnings[] = WarningCode::with_detail( WarningCode::ATTRIBUTE_NAME_COLLISION, $name );
				continue;
			}

			$values = is_array( $option['values'] ?? null )
				? array_values( array_filter( $option['values'], 'is_string' ) )
				: [];

			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $name );
			$attribute->set_options( $values );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes[] = $attribute;
		}

		return [ $attributes, $axis_names, $warnings ];
	}

	/**
	 * @param array<int,array<string,mixed>> $variants
	 * @return array<int,string>
	 */
	private function variation_axis_names( array $variants ): array {
		$axis1 = null;
		$axis2 = null;

		foreach ( $variants as $variant ) {
			$axis1 = $axis1 ?? Value::string( $variant['option1_name'] ?? null );
			$axis2 = $axis2 ?? Value::string( $variant['option2_name'] ?? null );
		}

		return array_values( array_filter( [ $axis1, $axis2 ], static fn ( ?string $name ): bool => null !== $name ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $variants
	 * @return array<int,string>
	 */
	private function axis_values( array $variants, int $axis_index ): array {
		$key    = 0 === $axis_index ? 'option1_value' : 'option2_value';
		$values = [];

		foreach ( $variants as $variant ) {
			$value = Value::string( $variant[ $key ] ?? null );

			if ( null !== $value && ! in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * `write()`がsave()済みの`$product`をそのまま受け取る（`wc_get_product()`による
	 * 冗長な再取得を避けるため）。
	 *
	 * @param array<int,array<string,mixed>> $images
	 * @return array<int,string>
	 */
	private function apply_images( WC_Product $product, array $images ): array {
		if ( [] === $images ) {
			return [];
		}

		$product_id = $product->get_id();

		usort(
			$images,
			static function ( array $a, array $b ): int {
				$a_position = Value::int( $a['position'] ?? null ) ?? PHP_INT_MAX;
				$b_position = Value::int( $b['position'] ?? null ) ?? PHP_INT_MAX;

				return $a_position <=> $b_position;
			}
		);

		$warnings       = [];
		$attachment_ids = [];

		foreach ( $images as $image ) {
			$src = Value::string( $image['src'] ?? null );

			if ( null === $src ) {
				continue;
			}

			$attachment_id = $this->media->import( $src, $product_id );

			if ( null === $attachment_id ) {
				$warnings[] = WarningCode::with_detail( WarningCode::IMAGE_DOWNLOAD_FAILED, $src );
				continue;
			}

			$attachment_ids[] = $attachment_id;
		}

		if ( [] === $attachment_ids ) {
			return $warnings;
		}

		// メイン画像はASP側の値を常に反映する。ギャラリーはこちらが過去に取り込んだ添付
		// （`_cbjp_source_url`メタあり）だけを差し替え、ユーザーが手動で追加した画像は残す。
		$preserved_gallery = array_values(
			array_filter(
				$product->get_gallery_image_ids(),
				static fn ( int $attachment_id ): bool => '' === get_post_meta( $attachment_id, '_cbjp_source_url', true )
			)
		);

		$product->set_image_id( $attachment_ids[0] );
		$product->set_gallery_image_ids( array_values( array_merge( array_slice( $attachment_ids, 1 ), $preserved_gallery ) ) );
		$product->save();

		return $warnings;
	}

	/**
	 * @param array<string,mixed> $extras
	 * @return array<string,mixed>
	 */
	private function meta_extras( array $extras ): array {
		// checksum算出に使われないメタ専用キー（remote_idは別途 `_cbjp_remote_id` として書くため除外）。
		unset( $extras['remote_id'] );

		return $extras;
	}

	private function weight_unit(): string {
		$unit = get_option( 'woocommerce_weight_unit', 'kg' );

		return is_string( $unit ) && '' !== $unit ? $unit : 'kg';
	}
}
