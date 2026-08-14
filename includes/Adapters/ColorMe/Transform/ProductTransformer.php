<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe\Transform;

use CartBridgeJP\Canonical\CanonicalProduct;

/**
 * `GET /v1/products.json` `GET /v1/products/{id}.json` の1要素を `CanonicalProduct` へ変換する。
 * マッピングの詳細は `docs/01-plan-colorme.md` §4。
 */
final class ProductTransformer {

	/**
	 * @param array<string,mixed> $raw `products[]` の1要素、または `product` 単体。
	 */
	public function transform( array $raw ): CanonicalProduct {
		$remote_id = Cast::to_string_or_null( $raw['id'] ?? null ) ?? '';

		return new CanonicalProduct(
			Cast::to_string_or_null( $raw['name'] ?? null ) ?? '',
			$this->sku( $raw, $remote_id ),
			Cast::money( $raw['sales_price_including_tax'] ?? null ),
			null,
			Cast::sanitize_html( $raw['expl'] ?? null ),
			$this->images( $raw ),
			$this->variants( $raw, $remote_id ),
			$this->options( $raw ),
			$this->category_refs( $raw ),
			Cast::to_int_or_null( $raw['stocks'] ?? null ),
			$this->status( $raw ),
			$this->extras( $raw, $remote_id )
		);
	}

