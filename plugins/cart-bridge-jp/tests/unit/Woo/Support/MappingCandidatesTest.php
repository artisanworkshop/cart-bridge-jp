<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\MappingCandidates;
use WP_UnitTestCase;

final class MappingCandidatesTest extends WP_UnitTestCase {

	public function test_categories_lists_product_cat_terms(): void {
		$term = wp_insert_term( 'Mapping Candidates Category', 'product_cat' );
		$this->assertIsArray( $term );

		$candidates = MappingCandidates::categories();
		$ids        = array_column( $candidates, 'id' );

		$this->assertContains( (string) $term['term_id'], $ids );
		$names = array_combine( $ids, array_column( $candidates, 'name' ) );
		$this->assertSame( 'Mapping Candidates Category', $names[ (string) $term['term_id'] ] );
	}

	/**
	 * `get_terms()`は保存時にエンティティ化された生の値（例: `Men &amp; Women`）をそのまま返す。
	 * このJSON APIの結果はReactのテキストノードへ渡るため、デコードせずに返すと
	 * ブラウザ上で「Men &amp; Women」と文字どおり二重エスケープ表示されてしまう（G3指摘）。
	 */
	public function test_categories_decodes_html_entities_in_names(): void {
		$term = wp_insert_term( 'Men & Women', 'product_cat' );
		$this->assertIsArray( $term );

		$candidates = MappingCandidates::categories();
		$names      = array_combine( array_column( $candidates, 'id' ), array_column( $candidates, 'name' ) );

		$this->assertSame( 'Men & Women', $names[ (string) $term['term_id'] ] );
	}
}
