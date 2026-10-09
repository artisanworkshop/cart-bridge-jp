<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Entities;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Admin\DryRunReportCsv;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\VerificationReport;
use CartBridgeJP\Tests\Fixtures\DelegatingPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\Gizmo\ThrowingEntityType;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;
use CartBridgeJP\Woo\Tools\MappingRebuilder;
use CartBridgeJP\Woo\Tools\PushIntentPresenter;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\WooReaderRepositoryFactory;
use CartBridgeJP\Woo\WooRepositoryFactory;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * 外部の種類が例外を投げても、一覧・レポート・ツールを組み立てる処理が落ちない（R3-6b1。原則 8）。
 * 一覧を組み立てる箇所はその種類を「非対応」「説明なし」「不明」に倒す。
 */
final class ThrowingEntityTypeTest extends WP_UnitTestCase {

	use RegistersEntityTypes;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter( categories: [ new CanonicalCategory( 'c1', 'Category 1', null, null ) ] );

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
		$this->register_entity_types( [ new ThrowingEntityType() ] );
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->forget_entity_types();
		parent::tear_down();
		$this->forget_entity_types();
	}

	public function test_connections_leave_the_type_out(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response       = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) );
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。

		$this->assertSame( 200, $response->get_status() );

		foreach ( $response->get_data() as $connection ) {
			$this->assertNotContains( 'boom', array_column( $connection['entities']['import'], 'key' ) );
			$this->assertNotContains( 'boom', array_column( $connection['entities']['export'], 'key' ) );
		}
	}

	public function test_a_run_leaves_the_type_out(): void {
		$run_id = JobManager::create()->start_run( JobManager::TYPE_DRY_RUN, 'mock', [ 'boom', 'category' ] );

		$this->assertSame( [ 'category' ], array_column( ( new JobRepository() )->find_by_run( $run_id ), 'entity' ) );
	}

	public function test_the_woo_writer_skips_the_type(): void {
		$result = ( new WooRepositoryFactory() )->for_platform( 'mock' )->write( 'boom', new CanonicalCategory( 'c1', 'C', null, null ), null );

		$this->assertSame( [ WarningCode::ENTITY_NOT_SUPPORTED ], $result->warnings );
	}

	public function test_the_csv_falls_back_when_the_type_cannot_describe(): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );
		$items = new DryRunItemRepository();
		$items->insert_many(
			'run-boom',
			1,
			[
				[
					'entity'            => 'boom',
					'remote_id'         => 'b1',
					'label'             => '',
					'operation'         => 'created',
					'existing_local_id' => 0,
					'warnings'          => [ 'boom_code', 'category_parent_unresolved:9' ],
				],
			]
		);

		$rows = ( new DryRunReportCsv( $items ) )->rows( 'run-boom', null, false, WarningCatalog::IMPORT );

		$this->assertSame( WarningCatalog::SEVERITY_UNKNOWN, $rows[0][8] );
		$this->assertSame( WarningCatalog::SEVERITY_ACTION_REQUIRED, $rows[1][8], '無料版のコードは行の種類が例外を投げても説明する' );
		$this->assertSame( 'reference_pending_import', $rows[1][7] );
	}

	public function test_the_link_rebuild_completes_without_the_type(): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );

		$result = ( new MappingRebuilder( new MappingRepository() ) )->run( 'mock' );

		$this->assertNull( $result['cursor'] );
		$this->assertArrayNotHasKey( 'boom', $result['counts'] );
	}

	/**
	 * @return array<string,array{0:callable():mixed}>
	 */
	public static function broken_scans(): array {
		return [
			'throws'                 => [ static fn (): array => throw new \RuntimeException( 'scan' ) ],
			'not an array'           => [ static fn (): string => 'nope' ],
			'missing rows'           => [ static fn (): array => [ 'scanned' => 1 ] ],
			'negative count'         => [
				static fn (): array => [
					'scanned' => -1,
					'rows'    => [],
				],
			],
			'beyond the limit'       => [
				static fn (): array => [
					'scanned' => 10000,
					'rows'    => [],
				],
			],
			'more rows than scanned' => [
				static fn (): array => [
					'scanned' => 0,
					'rows'    => [ 5 => 'b1' ],
				],
			],
		];
	}

	/**
	 * 1 つの LinkSource の走査が例外を投げる・形の違う結果を返しても、ほかの種類の復元は最後まで進む。
	 *
	 * @dataProvider broken_scans
	 *
	 * @param callable():mixed $scan
	 */
	public function test_the_link_rebuild_skips_a_source_that_fails_to_scan( callable $scan ): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );
		$broken = new class( $scan ) extends \CartBridgeJP\Entities\EntityType {

			/**
			 * @param callable():mixed $scan
			 */
			public function __construct( private readonly mixed $scan ) {}

			public function key(): string {
				return 'broken';
			}

			public function label(): string {
				return 'Broken';
			}

			public function position(): int {
				return 25;
			}

			public function link_sources(): array {
				$scan = $this->scan;

				return [
					new class( $scan ) extends \CartBridgeJP\Entities\LinkSource {

						/**
						 * @param callable():mixed $scan
						 */
						public function __construct( private readonly mixed $scan ) {}

						public function key(): string {
							return 'broken';
						}

						public function label(): string {
							return 'Broken';
						}

						public function position(): int {
							return 25;
						}

						public function scan( string $platform, int $offset, int $limit ): array {
							return ( $this->scan )();
						}
					},
				];
			}
		};
		$this->register_entity_types( [ $broken ] );
		$term_id = self::factory()->term->create( [ 'taxonomy' => 'product_tag' ] );
		update_term_meta( $term_id, '_cbjp_platform', 'mock' );
		update_term_meta( $term_id, '_cbjp_remote_id', 't1' );

		$result = ( new MappingRebuilder( new MappingRepository() ) )->run( 'mock' );

		$this->assertNull( $result['cursor'] );
		$this->assertSame( 0, $result['counts']['broken'] );
		$this->assertSame( 1, $result['counts']['tag'] );
		$this->assertSame( $term_id, ( new MappingRepository() )->find_local_id( 'mock', 'tag', 't1' ) );
	}

	public function test_the_verification_report_marks_the_type_as_unknown(): void {
		$jobs   = new JobRepository();
		$job_id = $jobs->create( 'run-boom-report', JobManager::TYPE_IMPORT, 'mock', 'boom' );
		$jobs->update_status( $job_id, JobRepository::STATUS_COMPLETED );
		( new MappingRepository() )->upsert( 'mock', 'boom', 'b1', 123, null );

		$row = ( new VerificationReport( $jobs, new MappingRepository() ) )->build( 'run-boom-report' )['entities'][0];

		$this->assertSame( 1, $row['linked'] );
		$this->assertNull( $row['existing'] );
		$this->assertNull( $row['missing'] );
		$this->assertNull( $row['local_amount'] );
	}

	public function test_push_intents_describe_the_type_as_missing(): void {
		$this->assertSame(
			[
				'exists'   => false,
				'edit_url' => null,
				'details'  => [],
			],
			( new PushIntentPresenter() )->describe( 'boom', 1 )
		);
	}

	public function test_labels_fall_back_to_the_key(): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );

		$this->assertSame( 'boom', EntityTypeRegistry::labels()['boom'] );
	}

	public function test_failing_writers_and_readers_are_logged(): void {
		( new WooRepositoryFactory() )->for_platform( 'mock' );
		( new WooReaderRepositoryFactory() )->for_platform( 'mock' );

		$messages = array_column( ( new LogRepository() )->list( null, 'error' ), 'message' );

		$this->assertContains( 'Entity type failed to build its Woo writer.', $messages );
		$this->assertContains( 'Entity type failed to build its Woo reader.', $messages );
	}

	public function test_a_failing_beta_check_is_reported_as_beta(): void {
		$shaky = new class() extends \CartBridgeJP\Entities\EntityType {

			public function key(): string {
				return 'shaky';
			}

			public function label(): string {
				return 'Shaky';
			}

			public function position(): int {
				return 90;
			}

			public function supports_export( \CartBridgeJP\Adapters\PlatformAdapter $adapter ): bool {
				return true;
			}

			public function is_export_beta( \CartBridgeJP\Adapters\PlatformAdapter $adapter ): bool {
				throw new \RuntimeException( 'beta' );
			}
		};
		$this->register_entity_types( [ $shaky ] );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		do_action( 'rest_api_init', $wp_rest_server );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$connections    = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data();
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$mock           = array_values( array_filter( $connections, static fn ( array $item ): bool => 'mock' === $item['platform'] ) )[0];
		$shaky_option   = array_values( array_filter( $mock['entities']['export'], static fn ( array $option ): bool => 'shaky' === $option['key'] ) );

		$this->assertSame( [ true ], array_column( $shaky_option, 'beta' ) );
	}

	/**
	 * アダプタの能力の読み取りが失敗したら、以前と同じく run を始めない（要求した種類が黙って外れた run にしない）。
	 */
	public function test_a_failing_adapter_capability_check_stops_the_run(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ): array {
				$adapters['mock'] = new DelegatingPlatformAdapter( new MockPlatformAdapter(), new \RuntimeException( 'capabilities failed' ) );

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'capabilities failed' );

		JobManager::create()->start_run( JobManager::TYPE_IMPORT, 'mock', [ 'category', 'customer' ] );
	}

	/**
	 * 外部の種類が重複・無関係な ID を「実在」として返しても、問い合わせた ID の中だけを数え、金額の集計にも渡さない。
	 */
	public function test_the_verification_report_ignores_ids_it_did_not_ask_about(): void {
		$real = self::factory()->post->create();
		$liar = new class( $real ) extends \CartBridgeJP\Entities\EntityType {

			/**
			 * @var array<int,int>
			 */
			public array $summarized = [];

			public function __construct( private readonly int $real ) {}

			public function key(): string {
				return 'liar';
			}

			public function label(): string {
				return 'Liar';
			}

			public function position(): int {
				return 90;
			}

			public function existing_local_ids( array $local_ids ): ?array {
				return [ $this->real, $this->real, 888888 ];
			}

			public function local_amount_summary( array $local_ids ): ?array {
				$this->summarized = $local_ids;

				return [
					'total_minor' => 100,
					'currencies'  => [ 'JPY' ],
				];
			}
		};
		$this->register_entity_types( [ $liar ] );
		$jobs   = new JobRepository();
		$job_id = $jobs->create( 'run-liar', JobManager::TYPE_IMPORT, 'mock', 'liar' );
		$jobs->update_status( $job_id, JobRepository::STATUS_COMPLETED );
		$mappings = new MappingRepository();
		$mappings->upsert( 'mock', 'liar', 'l1', $real, null );
		$mappings->upsert( 'mock', 'liar', 'l2', 777777, null );

		$row = ( new VerificationReport( $jobs, $mappings ) )->build( 'run-liar' )['entities'][0];

		$this->assertSame( 2, $row['linked'] );
		$this->assertSame( 1, $row['existing'] );
		$this->assertSame( 1, $row['missing'] );
		$this->assertSame( [ $real ], $liar->summarized );
	}

	/**
	 * push intent の解除で、外部の種類が返したモデルの `remote_id()` が例外を投げても、500 ではなく REMOTE_UNAVAILABLE で返す。
	 */
	public function test_a_fetched_model_that_cannot_report_its_id_is_reported_as_unavailable(): void {
		$odd = new class() extends \CartBridgeJP\Entities\EntityType {

			public function key(): string {
				return 'odd';
			}

			public function label(): string {
				return 'Odd';
			}

			public function position(): int {
				return 90;
			}

			public function fetch_by_remote_id( \CartBridgeJP\Adapters\PlatformAdapter $adapter, string $remote_id ): ?\CartBridgeJP\Canonical\CanonicalModel {
				return new class() implements \CartBridgeJP\Canonical\CanonicalModel {

					public function to_array(): array {
						return [];
					}

					public static function from_array( array $data ): self {
						return new self();
					}

					public function canonical_json(): string {
						return '{}';
					}

					public function checksum(): string {
						return '';
					}

					public function remote_id(): ?string {
						throw new \RuntimeException( 'remote_id' );
					}
				};
			}
		};
		$this->register_entity_types( [ $odd ] );
		$intents = new \CartBridgeJP\Sync\PushIntentRepository();
		$intents->begin( 'mock', 'odd', 404, null, null );
		$id = $intents->find_unresolved( 'mock' )[0]['id'];

		try {
			( new \CartBridgeJP\Woo\Tools\PushIntentResolver( $intents, new MappingRepository() ) )->resolve_link( 'mock', $id, new MockPlatformAdapter(), 'o1' );
			$this->fail( '例外が出ませんでした。' );
		} catch ( \CartBridgeJP\Woo\Tools\PushIntentResolutionException $exception ) {
			$this->assertSame( \CartBridgeJP\Woo\Tools\PushIntentResolutionException::REMOTE_UNAVAILABLE, $exception->reason() );
		}
	}

	/**
	 * 外部の種類の金額の集計が、通貨の一覧に不正な要素を含んでいたら、要素を捨てて突合せずに集計ごと捨てる（通貨の不一致を見逃さない）。
	 */
	public function test_an_amount_summary_with_a_malformed_currency_is_not_compared(): void {
		$real  = self::factory()->post->create();
		$mixed = new class( $real ) extends \CartBridgeJP\Entities\EntityType {

			public function __construct( private readonly int $real ) {}

			public function key(): string {
				return 'mixed';
			}

			public function label(): string {
				return 'Mixed';
			}

			public function position(): int {
				return 90;
			}

			public function existing_local_ids( array $local_ids ): ?array {
				return [ $this->real ];
			}

			public function local_amount_summary( array $local_ids ): ?array {
				return [
					'total_minor' => 100,
					'currencies'  => [ 'JPY', 5 ],
				];
			}
		};
		$this->register_entity_types( [ $mixed ] );
		$jobs   = new JobRepository();
		$job_id = $jobs->create( 'run-mixed', JobManager::TYPE_IMPORT, 'mock', 'mixed' );
		$jobs->update_status( $job_id, JobRepository::STATUS_COMPLETED );
		$mappings = new MappingRepository();
		$mappings->upsert( 'mock', 'mixed', 'm1', $real, null );

		$report = ( new VerificationReport( $jobs, $mappings ) )->build( 'run-mixed' );

		$this->assertNull( $report['entities'][0]['local_amount'] );
		$this->assertNull( $report['entities'][0]['remote_amount'] );
		$this->assertFalse( $report['currency_mismatch'] );
		$this->assertSame( 1, $report['entities'][0]['existing'] );
	}
}
