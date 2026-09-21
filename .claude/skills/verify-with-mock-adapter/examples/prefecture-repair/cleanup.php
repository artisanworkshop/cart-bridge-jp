<?php
// 例（issue #46 県コード修復ツール）: seed.php が投入したものだけを撤去する。
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/prefecture-repair/cleanup.php
// 続けて: mock-adapter.sh uninstall → mock-adapter.sh inspect（検証前と同じか確認）
//
// 対象は `cbjp_verify_ids` に記録した ID と `ZZV-` 接頭辞の remote_id だけ。実 platform の既存行・他のユーザーには触れない。
require_once ABSPATH . 'wp-admin/includes/user.php';

use CartBridgeJP\Sync\MappingRepository;

$platform = 'colorme';
$ids      = get_option( 'cbjp_verify_ids', [] );
$mappings = new MappingRepository();

$kept = [];

foreach ( $ids['users'] ?? [] as $remote => $uid ) {
	// 削除してよいのは、シードの import が**新規作成した**アカウントだけ（`CustomerWriter` は新規作成時にだけ
	// `_cbjp_created_by_import` を書く）。email 突合で再利用された既存アカウントには付かないので、ここで弾く。
	if ( $platform === get_user_meta( (int) $uid, '_cbjp_created_by_import', true ) ) {
		wp_delete_user( (int) $uid );
	} elseif ( get_userdata( (int) $uid ) ) {
		$kept[ $remote ] = (int) $uid;
	}

	$mappings->delete_one( $platform, 'customer', $remote );
}

foreach ( $ids['orders'] ?? [] as $number => $oid ) {
	$order = wc_get_order( (int) $oid );

	if ( $order ) {
		$order->delete( true );
	}

	$mappings->delete_one( $platform, 'order', $number );
}

if ( [] !== $kept ) {
	// 作成マーカーの無いアカウントは削除しない。記録を残して、人が確認できるようにする。
	update_option( 'cbjp_verify_ids', [ 'users' => $kept, 'orders' => [] ], false );
	echo 'KEPT (not created by the seed import, not deleted): ' . wp_json_encode( $kept ) . "\n";
} else {
	delete_option( 'cbjp_verify_seed' );
	delete_option( 'cbjp_verify_ids' );
}

global $wpdb;
$table = $wpdb->prefix . 'cbjp_mappings';
echo 'remaining ZZV mappings: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE remote_id LIKE 'ZZV-%'" ) . "\n";
echo 'verify options left: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'cbjp_verify_%'" ) . "\n";
