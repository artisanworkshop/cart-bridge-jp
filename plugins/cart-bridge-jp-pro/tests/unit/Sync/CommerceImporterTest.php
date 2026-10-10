<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Pro\Canonical\CanonicalOrder;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Pro\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\InMemoryWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\WooRepositoryFactory;
use WP_UnitTestCase;

/**
 * 受注を無料版の `Importer` で通す（R3-6c1 で `ImporterTest` から分けた）。
 */
final class CommerceImporterTest extends WP_UnitTestCase {

	use RegistersCommerceAdapters;

	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings = new MappingRepository();
	}

	public function tear_down(): void {
		$this->forget_commerce_adapters();
		parent::tear_down();
		$this->forget_commerce_adapters();
	}

	/**
	 * 接続先（`mock`）に受注を返す `CommerceAdapter` を登録し、無料版のアダプタを返す。
	 *
	 * @param array<int,CanonicalOrder> $orders
	 */
	private function adapter_with_orders( array $orders ): MockPlatformAdapter {
		$this->register_commerce_adapter( new MockCommerceAdapter( orders: $orders ) );

		return new MockPlatformAdapter();
	}

	/**
	 * 移行後検証レポート（D17）用の `remote_amount` は、書込の成否・checksum一致スキップに
	 * 関わらず、この run で ASP から取得した全受注の合計になる（Woo側の「リンク済み受注の合計」と
	 * 並べて、警告・例外で取り込めなかった分も金額で見せるため）。
	 */
	public function test_remote_amount_accumulates_the_total_of_every_processed_order(): void {
		$skipped = new CanonicalOrder( '1001', 'processing', null, [], [], [], [ 'total' => '1500.5' ], '2026-07-01 00:00:00', null );
		$written = new CanonicalOrder( '1002', 'processing', null, [], [], [], [ 'total' => '2000' ], '2026-07-01 00:00:00', null );
		$adapter = $this->adapter_with_orders( [ $skipped, $written ] );

		// 1件目は checksum 一致でスキップされる経路に乗せる。
		$this->mappings->upsert( $adapter->id(), 'order', '1001', 501, $skipped->checksum() );

		$writer   = new InMemoryWriter();
		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, $writer, 'order', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes, 'checksum一致の1件は書き込まれない' );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 350050, $result['totals']['remote_amount'], '1500.50 + 2000.00 を 1/100 単位で累積' );
	}

	/**
	 * R3-0m: 決済方法が未マッピングのまま本取込みした受注は checksum を保存しない。そのため Mappings タブで設定した後の
	 * dry-run は checksum 一致で検証を飛ばさずに検証し直し（警告だけが消えて直ったように見える、を防ぐ）、次回の本取込みで
	 * 決済方法が付き直る。実 Writer（`WooRepositoryFactory`）で通して確かめる。
	 */
	public function test_order_imported_with_an_unmapped_payment_method_is_fixed_by_the_next_import(): void {
		$order    = new CanonicalOrder(
			'2001',
			'processing',
			null,
			[],
			[],
			[
				'method_id'   => 'pay-1',
				'method_name' => 'Bank transfer',
			],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			'2026-07-01T00:00:00+00:00',
			null
		);
		$adapter  = $this->adapter_with_orders( [ $order ] );
		$factory  = new WooRepositoryFactory();
		$importer = new Importer( $this->mappings );

		$first = $importer->run_page( $adapter, $factory->for_platform( $adapter->id() ), 'order', Cursor::start(), false );

		$this->assertSame( 1, $first['totals']['created'] );
		$this->assertNull( $this->mappings->find_checksum( $adapter->id(), 'order', '2001' ), '未マッピングの受注は checksum を保存しない' );
		$local_id = $this->mappings->find_local_id( $adapter->id(), 'order', '2001' );
		$this->assertIsInt( $local_id );
		$this->assertSame( '', wc_get_order( $local_id )->get_payment_method() );

		update_option( 'cbjp_settings_' . $adapter->id(), [ 'payment_map' => [ 'pay-1' => 'bacs' ] ] );

		$dry_run = $importer->run_page( $adapter, $factory->for_dry_run( $adapter->id() ), 'order', Cursor::start(), true );

		$this->assertSame( 0, $dry_run['totals']['skipped'], '設定後の dry-run は checksum 一致で飛ばさず検証し直す' );
		$this->assertSame( 1, $dry_run['totals']['updated'] );

		$second = $importer->run_page( $adapter, $factory->for_platform( $adapter->id() ), 'order', Cursor::start(), false );

		$this->assertSame( 1, $second['totals']['updated'] );
		$this->assertSame( 'bacs', wc_get_order( $local_id )->get_payment_method() );
		$this->assertNotNull( $this->mappings->find_checksum( $adapter->id(), 'order', '2001' ), '設定後は checksum を保存する' );

		delete_option( 'cbjp_settings_' . $adapter->id() );
	}

	/**
	 * D26: 受注は、軽減税率の明細を入れる税区分が無く標準に倒しても checksum を保存する（商品と違い、取込みのたびに明細を作り直さない。
	 * 受注の金額は ASP の値を明示しており、変わるのは税区分の名前だけ。review-loop R1-4）。
	 */
	public function test_order_imported_without_a_reduced_class_keeps_its_checksum(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		\WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Rice' );
		$wc_product->set_regular_price( '1080' );
		$product_id = $wc_product->save();

		$order   = new CanonicalOrder(
			'2101',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'p-rice',
					'name'                => 'Rice',
					'price'               => '1080',
					'unit_price_excl_tax' => '1000',
					'subtotal'            => '1080',
					'quantity'            => 1,
					'tax_reduced'         => true,
				],
			],
			[],
			[],
			[
				'total'        => '1080',
				'tax'          => '80',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			'2026-07-01T00:00:00+00:00',
			null
		);
		$adapter = $this->adapter_with_orders( [ $order ] );
		$this->mappings->upsert( $adapter->id(), 'product', 'p-rice', $product_id, null );

		$result = ( new Importer( $this->mappings ) )->run_page( $adapter, ( new WooRepositoryFactory() )->for_platform( $adapter->id() ), 'order', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['created'] );
		$local_id = $this->mappings->find_local_id( $adapter->id(), 'order', '2101' );
		$this->assertIsInt( $local_id );
		$items = array_values( wc_get_order( $local_id )->get_items() );
		$this->assertSame( '', $items[0]->get_tax_class(), '入れる税区分が無いので標準に倒す' );
		$this->assertNotNull( $this->mappings->find_checksum( $adapter->id(), 'order', '2101' ), '受注は checksum を保存する' );
	}
}
