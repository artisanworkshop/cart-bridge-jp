<?php
/**
 * run を REST（`POST /runs`）で始め、その run のジョブだけを同期で最後まで処理して結果を出す。
 * 引数: shop=<login_id> type=<dry_run|import|dry_run_export|export> [entities=a,b,...] [cancel-after=<n>] [max-minutes=<n>] [attach=<run_id>] [context=cron|admin]
 *
 * - export は管理画面と同じく `acknowledge_production_write=true` を付ける（REST の検証を通す）。
 * - ジョブは Action Scheduler の claim を取ってから処理する（`ActionScheduler::runner()->process_action()`）。管理画面を開いたままでも、
 *   WP-Cron／非同期のキューランナーと同じアクションを二重に処理しない。claim に他の run のアクションが混ざったら、そのジョブが開いていれば手放し、
 *   閉じている（完了・失敗・キャンセル）か存在しなければ処理して片付ける（`process_job()` は何もせずに戻る。残ったアクションで自分の番が来なくなるのを防ぐ）。
 * - レート制限で paused になったジョブは、再開の予定時刻まで待ってから続ける（`max-minutes` で打ち切る。既定 40 分）。
 * - `cancel-after=<n>`: n ページ（アクション）を処理したら `POST /runs/{id}/cancel` して止める（キャンセル→再実行の試験用）。
 * - `attach=<run_id>`: 新しく始めず、途中で止まった同じ種別の run を続きから処理する（max-minutes で打ち切った・スクリプトが異常終了した run）。
 * - `context=cron`（既定）: ジョブは未ログイン＋kses ありで処理する（WP-Cron と同じ）。`context=admin` は管理者として処理する（管理画面から非同期ランナーが動く場合と同じ）。
 *   どちらで処理されるかで商品名・説明の保存結果が変わる（R3-1 で実測）。
 * - dry-run は明細（`cbjp_dry_run_items`）を `.rehearsal/<run_id>-items.json` に保存し、entity×操作と警告コードの件数を出す。
 * - import は `GET /runs/{id}/verification`（件数・受注合計の突合）も出す。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\JobManager;

// REST（manage_woocommerce が要る）を呼ぶ管理者。ID を決め打ちせず、最初の管理者を使う（ジョブの処理後もこのユーザーに戻す）。
$cbjp_admins = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);

if ( [] === $cbjp_admins ) {
	cbjp_rh_abort( 'no administrator user on this site' );
}

$cbjp_admin_id = (int) $cbjp_admins[0];
wp_set_current_user( $cbjp_admin_id );

global $wpdb;

$cbjp_opts = cbjp_rh_args( $args, [ 'shop', 'type', 'entities', 'cancel-after', 'max-minutes', 'attach', 'context' ] );
cbjp_rh_require_shop( $cbjp_opts );

$cbjp_type = $cbjp_opts['type'] ?? '';

if ( ! in_array( $cbjp_type, [ JobManager::TYPE_DRY_RUN, JobManager::TYPE_IMPORT, JobManager::TYPE_DRY_RUN_EXPORT, JobManager::TYPE_EXPORT ], true ) ) {
	cbjp_rh_abort( 'type must be dry_run, import, dry_run_export or export' );
}

$cbjp_is_export  = in_array( $cbjp_type, [ JobManager::TYPE_DRY_RUN_EXPORT, JobManager::TYPE_EXPORT ], true );
$cbjp_is_dry_run = in_array( $cbjp_type, [ JobManager::TYPE_DRY_RUN, JobManager::TYPE_DRY_RUN_EXPORT ], true );
$cbjp_entities   = isset( $cbjp_opts['entities'] )
	? array_values( array_filter( array_map( 'trim', explode( ',', $cbjp_opts['entities'] ) ) ) )
	: ( $cbjp_is_export ? [ 'product', 'customer', 'order', 'stock' ] : [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon' ] );

foreach ( [ 'cancel-after', 'max-minutes' ] as $cbjp_key ) {
	if ( isset( $cbjp_opts[ $cbjp_key ] ) && 1 !== preg_match( '/\A[1-9][0-9]{0,3}\z/', $cbjp_opts[ $cbjp_key ] ) ) {
		cbjp_rh_abort( "{$cbjp_key} must be a positive integer" );
	}
}

$cbjp_cancel_after = isset( $cbjp_opts['cancel-after'] ) ? (int) $cbjp_opts['cancel-after'] : null;
$cbjp_deadline     = time() + 60 * ( isset( $cbjp_opts['max-minutes'] ) ? (int) $cbjp_opts['max-minutes'] : 40 );
$cbjp_context      = $cbjp_opts['context'] ?? 'cron';

if ( ! in_array( $cbjp_context, [ 'cron', 'admin' ], true ) ) {
	cbjp_rh_abort( 'context must be cron or admin' );
}

// ジョブを処理するときのユーザー（REST の呼び出しは manage_woocommerce が要るので管理者のまま）。
// - cron（既定）: WP-Cron（未ログインの HTTP リクエスト）と同じ。投稿の保存に kses がかかる（商品名の `&` が `&amp;` になり、
//   許可されないタグが除かれる）。WP-CLI は `--user` が無いと `init` の優先度 11 に `kses_remove_filters` を登録して kses を外す（実測）ので、
//   ここで明示的に登録し直す（`wp action-scheduler run` で処理した場合は、この理由で kses がかからない＝admin と同じ結果になる）。
// - admin: 管理画面を開いている間に Action Scheduler の非同期ランナーが処理する場合（管理者の Cookie を転送するので unfiltered_html あり）。
$cbjp_enter_context = static function () use ( $cbjp_context ): void {
	if ( 'cron' === $cbjp_context ) {
		wp_set_current_user( 0 );
		kses_init_filters();
	}
};
$cbjp_leave_context = static function () use ( $cbjp_context, $cbjp_admin_id ): void {
	if ( 'cron' === $cbjp_context ) {
		kses_remove_filters();
		wp_set_current_user( $cbjp_admin_id );
	}
};

$cbjp_params = [
	'type'     => $cbjp_type,
	'platform' => 'colorme',
	'entities' => $cbjp_entities,
];

if ( JobManager::TYPE_EXPORT === $cbjp_type ) {
	$cbjp_params['acknowledge_production_write'] = true;
}

if ( isset( $cbjp_opts['attach'] ) ) {
	// 途中で止まった run（max-minutes・スクリプトの異常終了）を、新しく始めずに続きから処理する。種別と platform が一致するものだけ。
	$cbjp_run_id = $cbjp_opts['attach'];
	$cbjp_found  = $wpdb->get_row( $wpdb->prepare( "SELECT type, platform FROM {$wpdb->prefix}cbjp_jobs WHERE run_id = %s LIMIT 1", $cbjp_run_id ), ARRAY_A );

	if ( ! is_array( $cbjp_found ) || $cbjp_found['type'] !== $cbjp_type || 'colorme' !== $cbjp_found['platform'] ) {
		cbjp_rh_abort( "no colorme run of type {$cbjp_type} with run_id {$cbjp_run_id}" );
	}
} else {
	$cbjp_started = cbjp_rh_rest( 'POST', '/cbjp/v1/runs', $cbjp_params );
	$cbjp_run_id  = is_array( $cbjp_started['data'] ) ? ( $cbjp_started['data']['run_id'] ?? null ) : null;

	if ( ! is_string( $cbjp_run_id ) ) {
		cbjp_rh_abort( "POST /runs failed ({$cbjp_started['status']}): " . wp_json_encode( $cbjp_started['data'], JSON_UNESCAPED_UNICODE ) );
	}
}

$cbjp_t0 = microtime( true );
echo "run_id={$cbjp_run_id} type={$cbjp_type} context={$cbjp_context} entities=" . implode( ',', $cbjp_entities ) . "\n";

$cbjp_run = static function () use ( $cbjp_run_id ): array {
	$result = cbjp_rh_rest( 'GET', "/cbjp/v1/runs/{$cbjp_run_id}" );

	if ( 200 !== $result['status'] || ! is_array( $result['data']['jobs'] ?? null ) ) {
		cbjp_rh_abort( 'GET /runs/{id} failed: ' . wp_json_encode( $result ) );
	}

	return $result['data']['jobs'];
};

$cbjp_job_ids = array_map( static fn ( array $job ): int => (int) $job['id'], $cbjp_run() );
$cbjp_store   = ActionScheduler::store();
$cbjp_runner  = ActionScheduler::runner();
$cbjp_done    = 0;
$cbjp_waited  = 0;
$cbjp_orphaned = 0;

while ( true ) {
	$cbjp_open = array_filter( $cbjp_run(), static fn ( array $job ): bool => in_array( $job['status'], [ 'pending', 'running', 'paused' ], true ) );

	if ( [] === $cbjp_open ) {
		break;
	}

	if ( time() > $cbjp_deadline ) {
		echo "STOP: max-minutes reached with open jobs; the run is left as it is (cancel it on the Tools tab if needed).\n";
		break;
	}

	// グループでは絞らない: 移行途中の HybridStore は旧ストア（wpPostStore）にも claim を問い合わせ、そのグループの term が
	// 無いと InvalidArgumentException を投げる（実測）。フックで絞り、下でジョブ ID を照合する（他の run のアクションは上のコメントの扱い）。
	$cbjp_claim     = $cbjp_store->stake_claim( 20, null, [ JobManager::ACTION_HOOK ] );
	$cbjp_processed = 0;

	foreach ( $cbjp_claim->get_actions() as $cbjp_action_id ) {
		$cbjp_action = $cbjp_store->fetch_action( (string) $cbjp_action_id );
		$cbjp_job_id = (int) ( $cbjp_action->get_args()['job_id'] ?? 0 );

		if ( ! in_array( $cbjp_job_id, $cbjp_job_ids, true ) ) {
			// 他の run のアクション。ジョブが既に閉じている（キャンセル・完了・失敗）か存在しないなら、`JobManager::process_job()` は
			// 何もせずに戻るので処理して片付ける（キャンセルした run のアクションが残ると、毎回それだけを claim して自分のアクションに届かない）。
			// 開いているジョブのものは手放す（他の run を巻き込まない）。
			// `get_var()` は値が空文字の行でも null を返し「行が無い」と区別できない（CLAUDE.md）ので、`get_row()` で行の有無を見る。
			$cbjp_other = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cbjp_jobs WHERE id = %d", $cbjp_job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( null === $cbjp_other || in_array( $cbjp_other['status'] ?? null, [ 'completed', 'failed', 'cancelled' ], true ) ) {
				$cbjp_runner->process_action( (int) $cbjp_action_id, 'CBJP Rehearsal (stale)' );
			} else {
				$cbjp_store->unclaim_action( (string) $cbjp_action_id );
			}

			continue;
		}

		if ( null !== $cbjp_cancel_after && $cbjp_done >= $cbjp_cancel_after ) {
			$cbjp_store->unclaim_action( (string) $cbjp_action_id );
			continue;
		}

		$cbjp_enter_context();
		try {
			$cbjp_runner->process_action( (int) $cbjp_action_id, 'CBJP Rehearsal' );
		} finally {
			$cbjp_leave_context();
		}
		++$cbjp_done;
		++$cbjp_processed;
	}

	$cbjp_store->release_claim( $cbjp_claim );

	if ( null !== $cbjp_cancel_after && $cbjp_done >= $cbjp_cancel_after ) {
		$cbjp_cancel = cbjp_rh_rest( 'POST', "/cbjp/v1/runs/{$cbjp_run_id}/cancel" );
		echo "cancelled after {$cbjp_done} page(s): HTTP {$cbjp_cancel['status']}\n";
		break;
	}

	if ( 0 === $cbjp_processed ) {
		// 次のアクションがまだ予定時刻前（paused からの再開待ち）か、他のランナーが処理中なら、少し待って見直す。
		// この run のジョブのアクションが 1 つも無い（pending も in-progress も無い）のにジョブが開いたままなら、待っても進まない
		// （アクションを失ったジョブ）。3 回続いたら max-minutes まで待たずに止める。
		$cbjp_live = 0;

		foreach ( $cbjp_job_ids as $cbjp_job_id ) {
			foreach ( [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ] as $cbjp_status ) {
				$cbjp_live += count(
					as_get_scheduled_actions(
						[
							'hook'     => JobManager::ACTION_HOOK,
							'args'     => [ 'job_id' => $cbjp_job_id ],
							'status'   => $cbjp_status,
							'per_page' => 1,
						],
						'ids'
					)
				);
			}
		}

		$cbjp_orphaned = 0 === $cbjp_live ? $cbjp_orphaned + 1 : 0;

		if ( $cbjp_orphaned >= 3 ) {
			echo "STOP: jobs are open but have no pending or running action; the run cannot progress (cancel it on the Tools tab).\n";
			break;
		}

		sleep( 5 );
		$cbjp_waited += 5;
	}
}

$cbjp_elapsed = round( microtime( true ) - $cbjp_t0, 1 );
echo "processed {$cbjp_done} page(s) in {$cbjp_elapsed}s (waited {$cbjp_waited}s)\n\n";

printf( "%-5s %-9s %-10s %s\n", 'job', 'entity', 'status', 'totals / error' );
foreach ( $cbjp_run() as $cbjp_job ) {
	printf(
		"%-5d %-9s %-10s %s%s\n",
		$cbjp_job['id'],
		$cbjp_job['entity'],
		$cbjp_job['status'],
		wp_json_encode( $cbjp_job['totals'] ),
		null !== $cbjp_job['error'] ? ' ERROR ' . wp_json_encode( $cbjp_job['error'], JSON_UNESCAPED_UNICODE ) : ''
	);
}

$cbjp_in   = implode( ',', array_map( 'intval', $cbjp_job_ids ) );
$cbjp_logs = $wpdb->get_results( "SELECT job_id, level, message, context_json FROM {$wpdb->prefix}cbjp_logs WHERE job_id IN ({$cbjp_in}) AND level IN ('warning','error') ORDER BY id", ARRAY_A ); // phpcs:ignore

if ( [] !== $cbjp_logs ) {
	echo "\n-- warning/error logs (" . count( $cbjp_logs ) . ") --\n";
	foreach ( array_slice( $cbjp_logs, 0, 60 ) as $cbjp_log ) {
		echo "[{$cbjp_log['level']}] job={$cbjp_log['job_id']} {$cbjp_log['message']} {$cbjp_log['context_json']}\n";
	}
}

if ( $cbjp_is_dry_run ) {
	$cbjp_items = ( new DryRunItemRepository() )->list_after( $cbjp_run_id, 0, 100000 );
	$cbjp_ops   = [];
	$cbjp_codes = [];

	foreach ( $cbjp_items as $cbjp_item ) {
		$cbjp_ops[ "{$cbjp_item['entity']} {$cbjp_item['operation']}" ] = ( $cbjp_ops[ "{$cbjp_item['entity']} {$cbjp_item['operation']}" ] ?? 0 ) + 1;
		$cbjp_warnings = json_decode( (string) $cbjp_item['warnings_json'], true );

		foreach ( is_array( $cbjp_warnings ) ? $cbjp_warnings : [] as $cbjp_warning ) {
			$cbjp_code                = "{$cbjp_item['entity']} " . strtok( (string) $cbjp_warning, ':' );
			$cbjp_codes[ $cbjp_code ] = ( $cbjp_codes[ $cbjp_code ] ?? 0 ) + 1;
		}
	}

	ksort( $cbjp_ops );
	ksort( $cbjp_codes );
	$cbjp_path = cbjp_rh_out_dir() . "/{$cbjp_run_id}-items.json";
	cbjp_rh_write_json( $cbjp_path, $cbjp_items );

	echo "\n-- dry-run items: " . count( $cbjp_items ) . " (saved to .rehearsal/{$cbjp_run_id}-items.json) --\n";
	foreach ( $cbjp_ops as $cbjp_key => $cbjp_count ) {
		echo "  {$cbjp_key}: {$cbjp_count}\n";
	}
	echo "-- warning codes --\n";
	foreach ( $cbjp_codes as $cbjp_key => $cbjp_count ) {
		echo "  {$cbjp_key}: {$cbjp_count}\n";
	}
}

if ( JobManager::TYPE_IMPORT === $cbjp_type ) {
	$cbjp_verification = cbjp_rh_rest( 'GET', "/cbjp/v1/runs/{$cbjp_run_id}/verification" );
	echo "\n-- verification (HTTP {$cbjp_verification['status']}) --\n" . wp_json_encode( $cbjp_verification['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
}

echo "\nrun_id={$cbjp_run_id}\n";
