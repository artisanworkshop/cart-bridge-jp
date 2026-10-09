<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\CustomerTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\OrderTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\ProductTransformer;
use CartBridgeJP\Sync\SampleSelector;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_UnitTestCase;

/**
 * `Importer::remote_id_of()` と `SampleSelector`（`includes/Sync/SampleSelector.php`）は
 * `CanonicalOrder::line_items[*]['remote_product_id']` / `customer_ref` が、対応する
 * Product/Customer Transformerの `extras['remote_id']` とバイト一致していることを前提にする。
 * この契約が崩れると無料版のサンプルID指定取得（D15）が静かに空振りする。
 */
final class CrossTransformerConsistencyTest extends WP_UnitTestCase {

	public function test_order_line_item_remote_product_ids_match_product_transformer_remote_ids(): void {
		$products           = array_map(
			fn( array $raw ) => ( new ProductTransformer() )->transform( $raw ),
			FixtureLoader::load( 'colorme', 'products' )['products']
		);
		$product_remote_ids = array_map( static fn( $p ) => $p->extras['remote_id'], $products );

		$orders = array_map(
			fn( array $raw ) => ( new OrderTransformer() )->transform( $raw ),
			FixtureLoader::load( 'colorme', 'sales' )['sales']
		);

		foreach ( $orders as $order ) {
			foreach ( $order->line_items as $line_item ) {
				$this->assertContains(
					$line_item['remote_product_id'],
					$product_remote_ids,
					"Order {$order->number} references a product not present in the product fixture."
				);
			}
		}
	}

	public function test_order_customer_ref_matches_customer_transformer_remote_id(): void {
		[ $customers_raw, $orders_raw ] = $this->raw_fixtures_with_members_flagged();

		$customers           = array_filter(
			array_map(
				fn( array $raw ) => ( new CustomerTransformer() )->transform( $raw ),
				$customers_raw
			)
		);
		$customer_remote_ids = array_map( static fn( $c ) => $c->extras['remote_id'], $customers );

		$orders = array_map(
			fn( array $raw ) => ( new OrderTransformer() )->transform( $raw ),
			$orders_raw
		);

		// オーバーライドが空振りしてこのテスト自体が無意味にならないことを保証する。
		$this->assertNotEmpty( $customer_remote_ids );

		foreach ( $orders as $order ) {
			// ゲスト購入（`customer.member === false`）は customer_ref を設定しない仕様のため対象外。
			if ( null === $order->customer_ref ) {
				continue;
			}

			$this->assertContains(
				$order->customer_ref,
				$customer_remote_ids,
				"Order {$order->number} references a customer not present in the customer fixture."
			);
		}
	}

	public function test_sample_selector_resolves_a_non_empty_sample_from_transformed_orders(): void {
		[ $customers_raw, $orders_raw ] = $this->raw_fixtures_with_members_flagged();

		$products  = array_map(
			fn( array $raw ) => ( new ProductTransformer() )->transform( $raw ),
			FixtureLoader::load( 'colorme', 'products' )['products']
		);
		$customers = array_values(
			array_filter(
				array_map(
					fn( array $raw ) => ( new CustomerTransformer() )->transform( $raw ),
					$customers_raw
				)
			)
		);
		$orders    = array_map(
			fn( array $raw ) => ( new OrderTransformer() )->transform( $raw ),
			$orders_raw
		);

		$adapter  = new MockPlatformAdapter( $products, $customers, $orders );
		$selector = new SampleSelector( $adapter );

		$sample = $selector->select_or_load( 'colorme-consistency-test-' . wp_generate_uuid4() );

		$this->assertNotEmpty( $sample->product_remote_ids );
		$this->assertNotEmpty( $sample->customer_refs );

		foreach ( $sample->product_remote_ids as $remote_id ) {
			$this->assertNotNull( $adapter->fetch_product_by_remote_id( $remote_id ) );
		}

		foreach ( $sample->customer_refs as $remote_id ) {
			$this->assertNotNull( $adapter->fetch_customer_by_remote_id( $remote_id ) );
		}
	}

	/**
	 * フィクスチャは実APIレスポンスをそのまま保持する（`tests/fixtures/README.md`）ため、コミット済み
	 * JSONの`member`値は書き換えない。会員パスを検証するため、`sales.json`が参照する2顧客
	 * （id 175271257 / 175271028。customers.json[0]/[1]と対応）のみインメモリで`member: true`に
	 * 上書きする。残りの顧客はゲストのまま残し、`CustomerTransformer`の除外分岐も併せて検証する。
	 *
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
	 */
	private function raw_fixtures_with_members_flagged(): array {
		$customers_raw = FixtureLoader::load( 'colorme', 'customers' )['customers'];
		$orders_raw    = FixtureLoader::load( 'colorme', 'sales' )['sales'];

		$customers_raw[0]['member']          = true;
		$customers_raw[1]['member']          = true;
		$orders_raw[0]['customer']['member'] = true;
		$orders_raw[1]['customer']['member'] = true;

		return [ $customers_raw, $orders_raw ];
	}
}
