<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Entities;

use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Entities\LinkSource;
use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Entities\WarningFlag;
use CartBridgeJP\Pro\Adapters\CommerceAdapters;
use CartBridgeJP\Pro\Adapters\CommerceCapabilities;
use CartBridgeJP\Pro\Core\Plugin;
use CartBridgeJP\Pro\Entities\CommerceEntityTypes;
use CartBridgeJP\Pro\Entities\CouponType;
use CartBridgeJP\Pro\Entities\CustomerType;
use CartBridgeJP\Pro\Entities\OrderType;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Pro\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;
use WP_UnitTestCase;

/**
 * 顧客・受注・クーポンの種類は、Pro アドオンが無料版の公開の口（`cbjp/entity_types/register`）から登録する（R3-6b1 の口。R3-6c1 で
 * 無料版から移した）。
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

	public function test_the_types_run_in_the_public_positions(): void {
		$this->assertSame( [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ], EntityTypeRegistry::keys() );
		$this->assertSame(
			[ 'category', 'tag', 'product', 'variant', 'coupon', 'customer', 'order' ],
			array_map( static fn ( LinkSource $source ): string => $source->key(), EntityTypeRegistry::link_sources() )
		);
		$this->assertSame(
			[ 'category', 'payment', 'shipping', 'status' ],
			array_map( static fn ( MappingKind $kind ): string => $kind->key(), EntityTypeRegistry::mapping_kinds() )
		);
		$this->assertSame( 'Orders', EntityTypeRegistry::labels()['order'] );
	}

	/**
	 * `EntityType` の既定（push intent を残さない・エクスポートで結ばれた判定をしない）を上書きし忘れると、D21-B の重複作成防止と
	 * D25 の保護が黙って効かない（backlog r3-6b1/R1-L9）。顧客・受注・クーポンが上書きしていることをここで固定する。
	 */
	public function test_the_types_record_push_intents_and_guard_the_link_direction(): void {
		foreach ( [ 'customer', 'order', 'coupon' ] as $key ) {
			$this->assertTrue( EntityTypeRegistry::get( $key )->records_push_intent(), $key );
		}

		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		$order_id    = wc_create_order()->get_id();
		$coupon      = new \WC_Coupon();
		$coupon->set_code( 'registration-guard' );
		$coupon_id = $coupon->save();

		$this->assertTrue( EntityTypeRegistry::get( 'customer' )->is_linked_by_export( 'mock', $customer_id ) );
		$this->assertTrue( EntityTypeRegistry::get( 'order' )->is_linked_by_export( 'mock', $order_id ) );
		$this->assertTrue( EntityTypeRegistry::get( 'coupon' )->is_linked_by_export( 'mock', $coupon_id ) );
	}

	public function test_registration_tolerates_a_misbehaving_earlier_filter(): void {
		$this->assertCount( 3, CommerceEntityTypes::register( false ) );
		$this->assertCount( 4, CommerceEntityTypes::register( [ 'x' ] ) );
	}

	public function test_the_order_codes_carry_their_flags(): void {
		$this->assertSame(
			[
				WarningFlag::UNRESOLVED_REFERENCE => true,
				WarningFlag::MAPPING_REQUIRED     => true,
			],
			EntityTypeRegistry::warning_flags( 'payment_method_unmapped' )
		);
		$this->assertSame( [ WarningFlag::EXPORT_BLOCKING => true ], EntityTypeRegistry::warning_flags( 'currency_mismatch' ) );
	}
}
