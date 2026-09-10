<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Tools;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Sync\SampleSelector;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Tools\CleanupNotPermittedException;
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

	public function set_up(): void {
		parent::set_up();
		// `wp_delete_user()` は capability を見ないため、ツール側で `delete_user` を要求する。
		// テストは管理者として実行する（shop_manager のケースは個別テストで確認）。
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

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

		// プレビューは mapping 行数ではなく「実際に削除される／mapping を外すだけ」を `run()` と同じ判定で分ける。
		$this->assertSame( 1, $preview['delete']['category'] );
		$this->assertSame( 1, $preview['delete']['product'] );
		$this->assertSame( 1, $preview['unlink']['product'], '別プラットフォーム所有の実体は unlink' );
		$this->assertSame( 1, $preview['delete']['variant'] );
		$this->assertSame( 1, $preview['delete']['customer'] );
		$this->assertSame( 1, $preview['unlink']['customer'], 'email突合で採用した既存アカウントは unlink' );
		$this->assertSame( 1, $preview['delete']['order'] );
		$this->assertSame( 1, $preview['unlink']['stock'] );
		$this->assertSame( 1, $preview['delete']['coupon'] );
		$this->assertSame( 1, $preview['delete']['attachment'] );
		$this->assertTrue( $preview['requires_delete_users'] );
		$this->assertTrue( $preview['can_delete_users'] );
		$this->assertTrue( $preview['sample_selected'] );

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
		update_user_meta( $admin_id, CustomerWriter::CREATED_BY_IMPORT_META, 'mock' );
		$this->mappings->upsert( 'mock', 'customer', 'cu-admin', $admin_id, null );

		$self_id = $this->import( 'mock', 'customer', CanonicalFactory::customer( 'cu-self', 'self@example.com' ) );
		// 実行者に削除権限を持たせたうえで、保護ロール・自分自身のガードだけで unlink に倒れることを確認する
		// （権限が無いだけで unlink になると、このテストがガードを検証しなくなる）。管理者を対象にした
		// `delete_user` は WooCommerce の `wc_modify_map_meta_cap` が非管理者に対して拒否するため、
		// 「自分自身」に対する capability が開いていることで権限側の前提を確認する。
		( new WP_User( $self_id ) )->add_cap( 'delete_users' );
		wp_set_current_user( $self_id );
		$this->assertTrue( current_user_can( 'delete_user', $self_id ) );

		$result = ( new SampleCleanup( $this->mappings ) )->run( 'mock' );

		$this->assertSame( 0, $result['deleted']['customer'] );
		$this->assertSame( 2, $result['unlinked']['customer'] );
		$this->assertInstanceOf( WP_User::class, get_userdata( $admin_id ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( $self_id ) );
		$this->assertSame( '', get_user_meta( $admin_id, '_cbjp_platform', true ) );
		$this->assertSame( '', get_user_meta( $self_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'customer' ) );
	}

	public function test_shop_manager_cannot_run_cleanup_while_import_created_accounts_exist(): void {
		// `manage_woocommerce` は持つが `delete_users` は持たない shop_manager が、WP 管理画面では
		// できないアカウント削除をこのツール経由で行えてはならない。かといって unlink だけして
		// mappings とサンプルセットをリセットすると、無料版の顧客上限を回避してアカウントを増やし続けられる
		// ため、実行自体を拒否する。
		$user_id = $this->import( 'mock', 'customer', CanonicalFactory::customer( 'cu1', 'cu1@example.com' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cleanup = new SampleCleanup( $this->mappings );
		$preview = $cleanup->preview( 'mock' );

		$this->assertTrue( $preview['requires_delete_users'] );
		$this->assertFalse( $preview['can_delete_users'] );
		$this->assertSame( 0, $preview['delete']['customer'] );
		$this->assertSame( 1, $preview['unlink']['customer'] );

		$this->expectException( CleanupNotPermittedException::class );

		try {
			$cleanup->run( 'mock' );
		} finally {
			$this->assertInstanceOf( WP_User::class, get_userdata( $user_id ) );
			$this->assertSame( 'mock', get_user_meta( $user_id, '_cbjp_platform', true ), '拒否時は何も変更しない' );
			$this->assertSame( 1, $this->mappings->count( 'mock', 'customer' ) );
		}
	}

	public function test_shop_manager_can_clean_up_when_no_import_created_accounts_remain(): void {
		// 採用した既存アカウントしか無ければ削除権限は不要（unlink のみ）。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'existing2',
				'user_email' => 'existing2@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);
		$this->import( 'mock', 'customer', CanonicalFactory::customer( 'cu2', 'existing2@example.com' ) );
		$this->import( 'mock', 'category', CanonicalFactory::category( 'c1', 'Category 1' ) );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'shop_manager' ] ) );

		$cleanup = new SampleCleanup( $this->mappings );
		$this->assertFalse( $cleanup->preview( 'mock' )['requires_delete_users'] );

		$result = $cleanup->run( 'mock' );

		$this->assertFalse( $result['has_more'] );
		$this->assertSame( 1, $result['deleted']['category'] );
		$this->assertSame( 1, $result['unlinked']['customer'] );
		$this->assertInstanceOf( WP_User::class, get_userdata( $existing_id ) );
	}

	public function test_marker_from_another_platform_prevents_deletion(): void {
		// A が作成したアカウントを B が email 突合で採用した場合、B のクリーンアップは削除ではなく unlink。
		$user_id = $this->import( 'other', 'customer', CanonicalFactory::customer( 'a1', 'shared@example.com' ) );
		$this->assertSame( 'other', get_user_meta( $user_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) );

		$adopted_id = $this->import( 'mock', 'customer', CanonicalFactory::customer( 'b1', 'shared@example.com' ) );
		$this->assertSame( $user_id, $adopted_id );
		$this->assertSame( 'mock', get_user_meta( $user_id, '_cbjp_platform', true ) );

		$cleanup = new SampleCleanup( $this->mappings );
		$this->assertFalse( $cleanup->requires_user_deletion( 'mock' ) );

		$result = $cleanup->run( 'mock' );

		$this->assertSame( 0, $result['deleted']['customer'] );
		$this->assertSame( 1, $result['unlinked']['customer'] );
		$this->assertInstanceOf( WP_User::class, get_userdata( $user_id ) );
	}

	public function test_budget_exhausted_by_variations_stops_the_page_early(): void {
		$product_a = $this->import(
			'mock',
			'product',
			CanonicalFactory::product(
				'pa',
				'SKU-A',
				5,
				[
					[
						'remote_id'     => 'va1',
						'sku'           => 'SKU-A-1',
						'option1_name'  => 'Size',
						'option1_value' => 'S',
						'price'         => '1000',
						'stock'         => null,
					],
					[
						'remote_id'     => 'va2',
						'sku'           => 'SKU-A-2',
						'option1_name'  => 'Size',
						'option1_value' => 'M',
						'price'         => '1000',
						'stock'         => null,
					],
					[
						'remote_id'     => 'va3',
						'sku'           => 'SKU-A-3',
						'option1_name'  => 'Size',
						'option1_value' => 'L',
						'price'         => '1000',
						'stock'         => null,
					],
				]
			)
		);
		$product_b = $this->import( 'mock', 'product', CanonicalFactory::product( 'pb', 'SKU-B' ) );

		$cleanup = new SampleCleanup( $this->mappings );
		$first   = $cleanup->run( 'mock', 2 );

		// 商品 A（+ variation 3 件）で予算 2 を使い切るため、同じページに含まれる商品 B は次のバッチへ回る。
		$this->assertTrue( $first['has_more'] );
		$this->assertSame( 1, $first['deleted']['product'] );
		$this->assertSame( 3, $first['deleted']['variant'] );
		$this->assertNull( get_post( $product_a ) );
		$this->assertInstanceOf( WC_Product::class, wc_get_product( $product_b ) );

		$second = $cleanup->run( 'mock', 2 );

		$this->assertFalse( $second['has_more'] );
		$this->assertSame( 1, $second['deleted']['product'] );
		$this->assertNull( get_post( $product_b ) );
	}

	public function test_run_with_nothing_linked_still_clears_the_sample_selection(): void {
		update_option( SampleSelector::option_name_for( 'mock' ), [ 'order_remote_ids' => [ '1' ] ], false );

		$cleanup = new SampleCleanup( $this->mappings );
		$preview = $cleanup->preview( 'mock' );

		$this->assertSame( 0, array_sum( $preview['delete'] ) + array_sum( $preview['unlink'] ) );
		$this->assertTrue( $preview['sample_selected'] );

		$result = $cleanup->run( 'mock' );

		$this->assertFalse( $result['has_more'] );
		$this->assertFalse( get_option( SampleSelector::option_name_for( 'mock' ) ) );
		$this->assertFalse( $cleanup->preview( 'mock' )['sample_selected'] );
	}

	public function test_images_of_products_that_are_still_linked_elsewhere_or_unmapped_are_preserved(): void {
		// mappings を失った店舗（Rebuild links が想定する状況）でクリーンアップを実行しても、
		// 残っている商品の画像を道連れにしない。
		$this->stub_image_http();

		$product_id = $this->import( 'mock', 'product', $this->variable_product_with_image( 'p1', 'SKU-KEEP' ) );
		$image_id   = (int) wc_get_product( $product_id )->get_image_id();
		$this->assertGreaterThan( 0, $image_id );

		$this->mappings->delete_for_platform( 'mock' );

		$cleanup = new SampleCleanup( $this->mappings );

		$this->assertSame( 0, $cleanup->preview( 'mock' )['delete']['attachment'] );

		$result = $cleanup->run( 'mock' );

		$this->assertFalse( $result['has_more'] );
		$this->assertSame( 0, $result['deleted']['attachment'] );
		$this->assertInstanceOf( WC_Product::class, wc_get_product( $product_id ) );
		$this->assertInstanceOf( \WP_Post::class, get_post( $image_id ) );
	}

	public function test_term_thumbnails_of_surviving_terms_are_preserved(): void {
		// タームの画像は親投稿 0 で取り込まれる（`TermWriter` → `MediaImporter::import( $url, 0 )`）ため、
		// 「親が無い」だけで孤児と判定すると残っているカテゴリの画像を消してしまう。
		$this->stub_image_http();

		$category_id = $this->import( 'mock', 'category', new CanonicalCategory( 'c1', 'Category 1', null, null, [ 'image_url' => 'https://example.test/c1.png' ] ) );
		$thumbnail   = (int) get_term_meta( $category_id, 'thumbnail_id', true );
		$this->assertGreaterThan( 0, $thumbnail );
		$this->assertSame( 'mock', get_post_meta( $thumbnail, '_cbjp_platform', true ) );

		$this->mappings->delete_for_platform( 'mock' );

		$cleanup = new SampleCleanup( $this->mappings );

		$this->assertSame( 0, $cleanup->preview( 'mock' )['delete']['attachment'] );
		$cleanup->run( 'mock' );

		$this->assertInstanceOf( \WP_Post::class, get_post( $thumbnail ) );

		// mapping があれば、タームと一緒に画像も消える。
		$this->mappings->upsert( 'mock', 'category', 'c1', $category_id, null );
		$this->assertSame( 1, $cleanup->preview( 'mock' )['delete']['attachment'] );
		$result = $cleanup->run( 'mock' );

		$this->assertSame( 1, $result['deleted']['category'] );
		$this->assertSame( 1, $result['deleted']['attachment'] );
		$this->assertNull( get_post( $thumbnail ) );
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
