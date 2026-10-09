<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\ExportSampleSelector;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WC_Coupon;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * `JobManager`のexport方向配線（Step 5-9で追加した`TYPE_EXPORT`/`TYPE_DRY_RUN_EXPORT`分岐）を
 * 実配線（`AdapterPlatformWriterFactory`/`WooReaderRepositoryFactory`/`MockPlatformAdapter`の
 * push成功モード + 実WC商品）で検証する。`Sync\Exporter`自体のオーケストレーションロジック
 * （checksum一致・quota等）は`ExporterTest`が実writer/readerを介さずに検証済み。
 */
final class JobManagerExportTest extends WP_UnitTestCase {

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
		remove_all_filters( 'cbjp/limits/product' );
		AdapterRegistry::reset_cache();
		ExportSampleSelector::clear( 'mock' );
		parent::tear_down();
	}

	private function register_adapter( bool $push_products_supported = true, bool $push_others_supported = false ): MockPlatformAdapter {
		$adapter = new MockPlatformAdapter( push_products_supported: $push_products_supported, push_others_supported: $push_others_supported );

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		return $adapter;
	}

	private function create_product( string $name, string $sku ): int {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( '1000' );

		return $product->save();
	}

	public function test_export_run_pushes_products_and_creates_mappings(): void {
		$this->register_adapter();
		$this->create_product( 'Product A', 'SKU-A' );
		$this->create_product( 'Product B', 'SKU-B' );

		// Pro相当（上限解除）でサンプリングを無効にし、通常のカーソル全量走査パスを通す。
		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 2, $this->mappings->count( 'mock', 'product' ) );
	}

	public function test_export_excludes_entities_without_an_implemented_reader(): void {
		$this->register_adapter();
		$this->create_product( 'Product A', 'SKU-A' );

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		// `category`/`tag`/`review`はReaderを持たない設計（`docs/03-design-decisions.md` §10.2:
		// カテゴリは独立したexportエンティティにせず`category_map`で解決する。tag/reviewは
		// `PlatformAdapter`に対応するpush_*()自体が存在しない）ため、要求してもproductだけが
		// ジョブになる（`JobManager::EXPORT_ENTITIES_WITH_READER`）。
		$run_id = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'category', 'tag', 'review', 'product' ] );

		$jobs = $this->jobs->find_by_run( $run_id );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 'product', $jobs[0]['entity'] );
	}

	public function test_export_capability_gate_excludes_customer_order_and_coupon(): void {
		$capabilities = new Capabilities( true, false, true, false, true, false, true, true, true, true, 600 );
		$adapter      = new MockPlatformAdapter( capabilities_override: $capabilities );

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product', 'customer', 'order', 'stock', 'coupon' ] );

		$jobs = array_column( $this->jobs->find_by_run( $run_id ), 'entity' );
		sort( $jobs );

		// can_update_customer=false / can_create_order=false / can_create_coupon=false のため
		// customer/order/couponは除外される。stockは対応するcapabilityフラグが無いため対象のまま。
		$this->assertSame( [ 'product', 'stock' ], $jobs );
	}

	public function test_dry_run_export_records_items_without_persisting_mappings_or_pushing(): void {
		$adapter = $this->register_adapter( push_products_supported: false );
		$this->create_product( 'Product A', 'SKU-A' );

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_DRY_RUN_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
		$this->assertSame( 0, count( $adapter->pushed_products ), 'dry-runはpush_product()を一切呼ばない' );
		$this->assertSame( 1, ( new DryRunItemRepository() )->count_for_run( $run_id, 'product' ) );
	}

	/**
	 * ColorMeのようにpush_*が`UnsupportedOperationException`を投げるアダプタでも、
	 * ジョブが失敗せず完了し、該当アイテムがskipped/warnedとして記録されること
	 * （PR-AのE2-2完了条件: E2-3のColorMe push実装を待たずに配線が正しく動く）。
	 */
	public function test_export_with_unsupported_push_completes_with_items_skipped(): void {
		$this->register_adapter( push_products_supported: false );
		$this->create_product( 'Product A', 'SKU-A' );

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$job    = $this->jobs->find_by_run( $run_id )[0];
		$totals = json_decode( (string) $job['totals_json'], true );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $totals['skipped'] );
		$this->assertSame( 1, $totals['warned'] );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
	}

	public function test_free_tier_export_sample_is_restricted_to_products_in_the_latest_orders(): void {
		$this->register_adapter();
		$sampled_id   = $this->create_product( 'Sampled', 'SKU-SAMPLED' );
		$unsampled_id = $this->create_product( 'Unsampled', 'SKU-UNSAMPLED' );

		// ちょうど10件（`ExportSampleSelector::SAMPLE_ORDER_LIMIT`）の受注を用意し、
		// §10.2 #5後半のフォールバック補完（受注10件未満の場合に通常一覧の先頭ページから
		// 残り枠を補完する）が発生しないようにする。補完が入ると`$unsampled_id`も
		// サンプルに含まれてしまい、このテストの検証対象（受注に紐づく商品のみへの制限）が
		// 別の挙動と混ざってしまうため。
		for ( $i = 0; $i < 10; $i++ ) {
			$order = wc_create_order();
			$order->add_product( wc_get_product( $sampled_id ), 1 );
			$order->calculate_totals();
			$order->save();
		}

		$manager = JobManager::create();
		// 無料版の上限（デフォルト50件）はそのまま = サンプリング有効。
		$run_id = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'product' ] );
		$manager->run_to_completion( $run_id );

		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ) );
		$this->assertNotNull( $this->mappings->find_remote_id( 'mock', 'product', $sampled_id ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', $unsampled_id ) );
	}

	public function test_export_pushes_customer_when_supported(): void {
		$adapter = $this->register_adapter( push_others_supported: true );
		self::factory()->user->create( [ 'role' => 'customer' ] );

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'customer' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'customer' ) );
		$this->assertCount( 1, $adapter->pushed_customers );
	}

	public function test_export_pushes_order_when_supported(): void {
		$adapter = $this->register_adapter( push_others_supported: true );
		$order   = wc_create_order();
		$order->save();

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'order' ) );
		$this->assertCount( 1, $adapter->pushed_orders );
	}

	public function test_export_pushes_stock_when_supported(): void {
		$adapter    = $this->register_adapter( push_others_supported: true );
		$product_id = $this->create_product( 'Product A', 'SKU-A' );
		$this->mappings->upsert( 'mock', 'product', 'p-1', $product_id, null );

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'stock' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertCount( 1, $adapter->pushed_stocks );
		$this->assertSame( 'p-1', $adapter->pushed_stocks[0]->product_ref );
	}

	public function test_export_pushes_coupon_when_supported(): void {
		$adapter = $this->register_adapter( push_others_supported: true );
		$coupon  = new WC_Coupon();
		$coupon->set_code( 'SAVE10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$coupon->save();

		add_filter( 'cbjp/limits/product', static fn () => null );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'coupon' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'coupon' ) );
		$this->assertCount( 1, $adapter->pushed_coupons );
	}

	/**
	 * 実装計画Eの回帰テスト: `stock`エンティティ自身の無料版上限
	 * （`LimitPolicy::DEFAULT_LIMITS['stock']`）はnull（数値上限なし）だが、`product`の上限を
	 * 基準にサンプリングが働き、受注サンプルに含まれない商品の在庫はexport対象にならないこと。
	 */
	public function test_free_tier_stock_export_sample_is_restricted_to_sampled_products(): void {
		$adapter      = $this->register_adapter( push_others_supported: true );
		$sampled_id   = $this->create_product( 'Sampled', 'SKU-SAMPLED' );
		$unsampled_id = $this->create_product( 'Unsampled', 'SKU-UNSAMPLED' );

		// 両商品とも既にexport済み（mapping有り）という前提にし、このテストの検証対象を
		// 「サンプル制限」だけに絞る（mapping未整備によるSTOCK_PRODUCT_NOT_EXPORTEDブロックと
		// 混同しないようにするため）。
		$this->mappings->upsert( 'mock', 'product', 'p-sampled', $sampled_id, null );
		$this->mappings->upsert( 'mock', 'product', 'p-unsampled', $unsampled_id, null );

		for ( $i = 0; $i < 10; $i++ ) {
			$order = wc_create_order();
			$order->add_product( wc_get_product( $sampled_id ), 1 );
			$order->calculate_totals();
			$order->save();
		}

		$manager = JobManager::create();
		// 無料版の上限（デフォルト50件）はそのまま = サンプリング有効。
		$run_id = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'stock' ] );
		$manager->run_to_completion( $run_id );

		$pushed_refs = array_map( static fn ( $stock ) => $stock->product_ref, $adapter->pushed_stocks );
		$this->assertContains( 'p-sampled', $pushed_refs );
		$this->assertNotContains( 'p-unsampled', $pushed_refs );
	}

	/**
	 * 実装計画Fの回帰テスト: `coupon`は受注サンプルに紐付かない独立した上限
	 * （`LimitPolicy::DEFAULT_LIMITS['coupon']`=10）のみで制限され、`ExportSampleSelector`の
	 * サンプルID方式（`only_local_ids`）の対象にならないこと。受注サンプルと無関係なクーポンを
	 * 作成し、サンプリング有効（無料版）のままでもLimitPolicyの上限までは正しくpushされることを
	 * 確認する。
	 */
	public function test_coupon_export_is_not_restricted_by_order_sample(): void {
		$adapter = $this->register_adapter( push_others_supported: true );

		for ( $i = 0; $i < 3; $i++ ) {
			$coupon = new WC_Coupon();
			$coupon->set_code( "CODE{$i}" );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( '10' );
			$coupon->save();
		}

		// 受注サンプルの起点となる受注は1件も作らない（クーポンのサンプルが受注に依存しない
		// ことを確認するため）。
		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'coupon' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertCount( 3, $adapter->pushed_coupons );
		$this->assertSame( 3, $this->mappings->count( 'mock', 'coupon' ) );
	}

	/**
	 * D15 §10.2「クーポン: 最新10件」は`LimitPolicy`（`cbjp_mappings`累積カウント）だけで
	 * 強制される（サンプルID方式を使わないため）。`LimitPolicy::DEFAULT_LIMITS['coupon']`=10を
	 * 超える新規クーポンが実際に頭打ちになることを確認する回帰テスト。
	 */
	public function test_coupon_export_is_capped_at_the_free_tier_limit(): void {
		$adapter = $this->register_adapter( push_others_supported: true );

		for ( $i = 0; $i < 12; $i++ ) {
			$coupon = new WC_Coupon();
			$coupon->set_code( "CODE{$i}" );
			$coupon->set_discount_type( 'percent' );
			$coupon->set_amount( '10' );
			$coupon->save();
		}

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'coupon' ] );
		$manager->run_to_completion( $run_id );

		$job = $this->jobs->find_by_run( $run_id )[0];
		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertCount( 10, $adapter->pushed_coupons );
		$this->assertSame( 10, $this->mappings->count( 'mock', 'coupon' ) );
	}
}
