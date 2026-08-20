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
use CartBridgeJP\Woo\Support\PlatformOwnership;
use CartBridgeJP\Woo\Support\SkuGuard;
use CartBridgeJP\Woo\Support\StockApplier;
use CartBridgeJP\Woo\Support\TaxClass;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\Support\WeightUnit;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;
use WC_Product;
use WC_Product_Attribute;
use WP_Term;

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

		// stale-IDフォールバックで`$existing_local_id`がnullに戻される前の元の値を保持する。
		// 親post自体は手動削除等で無くなっていても、`wp_delete_post()`を経由しない削除
		// （直接DB操作・別プラグイン等）ではWooCommerceが子variation投稿を自動カスケード
		// 削除しない場合があり、旧IDに紐づく孤立variationが残りうるため、その掃除に使う。
		$original_existing_local_id = $existing_local_id;

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
		// （`VariationWriter::sync()`経由で呼ばれる`WC_Product_Variable::sync()`が
		// 子の価格レンジ・stock_statusから親を再計算する。ただし`sync()`が触るのは
		// `_price`/`_regular_price`/`_sale_price`（`sync_price()`が明示的に`delete_post_meta()`
		// してから子から再構築する）と`_stock_status`（`sync_stock_status()`）のみで、
		// `manage_stock`/`_stock`（数量）は対象外。既存商品がsimple→variableへ型変更された
		// 場合、simple時代の`manage_stock=true`+数量がそのままpostmetaに残り続け
		// （`wc_get_product_object()`は既存postの全メタを読み込むため）、variation側の
		// 在庫管理と競合する（StockWriterが親をvariableとして扱う際にmanage_stockを
		// falseにする方針と矛盾する状態になる）。
		if ( ! $has_variants ) {
			if ( is_numeric( $item->price ) && (float) $item->price >= 0 ) {
				[ $sale_price, $sale_price_warning ] = $this->resolve_sale_price( $item->price, $item->sale_price );

				if ( null !== $sale_price_warning ) {
					$warnings[] = $sale_price_warning;
				}

				$product->set_regular_price( $item->price );
				$product->set_sale_price( $sale_price ?? '' );
				$product->set_price( $sale_price ?? $item->price );
			} else {
				// 非数値・負の価格をそのまま`set_regular_price()`に渡すと、`wc_format_decimal()`は
				// 符号を検証しないため不正な価格のまま公開されてしまう（無料/マイナス価格の
				// 金銭的リスク）。価格を設定せず（Wooの規約上、価格未設定の商品は購入不可になる）
				// 警告を積む。他フィールドは通常どおり反映し、商品自体の作成/更新は継続する。
				$warnings[] = WarningCode::with_detail( WarningCode::PRODUCT_PRICE_INVALID, $item->price );
			}

			StockApplier::apply( $product, $item->stock );
		} else {
			// simple→variable型変更時にsimple時代の`manage_stock`/数量が残らないよう明示的に
			// クリアする（上のコメント参照。`sync()`はこのフィールドを再計算しない）。
			$product->set_manage_stock( false );
		}

		// few_numが欠損/nullになった場合（再同期でASP側の設定が解除された等）も、直後の
		// `ExtrasMeta::apply()`が`_cbjp_few_num`メタを正しく削除するのと同様に、Woo標準の
		// `low_stock_amount`（`_low_stock_amount`postmeta）も明示的にクリアする。ここで
		// 何もしないと以前設定した閾値がpostmetaに残り続け、実際にはfew_numを持たない商品でも
		// 古い閾値のまま低在庫通知が発火し続けてしまう。
		$few_num = Value::int( $item->extras['few_num'] ?? null );
		$product->set_low_stock_amount( null !== $few_num ? $few_num : '' );

		// few_num/low_stock_amountと同じ理由: 再同期でASP側が重量を送らなくなった場合も
		// 古い値がpostmetaに残り続けないよう明示的にクリアする。
		$product->set_weight( null !== $item->weight ? WeightUnit::convert_from_grams( $item->weight ) : '' );

		$warnings = array_merge( $warnings, $this->apply_tax_class( $product, $item->tax_class ) );
		$warnings = array_merge( $warnings, SkuGuard::apply( $product, $item->sku ) );

		[ $category_ids, $category_warnings ] = $this->resolve_refs( $item->category_refs, 'category', 'product_cat', WarningCode::CATEGORY_REF_UNRESOLVED );
		$product->set_category_ids( $category_ids );
		$warnings = array_merge( $warnings, $category_warnings );

		[ $tag_ids, $tag_warnings ] = $this->resolve_refs( $item->tag_refs, 'tag', 'product_tag', WarningCode::TAG_REF_UNRESOLVED );
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
		// `$existing_local_id`ではなく`$original_existing_local_id`を使う: stale-ID
		// フォールバックが発生した場合（親post自体が既に無い）でも、その旧IDに紐づく
		// 孤立variationの掃除は引き続き必要なため。
		$stale_variations = ( ! $has_variants && null !== $original_existing_local_id )
			? $this->variations->find_owned_variation_remote_ids( $original_existing_local_id )
			: [];

		$product_id = $product->save();

		if ( 0 === $product_id ) {
			// `WC_Product_Data_Store_CPT::create()` は `wp_insert_post()` が失敗（DB障害等）した場合、
			// 例外を投げず黙ってIDを未設定のまま返す（`WC_Product::save()`は`get_id()`=0を返す）。
			// このまま`variations->sync(0, ...)`/`apply_images()`を走らせると、parent_id=0の
			// 孤立バリエーションを作りかねない。`WriteResult::$local_id === 0`はImporterが
			// mappingsを書かない契約のため、ここで打ち切らないと警告も無いまま永久に気付けない
			// （OrderWriter/CustomerWriter/TermWriter/VariationWriterの同種の作成失敗ガードと同じ方針）。
			$warnings[] = WarningCode::PRODUCT_SAVE_FAILED;

			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		try {
			$warnings = array_merge( $warnings, $this->apply_images( $product, $item->images ) );

			if ( $has_variants ) {
				$warnings = array_merge(
					$warnings,
					$this->variations->sync( $product_id, $item->variants, $variation_axis_names )
				);
			} elseif ( [] !== $stale_variations ) {
				$warnings = array_merge( $warnings, $this->variations->remove_all( $stale_variations ) );
			}
		} catch ( Throwable $exception ) {
			// `$product->save()`は直後にDBへ永続化するため、ここで例外が伝播すると呼び出し元
			// Importerはmappingsを書けない（OrderWriterの同種の対応と同じ理由）。新規作成
			// だった場合、再試行時に同一remote productに対して重複した孤立商品を作ってしまう
			// ため、ここで削除してから例外を再送出し、次回はクリーンな状態からやり直せるようにする。
			if ( null === $existing_local_id ) {
				// `variations->sync()`は複数variantを1件ずつ保存・upsertするため、後続のvariantで
				// 例外が起きた時点で先行するvariantは既に保存済み・mapping済みのことがある。
				// `product`投稿型は非階層のため`$product->delete(true)`は子のvariation投稿を
				// カスケード削除せず、そのまま放置すると親を失った孤立variationと、存在しない
				// 親を指したままのmapping行が残ってしまう。`find_owned_variation_remote_ids()`は
				// stale-variation掃除と同じ手法（post_parent＋プラットフォーム所有権）で
				// これらを検出できるため、親を削除する前にここでも同様に掃除する。
				$this->variations->remove_all( $this->variations->find_owned_variation_remote_ids( $product_id ) );
				$product->delete( true );
			}

			throw $exception;
		}

		return new WriteResult( $product_id, $operation, $warnings );
	}

	/**
	 * `set_sale_price('')`を無条件に呼ぶ従来実装では`CanonicalProduct::$sale_price`が
	 * 一切参照されず、値があっても常に破棄されていた。セール価格として不正な値
	 * （非数値・0以下・通常価格以上）をそのまま適用すると誤って割引が効いてしまう金銭的
	 * リスクがあるため、数値かつ0より大きくかつ通常価格未満の場合のみ採用し、それ以外は
	 * 「セールなし」として扱う（WooCommerce自身のREST/管理画面バリデーションと同じ振る舞い）。
	 * 値が存在するのに拒否した場合は、他の同種のフェイルクローズ分岐（tax_class・SKU・
	 * カテゴリ/タグ参照等）と同じく警告を積み、結果レポートから追跡できるようにする。
	 *
	 * @return array{0:?string,1:?string} 採用するセール価格（無い場合null）と警告（無い場合null）。
	 */
	private function resolve_sale_price( string $regular_price, ?string $sale_price ): array {
		if ( null === $sale_price || '' === $sale_price ) {
			return [ null, null ];
		}

		if ( ! is_numeric( $sale_price ) || ! is_numeric( $regular_price ) || (float) $sale_price <= 0 || (float) $sale_price >= (float) $regular_price ) {
			return [ null, WarningCode::with_detail( WarningCode::SALE_PRICE_INVALID, $sale_price ) ];
		}

		return [ $sale_price, null ];
	}

	/**
	 * @return array<int,string>
	 */
	private function apply_tax_class( WC_Product $product, ?string $tax_class ): array {
		[ $resolved, $warnings ] = TaxClass::resolve( $tax_class );

		$product->set_tax_class( $resolved );

		return $warnings;
	}


	/**
	 * category_refs/tag_refsをmappingsから解決する。`find_local_id()`をrefの数だけループ
	 * 呼び出すとカテゴリ・タグを多く持つ商品ほどSELECTが積み重なる（`VariationWriter`が
	 * ページ内variantの一括取得に`find_many()`を使っているのと同じN+1）ため、ここでも
	 * refsをまとめて1クエリで解決する。
	 *
	 * @param array<int,string> $refs
	 * @return array{0:array<int,int>,1:array<int,string>}
	 */
	private function resolve_refs( array $refs, string $entity_type, string $taxonomy, string $warning_code ): array {
		$ids      = [];
		$warnings = [];
		$mapped   = $this->mappings->find_many( $this->platform, $entity_type, $refs );

		foreach ( $refs as $ref ) {
			$local_id = isset( $mapped[ $ref ] ) ? $mapped[ $ref ]['local_id'] : null;

			// mappingsが指すタームが手動削除等で既に存在しない場合も未解決として扱う
			// （存在しないterm IDをそのまま`set_category_ids()`/`set_tag_ids()`に渡さない）。
			// `get_term()`にtaxonomyを明示するのは`TermWriter`の親ターム検証と同じ理由:
			// term_idはtaxonomyをまたいで一意である保証がないため、指定しないと別taxonomyの
			// タームを誤って同一term_idとして解決しかねない。
			if ( null === $local_id || ! get_term( $local_id, $taxonomy ) instanceof WP_Term ) {
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
	 * 戻り値のキーは元のoption1/2スロット番号（0=option1, 1=option2）をそのまま保持する
	 * （`array_values()`で詰め直さない）。`VariationWriter::variation_attributes()`は
	 * `isset($axis_names[0])`/`isset($axis_names[1])`でスロット番号として直接参照するため、
	 * ここでキーを0始まりに詰め直すと、option1_nameが全variantでnullかつoption2_nameのみ
	 * 存在する商品（ColorMeのoption1/option2は独立フィールドで構造的にありうる）で
	 * axis2がキー0に繰り上がり、`axis_values()`/`variation_attributes()`が誤って
	 * option1_valueを読んでしまう（値が空またはoption1のものになり、variationが
	 * ストア上で区別できなくなる）。
	 *
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

		return array_filter( [ $axis1, $axis2 ], static fn ( ?string $name ): bool => null !== $name );
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

			// D16のリンク再構築ツール等で複数プラットフォームが同一Woo商品を共有しうるため、
			// どのプラットフォームが取り込んだ添付かをここでタグ付けする（ギャラリー保持判定で使う）。
			update_post_meta( $attachment_id, '_cbjp_platform', $this->platform );
			$attachment_ids[] = $attachment_id;
		}

		if ( [] === $attachment_ids ) {
			return $warnings;
		}

		// メイン画像はASP側の値を常に反映する。ギャラリーは「このプラットフォームが過去に
		// 取り込んだ添付」（`_cbjp_source_url`あり かつ `_cbjp_platform`が自分自身）だけを
		// 差し替え、ユーザーが手動で追加した画像・別プラットフォームが取り込んだ画像は残す
		// （`_cbjp_source_url`の有無だけで判定すると、複数プラットフォームが同一商品を共有する
		// 場合に他プラットフォームのギャラリーを消してしまう）。
		$existing_gallery_ids = $product->get_gallery_image_ids();

		// `VariationWriter::find_owned_variation_remote_ids()`/`delete_variations()`と同じ理由:
		// 以下のフィルタで画像1枚ごとに`get_post_meta()`/`PlatformOwnership::owns_post()`
		// （内部でも`get_post_meta()`）を呼ぶと未キャッシュの個別SELECTが発生するため、
		// ここで一括プリロードする。
		update_meta_cache( 'post', $existing_gallery_ids );

		$preserved_gallery = array_values(
			array_filter(
				$existing_gallery_ids,
				fn ( int $attachment_id ): bool => '' === get_post_meta( $attachment_id, '_cbjp_source_url', true )
					|| ! PlatformOwnership::owns_post( $attachment_id, $this->platform )
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
}
