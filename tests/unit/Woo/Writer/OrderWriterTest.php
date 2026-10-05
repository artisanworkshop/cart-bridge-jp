<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Adapters\ColorMe\Transform\OrderTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\ProductTransformer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\OrderItemBuilder;
use CartBridgeJP\Woo\Writer\OrderWriter;
use CartBridgeJP\Woo\Writer\ProductWriter;
use CartBridgeJP\Woo\Writer\VariationWriter;
use Automattic\WooCommerce\Caches\OrderCountCacheService;
use WC_Customer;
use WC_Order_Item_Product;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Shipping_Zone;

final class OrderWriterTest extends WooTestCase {

	private function make_writer(): OrderWriter {
		$resolver = new ProductResolver( 'colorme', $this->mappings );

		return new OrderWriter( 'colorme', $this->mappings, new OrderItemBuilder( $resolver ), new MethodMap( 'colorme' ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $line_items
	 * @param array<string,mixed>            $shipping
	 * @param array<string,mixed>            $payment
	 * @param array<string,mixed>            $totals
	 * @param array<string,mixed>            $extras
	 */
	private function make_order(
		string $number = '1',
		string $status = 'processing',
		?string $customer_ref = null,
		array $line_items = [],
		array $shipping = [],
		array $payment = [],
		array $totals = [
			'total'        => '1000',
			'tax'          => '0',
			'shipping_fee' => '0',
			'discount'     => '0',
		],
		array $extras = []
	): CanonicalOrder {
		return new CanonicalOrder( $number, $status, $customer_ref, $line_items, $shipping, $payment, $totals, '2026-01-01T00:00:00+00:00', null, $extras );
	}

	/**
	 * `ProductWriter`+`VariationWriter`の実パイプラインでvariable親商品+variationを作る
	 * （手組みの`WC_Product_Attribute`/`WC_Product_Variation`だと本番の属性構造とズレて
	 * `ProductResolver::resolve_variation_by_options()`の前提が崩れやすいため）。
	 *
	 * @param array<int,array<string,mixed>> $variants `CanonicalFactory::product()`と同じ
	 *   `option1_name`/`option1_value`等のキー規約。
	 * @return int 親商品のローカルID。
	 */
	private function make_variable_product( string $product_remote_id, array $variants ): int {
		$writer    = new ProductWriter( 'colorme', $this->mappings, new VariationWriter( 'colorme', $this->mappings ), new MediaImporter( 'colorme' ) );
		$canonical = CanonicalFactory::product( $product_remote_id, "SKU-{$product_remote_id}", 5, $variants );
		$result    = $writer->write( $canonical, null );

		// local_id=0（保存失敗）のまま`seed_mapping()`へ進むと、無効なmappingが登録されて
		// このヘルパーを呼んだ各テストの失敗理由が「variationが解決できない」等の的外れな
		// ものになり、本当の原因（商品保存失敗）の診断が難しくなる。ここで即座に落とす。
		$this->assertNotSame( 0, $result->local_id );

		// テスト環境のWooCommerce税設定（`prices_include_tax_disabled`）はこのヘルパーの
		// 関心事ではないため、その警告だけ除外して他に予期しない警告が無いことを確認する。
		$this->assertSame(
			[],
			array_values( array_diff( $result->warnings, [ WarningCode::PRICES_INCLUDE_TAX_DISABLED ] ) )
		);

		$this->seed_mapping( 'colorme', 'product', $product_remote_id, $result->local_id );

		return $result->local_id;
	}

	public function test_resolves_line_item_by_sku(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product->set_sku( 'WIDGET-1' );
		// SKUフォールバックのownershipガード（`PlatformOwnership`）を通すために必要
		// （`VariationWriter`/`ProductWriter`が通常のsync時に付与するメタを模擬している）。
		$product->update_meta_data( '_cbjp_platform', 'colorme' );
		$product_id = $product->save();

		$order = $this->make_order(
			'1001',
			'processing',
			null,
			[
				[
					'sku'                 => 'WIDGET-1',
					'remote_product_id'   => 'p1',
					'name'                => 'Widget (at purchase)',
					'price'               => '1100',
					'unit_price_excl_tax' => '1000',
					'subtotal'            => '1100',
					'quantity'            => 1,
				],
			]
		);

		$result = $this->make_writer()->write( $order, null );
		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );

		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );
		$this->assertCount( 1, $items );
		$this->assertSame( $product_id, $items[0]->get_product_id() );
		// 注文時点の商品名（現在の商品名ではなく）が使われる。
		$this->assertSame( 'Widget (at purchase)', $items[0]->get_name() );
		$this->assertSame( '1000', $items[0]->get_total() );
		$this->assertSame( '100', $items[0]->get_total_tax() );
	}

	public function test_line_item_sku_match_on_foreign_platform_product_is_treated_as_unresolved(): void {
		// `ProductResolver::resolve_stock_target()`のSKUフォールバックと同じownershipガード
		// （`PlatformOwnership`）を`resolve_by_sku_or_remote_id()`にも適用済み。偶然SKUが
		// 一致しただけの別プラットフォーム由来（または店舗手動作成）の商品に、注文明細を
		// 誤って紐付けてはならない（誤った商品・価格・統計が注文に付いてしまう）。
		$foreign = new WC_Product_Simple();
		$foreign->set_name( 'Foreign' );
		$foreign->set_sku( 'SHARED-SKU' );
		$foreign->update_meta_data( '_cbjp_platform', 'makeshop' );
		$foreign->save();

		$order = $this->make_order(
			'1017',
			'processing',
			null,
			[
				[
					'sku'                 => 'SHARED-SKU',
					'remote_product_id'   => 'p-unmapped',
					'name'                => 'Widget (at purchase)',
					'price'               => '1100',
					'unit_price_excl_tax' => '1000',
					'subtotal'            => '1100',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'p-unmapped' ), $result->warnings );
	}

	public function test_resolves_line_item_by_mapping_when_sku_missing(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product_id = $product->save();
		$this->seed_mapping( 'colorme', 'product', 'p1', $product_id );

		$order = $this->make_order(
			'1002',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'p1',
					'name'                => 'Widget',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( $product_id, $items[0]->get_product_id() );
	}

	public function test_unresolved_line_item_creates_custom_row_with_meta(): void {
		$order = $this->make_order(
			'1003',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'gone',
					'name'                => 'Deleted product',
					'price'               => '500',
					'unit_price_excl_tax' => '500',
					'subtotal'            => '500',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		$this->assertSame( 'gone', $items[0]->get_meta( '_cbjp_remote_product_id' ) );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'gone' ), $result->warnings );
	}

	public function test_line_item_resolving_to_variable_parent_is_treated_as_unresolved(): void {
		// ColorMeの受注明細は`remote_product_id`として常に親商品のIDしか持たない
		// （どのvariationかはoption1/2の値でしか特定できない）。SKU解決に失敗した場合、
		// remote_idフォールバックが親のvariable商品そのものに解決してしまうと、
		// variable商品は単体では購入対象にならないため不整合な注文行になる。
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Variable parent' );
		$parent->set_status( 'publish' );
		$parent_id = $parent->save();
		$this->seed_mapping( 'colorme', 'product', 'vp1', $parent_id );

		$order = $this->make_order(
			'3005',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'vp1',
					'name'                => 'Some variant',
					'price'               => '500',
					'unit_price_excl_tax' => '500',
					'subtotal'            => '500',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		// 商品は取り込み済みなので「先にインポートすれば消える」`ORDER_LINE_PRODUCT_UNRESOLVED`ではない（R3-0n）。
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp1' ), $result->warnings );
		$this->assertNotContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'vp1' ), $result->warnings );
	}

	/**
	 * R3-0n: `ORDER_LINE_VARIATION_UNMATCHED`は「mapping先が実在するvariable商品」のときだけ。mapping はあるが
	 * 取り込んだ商品が Woo 側で完全に削除された明細は、先にインポートし直せば解決しうるので従来どおり
	 * `ORDER_LINE_PRODUCT_UNRESOLVED`（`ProductResolver::maps_to_variable_product()`の否定側。ゴミ箱の商品は
	 * `wc_get_product()`が返すので実在する側に入る）。
	 */
	public function test_line_item_whose_mapped_variable_product_was_deleted_is_product_unresolved(): void {
		$parent = new WC_Product_Variable();
		$parent->set_name( 'Deleted variable parent' );
		$parent_id = $parent->save();
		$this->seed_mapping( 'colorme', 'product', 'vp-deleted', $parent_id );
		$parent->delete( true );

		$order = $this->make_order(
			'3006',
			'processing',
			null,
			[
				[
					'sku'                   => null,
					'remote_product_id'     => 'vp-deleted',
					'name'                  => 'Deleted variable parent（カラー：赤）',
					'price'                 => '500',
					'unit_price_excl_tax'   => '500',
					'subtotal'              => '500',
					'quantity'              => 1,
					'option1_value_current' => '赤',
				],
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'vp-deleted' ), $result->warnings );
		$this->assertNotContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp-deleted' ), $result->warnings );
	}

	public function test_line_item_resolves_to_variation_by_single_axis_option_value(): void {
		$parent_id    = $this->make_variable_product(
			'vp-single',
			[
				[
					'remote_id'     => 'v-red',
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '赤',
					'price'         => '1000',
					'stock'         => 5,
				],
				[
					'remote_id'     => 'v-blue',
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '青',
					'price'         => '1000',
					'stock'         => 5,
				],
			]
		);
		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v-red' );

		$order = $this->make_order(
			'4001',
			'processing',
			null,
			[
				[
					'sku'                   => null,
					'remote_product_id'     => 'vp-single',
					'name'                  => '商品（カラー：赤）',
					'price'                 => '1000',
					'unit_price_excl_tax'   => '1000',
					'subtotal'              => '1000',
					'quantity'              => 1,
					'option1_value_current' => '赤',
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( $parent_id, $items[0]->get_product_id() );
		$this->assertSame( $variation_id, $items[0]->get_variation_id() );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED ) )
		);
	}

	public function test_line_item_resolves_to_variation_when_only_option2_axis_is_populated(): void {
		// ColorMeのoption1/option2は独立フィールドで、option1が無くoption2のみ持つ商品も
		// 構造的にありうる（`ProductWriter::variation_axis_names()`参照）。この場合
		// `option1_value_current`は常にnullで`option2_value_current`だけが値を持つが、
		// 保存後のWC属性はどちらのスロット由来かを覚えていないため、スロット番号（0=option1）
		// に固定して対応付けると常に未解決になってしまっていた（Codexレビュー指摘）。
		$parent_id    = $this->make_variable_product(
			'vp-option2-only',
			[
				[
					'remote_id'     => 'v-m',
					'sku'           => null,
					'option2_name'  => 'Size',
					'option2_value' => 'M',
					'price'         => '1000',
					'stock'         => 5,
				],
				[
					'remote_id'     => 'v-l',
					'sku'           => null,
					'option2_name'  => 'Size',
					'option2_value' => 'L',
					'price'         => '1000',
					'stock'         => 5,
				],
			]
		);
		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v-m' );

		$order = $this->make_order(
			'4005',
			'processing',
			null,
			[
				[
					'sku'                   => null,
					'remote_product_id'     => 'vp-option2-only',
					'name'                  => '商品（サイズ：M）',
					'price'                 => '1000',
					'unit_price_excl_tax'   => '1000',
					'subtotal'              => '1000',
					'quantity'              => 1,
					'option2_value_current' => 'M',
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( $parent_id, $items[0]->get_product_id() );
		$this->assertSame( $variation_id, $items[0]->get_variation_id() );
	}

	public function test_line_item_resolves_to_variation_by_two_axis_option_values(): void {
		$this->make_variable_product(
			'vp-double',
			[
				[
					'remote_id'     => 'v-red-s',
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '赤',
					'option2_name'  => 'Size',
					'option2_value' => 'S',
					'price'         => '1000',
					'stock'         => 5,
				],
				[
					'remote_id'     => 'v-red-m',
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '赤',
					'option2_name'  => 'Size',
					'option2_value' => 'M',
					'price'         => '1000',
					'stock'         => 5,
				],
			]
		);
		$expected_variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'v-red-m' );

		$order = $this->make_order(
			'4002',
			'processing',
			null,
			[
				[
					'sku'                   => null,
					'remote_product_id'     => 'vp-double',
					'name'                  => '商品（カラー：赤、サイズ：M）',
					'price'                 => '1000',
					'unit_price_excl_tax'   => '1000',
					'subtotal'              => '1000',
					'quantity'              => 1,
					'option1_value_current' => '赤',
					'option2_value_current' => 'M',
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		// 「赤」だけならv-red-s/v-red-mの2件に一致してしまうため、option2（Size）まで
		// 揃って初めて一意にv-red-mへ絞り込めていることを確認する。
		$this->assertSame( $expected_variation_id, $items[0]->get_variation_id() );
	}

	public function test_line_item_option_value_not_matching_any_variation_remains_unresolved(): void {
		// `option1_value_current`はASP側APIの「最新の商品情報」（swagger の記述。実店舗の古い受注では受注時点の
		// 値だった例もある。R3-0n）で、オプション名変更後の受注では一致しないことがある。捏造した一致を返さず、
		// 従来どおり未解決としてフェイルクローズすることを確認する。
		$this->make_variable_product(
			'vp-nomatch',
			[
				[
					'remote_id'     => 'v-red',
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '赤',
					'price'         => '1000',
					'stock'         => 5,
				],
			]
		);

		$order = $this->make_order(
			'4003',
			'processing',
			null,
			[
				[
					'sku'                   => null,
					'remote_product_id'     => 'vp-nomatch',
					'name'                  => '商品（カラー：緑）',
					'price'                 => '1000',
					'unit_price_excl_tax'   => '1000',
					'subtotal'              => '1000',
					'quantity'              => 1,
					'option1_value_current' => '緑',
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		// 商品は取り込み済みなので「先にインポートすれば消える」`ORDER_LINE_PRODUCT_UNRESOLVED`ではない（R3-0n）。
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp-nomatch' ), $result->warnings );
		$this->assertNotContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'vp-nomatch' ), $result->warnings );
	}

	public function test_line_item_ambiguous_variation_match_remains_unresolved(): void {
		// 2件のvariationが偶然同じ属性値を持つ（データ不整合等）場合、どちらか一方を
		// 恣意的に選ばず未解決としてフェイルクローズすることを確認する。
		$parent_id = $this->make_variable_product(
			'vp-dup',
			[
				[
					'remote_id'     => 'v-a',
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '赤',
					'price'         => '1000',
					'stock'         => 5,
				],
			]
		);

		// 属性値が重複する2件目のvariationを直接作成する（正規のVariationWriter経路は
		// remote_id単位のupsertのため、このような重複状態を通常は生まないが、
		// リンク再構築ツール（D16）等で別経路からデータが混入した場合の防御として検証する）。
		$duplicate = new WC_Product_Variation();
		$duplicate->set_parent_id( $parent_id );
		$duplicate->set_attributes( [ 'color' => '赤' ] );
		$duplicate->set_regular_price( '1000' );
		$duplicate->update_meta_data( '_cbjp_platform', 'colorme' );
		$duplicate->update_meta_data( '_cbjp_remote_id', 'v-a-dup' );
		$duplicate->save();

		$order = $this->make_order(
			'4004',
			'processing',
			null,
			[
				[
					'sku'                   => null,
					'remote_product_id'     => 'vp-dup',
					'name'                  => '商品（カラー：赤）',
					'price'                 => '1000',
					'unit_price_excl_tax'   => '1000',
					'subtotal'              => '1000',
					'quantity'              => 1,
					'option1_value_current' => '赤',
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_product_id() );
		// 商品は取り込み済みなので「先にインポートすれば消える」`ORDER_LINE_PRODUCT_UNRESOLVED`ではない（R3-0n）。
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp-dup' ), $result->warnings );
		$this->assertNotContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, 'vp-dup' ), $result->warnings );
	}

	public function test_real_colorme_variation_purchase_resolves_through_transformer_and_writer(): void {
		// 上記のvariation解決テストは`CanonicalProduct`/`CanonicalOrder`をテストコード側で
		// 直接組み立てているため、writerがテストコードが書いたキーと整合することしか
		// 証明できない。`ProductTransformer`/`OrderTransformer`が実際に出力するキー・値と
		// writer側の前提がずれていないかは、実フィクスチャを両方の変換層に通して初めて
		// 検証できる（CLAUDE.md「変換層とwriterをフィクスチャで別々にテストしても...層間ズレは
		// 検出できない」の適用例。Codexレビュー指摘）。
		//
		// `products.json`のid=192817159（バリエーション商品）と、その option1=赤/option2=S の
		// variant（remote_id=1847906909）を購入した`sale_daibiki_detail.json`を実際に
		// ProductTransformer/OrderTransformerへ通し、Woo書込み後に正しいvariationへ解決される
		// ことを確認する。
		$raw_products = FixtureLoader::load( 'colorme', 'products' )['products'];
		$raw_product  = current(
			array_filter( $raw_products, static fn ( array $p ): bool => 192817159 === ( $p['id'] ?? null ) )
		);
		$this->assertIsArray( $raw_product, 'Fixture product 192817159 not found; fixtures/colorme/products.json may have changed.' );

		$canonical_product = ( new ProductTransformer() )->transform( $raw_product );
		$product_writer    = new ProductWriter( 'colorme', $this->mappings, new VariationWriter( 'colorme', $this->mappings ), new MediaImporter( 'colorme' ) );
		$product_result    = $product_writer->write( $canonical_product, null );
		$this->assertNotSame( 0, $product_result->local_id );
		$this->seed_mapping( 'colorme', 'product', $canonical_product->remote_id(), $product_result->local_id );

		$expected_variation_id = $this->mappings->find_local_id( 'colorme', 'variant', '1847906909' );
		$this->assertNotNull( $expected_variation_id, 'Fixture variant 1847906909 (option1=赤/option2=S) not found after write.' );

		$raw_sale        = FixtureLoader::load( 'colorme', 'sale_daibiki_detail' )['sale'];
		$canonical_order = ( new OrderTransformer() )->transform( $raw_sale );

		$order_result = $this->make_writer()->write( $canonical_order, null );
		$wc_order     = wc_get_order( $order_result->local_id );
		$items        = array_values( $wc_order->get_items() );

		$this->assertCount( 1, $items );
		$this->assertSame( $product_result->local_id, $items[0]->get_product_id() );
		$this->assertSame( $expected_variation_id, $items[0]->get_variation_id() );
		$this->assertEmpty(
			array_filter(
				$order_result->warnings,
				static fn ( string $w ): bool => str_starts_with( $w, WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED ) || str_starts_with( $w, WarningCode::ORDER_LINE_VARIATION_UNMATCHED )
			)
		);
	}

	/**
	 * R3-0n: 実店舗の2018年の受注（匿名化）。受注時の商品はオプションが1軸だったが、後から2軸目（手提紙袋）が
	 * 追加され、現在の商品のバリエーションとは軸の数が合わない。商品は取り込み済みなので、先に商品をインポート
	 * しても消えない`ORDER_LINE_VARIATION_UNMATCHED`になり、明細は商品リンクの無いカスタム行で残る。
	 * 同じ受注は`sale.totals`が null（2019-09-09 以前）で、税合計の不完全も付く。
	 */
	public function test_real_order_whose_product_gained_an_option_axis_is_variation_unmatched(): void {
		$axes = static fn ( string $content, string $bag, string $remote_id ): array => [
			'remote_id'     => $remote_id,
			'sku'           => null,
			'option1_name'  => '内容',
			'option1_value' => $content,
			'option2_name'  => '手提紙袋',
			'option2_value' => $bag,
			'price'         => '2120',
			'stock'         => 5,
		];
		$this->make_variable_product(
			'900000052',
			[
				$axes( '白２本・赤２本', '必要', 'v-a' ),
				$axes( '白２本・赤２本', '不要', 'v-b' ),
			]
		);

		$raw_sale        = FixtureLoader::load( 'colorme', 'sale_without_totals_detail' )['sale'];
		$canonical_order = ( new OrderTransformer() )->transform( $raw_sale );

		// dry-run（`validate()`）も`write()`と同じ`prepare()`を通るので、同じ警告になる。
		$dry_run = $this->make_writer()->validate( $canonical_order, null );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, '900000052' ), $dry_run->warnings );

		$result   = $this->make_writer()->write( $canonical_order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertCount( 1, $items );
		$this->assertSame( 0, $items[0]->get_product_id() );
		$this->assertSame( 2, $items[0]->get_quantity() );
		$this->assertSame( '900000052', $items[0]->get_meta( '_cbjp_remote_product_id' ) );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, '900000052' ), $result->warnings );
		$this->assertNotContains( WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, '900000052' ), $result->warnings );
		$this->assertContains( WarningCode::ORDER_TAX_TOTAL_INCOMPLETE, $result->warnings );
	}

	/**
	 * R3-0n: 実店舗の受注（匿名化）で、一部の明細を外した行（`product_num=0`・`subtotal_price=0`。単価は残る）。
	 * 変換層→writer を通して、数量0・金額0の明細がそのまま残り、数量の捏造（数量1）とその副作用の税の不整合
	 * （`subtotal`0円＜税抜単価×1）が起きないことを確かめる。もう一方の明細は通常どおり解決・計算される。
	 */
	public function test_real_order_with_a_removed_line_keeps_it_as_zero_quantity(): void {
		$bag = static fn ( string $value, string $remote_id ): array => [
			'remote_id'     => $remote_id,
			'sku'           => null,
			'option1_name'  => '手提紙袋',
			'option1_value' => $value,
			'price'         => '2283',
			'stock'         => 5,
		];
		$this->make_variable_product( '900000051', [ $bag( '必要', 'v-need' ), $bag( '不要', 'v-none' ) ] );

		$raw_sale        = FixtureLoader::load( 'colorme', 'sale_zero_quantity_line_detail' )['sale'];
		$canonical_order = ( new OrderTransformer() )->transform( $raw_sale );
		$line_warnings   = static fn ( array $warnings ): array => array_values( array_filter( $warnings, static fn ( string $w ): bool => str_starts_with( $w, 'order_line_' ) ) );

		// dry-run（`validate()`）も`write()`と同じ`prepare()`を通るので、同じく明細の警告が付かない。
		$this->assertSame( [], $line_warnings( $this->make_writer()->validate( $canonical_order, null )->warnings ) );

		$result   = $this->make_writer()->write( $canonical_order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertCount( 2, $items );
		$this->assertSame( $this->mappings->find_local_id( 'colorme', 'variant', 'v-need' ), $items[0]->get_variation_id() );
		$this->assertSame( 0, $items[0]->get_quantity() );
		$this->assertSame( '0', $items[0]->get_subtotal() );
		$this->assertSame( '0', $items[0]->get_total() );
		$this->assertSame( '0', $items[0]->get_total_tax() );

		$this->assertSame( $this->mappings->find_local_id( 'colorme', 'variant', 'v-none' ), $items[1]->get_variation_id() );
		$this->assertSame( 1, $items[1]->get_quantity() );
		$this->assertSame( '2114', $items[1]->get_total() );
		$this->assertSame( '169', $items[1]->get_total_tax() );

		$this->assertSame( '3303.00', $wc_order->get_total() );
		$this->assertSame( [], $line_warnings( $result->warnings ) );
	}

	public function test_missing_line_item_quantity_falls_back_to_one_with_warning(): void {
		// 数量欠損を1個として黙って捏造すると実際の購入数と食い違う出荷指示になりうる
		// （CLAUDE.md参照）。行自体は注文履歴のため残しつつ、数量が不確かである旨を
		// 警告として可視化することを確認する。
		$order = $this->make_order(
			'3009',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'no-qty',
					'name'                => 'Mystery quantity',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => null,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 1, (int) $items[0]->get_quantity() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, 'no-qty' ), $result->warnings );
	}

	public function test_non_numeric_line_item_price_with_no_subtotal_fails_closed(): void {
		// `subtotal`欠損時のフォールバック計算に使う`price`自体を検証しないと、桁区切り付き
		// 文字列（`"1,200"`）が`(float)`キャストで`1.0`へ静かに切り詰められ、誤った金額
		// （数量2なら合計¥2）が無警告で確定してしまっていた。
		$order = $this->make_order(
			'3020',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'bad-price',
					'name'                => 'Malformed price',
					'price'               => '1,200',
					'unit_price_excl_tax' => null,
					'subtotal'            => null,
					'quantity'            => 2,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( '0', $items[0]->get_total() );
		// detailは金額の値自体ではなくremote_product_id（同メソッド内の他の警告・
		// F1-6の結果レポートがどの明細か特定できるようにする契約と揃える）。
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_AMOUNT_INVALID, 'bad-price' ), $result->warnings );
	}

	public function test_zero_or_negative_line_item_quantity_falls_back_to_one_with_warning(): void {
		// 0以下の数量をそのまま`set_quantity()`に渡すと負/ゼロ数量の明細行になり、
		// 注文の集計・返金計算が破綻しうるため、欠損時と同じフェイルクローズ扱いにする。
		$order = $this->make_order(
			'3014',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'negative-qty',
					'name'                => 'Negative quantity',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => -1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 1, (int) $items[0]->get_quantity() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, 'negative-qty' ), $result->warnings );
	}

	/**
	 * R3-0n: 数量0かつ明細合計0の行（ColorMe はキャンセルした受注の全明細と、一部を外した明細をこの形にする）は、
	 * 数量0・金額0のまま警告なしで取り込む。単価（`price`/`unit_price_excl_tax`）は残っていても使わないので、
	 * 税抜単価が無い（他ASP）・単価が読めない場合も税の分割や金額の警告を出さない。
	 *
	 * @dataProvider zero_line_provider
	 */
	public function test_zero_quantity_line_with_zero_subtotal_is_kept_without_warnings( mixed $quantity, mixed $subtotal, string $price, ?string $unit_price_excl_tax ): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Removed item' );
		$product->set_sku( 'REMOVED-1' );
		$product->update_meta_data( '_cbjp_platform', 'colorme' );
		$product->save();

		$order = $this->make_order(
			'3030',
			'cancelled',
			null,
			[
				[
					'sku'                 => 'REMOVED-1',
					'remote_product_id'   => 'removed',
					'name'                => 'Removed item',
					'price'               => $price,
					'unit_price_excl_tax' => $unit_price_excl_tax,
					'subtotal'            => $subtotal,
					'quantity'            => $quantity,
				],
			],
			[],
			[],
			[
				'total'        => '0',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 0, $items[0]->get_quantity() );
		$this->assertSame( '0', $items[0]->get_subtotal() );
		$this->assertSame( '0', $items[0]->get_total() );
		$this->assertSame( '0', $items[0]->get_total_tax() );
		$this->assertSame(
			[],
			array_values(
				array_filter(
					$result->warnings,
					static fn ( string $w ): bool => str_starts_with( $w, 'order_line_' ) || str_starts_with( $w, WarningCode::ORDER_TAX_SPLIT_UNAVAILABLE )
				)
			)
		);
	}

	/**
	 * @return array<string,array{0:mixed,1:mixed,2:string,3:?string}>
	 */
	public static function zero_line_provider(): array {
		return [
			'int zero'                       => [ 0, '0', '3602', '3335' ],
			'string zero'                    => [ '0', '0', '3602', '3335' ],
			'float zero'                     => [ 0.0, '0.00', '3602', '3335' ],
			'int zero subtotal'              => [ '0', 0, '3602', '3335' ],
			'no excl-tax unit price'         => [ 0, '0', '3602', null ],
			'unreadable unit price (unused)' => [ 0, '0', '1,200', '3335' ],
		];
	}

	/**
	 * 数量0でも「明細合計0」と確かめられない行、数量が0に切り捨てられただけの小数は、従来どおり数量1に
	 * フェイルクローズして警告する（R3-0n の数量0の扱いを広げすぎない）。
	 *
	 * @dataProvider not_a_zero_line_provider
	 */
	public function test_zero_quantity_without_a_zero_subtotal_still_falls_back_to_one( mixed $quantity, mixed $subtotal ): void {
		$order = $this->make_order(
			'3031',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'odd-qty',
					'name'                => 'Odd quantity',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => $subtotal,
					'quantity'            => $quantity,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 1, (int) $items[0]->get_quantity() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, 'odd-qty' ), $result->warnings );
	}

