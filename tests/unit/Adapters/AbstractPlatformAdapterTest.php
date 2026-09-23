<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters;

use CartBridgeJP\Adapters\AbstractPlatformAdapter;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * `PlatformAdapter` の外部互換ポリシー（D20。`docs/03-design-decisions.md` §2）を強制する契約テスト。
 *
 * v1.0.0 公開後、`AbstractPlatformAdapter` の未実装（抽象）メソッド一覧・シグネチャが変わると
 * このテストが失敗する。それは「既定実装の無いメソッド追加」「既存シグネチャの変更」を意味し、
 * `AbstractPlatformAdapter` を継承した外部実装を fatal にしうる変更である。公開後は BASELINE を
 * 更新せず、`AbstractPlatformAdapter` に既定実装を置くか、新メソッドとして追加すること。
 *
 * v1.0.0 公開前はこのテストごとBASELINEを更新してよい（D19/D20が許容する期間）。
 */
final class AbstractPlatformAdapterTest extends WP_UnitTestCase {

	/**
	 * v1.0 時点で `AbstractPlatformAdapter` が既定実装を持たない（＝抽象のままの）メソッドの
	 * シグネチャ一覧。`describe_methods()` と同じ形式（メソッド名 => "(引数...): 戻り値型"）。
	 *
	 * @var array<string,string>
	 */
	private const BASELINE = [
		'capabilities'                => '(): CartBridgeJP\Adapters\Capabilities',
		'connection_fields'           => '(): array',
		'fetch_categories'            => '(): array',
		'fetch_coupons'               => '(CartBridgeJP\Adapters\Cursor $cursor): CartBridgeJP\Adapters\Page',
		'fetch_customer_by_remote_id' => '(string $remote_id): ?CartBridgeJP\Canonical\CanonicalCustomer',
		'fetch_customers'             => '(CartBridgeJP\Adapters\Cursor $cursor): CartBridgeJP\Adapters\Page',
		'fetch_latest_orders'         => '(int $limit): array',
		'fetch_order_by_remote_id'    => '(string $remote_id): ?CartBridgeJP\Canonical\CanonicalOrder',
		'fetch_orders'                => '(CartBridgeJP\Adapters\Cursor $cursor): CartBridgeJP\Adapters\Page',
		'fetch_product_by_remote_id'  => '(string $remote_id): ?CartBridgeJP\Canonical\CanonicalProduct',
		'fetch_products'              => '(CartBridgeJP\Adapters\Cursor $cursor): CartBridgeJP\Adapters\Page',
		'fetch_reviews'               => '(CartBridgeJP\Adapters\Cursor $cursor): CartBridgeJP\Adapters\Page',
		'fetch_stocks'                => '(CartBridgeJP\Adapters\Cursor $cursor): CartBridgeJP\Adapters\Page',
		'fetch_tags'                  => '(): array',
		'id'                          => '(): string',
		'label'                       => '(): string',
		'mapping_candidates'          => '(): array',
		'push_category'               => '(CartBridgeJP\Canonical\CanonicalCategory $category): CartBridgeJP\Adapters\PushResult',
		'push_coupon'                 => '(CartBridgeJP\\Canonical\\CanonicalCoupon $coupon, ?string $remote_id): CartBridgeJP\\Adapters\\PushResult',
		'push_customer'               => '(CartBridgeJP\\Canonical\\CanonicalCustomer $customer, ?string $remote_id): CartBridgeJP\\Adapters\\PushResult',
		'push_order'                  => '(CartBridgeJP\\Canonical\\CanonicalOrder $order, ?string $remote_id): CartBridgeJP\\Adapters\\PushResult',
		'push_product'                => '(CartBridgeJP\\Canonical\\CanonicalProduct $product, ?string $remote_id): CartBridgeJP\\Adapters\\PushResult',
		'push_stock'                  => '(CartBridgeJP\Canonical\CanonicalStock $stock): CartBridgeJP\Adapters\PushResult',
		'test_connection'             => '(): CartBridgeJP\Adapters\ConnectionResult',
	];

	public function test_abstract_methods_match_v1_baseline(): void {
		$reflection = new ReflectionClass( AbstractPlatformAdapter::class );
		$actual     = self::describe_methods( $reflection );

		ksort( $actual );
		$expected = self::BASELINE;
		ksort( $expected );

		$this->assertSame(
			$expected,
			$actual,
			'AbstractPlatformAdapter の未実装メソッド一覧・シグネチャが変わっています。v1.0.0 公開後はBASELINEを更新せず、' .
			'AbstractPlatformAdapter に既定実装を置くか新メソッドとして追加してください（D20、docs/03-design-decisions.md §2）。' .
			'v1.0.0 公開前の意図した変更であれば、このテストの BASELINE 定数を更新してください。'
		);
	}

	public function test_bundled_adapters_extend_base(): void {
		$this->assertInstanceOf( AbstractPlatformAdapter::class, new ColorMeAdapter() );
		$this->assertInstanceOf( AbstractPlatformAdapter::class, new MockPlatformAdapter() );
	}

	/**
	 * `$reflection` が実装しない（抽象のままの）インターフェースメソッドを
	 * `メソッド名 => "(引数...): 戻り値型"` の形にして返す。
	 *
	 * @return array<string,string>
	 */
	private static function describe_methods( ReflectionClass $reflection ): array {
		$result = [];

		foreach ( $reflection->getMethods() as $method ) {
			if ( ! $method->isAbstract() ) {
				continue;
			}

			$result[ $method->getName() ] = self::describe_signature( $method );
		}

		return $result;
	}

	private static function describe_signature( ReflectionMethod $method ): string {
		$params = [];

		foreach ( $method->getParameters() as $param ) {
			$type  = $param->getType();
			$piece = ( null !== $type ? $type . ' ' : '' ) . '$' . $param->getName();

			if ( $param->isDefaultValueAvailable() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- シグネチャ文字列化用。デバッグ出力ではない。
				$piece .= ' = ' . var_export( $param->getDefaultValue(), true );
			}

			$params[] = $piece;
		}

		$return_type = $method->getReturnType();

		return '(' . implode( ', ', $params ) . '): ' . ( null !== $return_type ? (string) $return_type : 'void' );
	}
}
