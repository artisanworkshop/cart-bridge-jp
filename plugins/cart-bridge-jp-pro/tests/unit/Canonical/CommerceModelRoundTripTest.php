<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Canonical;

use CartBridgeJP\Pro\Canonical\CanonicalCoupon;
use CartBridgeJP\Pro\Canonical\CanonicalCustomer;
use CartBridgeJP\Pro\Canonical\CanonicalOrder;
use WP_UnitTestCase;

/**
 * 顧客・受注・クーポンの Canonical モデル: to_array()/from_array()の往復、checksumのキー順非依存性を検証する（R3-6c1 で
 * 無料版の `CanonicalModelRoundTripTest` から分けた）。
 */
final class CommerceModelRoundTripTest extends WP_UnitTestCase {

	/**
	 * @return array<string,array{0:class-string,1:array<string,mixed>}>
	 */
	public static function model_provider(): array {
		return [
			'customer' => [
				CanonicalCustomer::class,
				[
					'email'          => 'taro@example.com',
					'name'           => '山田 太郎',
					'kana'           => 'ヤマダ タロウ',
					'company'        => null,
					'department'     => null,
					'address'        => [ 'postal_code' => '100-0001' ],
					'phone'          => '090-0000-0001',
					'birthday'       => '1990-01-01',
					'mailmag_opt_in' => true,
					'note'           => null,
					'extras'         => [],
				],
			],
			'order'    => [
				CanonicalOrder::class,
				[
					'number'       => '1000000001',
					'status'       => 'processing',
					'customer_ref' => 'cust-1',
					'line_items'   => [
						[
							'sku'      => 'SKU-1',
							'quantity' => 1,
						],
					],
					'shipping'     => [ 'method' => 'standard' ],
					'payment'      => [ 'method' => 'credit_card' ],
					'totals'       => [ 'total' => '1000' ],
					'placed_at'    => '2026-07-01 00:00:00',
					'note'         => null,
					'extras'       => [],
				],
			],
			'coupon'   => [
				CanonicalCoupon::class,
				[
					'code'                         => 'SAVE10',
					'type'                         => 'percent',
					'amount'                       => '10',
					'min_amount'                   => null,
					'expires_at'                   => null,
					'usage_limit'                  => 100,
					'extras'                       => [],
					'free_shipping'                => false,
					'usage_limit_per_user'         => null,
					'has_unsupported_restrictions' => false,
				],
			],
		];
	}

	/**
	 * @dataProvider model_provider
	 * @param class-string $model_class
	 * @param array<string,mixed> $data
	 */
	public function test_from_array_to_array_round_trips( string $model_class, array $data ): void {
		$model = $model_class::from_array( $data );

		$this->assertSame( $data, $model->to_array() );
	}

	/**
	 * @dataProvider model_provider
	 * @param class-string $model_class
	 * @param array<string,mixed> $data
	 */
	public function test_checksum_is_stable_regardless_of_source_key_order( string $model_class, array $data ): void {
		$reversed = array_reverse( $data, true );

		$checksum_a = $model_class::from_array( $data )->checksum();
		$checksum_b = $model_class::from_array( $reversed )->checksum();

		$this->assertSame( $checksum_a, $checksum_b );
	}

	/**
	 * @dataProvider model_provider
	 * @param class-string $model_class
	 * @param array<string,mixed> $data
	 */
	public function test_checksum_changes_when_a_value_changes( string $model_class, array $data ): void {
		$model_a = $model_class::from_array( $data );

		$mutated               = $data;
		$first_key             = array_key_first( $mutated );
		$mutated[ $first_key ] = is_string( $mutated[ $first_key ] ) ? $mutated[ $first_key ] . '-changed' : $mutated[ $first_key ];
		$model_b               = $model_class::from_array( $mutated );

		if ( $model_a->to_array() === $model_b->to_array() ) {
			$this->markTestSkipped( 'Mutation was a no-op for this field type.' );
		}

		$this->assertNotSame( $model_a->checksum(), $model_b->checksum() );
	}

	/**
	 * @dataProvider coupon_restriction_flag_provider
	 * @param array<string,mixed> $source
	 */
	public function test_coupon_restriction_flag_survives_the_round_trip( array $source, ?bool $expected ): void {
		// 復元経路だけが「キーが無い＝制限なし」へ倒れると、`Woo\Writer\CouponWriter`の
		// フェイルクローズ（未宣言は保存しない）をシリアライズ往復で回避できてしまう（issue #15）。
		// `from_array()`は`isset()`判定のため明示的な`null`とキー欠損は同じ`null`に落ちる。
		$coupon = CanonicalCoupon::from_array(
			array_merge(
				[
					'code'   => 'SAVE10',
					'type'   => 'percent',
					'amount' => '10',
				],
				$source
			)
		);

		$this->assertSame( $expected, $coupon->has_unsupported_restrictions );
		$this->assertSame( $expected, $coupon->to_array()['has_unsupported_restrictions'] );
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:?bool}>
	 */
	public static function coupon_restriction_flag_provider(): array {
		return [
			'key omitted'    => [ [], null ],
			'explicit null'  => [ [ 'has_unsupported_restrictions' => null ], null ],
			'declared false' => [ [ 'has_unsupported_restrictions' => false ], false ],
			'declared true'  => [ [ 'has_unsupported_restrictions' => true ], true ],
			// 非boolは「不明」へ倒す。`(bool)`キャストだと`'0'`が`false`（＝保存してよい）に
			// 化けて`CouponWriter`のフェイルクローズを迂回できてしまう（Copilot指摘 G1-2）。
			'string zero'    => [ [ 'has_unsupported_restrictions' => '0' ], null ],
			'string false'   => [ [ 'has_unsupported_restrictions' => 'false' ], null ],
			'int one'        => [ [ 'has_unsupported_restrictions' => 1 ], null ],
			'array'          => [ [ 'has_unsupported_restrictions' => [] ], null ],
		];
	}
}
