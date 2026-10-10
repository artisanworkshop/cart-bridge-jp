<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Entities;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Admin\DryRunReportCsv;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\JobManager;
use CartBridgeJP\Sync\JobRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Sync\VerificationReport;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Export\AdapterPlatformWriter;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Tools\PushIntentPresenter;
use CartBridgeJP\Woo\Tools\PushIntentResolutionException;
use CartBridgeJP\Woo\Tools\PushIntentResolver;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\WooReaderRepositoryFactory;
use CartBridgeJP\Woo\WooRepositoryFactory;
use ReflectionClass;
use RuntimeException;
use WP_UnitTestCase;

/**
 * 実体の種類をレジストリへ移す（R3-6b1）前の振る舞いを固定する特性テスト。
 *
 * 実体ごとの分岐は `JobManager`・`Importer`・ファクトリ・`AdapterPlatformWriter`・ツール・警告の判定に散らばっていて、
 * 既存のテストは実行順やキー順を並べ替えてから比べたり、該当しない実体の扱いを固定していなかったりする。
 * レジストリへ移した後も、ここで固定した結果（順序・能力の組み合わせ・該当しない実体・警告の印・CSV の note・カタログの文言）が
 * 変わらないことを確かめる。
 */
final class DispatchCharacterizationTest extends WP_UnitTestCase {

	/**
	 * 警告の判定関数ごとに、真になるコード（`WarningCode` の全定数のうち）。R3-6b1 の前のコードで算出した。
	 *
	 * @var array<string,array<int,string>>
	 */
	private const PREDICATE_TABLE = [
		'export_blocking'            => [ 'all_variations_excluded', 'coupon_restrictions_unsupported', 'currency_mismatch', 'order_line_amount_invalid', 'order_line_product_deleted', 'order_line_product_missing', 'order_line_quantity_invalid', 'order_line_tax_class_unsupported', 'order_line_variation_unresolved', 'order_refunded', 'order_totals_invalid', 'price_tax_basis_unresolved', 'product_price_invalid', 'stock_product_not_exported', 'tax_class_unsupported', 'tax_status_not_taxable', 'variation_any_attribute_unsupported', 'variation_axis_limit_exceeded', 'variation_tax_class_unsupported' ],
		'unresolved_reference'       => [ 'category_map_unresolved', 'category_parent_unresolved', 'category_ref_unresolved', 'order_customer_not_exported', 'order_customer_unresolved', 'order_line_product_not_exported', 'order_line_product_unresolved', 'order_line_variation_unmatched', 'payment_method_unmapped', 'product_details_push_incomplete', 'product_image_push_incomplete', 'product_variant_push_incomplete', 'push_interrupted_after_create', 'shipping_method_unmapped', 'tag_ref_unresolved' ],
		'pending_import'             => [ 'category_parent_unresolved', 'category_ref_unresolved', 'product_details_push_incomplete', 'product_image_push_incomplete', 'product_variant_push_incomplete', 'push_interrupted_after_create', 'stock_product_unresolved', 'tag_ref_unresolved' ],
		'order_reference_unresolved' => [ 'order_customer_unresolved', 'order_line_product_unresolved' ],
		'pending_export'             => [ 'order_customer_not_exported', 'order_line_product_not_exported', 'stock_product_not_exported' ],
		'mapping_required'           => [ 'category_map_unresolved', 'payment_method_unmapped', 'shipping_method_unmapped' ],
		'tax_setup_required'         => [ 'reduced_tax_class_not_found', 'tax_rates_not_configured' ],
		'kept_by_link_direction'     => [ 'linked_by_export_not_imported' ],
		'variation_stock_mixed'      => [ 'variation_stock_management_mixed' ],
		'reduced_tax_class_fallback' => [ 'reduced_tax_class_not_found' ],
	];

