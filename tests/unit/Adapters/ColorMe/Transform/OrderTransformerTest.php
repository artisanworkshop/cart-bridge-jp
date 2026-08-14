<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\OrderTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use RuntimeException;
use WP_UnitTestCase;

final class OrderTransformerTest extends WP_UnitTestCase {

	public function test_transforms_bank_transfer_order_and_reconciles_totals(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '219293424', $order->number );
		$this->assertSame( 'pending', $order->status );
		$this->assertSame( '175271257', $order->customer_ref );
		$this->assertSame( '2026-07-27T23:42:45+00:00', $order->placed_at );

		$this->assertCount( 1, $order->line_items );
		$this->assertSame( '192817398', $order->line_items[0]['remote_product_id'] );
		$this->assertSame( 1, $order->line_items[0]['quantity'] );
		$this->assertSame( '3080', $order->line_items[0]['subtotal'] );

		$this->assertSame( '1000', $order->shipping['fee'] );
		$this->assertSame( '銀行振込', $order->payment['method_name'] );
		$this->assertSame( '0', $order->payment['fee'] );

		$this->assertSame( '4080', $order->totals['total'] );
		$this->assertArrayNotHasKey( 'residual', $order->totals );
	}

	public function test_transforms_cod_order_with_fee_and_reconciles_totals(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_daibiki_detail' )['sale'];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '175271028', $order->customer_ref );
		$this->assertSame( '300', $order->payment['fee'] );
		$this->assertSame( '商品代引き（ゆうパック・ゆうメール）', $order->payment['method_name'] );
		$this->assertSame( '6250', $order->totals['total'] );
		$this->assertArrayNotHasKey( 'residual', $order->totals );
	}

	public function test_order_level_tax_includes_shipping_tax_unlike_sale_tax(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$order = $this->make_transformer()->transform( $raw );

		// sale.tax (280) は商品分のみ。注文全体の税は sale.totals.normal_tax_amount (371) を使う。
		$this->assertSame( 280, $raw['tax'] );
		$this->assertSame( '371', $order->totals['tax'] );
		$this->assertSame( 280, $order->extras['product_tax'] );
		$this->assertSame( 'sale.totals', $order->totals['tax_source'] );
	}

	public function test_unresolved_payment_and_delivery_names_are_null_not_missing(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$order = ( new OrderTransformer() )->transform( $raw );

		$this->assertSame( '1094978', $order->payment['method_id'] );
		$this->assertNull( $order->payment['method_name'] );
	}

	public function test_status_mapping_for_canceled_delivered_and_paid(): void {
		$transformer = $this->make_transformer();
		$base        = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$canceled             = $base;
		$canceled['canceled'] = true;
		$this->assertSame( 'cancelled', $transformer->transform( $canceled )->status );

		$delivered              = $base;
		$delivered['delivered'] = true;
		$this->assertSame( 'completed', $transformer->transform( $delivered )->status );

		$paid         = $base;
		$paid['paid'] = true;
		$this->assertSame( 'processing', $transformer->transform( $paid )->status );
	}

	public function test_missing_make_date_throws(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['make_date'] );

		$this->expectException( RuntimeException::class );

		( new OrderTransformer() )->transform( $raw );
	}

	public function test_missing_id_throws_instead_of_yielding_empty_remote_id(): void {
		// CanonicalOrder::remote_id()はnumberを素通しするため、空文字のまま
		// 通すとImporterが弾かず複数注文が同一remote_idに衝突する。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['id'] );

		$this->expectException( RuntimeException::class );

		( new OrderTransformer() )->transform( $raw );
	}

	public function test_multi_delivery_order_uses_order_level_shipping_charge_not_first_leg(): void {
		$raw                          = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['delivery_total_charge'] = 1800;
		$raw['sale_deliveries'][0]['delivery_charge'] = 900;
		$raw['sale_deliveries'][]                     = $raw['sale_deliveries'][0];
		$raw['sale_deliveries'][1]['delivery_charge'] = 900;
		$raw['total_price']                           = 4880; // 3080 + 1800

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '1800', $order->shipping['fee'] );
		$this->assertArrayNotHasKey( 'residual', $order->totals );
		$this->assertCount( 2, $order->extras['sale_deliveries'] );
	}

	public function test_shipping_address_normalizes_overseas_pref_id_like_customer_transformer(): void {
		// saleDeliveryスキーマ自体には海外値の明記が無いが、customerスキーマとの一貫性から
		// pref_id=48を「海外」として正規化する（CustomerTransformerと同じ規約）。
		$raw                                  = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['sale_deliveries'][0]['pref_id'] = 48;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( 48, $order->shipping['pref_id'] );
		$this->assertNull( $order->shipping['country'] );
	}

	public function test_shipping_address_defaults_to_jp_for_domestic_pref_id(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( 13, $order->shipping['pref_id'] );
		$this->assertSame( 'JP', $order->shipping['country'] );
	}

	public function test_totals_residual_is_recorded_when_the_identity_does_not_balance(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		// 本来のtotal_priceは4080。恒等式と食い違う値を与えてresidualが記録されることを確認する。
		$raw['total_price'] = 3000;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '1080', $order->totals['residual'] );
	}

	public function test_quantity_greater_than_one_is_preserved(): void {
		$raw                                 = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'][0]['product_num']    = 3;
		$raw['details'][0]['subtotal_price'] = 9240;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( 3, $order->line_items[0]['quantity'] );
		$this->assertSame( '9240', $order->line_items[0]['subtotal'] );
	}

	public function test_line_item_customizations_are_preserved(): void {
		$raw                                 = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'][0]['customizations'] = [
			[
				'name'  => '刻印',
				'value' => 'Happy Birthday',
			],
		];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame(
			[
				[
					'name'  => '刻印',
					'value' => 'Happy Birthday',
				],
			],
			$order->line_items[0]['customizations']
		);
	}

	public function test_customer_snapshot_preserves_order_time_billing_info(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame(
			[
				'email'      => 'taro@example.com',
				'name'       => '山田 太郎',
				'kana'       => 'ヤマダ タロウ',
				'company'    => null,
				'department' => null,
				'phone'      => '0300000001',
				'postal'     => '1000001',
				'pref_id'    => 13,
				'pref_name'  => '東京都',
				'address1'   => '千代田区千代田1-1-1',
				'address2'   => '株式会社サンプル サンプルマンション101',
			],
			$order->extras['customer_snapshot']
		);
	}

	public function test_customer_snapshot_is_null_when_customer_is_absent(): void {
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['customer'] );

		$order = $this->make_transformer()->transform( $raw );

		$this->assertNull( $order->extras['customer_snapshot'] );
	}

	private function make_transformer(): OrderTransformer {
		return new OrderTransformer(
			[
				1094978 => '銀行振込',
				1094475 => '商品代引き（ゆうパック・ゆうメール）',
			],
			[
				640580 => 'クロネコヤマト',
			]
		);
	}
}
