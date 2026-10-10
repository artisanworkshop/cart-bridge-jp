<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Adapters;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Pro\Adapters\ColorMe\ColorMeCommerceAdapter;
use CartBridgeJP\Pro\Adapters\CommerceAdapters;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Pro\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
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
	 * 2 つ目は記録する理由（null は記録しない。フィルターが配列を返さないのは「登録が無い」と同じ）。
	 *
	 * @return array<string,array{0:mixed,1:?string}>
	 */
	public static function broken_registrations(): array {
		return [
			'not an array'               => [ 'not-an-array', null ],
			'not callable'               => [ [ 'mock' => 'not_a_function_cbjp' ], 'not_callable' ],
			'returns another type'       => [ [ 'mock' => static fn (): \stdClass => new \stdClass() ], 'not_a_commerce_adapter' ],
			// `id()` は一致するが `CommerceAdapter` ではない（無料版のアダプタを返してしまう登録）。
			'returns a platform adapter' => [ [ 'mock' => static fn (): MockPlatformAdapter => new MockPlatformAdapter() ], 'not_a_commerce_adapter' ],
			'throws'                     => [ [ 'mock' => static fn () => throw new \LogicException( 'boom' ) ], 'LogicException' ],
			'reports another id'         => [ [ 'mock' => static fn (): MockCommerceAdapter => new MockCommerceAdapter( platform_id: 'other' ) ], 'id_mismatch' ],
			// 組み立ては通り、`id()` だけが投げる（組み立ての例外とは別の try。R3-6c1 review-loop R1-1）。
			'id throws'                  => [
				[
					'mock' => static fn () => new class() extends \CartBridgeJP\Pro\Adapters\CommerceAdapter {

						public function id(): string {
							throw new \RuntimeException( 'id' );
						}

						public function capabilities(): \CartBridgeJP\Pro\Adapters\CommerceCapabilities {
							throw new \RuntimeException( 'capabilities' );
						}
					},
				],
				'RuntimeException',
			],
		];
	}

	/**
	 * 壊れた登録はその接続先に「無い」（null）とし、記録する（顧客・受注・クーポンは選択肢に出ない）。
	 *
	 * @dataProvider broken_registrations
	 *
	 * @param mixed   $registration フィルターの戻り値。
	 * @param ?string $reason       記録する理由（null は記録しない）。
	 */
	public function test_a_broken_registration_counts_as_none_and_is_logged( mixed $registration, ?string $reason ): void {
		add_filter( CommerceAdapters::FILTER, static fn () => $registration );
		CommerceAdapters::reset_cache();

		$this->assertNull( CommerceAdapters::get( new MockPlatformAdapter() ) );

		$reasons = array_map(
			static fn ( array $log ): mixed => json_decode( (string) $log['context_json'], true )['reason'] ?? null,
			( new LogRepository() )->list( null, 'warning' )
		);

		$this->assertSame( null === $reason ? [] : [ $reason ], $reasons );
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
