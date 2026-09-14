<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use CartBridgeJP\Woo\WarningCode;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_Term;

/**
 * variation属性（軸）とバリエーション毎の値（option1/2）を解決する（`Woo\Reader\ProductReader`の
 * バリエーション変換と`Woo\Reader\OrderReader`の受注明細変換の両方が同じ規約を共有するための
 * 切り出し。`Woo\Support\StockDerivation`と同じ理由）。`CanonicalProduct::$variants`の
 * option1/2規約（2軸まで、キー0=軸1・キー1=軸2のスロット）に合わせる。
 */
final class VariationAxisResolver {

	private function __construct() {}

	/**
	 * variation属性（軸）の一覧。3軸目以降は警告のうえ切り捨てる（Codex/Copilot指摘, PR #40:
	 * 無警告の切り捨ては異なる3軸目の値を持つバリエーション同士が同じoption1/2の組に潰れうる）。
	 *
	 * @param array<int,string> $warnings 呼び出し元と共有する警告配列。
	 * @return array<int,WC_Product_Attribute>
	 */
	public static function axis_attributes( WC_Product_Variable $product, array &$warnings ): array {
		$axis              = [];
		$has_axis_overflow = false;

		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof WC_Product_Attribute && $attribute->get_variation() ) {
				if ( 2 === count( $axis ) ) {
					$has_axis_overflow = true;
					break;
				}

				$axis[] = $attribute;
			}
		}

		if ( $has_axis_overflow ) {
			$warnings[] = WarningCode::VARIATION_AXIS_LIMIT_EXCEEDED;
		}

		return $axis;
	}

	public static function attribute_label( WC_Product_Attribute $attribute ): string {
		return $attribute->is_taxonomy() ? wc_attribute_label( $attribute->get_name() ) : $attribute->get_name();
	}

	/**
	 * @param array<string,string> $raw_attributes `WC_Product_Variation::get_attributes()`
	 *   （taxonomy属性はterm slug、ローカル属性は生値）。
	 */
	public static function attribute_value( WC_Product_Attribute $attribute, array $raw_attributes ): ?string {
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
	 * 指定バリエーションのoption1/2値（軸属性の値ラベル）。軸が1つしか無い場合、2つ目は`null`。
	 *
	 * @param array<int,WC_Product_Attribute> $axis_attributes
	 * @return array{0:?string,1:?string}
	 */
	public static function option_values( WC_Product_Variation $variation, array $axis_attributes ): array {
		$raw_attributes = $variation->get_attributes();
		$values         = [ null, null ];

		foreach ( [ 0, 1 ] as $index ) {
			$attribute = $axis_attributes[ $index ] ?? null;

			if ( null === $attribute ) {
				continue;
			}

			$values[ $index ] = self::attribute_value( $attribute, $raw_attributes );
		}

		return $values;
	}
}
