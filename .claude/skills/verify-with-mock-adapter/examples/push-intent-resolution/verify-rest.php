<?php
// 例（issue #73 / D21-B push intent）: 「作成結果が不明な export → 印（push intent）が残る → 再 export はブロック → 解除 → 再 export」を
// rest_do_request() で通し、各ステップを PASS/FAIL で出す。失敗が1つでもあれば非ゼロで終了する（seed は不要。push の切替は下で自分で行う）。
//
// 前提:
//   - mock を非衝突キーで登録済み: mock-adapter.sh install mockv（実 platform の mapping に触れないため colorme では登録しない）
//   - dev サイトに export できる Woo 商品が 1 件以上ある（export できる商品はすべて対象になる。実 platform の
//     mapping が付いていても、mockv 側は空なので未 export 扱いになる）
//   - 前回の残りが無い（あれば cleanup.php を先に流す。残りがあると結果が変わるので、この先頭で中止する）
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/push-intent-resolution/verify-rest.php
// 続けて: cleanup.php → mock-adapter.sh uninstall → mock-adapter.sh inspect（検証前と同じか確認）
//
// 確認しないこと: `link` の成功系統（実在確認・別実体で使用中の remote_id の 409）。mock は商品を保持しないため、実在確認は常に
// 「見つからない」になる。そちらは PushIntentResolverTest／RestControllerTest（単体）の担当。
use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;

wp_set_current_user( 1 );

$platform = 'mockv'; // install に渡したキーと同じにする。
$failures = 0;

$check = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";

	if ( ! $ok ) {
		++$failures;
	}
};

// クエリ文字列をルートに含めると rest_no_route になるので、GET は set_query_params()、POST は set_body_params()。
$call = static function ( string $method, string $route, array $params = [] ): array {
	$request = new WP_REST_Request( $method, $route );

	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}

	$response = rest_do_request( $request );

	return [
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	];
};

// 一覧の取得が失敗したときに「印が無い」と読み違えて PASS しないよう、200 以外は中止する。
$intents_now = static function () use ( $call, $platform ): array {
	$result = $call( 'GET', "/cbjp/v1/push-intents/{$platform}" );

	if ( 200 !== $result['status'] || ! is_array( $result['data']['intents'] ?? null ) ) {
		echo 'ABORT: GET /push-intents failed: ' . wp_json_encode( $result ) . "\n";
		exit( 1 );
	}

	return $result['data']['intents'];
};

// product の実 export を1回走らせ、その run の product ジョブの totals を返す。
//   - seed を書き換えたら AdapterRegistry::reset_cache() が必須（`all()` は静的キャッシュで、同一プロセスでは最初に組み立てた mock が残る）
//   - `cbjp_process_job`（Action Scheduler）は CLI では自走しないので、pending を自分で処理する。**この run のジョブだけ**を job_id で引いて処理する
//     （サイト全体の pending を流すと、他 platform〔colorme 等〕のジョブを同期実行して実 API を叩きかねない）。20 回で処理し切れなければエラーにする
$run_export = static function ( array $seed ) use ( $call, $platform ): array {
	// `cbjp_verify_seed` は他の example と共有する（customers/orders など）ので、丸ごと上書きせず `push` キーだけを差し替える。
	$current = get_option( 'cbjp_verify_seed', [] );
	$current = is_array( $current ) ? $current : [];
	unset( $current['push'] );

	if ( is_array( $seed['push'] ?? null ) ) {
		$current['push'] = $seed['push'];
	}

	update_option( 'cbjp_verify_seed', $current );
	AdapterRegistry::reset_cache();

	$started = $call(
		'POST',
		'/cbjp/v1/runs',
		[
			'type'                         => 'export',
			'platform'                     => $platform,
			'entities'                     => [ 'product' ],
			'acknowledge_production_write' => true,
		]
	);
	$run_id  = $started['data']['run_id'] ?? null;

	if ( ! is_string( $run_id ) ) {
		return [ 'error' => 'start_run failed: ' . wp_json_encode( $started ) ];
	}

	$queued  = $call( 'GET', "/cbjp/v1/runs/{$run_id}" );
	$job_ids = array_map( static fn ( array $job ): int => (int) ( $job['id'] ?? 0 ), $queued['data']['jobs'] ?? [] );
	$drained = false;

	for ( $i = 0; $i < 20 && ! $drained; $i++ ) {
		$drained = true;

		foreach ( $job_ids as $job_id ) {
			$pending = as_get_scheduled_actions(
				[
					'hook'     => 'cbjp_process_job',
					'args'     => [ 'job_id' => $job_id ],
					'status'   => 'pending',
					'per_page' => 10,
				]
			);

			foreach ( $pending as $action_id => $action ) {
				$drained = false;
				do_action_ref_array( $action->get_hook(), $action->get_args() );
				ActionScheduler_Store::instance()->mark_complete( (string) $action_id );
			}
		}
	}

	if ( ! $drained ) {
		return [ 'error' => 'the run still had pending jobs after 20 rounds' ];
	}

	$run = $call( 'GET', "/cbjp/v1/runs/{$run_id}" );

	return $run['data']['jobs'][0]['totals'] ?? [ 'error' => 'no job totals: ' . wp_json_encode( $run ) ];
};

