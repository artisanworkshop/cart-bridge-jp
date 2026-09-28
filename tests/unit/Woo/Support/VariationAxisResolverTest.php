<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Tests\Fixtures\VariableProductFactory;
use CartBridgeJP\Woo\Support\VariationAxisResolver;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

final class VariationAxisResolverTest extends WP_UnitTestCase {

	/**
	 * @param array<int,\WC_Product_Attribute> $axes
	 */
	private function axes( int $parent_id ): array {
		$parent = wc_get_product( $parent_id );
		$this->assertInstanceOf( WC_Product_Variable::class, $parent );

		$warnings = [];

		return VariationAxisResolver::axis_attributes( $parent, $warnings );
	}

	private function variation( int $variation_id ): WC_Product_Variation {
		$variation = wc_get_product( $variation_id );
		$this->assertInstanceOf( WC_Product_Variation::class, $variation );

		return $variation;
	}

	/**
	 * D23: Wooは軸を「Any」にしたバリエーションの属性値を空文字列で保存する（実測確認済み）。
	 */
	public function test_an_empty_attribute_value_is_any(): void {
		$parent_id = VariableProductFactory::create_parent( 'Any shirt', [ 'Size' => [ 'S', 'M' ] ] );
		$any_id    = VariableProductFactory::add_variation( $parent_id, [ 'size' => '' ] );
		$s_id      = VariableProductFactory::add_variation( $parent_id, [ 'size' => 'S' ] );

		$axes = $this->axes( $parent_id );

		$this->assertTrue( VariationAxisResolver::has_any_attribute( $this->variation( $any_id ), $axes ) );
		$this->assertFalse( VariationAxisResolver::has_any_attribute( $this->variation( $s_id ), $axes ) );
	}

	/**
	 * 2軸のうち1軸だけが「Any」でも該当する（軸1が具体的な値でも軸2がAnyなら、どの組の注文か決まらない）。
	 */
	public function test_any_on_either_of_two_axes_is_detected(): void {
		$parent_id = VariableProductFactory::create_parent(
			'Two axes',
			[
				'Size'  => [ 'S', 'M' ],
				'Color' => [ 'Red', 'Blue' ],
			]
		);
		$axes      = $this->axes( $parent_id );

		$any_second = VariableProductFactory::add_variation(
			$parent_id,
			[
				'size'  => 'S',
				'color' => '',
			]
		);
		$any_first  = VariableProductFactory::add_variation(
			$parent_id,
			[
				'size'  => '',
				'color' => 'Red',
			]
		);
		$concrete   = VariableProductFactory::add_variation(
			$parent_id,
			[
				'size'  => 'M',
				'color' => 'Blue',
			]
		);

		$this->assertTrue( VariationAxisResolver::has_any_attribute( $this->variation( $any_second ), $axes ) );
		$this->assertTrue( VariationAxisResolver::has_any_attribute( $this->variation( $any_first ), $axes ) );
		$this->assertFalse( VariationAxisResolver::has_any_attribute( $this->variation( $concrete ), $axes ) );
	}

	/**
	 * taxonomy属性でも空文字列（term slugが空）が「Any」になる（`attribute_value()`は
	 * ローカル属性と同じく空文字列をnullにする）。
	 */
	public function test_an_empty_taxonomy_attribute_value_is_any(): void {
		$attribute_id = wc_create_attribute(
			[
				'name'         => 'Vartest Color',
				'slug'         => 'vartestcolor',
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			]
		);
		$this->assertIsInt( $attribute_id );

		$taxonomy = 'pa_vartestcolor';
		register_taxonomy( $taxonomy, 'product', [ 'hierarchical' => false ] );

		try {
			$red = wp_insert_term( 'Vartest Red', $taxonomy, [ 'slug' => 'vartest-red' ] );
			$this->assertIsArray( $red );

			$parent = new WC_Product_Variable();
			$parent->set_name( 'Taxonomy any' );
			$attribute = new \WC_Product_Attribute();
			$attribute->set_id( $attribute_id );
			$attribute->set_name( $taxonomy );
			$attribute->set_options( [ (int) $red['term_id'] ] );
			$attribute->set_position( 0 );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$parent->set_attributes( [ $attribute ] );
			$parent_id = $parent->save();
			wp_set_object_terms( $parent_id, [ 'vartest-red' ], $taxonomy );

			$any_id      = VariableProductFactory::add_variation( $parent_id, [ $taxonomy => '' ] );
			$concrete_id = VariableProductFactory::add_variation( $parent_id, [ $taxonomy => 'vartest-red' ] );

			$axes = $this->axes( $parent_id );
			$this->assertTrue( $axes[0]->is_taxonomy(), '前提: taxonomy属性の軸として解決されている' );

			$this->assertTrue( VariationAxisResolver::has_any_attribute( $this->variation( $any_id ), $axes ) );
			$this->assertFalse( VariationAxisResolver::has_any_attribute( $this->variation( $concrete_id ), $axes ) );
		} finally {
			// 属性を先に消す。taxonomyが登録されている間でないとターム削除が行われないため。
			wc_delete_attribute( $attribute_id );
			unregister_taxonomy( $taxonomy );
		}
	}

	public function test_no_axes_means_no_any(): void {
		$parent_id = VariableProductFactory::create_parent( 'No axes', [] );
		$variation = VariableProductFactory::add_variation( $parent_id, [] );

		$this->assertFalse( VariationAxisResolver::has_any_attribute( $this->variation( $variation ), [] ) );
	}
}
