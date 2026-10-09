<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * variable商品・バリエーションを`WC_Product`のCRUDで直接作るテスト用ファクトリ
 * （D22の在庫管理の混在・D23の「Any」バリエーションのテストで、`ProductReader`/`StockReader`/
 * `OrderReader`のテストが共有する）。Writer経由（`ProductWriter`）ではAnyや在庫管理の混在を
 * 作れないため、Wooの実データ形式を直接作る。
 */
final class VariableProductFactory {

	private function __construct() {}

	/**
	 * variation属性（軸）を持つvariable親を作る。
	 *
	 * @param array<string,array<int,string>> $axes 属性名（ローカル属性。キーの順が軸1・軸2）=>選択肢。
	 * @return int 親商品ID。
	 */
	public static function create_parent( string $name, array $axes = [ 'Size' => [ 'S', 'M', 'L' ] ] ): int {
		$parent     = new WC_Product_Variable();
		$attributes = [];
		$position   = 0;

		foreach ( $axes as $attribute_name => $options ) {
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( $attribute_name );
			$attribute->set_options( $options );
			$attribute->set_position( $position++ );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}

		$parent->set_name( $name );
		$parent->set_attributes( $attributes );

		return $parent->save();
	}

	/**
	 * バリエーションを1件作る。
	 *
	 * @param array<string,string> $attributes `WC_Product_Variation::set_attributes()`の形（属性キー=>値。
	 *   値が空文字列なら「Any」）。
	 * @param array{price?:string,manage_stock?:bool,stock_quantity?:int,stock_status?:string,status?:string} $args
	 *   `manage_stock`を省略すると個別管理しない（`false`）。`stock_quantity`は`manage_stock=true`のときだけ使う。
	 * @return int バリエーションID。
	 */
	public static function add_variation( int $parent_id, array $attributes, array $args = [] ): int {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( $attributes );
		$variation->set_regular_price( $args['price'] ?? '1000' );
		$variation->set_status( $args['status'] ?? 'publish' );
		$variation->set_manage_stock( $args['manage_stock'] ?? false );

		if ( true === ( $args['manage_stock'] ?? false ) ) {
			$variation->set_stock_quantity( $args['stock_quantity'] ?? 0 );
		}

		$variation->set_stock_status( $args['stock_status'] ?? 'instock' );

		return $variation->save();
	}
}
