<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Canonical;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalReview;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Canonical\CanonicalTag;
use WP_UnitTestCase;

/**
 * 8種のCanonicalモデル共通: to_array()/from_array()の往復、checksumのキー順非依存性を検証する。
 */
final class CanonicalModelRoundTripTest extends WP_UnitTestCase {

	/**
	 * @return array<string,array{0:class-string,1:array<string,mixed>}>
	 */
	public static function model_provider(): array {
		return [
			'product'  => [
				CanonicalProduct::class,
				[
					'name'          => 'Sample Product',
					'sku'           => 'SKU-1',
					'price'         => '1000',
					'sale_price'    => null,
					'description'   => 'desc',
					'images'        => [ [ 'url' => 'https://example.test/a.jpg' ] ],
					'variants'      => [],
					'options'       => [],
					'category_refs' => [ 'cat-1' ],
					'stock'         => 10,
					'status'        => 'publish',
					'extras'        => [ 'foo' => 'bar' ],
				],
			],
			'category' => [
				CanonicalCategory::class,
				[
					'id'        => 'cat-1',
					'name'      => 'Category 1',
					'parent_id' => null,
					'slug'      => 'category-1',
				],
			],
			'tag'      => [
				CanonicalTag::class,
				[
					'id'   => 'tag-1',
					'name' => 'Tag 1',
				],
			],
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
			'stock'    => [
				CanonicalStock::class,
				[
					'product_ref' => 'prod-1',
					'variant_ref' => null,
					'sku'         => 'SKU-1',
					'quantity'    => 5,
					'in_stock'    => true,
					'extras'      => [],
				],
			],
			'coupon'   => [
				CanonicalCoupon::class,
				[
					'code'        => 'SAVE10',
					'type'        => 'percent',
					'amount'      => '10',
					'min_amount'  => null,
					'expires_at'  => null,
					'usage_limit' => 100,
					'extras'      => [],
				],
			],
			'review'   => [
				CanonicalReview::class,
				[
					'product_ref' => 'prod-1',
					'author_name' => '鈴木 花子',
					'rating'      => 5,
					'title'       => 'Great',
					'content'     => 'Very good.',
					'created_at'  => '2026-07-01 00:00:00',
					'extras'      => [],
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
}
