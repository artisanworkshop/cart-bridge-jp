<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Tools;

use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\SampleSelector;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Tools\SampleCleanup;
use CartBridgeJP\Woo\WooRepositoryFactory;
use CartBridgeJP\Woo\Writer\CustomerWriter;
use WC_Order;
use WC_Product;
use WP_User;

final class SampleCleanupTest extends WooTestCase {

	/**
	 * @var array<string,WooWriter>
	 */
	private array $writers = [];

	public function tear_down(): void {
		wp_set_current_user( 0 );
		delete_option( SampleSelector::option_name_for( 'mock' ) );
		parent::tear_down();
	}

	private function writer( string $platform ): WooWriter {
		return $this->writers[ $platform ] ??= ( new WooRepositoryFactory() )->for_platform( $platform );
	}

	/**
	 * 実 writer で書き込み、`Sync\Importer` と同じ契約で mapping を記録する。
	 */
	private function import( string $platform, string $entity, CanonicalModel $item ): int {
		$result = $this->writer( $platform )->write( $entity, $item, null );
		$this->assertGreaterThan( 0, $result->local_id, "{$entity} の書込に失敗: " . implode( ',', $result->warnings ) );
		$this->mappings->upsert( $platform, $entity, (string) $item->remote_id(), $result->local_id, null );

		return $result->local_id;
	}

	private function variable_product_with_image( string $remote_id, string $sku ): CanonicalProduct {
		return new CanonicalProduct(
			"Product {$remote_id}",
			$sku,
			'1000',
			null,
			null,
			[
				[
					'src'      => "https://example.test/{$remote_id}.png",
					'position' => 0,
				],
			],
			[
				[
					'remote_id'     => "{$remote_id}-v1",
					'sku'           => "{$sku}-S",
					'option1_name'  => 'Size',
					'option1_value' => 'S',
					'price'         => '1000',
					'stock'         => null,
				],
			],
			[],
			[ 'c1' ],
			null,
			'publish',
			[ 'remote_id' => $remote_id ]
		);
	}

