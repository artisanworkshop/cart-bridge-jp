<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Pro\Tests\Fixtures\CommerceFactory;
use CartBridgeJP\Pro\Tests\Fixtures\MockCommerceAdapter;
use CartBridgeJP\Pro\Tests\Fixtures\RegistersCommerceAdapters;
use CartBridgeJP\Sync\Exporter;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Export\AdapterPlatformWriter;
use CartBridgeJP\Woo\WooReaderRepositoryFactory;
use CartBridgeJP\Woo\WooRepositoryFactory;
use WC_Product_Simple;
use WP_UnitTestCase;

/**
 * D25（issue #98）の顧客を含む往復（R3-6c1 で `RoundTripOriginTest` から分けた。商品・在庫だけの往復は無料版に残る）。「実体は作られた向きにだけ更新する」を、実配線（`WooRepositoryFactory`・`WooReaderRepositoryFactory`・
 * `AdapterPlatformWriter`）で往復させて確かめる結合テスト。Reader・Exporter・リポジトリ・Writer を別々にテストしても、
 * 層をまたぐ印の受け渡し（取込みが書く`_cbjp_platform`をエクスポートの Reader が読む等）のずれは検出できないため
 * （CLAUDE.md「変換層と writer をフィクスチャで別々にテストしても…」）。
 */
final class CommerceRoundTripOriginTest extends WP_UnitTestCase {

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
	 * @param array<int,string> $entities
	 * @return array<string,array<string,int>>
	 */
	private function import( MockPlatformAdapter $adapter, array $entities ): array {
		$importer = new Importer( $this->mappings );
		$writer   = ( new WooRepositoryFactory() )->for_platform( $adapter->id() );
		$totals   = [];

		foreach ( $entities as $entity ) {
			$totals[ $entity ] = $importer->run_page( $adapter, $writer, $entity, Cursor::start(), false )['totals'];
		}

		return $totals;
	}

	/**
	 * @param array<int,string> $entities
	 * @return array<string,array<string,int>>
	 */
	private function export( MockPlatformAdapter $adapter, array $entities ): array {
		$exporter = new Exporter( $this->mappings );
		$reader   = ( new WooReaderRepositoryFactory() )->for_platform( $adapter->id() );
		$writer   = new AdapterPlatformWriter( $adapter );
		$totals   = [];

		foreach ( $entities as $entity ) {
			$totals[ $entity ] = $exporter->run_page( $adapter, $writer, $reader, $entity, Cursor::start(), false )['totals'];
		}

		return $totals;
	}

	/**
	 * 取り込んだ商品・顧客・在庫を同じプラットフォームへエクスポートしても、何も送らない（往復で ColorMe の値が書き換わらない）。
	 * 取込みが mapping に書いた checksum もそのまま残り、次の取込みは checksum 一致で`unchanged`になる。
	 */
	public function test_entities_imported_from_the_platform_are_not_pushed_back(): void {
		$adapter  = new MockPlatformAdapter(
			products: [ CanonicalFactory::product( '100', 'SKU-100', 5 ) ],
			push_products_supported: true,
			push_stocks_supported: true
		);
		$commerce = new MockCommerceAdapter(
			customers: [ CommerceFactory::customer( '200', 'imported@example.com' ) ],
			push_supported: true
		);
		$this->register_commerce_adapter( $commerce );

		$this->import( $adapter, [ 'product', 'customer', 'stock' ] );
		$product_checksum = $this->mappings->find_checksum( 'mock', 'product', '100' );
		$this->assertNotNull( $product_checksum );

		$totals = $this->export( $adapter, [ 'product', 'customer', 'stock' ] );

		$this->assertSame( [], $adapter->pushed_products );
		$this->assertSame( [], $commerce->pushed_customers );
		$this->assertSame( [], $adapter->pushed_stocks );

		foreach ( [ 'product', 'customer', 'stock' ] as $entity ) {
			$this->assertSame( 1, $totals[ $entity ]['skipped'], $entity );
			$this->assertSame( 1, $totals[ $entity ]['unchanged'], $entity );
		}

		$this->assertSame( $product_checksum, $this->mappings->find_checksum( 'mock', 'product', '100' ) );

		$again = $this->import( $adapter, [ 'product' ] );
		$this->assertSame( 1, $again['product']['unchanged'] );
	}

	/**
	 * Woo で作ってエクスポートした商品・顧客は、同じプラットフォームから再取込みしても（ColorMe 側の値が違っていても）上書きしない。
	 * mapping（エクスポートの checksum）も変えない。
	 */
	public function test_entities_created_by_export_are_not_overwritten_by_a_reimport(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Woo born' );
		$product->set_sku( 'WOO-1' );
		$product->set_regular_price( '1980' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product_id = $product->save();

		$customer_id = wp_insert_user(
			[
				'user_login' => 'woo-born',
				'user_email' => 'woo-born@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'customer',
				'first_name' => 'Hanako',
				'last_name'  => 'Watanabe',
			]
		);

		$export_adapter = new MockPlatformAdapter( push_products_supported: true, push_stocks_supported: true );
		$this->register_commerce_adapter( new MockCommerceAdapter( push_supported: true ) );
		$this->export( $export_adapter, [ 'product', 'customer', 'stock' ] );

		$product_remote_id  = $this->mappings->find_remote_id( 'mock', 'product', $product_id );
		$customer_remote_id = $this->mappings->find_remote_id( 'mock', 'customer', $customer_id );
		$this->assertNotNull( $product_remote_id );
		$this->assertNotNull( $customer_remote_id );
		$this->assertCount( 1, $export_adapter->pushed_stocks );
		$export_checksum = $this->mappings->find_checksum( 'mock', 'product', $product_remote_id );

		// ColorMe 側では値が変わっている（エクスポートで作った同じ remote_id の実体）想定で再取込みする。
		$reimport_adapter = new MockPlatformAdapter(
			products: [ CanonicalFactory::product( $product_remote_id, 'COLORME-SKU', 0 ) ]
		);
		$this->register_commerce_adapter( new MockCommerceAdapter( customers: [ CommerceFactory::customer( $customer_remote_id, 'woo-born@example.com' ) ] ) );
		$totals = $this->import( $reimport_adapter, [ 'product', 'customer', 'stock' ] );

		$reloaded = wc_get_product( $product_id );
		$this->assertSame( 'Woo born', $reloaded->get_name() );
		$this->assertSame( 'WOO-1', $reloaded->get_sku() );
		$this->assertSame( 5, $reloaded->get_stock_quantity() );
		$this->assertSame( '', get_post_meta( $product_id, '_cbjp_platform', true ) );
		$this->assertSame( 'Hanako', get_user_meta( $customer_id, 'first_name', true ) );
		$this->assertSame( '', get_user_meta( $customer_id, '_cbjp_platform', true ) );

		$this->assertSame( $product_id, $this->mappings->find_local_id( 'mock', 'product', $product_remote_id ) );
		$this->assertSame( $export_checksum, $this->mappings->find_checksum( 'mock', 'product', $product_remote_id ) );

		foreach ( [ 'product', 'customer', 'stock' ] as $entity ) {
			$this->assertSame( 0, $totals[ $entity ]['created'], $entity );
			$this->assertSame( 0, $totals[ $entity ]['updated'], $entity );
			$this->assertSame( 1, $totals[ $entity ]['skipped'], $entity );
			$this->assertSame( 1, $totals[ $entity ]['unchanged'], $entity );
		}
	}
}
