<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Tools;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\Tools\PrefStateRepair;
use CartBridgeJP\Woo\Tools\RepairInterruptedException;
use CartBridgeJP\Woo\WooRepositoryFactory;
use CartBridgeJP\Woo\Writer\OrderItemBuilder;
use CartBridgeJP\Woo\Writer\OrderWriter;
use InvalidArgumentException;
use RuntimeException;
use WC_Order;
use WP_Error;

/**
 * 県コード修復ツール（issue #46）。
 *
 * 「修復前のデータ」は、実 Writer（`CustomerWriter`/`OrderWriter`）で取り込んだ実体の `state` を
 * 旧コードの出力（`JP{pref_id}`＝恒等変換）へ書き戻して再現する。Canonical→Writer→ツールを実際に
 * 通すことで、層間の入力のズレ（例: 受注請求先は `customer_snapshot`）を検出できるようにしている。
 * 期待値の `JPxx` は `AddressMapper` の表とは別に、PR #44 で確定した対応をここへ直書きしている。
 */
final class PrefStateRepairTest extends WooTestCase {

	private const PLATFORM = 'colorme';

	/**
	 * 旧バグの影響を受ける23県。[ColorMe の pref_id, 正しい Woo の state]。
	 * 巡回置換（19→22→23→21→20→19 など）を含むため、二重適用では戻らない。
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public static function affected_prefectures(): array {
		$pairs = [
			4  => 'JP05',
			5  => 'JP04',
			16 => 'JP18',
			18 => 'JP16',
			19 => 'JP22',
			20 => 'JP19',
			21 => 'JP20',
			22 => 'JP23',
			23 => 'JP21',
			25 => 'JP30',
			26 => 'JP25',
			27 => 'JP29',
			28 => 'JP26',
			29 => 'JP27',
			30 => 'JP28',
			31 => 'JP33',
			32 => 'JP34',
			33 => 'JP31',
			34 => 'JP32',
			36 => 'JP37',
			37 => 'JP36',
			43 => 'JP44',
			44 => 'JP43',
		];

		$cases = [];

		foreach ( $pairs as $pref_id => $state ) {
			$cases[ "pref_id {$pref_id}" ] = [ $pref_id, $state ];
		}

		return $cases;
	}

	/**
	 * 新旧で state が同じ（表が恒等の）県。
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function unaffected_prefectures(): array {
		return [
			'Hokkaido' => [ 1 ],
			'Tokyo'    => [ 13 ],
			'Fukui'    => [ 17 ],
			'Gifu'     => [ 24 ],
			'Osaka'    => [ 35 ],
			'Okinawa'  => [ 47 ],
		];
	}

	// ---- ヘルパー ---------------------------------------------------------------------------

	/**
	 * @return array<string,mixed>
	 */
	private function address( int $pref_id, string $postal = '1000001', string $address1 = 'Chiyoda 1-1-1' ): array {
		return [
			'name'      => 'Yamada Taro',
			'email'     => 'yamada@example.com',
			'phone'     => '0312345678',
			'postal'    => $postal,
			'pref_id'   => $pref_id,
			'pref_name' => 'Placeholder',
			'address1'  => $address1,
			'address2'  => null,
			'tel'       => '0312345678',
			'country'   => 'JP',
		];
	}

	private function customer_model( string $remote_id, int $pref_id, string $postal = '1000001', string $address1 = 'Chiyoda 1-1-1' ): CanonicalCustomer {
		return new CanonicalCustomer(
			"cust-{$remote_id}@example.com",
			'Yamada Taro',
			null,
			null,
			null,
			$this->address( $pref_id, $postal, $address1 ),
			'0312345678',
			null,
			null,
			null,
			[ 'remote_id' => $remote_id ]
		);
	}

	private function order_model( string $number, int $billing_pref_id, int $shipping_pref_id ): CanonicalOrder {
		return new CanonicalOrder(
			$number,
			'processing',
			null,
			[],
			$this->address( $shipping_pref_id ),
			[],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			'2026-01-01T00:00:00+00:00',
			null,
			[
				'remote_id'         => $number,
				'customer_snapshot' => $this->address( $billing_pref_id ),
			]
		);
	}

