<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\CommerceCapabilities;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersCommerceAdapters;
use WC_Coupon;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * 顧客・受注・クーポンのエクスポート（R3-6c1 で `JobManagerExportTest` から分けた）。`JobManager`のexport方向配線を
 * 実配線（`AdapterPlatformWriterFactory`/`WooReaderRepositoryFactory`/`MockPlatformAdapter`の
 * push成功モード + 実WC商品）で検証する。`Sync\Exporter`自体のオーケストレーションロジック
 * （checksum一致・push intent等）は`ExporterTest`が実writer/readerを介さずに検証済み。
 */
final class CommerceJobManagerExportTest extends WP_UnitTestCase {

	use RegistersCommerceAdapters;

	private JobRepository $jobs;
	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		// `Woo\Reader\OrderReader`が`Woo\Writer\OrderWriter::PLATFORM_CURRENCY`（JPY）と
		// 異なる店舗通貨を`CURRENCY_MISMATCH`でexport-blockingにするため、テスト環境の既定通貨
		// （USD）のままだと`wc_create_order()`で作った注文のpushが無条件にスキップされる
		// （`OrderReaderTest`と同じ理由）。
		update_option( 'woocommerce_currency', 'JPY' );

		$this->jobs     = new JobRepository();
		$this->mappings = new MappingRepository();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->forget_commerce_adapters();
		parent::tear_down();
		$this->forget_commerce_adapters();
	}

	/**
	 * 接続先（`mock`）と、その顧客・受注・クーポンのアダプタを登録し、後者を返す（送信の記録を見るため）。
	 */
	private function register_adapter( bool $push_supported = true, ?CommerceCapabilities $capabilities = null ): MockCommerceAdapter {
		$adapter  = new MockPlatformAdapter( push_products_supported: true );
		$commerce = new MockCommerceAdapter( push_supported: $push_supported, capabilities_override: $capabilities );
		$this->register_commerce_adapter( $commerce );

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		return $commerce;
	}

	private function create_product( string $name, string $sku ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( '1000' );

		return $product->save();
	}



	public function test_export_capability_gate_excludes_customer_order_and_coupon(): void {
		$this->register_adapter(
			capabilities: new CommerceCapabilities(
				can_fetch_customers: true,
				can_update_customer: false,
				can_create_order: false,
				has_coupons: true,
				can_create_coupon: false
			)
		);

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product', 'customer', 'order', 'stock', 'coupon' ] );

		$jobs = array_column( $this->jobs->find_by_run( $run_id ), 'entity' );
		sort( $jobs );

		// can_update_customer=false / can_create_order=false / can_create_coupon=false のため
		// customer/order/couponは除外される。stockは対応するcapabilityフラグが無いため対象のまま。
		$this->assertSame( [ 'product', 'stock' ], $jobs );
	}





	public function test_export_pushes_customer_when_supported(): void {
		$adapter = $this->register_adapter( push_supported: true );
		self::factory()->user->create( [ 'role' => 'customer' ] );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'customer' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'customer' ) );
		$this->assertCount( 1, $adapter->pushed_customers );
	}

	public function test_export_pushes_order_when_supported(): void {
		$adapter = $this->register_adapter( push_supported: true );
		$order   = wc_create_order();
		$order->save();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'order' ) );
		$this->assertCount( 1, $adapter->pushed_orders );
	}


	public function test_export_pushes_coupon_when_supported(): void {
		$adapter = $this->register_adapter( push_supported: true );
		$coupon  = new WC_Coupon();
		$coupon->set_code( 'SAVE10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$coupon->save();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'coupon' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'coupon' ) );
		$this->assertCount( 1, $adapter->pushed_coupons );
	}
}