	public function test_deletes_owned_data_unlinks_adopted_customers_and_clears_the_sample(): void {
		$this->stub_image_http();

		$category_id  = $this->import( 'mock', 'category', CanonicalFactory::category( 'c1', 'Category 1' ) );
		$product_id   = $this->import( 'mock', 'product', $this->variable_product_with_image( 'p1', 'SKU-CLEAN' ) );
		$variation_id = $this->mappings->find_local_id( 'mock', 'variant', 'p1-v1' );
		$this->assertNotNull( $variation_id );
		$image_id = (int) wc_get_product( $product_id )->get_image_id();
		$this->assertGreaterThan( 0, $image_id );
		$this->assertSame( 'mock', get_post_meta( $image_id, '_cbjp_platform', true ) );

		$coupon_id   = $this->import( 'mock', 'coupon', new CanonicalCoupon( 'CLEAN10', 'percent', '10', null, null, null, [ 'remote_id' => 'cp1' ] ) );
		$new_user_id = $this->import( 'mock', 'customer', CanonicalFactory::customer( 'cu1', 'cu1@example.com' ) );

		// email突合で採用される既存アカウント（本プラグインが作成していない）。
		$existing_user_id = wp_insert_user(
			[
				'user_login' => 'existing',
				'user_email' => 'existing@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);
		$adopted_user_id  = $this->import( 'mock', 'customer', CanonicalFactory::customer( 'cu2', 'existing@example.com' ) );
		$this->assertSame( $existing_user_id, $adopted_user_id );

		$order_id = $this->import( 'mock', 'order', CanonicalFactory::order( '1001', 'cu1', [ 'p1' ] ) );
		$this->mappings->upsert( 'mock', 'stock', 'p1', $product_id, null );

		// 別プラットフォームのデータと mappings には触らない。
		$other_product_id = $this->import( 'other', 'product', CanonicalFactory::product( 'op1', 'SKU-OTHER' ) );
		// mapping はあるが実体の所有者が別プラットフォーム → mapping 行だけ外す。
		$this->mappings->upsert( 'mock', 'product', 'p-foreign', $other_product_id, null );

		update_option( SampleSelector::option_name_for( 'mock' ), [ 'order_remote_ids' => [ '1001' ] ], false );

		$cleanup = new SampleCleanup( $this->mappings );
		$preview = $cleanup->preview( 'mock' );

		$this->assertSame( 1, $preview['counts']['category'] );
		$this->assertSame( 2, $preview['counts']['product'] );
		$this->assertSame( 1, $preview['counts']['variant'] );
		$this->assertSame( 2, $preview['counts']['customer'] );
		$this->assertSame( 1, $preview['counts']['order'] );
		$this->assertSame( 1, $preview['counts']['stock'] );
		$this->assertSame( 1, $preview['counts']['coupon'] );
		$this->assertSame( 1, $preview['attachments'] );
		$this->assertSame(
			[
				'delete' => 1,
				'unlink' => 1,
			],
			$preview['customers']
		);

		$result = $cleanup->run( 'mock' );

		$this->assertFalse( $result['has_more'] );
		$this->assertSame( 1, $result['deleted']['order'] );
		$this->assertSame( 1, $result['deleted']['product'] );
		$this->assertSame( 1, $result['deleted']['variant'] );
		$this->assertSame( 1, $result['deleted']['coupon'] );
		$this->assertSame( 1, $result['deleted']['customer'] );
		$this->assertSame( 1, $result['deleted']['category'] );
		$this->assertSame( 1, $result['deleted']['attachment'] );
		$this->assertSame( 1, $result['unlinked']['product'], '別プラットフォーム所有の実体は unlink のみ' );
		$this->assertSame( 1, $result['unlinked']['customer'], 'email突合で採用した既存アカウントは unlink のみ' );
		$this->assertSame( 1, $result['unlinked']['stock'] );

		$this->assertNotInstanceOf( WC_Product::class, wc_get_product( $product_id ) );
		// 削除済み variation は `wc_get_product()` が（投稿が無くても）空の `WC_Product_Variation` を
		// 返しうるため（`WC_Product_Variation_Data_Store_CPT::read()` は投稿欠損で例外を投げない）、
		// 投稿の有無で判定する。
		$this->assertNull( get_post( $variation_id ) );
		$this->assertNull( get_post( $image_id ) );
		$this->assertNotInstanceOf( WC_Order::class, wc_get_order( $order_id ) );
		$this->assertNull( get_post( $coupon_id ) );
		$this->assertNull( get_term( $category_id, 'product_cat' ) );
		$this->assertFalse( get_userdata( $new_user_id ) );

		$this->assertInstanceOf( WP_User::class, get_userdata( $existing_user_id ) );
		$this->assertSame( '', get_user_meta( $existing_user_id, '_cbjp_platform', true ) );
		$this->assertSame( '', get_user_meta( $existing_user_id, '_cbjp_remote_id', true ) );

		$this->assertInstanceOf( WC_Product::class, wc_get_product( $other_product_id ) );
		$this->assertSame( $other_product_id, $this->mappings->find_local_id( 'other', 'product', 'op1' ) );

		foreach ( SampleCleanup::RESULT_KEYS as $key ) {
			$this->assertSame( 0, $this->mappings->count( 'mock', $key ), "mock の {$key} mapping が残っている" );
		}

		$this->assertFalse( get_option( SampleSelector::option_name_for( 'mock' ) ) );
	}

	public function test_budget_splits_the_cleanup_into_batches_and_finalizes_only_at_the_end(): void {
		foreach ( [ 'c1', 'c2', 'c3' ] as $remote_id ) {
			$this->import( 'mock', 'category', CanonicalFactory::category( $remote_id, "Category {$remote_id}" ) );
		}

		update_option( SampleSelector::option_name_for( 'mock' ), [ 'order_remote_ids' => [] ], false );

		$cleanup = new SampleCleanup( $this->mappings );

		$first = $cleanup->run( 'mock', 2 );

		$this->assertTrue( $first['has_more'] );
		$this->assertSame( 2, $first['deleted']['category'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'category' ) );
		$this->assertNotFalse( get_option( SampleSelector::option_name_for( 'mock' ) ), '完了前にサンプルセットを消さない' );

		$second = $cleanup->run( 'mock', 2 );

		$this->assertFalse( $second['has_more'] );
		$this->assertSame( 1, $second['deleted']['category'] );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'category' ) );
		$this->assertFalse( get_option( SampleSelector::option_name_for( 'mock' ) ) );
	}

	public function test_staff_accounts_and_the_current_user_are_never_deleted(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $admin_id, '_cbjp_platform', 'mock' );
		update_user_meta( $admin_id, '_cbjp_remote_id', 'cu-admin' );
		update_user_meta( $admin_id, CustomerWriter::CREATED_BY_IMPORT_META, '1' );
		$this->mappings->upsert( 'mock', 'customer', 'cu-admin', $admin_id, null );

		$self_id = $this->import( 'mock', 'customer', CanonicalFactory::customer( 'cu-self', 'self@example.com' ) );
		wp_set_current_user( $self_id );

		$result = ( new SampleCleanup( $this->mappings ) )->run( 'mock' );

		$this->assertSame( 0, $result['deleted']['customer'] );
		$this->assertSame( 2, $result['unlinked']['customer'] );
		$this->assertInstanceOf( WP_User::class, get_userdata( $admin_id ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( $self_id ) );
		$this->assertSame( '', get_user_meta( $admin_id, '_cbjp_platform', true ) );
		$this->assertSame( '', get_user_meta( $self_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'customer' ) );
	}

	public function test_missing_entities_are_only_unlinked(): void {
		foreach ( [ 'category', 'tag', 'product', 'variant', 'customer', 'order', 'coupon', 'review' ] as $entity ) {
			$this->mappings->upsert( 'mock', $entity, "gone-{$entity}", 999999, null );
		}

		$result = ( new SampleCleanup( $this->mappings ) )->run( 'mock' );

		$this->assertFalse( $result['has_more'] );
		$this->assertSame( 0, array_sum( $result['deleted'] ) );
		$this->assertSame( 8, array_sum( $result['unlinked'] ) );

		foreach ( SampleCleanup::RESULT_KEYS as $key ) {
			$this->assertSame( 0, $this->mappings->count( 'mock', $key ) );
		}
	}
}