	/**
	 * 実 `CustomerWriter` で取り込み、mapping を登録する。state は現行コード（＝正しい値）で書かれる。
	 */
	private function import_customer( CanonicalCustomer $customer ): int {
		$result = ( new WooRepositoryFactory() )->for_platform( self::PLATFORM )->write( 'customer', $customer, null );

		$this->assertGreaterThan( 0, $result->local_id, 'customer import failed: ' . implode( ',', $result->warnings ) );
		$this->seed_mapping( self::PLATFORM, 'customer', (string) $customer->remote_id(), $result->local_id );

		return $result->local_id;
	}

	private function import_order( CanonicalOrder $order ): int {
		$resolver = new ProductResolver( self::PLATFORM, $this->mappings );
		$writer   = new OrderWriter( self::PLATFORM, $this->mappings, new OrderItemBuilder( $resolver ), new MethodMap( self::PLATFORM ) );
		$result   = $writer->write( $order, null );

		$this->assertGreaterThan( 0, $result->local_id, 'order import failed: ' . implode( ',', $result->warnings ) );
		$this->seed_mapping( self::PLATFORM, 'order', $order->remote_id(), $result->local_id );

		return $result->local_id;
	}

	/**
	 * PR #44 より前のコードが書いていた値（`JP{pref_id}`）へ戻して「修復前のデータ」を再現する。
	 */
	private function make_customer_legacy( int $user_id, int $pref_id ): void {
		$legacy = sprintf( 'JP%02d', $pref_id );

		update_user_meta( $user_id, 'billing_state', $legacy );
		update_user_meta( $user_id, 'shipping_state', $legacy );
	}

	private function make_order_legacy( int $order_id, int $billing_pref_id, int $shipping_pref_id ): void {
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );

