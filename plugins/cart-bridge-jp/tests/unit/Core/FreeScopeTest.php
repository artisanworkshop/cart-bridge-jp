<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Woo\Support\EntityOrigin;
use CartBridgeJP\Woo\Support\MappingCandidates;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\WarningCode;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use WP_UnitTestCase;

/**
 * 無料版は顧客・受注・クーポンのコードを同梱しない（D27。R3-6c1 で Pro アドオンへ移した）。移したものが無料版に戻る退行を止める。
 * 公開後に無料版の機能を Pro へ移すことはしない（docs/03 §10.0「無料版で守ること」4）ので、境目はここで固定する。
 */
final class FreeScopeTest extends WP_UnitTestCase {

	private const COMMERCE = '/customer|order|coupon/i';

	public function test_the_platform_adapter_has_no_customer_order_or_coupon_methods(): void {
		$methods = array_map( static fn ( \ReflectionMethod $method ): string => $method->getName(), ( new ReflectionClass( PlatformAdapter::class ) )->getMethods() );

		$this->assertSame( [], array_values( preg_grep( self::COMMERCE, $methods ) ) );
	}

	/**
	 * 顧客・受注のコードを分けて Pro へ移した補助クラス・アダプタ・能力に、受注などの公開メソッド・能力（`Capabilities` のプロパティ）・定数が戻らない
	 * （R3-6c1 review-loop R1）。決済・配送は受注のマッピングなので一緒に見る。部分一致なので、商品側の正当な名前（並べ替えの `menu_order`・`orderby`、
	 * 商品の `shipping_class` など）でも落ちる。そのときは名前を変えるか、理由を書いて除く一覧をここに足す（黙って通すより、見て判断する）。
	 */
	public function test_the_shared_helpers_have_no_customer_order_or_coupon_members(): void {
		$members = [];

		foreach ( [ PlatformAdapter::class, ColorMeAdapter::class, Capabilities::class, MethodMap::class, MappingCandidates::class, LocalEntityLookup::class, EntityOrigin::class ] as $class ) {
			$reflection = new ReflectionClass( $class );
			$names      = array_merge(
				array_map( static fn ( \ReflectionMethod $method ): string => $method->getName(), $reflection->getMethods( \ReflectionMethod::IS_PUBLIC ) ),
				array_map( static fn ( \ReflectionProperty $property ): string => '$' . $property->getName(), $reflection->getProperties( \ReflectionProperty::IS_PUBLIC ) ),
				array_keys( $reflection->getConstants() )
			);

			foreach ( preg_grep( '/customer|order|coupon|payment|shipping/i', $names ) as $name ) {
				$members[] = $reflection->getShortName() . '::' . $name;
			}
		}

		$this->assertSame( [], $members );
	}

	public function test_no_class_file_is_named_after_customers_orders_or_coupons(): void {
		$files = [];

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( CBJP_PATH . 'includes', RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( str_ends_with( (string) $file, '.php' ) && preg_match( self::COMMERCE, basename( (string) $file ) ) ) {
				$files[] = substr( (string) $file, strlen( CBJP_PATH ) );
			}
		}

		$this->assertSame( [], $files );
	}

	public function test_the_warning_codes_have_no_customer_order_or_coupon_codes(): void {
		$codes = array_keys( ( new ReflectionClass( WarningCode::class ) )->getConstants() );

		$this->assertSame( [], array_values( preg_grep( '/^(ORDER|CUSTOMER|COUPON|PAYMENT_METHOD|SHIPPING_METHOD)_|^(CURRENCY_MISMATCH|ADDRESS_OVERSEAS)$/', $codes ) ) );
	}

	/**
	 * 無料版だけ（Pro アドオンが無い）では顧客・受注・クーポンの種類は登録されない（無料版のテストは Pro を読み込まない）。
	 */
	public function test_no_customer_order_or_coupon_type_is_registered_without_the_add_on(): void {
		$this->assertSame( [], array_values( preg_grep( self::COMMERCE, \CartBridgeJP\Entities\EntityTypeRegistry::keys() ) ) );
	}
}