$abort = static function ( string $message, int $code ): void {
	echo "ABORT: {$message}\n";
	exit( $code );
};

// ---- 0) 前提 ----
// `acknowledge_production_write=true` の実 export を走らせるので、mock アダプタが登録され、かつ OAuth トークンが無い platform だけを許す
// （実アダプタに差し替わっている・トークンが残っている platform では、実店舗へ書き込みうるため run を始める前に拒否する。cleanup.php と同じ判定）。
if ( false !== get_option( 'cbjp_token_' . $platform, false ) || ! AdapterRegistry::get( $platform ) instanceof MockPlatformAdapter ) {
	$abort( "'{$platform}' is not a registered mock adapter without a stored token — run: mock-adapter.sh install {$platform} (use a key that no real platform uses)", 2 );
}

// `cbjp_verify_seed` は共有オプション。配列でない壊れた値を上書きして失わないよう、始める前に止める（cleanup.php が壊れた値を消す）。
if ( ! is_array( get_option( 'cbjp_verify_seed', [] ) ) ) {
	$abort( 'option cbjp_verify_seed is not an array — run cleanup.php first (it removes the broken value), then retry', 2 );
}

global $wpdb;
$repo     = new PushIntentRepository();
// cleanup.php は `$platform` の intent・mapping・job・ログをすべて消すので、始める前にそれらが残っていたら（別の検証の残りを巻き込まないよう）先に止める。
// ログは cleanup.php と同じ条件（job_id が無く、context に platform を持つ行。resolve の操作ログ）で数える。job に紐づくログは job の数で足りる。
$log_like = '%' . $wpdb->esc_like( '"platform":"' . $platform . '"' ) . '%';
$leftover = count( $repo->find_unresolved( $platform ) )
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_mappings WHERE platform = %s", $platform ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs WHERE platform = %s", $platform ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_logs WHERE job_id IS NULL AND context_json LIKE %s", $log_like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( $leftover > 0 ) {
	$abort( "'{$platform}' already has intents, mappings, jobs or logs ({$leftover}) — if they are leftovers of this example, run cleanup.php first, then retry", 2 );
}

// ---- 1) push 無効: 送信されず、印も残らない ----
$r = $run_export( [] );
$check( '1 前提: export 対象の商品がある', (int) ( $r['processed'] ?? 0 ) >= 1, wp_json_encode( $r ) );

if ( 0 === (int) ( $r['processed'] ?? 0 ) ) {
	$abort( 'no exportable product was processed — create a simple product on the dev site first', 2 );
}

$check( '1 push 無効（UnsupportedOperationException）: 作成されず、印も残らない', 0 === (int) ( $r['created'] ?? -1 ) && [] === $intents_now(), wp_json_encode( $r ) );

