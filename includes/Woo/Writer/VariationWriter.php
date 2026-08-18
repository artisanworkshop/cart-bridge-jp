<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\Support\PlatformOwnership;
use CartBridgeJP\Woo\Support\SkuGuard;
use CartBridgeJP\Woo\Support\StockApplier;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\Support\WeightUnit;
use CartBridgeJP\Woo\WarningCode;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Post;

/**
 * `CanonicalProduct::variants` をWooのバリエーション（`WC_Product_Variation`）へ同期する。
 * `ProductWriter` から親商品の保存後に呼ばれる。
 */
final class VariationWriter {

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings
	) {}

	/**
	 * @param array<int,array<string,mixed>> $variants
	 * @param array<int,string>              $axis_names variation属性の軸名（先頭がoption1、2番目がoption2）。
	 * @return array<int,string> 警告
	 */
	public function sync( int $product_id, array $variants, array $axis_names ): array {
		$warnings        = [];
		$seen_remote_ids = [];
		// remote_id・priceのいずれかが解決できないvariantが混ざっている場合、このレスポンスは
		// 不完全なスナップショットである可能性がある（部分的なAPIレスポンス等）。その状態で
		// stale削除を行うと、実際には消えていない正当なvariationを「今回のセットに無い」と
		// 誤認して削除しかねないため、そのようなvariantが1件でもあればstale削除自体を中止する。
		$incomplete_snapshot = false;

		// アイテム（variant）毎のSELECTを避けるため、対象になり得る全remote_idのmappingを
		// 一括プリロードする（Sync\Importer::process_items()と同じ理由・同じ手法）。
		$remote_ids = array_values(
			array_filter(
				array_map(
					static fn ( array $variant ): ?string => Value::string( $variant['remote_id'] ?? null ),
					$variants
				),
				static fn ( ?string $remote_id ): bool => null !== $remote_id
			)
		);
		$existing   = $this->mappings->find_many( $this->platform, 'variant', $remote_ids );

		foreach ( $variants as $variant ) {
			$remote_id = Value::string( $variant['remote_id'] ?? null );

			if ( null === $remote_id ) {
				$incomplete_snapshot = true;
				continue;
			}

			$price = Value::string( $variant['price'] ?? null );

			if ( null === $price ) {
				// 価格が解決できないvariantを楽観的に0円で公開すると、誰でも購入できる
				// 無料のvariationを作ってしまう金銭的リスクがある（CLAUDE.md参照）ため、
				// このvariantの作成/更新自体を見送りフェイルクローズする。
				$incomplete_snapshot = true;
				$warnings[]          = WarningCode::with_detail( WarningCode::VARIATION_PRICE_INVALID, $remote_id );
				continue;
			}

			$seen_remote_ids[]     = $remote_id;
			$existing_variation_id = $existing[ $remote_id ]['local_id'] ?? null;
			$warnings              = array_merge(
				$warnings,
				$this->sync_one( $product_id, $variant, $remote_id, $price, $axis_names, $existing_variation_id )
			);
		}

		// stale削除・variable同期の判定で二重に`wc_get_product()`しないよう、ここで一度だけ取得する。
		$product = wc_get_product( $product_id );

		if ( $incomplete_snapshot ) {
			$warnings[] = WarningCode::VARIATION_SNAPSHOT_INCOMPLETE;
		} elseif ( $product instanceof WC_Product_Variable ) {
			$warnings = array_merge( $warnings, $this->remove_stale_variations( $product, $seen_remote_ids ) );
		}

		if ( $product instanceof WC_Product_Variable ) {
			WC_Product_Variable::sync( $product_id );
		}

		return $warnings;
	}

	/**
	 * @param array<string,mixed> $variant
	 * @param array<int,string>   $axis_names
	 * @return array<int,string>
	 */
	private function sync_one( int $product_id, array $variant, string $remote_id, string $price, array $axis_names, ?int $existing_variation_id ): array {
		$warnings = [];

		// mappingsが指すvariation投稿が手動削除等で既に存在しない場合、`new WC_Product_Variation($id)`は
		// （`wc_get_product_object()`と異なり）例外を投げず、`WC_Product_Variation_Data_Store_CPT::read()`が
		// 早期リターンするだけで気付けない。その状態のまま`save()`すると存在しない投稿IDへの更新を
		// 試みて何も永続化されず、mappingだけが恒久的にstaleのまま残りこのvariantが二度と復旧しない
		// （TermWriter/CustomerWriter/ProductWriter/OrderWriterの同種のstale-ID対応と同じ方針）。
		if ( null !== $existing_variation_id && ! get_post( $existing_variation_id ) instanceof WP_Post ) {
			$existing_variation_id = null;
		}

		$variation = new WC_Product_Variation( $existing_variation_id ?? 0 );

		$variation->set_parent_id( $product_id );
		$variation->set_attributes( $this->variation_attributes( $variant, $axis_names ) );
		$variation->set_status( 'publish' );

		$variation->set_regular_price( $price );
		$variation->set_price( $price );

		StockApplier::apply( $variation, Value::int( $variant['stock'] ?? null ) );

		$weight = Value::int( $variant['weight'] ?? null );

		if ( null !== $weight ) {
			$variation->set_weight( WeightUnit::convert_from_grams( $weight ) );
		}

		$warnings = array_merge( $warnings, SkuGuard::apply( $variation, Value::string( $variant['sku'] ?? null ) ) );

		// 値がnullになった場合も`ExtrasMeta::apply()`経由でメタを削除する（更新のみで
		// 削除しないと、再実行時にvariantから値が消えても古い値のメタが残り続けてしまう）。
		ExtrasMeta::apply(
			$variation,
			[
				'few_num'                     => Value::int( $variant['few_num'] ?? null ),
				'cost'                        => Value::int( $variant['cost'] ?? null ),
				'members_price_including_tax' => Value::int( $variant['members_price_including_tax'] ?? null ),
				'market_price'                => Value::int( $variant['market_price'] ?? null ),
			]
		);

		$variation->update_meta_data( '_cbjp_platform', $this->platform );
		$variation->update_meta_data( '_cbjp_remote_id', $remote_id );

		$variation_id = $variation->save();

		if ( 0 === $variation_id ) {
			// `Importer`は`WriteResult::$local_id === 0`を「書けなかった」の意味として扱い
			// mappingsを書かない契約（穴Bの対処）だが、この保存は`ProductWriter`の
			// `WriteResult`とは別枠でここが直接upsertしているため、同じ契約をここでも
			// 明示的に守る必要がある。守らないとlocal_id=0のmapping行が残り、
			// StockWriter/ProductResolverの以後の解決が全て存在しない商品ID 0を指してしまう。
			$warnings[] = WarningCode::with_detail( WarningCode::VARIATION_SAVE_FAILED, $remote_id );

			return $warnings;
		}

		$this->mappings->upsert( $this->platform, 'variant', $remote_id, $variation_id, null );

		return $warnings;
	}

	/**
	 * @param array<string,mixed> $variant
	 * @param array<int,string>   $axis_names
	 * @return array<string,string>
	 */
	private function variation_attributes( array $variant, array $axis_names ): array {
		$attributes = [];

		if ( isset( $axis_names[0] ) ) {
			$value = Value::string( $variant['option1_value'] ?? null );

			if ( null !== $value ) {
				$attributes[ sanitize_title( $axis_names[0] ) ] = $value;
			}
		}

		if ( isset( $axis_names[1] ) ) {
			$value = Value::string( $variant['option2_value'] ?? null );

			if ( null !== $value ) {
				$attributes[ sanitize_title( $axis_names[1] ) ] = $value;
			}
		}

		return $attributes;
	}

	/**
	 * ASP側から消えたvariationを削除する（残すと価格レンジ・在庫状態がずれるため）。
	 *
	 * @param array<int,string> $seen_remote_ids
	 * @return array<int,string>
	 */
	private function remove_stale_variations( WC_Product_Variable $product, array $seen_remote_ids ): array {
		return $this->delete_variations( $product->get_children(), $seen_remote_ids );
	}

	/**
	 * 親商品がvariable→simpleへ型変更される場合、`$product->save()`の時点でWooCommerce自身が
	 * （`WC_Post_Data::product_type_changed()`経由で）子variationの投稿を強制削除する。
	 * そのため保存後に`get_posts()`で再検索しても対象は既に消えており、`cbjp_mappings`だけが
	 * 削除済みpost IDを指したまま残ってしまう。`ProductWriter::write()`は本メソッドを
	 * `$product->save()`より**前**に呼び、削除対象の(variation_id => remote_id)を確定させておく。
	 *
	 * @return array<int,string>
	 */
	public function find_owned_variation_remote_ids( int $product_id ): array {
		$variation_ids = get_posts(
			[
				'post_type'   => 'product_variation',
				'post_parent' => $product_id,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);

		// `get_posts(['fields' => 'ids'])`は投稿オブジェクトを構築しないためpostmetaキャッシュを
		// 温めない。温めないまま以下のループで`get_post_meta()`/`PlatformOwnership::owns_post()`を
		// 呼ぶとvariation毎に個別SELECTが発生するため、ここで一括プリロードする。
		update_meta_cache( 'post', $variation_ids );

		$remote_ids_by_variation_id = [];

		foreach ( $variation_ids as $variation_id ) {
			$remote_id = get_post_meta( $variation_id, '_cbjp_remote_id', true );

			if ( is_string( $remote_id ) && '' !== $remote_id && PlatformOwnership::owns_post( $variation_id, $this->platform ) ) {
				$remote_ids_by_variation_id[ $variation_id ] = $remote_id;
			}
		}

		return $remote_ids_by_variation_id;
	}

	/**
	 * `find_owned_variation_remote_ids()`で保存前に確定させた削除対象を掃除する。
	 * variation投稿自体は`$product->save()`の型変更処理で既にWooCommerceが削除済みのことが
	 * ほとんどだが、（`woocommerce_delete_variations_on_product_type_change`フィルターで
	 * 無効化されている等）投稿が残っているケースにも備えて`wc_get_product()`が実体を
	 * 返す場合のみ明示的に削除する。
	 *
	 * @param array<int,string> $remote_ids_by_variation_id
	 * @return array<int,string>
	 */
	public function remove_all( array $remote_ids_by_variation_id ): array {
		$warnings = [];

		foreach ( $remote_ids_by_variation_id as $variation_id => $remote_id ) {
			$warnings[] = $this->remove_variation( $variation_id, $remote_id );
		}

		return $warnings;
	}

	/**
	 * variation投稿の削除＋mapping掃除＋`VARIATION_REMOVED`警告の組み立てを1箇所に集約する
	 * （`remove_all()`/`delete_variations()`の呼び出し元でフィルタ条件が異なるだけで、
	 * 削除後処理の契約自体は共通のため）。
	 */
	private function remove_variation( int $variation_id, string $remote_id ): string {
		$variation = wc_get_product( $variation_id );

		if ( $variation instanceof WC_Product && get_post( $variation_id ) instanceof WP_Post ) {
			$variation->delete( true );
		}

		// mappingsを残すとremote_idが削除済みpost IDを指したままになり、ASP側で同じ
		// remote_idのバリエーションが復活した際に不整合を起こすため、削除に合わせて掃除する。
		$this->mappings->delete_one( $this->platform, 'variant', $remote_id );

		return WarningCode::with_detail( WarningCode::VARIATION_REMOVED, $remote_id );
	}

	/**
	 * `_cbjp_remote_id`メタを持たないvariation（このプラグイン外で作成されたもの）は対象外。
	 * `_cbjp_platform`が`$this->platform`と一致しないvariation（別プラットフォーム由来。
	 * リンク再構築ツール=D16等で複数プラットフォームが同一Woo商品を共有するケースを想定）も
	 * このWriterインスタンスの管轄外として対象外にする。
	 *
	 * @param array<int,int>    $variation_ids
	 * @param array<int,string> $seen_remote_ids
	 * @return array<int,string>
	 */
	private function delete_variations( array $variation_ids, array $seen_remote_ids ): array {
		$warnings = [];

		// `WC_Product_Variable::get_children()`はpostmetaキャッシュを温めないため、
		// 以下のループで`get_post_meta()`/`PlatformOwnership::owns_post()`を個別に呼ぶと
		// variation毎にSELECTが発生する。この経路は対象商品がvariableである限り
		// 商品を書き込む度に毎回通るため、ここで一括プリロードする。
		update_meta_cache( 'post', $variation_ids );

		foreach ( $variation_ids as $variation_id ) {
			$remote_id = get_post_meta( $variation_id, '_cbjp_remote_id', true );

			if ( ! is_string( $remote_id ) || '' === $remote_id || ! PlatformOwnership::owns_post( $variation_id, $this->platform ) || in_array( $remote_id, $seen_remote_ids, true ) ) {
				continue;
			}

			$warnings[] = $this->remove_variation( $variation_id, $remote_id );
		}

		return $warnings;
	}
}
