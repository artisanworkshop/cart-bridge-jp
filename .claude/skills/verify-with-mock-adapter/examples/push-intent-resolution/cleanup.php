<?php
// 例（issue #73 / D21-B push intent）: verify-rest.php が作った mock platform の export の痕跡だけを撤去する。
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/push-intent-resolution/cleanup.php
// 続けて: mock-adapter.sh uninstall → mock-adapter.sh inspect（検証前と同じか確認）。`inspect` は logs・jobs・intents を数えないので、
//         この出力の「left」の行（0 であること）も見る。
//
// 対象は下の $platform（install に渡したキーと同じ）の行だけ。**mock アダプタが登録されているときだけ実行する**（許可する側で判定する）:
// OAuth トークンを持つ platform、mock 以外のアダプタが登録されている platform（切断済みの実 platform はトークンを持たない）、
// アダプタが未登録の platform（mock を uninstall した後など、mock かどうか確かめられない）はすべて拒否する。
// mapping・job・印を消すので、`colorme` のような実 platform に向けると実データを壊す。前回が途中で止まった掃除は、`install` し直してから流す。
use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;

global $wpdb;

$platform = 'mockv';
$adapter  = AdapterRegistry::get( $platform );

if ( false !== get_option( 'cbjp_token_' . $platform, false ) || ! $adapter instanceof MockPlatformAdapter ) {
	echo "REFUSED: '{$platform}' is not a registered mock adapter (a stored token, a non-mock adapter, or no adapter at all). Nothing was deleted. If the mock is uninstalled, run: mock-adapter.sh install {$platform} — then cleanup.php, then uninstall.\n";
	exit( 2 );
}

$prefix = $wpdb->prefix;
// resolve の操作ログ（'A push intent was resolved.'）は job_id を持たず、context に platform だけが入る。
$like = '%' . $wpdb->esc_like( '"platform":"' . $platform . '"' ) . '%';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$job_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) ) );
$deleted = [
	'intents'  => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_push_intents WHERE platform = %s", $platform ) ),
	'mappings' => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_mappings WHERE platform = %s", $platform ) ),
	'jobs'     => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) ),
	'logs'     => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_logs WHERE job_id IS NULL AND context_json LIKE %s", $like ) ),
];

if ( [] !== $job_ids ) {
	// ジョブに紐づくログ・dry-run 明細（IN の中身は上で int 化した ID だけ）。
	$in                        = implode( ',', $job_ids );
	$deleted['job_logs']       = (int) $wpdb->query( "DELETE FROM {$prefix}cbjp_logs WHERE job_id IN ({$in})" );
	$deleted['dry_run_items']  = (int) $wpdb->query( "DELETE FROM {$prefix}cbjp_dry_run_items WHERE job_id IN ({$in})" );
}
// phpcs:enable

// `cbjp_verify_seed` は prefecture-repair の example とも共有する（customers/orders）ので、この example が書く `push` キーだけを外す。
// `cbjp_verify_ids` はあちらだけが書く（この example は書かない）ので触らない。
$seed = get_option( 'cbjp_verify_seed', null );

if ( is_array( $seed ) ) {
	unset( $seed['push'] );

	if ( [] === $seed ) {
		delete_option( 'cbjp_verify_seed' );
	} else {
		update_option( 'cbjp_verify_seed', $seed, false );
	}
} elseif ( null !== $seed ) {
	delete_option( 'cbjp_verify_seed' ); // 配列でない壊れた値（mu-plugin は読み飛ばすが、残す理由も無い）。
}

foreach ( [ 'cbjp_export_sample_' . $platform, 'cbjp_sample_' . $platform, 'cbjp_rate_limit_' . $platform ] as $option ) {
	delete_option( $option );
}

echo 'deleted: ' . wp_json_encode( $deleted ) . "\n";
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$left = [
	'intents'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_push_intents WHERE platform = %s", $platform ) ),
	'mappings' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_mappings WHERE platform = %s", $platform ) ),
	'jobs'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) ),
	'logs'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_logs WHERE context_json LIKE %s", $like ) ),
];
// phpcs:enable
echo 'left: ' . wp_json_encode( $left ) . "\n";
exit( 0 === array_sum( $left ) ? 0 : 1 );
