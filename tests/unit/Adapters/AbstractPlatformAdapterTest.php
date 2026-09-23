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
 * - `PlatformAdapter`インターフェースの現在のメソッド一覧・シグネチャが`BASELINE`と一致しなくなった
 *   （`test_interface_signatures_match_v1_baseline`。**`BASELINE`は常にインターフェースの全メソッドを
 *   1対1で記録する**。新しいメソッドを追加したらそのメソッドも同時に`BASELINE`へ追加しないとこのテストが
 *   失敗する — 追加を「任意」にすると、そのメソッドのシグネチャが以降まったく凍結されないまま
 *   `AbstractPlatformAdapter`の既定実装ごと変更できてしまう。PR #58 G2 Codex指摘）
 * - v1.0.0公開後に追加された（＝`v1_method_names()`に無い）メソッドが `AbstractPlatformAdapter`
 *   で既定実装を持たないまま追加された（`test_new_methods_have_default_implementations`。
 *   `v1_method_names()`は公開時に固定するまでは`BASELINE`と連動して動く。PR #58 G1-2/G3指摘）
 * いずれも `AbstractPlatformAdapter` を継承した外部実装を fatal にしうる変更である。
 * 公開後はBASELINEの既存エントリを書き換えず、新しいメソッドを`PlatformAdapter`へ追加するときは
 * `AbstractPlatformAdapter`への既定実装の追加と`BASELINE`への追記を**同時に**行うこと。
 *
 * v1.0.0 公開前はBASELINEを自由に更新してよい（D19/D20が許容する期間）。
 */
final class AbstractPlatformAdapterTest extends WP_UnitTestCase {

	/**
	 * `PlatformAdapter` の現在の全メソッドのシグネチャ一覧（凍結対象。1対1で完全一致する必要がある）。
	 * `describe_signature()` と同じ形式（メソッド名 => "(引数...): 戻り値型"。static修飾子・
	 * 参照渡し戻り値がある場合は先頭に付与）。新しいメソッドを追加したら必ずここにも追記すること。
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
	 * 「既定実装が無くても許容される（＝抽象のままでよい）」メソッド名の一覧。
	 *
	 * v1.0.0 公開前の**今は**`BASELINE`のキーをそのまま返す（動的）。D20は公開前のメソッド追加に
	 * 既定実装を要求しないため、`BASELINE`に追記されたメソッドは（`test_interface_signatures_match_v1_baseline`
	 * によってシグネチャ凍結の対象にはなるが）自動的にこちらの「既定実装不要」側にも含まれる
	 * （PR #58 G3 Copilot指摘。固定リストにすると公開前の正当な追加まで失敗していた）。
	 *
	 * **v1.0.0 公開時（`docs/10-tasks.md` R3-4）に必ずこの実装を書き換え、その時点の
	 * `array_keys( self::BASELINE )` をコピーしたリテラル配列に置き換えること。** 書き換えないと、
	 * 公開後に追加されたメソッドの既定実装が（シグネチャを変えずに）削除される変更を検出できなくなる
	 * （PR #58 G1-2 Codex指摘のケースが再発する）。書き換え後は本メソッドの内容が固定され、以降
	 * `BASELINE`が成長してもこちらは変わらなくなる。
	 *
	 * @return array<int,string>
	 */
	private static function v1_method_names(): array {
		return array_keys( self::BASELINE );
	}

	/**
	 * `PlatformAdapter`の現在の全メソッド・シグネチャがBASELINEと完全一致すること
	 * （既定実装の有無に関わらず、インターフェース自体を反射して確認する）。BASELINEは常に
	 * インターフェースの全メソッドを1対1で記録するため、新しいメソッドの追加も検出する
	 * （BASELINEへの追記漏れとして失敗する。`test_new_methods_have_default_implementations`と役割が
	 * 分かれており、こちらはシグネチャ凍結、あちらは既定実装の有無を担当する）。
	 */
	public function test_interface_signatures_match_v1_baseline(): void {
		$reflection = new ReflectionClass( PlatformAdapter::class );
		$actual     = self::describe_methods( $reflection );

		ksort( $actual );
		$expected = self::BASELINE;
		ksort( $expected );

		$this->assertSame(
			$expected,
			$actual,
			'PlatformAdapterの現在のメソッド一覧・シグネチャがBASELINEと一致しません。新しいメソッドを追加した' .
			'場合はBASELINEにも同時に追記してください（追記を怠ると、そのメソッドのシグネチャが凍結されないまま' .
			'変更できてしまいます）。既存メソッドのシグネチャ変更であれば、v1.0.0 公開後は許可されません' .
			'（D20、docs/03-design-decisions.md §2）。公開前の意図した変更であれば、このテストの BASELINE ' .
			'定数を更新してください。'
		);
	}

	/**
	 * `v1_method_names()`に無い（＝v1.0.0公開後に追加された）メソッドは、`AbstractPlatformAdapter`で
	 * 既定実装を持っている（＝抽象のままではない）こと。v1.0.0公開前の今は`v1_method_names()`が
	 * `BASELINE`と連動するため実質的に何もチェックしない（D20が公開前の既定実装なし追加を許容するため）。
	 * `v1_method_names()`が公開時に固定される理由は同メソッドのdocblock参照。
	 */
	public function test_new_methods_have_default_implementations(): void {
		$interface_method_names = array_keys( self::describe_methods( new ReflectionClass( PlatformAdapter::class ) ) );
		$new_method_names       = array_diff( $interface_method_names, self::v1_method_names() );

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
