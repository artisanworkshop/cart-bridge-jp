<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\License;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Pro\License\LicenseGate;
use CartBridgeJP\Pro\License\LicenseStore;
use CartBridgeJP\Pro\Tests\Fixtures\ActivatesLicense;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Pro\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Sync\DryRunOnlyException;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * キーを有効化していない間は、顧客・受注・クーポンを dry-run だけにする（D30・R3-8b。`docs/03` §10.7）。
 * 判定は Pro の `LicenseGate`（ローカルの有効化の記録だけ）で、無料版の汎用の口（`EntityType::dry_run_only_reason()`）から
 * 宣言（`GET /connections`）・run の作成・Retry の拒否に効く。商品系は変わらない。
 */
final class LicenseGateTest extends WP_UnitTestCase {

	use ActivatesLicense;
	use RegistersCommerceAdapters;

	private const COMMERCE = [ 'customer', 'order', 'coupon' ];

	private JobRepository $jobs;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->deactivate_license();

		$adapter = new MockPlatformAdapter( push_products_supported: true );
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
		$this->register_commerce_adapter( new MockCommerceAdapter( push_supported: true ) );

		$this->jobs = new JobRepository();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。

		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->forget_commerce_adapters();
		parent::tear_down();
		$this->forget_commerce_adapters();
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function provide_states_that_are_not_an_activation(): array {
		return [
			'no state'       => [ null ],
			'deactivated'    => [ [ 'activated' => 'no' ] ],
			'boolean true'   => [ [ 'activated' => true ] ],
			'one'            => [ [ 'activated' => '1' ] ],
			'upper-case yes' => [ [ 'activated' => 'YES' ] ],
			'not an array'   => [ 'yes' ],
		];
	}

	/**
	 * 有効化の記録が `'yes'` でなければ、3 種類とも理由つきで dry-run だけ（壊れた記録も有効化とみなさない。原則 9）。
	 *
	 * @dataProvider provide_states_that_are_not_an_activation
	 */
	public function test_the_commerce_types_are_dry_run_only_until_the_key_is_activated( mixed $state ): void {
		if ( null !== $state ) {
			update_option( LicenseStore::STATE_OPTION, $state );
		}

		$adapter = AdapterRegistry::get( 'mock' );

		foreach ( self::COMMERCE as $key ) {
			$this->assertSame( LicenseGate::dry_run_only_reason(), EntityTypeRegistry::dry_run_only_reason( EntityTypeRegistry::get( $key ), $adapter ), $key );
		}

		$this->assertStringContainsString( 'activate your Cart Bridge JP Pro license key', (string) LicenseGate::dry_run_only_reason() );
		$this->assertSame( self::COMMERCE, array_keys( EntityTypeRegistry::dry_run_only( $adapter, EntityTypeRegistry::keys() ) ) );
	}

	public function test_activating_the_key_lifts_the_restriction_on_the_same_instances(): void {
		$adapter  = AdapterRegistry::get( 'mock' );
		$customer = EntityTypeRegistry::get( 'customer' );
		$this->assertNotNull( $customer->dry_run_only_reason( $adapter ) );

		// レジストリが持つ同じインスタンスでも、有効化・無効化の後は読み直す（判定を保持しない）。
		$this->activate_license();
		$this->assertNull( LicenseGate::dry_run_only_reason() );
		$this->assertNull( $customer->dry_run_only_reason( $adapter ) );
		$this->assertSame( [], EntityTypeRegistry::dry_run_only( $adapter, EntityTypeRegistry::keys() ) );

		$this->deactivate_license();
		$this->assertNotNull( $customer->dry_run_only_reason( $adapter ) );
	}

	public function test_import_and_export_runs_are_refused_without_a_key_but_dry_runs_and_products_run(): void {
		$manager = JobManager::create();

		foreach ( [
			JobManager::TYPE_IMPORT => [ 'product', 'customer', 'order' ],
			JobManager::TYPE_EXPORT => [ 'product', 'customer', 'coupon' ],
		] as $type => $entities ) {
			try {
				$manager->start_run( $type, 'mock', $entities );
				$this->fail( "The {$type} run must be refused without a key." );
			} catch ( DryRunOnlyException $exception ) {
				$this->assertSame( array_slice( $entities, 1 ), array_keys( $exception->reasons() ), $type );
			}
		}

		foreach ( [ JobManager::TYPE_DRY_RUN, JobManager::TYPE_DRY_RUN_EXPORT ] as $type ) {
			$run_id = $manager->start_run( $type, 'mock', [ 'product', 'customer', 'order', 'coupon' ] );
			$this->assertSame( [ 'product', 'customer', 'order', 'coupon' ], array_column( $this->jobs->find_by_run( $run_id ), 'entity' ), $type );
			$this->jobs->cancel_run( $run_id );
		}

		$run_id = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'product' ] );
		$this->assertSame( [ 'product' ], array_column( $this->jobs->find_by_run( $run_id ), 'entity' ) );
		$this->jobs->cancel_run( $run_id );

		$this->activate_license();
		$run_id = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'product', 'customer', 'order' ] );
		$this->assertSame( [ 'product', 'customer', 'order' ], array_column( $this->jobs->find_by_run( $run_id ), 'entity' ) );
	}

	public function test_a_failed_import_job_cannot_be_retried_after_the_key_is_deactivated(): void {
		$this->activate_license();
		$job_id = $this->jobs->create( wp_generate_uuid4(), JobManager::TYPE_IMPORT, 'mock', 'order' );
		$this->jobs->update_status( $job_id, JobRepository::STATUS_FAILED );
		$this->deactivate_license();

		try {
			JobManager::create()->retry( $job_id );
			$this->fail( 'The retry must be refused without a key.' );
		} catch ( DryRunOnlyException $exception ) {
			$this->assertSame( [ 'order' ], array_keys( $exception->reasons() ) );
		}

		$this->assertSame( JobRepository::STATUS_FAILED, $this->jobs->find( $job_id )['status'] );
	}

	public function test_the_rest_api_declares_and_enforces_the_restriction(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		do_action( 'rest_api_init', $wp_rest_server );

		$connections = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data();
		$connection  = array_values( array_filter( $connections, static fn ( array $item ): bool => 'mock' === $item['platform'] ) )[0];
		$import      = array_column( $connection['entities']['import'], null, 'key' );
		$export      = array_column( $connection['entities']['export'], null, 'key' );

		$this->assertFalse( $import['product']['dry_run_only'] );
		$this->assertFalse( $export['product']['dry_run_only'] );

		foreach ( [ 'customer', 'order', 'coupon' ] as $key ) {
			$this->assertTrue( $import[ $key ]['dry_run_only'], $key );
			$this->assertSame( LicenseGate::dry_run_only_reason(), $import[ $key ]['dry_run_only_reason'], $key );
		}

		foreach ( [ 'customer', 'order', 'coupon' ] as $key ) {
			$this->assertTrue( $export[ $key ]['dry_run_only'], $key );
		}

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'type'     => JobManager::TYPE_IMPORT,
					'platform' => 'mock',
					'entities' => [ 'product', 'customer' ],
				]
			)
		);
		$response = $wp_rest_server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'cbjp_dry_run_only', $response->get_data()['code'] );
		$this->assertStringContainsString( 'Customers', $response->get_data()['message'] );
		$this->assertStringContainsString( (string) LicenseGate::dry_run_only_reason(), $response->get_data()['message'] );
	}
}
