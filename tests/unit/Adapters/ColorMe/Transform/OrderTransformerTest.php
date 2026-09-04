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
		// このフィクスチャの顧客は `member: false`（ゲスト購入）のため customer_ref は設定しない。
		$this->assertNull( $order->customer_ref );
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

	public function test_customer_ref_is_populated_only_for_registered_members(): void {
		// フィクスチャは実APIレスポンスをそのまま保持する（tests/fixtures/README.md）ため、
		// 会員シナリオはコミット済みJSONを書き換えず、ここでインメモリにオーバーライドして検証する。
		$raw                       = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['customer']['member'] = true;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '175271257', $order->customer_ref );
	}

	public function test_customer_ref_is_excluded_for_members_without_a_usable_email(): void {
		// CustomerTransformer::transform()はmail欠損の会員を除外するため、ここでcustomer_refに
		// 含めてしまうとSampleSelectorが無料版の限られた顧客サンプル枠を「実際には決してWoo顧客
		// として作成されない」remote_idで消費してしまう。請求先情報自体はcustomer_snapshotに残す。
		$raw                       = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['customer']['member'] = true;
		$raw['customer']['mail']   = null;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertNull( $order->customer_ref );
		$this->assertSame( '山田 太郎', $order->extras['customer_snapshot']['name'] );
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

	public function test_missing_total_price_throws_instead_of_yielding_zero_total(): void {
		// 欠損・非数値のtotal_priceを0円として通すと、実際は金額のある注文が0円の履歴注文
		// として書き込まれてしまう。全項目が同時に欠損した部分的なレスポンスではresidualが
		// 恒等式の食い違いを検知できないため、total_price自体を必須として弾く。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['total_price'] );

		$this->expectException( RuntimeException::class );

		( new OrderTransformer() )->transform( $raw );
	}

	public function test_missing_line_item_quantity_throws_instead_of_defaulting_to_one(): void {
		// 欠損・非数値の数量を1個として捏造すると、実際の購入数と食い違う出荷指示になりうる。
		// 他にこの明細の数量を復元できる情報源が無いため、注文全体を弾く。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['details'][0]['product_num'] );

		$this->expectException( RuntimeException::class );

		( new OrderTransformer() )->transform( $raw );
	}

	public function test_missing_line_item_unit_price_throws_instead_of_yielding_zero(): void {
		// Cast::money()は欠損・非数値を無言で'0'に丸めるため、区別なく通すと小計・注文合計は
		// 非ゼロなのに明細単価だけ0円という不整合な注文になり、返金・履歴レポートを壊す。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['details'][0]['price_with_tax'] );

		$this->expectException( RuntimeException::class );

		( new OrderTransformer() )->transform( $raw );
	}

	public function test_missing_line_item_excl_tax_price_yields_null_instead_of_zero(): void {
		// `price`（税抜単価）が欠損している場合、`Cast::money()`で無言で'0'に丸めると
		// 税込単価(price_with_tax)は非ゼロなのに税抜単価だけ0円という不整合になり、
		// `OrderItemBuilder`側の`ORDER_TAX_SPLIT_UNAVAILABLE`フォールバックも発火しないまま
		// 誤った税額がWooに書き込まれてしまう（issue #14）。nullを透過させて区別できることを確認する。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['details'][0]['price'] );

		$order = $this->make_transformer()->transform( $raw );

		$this->assertNull( $order->line_items[0]['unit_price_excl_tax'] );
		$this->assertSame( '3080', $order->line_items[0]['price'] );
	}

	public function test_non_numeric_line_item_excl_tax_price_yields_null_instead_of_zero(): void {
		// 欠損だけでなく非数値（型不正なレスポンス）でも`Cast::money_or_null()`がnullを
		// 透過することを確認する。将来`to_int_or_null()`の判定条件が変わっても、非数値ケースで
		// `unit_price_excl_tax`が'0'に戻らないことを回帰的に固定する。
		$raw                        = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'][0]['price'] = 'not-a-number';

		$order = $this->make_transformer()->transform( $raw );

		$this->assertNull( $order->line_items[0]['unit_price_excl_tax'] );
		$this->assertSame( '3080', $order->line_items[0]['price'] );
	}

	public function test_missing_details_field_throws_instead_of_yielding_a_zero_item_order(): void {
		// `details`欠損・非配列を「明細0件」にフォールバックすると、`OrderWriter`が再同期時に
		// 既存の明細を全削除した後この空リストから再構築してしまい、報告済みの合計金額は
		// そのままなのに明細が消えた不整合な注文履歴が残る。他の必須フィールド欠損時と同じく
		// 注文全体を弾く。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		unset( $raw['details'] );

		$this->expectException( RuntimeException::class );

		( new OrderTransformer() )->transform( $raw );
	}

	public function test_non_array_details_field_throws(): void {
		$raw            = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'] = 'unexpected-string';

		$this->expectException( RuntimeException::class );

		( new OrderTransformer() )->transform( $raw );
	}

	public function test_non_array_detail_element_throws_instead_of_being_silently_skipped(): void {
		// 個々の明細を黙って読み飛ばすと、実際には注文に含まれていた商品が復元できないまま
		// 静かに欠落し、上記と同じ不整合な注文履歴を招く。
		$raw               = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'][0] = 'unexpected-string';

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

	public function test_discount_prefers_totals_aggregate_over_the_component_breakdown(): void {
		// Yahooポイント利用のように、point_discount/gmo_point_discount/other_discountの内訳側に
		// 対応フィールドが無い割引種別がある。sale.totals.discount_amount_for_*_taxは割引手段を
		// 問わない集計値のため、内訳合計より優先して使う。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['totals']['discount_amount_for_normal_tax']  = 500;
		$raw['totals']['discount_amount_for_reduced_tax'] = 0;
		$raw['total_price']                               = 3580; // 4080 - 500

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '500', $order->totals['discount'] );
		$this->assertSame( '0', $order->totals['discount_point'] );
		$this->assertArrayNotHasKey( 'residual', $order->totals );
	}

	public function test_discount_falls_back_to_component_breakdown_when_totals_is_missing(): void {
		$raw                   = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['totals']         = null;
		$raw['point_discount'] = 300;
		$raw['total_price']    = 3780; // 4080 - 300

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '300', $order->totals['discount'] );
		$this->assertArrayNotHasKey( 'residual', $order->totals );
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

	public function test_shipping_preserves_requested_delivery_window(): void {
		// 配送希望日・希望時間帯は購入者が指定した制約であり、raw extrasのみに残すと
		// Woo writer側でASP固有構造を逆解析しないと配送スタッフに表示できない。
		$raw = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['sale_deliveries'][0]['preferred_date']   = '2026-08-01';
		$raw['sale_deliveries'][0]['preferred_period'] = '午前中';

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '2026-08-01', $order->shipping['preferred_date'] );
		$this->assertSame( '午前中', $order->shipping['preferred_period'] );
	}

	public function test_shipping_preserves_gift_fulfillment_instructions(): void {
		// 熨斗の文言・メッセージカード・ラッピングの指示は、raw extrasのみに残すと
		// Woo writer側でASP固有構造を逆解析しないとスタッフに提示できない。
		$raw                                        = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['sale_deliveries'][0]['noshi_text']    = '寿';
		$raw['sale_deliveries'][0]['card_name']     = 'バースデーカード';
		$raw['sale_deliveries'][0]['card_text']     = 'おめでとう';
		$raw['sale_deliveries'][0]['wrapping_name'] = 'ギフト用ラッピング（ピンク）';

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '寿', $order->shipping['noshi_text'] );
		$this->assertSame( 'バースデーカード', $order->shipping['card_name'] );
		$this->assertSame( 'おめでとう', $order->shipping['card_text'] );
		$this->assertSame( 'ギフト用ラッピング（ピンク）', $order->shipping['wrapping_name'] );
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
		$this->assertSame( '700', $order->totals['shipping_fee'] );
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

	public function test_line_item_preserves_order_time_product_cost(): void {
		// 商品原価は商品マスタ側で後から変わり得るため、履歴上のCOGS/利益計算には
		// 明細レベル（注文時点）の値を使う必要がある。
		$raw                               = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'][0]['product_cost'] = 1000;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( 1000, $order->line_items[0]['cost'] );
	}

	public function test_line_item_preserves_quantity_unit(): void {
		// 数量の単位（箱・セット・重量単位等）が欠けると、梱包・出荷資料側で数量ラベルを
		// 復元できない。
		$raw                       = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'][0]['unit'] = '箱';

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( '箱', $order->line_items[0]['unit'] );
	}

	public function test_line_item_preserves_captured_product_image_urls(): void {
		// 削除済み・突合不能な商品（D10により商品行として取り込む）は現行のWoo商品から
		// 画像を復元できないため、この明細レベルの値が唯一の情報源になる。
		$raw                                    = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['details'][0]['product_image_url'] = 'https://img.example.com/product.jpg';
		$raw['details'][0]['product_thumbnail_image_url'] = 'https://img.example.com/product_th.jpg';

		$order = $this->make_transformer()->transform( $raw );

		$this->assertSame( 'https://img.example.com/product.jpg', $order->line_items[0]['image_url'] );
		$this->assertSame( 'https://img.example.com/product_th.jpg', $order->line_items[0]['thumbnail_url'] );
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
				'country'    => 'JP',
			],
			$order->extras['customer_snapshot']
		);
	}

	public function test_customer_snapshot_normalizes_overseas_pref_id_like_shipping(): void {
		// ゲスト購入（customer_ref===null）ではこのスナップショットが唯一の正規化済み請求先情報に
		// なるため、shipping()・CustomerTransformer::address()と同じpref_id=48規約を適用する。
		$raw                        = FixtureLoader::load( 'colorme', 'sale_bank_detail' )['sale'];
		$raw['customer']['pref_id'] = 48;

		$order = $this->make_transformer()->transform( $raw );

		$this->assertNull( $order->extras['customer_snapshot']['country'] );
		$this->assertSame( 48, $order->extras['customer_snapshot']['pref_id'] );
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
