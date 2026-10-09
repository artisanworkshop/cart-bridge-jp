<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Entities;

use CartBridgeJP\Entities\Commerce\CommerceEntityTypes;
use CartBridgeJP\Entities\Core\ProductType;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Entities\LinkSource;
use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Entities\WarningFlag;
use CartBridgeJP\Tests\Fixtures\Gizmo\GizmoType;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;
use WP_UnitTestCase;

/**
 * `EntityTypeRegistry`（R3-6b1）: 無料版の種類と `cbjp/entity_types/register` の登録・検証・並び・キャッシュ。
 */
final class EntityTypeRegistryTest extends WP_UnitTestCase {

	use RegistersEntityTypes;

	public function tear_down(): void {
		$this->forget_entity_types();
		parent::tear_down();
		$this->forget_entity_types();
	}

	/**
	 * キーと位置だけを返す種類（検証のテスト用）。
	 */
	private static function type( string $key, int $position = 90 ): EntityType {
		return new class( $key, $position ) extends EntityType {

			public function __construct( private readonly string $type_key, private readonly int $type_position ) {}

			public function key(): string {
				return $this->type_key;
			}

			public function label(): string {
				return 'Label ' . $this->type_key;
			}

			public function position(): int {
				return $this->type_position;
			}
		};
	}

	public function test_built_in_and_bundled_types_run_in_the_fixed_order(): void {
		$this->assertSame( [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ], EntityTypeRegistry::keys() );
	}

	public function test_without_the_commerce_registration_only_the_free_types_remain(): void {
		remove_all_filters( EntityTypeRegistry::FILTER );
		EntityTypeRegistry::reset_cache();

		$this->assertSame( [ 'category', 'tag', 'product', 'stock', 'review' ], EntityTypeRegistry::keys() );
	}

	public function test_a_registered_type_is_placed_by_its_position_and_rekeyed(): void {
		$gizmo = new GizmoType();
		add_filter(
			EntityTypeRegistry::FILTER,
			static function ( array $types ) use ( $gizmo ): array {
				$types['wrong-array-key'] = $gizmo;

				return $types;
			}
		);
		EntityTypeRegistry::reset_cache();

		$this->assertSame( [ 'category', 'tag', 'product', 'customer', 'gizmo', 'order', 'stock', 'coupon', 'review' ], EntityTypeRegistry::keys() );
		$this->assertSame( $gizmo, EntityTypeRegistry::get( 'gizmo' ) );
		$this->assertFalse( EntityTypeRegistry::has( 'wrong-array-key' ) );
	}

	public function test_same_position_is_ordered_by_key(): void {
		$this->register_entity_types( [ self::type( 'zeta', 45 ), self::type( 'alpha', 45 ) ] );

		$this->assertSame( [ 'category', 'tag', 'product', 'customer', 'alpha', 'zeta', 'order', 'stock', 'coupon', 'review' ], EntityTypeRegistry::keys() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_registrations(): array {
		return [
			'not an entity type' => [ [ new \stdClass() ] ],
			'key with a hyphen'  => [ [ self::type( 'bad-key' ) ] ],
			'empty key'          => [ [ self::type( '' ) ] ],
			'key over 20 chars'  => [ [ self::type( 'abcdefghijklmnopqrstu' ) ] ],
			'reserved key'       => [ [ self::type( 'variant' ) ] ],
			'built-in key'       => [ [ self::type( 'product' ) ] ],
		];
	}

	/**
	 * @dataProvider invalid_registrations
	 *
	 * @param array<int,mixed> $types
	 */
	public function test_invalid_registrations_are_dropped( array $types ): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );
		add_filter( EntityTypeRegistry::FILTER, static fn ( array $registered ): array => array_merge( $registered, $types ) );
		EntityTypeRegistry::reset_cache();

		$this->assertSame( [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ], EntityTypeRegistry::keys() );
		$this->assertInstanceOf( ProductType::class, EntityTypeRegistry::get( 'product' ) );
	}