		$order->set_billing_state( sprintf( 'JP%02d', $billing_pref_id ) );
		$order->set_shipping_state( sprintf( 'JP%02d', $shipping_pref_id ) );
		$order->save();
	}

	private function tool( MockPlatformAdapter $adapter ): PrefStateRepair {
		return new PrefStateRepair( $this->mappings, $adapter );
	}

	private function user_state( int $user_id, string $side ): string {
		return (string) get_user_meta( $user_id, "{$side}_state", true );
	}

	private function order( int $order_id ): WC_Order {
		$order = wc_get_order( $order_id );
		$this->assertInstanceOf( WC_Order::class, $order );

		return $order;
	}

	// ---- 顧客 -------------------------------------------------------------------------------

	/**
	 * @dataProvider affected_prefectures
	 */
	public function test_customer_states_are_scanned_then_repaired_for_every_affected_prefecture( int $pref_id, string $expected ): void {
		$customer = $this->customer_model( 'C1', $pref_id );
		$user_id  = $this->import_customer( $customer );
		$legacy   = sprintf( 'JP%02d', $pref_id );

		// 現行コードで取り込んだ直後は既に正しい値（ツールの期待値と Writer の出力が一致していることの確認）。
		$this->assertSame( $expected, $this->user_state( $user_id, 'billing' ) );

		$this->make_customer_legacy( $user_id, $pref_id );

		$tool = $this->tool( new MockPlatformAdapter( [], [ $customer ] ) );

		// Scan は何も書かない。
		$scan = $tool->run( self::PLATFORM, false );
		$this->assertSame( 1, $scan['counts']['customer']['fixed'] );
		$this->assertNull( $scan['cursor'] );
		$this->assertNull( $scan['interruption'] );
		$this->assertSame( $legacy, $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( $legacy, $this->user_state( $user_id, 'shipping' ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, PrefStateRepair::AUDIT_META, true ) );

		// Repair は請求先・配送先の両方を補正し、変更を監査メタに残す。
		$repair = $tool->run( self::PLATFORM, true );
		$this->assertSame( 1, $repair['counts']['customer']['fixed'] );
		$this->assertSame( $expected, $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( $expected, $this->user_state( $user_id, 'shipping' ) );

		$audit = json_decode( (string) get_user_meta( $user_id, PrefStateRepair::AUDIT_META, true ), true );
		$this->assertSame(
			[
				'billing'  => [
					'from' => $legacy,
					'to'   => $expected,
				],
				'shipping' => [
					'from' => $legacy,
					'to'   => $expected,
				],
			],
			$audit
		);

		// 冪等: もう一度実行しても何も変わらない（二重適用で別の県にならない）。
		$again = $tool->run( self::PLATFORM, true );
		$this->assertSame( 0, $again['counts']['customer']['fixed'] );
		$this->assertSame( 1, $again['counts']['customer']['already_correct'] );
		$this->assertSame( $expected, $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( $expected, $this->user_state( $user_id, 'shipping' ) );
	}

	/**
	 * @dataProvider unaffected_prefectures
	 */
	public function test_unaffected_prefectures_are_settled_locally_without_asking_the_platform( int $pref_id ): void {
		$customer = $this->customer_model( 'C1', $pref_id );
		$user_id  = $this->import_customer( $customer );
		$state    = $this->user_state( $user_id, 'billing' );
		$adapter  = new MockPlatformAdapter( [], [ $customer ] );

		$result = $this->tool( $adapter )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['already_correct'] );
		$this->assertSame( [], $adapter->fetched_by_id, '旧バグの影響を受けない値の実体は ASP に照会しない' );
		$this->assertSame( $state, $this->user_state( $user_id, 'billing' ) );
	}

	public function test_overseas_customers_are_left_alone(): void {
		$customer = $this->customer_model( 'C1', 48 );
		$user_id  = $this->import_customer( $customer );
		$adapter  = new MockPlatformAdapter( [], [ $customer ] );

		$result = $this->tool( $adapter )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['already_correct'] );
		$this->assertSame( '', $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( [], $adapter->fetched_by_id );
	}

	public function test_a_state_that_is_already_correct_is_confirmed_but_not_rewritten(): void {
		// pref_id=4（秋田）の正しい値 JP05 は「旧バグが出力しうる値」の集合に含まれるため照会はされるが、
		// ASP の権威値から見て既に正しいので何も書かない。
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );
		$adapter  = new MockPlatformAdapter( [], [ $customer ] );

		$result = $this->tool( $adapter )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['already_correct'] );
		$this->assertSame( 0, $result['counts']['customer']['fixed'] );
		$this->assertCount( 1, $adapter->fetched_by_id );
		$this->assertSame( 'JP05', $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, PrefStateRepair::AUDIT_META, true ) );
	}

	public function test_a_hand_edited_state_is_reported_as_unverified_and_not_changed(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );

		// 店舗が手で別の（旧バグが出しうる値の）県へ変更した。旧出力（JP04）でも正しい値（JP05）でもない。
		update_user_meta( $user_id, 'billing_state', 'JP20' );
		update_user_meta( $user_id, 'shipping_state', 'JP20' );

		$result = $this->tool( new MockPlatformAdapter( [], [ $customer ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['unverified'] );
		$this->assertSame( 0, $result['counts']['customer']['fixed'] );
		$this->assertSame( 'JP20', $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( 'JP20', $this->user_state( $user_id, 'shipping' ) );
	}

	public function test_each_side_is_judged_independently(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );
		$this->make_customer_legacy( $user_id, 4 );

		// ASP 側の住所変更を想定し、請求先だけ郵便番号が食い違う。配送先は旧バグの出力のまま。
		update_user_meta( $user_id, 'billing_postcode', '9999999' );

		$result = $this->tool( new MockPlatformAdapter( [], [ $customer ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['fixed'] );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'billing' ), '郵便番号が食い違う側は state だけを書き換えない' );
		$this->assertSame( 'JP05', $this->user_state( $user_id, 'shipping' ) );

		$audit = json_decode( (string) get_user_meta( $user_id, PrefStateRepair::AUDIT_META, true ), true );
		$this->assertSame( [ 'shipping' ], array_keys( $audit ) );
	}

	public function test_a_changed_address_line_is_unverified(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );
		$this->make_customer_legacy( $user_id, 4 );
		update_user_meta( $user_id, 'billing_address_1', 'Somewhere else 9-9-9' );
		update_user_meta( $user_id, 'shipping_address_1', 'Somewhere else 9-9-9' );

		$result = $this->tool( new MockPlatformAdapter( [], [ $customer ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['unverified'] );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'shipping' ) );
	}

	public function test_a_hyphen_in_the_postcode_does_not_prevent_repair(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );
		$this->make_customer_legacy( $user_id, 4 );
		update_user_meta( $user_id, 'billing_postcode', '100-0001' );
		update_user_meta( $user_id, 'shipping_postcode', '100-0001' );

		$result = $this->tool( new MockPlatformAdapter( [], [ $customer ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['fixed'] );
		$this->assertSame( 'JP05', $this->user_state( $user_id, 'billing' ) );
	}

	public function test_staff_accounts_are_never_touched(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = self::factory()->user->create( [ 'role' => 'shop_manager' ] );

		// スタッフのアカウントが ASP 顧客と email 突合されて mapping だけ作られた状況。
		update_user_meta( $user_id, '_cbjp_platform', self::PLATFORM );
		update_user_meta( $user_id, 'billing_state', 'JP04' );
		update_user_meta( $user_id, 'billing_postcode', '1000001' );
		update_user_meta( $user_id, 'billing_address_1', 'Chiyoda 1-1-1' );
		$this->seed_mapping( self::PLATFORM, 'customer', 'C1', $user_id );

		$adapter = new MockPlatformAdapter( [], [ $customer ] );
		$result  = $this->tool( $adapter )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['skipped'] );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( [], $adapter->fetched_by_id );
	}

	public function test_users_not_owned_by_the_platform_are_skipped(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );
		$this->make_customer_legacy( $user_id, 4 );
		update_user_meta( $user_id, '_cbjp_platform', 'someone-else' );

		$adapter = new MockPlatformAdapter( [], [ $customer ] );
		$result  = $this->tool( $adapter )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['skipped'] );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( [], $adapter->fetched_by_id );
	}

	public function test_a_mapping_to_a_deleted_user_is_skipped_without_writing_orphan_meta(): void {
		$this->seed_mapping( self::PLATFORM, 'customer', 'C1', 999999 );

		$result = $this->tool( new MockPlatformAdapter( [], [ $this->customer_model( 'C1', 4 ) ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['skipped'] );
		$this->assertSame( '', (string) get_user_meta( 999999, 'billing_state', true ) );
	}

	public function test_a_record_the_platform_cannot_return_is_unavailable(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );
		$this->make_customer_legacy( $user_id, 4 );

		// ASP 側で削除済み（または変換不能）。何も書かず、`not_found` と混同しない専用の区分で数える。
		$result = $this->tool( new MockPlatformAdapter() )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['unavailable'] );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'billing' ) );
	}

	public function test_a_record_for_a_different_remote_id_is_not_trusted(): void {
		$customer = $this->customer_model( 'C1', 4 );
		$user_id  = $this->import_customer( $customer );
		$this->make_customer_legacy( $user_id, 4 );

		// 外部アダプタが要求と別の顧客（別の県）を返す契約違反。その住所で補正してはならない。
		$other  = $this->customer_model( 'OTHER', 16 );
		$result = $this->tool( new MockPlatformAdapter( customer_by_remote_id_override: $other ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['unavailable'] );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'billing' ) );
	}

	// ---- 受注 -------------------------------------------------------------------------------

	/**
	 * @dataProvider affected_prefectures
	 */
	public function test_order_states_are_repaired_for_every_affected_prefecture( int $pref_id, string $expected ): void {
		$model    = $this->order_model( '5001', $pref_id, $pref_id );
		$order_id = $this->import_order( $model );
		$legacy   = sprintf( 'JP%02d', $pref_id );

		// 実 `OrderWriter` の出力（請求先は customer_snapshot 由来）が期待値と一致していること。
		$this->assertSame( $expected, $this->order( $order_id )->get_billing_state() );
		$this->assertSame( $expected, $this->order( $order_id )->get_shipping_state() );

		$this->make_order_legacy( $order_id, $pref_id, $pref_id );

		$tool = $this->tool( new MockPlatformAdapter( [], [], [ $model ] ) );

		$scan = $tool->run( self::PLATFORM, false );
		$this->assertSame( 1, $scan['counts']['order']['fixed'] );
		$this->assertSame( $legacy, $this->order( $order_id )->get_billing_state(), 'Scan は書かない' );

		$repair = $tool->run( self::PLATFORM, true );
		$this->assertSame( 1, $repair['counts']['order']['fixed'] );
		$this->assertSame( $expected, $this->order( $order_id )->get_billing_state() );
		$this->assertSame( $expected, $this->order( $order_id )->get_shipping_state() );

		$audit = json_decode( (string) $this->order( $order_id )->get_meta( PrefStateRepair::AUDIT_META ), true );
		$this->assertSame( $legacy, $audit['billing']['from'] );
		$this->assertSame( $expected, $audit['shipping']['to'] );

		$again = $tool->run( self::PLATFORM, true );
		$this->assertSame( 0, $again['counts']['order']['fixed'] );
		$this->assertSame( $expected, $this->order( $order_id )->get_billing_state() );
	}

	public function test_order_billing_and_shipping_use_their_own_addresses(): void {
		// 請求先は秋田（pref_id=4）、配送先は宮城（pref_id=5）。両側が別々の権威値で補正される。
		$model    = $this->order_model( '5002', 4, 5 );
		$order_id = $this->import_order( $model );
		$this->make_order_legacy( $order_id, 4, 5 );

		$this->tool( new MockPlatformAdapter( [], [], [ $model ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 'JP05', $this->order( $order_id )->get_billing_state() );
		$this->assertSame( 'JP04', $this->order( $order_id )->get_shipping_state() );
	}

	public function test_an_order_that_needs_no_repair_is_not_saved(): void {
		// pref_id=4 の正しい値 JP05 は照会対象だが、既に正しい。save() すると date_modified が現在時刻へ
		// 更新されるため、それが起きていないことで「不要な書込みをしない」ことを確認する。
		$model    = $this->order_model( '5003', 4, 4 );
		$order_id = $this->import_order( $model );

		$order = $this->order( $order_id );
		$order->set_date_modified( '2020-01-01 00:00:00' );
		$order->save();
		$this->assertSame( '2020-01-01', $this->order( $order_id )->get_date_modified()->date( 'Y-m-d' ) );

		$result = $this->tool( new MockPlatformAdapter( [], [], [ $model ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['order']['already_correct'] );
		$this->assertSame( '2020-01-01', $this->order( $order_id )->get_date_modified()->date( 'Y-m-d' ) );
		$this->assertSame( '', (string) $this->order( $order_id )->get_meta( PrefStateRepair::AUDIT_META ) );
	}

	public function test_trashed_and_draft_orders_are_skipped(): void {
		$trashed_model = $this->order_model( '5004', 4, 4 );
		$trashed_id    = $this->import_order( $trashed_model );
		$this->make_order_legacy( $trashed_id, 4, 4 );
		$this->order( $trashed_id )->delete( false );

		$draft_model = $this->order_model( '5005', 4, 4 );
		$draft_id    = $this->import_order( $draft_model );
		$this->make_order_legacy( $draft_id, 4, 4 );
		$draft = $this->order( $draft_id );
		$draft->set_status( 'checkout-draft' );
		$draft->save();

		$adapter = new MockPlatformAdapter( [], [], [ $trashed_model, $draft_model ] );
		$result  = $this->tool( $adapter )->run( self::PLATFORM, true );

		$this->assertSame( 2, $result['counts']['order']['skipped'] );
		$this->assertSame( [], $adapter->fetched_by_id );
		$this->assertSame( 'JP04', $this->order( $draft_id )->get_billing_state() );
	}

	public function test_orders_not_owned_by_the_platform_are_skipped(): void {
		$model    = $this->order_model( '5006', 4, 4 );
		$order_id = $this->import_order( $model );
		$this->make_order_legacy( $order_id, 4, 4 );
		$order = $this->order( $order_id );
		$order->update_meta_data( '_cbjp_platform', 'someone-else' );
		$order->save();

		$result = $this->tool( new MockPlatformAdapter( [], [], [ $model ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['order']['skipped'] );
		$this->assertSame( 'JP04', $this->order( $order_id )->get_billing_state() );
	}

	public function test_a_mapping_to_a_missing_order_is_skipped(): void {
		$this->seed_mapping( self::PLATFORM, 'order', '5007', 999999 );

		$result = $this->tool( new MockPlatformAdapter( [], [], [ $this->order_model( '5007', 4, 4 ) ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['order']['skipped'] );
	}

	public function test_an_order_the_platform_cannot_return_is_unavailable_and_a_mismatched_one_is_not_trusted(): void {
		$model    = $this->order_model( '5008', 4, 4 );
		$order_id = $this->import_order( $model );
		$this->make_order_legacy( $order_id, 4, 4 );

		$missing = $this->tool( new MockPlatformAdapter() )->run( self::PLATFORM, true );
		$this->assertSame( 1, $missing['counts']['order']['unavailable'] );

		$other      = $this->order_model( '9999', 16, 16 );
		$mismatched = $this->tool( new MockPlatformAdapter( order_by_remote_id_override: $other ) )->run( self::PLATFORM, true );
		$this->assertSame( 1, $mismatched['counts']['order']['unavailable'] );
		$this->assertSame( 'JP04', $this->order( $order_id )->get_billing_state() );
	}

	// ---- 中断・cursor・入力検証 ----------------------------------------------------------------

	/**
	 * @return array<string,array{0:\Throwable,1:string}>
	 */
	public static function failures(): array {
		return [
			'client-side rate limit'     => [ new RateLimitExhaustedException( self::PLATFORM ), RepairInterruptedException::RATE_LIMITED ],
			'platform 429 (exhausted)'   => [ new ApiException( 'too many requests', 429, [ 'rate_limited' => true ] ), RepairInterruptedException::RATE_LIMITED ],
			'adapter not connected'      => [ new ApiException( 'not connected', 0, [ 'not_connected' => true ] ), RepairInterruptedException::NOT_CONNECTED ],
			'unauthorized'               => [ new ApiException( 'unauthorized', 401, [] ), RepairInterruptedException::NOT_CONNECTED ],
			// ステータス 0 は通信断・JSON 破損でも使われる。「未接続」と明示されていない限り再接続を促さない。
			'network failure (status 0)' => [ new ApiException( 'cURL error 28', 0, [ 'wp_error_code' => 'http_request_failed' ] ), RepairInterruptedException::API_ERROR ],
			'server error'               => [ new ApiException( 'server error', 500, [] ), RepairInterruptedException::API_ERROR ],
			'malformed'                  => [ new RuntimeException( 'malformed 200 response' ), RepairInterruptedException::API_ERROR ],
			'unsupported'                => [ new UnsupportedOperationException( self::PLATFORM, 'fetch_order_by_remote_id' ), RepairInterruptedException::UNSUPPORTED ],
		];
	}

	/**
	 * @dataProvider failures
	 */
	public function test_a_platform_failure_interrupts_with_the_progress_and_a_resumable_cursor( \Throwable $failure, string $reason ): void {
		// 1件目は旧バグの影響を受けない県（ローカルで確定）、2件目は照会が必要。
		$first  = $this->customer_model( 'C1', 13 );
		$second = $this->customer_model( 'C2', 4 );
		$this->import_customer( $first );
		$second_id = $this->import_customer( $second );
		$this->make_customer_legacy( $second_id, 4 );

		$adapter = new MockPlatformAdapter( customers: [ $first, $second ], fetch_by_id_failure: $failure );
		$result  = $this->tool( $adapter )->run( self::PLATFORM, true );

		$this->assertSame( $reason, $result['interruption'] );
		$this->assertSame( 1, $result['counts']['customer']['already_correct'], '中断前に処理した分は件数に残る' );
		$this->assertSame( '{"entity":"customer","offset":1}', $result['cursor'], '失敗した行から再開する' );
		$this->assertSame( 'JP04', $this->user_state( $second_id, 'billing' ) );

		// 障害が解消したら、返された cursor から再開して残りを補正できる。
		$resumed = $this->tool( new MockPlatformAdapter( [], [ $first, $second ] ) )->run( self::PLATFORM, true, $result['cursor'] );

		$this->assertNull( $resumed['interruption'] );
		$this->assertNull( $resumed['cursor'] );
		$this->assertSame( 1, $resumed['counts']['customer']['fixed'] );
		$this->assertSame( 'JP05', $this->user_state( $second_id, 'billing' ) );
	}

	public function test_the_budget_splits_the_work_and_the_cursor_walks_every_entity_exactly_once(): void {
		$models = [];
		$ids    = [];

		foreach ( [ 4, 5, 16 ] as $index => $pref_id ) {
			$models[ $index ] = $this->customer_model( "C{$index}", $pref_id );
			$ids[ $index ]    = $this->import_customer( $models[ $index ] );
			$this->make_customer_legacy( $ids[ $index ], $pref_id );
		}

		$order_model = $this->order_model( '5100', 4, 4 );
		$order_id    = $this->import_order( $order_model );
		$this->make_order_legacy( $order_id, 4, 4 );

		$adapter = new MockPlatformAdapter( [], $models, [ $order_model ] );
		$tool    = $this->tool( $adapter );
		$cursor  = null;
		$calls   = 0;
		$fixed   = 0;

		do {
			$result = $tool->run( self::PLATFORM, true, $cursor, 1 );
			$fixed += $result['counts']['customer']['fixed'] + $result['counts']['order']['fixed'];
			$cursor = $result['cursor'];
			++$calls;
		} while ( null !== $cursor && $calls < 20 );

		$this->assertGreaterThan( 1, $calls );
		$this->assertSame( 4, $fixed, '3 人の顧客と 1 件の受注を過不足なく1回ずつ補正する' );
		$this->assertSame( 'JP05', $this->user_state( $ids[0], 'billing' ) );
		$this->assertSame( 'JP04', $this->user_state( $ids[1], 'billing' ) );
		$this->assertSame( 'JP18', $this->user_state( $ids[2], 'billing' ) );
		$this->assertSame( 'JP05', $this->order( $order_id )->get_billing_state() );
	}

	public function test_an_invalid_cursor_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->tool( new MockPlatformAdapter() )->run( self::PLATFORM, false, '{"entity":"nope","offset":0}' );
	}

	public function test_a_platform_without_the_pref_id_scheme_is_rejected(): void {
		$this->expectException( UnsupportedOperationException::class );

		$this->tool( new MockPlatformAdapter() )->run( 'mock', false );
	}

	// ---- R1 レビュー対応 ---------------------------------------------------------------------

	public function test_a_stale_legacy_value_of_another_prefecture_is_not_reported_as_already_correct(): void {
		// 旧コード時代に pref_id=4 で取り込まれて JP04 が残っているが、その後 ASP 側で東京（pref_id=13。表の固定点）へ
		// 引っ越した顧客。JP04 は「旧バグが出力しうる値」なので照会されるが、13 の新旧は同じ値（JP13）のため、
		// 以前は郵便番号の確認にも到達せず「正常」と断定していた。実際には Woo 側は宮城（JP04）のまま。
		$customer = $this->customer_model( 'C1', 13 );
		$user_id  = $this->import_customer( $customer );
		update_user_meta( $user_id, 'billing_state', 'JP04' );
		update_user_meta( $user_id, 'shipping_state', 'JP04' );

		$result = $this->tool( new MockPlatformAdapter( [], [ $customer ] ) )->run( self::PLATFORM, true );

		$this->assertSame( 1, $result['counts']['customer']['unverified'] );
		$this->assertSame( 0, $result['counts']['customer']['already_correct'] );
		$this->assertSame( 'JP04', $this->user_state( $user_id, 'billing' ), '断定できないので書き換えない' );
	}

	public function test_the_original_value_is_kept_in_the_audit_when_a_side_is_repaired_again(): void {
		$first   = $this->customer_model( 'C1', 4 );
		$user_id = $this->import_customer( $first );
		$this->make_customer_legacy( $user_id, 4 );
		$this->tool( new MockPlatformAdapter( [], [ $first ] ) )->run( self::PLATFORM, true );

		// その後 ASP 側で静岡（pref_id=19）へ変わり、Woo 側は旧出力（JP19）の状態になった顧客を再度補正する。
		$moved = $this->customer_model( 'C1', 19 );
		update_user_meta( $user_id, 'billing_state', 'JP19' );
		update_user_meta( $user_id, 'shipping_state', 'JP19' );
		$this->tool( new MockPlatformAdapter( [], [ $moved ] ) )->run( self::PLATFORM, true );

		$audit = json_decode( (string) get_user_meta( $user_id, PrefStateRepair::AUDIT_META, true ), true );
		$this->assertSame( 'JP04', $audit['billing']['from'], '最初の補正前の値（本当の元の値）を失わない' );
		$this->assertSame( 'JP22', $audit['billing']['to'] );
	}

	public function test_a_record_that_cannot_be_saved_is_skipped_without_aborting_the_run(): void {
		$first  = $this->order_model( '5201', 4, 4 );
		$second = $this->order_model( '5202', 4, 4 );
		$id1    = $this->import_order( $first );
		$id2    = $this->import_order( $second );
		$this->make_order_legacy( $id1, 4, 4 );
		$this->make_order_legacy( $id2, 4, 4 );

		// 他プラグインのフックが保存時に例外を投げる状況（1件目だけ）。`WC_Abstract_Order::save()` はこの例外を
		// 握りつぶして成功のように ID を返すため、ツールは書き込めたことを読み直して確認し、走査全体を
		// 落とさず次の行へ進む（確認が無いと、保存に失敗したのに「補正した」と数えてしまう）。
		$thrown = false;
		add_action(
			'woocommerce_before_order_object_save',
			static function ( $order ) use ( &$thrown, $id1 ): void {
				if ( ! $thrown && $order instanceof WC_Order && $order->get_id() === $id1 ) {
					$thrown = true;

					throw new RuntimeException( 'save blocked by another plugin' );
				}
			}
		);

		$result = $this->tool( new MockPlatformAdapter( [], [], [ $first, $second ] ) )->run( self::PLATFORM, true );

		$this->assertNull( $result['interruption'] );
		$this->assertNull( $result['cursor'] );
		$this->assertSame( 1, $result['counts']['order']['skipped'], '保存に失敗した1件は skipped' );
		$this->assertSame( 1, $result['counts']['order']['fixed'], '後続の受注は補正される' );
		$this->assertSame( 'JP04', $this->order( $id1 )->get_billing_state() );
		$this->assertSame( 'JP05', $this->order( $id2 )->get_billing_state() );
	}

	/**
	 * 実 `ColorMeAdapter`（HTTP のみモック）→ 実 `OrderWriter`/`CustomerWriter` → ツールを通しで走らせる。
	 * ツールの判定は `postal`/`address1` が Transformer → Canonical → `AddressMapper::to_woo()` の経路で
	 * Writer が保存した値と一致することに依存する。Canonical を手で組み立てるテストでは、この層間のキー名の
	 * ズレ（1つでもずれると全件が `unverified` に倒れて1件も直らない）を検出できない。
	 */
	public function test_repairs_through_the_real_colorme_adapter_and_writers(): void {
		$token_store = new TokenStore( 'colorme-pref-repair-it' );
		$token_store->save( [ 'access_token' => 'token' ] );

		// フィクスチャの pref_id=13（東京）は表の固定点で何も検証できないため、固定点でない値へ差し替える。
		$sale                                = FixtureLoader::load( 'colorme', 'sale_bank_detail' );
		$sale['sale']['customer']['pref_id'] = 4;
		$sale['sale']['sale_deliveries'][0]['pref_id'] = 5;
		$customer                                      = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$customer['member']                            = true;
		$customer['pref_id']                           = 19;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, string $url ) use ( $sale, $customer ) {
				$body = null;

				if ( str_contains( $url, 'payments.json' ) ) {
					$body = FixtureLoader::load( 'colorme', 'payments' );
				} elseif ( str_contains( $url, 'deliveries.json' ) ) {
					$body = FixtureLoader::load( 'colorme', 'deliveries' );
				} elseif ( str_contains( $url, 'sales/219293424.json' ) ) {
					$body = $sale;
				} elseif ( str_contains( $url, 'customers/175271257.json' ) ) {
					$body = [ 'customer' => $customer ];
				}

				if ( null === $body ) {
					return new WP_Error( 'unexpected_request', "Unhandled request: {$url}" );
				}

				return [
					'response' => [
						'code'    => 200,
						'message' => 'OK',
					],
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => (string) wp_json_encode( $body ),
				];
			},
			10,
			3
		);

		$adapter = new ColorMeAdapter( $token_store );

		// ASP から取り込む（実 Transformer の出力）→ 実 Writer で保存 → mapping。
		$order = $adapter->fetch_order_by_remote_id( '219293424' );
		$this->assertInstanceOf( CanonicalOrder::class, $order );
		$order_id = $this->import_order( $order );

		$imported = $adapter->fetch_customer_by_remote_id( '175271257' );
		$this->assertInstanceOf( CanonicalCustomer::class, $imported );
		$user_id = $this->import_customer( $imported );

		// 現行コードで取り込んだ直後は正しい値になっている（請求先は秋田、配送先は宮城、顧客は静岡）。
		$this->assertSame( 'JP05', $this->order( $order_id )->get_billing_state() );
		$this->assertSame( 'JP04', $this->order( $order_id )->get_shipping_state() );
		$this->assertSame( 'JP22', $this->user_state( $user_id, 'billing' ) );

		// 旧コード（恒等変換）の出力へ戻す。
		$this->make_order_legacy( $order_id, 4, 5 );
		$this->make_customer_legacy( $user_id, 19 );

		$tool = new PrefStateRepair( $this->mappings, $adapter );

		$scan = $tool->run( self::PLATFORM, false );
		$this->assertSame( 1, $scan['counts']['customer']['fixed'] );
		$this->assertSame( 1, $scan['counts']['order']['fixed'] );
		$this->assertSame( 0, $scan['counts']['customer']['unverified'] + $scan['counts']['order']['unverified'], '実データの postal/address1 が期待値と一致する' );

		$repair = $tool->run( self::PLATFORM, true );
		$this->assertSame( 1, $repair['counts']['customer']['fixed'] );
		$this->assertSame( 1, $repair['counts']['order']['fixed'] );
		$this->assertSame( 'JP05', $this->order( $order_id )->get_billing_state() );
		$this->assertSame( 'JP04', $this->order( $order_id )->get_shipping_state() );
		$this->assertSame( 'JP22', $this->user_state( $user_id, 'billing' ) );
		$this->assertSame( 'JP22', $this->user_state( $user_id, 'shipping' ) );

		$token_store->delete();
	}
}