	/**
	 * CSV の `note` 列が空でないコード（`DryRunReportCsv` の判定の優先順の結果）。
	 *
	 * @var array<string,string>
	 */
	private const CSV_NOTES = [
		'category_map_unresolved'         => 'mapping_required',
		'category_parent_unresolved'      => 'reference_pending_import',
		'category_ref_unresolved'         => 'reference_pending_import',
		'order_customer_not_exported'     => 'reference_pending_export',
		'order_customer_unresolved'       => 'reference_unresolved',
		'order_line_product_not_exported' => 'reference_pending_export',
		'order_line_product_unresolved'   => 'reference_unresolved',
		'payment_method_unmapped'         => 'mapping_required',
		'product_details_push_incomplete' => 'reference_pending_import',
		'product_image_push_incomplete'   => 'reference_pending_import',
		'product_variant_push_incomplete' => 'reference_pending_import',
		'push_interrupted_after_create'   => 'reference_pending_import',
		'reduced_tax_class_not_found'     => 'tax_setup_required',
		'shipping_method_unmapped'        => 'mapping_required',
		'stock_product_not_exported'      => 'reference_pending_export',
		'stock_product_unresolved'        => 'reference_pending_import',
		'tag_ref_unresolved'              => 'reference_pending_import',
		'tax_rates_not_configured'        => 'tax_setup_required',
	];

