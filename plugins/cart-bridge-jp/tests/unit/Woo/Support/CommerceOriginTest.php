<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\CommerceOrigin;
use CartBridgeJP\Woo\Support\EntityOrigin;
use WC_Coupon;
use WC_Product_Simple;

/**
 * D25（issue #98）の顧客（WP ユーザー）・受注・クーポンの判定（R3-6c1 で `EntityOriginTest` から分けた）。
 */
final class CommerceOriginTest extends WooTestCase {

	/**
	 * 空のプラットフォームIDは、印の無い実体と一致してしまうため、どの実体も取込みで結ばれていないとする。
	 */
	public function test_an_empty_platform_never_matches(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'customer' ] );

		$this->assertFalse( CommerceOrigin::user_linked_by_import( $user_id, '' ) );
		$this->assertFalse( CommerceOrigin::order_linked_by_import( wc_create_order(), '' ) );
	}

	public function test_a_user_is_linked_by_import_with_the_link_or_the_creation_marker(): void {
		$adopted = self::factory()->user->create( [ 'role' => 'customer' ] );
		update_user_meta( $adopted, '_cbjp_platform', 'colorme' );

		$created = self::factory()->user->create( [ 'role' => 'customer' ] );
		update_user_meta( $created, '_cbjp_platform', 'makeshop' );
		update_user_meta( $created, '_cbjp_created_by_import', 'colorme' );

		$other = self::factory()->user->create( [ 'role' => 'customer' ] );
		update_user_meta( $other, '_cbjp_platform', 'makeshop' );

		$this->assertTrue( CommerceOrigin::user_linked_by_import( $adopted, 'colorme' ) );
		$this->assertTrue( CommerceOrigin::user_linked_by_import( $created, 'colorme' ) );
		$this->assertFalse( CommerceOrigin::user_linked_by_import( $other, 'colorme' ) );
	}

	public function test_an_order_is_linked_by_import_with_the_same_platform_marker(): void {
		$imported = wc_create_order();
		$imported->update_meta_data( '_cbjp_platform', 'colorme' );
		$imported->save();

		$this->assertTrue( CommerceOrigin::order_linked_by_import( wc_get_order( $imported->get_id() ), 'colorme' ) );
		$this->assertFalse( CommerceOrigin::order_linked_by_import( wc_create_order(), 'colorme' ) );
	}

	public function test_is_linked_by_export_for_coupons_customers_and_orders(): void {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'origin-coupon' );
		$coupon_id = $coupon->save();

		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$admin_id    = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$order_id    = wc_create_order()->get_id();

		$this->assertTrue( EntityOrigin::is_linked_by_export( 'colorme', 'coupon', $coupon_id ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'coupon', $this->product() ), 'not a coupon post' );
		$this->assertTrue( EntityOrigin::is_linked_by_export( 'colorme', 'customer', $customer_id ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'customer', $admin_id ), 'protected roles are left to CustomerWriter' );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'customer', 999999 ) );
		$this->assertTrue( EntityOrigin::is_linked_by_export( 'colorme', 'order', $order_id ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'order', 999999 ) );
		$this->assertFalse( EntityOrigin::is_linked_by_export( 'colorme', 'category', $coupon_id ), 'unknown entities are never judged' );
	}

	private function product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'P' );

		return $product->save();
	}
}