	/**
	 * `display_state` の4値のうち `sale_for_members`（掲載状態だが購入は会員のみ可能）は
	 * `showing` と同じく一般公開の掲載状態であり、`private` にすると誰にも見えなくなってしまう
	 * （swagger: 「掲載状態だが購入は会員のみ可能」）。購入制限自体はWoo標準機能で表現できないため
	 * extras の生の `display_state` に委ね、ここではWooの掲載可否のみを判定する。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function status( array $raw ): string {
		$display_state = $raw['display_state'] ?? null;

		return in_array( $display_state, [ 'showing', 'sale_for_members' ], true ) ? 'publish' : 'private';
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function sku( array $raw, string $remote_id ): string {
		$model_number = Cast::to_string_or_null( $raw['model_number'] ?? null );

		return null !== $model_number ? $model_number : "colorme-{$remote_id}";
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function images( array $raw ): array {
		$images = [];

		$main_image = Cast::to_string_or_null( $raw['image_url'] ?? null );

		if ( null !== $main_image ) {
			$images[] = [
				'src'      => $main_image,
				'position' => 0,
			];
		}

		$extra_images = $raw['images'] ?? [];

		if ( is_array( $extra_images ) ) {
			foreach ( $extra_images as $image ) {
				// `mobile: true` の項目はPC用画像と同一内容を指すモバイル向けの重複エントリのため除外する。
				if ( ! is_array( $image ) || true === ( $image['mobile'] ?? null ) ) {
					continue;
				}

				$src = Cast::to_string_or_null( $image['src'] ?? null );

				if ( null === $src ) {
					continue;
				}

				$images[] = [
					'src'      => $src,
					'position' => Cast::to_int_or_null( $image['position'] ?? null ),
				];
			}
		}

		return $images;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function variants( array $raw, string $product_remote_id ): array {
		$variants = $raw['variants'] ?? [];

		if ( ! is_array( $variants ) ) {
			return [];
		}

		$product_price = Cast::money( $raw['sales_price_including_tax'] ?? null );
		$result        = [];

		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}

			$variant_remote_id = Cast::to_string_or_null( $variant['id'] ?? null ) ?? '';
			$model_number      = Cast::to_string_or_null( $variant['model_number'] ?? null );
			$price             = Cast::to_string_or_null( $variant['option_price_including_tax'] ?? null );

			$result[] = [
				'remote_id'                   => $variant_remote_id,
				'sku'                         => null !== $model_number ? $model_number : "colorme-{$product_remote_id}-{$variant_remote_id}",
				'option1_name'                => Cast::to_string_or_null( $variant['option1']['name'] ?? null ),
				'option1_value'               => Cast::to_string_or_null( $variant['option1']['value'] ?? $variant['option1_value'] ?? null ),
				'option2_name'                => Cast::to_string_or_null( $variant['option2']['name'] ?? null ),
				'option2_value'               => Cast::to_string_or_null( $variant['option2']['value'] ?? $variant['option2_value'] ?? null ),
				'price'                       => null !== $price ? $price : $product_price,
				'stock'                       => Cast::to_int_or_null( $variant['stocks'] ?? null ),
				'weight'                      => Cast::to_int_or_null( $variant['weight'] ?? null ),
				// バリエーション別の上書き値。商品レベルの同種項目はextrasに退避済み（few_num/cost/
				// members_price_including_tax）だが、ここではバリエーションごとの値をそのまま保持する。
				'few_num'                     => Cast::to_int_or_null( $variant['few_num'] ?? null ),
				'cost'                        => Cast::to_int_or_null( $variant['option_cost'] ?? null ),
				'members_price_including_tax' => Cast::to_int_or_null( $variant['option_members_price_including_tax'] ?? null ),
			];
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,array<string,mixed>>
	 */
	private function options( array $raw ): array {
		$options = $raw['options'] ?? [];

		if ( ! is_array( $options ) ) {
			return [];
		}

		$result = [];

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$values = $option['values'] ?? [];

			$result[] = [
				'name'   => Cast::to_string_or_null( $option['name'] ?? null ) ?? '',
				'values' => is_array( $values ) ? Cast::strings( $values ) : [],
			];
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,string>
	 */
	private function category_refs( array $raw ): array {
		$category = $raw['category'] ?? null;

		if ( ! is_array( $category ) ) {
			return [];
		}

		$ref = Cast::category_ref( $category['id_big'] ?? null, $category['id_small'] ?? null );

		return null !== $ref ? [ $ref ] : [];
	}

	/**
	 * ASP固有フィールドの退避先。`account_id`・`make_date`・`update_date`（ネスト含む）は
	 * checksumを内容ベースに保つため含めない。
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function extras( array $raw, string $remote_id ): array {
		return [
			'remote_id'                   => $remote_id,
			'display_state'               => Cast::to_string_or_null( $raw['display_state'] ?? null ),
			// `simple_expl`（簡易説明）は `CanonicalProduct` に専用フィールドが無いためextras経由でWoo抜粋に渡す。
			'short_description'           => Cast::sanitize_html( $raw['simple_expl'] ?? null ),
			// `CanonicalProduct` にタグ参照フィールドが無いため、グループIDはextras経由でF1-4がタグ紐付けに使う。
			'group_ids'                   => Cast::strings( is_array( $raw['group_ids'] ?? null ) ? $raw['group_ids'] : [] ),
			// 定価（税抜/税込どちらか不明）。Transformerはショップの税設定を持たないため税込換算を作らずそのまま退避する。
			'list_price'                  => Cast::to_int_or_null( $raw['price'] ?? null ),
			'members_price_including_tax' => Cast::to_int_or_null( $raw['members_price_including_tax'] ?? null ),
			'tax_reduced'                 => Cast::to_bool_or_null( $raw['tax_reduced'] ?? null ),
			'stock_managed'               => Cast::to_bool_or_null( $raw['stock_managed'] ?? null ),
			'few_num'                     => Cast::to_int_or_null( $raw['few_num'] ?? null ),
			'weight'                      => Cast::to_int_or_null( $raw['weight'] ?? null ),
			'unit'                        => Cast::to_string_or_null( $raw['unit'] ?? null ),
			'min_num'                     => Cast::to_int_or_null( $raw['min_num'] ?? null ),
			'max_num'                     => Cast::to_int_or_null( $raw['max_num'] ?? null ),
			// 商品一覧での表示順（数値が小さいほど先頭）。F1-4がWooのmenu_orderへマッピングする想定。
			'sort'                        => Cast::to_int_or_null( $raw['sort'] ?? null ),
			'cost'                        => Cast::to_int_or_null( $raw['cost'] ?? null ),
			'delivery_charge'             => Cast::to_int_or_null( $raw['delivery_charge'] ?? null ),
			'cool_charge'                 => Cast::to_int_or_null( $raw['cool_charge'] ?? null ),
			'unavailable_payment_ids'     => Cast::strings( is_array( $raw['unavailable_payment_ids'] ?? null ) ? $raw['unavailable_payment_ids'] : [] ),
			'unavailable_delivery_ids'    => Cast::strings( is_array( $raw['unavailable_delivery_ids'] ?? null ) ? $raw['unavailable_delivery_ids'] : [] ),
			'memo'                        => Cast::to_string_or_null( $raw['memo'] ?? null ),
			'sale_start_date'             => Cast::unix_to_iso( $raw['sale_start_date'] ?? null ),
			'sale_end_date'               => Cast::unix_to_iso( $raw['sale_end_date'] ?? null ),
			'soldout_display'             => Cast::to_bool_or_null( $raw['soldout_display'] ?? null ),
			'without_shipping'            => Cast::to_bool_or_null( $raw['without_shipping'] ?? null ),
			'digital_content'             => Cast::to_bool_or_null( $raw['digital_content'] ?? null ),
			'regular_purchase'            => Cast::to_bool_or_null( $raw['regular_purchase'] ?? null ),
			// 「限定公開」フラグ（display_stateとは独立）。Wooのカタログ表示設定（visible/hidden等）に
			// マッピングする想定でF1-4向けに退避する。
			'unlisted'                    => Cast::to_bool_or_null( $raw['unlisted'] ?? null ),
		];
	}
}
