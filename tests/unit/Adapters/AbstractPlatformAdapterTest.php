<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters;

use CartBridgeJP\Adapters\AbstractPlatformAdapter;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * `PlatformAdapter` の外部互換ポリシー（D20。`docs/03-design-decisions.md` §2）を強制する契約テスト。
 *
 * v1.0.0 公開後にここが失敗する場合は次のいずれかを意味する:
 * - BASELINEに記録済みのメソッドのシグネチャが変わった（`test_interface_signatures_match_v1_baseline`）。
 *   `AbstractPlatformAdapter`側で既定実装を持つようになった後でも、インターフェース側の宣言を
 *   直接変更すれば検出する（既定実装の有無に関わらず、シグネチャ自体はBASELINE記録時点で凍結）
 * - v1.0時点に存在しなかった（＝`V1_METHOD_NAMES`に無い）新しいメソッドが `AbstractPlatformAdapter`
 *   で既定実装を持たないまま追加された（`test_new_methods_have_default_implementations`。BASELINEへ
 *   追記済みかどうかに関わらず、`V1_METHOD_NAMES`に無い限り恒久的にチェックし続ける）
 * いずれも `AbstractPlatformAdapter` を継承した外部実装を fatal にしうる変更である。
 * 公開後はBASELINEの既存エントリを書き換えず、新メソッドは`AbstractPlatformAdapter`に既定実装を
 * 添えて追加すること（BASELINEへの追記は任意。追記すればそのメソッドのシグネチャも以降凍結される）。
 *
 * v1.0.0 公開前はBASELINEを自由に更新してよい（D19/D20が許容する期間）。
 */
final class AbstractPlatformAdapterTest extends WP_UnitTestCase {

	/**
	 * v1.0 時点の `PlatformAdapter` 全メソッドのシグネチャ一覧（凍結対象）。
	 * `describe_signature()` と同じ形式（メソッド名 => "(引数...): 戻り値型"。static修飾子・
	 * 参照渡し戻り値がある場合は先頭に付与）。
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
		'push_coupon'                 => '(CartBridgeJP\Canonical\CanonicalCoupon $coupon, ?string $remote_id): CartBridgeJP\Adapters\PushResult',
		'push_customer'               => '(CartBridgeJP\Canonical\CanonicalCustomer $customer, ?string $remote_id): CartBridgeJP\Adapters\PushResult',
		'push_order'                  => '(CartBridgeJP\Canonical\CanonicalOrder $order, ?string $remote_id): CartBridgeJP\Adapters\PushResult',
		'push_product'                => '(CartBridgeJP\Canonical\CanonicalProduct $product, ?string $remote_id): CartBridgeJP\Adapters\PushResult',
		'push_stock'                  => '(CartBridgeJP\Canonical\CanonicalStock $stock): CartBridgeJP\Adapters\PushResult',
		'test_connection'             => '(): CartBridgeJP\Adapters\ConnectionResult',
	];

	/**
	 * v1.0 時点で `PlatformAdapter` に存在した全メソッド名。`BASELINE`のキーと**意図的に別の定数**にしている。
	 * `BASELINE`は将来（v1.0以降に追加されたメソッドの）シグネチャ凍結のために任意で追記されうるが、
	 * それを「既定実装が要らない側」の判定にも流用すると、一度`AbstractPlatformAdapter`が既定実装を
	 * 持ち`BASELINE`にも追記された後で、その既定実装だけが（シグネチャは変えずに）削除される変更を
	 * 検出できなくなる（PR #58 G1-2 Codex指摘）。このリストは`BASELINE`の増減と関係なく固定し、
	 * 「v1.0時点で存在しなかったメソッドは常に既定実装を持つこと」を恒久的にチェックする土台にする。
	 *
	 * @var array<int,string>
	 */
	private const V1_METHOD_NAMES = [
		'capabilities',
		'connection_fields',
		'fetch_categories',
		'fetch_coupons',
		'fetch_customer_by_remote_id',
		'fetch_customers',
		'fetch_latest_orders',
		'fetch_order_by_remote_id',
		'fetch_orders',
		'fetch_product_by_remote_id',
		'fetch_products',
		'fetch_reviews',
		'fetch_stocks',
		'fetch_tags',
		'id',
		'label',
		'mapping_candidates',
		'push_category',
		'push_coupon',
		'push_customer',
		'push_order',
		'push_product',
		'push_stock',
		'test_connection',
	];

