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
use WP_UnitTestCase;

/**
 * 移行後検証レポートの汎用の部分（実 Woo writer〈`JobManager::create()`〉で取り込んだ実体の実在の突合）。受注の金額の突合は
 * Pro（`CommerceVerificationReportTest`。R3-6c1）。
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
		parent::tear_down();
	}

	private function register_adapter( MockPlatformAdapter $adapter ): void {
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

	public function test_non_order_entities_have_no_amounts(): void {
		$this->register_adapter( new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] ) );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'category' ] );
		$manager->run_to_completion( $run_id );

		$row = $this->build( $run_id )['entities'][0];

		$this->assertSame( 'category', $row['entity'] );
		$this->assertSame( 1, $row['existing'] );
		$this->assertNull( $row['remote_amount'] );
		$this->assertNull( $row['local_amount'] );
	}

	public function test_dry_run_type_is_passed_through_for_the_endpoint_to_reject(): void {
		$this->register_adapter( new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] ) );

		$manager = JobManager::create();
		$run_id  = $manager->start_run( JobManager::TYPE_DRY_RUN, 'mock', [ 'category' ] );
		$manager->run_to_completion( $run_id );

		$report = $this->build( $run_id );

		$this->assertSame( JobManager::TYPE_DRY_RUN, $report['type'] );
		$this->assertSame( 0, $report['entities'][0]['linked'], 'dry-run は mappings を書かない' );
	}
}
