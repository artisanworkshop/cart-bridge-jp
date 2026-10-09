<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Entities;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Admin\DryRunReportCsv;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Sync\VerificationReport;
use CartBridgeJP\Tests\Fixtures\Gizmo\CanonicalGizmo;
use CartBridgeJP\Tests\Fixtures\Gizmo\GizmoType;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;
use CartBridgeJP\Woo\Tools\MappingRebuilder;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\WarningCode;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * 外部の実体の種類（Pro アドオンが `cbjp/entity_types/register` で足す種類と同じ形のテスト用の `gizmo`）が、取込み・エクスポート・
 * push intent・D25・リンク再構築・検証レポート・dry-run の CSV・マッピング・REST の拡張点を通しで通ることを確かめる（R3-6b1）。
 */
final class ExternalEntityTypeTest extends WP_UnitTestCase {

	use RegistersEntityTypes;

	private GizmoType $gizmo;

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$adapter = new MockPlatformAdapter(
			mapping_candidates_override: [
				'gizmo' => [
					[
						'id'   => '1',
						'name' => 'Blue',
					],
				],
			],
			push_products_supported: true,
			push_others_supported: true
		);
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$this->gizmo = new GizmoType();
		$this->register_entity_types( [ $this->gizmo ] );

		// report の `entity` の enum はルートを登録した時点のレジストリから作るので、種類を登録した後に組み立てる。
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。

		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		$this->forget_entity_types();
		parent::tear_down();
		$this->forget_entity_types();
	}

	/**
	 * @param array<int,string> $entities
	 */
	private function run_entities( string $type, array $entities ): string {
		$manager = JobManager::create();
		$run_id  = $manager->start_run( $type, 'mock', $entities );
		$manager->run_to_completion( $run_id );

		return $run_id;
	}

	/**
	 * @return array<int,string>
	 */
	private function job_entities( string $run_id ): array {
		return array_map( static fn ( array $job ): string => (string) $job['entity'], ( new JobRepository() )->find_by_run( $run_id ) );
	}

	private function local_gizmo( string $name, bool $blocked = false ): int {
		$post_id = self::factory()->post->create( [ 'post_title' => $name ] );
		update_post_meta( $post_id, '_gizmo', '1' );
		update_post_meta( $post_id, '_gizmo_amount', '5' );

		if ( $blocked ) {
			update_post_meta( $post_id, '_gizmo_blocked', '1' );
		}

		return $post_id;
	}

	public function test_the_type_runs_in_its_position(): void {
		$manager = JobManager::create();
		$import  = $manager->start_run( JobManager::TYPE_DRY_RUN, 'mock', [ 'order', 'gizmo', 'customer', 'product' ] );
		( new JobRepository() )->cancel_run( $import );
		$export = $manager->start_run( JobManager::TYPE_DRY_RUN_EXPORT, 'mock', [ 'order', 'gizmo', 'customer', 'product' ] );

		$this->assertSame( [ 'product', 'customer', 'gizmo', 'order' ], $this->job_entities( $import ) );
		$this->assertSame( [ 'product', 'customer', 'gizmo', 'order' ], $this->job_entities( $export ) );
	}

	public function test_import_writes_links_and_reports_the_amounts(): void {
		$this->gizmo->remote = [
			new CanonicalGizmo( 'g1', 'Alpha', 10 ),
			new CanonicalGizmo( 'g2', 'Beta', 20 ),
			new CanonicalGizmo( 'g3', 'Gamma', 30 ),
		];

		$run_id = $this->run_entities( JobManager::TYPE_IMPORT, [ 'gizmo' ] );

		$mappings = new MappingRepository();
		$local_id = $mappings->find_local_id( 'mock', 'gizmo', 'g2' );
		$this->assertNotNull( $local_id );
		$this->assertSame( 'Beta', get_the_title( $local_id ) );
		$this->assertSame( 3, $mappings->count( 'mock', 'gizmo' ) );

		$row = ( new VerificationReport( new JobRepository(), $mappings ) )->build( $run_id )['entities'][0];

		$this->assertSame( 'gizmo', $row['entity'] );
		$this->assertSame( 3, $row['existing'] );
		$this->assertSame( 0, $row['missing'] );
		$this->assertSame( '60.00', $row['remote_amount'] );
		$this->assertSame( '60.00', $row['local_amount'] );
	}

	public function test_dry_run_csv_uses_the_types_label_flags_and_texts(): void {
		$this->gizmo->remote = [ new CanonicalGizmo( 'g1', 'pending part', 10 ) ];

		$run_id = $this->run_entities( JobManager::TYPE_DRY_RUN, [ 'gizmo' ] );
		$rows   = ( new DryRunReportCsv( new DryRunItemRepository() ) )->rows( $run_id, 'gizmo', true, WarningCatalog::IMPORT );

		$this->assertCount( 1, $rows );
		$this->assertSame(
			[ 'gizmo', 'g1', 'pending part', 'created', '0', 'gizmo_pending', 'g1', 'reference_pending_import', 'action_required', 'A gizmo part is not imported yet.', 'Import the parts first.' ],
			$rows[0]
		);
	}

	public function test_registered_flags_drive_the_shared_predicates(): void {
		$this->assertTrue( WarningCode::indicates_unresolved_reference( [ 'gizmo_pending:g1' ] ) );
		$this->assertTrue( WarningCode::indicates_pending_import( 'gizmo_pending:g1' ) );
		$this->assertTrue( WarningCode::indicates_export_blocking( [ 'gizmo_blocked' ] ) );
		$this->assertFalse( WarningCode::indicates_export_blocking( [ 'gizmo_pending' ] ) );
	}

	public function test_an_unresolved_import_does_not_cache_the_checksum(): void {
		$this->gizmo->remote = [ new CanonicalGizmo( 'g1', 'pending part', 10 ) ];

		$this->run_entities( JobManager::TYPE_IMPORT, [ 'gizmo' ] );

		$this->assertNull( ( new MappingRepository() )->find_checksum( 'mock', 'gizmo', 'g1' ) );
	}

	public function test_export_pushes_local_gizmos_but_not_imported_or_blocked_ones(): void {
		$this->gizmo->remote = [ new CanonicalGizmo( 'g1', 'Imported', 10 ) ];
		$this->run_entities( JobManager::TYPE_IMPORT, [ 'gizmo' ] );
		$local   = $this->local_gizmo( 'Local' );
		$blocked = $this->local_gizmo( 'Blocked', true );

		$this->run_entities( JobManager::TYPE_EXPORT, [ 'gizmo' ] );

		$this->assertSame( [ 'Local' ], array_map( static fn ( array $push ): string => $push[0]->name, $this->gizmo->pushed ) );
		$this->assertSame( '900', ( new MappingRepository() )->find_remote_id( 'mock', 'gizmo', $local ) );
		$this->assertNull( ( new MappingRepository() )->find_remote_id( 'mock', 'gizmo', $blocked ) );
	}

	public function test_import_does_not_overwrite_a_gizmo_linked_by_export(): void {
		$local = $this->local_gizmo( 'Made in Woo' );
		( new MappingRepository() )->upsert( 'mock', 'gizmo', 'g9', $local, null );
		$this->gizmo->remote = [ new CanonicalGizmo( 'g9', 'Platform name', 10 ) ];

		$this->run_entities( JobManager::TYPE_IMPORT, [ 'gizmo' ] );

		$this->assertSame( 'Made in Woo', get_the_title( $local ) );
	}

	public function test_a_failed_create_leaves_an_intent_that_can_be_described_and_linked(): void {
		$local                       = $this->local_gizmo( 'Unsure' );
		$this->gizmo->create_failure = new ApiException( 'upstream', 503 );

		$this->run_entities( JobManager::TYPE_EXPORT, [ 'gizmo' ] );

		$list = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/push-intents/mock' ) )->get_data()['intents'];
		$this->assertCount( 1, $list );
		$this->assertSame( 'gizmo', $list[0]['entity_type'] );
		$this->assertTrue( $list[0]['exists'] );
		$this->assertSame( [ 'name' => 'Unsure' ], $list[0]['details'] );

		$this->gizmo->remote = [ new CanonicalGizmo( 'g7', 'Unsure', 5 ) ];
		$resolve             = new WP_REST_Request( 'POST', '/cbjp/v1/push-intents/mock/' . $list[0]['id'] . '/resolve' );
		$resolve->set_body_params(
			[
				'action'    => 'link',
				'remote_id' => 'g7',
			]
		);

		$this->assertSame( 200, $this->server->dispatch( $resolve )->get_status() );
		$this->assertSame( $local, ( new MappingRepository() )->find_local_id( 'mock', 'gizmo', 'g7' ) );
		$this->assertFalse( ( new PushIntentRepository() )->has_unresolved( 'mock', 'gizmo', $local ) );
	}

	public function test_link_rebuild_restores_gizmo_links_in_position_order(): void {
		$this->gizmo->remote = [ new CanonicalGizmo( 'g1', 'Alpha', 10 ) ];
		$this->run_entities( JobManager::TYPE_IMPORT, [ 'gizmo' ] );
		$mappings = new MappingRepository();
		$local_id = $mappings->find_local_id( 'mock', 'gizmo', 'g1' );
		$mappings->delete_for_platform( 'mock' );

		$result = ( new MappingRebuilder( $mappings ) )->run( 'mock' );

		$this->assertNull( $result['cursor'] );
		$this->assertSame( [ 'category', 'tag', 'product', 'variant', 'gizmo', 'coupon', 'customer', 'order' ], array_keys( $result['counts'] ) );
		$this->assertSame( 1, $result['counts']['gizmo'] );
		$this->assertSame( $local_id, $mappings->find_local_id( 'mock', 'gizmo', 'g1' ) );
	}

	public function test_connections_offer_the_type_with_its_beta_flag(): void {
		$connections = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/connections' ) )->get_data();
		$connection  = array_values( array_filter( $connections, static fn ( array $item ): bool => 'mock' === $item['platform'] ) )[0];

		$this->assertContains(
			[
				'key'   => 'gizmo',
				'label' => 'Gizmos',
			],
			$connection['entities']['import']
		);
		$this->assertContains(
			[
				'key'   => 'gizmo',
				'label' => 'Gizmos',
				'beta'  => true,
			],
			$connection['entities']['export']
		);
		$this->assertSame( [ 'product', 'customer', 'gizmo', 'order', 'stock', 'coupon' ], array_column( $connection['entities']['export'], 'key' ) );
		$this->assertSame( [ false, false, true, false, false, false ], array_column( $connection['entities']['export'], 'beta' ) );
	}

	public function test_mapping_kinds_are_listed_and_unregistered_maps_survive_a_save(): void {
		$data = $this->server->dispatch( new WP_REST_Request( 'GET', '/cbjp/v1/settings/mappings/mock' ) )->get_data();

		$this->assertContains(
			[
				'key'           => 'gizmo',
				'map_key'       => 'gizmo_map',
				'source_side'   => 'asp',
				'applies'       => true,
				'import_notice' => true,
			],
			$data['kinds']
		);
		// 種類の実行順（gizmo 45 は受注 50 より前）→ 種類の中の順。
		$this->assertSame( [ 'category', 'gizmo', 'payment', 'shipping', 'status' ], array_column( $data['kinds'], 'key' ) );
		$this->assertSame(
			[
				[
					'id'   => '1',
					'name' => 'Blue',
				],
			],
			$data['asp_candidates']['gizmo']
		);
		// Woo 側の候補も ASP 側と同じ正規化（保存時と同じ形）を通る。
		$this->assertSame(
			[
				[
					'id'   => 'red',
					'name' => 'Red',
				],
				[
					'id'   => 'blue',
					'name' => 'Blue',
				],
			],
			$data['woo_candidates']['gizmo']
		);

		$save = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/mock' );
		$save->set_body_params( [ 'gizmo_map' => [ '1' => 'red' ] ] );
		$this->assertSame( 200, $this->server->dispatch( $save )->get_status() );

		// Pro アドオンを止めた状態（種類の登録が無い）で、ほかのマッピングを保存しても gizmo_map は消えない。
		remove_all_filters( EntityTypeRegistry::FILTER );
		EntityTypeRegistry::reset_cache();
		$save = new WP_REST_Request( 'PUT', '/cbjp/v1/settings/mappings/mock' );
		$save->set_body_params( [ 'category_map' => [ '5' => '7' ] ] );
		$saved = $this->server->dispatch( $save )->get_data();

		$this->assertArrayNotHasKey( 'gizmo_map', $saved );
		$this->assertSame( [ '1' => 'red' ], get_option( 'cbjp_settings_mock' )['gizmo_map'] );
		$this->assertSame( [ '5' => '7' ], get_option( 'cbjp_settings_mock' )['category_map'] );
	}

	public function test_report_entity_filter_accepts_registered_types_only(): void {
		$run_id = $this->run_entities( JobManager::TYPE_DRY_RUN, [ 'gizmo' ] );

		$gizmo = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/report" );
		$gizmo->set_query_params( [ 'entity' => 'gizmo' ] );
		$widget = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/report" );
		$widget->set_query_params( [ 'entity' => 'widget' ] );

		$this->assertSame( 200, $this->server->dispatch( $gizmo )->get_status() );
		$this->assertSame( 400, $this->server->dispatch( $widget )->get_status() );

		remove_all_filters( 'rest_pre_serve_request' );
	}

	/**
	 * 登録された種類の説明も、CSV を書く間だけ切り替えたユーザーの言語で翻訳される（説明を保持しない。R3-0k と同じ）。
	 */
	public function test_registered_texts_are_written_in_the_users_locale(): void {
		$this->gizmo->remote = [ new CanonicalGizmo( 'g1', 'pending part', 10 ) ];
		$run_id              = $this->run_entities( JobManager::TYPE_DRY_RUN, [ 'gizmo' ] );
		update_user_meta( get_current_user_id(), 'locale', 'en_US' );
		add_filter( 'locale', static fn (): string => 'ja', 1 );

		// 先に言語を切り替えずに一度説明させる（結果を保持する実装なら、ここで翻訳した文言が CSV に残る）。
		WarningCatalog::describe( 'gizmo_pending', WarningCatalog::IMPORT, 'gizmo' );

		$request  = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}/report" );
		$response = $this->server->dispatch( $request );
		$seen     = [];
		$recorder = static function ( string $translation, string $text, string $domain ) use ( &$seen ): string {
			if ( 'cart-bridge-jp' === $domain && 'A gizmo part is not imported yet.' === $text ) {
				$seen[] = determine_locale();
			}

			return $translation;
		};
		add_filter( 'gettext', $recorder, 10, 3 );

		ob_start();
		apply_filters( 'rest_pre_serve_request', false, $response, $request, $this->server );
		$output = (string) ob_get_clean();

		remove_filter( 'gettext', $recorder, 10 );
		remove_all_filters( 'rest_pre_serve_request' );
		restore_current_locale();

		$this->assertStringContainsString( 'A gizmo part is not imported yet.', $output );
		$this->assertSame( [ 'en_US' ], array_values( array_unique( $seen ) ) );
	}

	/**
	 * 外部の種類の `remote_amount()`・`dry_run_label()` が例外を投げても、ページを止めず 0・空として扱い、原因を記録する。
	 */
	public function test_a_type_failing_on_an_item_does_not_stop_the_page(): void {
		$this->gizmo->remote          = [ new CanonicalGizmo( 'g1', 'Alpha', 10 ), new CanonicalGizmo( 'g2', 'Beta', 20 ) ];
		$this->gizmo->explode_on_item = true;

		$run_id = $this->run_entities( JobManager::TYPE_DRY_RUN, [ 'gizmo' ] );
		$job    = ( new JobRepository() )->find_by_run( $run_id )[0];
		$totals = json_decode( (string) $job['totals_json'], true );
		$rows   = ( new DryRunReportCsv( new DryRunItemRepository() ) )->rows( $run_id, 'gizmo', false, WarningCatalog::IMPORT );
		$errors = array_column( ( new LogRepository() )->list( (int) $job['id'], 'error' ), 'message' );

		$this->assertSame( JobRepository::STATUS_COMPLETED, $job['status'] );
		$this->assertSame( 2, $totals['processed'] );
		$this->assertSame( 0, $totals['remote_amount'] );
		$this->assertSame( [ '', '' ], array_column( $rows, 2 ) );
		$this->assertContains( 'Entity type failed to report the amount of a gizmo item.', $errors );
		$this->assertContains( 'Entity type failed to build a dry-run label.', array_column( ( new LogRepository() )->list( null, 'error' ), 'message' ) );
	}
}