	/**
	 * BASELINEに記録済みのメソッドは、`AbstractPlatformAdapter`側で既定実装を持つようになった後でも
	 * シグネチャが変わっていないこと（インターフェース自体を反射して確認する。既定実装の有無では
	 * 判定しない）。BASELINEに無い新しいメソッドが増えるのは許容する（`test_new_methods_have_default_implementations`
	 * が別途チェックする）。
	 */
	public function test_interface_signatures_match_v1_baseline(): void {
		$reflection = new ReflectionClass( PlatformAdapter::class );
		$actual     = self::describe_methods( $reflection );

		foreach ( self::BASELINE as $name => $expected_signature ) {
			$this->assertArrayHasKey(
				$name,
				$actual,
				"PlatformAdapter::{$name}() がインターフェースから削除されています。BASELINEに記録済みのメソッドは削除できません（D20）。"
			);
			$this->assertSame(
				$expected_signature,
				$actual[ $name ],
				"PlatformAdapter::{$name}() のシグネチャが変わっています。v1.0.0 公開後はBASELINEに記録済みの" .
				'シグネチャを変更できません。変更が必要なら新しいメソッドとして追加してください（D20、docs/03-design-decisions.md §2）。' .
				'v1.0.0 公開前の意図した変更であれば、このテストの BASELINE 定数を更新してください。'
			);
		}
	}

	/**
	 * `V1_METHOD_NAMES`に無い（＝v1.0以降に追加された）メソッドは、`AbstractPlatformAdapter`で既定実装を
	 * 持っている（＝抽象のままではない）こと。BASELINEに追記済みかどうかは判定に使わない（そのメソッドが
	 * 既定実装ごと`AbstractPlatformAdapter`から削除される変更も、シグネチャ自体は変わらないため
	 * `test_interface_signatures_match_v1_baseline`では検出できず、このテストで恒久的に検出し続ける必要がある）。
	 */
	public function test_new_methods_have_default_implementations(): void {
		$interface_method_names = array_keys( self::describe_methods( new ReflectionClass( PlatformAdapter::class ) ) );
		$new_method_names       = array_diff( $interface_method_names, self::V1_METHOD_NAMES );

		$abstract_method_names       = array_keys( self::describe_methods( new ReflectionClass( AbstractPlatformAdapter::class ) ) );
		$new_methods_without_default = array_intersect( $new_method_names, $abstract_method_names );

		$this->assertSame(
			[],
			array_values( $new_methods_without_default ),
			'v1.0時点に存在しなかった PlatformAdapter メソッドが、AbstractPlatformAdapterで既定実装を持たないまま' .
			'（または既定実装が削除されて）います。外部実装を継承エラーにしないため、AbstractPlatformAdapterに' .
			'既定実装（原則UnsupportedOperationException）を追加してください（D20、docs/03-design-decisions.md §2）。'
		);
	}

	public function test_bundled_adapters_extend_base(): void {
		$this->assertInstanceOf( AbstractPlatformAdapter::class, new ColorMeAdapter() );
		$this->assertInstanceOf( AbstractPlatformAdapter::class, new MockPlatformAdapter() );
	}

	/**
	 * `$reflection` が実装しない（抽象のままの）インターフェースメソッドを
	 * `メソッド名 => "(引数...): 戻り値型"` の形にして返す。インターフェースを反射した場合、
	 * 全メソッドが常に抽象として返る（PHPの仕様）ため実質すべてのメソッドが対象になる。
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
			$piece = ( null !== $type ? $type . ' ' : '' )
				. ( $param->isPassedByReference() ? '&' : '' )
				. ( $param->isVariadic() ? '...' : '' )
				. '$' . $param->getName();

			if ( $param->isDefaultValueAvailable() ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- シグネチャ文字列化用。デバッグ出力ではない。
				$piece .= ' = ' . var_export( $param->getDefaultValue(), true );
			}

			$params[] = $piece;
		}

		$return_type = $method->getReturnType();
		$modifiers   = ( $method->isStatic() ? 'static ' : '' ) . ( $method->returnsReference() ? '&' : '' );

		return $modifiers . '(' . implode( ', ', $params ) . '): ' . ( null !== $return_type ? (string) $return_type : 'void' );
	}
}
