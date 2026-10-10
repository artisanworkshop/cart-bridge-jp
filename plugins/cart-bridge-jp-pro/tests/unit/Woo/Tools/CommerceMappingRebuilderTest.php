<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Woo\Tools;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalTag;
use CartBridgeJP\Pro\Canonical\CanonicalCoupon;
use CartBridgeJP\Pro\Tests\Fixtures\CommerceFactory;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Tools\MappingRebuilder;
use CartBridgeJP\Woo\WooRepositoryFactory;
use InvalidArgumentException;
use WC_Product_Simple;

/**
 * リンク再構築で顧客・受注・クーポンの印も戻る（R3-6c1 で無料版の `MappingRebuilderTest` から分けた。走査の順は種類の LinkSource の位置）。
 */
final class CommerceMappingRebuilderTest extends WooTestCase {

	/**
	 * @var array<string,WooWriter>
	 */
	private array $writers = [];

	private function writer( string $platform ): WooWriter {
		return $this->writers[ $platform ] ??= ( new WooRepositoryFactory() )->for_platform( $platform );
	}

	private function import( string $platform, string $entity, CanonicalModel $item ): int {
		$result = $this->writer( $platform )->write( $entity, $item, null );
		$this->assertGreaterThan( 0, $result->local_id, "{$entity} の書込に失敗: " . implode( ',', $result->warnings ) );
		$this->mappings->upsert( $platform, $entity, (string) $item->remote_id(), $result->local_id, 'checksum-before' );

		return $result->local_id;
	}

	/**
	 * @return array<int,array{0:string,1:string,2:int}> [entity, remote_id, local_id]
	 */
	private function seed_mock_platform(): array {
		$category_id  = $this->import( 'mock', 'category', CanonicalFactory::category( 'c1', 'Category 1' ) );
		$tag_id       = $this->import( 'mock', 'tag', new CanonicalTag( 't1', 'Tag 1' ) );
		$product_id   = $this->import(
			'mock',
			'product',
			new CanonicalProduct(
				'Product p1',
				'SKU-REBUILD',
				'1000',
				null,
				null,
				[],
				[
					[
						'remote_id'     => 'v1',
						'sku'           => 'SKU-REBUILD-S',
						'option1_name'  => 'Size',
						'option1_value' => 'S',
						'price'         => '1000',
						'stock'         => null,
					],
				],
				[],
				[],
				null,
				'publish',
				[ 'remote_id' => 'p1' ]
			)
		);
		$variation_id = $this->mappings->find_local_id( 'mock', 'variant', 'v1' );
		$this->assertNotNull( $variation_id );
		$coupon_id = $this->import( 'mock', 'coupon', new CanonicalCoupon( 'REBUILD10', 'percent', '10', null, null, null, [ 'remote_id' => 'cp1' ], has_unsupported_restrictions: false ) );
		$user_id   = $this->import( 'mock', 'customer', CommerceFactory::customer( 'cu1', 'cu1@example.com' ) );
		$order_id  = $this->import( 'mock', 'order', CommerceFactory::order( '1001', 'cu1', [ 'p1' ] ) );

		return [
			[ 'category', 'c1', $category_id ],
			[ 'tag', 't1', $tag_id ],
			[ 'product', 'p1', $product_id ],
			[ 'variant', 'v1', $variation_id ],
			[ 'coupon', 'cp1', $coupon_id ],
			[ 'customer', 'cu1', $user_id ],
			[ 'order', '1001', $order_id ],
		];
	}

	public function test_rebuilds_mappings_from_ownership_meta(): void {
		$expected = $this->seed_mock_platform();

		// 別プラットフォーム所有の商品と、本プラグイン外で作られた商品は対象外。
		$other_product_id = $this->import( 'other', 'product', CanonicalFactory::product( 'op1', 'SKU-OTHER' ) );
		$plain            = new WC_Product_Simple();
		$plain->set_name( 'Plain' );
		$plain->set_sku( 'SKU-PLAIN' );
		$plain->save();

		$this->mappings->delete_for_platform( 'mock' );

		foreach ( $expected as [ $entity ] ) {
			$this->assertSame( 0, $this->mappings->count( 'mock', $entity ) );
		}

		$result = ( new MappingRebuilder( $this->mappings ) )->run( 'mock' );

		$this->assertNull( $result['cursor'] );
		$this->assertSame(
			[
				'category' => 1,
				'tag'      => 1,
				'product'  => 1,
				'variant'  => 1,
				'coupon'   => 1,
				'customer' => 1,
				'order'    => 1,
			],
			$result['counts']
		);

		foreach ( $expected as [ $entity, $remote_id, $local_id ] ) {
			$this->assertSame( $local_id, $this->mappings->find_local_id( 'mock', $entity, $remote_id ), "{$entity}/{$remote_id}" );
			$this->assertNull( $this->mappings->find_checksum( 'mock', $entity, $remote_id ), '再構築後は次回 import で必ず再検証させる' );
		}

		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ), '他プラットフォーム・プラグイン外の商品は拾わない' );
		$this->assertSame( $other_product_id, $this->mappings->find_local_id( 'other', 'product', 'op1' ), '他プラットフォームの mapping には触らない' );
	}

	public function test_is_idempotent(): void {
		$this->seed_mock_platform();
		$this->mappings->delete_for_platform( 'mock' );

		$rebuilder = new MappingRebuilder( $this->mappings );
		$first     = $rebuilder->run( 'mock' );
		$second    = $rebuilder->run( 'mock' );

		$this->assertSame( $first['counts'], $second['counts'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'order' ) );
	}
}