// ---- 2) 作成 POST が 5xx（結果不明）: 印が残る ----
$r       = $run_export(
	[
		'push' => [
			'enabled'        => true,
			'create_failure' => 'ambiguous_5xx',
		],
	]
);
$intents = $intents_now();
$check( '2 作成 POST が 5xx: 何も作成されない', 0 === (int) ( $r['created'] ?? -1 ), wp_json_encode( $r ) );
$check( '2 作成 POST が 5xx: 印（push intent）が残る', count( $intents ) >= 1, 'intents=' . count( $intents ) );
$check( '2 印の reason は ambiguous_error', [] !== $intents && 'ambiguous_error' === ( $intents[0]['reason'] ?? null ) );

if ( [] === $intents ) {
	$abort( 'no push intent was recorded, so the remaining steps cannot run', 1 );
}

$initial = count( $intents );

// ---- 3) 印が残る実体は、再送が成功する状況でも送られない ----
// warned は「ブロックされた」証拠（PUSH_OUTCOME_UNCONFIRMED の警告）。無料版の上限で作成されなかっただけの場合は warned が増えないので、created 0 だけでは区別できない。
// 限界: warned は他の警告（商品データの品質など）も数えるので、それが印の数以上ある環境ではブロックが外れても通りうる。厳密には印のある local_id ごとに確かめる必要がある。
$r = $run_export( [ 'push' => [ 'enabled' => true ] ] ); // 5xx を止める。ブロックが無ければここで作成される。
$check( '3 印が残る実体は再 export でブロックされる（作成 0、印の数以上の警告）', 0 === (int) ( $r['created'] ?? -1 ) && (int) ( $r['warned'] ?? 0 ) >= $initial, wp_json_encode( $r ) );
$check( '3 ブロック中は印の数が変わらない', count( $intents_now() ) === $initial );

// ---- 4) link: 実在しない remote_id は 404（実在確認）。失敗した link では印が消えない ----
// 解除より前に、残っている印の1件目で確かめる（印が1件しか残らない環境でも、この経路を必ず通すため）。
$first = $intents[0];
$res   = $call( 'POST', "/cbjp/v1/push-intents/{$platform}/{$first['id']}/resolve", [ 'action' => 'link', 'remote_id' => 'ZZV-NOT-THERE' ] );
$check( '4 存在しない remote_id の link は 404 cbjp_remote_entity_not_found', 404 === $res['status'] && 'cbjp_remote_entity_not_found' === ( $res['data']['code'] ?? '' ), wp_json_encode( $res ) );
$check( '4 失敗した link では印が消えない', count( $intents_now() ) === $initial );

// ---- 5) not_created で解除 ----
$res = $call( 'POST', "/cbjp/v1/push-intents/{$platform}/{$first['id']}/resolve", [ 'action' => 'not_created' ] );
$check( '5 not_created で解除できる', 200 === $res['status'] && true === ( $res['data']['resolved'] ?? false ), wp_json_encode( $res ) );
$after = $intents_now();
$check( '5 一覧が1件減る', count( $after ) === $initial - 1, 'before=' . $initial . ' after=' . count( $after ) );

// ---- 6) 解除した実体は次の export で作成される ----
$r = $run_export( [ 'push' => [ 'enabled' => true ] ] );
$check( '6 解除した1件だけが次の export で作成される', 1 === (int) ( $r['created'] ?? 0 ), wp_json_encode( $r ) );
$check( '6 解除していない実体は引き続きブロックされる（印の数が変わらず、その数以上の警告）', count( $intents_now() ) === $initial - 1 && (int) ( $r['warned'] ?? 0 ) >= $initial - 1, wp_json_encode( $r ) );

echo "\n" . ( 0 === $failures ? 'ALL PASS' : "{$failures} FAILED" ) . "\n";
echo "次: cleanup.php で '{$platform}' の痕跡を撤去し、mock-adapter.sh uninstall → inspect で検証前に戻ったことを確認する\n";
exit( 0 === $failures ? 0 : 1 );
