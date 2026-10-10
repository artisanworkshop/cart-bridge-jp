<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Entities;

use CartBridgeJP\Adapters\CommerceAdapters;
use CartBridgeJP\Adapters\CommerceCapabilities;
use CartBridgeJP\Core\Plugin;
use CartBridgeJP\Entities\Commerce\CouponType;
use CartBridgeJP\Entities\Commerce\CustomerType;
use CartBridgeJP\Entities\Commerce\OrderType;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;
use WP_UnitTestCase;

/**
 * 顧客・受注・クーポンの種類は、無料版自身が公開の口（`cbjp/entity_types/register`）から登録する（R3-6b1。R3-6c で Pro へ移す）。
 */
final class CommerceRegistrationTest extends WP_UnitTestCase {

	use RegistersCommerceAdapters;
	use RegistersEntityTypes;

	public function tear_down(): void {
		$this->forget_entity_types();
		$this->forget_commerce_adapters();
		parent::tear_down();
		$this->forget_entity_types();
		$this->forget_commerce_adapters();
	}

	/**
	 * D24: 受注のエクスポートは、接続先の `CommerceAdapter` がベータと宣言したときだけベータ（R3-6c1 で `EntityTypeRegistryTest` から移した）。
	 */
	public function test_order_export_is_beta_only_when_the_commerce_adapter_declares_it(): void {
		$adapter = new MockPlatformAdapter();
		$beta    = new CommerceCapabilities(
			can_fetch_customers: true,
			can_update_customer: true,
			can_create_order: true,
			has_coupons: true,
			can_create_coupon: true,
			order_export_beta: true
		);

		$this->register_commerce_adapter( new MockCommerceAdapter( capabilities_override: $beta ) );
		$this->assertTrue( EntityTypeRegistry::is_export_beta( EntityTypeRegistry::get( 'order' ), $adapter ) );
		$this->assertFalse( EntityTypeRegistry::is_export_beta( EntityTypeRegistry::get( 'product' ), $adapter ) );

		remove_all_filters( CommerceAdapters::FILTER );
		$this->register_commerce_adapter( new MockCommerceAdapter() );
		$this->assertFalse( EntityTypeRegistry::is_export_beta( EntityTypeRegistry::get( 'order' ), $adapter ) );
	}

	public function test_commerce_types_come_through_the_public_filter(): void {
		$this->assertInstanceOf( CustomerType::class, EntityTypeRegistry::get( 'customer' ) );
		$this->assertInstanceOf( OrderType::class, EntityTypeRegistry::get( 'order' ) );
		$this->assertInstanceOf( CouponType::class, EntityTypeRegistry::get( 'coupon' ) );

		remove_all_filters( EntityTypeRegistry::FILTER );
		EntityTypeRegistry::reset_cache();

		$this->assertNull( EntityTypeRegistry::get( 'customer' ) );
		$this->assertNull( EntityTypeRegistry::get( 'order' ) );
		$this->assertNull( EntityTypeRegistry::get( 'coupon' ) );
	}

	public function test_boot_resets_a_prematurely_populated_cache(): void {
		// 登録（boot）より前に外部コードが一覧を取得し、登録前の結果がキャッシュに固定された状態を再現する。
		remove_all_filters( EntityTypeRegistry::FILTER );
		EntityTypeRegistry::reset_cache();
		$this->assertFalse( EntityTypeRegistry::has( 'order' ) );

		$plugin = Plugin::instance();
		$booted = new \ReflectionProperty( Plugin::class, 'booted' );
		$booted->setValue( $plugin, false );
		$plugin->boot();

		$this->assertTrue( EntityTypeRegistry::has( 'order' ) );
	}
}
