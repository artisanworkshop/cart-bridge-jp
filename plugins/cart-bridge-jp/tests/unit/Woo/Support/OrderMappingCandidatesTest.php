<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\OrderMappingCandidates;
use WC_Shipping_Zone;
use WC_Shipping_Zones;
use WP_UnitTestCase;

/**
 * 受注のマッピング（決済・配送・注文ステータス）の Woo 側の候補（R3-6c1 で `MappingCandidatesTest` から分けた）。
 */
final class OrderMappingCandidatesTest extends WP_UnitTestCase {

	public function test_payment_gateways_includes_core_default_gateways(): void {
		$candidates = OrderMappingCandidates::payment_gateways();
		$ids        = array_column( $candidates, 'id' );

		// WooCommerceのテスト環境はデフォルトで bacs/cheque/cod 等を登録済み（有効化状態は問わない。
		// `WC_Payment_Gateways::payment_gateways()`は登録済み全ゲートウェイを返す）。
		$this->assertContains( 'bacs', $ids );
	}

	public function test_shipping_methods_returns_method_instance_id_pairs_with_zone_name(): void {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Mapping Candidates Zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );

		$this->assertNotSame( 0, $instance_id );

		$candidates = OrderMappingCandidates::shipping_methods();
		$ids        = array_column( $candidates, 'id' );

		$this->assertContains( "flat_rate:{$instance_id}", $ids );
		$names = array_combine( $ids, array_column( $candidates, 'name' ) );
		$this->assertStringStartsWith( 'Mapping Candidates Zone: ', $names[ "flat_rate:{$instance_id}" ] );
	}

	public function test_order_statuses_strips_wc_prefix(): void {
		$candidates = OrderMappingCandidates::order_statuses();
		$ids        = array_column( $candidates, 'id' );

		$this->assertContains( 'processing', $ids );
		foreach ( $ids as $id ) {
			$this->assertStringStartsNotWith( 'wc-', $id );
		}
	}

	/**
	 * `checkout-draft`（WooCommerce Blocksのチェックアウト下書き）は`wc_get_order_statuses()`に
	 * 含まれるが、`woocommerce_cleanup_draft_orders`日次cronが24時間経過分を完全削除するため、
	 * マッピング候補には出さない（出すとASP受注をこのステータスへ対応付けた場合に受注が消滅しうる）。
	 */
	public function test_order_statuses_excludes_checkout_draft(): void {
		$candidates = OrderMappingCandidates::order_statuses();
		$ids        = array_column( $candidates, 'id' );

		$this->assertNotContains( 'checkout-draft', $ids );
	}

	public function test_shipping_methods_includes_methods_from_the_locations_not_covered_zone(): void {
		$zone        = WC_Shipping_Zones::get_zone( 0 );
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		$candidates  = OrderMappingCandidates::shipping_methods();
		$ids         = array_column( $candidates, 'id' );

		$this->assertContains( "flat_rate:{$instance_id}", $ids );
	}
}
