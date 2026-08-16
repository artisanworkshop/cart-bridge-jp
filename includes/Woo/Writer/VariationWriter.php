<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use WC_Data_Exception;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

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

			$seen_remote_ids[] = $remote_id;
			$warnings          = array_merge( $warnings, $this->sync_one( $product_id, $variant, $remote_id, $price, $axis_names ) );
		}

		if ( $incomplete_snapshot ) {
			$warnings[] = WarningCode::VARIATION_SNAPSHOT_INCOMPLETE;
		} else {
			$warnings = array_merge( $warnings, $this->remove_stale_variations( $product_id, $seen_remote_ids ) );
		}

		$product = wc_get_product( $product_id );

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
	private function sync_one( int $product_id, array $variant, string $remote_id, string $price, array $axis_names ): array {
		$warnings              = [];
		$existing_variation_id = $this->mappings->find_local_id( $this->platform, 'variant', $remote_id );
		$variation             = new WC_Product_Variation( $existing_variation_id ?? 0 );

		$variation->set_parent_id( $product_id );
		$variation->set_attributes( $this->variation_attributes( $variant, $axis_names ) );
		$variation->set_status( 'publish' );

		$variation->set_regular_price( $price );
		$variation->set_price( $price );

		$stock = Value::int( $variant['stock'] ?? null );

		if ( null === $stock ) {
			$variation->set_manage_stock( false );
			$variation->set_stock_status( 'instock' );
		} else {
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $stock );
			$variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
		}

		$weight = Value::int( $variant['weight'] ?? null );

		if ( null !== $weight ) {
			$unit = get_option( 'woocommerce_weight_unit', 'kg' );
			$variation->set_weight( (string) wc_get_weight( $weight, is_string( $unit ) && '' !== $unit ? $unit : 'kg', 'g' ) );
		}

		$sku = Value::string( $variant['sku'] ?? null ) ?? '';

		if ( '' !== $sku ) {
			$conflict = wc_get_product_id_by_sku( $sku );

			if ( 0 !== $conflict && $conflict !== $variation->get_id() ) {
				$variation->update_meta_data( '_cbjp_original_sku', $sku );
				$warnings[] = WarningCode::with_detail( WarningCode::SKU_DUPLICATE, $sku );
				$sku        = '';
			}
		}

		try {
			$variation->set_sku( $sku );
		} catch ( WC_Data_Exception ) {
			$variation->update_meta_data( '_cbjp_original_sku', $sku );
			$variation->set_sku( '' );
			$warnings[] = WarningCode::with_detail( WarningCode::SKU_DUPLICATE, $sku );
		}

		foreach ( [ 'few_num', 'cost', 'members_price_including_tax', 'market_price' ] as $key ) {
			$value = Value::int( $variant[ $key ] ?? null );

			if ( null !== $value ) {
				$variation->update_meta_data( "_cbjp_{$key}", (string) $value );
			}
		}

		$variation->update_meta_data( '_cbjp_platform', $this->platform );
		$variation->update_meta_data( '_cbjp_remote_id', $remote_id );

		$variation_id = $variation->save();
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
	 * `_cbjp_remote_id`メタを持たないvariation（このプラグイン外で作成されたもの）は対象外。
	 * `_cbjp_platform`が`$this->platform`と一致しないvariation（別プラットフォーム由来。
	 * リンク再構築ツール=D16等で複数プラットフォームが同一Woo商品を共有するケースを想定）も
	 * このWriterインスタンスの管轄外として対象外にする。
	 *
	 * @param array<int,string> $seen_remote_ids
	 * @return array<int,string>
	 */
	private function remove_stale_variations( int $product_id, array $seen_remote_ids ): array {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product_Variable ) {
			return [];
		}

		$warnings = [];

		foreach ( $product->get_children() as $variation_id ) {
			$remote_id = get_post_meta( $variation_id, '_cbjp_remote_id', true );
			$platform  = get_post_meta( $variation_id, '_cbjp_platform', true );

			if ( ! is_string( $remote_id ) || '' === $remote_id || $platform !== $this->platform || in_array( $remote_id, $seen_remote_ids, true ) ) {
				continue;
			}

			$variation = wc_get_product( $variation_id );

			if ( $variation instanceof WC_Product ) {
				$variation->delete( true );
			}

			// mappingsを残すとremote_idが削除済みpost IDを指したままになり、ASP側で同じ
			// remote_idのバリエーションが復活した際に不整合を起こすため、削除に合わせて掃除する。
			$this->mappings->delete_one( $this->platform, 'variant', $remote_id );

			$warnings[] = WarningCode::with_detail( WarningCode::VARIATION_REMOVED, $remote_id );
		}

		return $warnings;
	}
}
