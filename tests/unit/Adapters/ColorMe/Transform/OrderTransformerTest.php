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

		// このフィクスチャの顧客は `member: false`（ゲスト購入）のため customer_ref は設定しない。
		$this->assertNull( $order->customer_ref );
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

	public function test_line_item_option_values_are_labeled_as_current_not_order_time(): void {
		// swagger: option1_value/option2_valueは「最新の商品情報」。オプション名変更後は
		// 購入時の選択と食い違いうるため、order-time相当の`option1_value`キーとして
		// 誤解される名前を避け、current_接尾辞付きキーで明示する。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertArrayNotHasKey( 'option1_value', $order->line_items[0] );
		$this->assertSame( 'S', $order->line_items[0]['option1_value_current'] );
		$this->assertArrayHasKey( 'option2_value_current', $order->line_items[0] );
	}

	public function test_line_item_retains_its_delivery_association(): void {
		// 複数配送先の受注で、どの明細がどの配送先(sale_deliveries[].id)に属するかを
		// Woo writerがraw extrasを逆解析せずに判定できるようにする。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '212059370', $order->line_items[0]['remote_sale_delivery_id'] );
		$this->assertSame( $order->extras['sale_deliveries'][0]['id'], (int) $order->line_items[0]['remote_sale_delivery_id'] );
	}

	public function test_split_order_uses_segment_amounts_not_parent_order_totals(): void {
		// segment.splitted=trueの受注は、商品・送料・熨斗等の合計がsegment側の実額であり、
		// トップレベルのsale.*は分割前の全体額のまま(=このsplitには使えない)。
		$raw            = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['segment'] = [
			'id'                    => 1,
			'name'                  => '区分A',
			'parent_sale_id'        => 4434233,
			'splitted'              => true,
			'product_total_price'   => 2000,
			'delivery_total_charge' => 700,
			'total_price'           => 2700,
			'noshi_total_charge'    => 0,
			'card_total_charge'     => 0,
			'wrapping_total_charge' => 0,
		];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '2000', $order->totals['subtotal'] );
		$this->assertSame( '700', $order->totals['shipping'] );
		$this->assertSame( '2700', $order->totals['total'] );
		$this->assertSame( 4434233, $order->extras['segment']['parent_sale_id'] );
		// 配送料もtotals側と同じsegment実額を使い、食い違いが起きないことを確認する。
		$this->assertSame( '700', $order->shipping['fee'] );
	}

	public function test_split_order_does_not_copy_parent_orders_tax(): void {
		// segmentスキーマには税額フィールドが無いため、分割受注では親受注（分割前の全体）の
		// 税額（sale.totals.normal_tax_amount=371）をそのまま転記せず、明示的に不明とする。
		$raw            = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['segment'] = [
			'id'                    => 1,
			'splitted'              => true,
			'product_total_price'   => 2000,
			'delivery_total_charge' => 700,
			'total_price'           => 2700,
			'noshi_total_charge'    => 0,
			'card_total_charge'     => 0,
			'wrapping_total_charge' => 0,
		];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '0', $order->totals['tax'] );
		$this->assertSame( 'unavailable_for_split_order', $order->totals['tax_source'] );
	}

	public function test_split_order_zeroes_fee_and_discounts_instead_of_duplicating_the_parent_orders(): void {
		// sale.fee/point_discount等はsegmentスキーマに対応フィールドが無く、分割前1回分の額
		// でしかない。複数segmentをそれぞれ個別注文としてインポートすると同じ額が重複計上される
		// ため、totals()・payment()どちらも0にする。
		$raw = FixtureLoader::load( 'colorme', 'sale_daibiki_detail' )['sale'];
		$this->assertSame( 300, $raw['fee'] );
		$raw['segment'] = [
			'id'                    => 1,
			'splitted'              => true,
			'product_total_price'   => 2000,
			'delivery_total_charge' => 700,
			'total_price'           => 2700,
			'noshi_total_charge'    => 0,
			'card_total_charge'     => 0,
			'wrapping_total_charge' => 0,
		];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '0', $order->totals['fee'] );
		$this->assertSame( '0', $order->totals['discount_point'] );
		$this->assertSame( '0', $order->totals['discount_gmo'] );
		$this->assertSame( '0', $order->totals['discount_other'] );
		$this->assertSame( '0', $order->payment['fee'] );
		$this->assertArrayNotHasKey( 'residual', $order->totals );
	}

	public function test_non_split_order_tax_fallback_marks_itself_incomplete_when_totals_is_missing(): void {
		// sale.totalsが欠損している場合の最後の手段sale.taxは商品分のみで送料分の税を含まない。
		// 実額を捏造せず、tax_sourceで不完全である旨を明示する。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['totals'] );

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( 280, $order->extras['product_tax'] );
		$this->assertSame( '280', $order->totals['tax'] );
		$this->assertSame( 'sale.tax_incomplete_excludes_shipping_tax', $order->totals['tax_source'] );
	}

	public function test_gmo_and_yahoo_point_activity_is_preserved_in_extras(): void {
		$raw                         = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['granted_gmo_points']   = 10;
		$raw['use_gmo_points']       = 5;
		$raw['granted_yahoo_points'] = 20;
		$raw['use_yahoo_points']     = 15;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( 10, $order->extras['granted_gmo_points'] );
		$this->assertSame( 5, $order->extras['use_gmo_points'] );
		$this->assertSame( 20, $order->extras['granted_yahoo_points'] );
		$this->assertSame( 15, $order->extras['use_yahoo_points'] );
	}

	public function test_non_split_order_ignores_segment_and_uses_sale_level_totals(): void {
		// segment.splitted=falseの場合、segmentは自身の受注IDを指すだけの非分割メタ情報であり、
		// 分割金額の情報源としては使わない。
		$raw            = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['segment'] = [
			'id'                  => 1,
			'parent_sale_id'      => (int) $raw['id'],
			'splitted'            => false,
			'product_total_price' => 999999,
		];

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '4080', $order->totals['total'] );
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