	/**
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	public static function not_a_zero_line_provider(): array {
		return [
			'zero with an amount'           => [ 0, '100' ],
			'zero without a subtotal'       => [ 0, null ],
			'zero with a non-numeric total' => [ 0, 'abc' ],
			'fraction truncated to zero'    => [ 0.5, '0' ],
			'string fraction'               => [ '0.4', '0' ],
			// PR #90 G1: float へ変換すると 0 にアンダーフローする値・指数表記・空白付きは数量0として受けない。
			'quantity underflowing to zero' => [ '1e-400', '0' ],
			'subtotal underflowing to zero' => [ 0, '1e-400' ],
			'zero in exponent notation'     => [ '0e0', '0' ],
			'zero with surrounding space'   => [ 0, ' 0' ],
		];
	}

	/**
	 * PR #90 G1/G2: ColorMe の`subtotal_price`が欠損・小数・指数表記の`product_num=0`の明細は、変換層が小計を`'0'`に
	 * 丸めなくなったので「数量0・金額0の明細」にはならず、従来どおり数量1＋`ORDER_LINE_QUANTITY_INVALID`になる（変換層→writer）。
	 *
	 * @dataProvider unreadable_colorme_subtotal_provider
	 */
	public function test_colorme_zero_quantity_line_without_a_readable_subtotal_is_not_a_zero_line( mixed $subtotal_price, bool $remove ): void {
		$raw_sale = FixtureLoader::load( 'colorme', 'sale_zero_quantity_line_detail' )['sale'];

		if ( $remove ) {
			unset( $raw_sale['details'][0]['subtotal_price'] );
		} else {
			$raw_sale['details'][0]['subtotal_price'] = $subtotal_price;
		}

		$result   = $this->make_writer()->write( ( new OrderTransformer() )->transform( $raw_sale ), null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( 1, $items[0]->get_quantity() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, '900000051' ), $result->warnings );
	}

