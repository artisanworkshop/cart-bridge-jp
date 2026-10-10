<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ColorMe\ColorMeCommerceAdapter;
use CartBridgeJP\Adapters\CommerceAdapters;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Fixtures\RegistersCommerceAdapters;
use WP_UnitTestCase;

/**
 * 接続先ごとの `CommerceAdapter` の組み立て（R3-6c1）。フィルターの戻り値は信用しない（原則 8）。
 */
final class CommerceAdaptersTest extends WP_UnitTestCase {

	use RegistersCommerceAdapters;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
	}

	public function tear_down(): void {
		remove_all_filters( CommerceAdapters::FILTER );
		$this->forget_commerce_adapters();
		parent::tear_down();
		$this->forget_commerce_adapters();
	}

	/**
	 * 同梱は ColorMe（型が `ColorMeAdapter` のときだけ。同じキーの別のアダプタ〈検証用の mock〉には作らない）。
	 */
	public function test_colorme_is_bundled_only_for_the_colorme_adapter(): void {
		$this->assertInstanceOf( ColorMeCommerceAdapter::class, CommerceAdapters::get( new ColorMeAdapter() ) );
		$this->assertNull( CommerceAdapters::get( new MockPlatformAdapter( platform_id: ColorMeAdapter::ID ) ) );
		$this->assertNull( CommerceAdapters::get( new MockPlatformAdapter() ) );
	}

	public function test_the_result_is_kept_per_adapter_instance(): void {
		$adapter = new ColorMeAdapter();

		$this->assertSame( CommerceAdapters::get( $adapter ), CommerceAdapters::get( $adapter ) );
		$this->assertNotSame( CommerceAdapters::get( $adapter ), CommerceAdapters::get( new ColorMeAdapter() ) );
	}

	public function test_a_registered_factory_builds_the_adapter_for_its_platform(): void {
		$commerce = new MockCommerceAdapter();
		$this->register_commerce_adapter( $commerce );

		$this->assertSame( $commerce, CommerceAdapters::get( new MockPlatformAdapter() ) );
		$this->assertNull( CommerceAdapters::get( new MockPlatformAdapter( platform_id: 'other' ) ) );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function broken_registrations(): array {
		return [
			'not an array'               => [ 'not-an-array' ],
			'not callable'               => [ [ 'mock' => 'not_a_function_cbjp' ] ],
			'returns another type'       => [ [ 'mock' => static fn (): \stdClass => new \stdClass() ] ],
			// `id()` は一致するが `CommerceAdapter` ではない（無料版のアダプタを返してしまう登録）。
			'returns a platform adapter' => [ [ 'mock' => static fn (): MockPlatformAdapter => new MockPlatformAdapter() ] ],
			'throws'                     => [ [ 'mock' => static fn () => throw new \RuntimeException( 'boom' ) ] ],
			'reports another id'         => [ [ 'mock' => static fn (): MockCommerceAdapter => new MockCommerceAdapter( platform_id: 'other' ) ] ],
			'id throws'                  => [
				[
					'mock' => static fn () => new class() extends \CartBridgeJP\Adapters\CommerceAdapter {

						public function id(): string {
							throw new \RuntimeException( 'id' );
						}

						public function capabilities(): \CartBridgeJP\Adapters\CommerceCapabilities {
							throw new \RuntimeException( 'capabilities' );
						}
					},
				],
			],
		];
	}

	/**
	 * 壊れた登録はその接続先に「無い」（null）とし、記録する（顧客・受注・クーポンは選択肢に出ない）。
	 *
	 * @dataProvider broken_registrations
	 *
	 * @param mixed $registration フィルターの戻り値。
	 */
	public function test_a_broken_registration_counts_as_none_and_is_logged( mixed $registration ): void {
		add_filter( CommerceAdapters::FILTER, static fn () => $registration );
		CommerceAdapters::reset_cache();

		$this->assertNull( CommerceAdapters::get( new MockPlatformAdapter() ) );

		if ( is_array( $registration ) ) {
			$this->assertNotEmpty( ( new LogRepository() )->list( null, 'warning' ) );
		}
	}

	public function test_get_required_throws_when_the_platform_has_none(): void {
		$this->expectException( UnsupportedOperationException::class );

		CommerceAdapters::get_required( new MockPlatformAdapter(), 'fetch_orders' );
	}

	public function test_reset_cache_rebuilds_from_the_filter(): void {
		$adapter = new MockPlatformAdapter();
		$this->assertNull( CommerceAdapters::get( $adapter ) );

		add_filter(
			CommerceAdapters::FILTER,
			static function ( $factories ) {
				$factories['mock'] = static fn ( PlatformAdapter $platform ): MockCommerceAdapter => new MockCommerceAdapter( platform_id: $platform->id() );

				return $factories;
			}
		);

		$this->assertNull( CommerceAdapters::get( $adapter ), 'フィルターを変えても、捨てるまでは前の結果' );

		CommerceAdapters::reset_cache();

		$this->assertInstanceOf( MockCommerceAdapter::class, CommerceAdapters::get( $adapter ) );
	}
}
