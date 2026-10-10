<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\EntityOrigin;
use WC_Admin_Duplicate_Product;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * D25（issue #98）「実体は作られた向きにだけ更新する」の判定。
 */
final class EntityOriginTest extends WooTestCase {

	private function product( ?string $platform ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'P' );
		$id = $product->save();

		if ( null !== $platform ) {
			update_post_meta( $id, '_cbjp_platform', $platform );
		}

		return $id;
	}

	public function test_a_post_is_linked_by_import_only_with_the_same_platform_marker(): void {
		$this->assertTrue( EntityOrigin::post_linked_by_import( $this->product( 'colorme' ), 'colorme' ) );
		$this->assertFalse( EntityOrigin::post_linked_by_import( $this->product( 'makeshop' ), 'colorme' ) );
		$this->assertFalse( EntityOrigin::post_linked_by_import( $this->product( null ), 'colorme' ) );
	}

	/**
	 * 空のプラットフォームIDは、印の無い実体（`get_post_meta()`が`''`を返す）と一致してしまうため、どの実体も取込みで結ばれていないとする。
	 */
	public function test_an_empty_platform_never_matches(): void {
		$product_id = $this->product( null );

		$this->assertFalse( EntityOrigin::post_linked_by_import( $product_id, '' ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( '', 'product', $product_id ) );
	}

	/**
	 * 実体があり取込みで結ばれていない実体だけがエクスポートで結ばれた（取込みで上書きしない）実体。商品は posts の行と
	 * 投稿タイプで実体の有無を見る（`wc_get_product_object()`が例外を投げる条件。メタだけが残る行は実体が無い）。
	 */
	public function test_is_linked_by_export_requires_an_existing_entity_without_the_import_marker(): void {
		global $wpdb;

		$woo_born = $this->product( null );
		$imported = $this->product( 'colorme' );
		$deleted  = $this->product( null );
		$wpdb->delete( $wpdb->posts, [ 'ID' => $deleted ] );
		clean_post_cache( $deleted );
		$page = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $woo_born );
		$variation_id = $variation->save();

		$this->assertTrue( EntityOrigin::is_linked_by_export( 'colorme', 'product', $woo_born ) );
		$this->assertTrue( EntityOrigin::is_linked_by_export( 'colorme', 'product', $variation_id ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'product', $imported ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'product', $deleted ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'product', $page ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'product', 999999 ) );
	}

	public function test_blocks_import_only_for_guarded_entities_with_a_mapping(): void {
		$woo_born = $this->product( null );

		$this->assertTrue( EntityOrigin::blocks_import( 'colorme', 'product', $woo_born ) );
		$this->assertFalse( EntityOrigin::blocks_import( 'colorme', 'product', null ) );
		$this->assertFalse( EntityOrigin::blocks_import( 'colorme', 'stock', $woo_born ) );
		$this->assertFalse( EntityOrigin::blocks_import( 'colorme', 'category', $woo_born ) );
	}

	/**
	 * 先に登録された他のコールバックが配列以外を返しても落ちず、紐づけのメタを足す。
	 */
	public function test_the_duplicate_filter_adds_the_link_meta_keys(): void {
		$this->assertSame( [ '_cbjp_platform', '_cbjp_remote_id' ], EntityOrigin::exclude_link_meta_on_duplicate( null ) );
		$this->assertSame( [ '_other', '_cbjp_platform', '_cbjp_remote_id' ], EntityOrigin::exclude_link_meta_on_duplicate( [ '_other' ] ) );
	}

	/**
	 * 店舗が取り込んだ商品を複製すると、WooCommerce は既定でメタをすべて写す。`Core\Plugin::boot()`が登録したフィルターで、
	 * 複製（商品とバリエーション）には紐づけのメタが付かず、取込みの印ではないメタはそのまま写る（WC の実際の複製で確認する）。
	 */
	public function test_duplicating_an_imported_product_does_not_copy_the_link_meta(): void {
		$this->assertNotFalse( has_filter( 'woocommerce_duplicate_product_exclude_meta', [ EntityOrigin::class, 'exclude_link_meta_on_duplicate' ] ) );

		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'size' );
		$attribute->set_options( [ 'S' ] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$product = new WC_Product_Variable();
		$product->set_name( 'Imported' );
		$product->set_attributes( [ $attribute ] );
		$product->update_meta_data( '_cbjp_platform', 'colorme' );
		$product->update_meta_data( '_cbjp_remote_id', '100' );
		$product->update_meta_data( '_cbjp_few_num', '3' );
		$product_id = $product->save();

		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes( [ 'size' => 'S' ] );
		$variation->update_meta_data( '_cbjp_platform', 'colorme' );
		$variation->update_meta_data( '_cbjp_remote_id', '100-1' );
		$variation->save();

		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-duplicate-product.php';
		$duplicate = ( new WC_Admin_Duplicate_Product() )->product_duplicate( wc_get_product( $product_id ) );

		$this->assertNotSame( $product_id, $duplicate->get_id() );
		$this->assertSame( '', get_post_meta( $duplicate->get_id(), '_cbjp_platform', true ) );
		$this->assertSame( '', get_post_meta( $duplicate->get_id(), '_cbjp_remote_id', true ) );
		$this->assertSame( '3', get_post_meta( $duplicate->get_id(), '_cbjp_few_num', true ) );
		$this->assertFalse( EntityOrigin::post_linked_by_import( $duplicate->get_id(), 'colorme' ) );

		$children = $duplicate->get_children();
		$this->assertCount( 1, $children );
		$this->assertSame( '', get_post_meta( $children[0], '_cbjp_platform', true ) );
		$this->assertSame( '', get_post_meta( $children[0], '_cbjp_remote_id', true ) );

		// 元の商品の印は残る。
		$this->assertTrue( EntityOrigin::post_linked_by_import( $product_id, 'colorme' ) );
	}
}
