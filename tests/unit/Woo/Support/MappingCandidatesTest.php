<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\MappingCandidates;
use WC_Shipping_Zone;
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

	public function test_payment_gateways_includes_core_default_gateways(): void {
		$candidates = MappingCandidates::payment_gateways();
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

		$candidates = MappingCandidates::shipping_methods();
		$ids        = array_column( $candidates, 'id' );

		$this->assertContains( "flat_rate:{$instance_id}", $ids );
		$names = array_combine( $ids, array_column( $candidates, 'name' ) );
		$this->assertStringStartsWith( 'Mapping Candidates Zone: ', $names[ "flat_rate:{$instance_id}" ] );
	}

	public function test_order_statuses_strips_wc_prefix(): void {
		$candidates = MappingCandidates::order_statuses();
		$ids        = array_column( $candidates, 'id' );

		$this->assertContains( 'processing', $ids );
		foreach ( $ids as $id ) {
			$this->assertStringStartsNotWith( 'wc-', $id );
		}
	}
}
