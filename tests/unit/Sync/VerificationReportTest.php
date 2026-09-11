<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\VerificationReport;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WC_Order;
use WP_UnitTestCase;

/**
 * 実 Woo writer（`JobManager::create()`）で受注を取り込み、ASP側（totals の remote_amount）と
 * Woo側（mappings がリンクする受注の実在・合計）の突合を検証する。
 */
final class VerificationReportTest extends WP_UnitTestCase {

	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings = new MappingRepository();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		delete_option( 'cbjp_sample_mock' );
		parent::tear_down();
	}

	private function register_adapter_with_orders(): void {
		$adapter = new MockPlatformAdapter(
			orders: [
				CanonicalFactory::order( '1001', null, [ 'p1' ] ),
				CanonicalFactory::order( '1002', null, [ 'p1' ] ),
			]
		);

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
	}

	private function build( string $run_id ): ?array {
		return ( new VerificationReport( new JobRepository(), $this->mappings ) )->build( $run_id );
	}

	public function test_returns_null_for_unknown_run(): void {
		$this->assertNull( $this->build( 'no-such-run' ) );
	}

	public function test_import_run_reconciles_counts_and_order_totals(): void {
		$this->register_adapter_with_orders();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		$report = $this->build( $run_id );

		$this->assertNotNull( $report );
		$this->assertSame( 'mock', $report['platform'] );
		$this->assertSame( JobManager::TYPE_IMPORT, $report['type'] );
		$this->assertSame( get_woocommerce_currency(), $report['currency'], '受注に保存された通貨（取込時の店舗通貨）' );
		$this->assertSame( 'JPY', $report['platform_currency'] );
		$this->assertSame( 'JPY' !== get_woocommerce_currency(), $report['currency_mismatch'] );
		$this->assertCount( 1, $report['entities'] );

		$row = $report['entities'][0];
		$this->assertSame( 'order', $row['entity'] );
		$this->assertSame( JobRepository::STATUS_COMPLETED, $row['status'] );
		$this->assertSame( 2, $row['processed'] );
		$this->assertSame( 2, $row['written'] );
		$this->assertSame( 2, $row['linked'] );
		$this->assertSame( 2, $row['existing'] );
		$this->assertSame( 0, $row['missing'] );
		// CanonicalFactory::order() の total は 1000 固定 → 2件で 2000。
		$this->assertSame( '2000.00', $row['remote_amount'] );
		$this->assertSame( '2000.00', $row['local_amount'] );
	}

	public function test_deleted_order_is_reported_as_missing_and_drops_out_of_the_woo_total(): void {
		$this->register_adapter_with_orders();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		$order_id = $this->mappings->find_local_id( 'mock', 'order', '1001' );
		$this->assertNotNull( $order_id );
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->delete( true );

		$row = $this->build( $run_id )['entities'][0];

		$this->assertSame( 2, $row['processed'], 'ASP側の件数は run 時点の値のまま' );
		$this->assertSame( 2, $row['linked'] );
		$this->assertSame( 1, $row['existing'] );
		$this->assertSame( 1, $row['missing'] );
		$this->assertSame( '2000.00', $row['remote_amount'] );
		$this->assertSame( '1000.00', $row['local_amount'] );
	}

	public function test_currency_comes_from_the_imported_orders_not_the_current_store_setting(): void {
		// USD の店舗で取り込んだ受注は USD のまま残る。後から店舗通貨を JPY に変えても、
		// 受注側の通貨で判定して「不一致」のままにする（数値だけ一致する偽の突合を避ける）。
		update_option( 'woocommerce_currency', 'USD' );
		$this->register_adapter_with_orders();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		update_option( 'woocommerce_currency', 'JPY' );

		$report = $this->build( $run_id );

		$this->assertSame( 'USD', $report['currency'] );
		$this->assertTrue( $report['currency_mismatch'] );
	}

	public function test_non_order_entities_have_no_amounts(): void {
		$adapter = new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] );
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'category' ] );
		$manager->run_to_completion( $run_id );

		$row = $this->build( $run_id )['entities'][0];

		$this->assertSame( 'category', $row['entity'] );
		$this->assertSame( 1, $row['existing'] );
		$this->assertNull( $row['remote_amount'] );
		$this->assertNull( $row['local_amount'] );
	}

	public function test_legacy_jobs_without_remote_amount_report_the_platform_total_as_unknown(): void {
		// F1-7 より前に完了したジョブの totals_json には `remote_amount` が無い。0.00 として突合すると
		// 偽の「Totals differ」になるため null（不明）で返す。
		$jobs   = new JobRepository();
		$job_id = $jobs->create( 'legacy-run', JobManager::TYPE_IMPORT, 'mock', 'order' );
		$jobs->update_progress(
			$job_id,
			null,
			[
				'total'     => 1,
				'processed' => 1,
				'created'   => 1,
				'updated'   => 0,
				'skipped'   => 0,
				'warned'    => 0,
				'failed'    => 0,
			]
		);
		$jobs->update_status( $job_id, JobRepository::STATUS_COMPLETED );

		$row = $this->build( 'legacy-run' )['entities'][0];

		$this->assertNull( $row['remote_amount'] );
		$this->assertSame( '0.00', $row['local_amount'] );
	}

	public function test_dry_run_type_is_passed_through_for_the_endpoint_to_reject(): void {
		$this->register_adapter_with_orders();

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_DRY_RUN, 'mock', [ 'order' ] );
		$manager->run_to_completion( $run_id );

		$report = $this->build( $run_id );

		$this->assertSame( JobManager::TYPE_DRY_RUN, $report['type'] );
		$this->assertSame( 0, $report['entities'][0]['linked'], 'dry-run は mappings を書かない' );
	}
}
