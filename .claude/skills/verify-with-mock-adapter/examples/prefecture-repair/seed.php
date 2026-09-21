<?php
// 例（issue #46 県コード修復ツール）: 旧コード（PR #44 より前）が書いた state を、実 Writer で再現して投入する。
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/prefecture-repair/seed.php
// 事前: mock-adapter.sh inspect（既存データの確認）→ mock-adapter.sh install colorme
//
// 流れ: 現行の Writer で取り込む（＝正しい state が書かれる）→ 旧コードの出力（`JP{pref_id}` の恒等変換）へ書き戻す。
// 投入した ID は `cbjp_verify_ids`、mock へ渡す ASP 側のデータは `cbjp_verify_seed` に保存する（cleanup.php が使う）。
// 投入する実体の remote_id は必ず `ZZV-` 接頭辞にする（撤去時の目印）。
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\WooRepositoryFactory;
use CartBridgeJP\Woo\Writer\OrderItemBuilder;
use CartBridgeJP\Woo\Writer\OrderWriter;

$platform = 'colorme';
$mappings = new MappingRepository();
$writer   = ( new WooRepositoryFactory() )->for_platform( $platform );
$address  = static fn ( int $pref ): array => [
	'name'      => 'Verify User',
	'email'     => 'verify@example.com',
	'postal'    => '1000001',
	'pref_id'   => $pref,
	'pref_name' => 'Placeholder',
	'address1'  => 'Verify 1-1-1',
	'address2'  => null,
	'tel'       => '0312345678',
	'country'   => 'JP',
];

// 前回の投入が cleanup 前のまま残っていれば、その ID を引き継いで**累積**する（上書きすると前回分が孤立し、
// cleanup が「完全に元へ戻す」約束を守れなくなる）。
$ids  = get_option( 'cbjp_verify_ids', [] ) + [
	'users'  => [],
	'orders' => [],
];
$seed = get_option( 'cbjp_verify_seed', [] ) + [
	'customers' => [],
	'orders'    => [],
];

$specs = [
	[ 'ZZV-C1', 4, 'legacy' ],      // 秋田: 旧出力 JP04 → JP05 に補正されるべき
	[ 'ZZV-C2', 19, 'legacy' ],     // 静岡: 5巡回の一員 JP19 → JP22
	[ 'ZZV-C3', 13, 'legacy' ],     // 東京: 表の固定点。ASP に照会せず「被害なし」と確定する
	[ 'ZZV-C4', 4, 'hand-edited' ], // 手修正（JP20）: 旧出力でも正しい値でもない → 変更せず「確認できなかった」
];

// 投入前の安全確認: seed の email が既存ユーザーのものだと、`CustomerWriter` は email 突合でそのアカウントを
// **再利用**して住所を上書きし（作成マーカーも付かない）、cleanup がそれを消してしまう。既に自分の投入分として
// 記録済みの ID を除き、衝突があれば何も書かずに中止する。
$known_ids = array_map( 'intval', array_values( $ids['users'] ) );

foreach ( $specs as [ $remote ] ) {
	$existing = get_user_by( 'email', strtolower( $remote ) . '@example.com' );

	if ( $existing && ! in_array( (int) $existing->ID, $known_ids, true ) ) {
		echo "ABORT: user #{$existing->ID} already has the seed email for {$remote}; not seeding (it would be reused and overwritten).\n";

		return;
	}
}

foreach ( $specs as [ $remote, $pref, $mode ] ) {
	$email    = strtolower( $remote ) . '@example.com';
	$customer = new CanonicalCustomer( $email, 'Verify User', null, null, null, $address( $pref ), '0312345678', null, null, null, [ 'remote_id' => $remote ] );
	$result   = $writer->write( 'customer', $customer, null );
	$mappings->upsert( $platform, 'customer', $remote, $result->local_id, null );

	$state = 'legacy' === $mode ? sprintf( 'JP%02d', $pref ) : 'JP20';
	update_user_meta( $result->local_id, 'billing_state', $state );
	update_user_meta( $result->local_id, 'shipping_state', $state );

	$ids['users'][ $remote ]      = $result->local_id;
	$seed['customers'][ $remote ] = [
		'remote_id' => $remote,
		'email'     => $email,
		'pref'      => $pref,
	];
}

$order_writer = new OrderWriter( $platform, $mappings, new OrderItemBuilder( new ProductResolver( $platform, $mappings ) ), new MethodMap( $platform ) );
$order        = new CanonicalOrder(
	'ZZV-O1',
	'processing',
	null,
	[],
	$address( 16 ),
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
		'remote_id'         => 'ZZV-O1',
		'customer_snapshot' => $address( 5 ),
	]
);
// `OrderWriter::write()` は既存の local_id を渡さない限り毎回**新規作成**する（Importer は mapping から引いて渡す）。
// 再実行時に受注が増えて孤立しないよう、前回の投入分の ID を mapping から引いて渡し、同じ受注を更新する。
$existing_order_id = $mappings->find_local_id( $platform, 'order', 'ZZV-O1' );
$result            = $order_writer->write( $order, $existing_order_id );
$mappings->upsert( $platform, 'order', 'ZZV-O1', $result->local_id, null );

$wc_order = wc_get_order( $result->local_id );
$wc_order->set_billing_state( 'JP05' );  // 請求先 pref 5（宮城）の旧出力
$wc_order->set_shipping_state( 'JP16' ); // 配送先 pref 16（福井）の旧出力
$wc_order->save();

$ids['orders']['ZZV-O1']  = $result->local_id;
$seed['orders']['ZZV-O1'] = [
	'number'        => 'ZZV-O1',
	'billing_pref'  => 5,
	'shipping_pref' => 16,
];

update_option( 'cbjp_verify_seed', $seed, false );
update_option( 'cbjp_verify_ids', $ids, false );

echo wp_json_encode( $ids ) . "\n";
