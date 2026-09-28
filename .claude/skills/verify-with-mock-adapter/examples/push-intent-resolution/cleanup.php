<?php
// 例（issue #73 / D21-B push intent）: verify-rest.php が作った mock platform の export の痕跡だけを撤去する。
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/push-intent-resolution/cleanup.php
// 続けて: mock-adapter.sh uninstall → mock-adapter.sh inspect（検証前と同じか確認）
//
// 対象は下の $platform（install に渡したキーと同じ）の行だけ。**OAuth トークンを持つ platform（実 platform）は拒否する**
// （mapping・job・印を消すので、`colorme` のような実 platform に向けると実データを壊す）。前回が途中で止まったときの掃除にも使える。
global $wpdb;

$platform = 'mockv';

if ( false !== get_option( 'cbjp_token_' . $platform, false ) ) {
	echo "REFUSED: '{$platform}' has a stored token (cbjp_token_{$platform}); it looks like a real platform. Nothing was deleted.\n";
	exit( 2 );
}

$prefix = $wpdb->prefix;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$job_ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) ) );
$deleted  = [
	'intents'  => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_push_intents WHERE platform = %s", $platform ) ),
	'mappings' => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_mappings WHERE platform = %s", $platform ) ),
	'jobs'     => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) ),
];

if ( [] !== $job_ids ) {
	// ジョブに紐づくログ・dry-run 明細（job_id で引く。IN の中身は上で int 化した ID だけ）。
	$in                       = implode( ',', $job_ids );
	$deleted['logs']          = (int) $wpdb->query( "DELETE FROM {$prefix}cbjp_logs WHERE job_id IN ({$in})" );
	$deleted['dry_run_items'] = (int) $wpdb->query( "DELETE FROM {$prefix}cbjp_dry_run_items WHERE job_id IN ({$in})" );
}
// phpcs:enable

foreach ( [ 'cbjp_verify_seed', 'cbjp_verify_ids', 'cbjp_export_sample_' . $platform, 'cbjp_sample_' . $platform, 'cbjp_rate_limit_' . $platform ] as $option ) {
	delete_option( $option );
}

echo 'deleted: ' . wp_json_encode( $deleted ) . "\n";
echo 'verify options left: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'cbjp_verify_%'" ) . "\n"; // phpcs:ignore WordPress.DB