	/**
	 * @return array<string,array{0:mixed,1:bool}>
	 */
	public static function unreadable_colorme_subtotal_provider(): array {
		return [
			'missing'           => [ null, true ],
			'fraction'          => [ 0.4, false ],
			'underflow to zero' => [ '1e-400', false ],
			// PR #90 G3: JSON の `1e-400` は json_decode で float(0) になる。
			'float zero'        => [ 0.0, false ],
		];
	}

	public function test_reduced_rate_tax_class_not_configured_falls_back_to_standard(): void {
		// ProductWriterと同じ理由: 未設定のtax_classをそのまま`set_tax_class()`に渡すと
		// `WC_Order_Item_Product`は`WC_Data_Exception`を投げる。WooCommerceは標準で
		// 'reduced-rate'を用意しているため、未設定ストアを模擬するため明示的に削除する。
		\WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		$order = $this->make_order(
			'3010',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'reduced-item',
					'name'                => 'Reduced rate item',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => 1,
					'tax_reduced'         => true,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( '', $items[0]->get_tax_class() );
		$this->assertContains( WarningCode::with_detail( WarningCode::TAX_CLASS_MISSING, 'reduced-rate' ), $result->warnings );
	}

	public function test_inconsistent_tax_split_clamps_to_zero_with_warning(): void {
		// `subtotal`（税込線合計の情報源）と`unit_price_excl_tax`×数量は別々のASPフィールドから
		// 独立に導出されるため、行割引・端数処理の都合で整合しないことがある。税抜側が
		// 税込側を上回ると減算結果が負になり、そのまま`set_taxes()`へ書き込むと注文の税合計が
		// 破綻するため、税額0へフェイルクローズし警告で可視化することを確認する。
		$order = $this->make_order(
			'3012',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'bad-split',
					'name'                => 'Inconsistent split',
					'price'               => '100',
					'unit_price_excl_tax' => '150',
					'subtotal'            => '100',
					'quantity'            => 1,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );
		$items    = array_values( $wc_order->get_items() );

		$this->assertSame( '0', $items[0]->get_total_tax() );
		$this->assertContains( WarningCode::ORDER_LINE_TAX_INCONSISTENT, $result->warnings );
	}

	public function test_customer_ref_pointing_to_deleted_user_is_treated_as_unresolved(): void {
		$user_id = wp_insert_user(
			[
				'user_login' => 'cust2',
				'user_email' => 'cust2@example.com',
				'user_pass'  => 'x',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', 'c-gone', $user_id );
		wp_delete_user( $user_id );

		$order    = $this->make_order( '3011', 'processing', 'c-gone' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 0, $wc_order->get_customer_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, 'c-gone' ), $result->warnings );
	}

	public function test_discount_point_meta_is_deleted_when_no_longer_present(): void {
		// ポイント利用等が取り消されてtotalsから値が消えた場合、更新のみで削除しないと
		// 古い金額のメタが残り、実際の割引内容と食い違ったまま残り続けてしまう。
		$with_point = $this->make_order(
			'3013',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'          => '1000',
				'tax'            => '0',
				'shipping_fee'   => '0',
				'discount'       => '0',
				'discount_point' => '500',
			]
		);
		$first      = $this->make_writer()->write( $with_point, null );
		$this->assertSame( '500', wc_get_order( $first->local_id )->get_meta( '_cbjp_discount_point' ) );

		$without_point = $this->make_order( '3013', 'processing' );
		$this->make_writer()->write( $without_point, $first->local_id );

		$this->assertSame( '', wc_get_order( $first->local_id )->get_meta( '_cbjp_discount_point' ) );
	}

	public function test_stale_existing_local_id_falls_back_to_create(): void {
		// mappingsが指す注文が手動削除等で既に存在しない場合を模擬する
		// （実在しない注文IDを直接existing_local_idとして渡す）。
		$order  = $this->make_order( '3006', 'processing' );
		$result = $this->make_writer()->write( $order, 999999 );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( 999999, $result->local_id );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $result->local_id ) );
	}

	public function test_unknown_status_falls_back_to_on_hold_with_warning(): void {
		$order    = $this->make_order( '3007', 'some-unknown-status' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 'on-hold', $wc_order->get_status() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_STATUS_UNKNOWN, 'some-unknown-status' ), $result->warnings );
	}

	/**
	 * `MappingCandidates::order_statuses()`（E2-1のマッピングUI）は`checkout-draft`を候補から
	 * 除外しているが、UIを経由しないREST直PUTや、除外前に保存済みの`status_map`から紛れ込む
	 * 経路は候補一覧の除外だけでは防げない。この状態へ受注を書き込むと
	 * `woocommerce_cleanup_draft_orders`（日次cron）が24時間後に受注を完全削除するため、
	 * 書込み側（`apply_status()`）でも同じ値をフェイルクローズすることを確認する（G1指摘）。
	 */
	public function test_status_mapped_to_checkout_draft_falls_back_to_on_hold_with_warning(): void {
		update_option( 'cbjp_settings_colorme', [ 'status_map' => [ 'pending' => 'checkout-draft' ] ] );

		$order    = $this->make_order( '3007b', 'pending' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 'on-hold', $wc_order->get_status() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_STATUS_UNKNOWN, 'checkout-draft' ), $result->warnings );

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_tax_total_incomplete_source_warns(): void {
		$order = $this->make_order(
			'3008',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '80',
				'shipping_fee' => '0',
				'discount'     => '0',
				'tax_source'   => 'sale.tax_incomplete_excludes_shipping_tax',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::ORDER_TAX_TOTAL_INCOMPLETE, $result->warnings );
	}

	public function test_totals_are_set_from_asp_values_without_recalculation(): void {
		$order = $this->make_order(
			'1004',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '9999',
				'tax'          => '111',
				'shipping_fee' => '300',
				'discount'     => '50',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( '9999.00', $wc_order->get_total() );
		$this->assertSame( '300', $wc_order->get_shipping_total() );
		$this->assertSame( '50', $wc_order->get_discount_total() );
		$this->assertSame( '111', $wc_order->get_total_tax() );
	}

	public function test_negative_total_is_rejected_before_creating_the_order(): void {
		// `合計はASP側の値をそのまま設定`する契約上、合計自体が壊れていると実際に決済
		// された金額と一致しない注文になる金銭的リスクがある。`WC_Order`に一切触れず
		// 注文全体を見送ることを確認する（受注そのものが作られない）。
		$order = $this->make_order(
			'1015',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '-100',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, 'total' ), $result->warnings );
		$this->assertCount( 0, wc_get_orders( [ 'limit' => -1 ] ) );
	}

	public function test_missing_total_key_is_rejected_before_creating_the_order(): void {
		// `total`キー自体が欠損している場合、`apply_totals()`は`Value::string(...) ?? '0'`で
		// 無警告のまま0円にフォールバックしてしまう。他の3キー（discount/shipping_fee/tax）は
		// 正当に欠損しうるが`total`だけは必須であることを確認する。
		$order = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[],
			[],
			[
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, 'total' ), $result->warnings );
		$this->assertCount( 0, wc_get_orders( [ 'limit' => -1 ] ) );
	}

	public function test_date_paid_is_cleared_when_paid_flag_reverts_to_false(): void {
		// 更新のみで削除しないと、再実行時に返金・注文取消等でASP側のpaidフラグが
		// falseへ戻っても古いdate_paidが残り続け、WooCommerce側の会計・エクスポートで
		// 支払済みのまま扱われてしまう。
		$paid  = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => true ]
		);
		$first = $this->make_writer()->write( $paid, null );
		$this->assertNotNull( wc_get_order( $first->local_id )->get_date_paid() );

		$unpaid = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => false ]
		);
		$this->make_writer()->write( $unpaid, $first->local_id );

		$this->assertNull( wc_get_order( $first->local_id )->get_date_paid() );
		// `paid`は`date_paid`として既に反映済みのため、汎用extras passthrough
		// （`ExtrasMeta::apply()`）には渡らない。渡ると`false`が空文字列として書き込まれ、
		// 未設定と区別できなくなる。
		$this->assertSame( '', wc_get_order( $first->local_id )->get_meta( '_cbjp_paid' ) );
	}

	public function test_date_paid_is_preserved_when_paid_flag_is_absent(): void {
		// `paid`キーが欠損/null（未対応ASP、または値を解釈できなかった場合）は
		// 「未払いに変わった」という明示的なシグナルではないため、falseと同一視して
		// 既存のdate_paidを消してはいけない。
		$paid  = $this->make_order(
			'1017',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => true ]
		);
		$first = $this->make_writer()->write( $paid, null );
		$this->assertNotNull( wc_get_order( $first->local_id )->get_date_paid() );

		$resynced = $this->make_order(
			'1017',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[]
		);
		$this->make_writer()->write( $resynced, $first->local_id );

		$this->assertNotNull( wc_get_order( $first->local_id )->get_date_paid() );
	}

	public function test_new_completed_order_with_ambiguous_paid_flag_does_not_get_migration_run_time_as_paid_date(): void {
		// `apply_status()`（直前に呼ばれる）の`WC_Order::set_status()`は、pending→completedの
		// ステータス遷移時に`maybe_set_date_paid()`/`maybe_set_date_completed()`を発火させ、
		// `date_paid`へ移行実行時刻（`time()`）を自動的に焼き込む（WooCommerce本体の仕様）。
		// `paid`フラグが欠損/nullの新規注文でこれを放置すると、実際にはASP側で何年も前に
		// 支払われたか不明な注文が「移行を実行した今日」支払われたことになってしまう。
		// 一方`date_completed`はステータス（completed）自体から導かれる事実であり、
		// `paid`の有無に関わらずASP側の受注日時（`placed_at`）で確定してよい。
		$order  = $this->make_order(
			'1021',
			'completed',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[]
		);
		$result = $this->make_writer()->write( $order, null );

		$wc_order = wc_get_order( $result->local_id );
		$this->assertNull( $wc_order->get_date_paid() );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $wc_order->get_date_completed()->date( 'c' ) );
	}

	public function test_completed_order_with_explicit_paid_flag_gets_placed_at_as_completed_and_paid_dates(): void {
		$order  = $this->make_order(
			'1022',
			'completed',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[ 'paid' => true ]
		);
		$result = $this->make_writer()->write( $order, null );

		$wc_order = wc_get_order( $result->local_id );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $wc_order->get_date_paid()->date( 'c' ) );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $wc_order->get_date_completed()->date( 'c' ) );
	}

	/**
	 * @dataProvider status_provider
	 */
	public function test_status_mapping( string $canonical_status, string $expected_woo_status ): void {
		$order    = $this->make_order( '1005', $canonical_status );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( $expected_woo_status, $wc_order->get_status() );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function status_provider(): array {
		return [
			'pending'    => [ 'pending', 'pending' ],
			'processing' => [ 'processing', 'processing' ],
			'completed'  => [ 'completed', 'completed' ],
			'cancelled'  => [ 'cancelled', 'cancelled' ],
		];
	}

	public function test_stock_reduced_flag_reflects_status(): void {
		$processing = $this->make_writer()->write( $this->make_order( '1006', 'processing' ), null );
		$this->assertTrue( wc_get_order( $processing->local_id )->get_data_store()->get_stock_reduced( wc_get_order( $processing->local_id ) ) );

		$pending = $this->make_writer()->write( $this->make_order( '1007', 'pending' ), null );
		$this->assertFalse( wc_get_order( $pending->local_id )->get_data_store()->get_stock_reduced( wc_get_order( $pending->local_id ) ) );
	}

	public function test_sales_and_download_permission_flags_reflect_status(): void {
		// pending/on-hold等の未処理注文にまで無条件でtrueを立てると、この注文が後に本当に
		// processing/completedへ遷移した際、WooCommerce標準フック
		// （`wc_update_total_sales_counts()`/`wc_update_coupon_usage_counts()`/
		// ダウンロード権限付与）が「既に処理済み」と誤認して発火しなくなる。
		$completed       = $this->make_writer()->write( $this->make_order( '1018', 'completed' ), null );
		$completed_order = wc_get_order( $completed->local_id );
		$this->assertTrue( $completed_order->get_recorded_sales() );
		$this->assertTrue( $completed_order->get_recorded_coupon_usage_counts() );
		$this->assertTrue( $completed_order->get_download_permissions_granted() );

		$pending       = $this->make_writer()->write( $this->make_order( '1019', 'pending' ), null );
		$pending_order = wc_get_order( $pending->local_id );
		$this->assertFalse( $pending_order->get_recorded_sales() );
		$this->assertFalse( $pending_order->get_recorded_coupon_usage_counts() );
		$this->assertFalse( $pending_order->get_download_permissions_granted() );
	}

	public function test_customer_resolved_via_mapping(): void {
		$user_id = wp_insert_user(
			[
				'user_login' => 'cust',
				'user_email' => 'cust@example.com',
				'user_pass'  => 'x',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', 'c1', $user_id );

		$order    = $this->make_order( '1008', 'processing', 'c1' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( $user_id, $wc_order->get_customer_id() );
	}

	public function test_customer_mapping_pointing_to_protected_role_account_is_treated_as_unresolved(): void {
		// ASP側顧客のメールが店舗の管理者・スタッフアカウントと偶然一致した場合、
		// `CustomerWriter::write()`はプロフィールを上書きしないままmappingだけを維持する
		// （`CustomerWriter::PROTECTED_ROLES`参照）。このmappingを無条件に信用すると、
		// 見ず知らずのASP顧客の注文が管理者アカウントに紐付いてしまうため、注文側でも
		// 再検証してゲスト注文として扱うことを確認する。
		$admin_id = wp_insert_user(
			[
				'user_login' => 'store-admin',
				'user_email' => 'admin@example.com',
				'user_pass'  => 'x',
				'role'       => 'administrator',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', 'c-admin', $admin_id );

		$order    = $this->make_order( '1020', 'processing', 'c-admin' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 0, $wc_order->get_customer_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, 'c-admin' ), $result->warnings );
		// 管理者アカウントとの衝突は解決される見込みが無い終端状態のため（再試行しても
		// 保護は解除されない）、`ORDER_CUSTOMER_UNRESOLVED`と異なりfully_resolvedはtrueのまま
		// （falseにすると、解決される可能性が無いのに毎回無駄に再処理されてしまう）。
		$this->assertTrue( $result->fully_resolved );
	}

	public function test_unresolved_customer_ref_warns_and_is_guest(): void {
		$order    = $this->make_order( '1009', 'processing', 'missing-customer' );
		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 0, $wc_order->get_customer_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, 'missing-customer' ), $result->warnings );
		// `Importer::process_items()`はfully_resolved=falseの結果に対してchecksumを
		// キャッシュしない（顧客参照が後から解決可能になった場合に再試行するため）。
		$this->assertFalse( $result->fully_resolved );
	}

	public function test_residual_and_split_tax_warnings(): void {
		$order = $this->make_order(
			'1010',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
				'residual'     => '5',
				'tax_source'   => 'unavailable_for_split_order',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTAL_RESIDUAL, '5' ), $result->warnings );
		$this->assertContains( WarningCode::ORDER_SPLIT_TAX_UNKNOWN, $result->warnings );
	}

	public function test_re_run_does_not_duplicate_line_items(): void {
		$order = $this->make_order(
			'1011',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'x',
					'name'                => 'X',
					'price'               => '100',
					'unit_price_excl_tax' => '100',
					'subtotal'            => '100',
					'quantity'            => 1,
				],
			]
		);

		$first  = $this->make_writer()->write( $order, null );
		$second = $this->make_writer()->write( $order, $first->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$wc_order = wc_get_order( $second->local_id );
		$this->assertCount( 1, $wc_order->get_items() );
	}

	public function test_extras_not_on_the_hardcoded_whitelist_are_still_persisted(): void {
		// `other_discount_name`/`product_tax`はapply_meta()の旧ホワイトリストに含まれておらず
		// 静かに欠落していたキー。ExtrasMeta経由になったことで、明示的に扱っていないASP固有の
		// extrasキーも往復移行のために保存されることを検証する。
		$order = $this->make_order(
			'2001',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[
				'other_discount_name' => '会員割引',
				'shop_coupon'         => [
					'code'   => 'SUMMER',
					'amount' => 100,
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( '会員割引', $wc_order->get_meta( '_cbjp_other_discount_name' ) );
		$this->assertSame( '{"code":"SUMMER","amount":100}', $wc_order->get_meta( '_cbjp_shop_coupon' ) );
	}

	public function test_explicit_meta_fields_win_over_colliding_extras_keys(): void {
		// `cbjp/adapters/register`経由の外部アダプタは信頼境界のため、extrasに
		// '_cbjp_platform'等の予約済みメタと同名のキー（'platform'等）が偶然/意図的に
		// 含まれていても、`ExtrasMeta::apply()`より後に明示フィールドを設定することで
		// 正しい値が優先されることを確認する（他writer=ProductWriter/TermWriter/
		// CouponWriterと同じ順序規約）。
		$order = $this->make_order(
			'2003',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[
				'platform'            => 'malicious-override',
				'remote_order_number' => 'malicious-override',
				'remote_order_id'     => 'malicious-override',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( 'colorme', $wc_order->get_meta( '_cbjp_platform' ) );
		$this->assertSame( '2003', $wc_order->get_meta( '_cbjp_remote_order_number' ) );
		$this->assertSame( '2003', $wc_order->get_meta( '_cbjp_remote_order_id' ) );
	}

	public function test_customer_snapshot_extras_key_is_not_persisted_as_meta(): void {
		$order = $this->make_order(
			'2002',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			[
				'customer_snapshot' => [
					'name'  => 'Guest',
					'email' => 'guest@example.com',
				],
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( '', $wc_order->get_meta( '_cbjp_customer_snapshot' ) );
	}

	public function test_unmapped_payment_and_shipping_methods_warn(): void {
		$order = $this->make_order(
			'1012',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			],
			[
				'method_id'   => 'pay-1',
				'method_name' => '銀行振込',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, 'pay-1' ), $result->warnings );
		$this->assertContains( WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, 'ship-1' ), $result->warnings );

		$wc_order = wc_get_order( $result->local_id );
		$this->assertSame( '銀行振込', $wc_order->get_payment_method_title() );
	}

	/**
	 * R3-0m: 決済/配送方法が未マッピングのまま取り込んだ受注は checksum をキャッシュさせない（`fully_resolved`=false）。
	 * キャッシュすると、後から Mappings タブで設定しても `Importer` の checksum 一致で飛ばされ、空の決済/配送方法のまま
	 * 直らない（再 dry-run も検証を飛ばして警告だけが消える）。設定後に同じ受注を書き直すと付け直され、解決済みになる。
	 */
	public function test_unmapped_methods_are_not_fully_resolved_and_are_fixed_on_the_next_write(): void {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'R3-0m Zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );

		$order = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			],
			[
				'method_id'   => 'pay-1',
				'method_name' => '銀行振込',
			]
		);

		$first = $this->make_writer()->write( $order, null );

		$this->assertFalse( $first->fully_resolved );
		$this->assertSame( '', wc_get_order( $first->local_id )->get_payment_method() );

		update_option(
			'cbjp_settings_colorme',
			[
				'payment_map'  => [ 'pay-1' => 'bacs' ],
				'shipping_map' => [ 'ship-1' => "flat_rate:{$instance_id}" ],
			]
		);

		$second   = $this->make_writer()->write( $order, $first->local_id );
		$wc_order = wc_get_order( $second->local_id );

		$this->assertSame( $first->local_id, $second->local_id );
		$this->assertSame( WriteResult::OPERATION_UPDATED, $second->operation );
		$this->assertTrue( $second->fully_resolved, wp_json_encode( $second->warnings ) );
		$this->assertSame( 'bacs', $wc_order->get_payment_method() );
		$shipping_items = array_values( $wc_order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping_items );
		$this->assertSame( 'flat_rate', $shipping_items[0]->get_method_id() );

		delete_option( 'cbjp_settings_colorme' );
	}

	/**
	 * 決済だけ未マッピングでも checksum をキャッシュしない（配送だけの場合も同じ判定。`WarningCode::indicates_unresolved_reference()`）。
	 */
	public function test_payment_unmapped_alone_is_not_fully_resolved(): void {
		$order = $this->make_order(
			'1017',
			'processing',
			null,
			[],
			[],
			[
				'method_id'   => 'pay-1',
				'method_name' => '銀行振込',
			]
		);

		$result = $this->make_writer()->write( $order, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, 'pay-1' ), $result->warnings );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::SHIPPING_METHOD_UNMAPPED ) )
		);
		$this->assertFalse( $result->fully_resolved );
	}

	public function test_mapped_payment_method_sets_woo_gateway_id_not_the_asp_raw_id(): void {
		update_option( 'cbjp_settings_colorme', [ 'payment_map' => [ 'pay-1' => 'bacs' ] ] );

		$order = $this->make_order(
			'1013',
			'processing',
			null,
			[],
			[],
			[
				'method_id'   => 'pay-1',
				'method_name' => '銀行振込',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		// `payment_method`（決済ゲートウェイID）にはマッピング済みのWoo ID（'bacs'）が入り、
		// ASP側の生ID（'pay-1'）がそのまま入ってはならない。
		$this->assertSame( 'bacs', $wc_order->get_payment_method() );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::PAYMENT_METHOD_UNMAPPED ) )
		);

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_mapped_payment_method_to_unregistered_gateway_is_treated_as_unmapped(): void {
		// マッピング先のゲートウェイIDがプラグイン削除・無効化・単なる設定ミス等で
		// 現在実在しない場合、`payment_gateway_title()`はID自体をフォールバック表示する
		// だけで警告なく実在しないゲートウェイが注文へ書き込まれてしまっていた。
		// 未マッピングと同じ`PAYMENT_METHOD_UNMAPPED`警告に倒すことを確認する
		// （Codexレビュー指摘）。
		update_option( 'cbjp_settings_colorme', [ 'payment_map' => [ 'pay-1' => 'no-such-gateway' ] ] );

		$order = $this->make_order(
			'1019',
			'processing',
			null,
			[],
			[],
			[
				'method_id'   => 'pay-1',
				'method_name' => '銀行振込',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$this->assertSame( '', $wc_order->get_payment_method() );
		$this->assertContains( WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, 'pay-1' ), $result->warnings );

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_mapped_shipping_method_sets_woo_method_id_not_the_asp_raw_id(): void {
		update_option( 'cbjp_settings_colorme', [ 'shipping_map' => [ 'ship-1' => 'flat_rate' ] ] );

		$order = $this->make_order(
			'1014',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$shipping_items = array_values( $wc_order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping_items );
		// shipping item の method_id にはマッピング済みのWoo ID（'flat_rate'）が入り、
		// ASP側の生ID（'ship-1'）がそのまま入ってはならない。
		$this->assertSame( 'flat_rate', $shipping_items[0]->get_method_id() );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::SHIPPING_METHOD_UNMAPPED ) )
		);

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_mapped_shipping_method_with_zone_instance_id_splits_method_and_instance(): void {
		// マッピング値がゾーンインスタンスID付き（`flat_rate:{instance_id}`）の場合、
		// `method_id`（方式そのもの）と`instance_id`（ゾーン内のインスタンス番号）を
		// 別プロパティとして設定することを確認する（Codexレビュー指摘）。両者を1つの
		// 複合文字列のまま`set_method_id()`へ渡すと`get_method_id()`が実在しない方式ID
		// （`flat_rate:5`）を返してしまい、配送方法IDで判定する他のコード
		// （レポート・拡張機能等）と噛み合わない。実在するゾーンインスタンスを使う
		// （実在しないインスタンスは`test_stale_shipping_instance_mapping_is_treated_as_unmapped`
		// で別途検証する）。
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Test Zone' );
		$zone->save();
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		$this->assertIsInt( $instance_id );

		update_option( 'cbjp_settings_colorme', [ 'shipping_map' => [ 'ship-1' => "flat_rate:{$instance_id}" ] ] );

		$order = $this->make_order(
			'1015',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$shipping_items = array_values( $wc_order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping_items );
		$this->assertSame( 'flat_rate', $shipping_items[0]->get_method_id() );
		$this->assertSame( (string) $instance_id, $shipping_items[0]->get_instance_id() );
		$this->assertEmpty(
			array_filter( $result->warnings, static fn ( string $w ): bool => str_starts_with( $w, WarningCode::SHIPPING_METHOD_UNMAPPED ) )
		);

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_stale_shipping_instance_mapping_is_treated_as_unmapped(): void {
		// マッピング値の形式自体は正しくても（`flat_rate:999999`）、ゾーンから削除された・
		// 一度も存在しなかったインスタンスIDを指す場合、それを「マッピング済み」として
		// 扱うと消えた配送設定へ黙って注文が紐付いてしまう。未マッピングと同じ
		// `SHIPPING_METHOD_UNMAPPED`警告に倒すことを確認する（Codexレビュー指摘）。
		update_option( 'cbjp_settings_colorme', [ 'shipping_map' => [ 'ship-1' => 'flat_rate:999999' ] ] );

		$order = $this->make_order(
			'1018',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$shipping_items = array_values( $wc_order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping_items );
		$this->assertSame( '', $shipping_items[0]->get_method_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, 'ship-1' ), $result->warnings );

		delete_option( 'cbjp_settings_colorme' );
	}

	public function test_malformed_shipping_instance_mapping_is_treated_as_unmapped(): void {
		// `flat_rate:abc`（インスタンス部が非数値）のような壊れたマッピング値を、
		// 黙って`flat_rate`+インスタンス0のような「それらしい」組へ解決してしまうと、
		// 設定ミスが警告なく別のインスタンスとして書き込まれてしまう。未マッピングと
		// 同じ`SHIPPING_METHOD_UNMAPPED`警告に倒すことを確認する（Codexレビュー指摘）。
		update_option( 'cbjp_settings_colorme', [ 'shipping_map' => [ 'ship-1' => 'flat_rate:abc' ] ] );

		$order = $this->make_order(
			'1016',
			'processing',
			null,
			[],
			[
				'method_id'   => 'ship-1',
				'method_name' => '宅急便',
			]
		);

		$result   = $this->make_writer()->write( $order, null );
		$wc_order = wc_get_order( $result->local_id );

		$shipping_items = array_values( $wc_order->get_items( 'shipping' ) );
		$this->assertCount( 1, $shipping_items );
		$this->assertSame( '', $shipping_items[0]->get_method_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, 'ship-1' ), $result->warnings );

		delete_option( 'cbjp_settings_colorme' );
	}

	// --- validate()（F1-6 dry-run）: `wc_create_order()`は呼んだ瞬間にDBへ注文行を作るため、
	// `validate()`は未保存の`WC_Order`（`new WC_Order()`）へ組み立てる設計になっている。
	// ここでは特に「dry-runが実際に注文を作っていないこと」を`wc_get_orders()`で確認する。

	public function test_validate_new_order_matches_write_without_creating_an_order(): void {
		$before_count = count(
			wc_get_orders(
				[
					'return' => 'ids',
					'limit'  => -1,
				]
			)
		);

		$order      = $this->make_order( '5001', 'processing' );
		$validation = $this->make_writer()->validate( $order, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $validation->operation );
		// テスト環境のデフォルト通貨がJPYでないため`CURRENCY_MISMATCH`が乗る。テストの主眼
		// （このシナリオ固有の警告が出ないこと）とは無関係なので除外して比較する。
		$this->assertSame( [], array_diff( $validation->warnings, [ WarningCode::CURRENCY_MISMATCH ] ) );
		$this->assertSame(
			$before_count,
			count(
				wc_get_orders(
					[
						'return' => 'ids',
						'limit'  => -1,
					]
				)
			)
		);
	}

	public function test_validate_negative_total_is_rejected_without_creating_an_order(): void {
		$before_count = count(
			wc_get_orders(
				[
					'return' => 'ids',
					'limit'  => -1,
				]
			)
		);

		$order = $this->make_order(
			'5002',
			'processing',
			null,
			[],
			[],
			[],
			[
				'total'        => '-100',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			]
		);

		$validation = $this->make_writer()->validate( $order, null );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $validation->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, 'total' ), $validation->warnings );
		$this->assertSame(
			$before_count,
			count(
				wc_get_orders(
					[
						'return' => 'ids',
						'limit'  => -1,
					]
				)
			)
		);
	}

	public function test_validate_unknown_status_matches_write_without_creating_an_order(): void {
		$before_count = count(
			wc_get_orders(
				[
					'return' => 'ids',
					'limit'  => -1,
				]
			)
		);

		$order      = $this->make_order( '5003', 'some-unknown-status' );
		$validation = $this->make_writer()->validate( $order, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $validation->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_STATUS_UNKNOWN, 'some-unknown-status' ), $validation->warnings );
		$this->assertSame(
			$before_count,
			count(
				wc_get_orders(
					[
						'return' => 'ids',
						'limit'  => -1,
					]
				)
			)
		);
	}

	public function test_validate_unmapped_payment_method_matches_write(): void {
		$order = $this->make_order(
			'5004',
			'processing',
			null,
			[],
			[],
			[
				'method_id'   => 'unmapped-pay',
				'method_name' => 'Unmapped',
			]
		);

		$validation = $this->make_writer()->validate( $order, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, 'unmapped-pay' ), $validation->warnings );
	}

	public function test_validate_resolves_line_item_by_sku_without_creating_an_order(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Widget' );
		$product->set_sku( 'WIDGET-VALIDATE' );
		$product->update_meta_data( '_cbjp_platform', 'colorme' );
		$product->save();

		$before_count = count(
			wc_get_orders(
				[
					'return' => 'ids',
					'limit'  => -1,
				]
			)
		);

		$order = $this->make_order(
			'5005',
			'processing',
			null,
			[
				[
					'sku'                 => 'WIDGET-VALIDATE',
					'remote_product_id'   => 'p1',
					'name'                => 'Widget (at purchase)',
					'price'               => '1100',
					'unit_price_excl_tax' => '1000',
					'subtotal'            => '1100',
					'quantity'            => 1,
				],
			]
		);

		$validation = $this->make_writer()->validate( $order, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $validation->operation );
		// テスト環境のデフォルト通貨がJPYでないため`CURRENCY_MISMATCH`が乗る。テストの主眼
		// （このシナリオ固有の警告が出ないこと）とは無関係なので除外して比較する。
		$this->assertSame( [], array_diff( $validation->warnings, [ WarningCode::CURRENCY_MISMATCH ] ) );
		$this->assertSame(
			$before_count,
			count(
				wc_get_orders(
					[
						'return' => 'ids',
						'limit'  => -1,
					]
				)
			)
		);
	}

	public function test_validate_existing_order_is_updated_without_persisting_changes(): void {
		$order = $this->make_order( '5006', 'processing' );
		$first = $this->make_writer()->write( $order, null );

		$resynced   = $this->make_order( '5006', 'completed' );
		$validation = $this->make_writer()->validate( $resynced, $first->local_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $validation->operation );

		// 何も永続化していない（ステータスは元のままprocessing）。
		$this->assertSame( 'processing', wc_get_order( $first->local_id )->get_status() );
	}

	/**
	 * issue #91: 新規の受注は最終ステータスで1回だけ保存し、状態変化（`woocommerce_order_status_*`）を発火させない。
	 * 実店舗では、支払い待ちで作ってから完了等へ変えていたため、請求書プラグイン（PDF・メール）や決済プラグインの
	 * 完了時処理が、移行した過去の受注のすべてで動いていた。
	 *
	 * @dataProvider final_status_provider
	 */
	public function test_new_order_is_created_in_its_final_status_without_status_transition_hooks( string $status ): void {
		$fired = [];

		foreach ( [ "woocommerce_order_status_{$status}", "woocommerce_order_status_pending_to_{$status}", 'woocommerce_order_status_pending', 'woocommerce_order_status_changed', 'woocommerce_new_order' ] as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook, &$fired ): void {
					$fired[] = $hook;
				}
			);
		}

		$result = $this->make_writer()->write( $this->make_order( '9101', $status ), null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( $status, wc_get_order( $result->local_id )->get_status() );
		$this->assertSame( [ 'woocommerce_new_order' ], $fired );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function final_status_provider(): array {
		return [
			'completed'  => [ 'completed' ],
			'processing' => [ 'processing' ],
			'cancelled'  => [ 'cancelled' ],
			'pending'    => [ 'pending' ],
		];
	}

	/**
	 * issue #91: 完了時の処理で商品の無い明細に例外を投げるプラグイン（実店舗の PDF Invoice Japan は
	 * `wc_get_product()` の `false` に `get_tax_status()` を呼んでいた）があっても、状態変化を起こさないので、
	 * ColorMe で削除済みの商品の明細を持つ受注を取り込める。
	 */
	public function test_order_with_a_product_less_line_is_imported_despite_a_throwing_completed_hook(): void {
		add_action(
			'woocommerce_order_status_completed',
			static function ( $order_id ): void {
				foreach ( wc_get_order( $order_id )->get_items() as $order_item ) {
					if ( $order_item instanceof WC_Order_Item_Product && 0 === $order_item->get_product_id() ) {
						throw new \Error( 'Call to a member function get_tax_status() on bool' );
					}
				}
			}
		);

		$order = $this->make_order(
			'9102',
			'completed',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'gone-91',
					'name'                => 'Deleted product',
					'price'               => '500',
					'unit_price_excl_tax' => '500',
					'subtotal'            => '500',
					'quantity'            => 1,
				],
			]
		);

		$result = $this->make_writer()->write( $order, null );
		$items  = array_values( wc_get_order( $result->local_id )->get_items() );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( 0, $items[0]->get_product_id() );
		$this->assertSame( 'completed', wc_get_order( $result->local_id )->get_status() );
	}

	/**
	 * issue #91: 保存の途中で他プラグインが `Error` を投げたときは受注を残さず、WooCommerce の受注件数キャッシュ
	 * （`OrderCountCacheService`）も DB の件数と一致したまま。`WC_Abstract_Order::save()` は `Exception` を握りつぶして
	 * ログに残すだけなので、外へ出るのは実店舗と同じ `Error`（`Throwable` だが `Exception` ではない）だけ。以前は支払い待ちで作った受注をメモリ上で完了に
	 * 変えてから削除していたため、件数が「支払い待ち +1・完了 -1」にずれ、一覧の「支払い待ち」が空なのに件数だけ出ていた。
	 */
	public function test_failed_new_order_leaves_no_order_and_keeps_the_order_counts(): void {
		$counts      = static fn (): array => [
			'pending'   => wc_orders_count( 'pending' ),
			'completed' => wc_orders_count( 'completed' ),
		];
		$count_cache = wc_get_container()->get( OrderCountCacheService::class );
		$count_cache->refresh_cache( 'shop_order' );
		$before_counts = $counts();
		$before_ids    = wc_get_orders(
			[
				'return' => 'ids',
				'limit'  => -1,
				'status' => 'any',
			]
		);

		add_action(
			'woocommerce_new_order_item',
			static function (): void {
				throw new \Error( 'simulated third-party failure' );
			}
		);

		try {
			$this->make_writer()->write(
				$this->make_order(
					'9103',
					'completed',
					null,
					[
						[
							'sku'                 => null,
							'remote_product_id'   => 'p-91',
							'name'                => 'Item',
							'price'               => '500',
							'unit_price_excl_tax' => '500',
							'subtotal'            => '500',
							'quantity'            => 1,
						],
					]
				),
				null
			);
			$this->fail( 'write() should rethrow the third-party failure.' );
		} catch ( \Error $error ) {
			$this->assertSame( 'simulated third-party failure', $error->getMessage() );
		}

		$this->assertSame(
			$before_ids,
			wc_get_orders(
				[
					'return' => 'ids',
					'limit'  => -1,
					'status' => 'any',
				]
			)
		);
		$this->assertSame( $before_counts, $counts() );
	}

	/**
	 * issue #91: 状態変化を起こさないため、WooCommerce 本体が完了時に呼んでいた `wc_paying_customer()`
	 * （顧客に購入実績の印）を新規の completed の受注でだけ明示的に呼ぶ（他の本体の完了時処理はフラグで元々 no-op）。
	 *
	 * @dataProvider paying_customer_provider
	 */
	public function test_new_order_marks_the_customer_as_paying_only_when_completed( string $status, bool $expected ): void {
		$user_id = wp_insert_user(
			[
				'user_login' => "paying-{$status}",
				'user_email' => "paying-{$status}@example.com",
				'user_pass'  => 'x',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', "c-paying-{$status}", $user_id );

		$this->make_writer()->write( $this->make_order( '9104', $status, "c-paying-{$status}" ), null );

		$this->assertSame( $expected, ( new WC_Customer( $user_id ) )->get_is_paying_customer() );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public static function paying_customer_provider(): array {
		return [
			'completed'  => [ 'completed', true ],
			'processing' => [ 'processing', false ],
		];
	}

	/**
	 * issue #91 の範囲外（ユーザー判断）: 既存受注の更新でステータスが変わる場合は、従来どおり状態変化として
	 * 保存される（他プラグインのフックも動く）。backlog `fix-91/update-status-hooks`。
	 */
	public function test_updating_an_existing_order_to_a_new_status_still_records_the_transition(): void {
		$first = $this->make_writer()->write( $this->make_order( '9105', 'processing' ), null );
		$fired = 0;

		add_action(
			'woocommerce_order_status_processing_to_completed',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$this->make_writer()->write( $this->make_order( '9105', 'completed' ), $first->local_id );

		$this->assertSame( 1, $fired );
		$this->assertSame( 'completed', wc_get_order( $first->local_id )->get_status() );
	}

	/**
	 * PR R1-S1: 即時取込みモードの Analytics は `woocommerce_update_order` か `woocommerce_schedule_import` でしか取込みを
	 * 予約しない。新規作成は1回だけの保存（作成）で`woocommerce_update_order`を起こさないので、明示的に予約する。
	 */
	public function test_new_order_schedules_the_analytics_import(): void {
		$scheduled = [];

		add_action(
			'woocommerce_schedule_import',
			static function ( $order_id ) use ( &$scheduled ): void {
				$scheduled[] = $order_id;
			}
		);

		$result = $this->make_writer()->write( $this->make_order( '9106', 'completed' ), null );

		$this->assertSame( [ $result->local_id ], $scheduled );
	}

	/**
	 * PR R1-S2: 作成後の後処理（購入実績の印＝顧客の保存）で他プラグインが失敗しても、受注は作成済みのまま返す。
	 * 消すと mapping が書かれず、次回に同じ受注を重複作成する（無料版の上限にも数えられない）。
	 */
	public function test_a_failing_follow_up_step_keeps_the_created_order(): void {
		$user_id = wp_insert_user(
			[
				'user_login' => 'paying-fails',
				'user_email' => 'paying-fails@example.com',
				'user_pass'  => 'x',
			]
		);
		$this->seed_mapping( 'colorme', 'customer', 'c-paying-fails', $user_id );

		add_action(
			'woocommerce_update_customer',
			static function (): void {
				throw new \Error( 'simulated third-party failure on customer save' );
			}
		);

		$result = $this->make_writer()->write( $this->make_order( '9107', 'completed', 'c-paying-fails' ), null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( 0, $result->local_id );
		$this->assertInstanceOf( \WC_Order::class, wc_get_order( $result->local_id ) );
	}

	/**
	 * PR R1-S6: mapping が削除済みの受注を指す（stale ID）ときも、新規作成として状態変化なしで作り直す。
	 */
	public function test_stale_mapping_is_recreated_without_status_transition_hooks(): void {
		$first = $this->make_writer()->write( $this->make_order( '9108', 'completed' ), null );
		wc_get_order( $first->local_id )->delete( true );
		$fired = [];

		foreach ( [ 'woocommerce_order_status_completed', 'woocommerce_order_status_changed' ] as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook, &$fired ): void {
					$fired[] = $hook;
				}
			);
		}

		$result = $this->make_writer()->write( $this->make_order( '9108', 'completed' ), $first->local_id );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( $first->local_id, $result->local_id );
		$this->assertSame( [], $fired );
	}

	/**
	 * PR R1-S5: `WC_Abstract_Order::save()` は保存前のフックの `Exception` を握りつぶし、受注を作れなければ 0 を返す。
	 * 何も作られていないので、例外ではなく `ORDER_CREATE_FAILED` で見送る。
	 */
	public function test_order_that_woocommerce_could_not_create_is_skipped_with_a_warning(): void {
		$before_ids = wc_get_orders(
			[
				'return' => 'ids',
				'limit'  => -1,
				'status' => 'any',
			]
		);

		add_action(
			'woocommerce_before_order_object_save',
			static function ( $order ): void {
				if ( 0 === $order->get_id() ) {
					throw new \Exception( 'simulated third-party rejection' );
				}
			}
		);

		$result = $this->make_writer()->write( $this->make_order( '9109', 'completed' ), null );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( 0, $result->local_id );
		$this->assertSame( [ WarningCode::ORDER_CREATE_FAILED ], $result->warnings );
		$this->assertSame(
			$before_ids,
			wc_get_orders(
				[
					'return' => 'ids',
					'limit'  => -1,
					'status' => 'any',
				]
			)
		);
	}

	/**
	 * PR #92 G1: 件数キャッシュの加算（`woocommerce_new_order` の優先度 10）より前の処理が `Error` を投げても、受注を消した後の
	 * 件数は DB と一致する。加算されないまま削除の減算だけが走ると 1 件少なくなるため、失敗時はキャッシュを捨てて数え直させる。
	 * 減算が効くよう、先に完了の受注を 1 件作っておく（0 件のままでは減らないので、ずれを検出できない）。
	 */
	public function test_failure_before_the_count_cache_increment_keeps_the_order_counts(): void {
		$this->make_writer()->write( $this->make_order( '9110', 'completed' ), null );

		$counts        = static fn (): array => [
			'pending'   => wc_orders_count( 'pending' ),
			'completed' => wc_orders_count( 'completed' ),
		];
		wc_get_container()->get( OrderCountCacheService::class )->refresh_cache( 'shop_order' );
		$before_counts = $counts();

		add_action(
			'woocommerce_new_order',
			static function (): void {
				throw new \Error( 'simulated early third-party failure' );
			},
			5
		);

		try {
			$this->make_writer()->write( $this->make_order( '9111', 'completed' ), null );
			$this->fail( 'write() should rethrow the third-party failure.' );
		} catch ( \Error $error ) {
			$this->assertSame( 'simulated early third-party failure', $error->getMessage() );
		}

		$this->assertSame( 1, $before_counts['completed'] );
		$this->assertSame( $before_counts, $counts() );
	}

	/**
	 * PR #92 G2: 他プラグインの `woocommerce_new_order` が `Exception` を投げると、`save()` はそれを握りつぶして ID を返すが、
	 * 明細は保存されていない（作成→`woocommerce_new_order`→明細の保存の途中で止まる）。もう1回保存して明細を残し、
	 * 件数キャッシュ（加算も中断されうる）も DB と一致させる。
	 */
	public function test_new_order_keeps_its_items_when_a_new_order_hook_throws_an_exception(): void {
		global $wpdb;

		$this->make_writer()->write( $this->make_order( '9112', 'completed' ), null );
		$counts = static fn (): array => [
			'pending'   => wc_orders_count( 'pending' ),
			'completed' => wc_orders_count( 'completed' ),
		];
		wc_get_container()->get( OrderCountCacheService::class )->refresh_cache( 'shop_order' );

		add_action(
			'woocommerce_new_order',
			static function (): void {
				throw new \Exception( 'simulated third-party exception on new order' );
			},
			5
		);

		$result = $this->make_writer()->write(
			$this->make_order(
				'9113',
				'completed',
				null,
				[
					[
						'sku'                 => null,
						'remote_product_id'   => 'p-g2',
						'name'                => 'Item',
						'price'               => '500',
						'unit_price_excl_tax' => '500',
						'subtotal'            => '500',
						'quantity'            => 1,
					],
				]
			),
			null
		);

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		// 明細（商品 1・配送 1）が DB に保存されている（WooCommerce の受注キャッシュを介さずに数える）。
		$this->assertSame(
			2,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d", $result->local_id ) )
		);
		$this->assertSame(
			[
				'pending'   => 0,
				'completed' => 2,
			],
			$counts()
		);
	}

	/**
	 * PR #92 G2: もう1回保存しても明細を保存できない（明細の保存前のフックが毎回 `Exception` を投げる）なら、明細の欠けた受注を
	 * mapping ごと確定させず、受注を消して例外を伝える（Importer は mapping を書かず次回に再試行する）。
	 */
	public function test_new_order_that_cannot_save_its_items_is_discarded(): void {
		$before_ids = wc_get_orders(
			[
				'return' => 'ids',
				'limit'  => -1,
				'status' => 'any',
			]
		);

		add_action(
			'woocommerce_before_order_item_object_save',
			static function ( $order_item ): void {
				if ( $order_item instanceof WC_Order_Item_Product && 0 === $order_item->get_id() ) {
					throw new \Exception( 'simulated third-party exception on item save' );
				}
			}
		);

		try {
			$this->make_writer()->write(
				$this->make_order(
					'9114',
					'completed',
					null,
					[
						[
							'sku'                 => null,
							'remote_product_id'   => 'p-g2',
							'name'                => 'Item',
							'price'               => '500',
							'unit_price_excl_tax' => '500',
							'subtotal'            => '500',
							'quantity'            => 1,
						],
					]
				),
				null
			);
			$this->fail( 'write() should fail when the items cannot be saved.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'OrderWriter: the created order could not save its items.', $exception->getMessage() );
		}

		$this->assertSame(
			$before_ids,
			wc_get_orders(
				[
					'return' => 'ids',
					'limit'  => -1,
					'status' => 'any',
				]
			)
		);
	}

	/**
	 * D25 用: 1 軸（Color: 赤・青）の可変商品を作り、取込みの印（`_cbjp_platform`/`_cbjp_remote_id`）を親とバリエーションから外して
	 * エクスポートで作った商品と同じ状態にする（product・variant の mapping は残る）。
	 */
	private function make_export_linked_variable_product( string $product_remote_id ): int {
		$parent_id = $this->make_variable_product(
			$product_remote_id,
			[
				[
					'remote_id'     => "{$product_remote_id}-red",
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '赤',
					'price'         => '1000',
					'stock'         => 5,
				],
				[
					'remote_id'     => "{$product_remote_id}-blue",
					'sku'           => null,
					'option1_name'  => 'Color',
					'option1_value' => '青',
					'price'         => '1000',
					'stock'         => 5,
				],
			]
		);

		foreach ( array_merge( [ $parent_id ], wc_get_product( $parent_id )->get_children() ) as $post_id ) {
			delete_post_meta( (int) $post_id, '_cbjp_platform' );
			delete_post_meta( (int) $post_id, '_cbjp_remote_id' );
		}

		return $parent_id;
	}

	private function order_for_option( string $number, string $product_remote_id, string $option1_value ): CanonicalOrder {
		return $this->make_order(
			$number,
			'processing',
			null,
			[
				[
					'sku'                   => null,
					'remote_product_id'     => $product_remote_id,
					'name'                  => "商品（カラー：{$option1_value}）",
					'price'                 => '1000',
					'unit_price_excl_tax'   => '1000',
					'subtotal'              => '1000',
					'quantity'              => 1,
					'option1_value_current' => $option1_value,
				],
			]
		);
	}

	/**
	 * D25（issue #98）: エクスポートで作った可変商品のバリエーションは取込みの印を持たない（取込みがその商品を書かなくなったので
	 * 印が付くことも無い）が、variant の mapping でこのプラットフォームと結ばれているので、受注明細はバリエーションに解決する
	 * （単純商品が remote_id の mapping で解決するのと揃える）。
	 */
	public function test_line_item_resolves_to_a_variation_linked_by_export_through_its_variant_mapping(): void {
		$parent_id    = $this->make_export_linked_variable_product( 'vp-exported' );
		$variation_id = $this->mappings->find_local_id( 'colorme', 'variant', 'vp-exported-red' );
		$this->assertSame( '', get_post_meta( $variation_id, '_cbjp_platform', true ) );

		$result = $this->make_writer()->write( $this->order_for_option( '4101', 'vp-exported', '赤' ), null );
		$items  = array_values( wc_get_order( $result->local_id )->get_items() );

		$this->assertSame( $parent_id, $items[0]->get_product_id() );
		$this->assertSame( $variation_id, $items[0]->get_variation_id() );
		$this->assertNotContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp-exported' ), $result->warnings );
	}

	/**
	 * D25: 印も variant の mapping も無いバリエーション（店舗が Woo で足したもの）は、従来どおり解決の対象にしない。
	 */
	public function test_line_item_does_not_resolve_to_a_variation_without_marker_or_mapping(): void {
		$parent_id = $this->make_export_linked_variable_product( 'vp-local' );

		$green = new WC_Product_Variation();
		$green->set_parent_id( $parent_id );
		$green->set_attributes( [ 'color' => '緑' ] );
		$green->save();

		$parent     = wc_get_product( $parent_id );
		$attributes = $parent->get_attributes();
		$attributes['color']->set_options( array_merge( $attributes['color']->get_options(), [ '緑' ] ) );
		$parent->set_attributes( $attributes );
		$parent->save();

		$result = $this->make_writer()->write( $this->order_for_option( '4102', 'vp-local', '緑' ), null );
		$items  = array_values( wc_get_order( $result->local_id )->get_items() );

		$this->assertSame( 0, $items[0]->get_variation_id() );
		$this->assertContains( WarningCode::with_detail( WarningCode::ORDER_LINE_VARIATION_UNMATCHED, 'vp-local' ), $result->warnings );
	}
}
