<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\CouponWriter;

final class CouponWriterTest extends WooTestCase {

	private function make_writer(): CouponWriter {
		return new CouponWriter( 'colorme' );
	}

	public function test_fixed_type_maps_to_fixed_cart(): void {
		$coupon = new CanonicalCoupon( 'SAVE500', 'fixed', '500', null, '2026-12-31', 10, [ 'remote_id' => '1' ] );

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );

		$wc_coupon = new \WC_Coupon( $result->local_id );
		$this->assertSame( 'fixed_cart', $wc_coupon->get_discount_type() );
		$this->assertSame( '500', $wc_coupon->get_amount() );
		$this->assertSame( 10, $wc_coupon->get_usage_limit() );
	}

	public function test_percent_type_maps_to_percent(): void {
		$coupon = new CanonicalCoupon( 'TENOFF', 'percent', '10', null, null, null, [ 'remote_id' => '2' ] );

		$result    = $this->make_writer()->write( $coupon, null );
		$wc_coupon = new \WC_Coupon( $result->local_id );

		$this->assertSame( 'percent', $wc_coupon->get_discount_type() );
	}

	public function test_free_shipping_flag_is_applied(): void {
		$coupon = new CanonicalCoupon( 'FREESHIP', 'fixed', '0', null, null, null, [ 'remote_id' => '3' ], true );

		$result    = $this->make_writer()->write( $coupon, null );
		$wc_coupon = new \WC_Coupon( $result->local_id );

		$this->assertTrue( $wc_coupon->get_free_shipping() );
	}

	public function test_reuses_existing_coupon_with_same_code(): void {
		$existing = new \WC_Coupon();
		$existing->set_code( 'DUPLICATE' );
		$existing_id = $existing->save();

		$coupon = new CanonicalCoupon( 'DUPLICATE', 'fixed', '100', null, null, null, [ 'remote_id' => '4' ] );
		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( $existing_id, $result->local_id );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_REUSED_EXISTING, (string) $existing_id ), $result->warnings );
	}

	public function test_usage_limit_per_user_is_applied(): void {
		$coupon = new CanonicalCoupon( 'ONEUSE', 'fixed', '100', null, null, null, [ 'remote_id' => '5' ], false, 1 );

		$result    = $this->make_writer()->write( $coupon, null );
		$wc_coupon = new \WC_Coupon( $result->local_id );

		$this->assertSame( 1, $wc_coupon->get_usage_limit_per_user() );
	}
}