	/**
	 * `WarningCatalog::describe()` の全結果（全コード × 向き × 行の種類 × detail の有無）の sha256。R3-6b1 の前のコードで算出した。
	 * カタログの分岐を移しても文言・重大度・対処が 1 文字も変わらないことを確かめる。変わったら、意図した変更かを確かめてから更新する。
	 */
	private const CATALOG_SHA256 = 'df668212267e79492ab247d48379d0c3524033866284b316bc1e4c37f64c2c57';

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		parent::tear_down();
	}

	/**
	 * @return array<int,string>
	 */
	private static function all_codes(): array {
		$codes = array_values( array_filter( ( new ReflectionClass( WarningCode::class ) )->getConstants(), 'is_string' ) );
		sort( $codes );

		return $codes;
	}

	public function test_warning_predicates_match_the_table(): void {
		$predicates = [
			'export_blocking'            => static fn ( string $w ): bool => WarningCode::indicates_export_blocking( [ $w ] ),
			'unresolved_reference'       => static fn ( string $w ): bool => WarningCode::indicates_unresolved_reference( [ $w ] ),
			'pending_import'             => static fn ( string $w ): bool => WarningCode::indicates_pending_import( $w ),
			'order_reference_unresolved' => static fn ( string $w ): bool => WarningCode::indicates_order_reference_unresolved( $w ),
			'pending_export'             => static fn ( string $w ): bool => WarningCode::indicates_pending_export( $w ),
			'mapping_required'           => static fn ( string $w ): bool => WarningCode::indicates_mapping_required( $w ),
			'tax_setup_required'         => static fn ( string $w ): bool => WarningCode::indicates_tax_setup_required( $w ),
			'kept_by_link_direction'     => static fn ( string $w ): bool => WarningCode::indicates_kept_by_link_direction( [ $w ] ),
			'variation_stock_mixed'      => static fn ( string $w ): bool => WarningCode::indicates_variation_stock_mixed( [ $w ] ),
			'reduced_tax_class_fallback' => static fn ( string $w ): bool => WarningCode::indicates_reduced_tax_class_fallback( [ $w ] ),
		];

		$actual = [];

		foreach ( $predicates as $name => $predicate ) {
			$actual[ $name ] = [];

			foreach ( self::all_codes() as $code ) {
				$plain       = $predicate( $code );
				$with_detail = $predicate( WarningCode::with_detail( $code, 'detail:with-colon' ) );

				$this->assertSame( $plain, $with_detail, "{$name}: {$code} の判定が detail の有無で変わりました。" );

				if ( $plain ) {
					$actual[ $name ][] = $code;
				}
			}
		}

		$this->assertSame( self::PREDICATE_TABLE, $actual );
	}

	public function test_csv_note_column_for_every_code(): void {
		$items = new DryRunItemRepository();
		$rows  = [];

		foreach ( self::all_codes() as $index => $code ) {
			$rows[] = [
				'entity'            => 'product',
				'remote_id'         => 'r' . $index,
				'label'             => '',
				'operation'         => 'created',
				'existing_local_id' => 0,
				'warnings'          => [ $code ],
			];
		}

		$items->insert_many( 'run-notes', 1, $rows );

		$notes = [];

		foreach ( ( new DryRunReportCsv( $items ) )->rows( 'run-notes', null, true, WarningCatalog::IMPORT ) as $row ) {
			if ( '' !== $row[7] ) {
				$notes[ $row[5] ] = $row[7];
			}
		}

		ksort( $notes );

		$this->assertSame( self::CSV_NOTES, $notes );
	}

	public function test_catalog_texts_are_unchanged(): void {
		$entities = [ '', 'category', 'tag', 'product', 'variant', 'customer', 'order', 'stock', 'coupon', 'review', 'Order', 'widget' ];
		$lines    = [];

		foreach ( self::all_codes() as $code ) {
			foreach ( [ WarningCatalog::IMPORT, WarningCatalog::EXPORT, 'sideways' ] as $direction ) {
				foreach ( $entities as $entity ) {
					foreach ( [ $code, WarningCode::with_detail( $code, 'DETAIL' ) ] as $warning ) {
						$lines[] = wp_json_encode( [ $warning, $direction, $entity, WarningCatalog::describe( $warning, $direction, $entity ) ] );
					}
				}
			}
		}

		$this->assertSame( self::CATALOG_SHA256, hash( 'sha256', implode( "\n", $lines ) ) );
	}

	/**
	 * @return array<string,array{0:string,1:?Capabilities,2:array<int,string>,3:array<int,string>}>
	 */
	public static function run_entity_cases(): array {
		$all       = [ 'review', 'coupon', 'stock', 'order', 'customer', 'product', 'tag', 'category', 'widget', 'variant' ];
		$caps      = static fn ( array $overrides ): Capabilities => new Capabilities(
			$overrides['can_create_category'] ?? true,
			$overrides['can_create_order'] ?? true,
			$overrides['can_fetch_customers'] ?? true,
			$overrides['can_update_customer'] ?? true,
			true,
			$overrides['can_create_coupon'] ?? true,
			$overrides['has_coupons'] ?? true,
			$overrides['has_tags'] ?? true,
			$overrides['has_reviews'] ?? true,
			true,
			600
		);
		$import    = JobManager::TYPE_IMPORT;
		$export    = JobManager::TYPE_EXPORT;
		$dry_run   = JobManager::TYPE_DRY_RUN;
		$dry_out   = JobManager::TYPE_DRY_RUN_EXPORT;
		$full_in   = [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ];
		$full_out  = [ 'product', 'customer', 'order', 'stock', 'coupon' ];
		$without   = static fn ( array $entities, string $entity ): array => array_values( array_diff( $entities, [ $entity ] ) );
		$no_coupon = $without( $full_out, 'coupon' );

		return [
			'import all'                       => [ $import, null, $all, $full_in ],
			'dry run all'                      => [ $dry_run, null, $all, $full_in ],
			'import without tags'              => [ $import, $caps( [ 'has_tags' => false ] ), $all, $without( $full_in, 'tag' ) ],
			'import without coupons'           => [ $import, $caps( [ 'has_coupons' => false ] ), $all, $without( $full_in, 'coupon' ) ],
			'import without reviews'           => [ $import, $caps( [ 'has_reviews' => false ] ), $all, $without( $full_in, 'review' ) ],
			'import without customers'         => [ $import, $caps( [ 'can_fetch_customers' => false ] ), $all, $without( $full_in, 'customer' ) ],
			'import ignores export-only flags' => [
				$import,
				$caps(
					[
						'can_create_order'    => false,
						'can_update_customer' => false,
						'can_create_coupon'   => false,
					]
				),
				$all,
				$full_in,
			],
			'import subset'                    => [ $import, null, [ 'order', 'product' ], [ 'product', 'order' ] ],
			'export all'                       => [ $export, null, $all, $full_out ],
			'dry run export all'               => [ $dry_out, null, $all, $full_out ],
			'export without customer update'   => [ $export, $caps( [ 'can_update_customer' => false ] ), $all, $without( $full_out, 'customer' ) ],
			'export without order create'      => [ $export, $caps( [ 'can_create_order' => false ] ), $all, $without( $full_out, 'order' ) ],
			'export without coupons'           => [ $export, $caps( [ 'has_coupons' => false ] ), $all, $no_coupon ],
			'export without coupon create'     => [ $export, $caps( [ 'can_create_coupon' => false ] ), $all, $no_coupon ],
			'export ignores import-only flags' => [
				$export,
				$caps(
					[
						'can_fetch_customers' => false,
						'has_tags'            => false,
						'has_reviews'         => false,
					]
				),
				$all,
				$full_out,
			],
			'export subset'                    => [ $export, null, [ 'stock', 'category', 'product' ], [ 'product', 'stock' ] ],
		];
	}

	/**
	 * @dataProvider run_entity_cases
	 *
	 * @param array<int,string> $requested
	 * @param array<int,string> $expected
	 */
	public function test_run_creates_jobs_in_the_fixed_order_for_supported_entities( string $type, ?Capabilities $capabilities, array $requested, array $expected ): void {
		$this->register( new MockPlatformAdapter( capabilities_override: $capabilities ) );

		$run_id = JobManager::create()->start_run( $type, 'mock', $requested );
		$jobs   = ( new JobRepository() )->find_by_run( $run_id );

		$this->assertSame( $expected, array_map( static fn ( array $job ): string => (string) $job['entity'], $jobs ) );
	}

	public function test_run_with_no_supported_entity_is_rejected(): void {
		$this->register( new MockPlatformAdapter() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'No supported entities to run.' );

		JobManager::create()->start_run( JobManager::TYPE_EXPORT, 'mock', [ 'category', 'tag', 'review' ] );
	}

	public function test_platform_writer_skips_entities_without_a_push(): void {
		$writer = new AdapterPlatformWriter( new MockPlatformAdapter() );
		$item   = new CanonicalCategory( 'c1', 'Category', null, null );

		foreach ( [ 'category', 'tag', 'review', 'widget' ] as $entity ) {
			$result = $writer->write( $entity, $item, null );

			$this->assertSame( '', $result->remote_id, $entity );
			$this->assertSame( PushResult::OPERATION_SKIPPED, $result->operation, $entity );
			$this->assertSame( [ WarningCode::ENTITY_NOT_SUPPORTED ], $result->warnings, $entity );
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function pushable_entities(): array {
		return [
			'product'  => [ 'product' ],
			'customer' => [ 'customer' ],
			'order'    => [ 'order' ],
			'stock'    => [ 'stock' ],
			'coupon'   => [ 'coupon' ],
		];
	}

	/**
	 * @dataProvider pushable_entities
	 */
	public function test_platform_writer_rejects_a_model_of_another_type( string $entity ): void {
		$writer = new AdapterPlatformWriter( new MockPlatformAdapter( push_products_supported: true, push_others_supported: true ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "AdapterPlatformWriter received an unsupported Canonical model for \"{$entity}\"." );

		$writer->write( $entity, new CanonicalCategory( 'c1', 'Category', null, null ), null );
	}

	public function test_woo_writer_skips_entities_without_a_writer(): void {
		$item = new CanonicalCategory( 'c1', 'Category', null, null );

		foreach ( [ 'review', 'widget' ] as $entity ) {
			foreach ( [
				'real' => ( new WooRepositoryFactory() )->for_platform( 'mock' ),
				'dry'  => ( new WooRepositoryFactory() )->for_dry_run( 'mock' ),
			] as $mode => $writer ) {
				$result = $writer->write( $entity, $item, null );

				$this->assertSame( 0, $result->local_id, "{$mode} {$entity}" );
				$this->assertSame( [ WarningCode::ENTITY_NOT_SUPPORTED ], $result->warnings, "{$mode} {$entity}" );
			}
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function entities_without_a_reader(): array {
		return [
			'category' => [ 'category' ],
			'tag'      => [ 'tag' ],
			'review'   => [ 'review' ],
			'widget'   => [ 'widget' ],
		];
	}

	/**
	 * @dataProvider entities_without_a_reader
	 */
	public function test_woo_reader_rejects_entities_without_a_reader( string $entity ): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( "No Woo reader registered for entity \"{$entity}\"." );

		( new WooReaderRepositoryFactory() )->for_platform( 'mock' )->read( $entity, new Cursor() );
	}

	public function test_importer_rejects_an_unknown_entity(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Entity "widget" is not a cursor-walk entity.' );

		( new Importer( new MappingRepository() ) )->run_page( new MockPlatformAdapter(), ( new WooRepositoryFactory() )->for_dry_run( 'mock' ), 'widget', new Cursor(), true );
	}

	public function test_one_writer_per_page_warns_about_prices_excluding_tax_only_once(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );

		$writer = ( new WooRepositoryFactory() )->for_platform( 'mock' );
		$first  = $writer->write( 'product', CanonicalFactory::product( 'p1', 'SKU-1' ), null );
		$second = $writer->write( 'product', CanonicalFactory::product( 'p2', 'SKU-2' ), null );

		$this->assertContains( WarningCode::PRICES_INCLUDE_TAX_DISABLED, $first->warnings );
		$this->assertNotContains( WarningCode::PRICES_INCLUDE_TAX_DISABLED, $second->warnings );
	}

	public function test_unknown_push_intent_entity_is_reported_as_remote_not_found(): void {
		$intents = new PushIntentRepository();
		$intents->begin( 'mock', 'widget', 303, null, null );
		$id = $intents->find_unresolved( 'mock' )[0]['id'];

		try {
			( new PushIntentResolver( $intents, new MappingRepository() ) )->resolve_link( 'mock', $id, new MockPlatformAdapter(), 'anything' );
			$this->fail( '例外が出ませんでした。' );
		} catch ( PushIntentResolutionException $exception ) {
			$this->assertSame( PushIntentResolutionException::REMOTE_NOT_FOUND, $exception->reason() );
		}
	}

	public function test_unknown_entities_have_no_local_details(): void {
		$post_id = self::factory()->post->create();

		$this->assertSame(
			[
				'exists'   => false,
				'edit_url' => null,
				'summary'  => '',
				'details'  => [],
			],
			( new PushIntentPresenter() )->describe( 'widget', $post_id )
		);
		$this->assertSame( [], ( new LocalEntityLookup() )->existing_ids( 'widget', [ $post_id ] ) );
	}

	public function test_verification_counts_unknown_entities_as_missing(): void {
		$jobs   = new JobRepository();
		$job_id = $jobs->create( 'run-widget', JobManager::TYPE_IMPORT, 'mock', 'widget' );
		$jobs->update_status( $job_id, JobRepository::STATUS_COMPLETED );

		$mappings = new MappingRepository();
		$mappings->upsert( 'mock', 'widget', 'w1', self::factory()->post->create(), null );
		$mappings->upsert( 'mock', 'widget', 'w2', self::factory()->post->create(), null );

		$row = ( new VerificationReport( $jobs, $mappings ) )->build( 'run-widget' )['entities'][0];

		$this->assertSame( 'widget', $row['entity'] );
		$this->assertSame( 2, $row['linked'] );
		$this->assertSame( 0, $row['existing'] );
		$this->assertSame( 2, $row['missing'] );
		$this->assertNull( $row['remote_amount'] );
		$this->assertNull( $row['local_amount'] );
	}

	private function register( MockPlatformAdapter $adapter ): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) use ( $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();
	}
}
