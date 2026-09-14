<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\CouponReader;
use CartBridgeJP\Woo\WarningCode;
use WC_Coupon;

final class CouponReaderTest extends WooTestCase {

	private function make_reader(): CouponReader {
		return new CouponReader();
	}

	private function create_coupon( string $code, string $discount_type = 'fixed_cart', string $amount = '500' ): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $discount_type );
		$coupon->set_amount( $amount );
		$coupon->save();

		return $coupon;
	}

	/**
	 * `WC_Coupon::save()`は新規作成（ID未確定）時のみ、状態プロパティが未設定（`null`）なら
	 * `post_status`を`'publish'`へフォールバックする（`WC_Coupon_Data_Store_CPT::create()`）。
	 * 2回目以降の`save()`（更新）は`$coupon->get_status('edit')`（インメモリの値）をそのまま
	 * `wp_update_post()`へ渡すため、`set_status()`を一度も呼んでいないインスタンスへ追加の
	 * setterを重ねて2回目の`save()`をすると`post_status`が`null`扱いになり、WordPress core側の
	 * 既定（`draft`）へ静かに落ちる（実測確認済み）。全フィールドを1回の`save()`で確定させ、
	 * この罠を踏まないようにする。
	 */
	public function test_reads_core_fields_with_no_restrictions(): void {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'SAVE10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$coupon->set_free_shipping( true );
		$coupon->set_usage_limit( 5 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_minimum_amount( '1000' );
		$coupon->set_description( 'Ten percent off' );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertCount( 1, $page->items );

		$item      = $page->items[0];
		$canonical = $item->item;

		$this->assertSame( $coupon->get_id(), $item->local_id );
		$this->assertSame( [], $item->warnings );
		// WC標準の挙動として、保存されたクーポンコードは小文字へ正規化される。
		$this->assertSame( 'save10', $canonical->code );
		$this->assertSame( 'percent', $canonical->type );
		$this->assertSame( 10.0, (float) $canonical->amount );
		$this->assertTrue( $canonical->free_shipping );
		$this->assertSame( 5, $canonical->usage_limit );
		$this->assertSame( 1, $canonical->usage_limit_per_user );
		$this->assertSame( 1000.0, (float) $canonical->min_amount );
		$this->assertSame( 'Ten percent off', $canonical->extras['name'] );
		$this->assertFalse( $canonical->has_unsupported_restrictions );
	}

	public function test_fixed_cart_maps_to_fixed_type(): void {
		$coupon = $this->create_coupon( 'FIXED5', 'fixed_cart', '500' );

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertSame( 'fixed', $page->items[0]->item->type );
		$this->assertFalse( $page->items[0]->item->has_unsupported_restrictions );
	}

	/**
	 * `fixed_product`（商品単位の定額値引き）は`CanonicalCoupon::$type`の`fixed`（カート単位）へ
	 * 無警告で丸めると割引の効き方が変わる金銭的リスクがあるため、`has_unsupported_restrictions`で
	 * 保存見送りに倒す（実装計画D参照）。
	 */
	public function test_fixed_product_type_marks_unsupported_restrictions_and_blocks_export(): void {
		$coupon = $this->create_coupon( 'PRODFIX', 'fixed_product', '500' );

		$page      = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$read_item = $page->items[0];

		$this->assertTrue( $read_item->item->has_unsupported_restrictions );
		$this->assertContains( WarningCode::COUPON_RESTRICTIONS_UNSUPPORTED, $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	public function test_native_product_restriction_marks_unsupported(): void {
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$coupon     = $this->create_coupon( 'PRODONLY' );
		$coupon->set_product_ids( [ $product_id ] );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_native_category_restriction_marks_unsupported(): void {
		$term   = wp_insert_term( 'Shirts', 'product_cat' );
		$coupon = $this->create_coupon( 'CATONLY' );
		$coupon->set_product_categories( [ (int) $term['term_id'] ] );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_native_email_restriction_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'EMAILONLY' );
		$coupon->set_email_restrictions( [ 'vip@example.com' ] );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_maximum_amount_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'MAXAMOUNT' );
		$coupon->set_maximum_amount( '5000' );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}

	public function test_query_walks_multiple_pages(): void {
		$ids = [];

		for ( $i = 0; $i < 22; $i++ ) {
			$ids[] = $this->create_coupon( "CODE{$i}" )->get_id();
		}

		$reader     = $this->make_reader();
		$first_page = $reader->query( Cursor::start(), null );

		$this->assertCount( 20, $first_page->items );
		$this->assertNotNull( $first_page->next_cursor );

		$second_page = $reader->query( $first_page->next_cursor, null );

		$this->assertCount( 2, $second_page->items );
		$this->assertNull( $second_page->next_cursor );

		$all_ids = array_merge(
			array_map( static fn ( $item ) => $item->local_id, $first_page->items ),
			array_map( static fn ( $item ) => $item->local_id, $second_page->items )
		);
		sort( $all_ids );
		sort( $ids );
		$this->assertSame( $ids, $all_ids );
	}
}