	public function test_a_filter_returning_a_non_array_keeps_the_free_types(): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );
		add_filter( EntityTypeRegistry::FILTER, '__return_false', 99 );
		EntityTypeRegistry::reset_cache();

		$this->assertSame( [ 'category', 'tag', 'product', 'stock', 'review' ], EntityTypeRegistry::keys() );
	}

	public function test_the_first_registration_of_a_key_wins(): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );
		$first  = self::type( 'thing', 45 );
		$second = self::type( 'thing', 46 );
		$this->register_entity_types( [ $first, $second ] );

		$this->assertSame( $first, EntityTypeRegistry::get( 'thing' ) );
	}

	public function test_commerce_registration_tolerates_a_misbehaving_earlier_filter(): void {
		$this->assertCount( 3, CommerceEntityTypes::register( false ) );
		$this->assertCount( 4, CommerceEntityTypes::register( [ 'x' ] ) );
	}

	public function test_the_list_is_not_cached_while_plugins_are_loading(): void {
		global $wp_current_filter;
		EntityTypeRegistry::reset_cache();

		// `plugins_loaded` の途中（Pro は優先度 20 で登録する）に呼ばれた結果で固まらない。
		$wp_current_filter[] = 'plugins_loaded'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- `doing_action()` を再現する。
		$this->assertFalse( EntityTypeRegistry::has( 'gizmo' ) );
		$this->register_without_reset( new GizmoType() );
		$this->assertTrue( EntityTypeRegistry::has( 'gizmo' ) );
		array_pop( $wp_current_filter );

		// 読み込みが終わった後の結果はキャッシュする（登録した側が `reset_cache()` を呼ぶ）。
		EntityTypeRegistry::reset_cache();
		$this->assertTrue( EntityTypeRegistry::has( 'gizmo' ) );
		remove_all_filters( EntityTypeRegistry::FILTER );
		$this->assertTrue( EntityTypeRegistry::has( 'gizmo' ) );
	}

	private function register_without_reset( EntityType $type ): void {
		add_filter( EntityTypeRegistry::FILTER, static fn ( array $types ): array => array_merge( $types, [ $type ] ), 30 );
	}

	public function test_support_checks_treat_a_throwing_type_as_unsupported(): void {
		$throwing = new class() extends EntityType {

			public function key(): string {
				return 'boom';
			}

			public function label(): string {
				return 'Boom';
			}

			public function position(): int {
				return 35;
			}

			public function supports_import( \CartBridgeJP\Adapters\PlatformAdapter $adapter ): bool {
				throw new \RuntimeException( 'import' );
			}
		};
		$this->register_entity_types( [ $throwing ] );

		$this->assertSame( [ 'product', 'order' ], EntityTypeRegistry::importable( new MockPlatformAdapter(), [ 'order', 'boom', 'product', 'nope' ] ) );
	}

	public function test_warning_flags_come_from_registered_types_but_not_for_free_codes(): void {
		$this->setExpectedIncorrectUsage( EntityTypeRegistry::FILTER );
		$sneaky = new class() extends EntityType {

			public function key(): string {
				return 'sneaky';
			}

			public function label(): string {
				return 'Sneaky';
			}

			public function position(): int {
				return 90;
			}

			public function warning_flags(): array {
				return [
					'sku_duplicate' => [ WarningFlag::EXPORT_BLOCKING ],
					'sneaky_code'   => [ WarningFlag::EXPORT_BLOCKING, 42 ],
				];
			}
		};
		$this->register_entity_types( [ $sneaky ] );

		$this->assertSame( [], EntityTypeRegistry::warning_flags( 'sku_duplicate' ) );
		$this->assertSame( [ WarningFlag::EXPORT_BLOCKING => true ], EntityTypeRegistry::warning_flags( 'sneaky_code' ) );
		$this->assertSame(
			[
				WarningFlag::UNRESOLVED_REFERENCE => true,
				WarningFlag::MAPPING_REQUIRED     => true,
			],
			EntityTypeRegistry::warning_flags( 'payment_method_unmapped' )
		);
	}

	public function test_reset_cache_also_forgets_the_flag_index(): void {
		$this->assertSame( [], EntityTypeRegistry::warning_flags( 'gizmo_blocked' ) );

		$this->register_entity_types( [ new GizmoType() ] );

		$this->assertSame( [ WarningFlag::EXPORT_BLOCKING => true ], EntityTypeRegistry::warning_flags( 'gizmo_blocked' ) );
	}

	public function test_mapping_kinds_follow_the_type_order(): void {
		$this->assertSame(
			[ 'category', 'payment', 'shipping', 'status' ],
			array_map( static fn ( MappingKind $kind ): string => $kind->key(), EntityTypeRegistry::mapping_kinds() )
		);
	}

	public function test_link_sources_keep_the_rebuild_order(): void {
		$this->assertSame(
			[ 'category', 'tag', 'product', 'variant', 'coupon', 'customer', 'order' ],
			array_map( static fn ( LinkSource $source ): string => $source->key(), EntityTypeRegistry::link_sources() )
		);
	}

	public function test_labels_include_link_sources(): void {
		$labels = EntityTypeRegistry::labels();

		$this->assertSame( 'Products', $labels['product'] );
		$this->assertSame( 'Orders', $labels['order'] );
		$this->assertSame( 'Variations', $labels['variant'] );
		$this->assertSame( [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review', 'variant' ], array_keys( $labels ) );
	}
}
