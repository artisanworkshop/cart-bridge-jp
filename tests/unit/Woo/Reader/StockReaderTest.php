<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Tests\Fixtures\VariableProductFactory;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Reader\StockReader;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\WarningCode;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

final class StockReaderTest extends WooTestCase {

	private const PLATFORM = 'colorme';

	private function make_reader(): StockReader {
		return new StockReader( self::PLATFORM, $this->mappings );
	}

	private function create_simple_product( int $stock_quantity ): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product->set_sku( 'WIDGET-1' );
		$product->set_regular_price( '1000' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock_quantity );

		return $product->save();
	}

	public function test_reads_simple_product_stock_with_resolved_mapping(): void {
		$product_id = $this->create_simple_product( 7 );
		$this->seed_mapping( self::PLATFORM, 'product', 'p-100', $product_id );

		$page = $this->make_reader()->query( Cursor::start(), [ $product_id ] );
		$this->assertCount( 1, $page->items );

		$item  = $page->items[0];
		$stock = $item->item;

		$this->assertSame( $product_id, $item->local_id );
		$this->assertSame( [], $item->warnings );
		$this->assertTrue( $item->fully_resolved );
		$this->assertSame( 'p-100', $stock->product_ref );
		$this->assertNull( $stock->variant_ref );
		$this->assertSame( 'WIDGET-1', $stock->sku );
		$this->assertSame( 7, $stock->quantity );
		$this->assertTrue( $stock->in_stock );
	}

	public function test_unmanaged_stock_reads_as_null(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'No Stock Mgmt' );
		$product->set_regular_price( '1000' );
		$product_id = $product->save();
		$this->seed_mapping( self::PLATFORM, 'product', 'p-9', $product_id );

		$page = $this->make_reader()->query( Cursor::start(), [ $product_id ] );
		$this->assertNull( $page->items[0]->item->quantity );
	}

	/**
	 * `manage_stock=true`かつ数量が正でも、`woocommerce_notify_no_stock_amount`
	 * （WooCommerce設定「在庫切れ通知のしきい値」）以下なら`stock_status`は`outofstock`になる
	 * （`WC_Product::validate_props()`が`save()`の度に強制する。`wp eval-file`で実測確認済み）。
	 * 数量をそのまま申告すると店舗が安全在庫として確保した分までASP側で購入可能として
	 * 申告してしまうため、0にフェイルクローズすることを確認する（`Woo\Support\StockDerivation`）。
	 */
	public function test_stock_below_notify_threshold_reads_as_zero_despite_positive_quantity(): void {
		update_option( 'woocommerce_notify_no_stock_amount', 10 );

		try {
			$product = new WC_Product_Simple();
			$product->set_name( 'Buffer Stock Widget' );
			$product->set_regular_price( '1000' );
			$product->set_manage_stock( true );
			$product->set_backorders( 'no' );
			$product->set_stock_quantity( 5 );
			$product_id = $product->save();
		} finally {
			update_option( 'woocommerce_notify_no_stock_amount', 0 );
		}

		$this->seed_mapping( self::PLATFORM, 'product', 'p-buffer', $product_id );

		$page  = $this->make_reader()->query( Cursor::start(), [ $product_id ] );
		$stock = $page->items[0]->item;

		$this->assertSame( 0, $stock->quantity );
		$this->assertFalse( $stock->in_stock );
	}

	public function test_unresolved_product_mapping_blocks_export_with_warning(): void {
		$product_id = $this->create_simple_product( 5 );

		$page      = $this->make_reader()->query( Cursor::start(), [ $product_id ] );
		$read_item = $page->items[0];

		$this->assertFalse( $read_item->fully_resolved );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_NOT_EXPORTED, (string) $product_id ), $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	private function make_variable_product_with_variations( array $variation_specs ): array {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 );
		$attribute->set_name( 'Size' );
		$attribute->set_options( array_column( $variation_specs, 'size' ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		$variation_ids = [];

		foreach ( $variation_specs as $spec ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( [ 'size' => $spec['size'] ] );
			$variation->set_regular_price( '1000' );
			$variation->set_sku( $spec['sku'] );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $spec['stock'] );

			if ( isset( $spec['status'] ) ) {
				$variation->set_status( $spec['status'] );
			}

			$variation_ids[ $spec['size'] ] = $variation->save();
		}

		return [ $parent_id, $variation_ids ];
	}

	public function test_variable_product_expands_to_one_item_per_published_variation(): void {
		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
				[
					'size'  => 'M',
					'sku'   => 'SHIRT-M',
					'stock' => 4,
				],
			]
		);

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-m', $variation_ids['M'] );

		$page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$this->assertCount( 2, $page->items );

		$by_variant_ref = [];

		foreach ( $page->items as $item ) {
			$by_variant_ref[ $item->item->variant_ref ] = $item;
		}

		$this->assertSame( 'p-parent', $by_variant_ref['v-s']->item->product_ref );
		$this->assertSame( 3, $by_variant_ref['v-s']->item->quantity );
		$this->assertSame( 4, $by_variant_ref['v-m']->item->quantity );
	}

	/**
	 * 親商品は解決済みでも、個々のバリエーションが（E2-3未実装のため）まだASP側に
	 * remote_idを持たない状態は現実的なシナリオ（`docs/03-design-decisions.md`
	 * 「E2-3への申し送り」参照）。一部だけ解決済みの場合、解決済みの行はpushされ、
	 * 未解決の行だけがブロックされることを確認する。
	 */
	public function test_partially_resolved_variations_only_block_the_unresolved_ones(): void {
		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
				[
					'size'  => 'M',
					'sku'   => 'SHIRT-M',
					'stock' => 4,
				],
			]
		);

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		// サイズSのバリエーションだけremote_idを持たせる。サイズMは未解決のまま残す。
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );

		$page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$this->assertCount( 2, $page->items );

		$by_local_id = [];

		foreach ( $page->items as $item ) {
			$by_local_id[ $item->local_id ] = $item;
		}

		$resolved = $by_local_id[ $variation_ids['S'] ];
		$this->assertSame( 'v-s', $resolved->item->variant_ref );
		$this->assertTrue( $resolved->fully_resolved );

		$unresolved = $by_local_id[ $variation_ids['M'] ];
		$this->assertFalse( $unresolved->fully_resolved );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_NOT_EXPORTED, (string) $variation_ids['M'] ), $unresolved->warnings );
	}

	public function test_unpublished_variation_is_omitted_entirely(): void {
		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
				[
					'size'   => 'M',
					'sku'    => 'SHIRT-M',
					'stock'  => 4,
					'status' => 'private',
				],
			]
		);

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-m', $variation_ids['M'] );

		$page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );

		$this->assertCount( 1, $page->items );
		$this->assertSame( 'v-s', $page->items[0]->item->variant_ref );
	}

	public function test_variation_stock_shared_with_parent_fails_closed_with_warning(): void {
		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
			]
		);

		// `WC_Product_Variation::get_manage_stock('view')`（既定コンテキスト）は、バリエーション
		// 自身が在庫管理していなくても**親が在庫管理している場合のみ**`'parent'`を返す
		// （`parent_data['manage_stock']`を見る。CLAUDE.md参照）。親側の在庫管理を明示しないと
		// このテストが検証したい「共有プール」状態を再現できない。
		$parent = wc_get_product( $parent_id );
		$parent->set_manage_stock( true );
		$parent->set_stock_quantity( 10 );
		$parent->save();

		$variation = wc_get_product( $variation_ids['S'] );
		$variation->set_manage_stock( false );
		$variation->save();

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );

		$page      = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$read_item = $page->items[0];

		$this->assertSame( 0, $read_item->item->quantity );
		$this->assertContains( WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT, $read_item->warnings );
	}

	public function test_page_total_is_always_null(): void {
		$product_id = $this->create_simple_product( 1 );
		$this->seed_mapping( self::PLATFORM, 'product', 'p-1', $product_id );

		$page = $this->make_reader()->query( Cursor::start(), null );
		$this->assertNull( $page->total );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}

	/**
	 * @param array<int,string> $warnings
	 */
	private function has_mixed_warning( array $warnings ): bool {
		return WarningCode::indicates_variation_stock_mixed( $warnings );
	}

	/**
	 * D22: 在庫管理が混在する商品（管理中5・管理外の在庫あり・管理外の在庫切れ）は、mapping解決済みの
	 * 全バリエーション行に`VARIATION_STOCK_MANAGEMENT_MIXED`が付く（止めるかどうかは`Exporter`が
	 * アダプタの能力で決める）。数量そのものは従来どおり。
	 */
	public function test_mixed_variation_stock_management_is_reported_on_every_variation_row(): void {
		$parent_id = VariableProductFactory::create_parent( 'Mixed stock' );
		$managed   = VariableProductFactory::add_variation(
			$parent_id,
			[ 'size' => 'S' ],
			[
				'manage_stock'   => true,
				'stock_quantity' => 5,
			]
		);
		$unmanaged = VariableProductFactory::add_variation( $parent_id, [ 'size' => 'M' ] );
		$sold_out  = VariableProductFactory::add_variation( $parent_id, [ 'size' => 'L' ], [ 'stock_status' => 'outofstock' ] );

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $managed );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-m', $unmanaged );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-l', $sold_out );

		$page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$this->assertCount( 3, $page->items );

		$quantities = [];

		foreach ( $page->items as $item ) {
			$this->assertContains( WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED, $item->warnings );
			$this->assertFalse( WarningCode::indicates_export_blocking( $item->warnings ), '止めるかどうかはアダプタの能力次第（Exporterが決める）' );
			$quantities[ $item->item->variant_ref ] = $item->item->quantity;
		}

		$this->assertSame(
			[
				'v-s' => 5,
				'v-m' => null,
				'v-l' => 0,
			],
			$quantities
		);
	}

	/**
	 * mapping未解決の行（`STOCK_PRODUCT_NOT_EXPORTED`）にも混在の警告を積む。商品より先に在庫行だけを見る
	 * 店舗（dry-run）にも理由が分かるようにする。
	 */
	public function test_mixed_warning_is_also_reported_on_unresolved_rows(): void {
		$parent_id = VariableProductFactory::create_parent( 'Mixed unresolved' );
		$managed   = VariableProductFactory::add_variation(
			$parent_id,
			[ 'size' => 'S' ],
			[
				'manage_stock'   => true,
				'stock_quantity' => 5,
			]
		);
		$unmanaged = VariableProductFactory::add_variation( $parent_id, [ 'size' => 'M' ] );

		// 親は未エクスポート（product mappingなし）。
		$page = $this->make_reader()->query( Cursor::start(), [ $parent_id ] );
		$this->assertCount( 2, $page->items );

		foreach ( $page->items as $item ) {
			$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_NOT_EXPORTED, (string) $item->local_id ), $item->warnings );
			$this->assertContains( WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED, $item->warnings );
		}

		// 親は解決済みだが、バリエーション（管理外の側）だけ未解決。
		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $managed );

		$by_local = [];

		foreach ( $this->make_reader()->query( Cursor::start(), [ $parent_id ] )->items as $item ) {
			$by_local[ $item->local_id ] = $item;
		}

		$this->assertContains( WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED, $by_local[ $managed ]->warnings );
		$this->assertContains( WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED, $by_local[ $unmanaged ]->warnings );
		$this->assertContains( WarningCode::with_detail( WarningCode::STOCK_PRODUCT_NOT_EXPORTED, (string) $unmanaged ), $by_local[ $unmanaged ]->warnings );
	}

	public function test_uniform_variation_stock_management_is_not_reported(): void {
		$parent_id = VariableProductFactory::create_parent( 'Uniform stock' );
		$a         = VariableProductFactory::add_variation(
			$parent_id,
			[ 'size' => 'S' ],
			[
				'manage_stock'   => true,
				'stock_quantity' => 5,
			]
		);
		$b         = VariableProductFactory::add_variation(
			$parent_id,
			[ 'size' => 'M' ],
			[
				'manage_stock'   => true,
				'stock_quantity' => 2,
			]
		);

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $a );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-m', $b );

		foreach ( $this->make_reader()->query( Cursor::start(), [ $parent_id ] )->items as $item ) {
			$this->assertSame( [], $item->warnings );
		}
	}

	/**
	 * 商品行（`ProductReader`）と在庫行（`StockReader`）は同じ判定・同じ母集団（公開バリエーションすべて）で
	 * 混在を判定する。層ごとに判定が食い違うと、商品は止まるのに在庫だけ通る（またはその逆）状態になる。
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>>,1:bool}>
	 */
	public static function agreement_cases(): array {
		return [
			'managed + unmanaged in stock'                 => [
				[
					[
						'size' => 'S',
						'args' => [
							'manage_stock'   => true,
							'stock_quantity' => 5,
						],
					],
					[
						'size' => 'M',
						'args' => [],
					],
				],
				true,
			],
			'unmanaged out of stock + unmanaged in stock'  => [
				[
					[
						'size' => 'S',
						'args' => [ 'stock_status' => 'outofstock' ],
					],
					[
						'size' => 'M',
						'args' => [],
					],
				],
				true,
			],
			'all managed'                                  => [
				[
					[
						'size' => 'S',
						'args' => [
							'manage_stock'   => true,
							'stock_quantity' => 5,
						],
					],
					[
						'size' => 'M',
						'args' => [
							'manage_stock'   => true,
							'stock_quantity' => 1,
						],
					],
				],
				false,
			],
			'unmanaged variation is private (not counted)' => [
				[
					[
						'size' => 'S',
						'args' => [
							'manage_stock'   => true,
							'stock_quantity' => 5,
						],
					],
					[
						'size' => 'M',
						'args' => [ 'status' => 'private' ],
					],
				],
				false,
			],
			'invalid price variation (still counted)'      => [
				[
					[
						'size' => 'S',
						'args' => [
							'manage_stock'   => true,
							'stock_quantity' => 5,
						],
					],
					[
						'size' => 'M',
						'args' => [ 'price' => '' ],
					],
				],
				true,
			],
		];
	}

	/**
	 * @dataProvider agreement_cases
	 * @param array<int,array<string,mixed>> $variations
	 */
	public function test_product_and_stock_readers_agree_on_mixed_management( array $variations, bool $expected_mixed ): void {
		$parent_id = VariableProductFactory::create_parent( 'Agreement' );

		foreach ( $variations as $spec ) {
			$variation_id = VariableProductFactory::add_variation( $parent_id, [ 'size' => $spec['size'] ], $spec['args'] );
			$this->seed_mapping( self::PLATFORM, 'variant', "v-{$spec['size']}", $variation_id );
		}

		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );

		$product_item = ( new ProductReader( self::PLATFORM, new MethodMap( self::PLATFORM ), $this->mappings ) )->query( Cursor::start(), [ $parent_id ] )->items[0];
		$this->assertSame( $expected_mixed, $this->has_mixed_warning( $product_item->warnings ), 'ProductReader' );

		$stock_items = $this->make_reader()->query( Cursor::start(), [ $parent_id ] )->items;
		$this->assertNotEmpty( $stock_items );

		foreach ( $stock_items as $item ) {
			$this->assertSame( $expected_mixed, $this->has_mixed_warning( $item->warnings ), 'StockReader local_id=' . $item->local_id );
		}
	}

	/**
	 * @return array<string,array{0:?string,1:bool}>
	 */
	public function linked_by_import_cases(): array {
		return [
			'imported from the same platform' => [ self::PLATFORM, true ],
			'imported from another platform'  => [ 'makeshop', false ],
			'woo born'                        => [ null, false ],
		];
	}

	/**
	 * D25（issue #98）: 在庫は商品に従う。在庫行を作るすべての箇所（単純商品・解決したバリエーション・未解決の行〔mapping の無い単純商品・
	 * mapping の無いバリエーション・mapping の無い variable 親のバリエーション〕）で、商品（variable は親）の
	 * 取込みの印から`linked_by_import`を立て、`ProductReader`の判定と一致する（同じ事実を 2 つの Reader で判定するため一致を固定する）。
	 *
	 * @dataProvider linked_by_import_cases
	 */
	public function test_every_stock_row_follows_the_product_and_agrees_with_the_product_reader( ?string $platform, bool $expected ): void {
		$mapped_simple = $this->create_simple_product( 5 );
		$this->seed_mapping( self::PLATFORM, 'product', 'p-simple', $mapped_simple );

		$unmapped = new WC_Product_Simple();
		$unmapped->set_name( 'Unmapped' );
		$unmapped->set_regular_price( '1000' );
		$unmapped_simple = $unmapped->save();

		[ $parent_id, $variation_ids ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'SHIRT-S',
					'stock' => 3,
				],
				[
					'size'  => 'M',
					'sku'   => 'SHIRT-M',
					'stock' => 4,
				],
			]
		);
		$this->seed_mapping( self::PLATFORM, 'product', 'p-parent', $parent_id );
		$this->seed_mapping( self::PLATFORM, 'variant', 'v-s', $variation_ids['S'] );

		// 親に mapping の無い variable 商品（mapping を失った取込み品）。バリエーションの行はすべて未解決になる。
		[ $unmapped_parent ] = $this->make_variable_product_with_variations(
			[
				[
					'size'  => 'S',
					'sku'   => 'TEE-S',
					'stock' => 1,
				],
			]
		);

		$products = [ $mapped_simple, $unmapped_simple, $parent_id, $unmapped_parent ];

		if ( null !== $platform ) {
			foreach ( $products as $product_id ) {
				update_post_meta( $product_id, '_cbjp_platform', $platform );
			}
		}

		$stock_items = $this->make_reader()->query( Cursor::start(), $products )->items;
		$this->assertCount( 5, $stock_items );

		$unresolved = array_filter( $stock_items, static fn ( $item ): bool => ! $item->fully_resolved );
		$this->assertCount( 3, $unresolved, 'unmapped simple product, variation M and the variation of the unmapped parent' );

		foreach ( $stock_items as $item ) {
			$this->assertSame( $expected, $item->linked_by_import, 'local_id=' . $item->local_id );
		}

		$product_items = ( new ProductReader( self::PLATFORM, new MethodMap( self::PLATFORM ), $this->mappings ) )->query( Cursor::start(), $products )->items;
		$this->assertCount( 4, $product_items );

		foreach ( $product_items as $item ) {
			$this->assertSame( $expected, $item->linked_by_import, 'ProductReader local_id=' . $item->local_id );
		}
	}
}
