<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Adapters;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ColorMe\ColorMeOAuth;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Pro\Adapters\ColorMe\ColorMeCommerceAdapter;
use CartBridgeJP\Pro\Adapters\CommerceAdapters;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Pro\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\TokenStore;
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

	/**
	 * R3-6c2 review-loop R1-1: 接続済みのトークンにスコープが無いだけなら「扱えない」ではなく「未接続」（接続し直せば扱える）。
	 */
	public function test_get_required_asks_to_reconnect_when_the_colorme_token_lacks_the_scopes(): void {
		( new TokenStore( ColorMeAdapter::ID ) )->save(
			[
				'access_token' => 'token',
				'scopes'       => [ 'read_products', 'write_products' ],
			]
		);

		try {
			CommerceAdapters::get_required( new ColorMeAdapter(), 'fetch_customer_by_remote_id' );
			$this->fail( 'ApiException was not thrown.' );
		} catch ( ApiException $exception ) {
			$this->assertSame( 0, $exception->status_code() );
			$this->assertSame( [ 'not_connected' => true ], $exception->context() );
		}
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

	/**
	 * R3-6c2: 接続済みのトークンに顧客・受注・クーポンのスコープが 1 つでも欠けていれば ColorMe を組み立てない（無料版だけで接続した後に
	 * Pro を有効にした。画面は再接続を促す）。記録の無いトークン（R3-6c2 より前の版が保存した。5 つを持つ）は組み立てる。
	 *
	 * @return array<string,array{0:?array<int,string>,1:bool}>
	 */
	public static function granted_scopes(): array {
		return [
			'products only'           => [ [ 'read_products', 'write_products' ], false ],
			'coupons missing'         => [ [ 'read_products', 'write_products', 'read_sales', 'write_sales' ], false ],
			'write_sales missing'     => [ [ 'read_products', 'write_products', 'read_sales', 'read_shop_coupons' ], false ],
			'nothing (broken record)' => [ [], false ],
			'all five'                => [ [ 'read_products', 'write_products', 'read_sales', 'write_sales', 'read_shop_coupons' ], true ],
			'commerce scopes only'    => [ [ 'read_sales', 'write_sales', 'read_shop_coupons' ], true ],
			'no record (legacy)'      => [ null, true ],
		];
	}

	/**
	 * @dataProvider granted_scopes
	 *
	 * @param ?array<int,string> $scopes トークンに記録したスコープ（null は記録なし）。
	 * @param bool               $built  組み立てるか。
	 */
	public function test_colorme_is_built_only_when_the_token_has_the_commerce_scopes( ?array $scopes, bool $built ): void {
		$payload = [ 'access_token' => 'token' ];

		if ( null !== $scopes ) {
			$payload['scopes'] = $scopes;
		}

		( new TokenStore( ColorMeAdapter::ID ) )->save( $payload );

		$commerce = CommerceAdapters::get( new ColorMeAdapter() );

		if ( $built ) {
			$this->assertInstanceOf( ColorMeCommerceAdapter::class, $commerce );
		} else {
			$this->assertNull( $commerce );
		}
	}

	/**
	 * 未接続（要再接続を含む）は組み立てる: 今までどおり API の呼び出しが「未接続」で失敗し、その案内に任せる。
	 */
	public function test_colorme_is_built_when_not_connected(): void {
		$this->assertNull( ( new ColorMeAdapter() )->granted_scopes() );
		$this->assertInstanceOf( ColorMeCommerceAdapter::class, CommerceAdapters::get( new ColorMeAdapter() ) );

		update_option( 'cbjp_token_' . ColorMeAdapter::ID, 'not-a-valid-ciphertext' );

		$this->assertTrue( ( new TokenStore( ColorMeAdapter::ID ) )->needs_reconnect() );
		$this->assertInstanceOf( ColorMeCommerceAdapter::class, CommerceAdapters::get( new ColorMeAdapter() ) );
	}

	/**
	 * Pro が有効なとき、無料版の認可は顧客・受注・クーポンのスコープも要求する（R3-6c2 より前と同じ 5 つ）。
	 */
	public function test_pro_adds_the_commerce_scopes_to_the_colorme_authorization(): void {
		$this->assertSame( ColorMeOAuth::LEGACY_SCOPES, ColorMeOAuth::scopes() );
	}

	public function test_add_oauth_scopes_adds_only_for_the_bundled_platforms(): void {
		$this->assertSame( [ 'read_products', 'read_sales', 'write_sales', 'read_shop_coupons' ], CommerceAdapters::add_oauth_scopes( [ 'read_products' ], ColorMeAdapter::ID ) );
		$this->assertSame( [ 'read_products' ], CommerceAdapters::add_oauth_scopes( [ 'read_products' ], 'mock' ) );
		$this->assertSame( [ 'read_products' ], CommerceAdapters::add_oauth_scopes( [ 'read_products' ], null ) );
		// 先行するフィルターが壊した値は、同梱の接続先なら自分の分だけにする（無料版が商品のスコープを足し直す。G1-3）。ほかの接続先はそのまま。
		$this->assertSame( ColorMeCommerceAdapter::OAUTH_SCOPES, CommerceAdapters::add_oauth_scopes( 'broken', ColorMeAdapter::ID ) );
		$this->assertSame( 'broken', CommerceAdapters::add_oauth_scopes( 'broken', 'mock' ) );
	}

	/**
	 * G1-3: 後から登録された拡張が値を壊す・置き換えても、認可は Pro の 3 つを要求する（Pro は最後に足す）。要求と Pro の判定がずれると、
	 * 再接続しても顧客・受注・クーポンが隠れたまま案内も出ない。
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function later_scope_filters(): array {
		return [
			'not an array' => [ static fn (): string => 'broken' ],
			'replaces'     => [ static fn (): array => [ 'read_products' ] ],
		];
	}

	/**
	 * @dataProvider later_scope_filters
	 *
	 * @param callable $filter 後から（優先度 20）登録する拡張のコールバック。
	 */
	public function test_pro_scopes_survive_a_later_extension( callable $filter ): void {
		add_filter( ColorMeOAuth::SCOPES_FILTER, $filter, 20 );

		$this->assertSame( ColorMeOAuth::LEGACY_SCOPES, ColorMeOAuth::scopes() );
	}
}
