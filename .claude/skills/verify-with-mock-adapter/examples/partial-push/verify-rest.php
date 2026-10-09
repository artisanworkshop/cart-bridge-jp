<?php
// 例（R3-0a / D21-A `PartialPushException`。R3-1 のモック確認）: 「作成は確定したが後続の処理で止まった」export が、
// mapping（checksum=null）を残して次の export を作成ではなく更新にすること（重複作成しないこと）を rest_do_request() で確かめる。
// 実 API では意図的に起こせない。失敗が1つでもあれば非ゼロで終了する（seed は不要。push の切替は下で自分で行う）。
// checksum は確かめない: mockv にはカテゴリ対応（`category_map`）が無く、どの商品も `category_map_unresolved` で checksum が null のまま残る
// （未解決の参照として次回も送り直す既知の挙動）。「作り直していない」は、作成の後で止まった例外だけが渡す固定の remote_id で確かめる。
//
// 前提:
//   - mock を非衝突キーで登録済み: mock-adapter.sh install mockv（実 platform の mapping に触れないため colorme では登録しない）
//   - dev サイトに価格のある公開の単純商品が 2 件以上ある（テンプレートの `partial_*` は remote_id が固定なので、1 回の export で作成経路を
//     通る商品を 1 件に絞る必要がある。R3-6a で無料版のサンプルを外したので、対象以外の export できる商品を先に `ZZV-OTHER-*` の
//     remote_id で結んでおき〔mockv の mapping。撤去で消える〕、更新経路に回す）
//   - 前回の残りが無い（あれば push-intent-resolution/cleanup.php を先に流す。同じキーの intent・mapping・job・ログを消す）
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/partial-push/verify-rest.php
// 続けて: push-intent-resolution/cleanup.php → mock-adapter.sh uninstall → mock-adapter.sh inspect（検証前と同じか確認）
use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\WarningCode;

wp_set_current_user( 1 );

$platform = 'mockv'; // install に渡したキーと同じにする。
$failures = 0;

$check = static function ( string $label, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? 'PASS' : 'FAIL' ) . " {$label}" . ( '' !== $detail ? " — {$detail}" : '' ) . "\n";

	if ( ! $ok ) {
		++$failures;
	}
};

$abort = static function ( string $message, int $code ): void {
	echo "ABORT: {$message}\n";
	exit( $code );
};

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

global $wpdb;

// その local_id の mockv の mapping（無ければ null）。checksum が null かどうかを見る。
$mapping = static function ( int $local_id ) use ( $wpdb, $platform ): ?array {
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT remote_id, checksum FROM {$wpdb->prefix}cbjp_mappings WHERE platform = %s AND entity_type = 'product' AND local_id = %d", $platform, $local_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return is_array( $row ) ? $row : null;
};

// 作成経路を通る商品を `$local_id` の 1 件に絞る: export できる（`ProductReader` が読む）ほかの商品のうち、まだ mockv の mapping が無いものを
// `ZZV-OTHER-{id}` の remote_id で結ぶ（更新経路に回る。mock の更新は失敗しない）。前の手順でこの例が `$local_id` 自身に置いた仮の mapping は外す
// （残すと `$local_id` が更新経路に回り、作成の後で止まる経路を通らない）。結び終えたら数え直し、ほかの商品がすべて mapping を持ち、
// `$local_id` に仮の mapping が無いことを確かめる（書込みが効かないまま進むと、別の商品も作成経路に入って固定の remote_id の検査が意味を
// 失う。G3-B3 と同じ理由）。`$local_id` が前の手順で作成済み（本物の mapping がある）なら、それは更新経路に回ってよい（手順 2）。
$pin_create_to = static function ( int $local_id ) use ( $platform ): bool {
	$mappings = new MappingRepository();

	if ( "ZZV-OTHER-{$local_id}" === $mappings->find_remote_id( $platform, 'product', $local_id ) ) {
		$mappings->delete_one( $platform, 'product', "ZZV-OTHER-{$local_id}" );
	}

	$ids = array_map(
		'intval',
		wc_get_products(
			[
				'status' => ProductReader::EXPORTABLE_STATUSES,
				'type'   => ProductReader::EXPORTABLE_TYPES,
				'limit'  => -1,
				'return' => 'ids',
			]
		)
	);

	foreach ( $ids as $id ) {
		if ( $id !== $local_id && null === $mappings->find_remote_id( $platform, 'product', $id ) ) {
			$mappings->upsert( $platform, 'product', "ZZV-OTHER-{$id}", $id, null );
		}
	}

	foreach ( $ids as $id ) {
		$remote_id = $mappings->find_remote_id( $platform, 'product', $id );

		if ( $id === $local_id ? "ZZV-OTHER-{$local_id}" === $remote_id : null === $remote_id ) {
			return false;
		}
	}

	return in_array( $local_id, $ids, true );
};

// 作成経路を 1 商品に絞り、push の seed を差し替えて product の実 export を 1 回走らせる。この run のジョブだけを処理する
// （paused からの再開予定のアクションも、時刻を待たずに処理する）。job の totals と status、paused のログの有無を返す。
$run_export = static function ( int $local_id, ?string $create_failure ) use ( $call, $platform, $wpdb, $pin_create_to ): array {
	if ( ! $pin_create_to( $local_id ) ) {
		return [ 'error' => "could not narrow the create path to product #{$local_id} (another exportable product still has no mockv mapping, #{$local_id} still has its placeholder mapping, or #{$local_id} is not an exportable product)" ];
	}

	$seed = get_option( 'cbjp_verify_seed', [] );
	$seed = is_array( $seed ) ? $seed : [];

	$seed['push'] = [
		'enabled'        => true,
		'create_failure' => $create_failure,
	];
	update_option( 'cbjp_verify_seed', $seed );

	// 前提の書込みを読み直して確かめる（`update_option()` は同じ値でも false を返すので戻り値では判断しない）。seed が古いままだと、
	// 別の失敗の仕方で export して、固定の remote_id の検査が意味を失う（G3-B3）。
	wp_cache_delete( 'cbjp_verify_seed', 'options' );

	if ( ( get_option( 'cbjp_verify_seed' )['push'] ?? null ) !== $seed['push'] ) {
		return [ 'error' => 'the push seed was not saved as requested' ];
	}

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

	$job_ids = array_map( static fn ( array $job ): int => (int) ( $job['id'] ?? 0 ), $call( 'GET', "/cbjp/v1/runs/{$run_id}" )['data']['jobs'] ?? [] );
	$drained = false;

	for ( $i = 0; $i < 20 && ! $drained; $i++ ) {
		$drained = true;

		foreach ( $job_ids as $job_id ) {
			foreach ( as_get_scheduled_actions( [ 'hook' => 'cbjp_process_job', 'args' => [ 'job_id' => $job_id ], 'status' => 'pending', 'per_page' => 10 ] ) as $action_id => $action ) {
				$drained = false;
				do_action_ref_array( $action->get_hook(), $action->get_args() );
				ActionScheduler_Store::instance()->mark_complete( (string) $action_id );
			}
		}
	}

	if ( ! $drained ) {
		return [ 'error' => 'the run still had pending jobs after 20 rounds' ];
	}

	$job    = $call( 'GET', "/cbjp/v1/runs/{$run_id}" )['data']['jobs'][0] ?? [];
	// 一時停止のログは job_id を文脈（context）にだけ持ち、ログの job_id 列は空（`JobManager::process_job()`）。文脈で探す。
	$paused = 0;

	foreach ( $job_ids as $job_id ) {
		$paused += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_logs WHERE message = 'Job paused: rate limit exhausted.' AND context_json LIKE %s", '%' . $wpdb->esc_like( '"job_id":' . $job_id ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// 作成の後で止まったことの証拠は、`Exporter` がその 1 件について残す警告ログ（`job_id` 列あり）で数える。集計の `warned` は
	// カテゴリ対応の無い mockv では全商品に付く（`category_map_unresolved`）ので、それだけでは区別できない（R1-1）。
	$in          = implode( ',', array_map( 'intval', $job_ids ) );
	$interrupted = '' !== $in ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_logs WHERE job_id IN ({$in}) AND message = 'Push of a product item was interrupted after the remote entity was created.'" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return [
		'totals'      => $job['totals'] ?? [],
		'status'      => $job['status'] ?? null,
		'paused'      => $paused,
		'interrupted' => $interrupted,
	];
};

// ---- 0) 前提 ----
if ( false !== get_option( 'cbjp_token_' . $platform, false ) || ! AdapterRegistry::get( $platform ) instanceof MockPlatformAdapter ) {
	$abort( "'{$platform}' is not a registered mock adapter without a stored token — run: mock-adapter.sh install {$platform} (use a key that no real platform uses)", 2 );
}

if ( ! is_array( get_option( 'cbjp_verify_seed', [] ) ) ) {
	$abort( 'option cbjp_verify_seed is not an array — run push-intent-resolution/cleanup.php first (it removes the broken value), then retry', 2 );
}

$leftover = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_mappings WHERE platform = %s", $platform ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs WHERE platform = %s", $platform ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	+ count( ( new PushIntentRepository() )->find_unresolved( $platform ) )
	// この example が書くもの（mapping・job・intent・`push` キー）と、job_id を持たない platform のログ（前回の resolve の操作ログ）を数える。
	// cleanup.php はほかに `cbjp_rate_limit_{platform}` も消すが、この example は書かないので数えない。
	+ ( is_array( get_option( 'cbjp_verify_seed', [] ) ) && array_key_exists( 'push', (array) get_option( 'cbjp_verify_seed', [] ) ) ? 1 : 0 )
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_logs WHERE job_id IS NULL AND context_json LIKE %s", '%' . $wpdb->esc_like( '"platform":"' . $platform . '"' ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

if ( $leftover > 0 ) {
	$abort( "'{$platform}' already has mappings, jobs, intents, a push seed or platform logs ({$leftover}) — run push-intent-resolution/cleanup.php first, then retry", 2 );
}

$products = array_values(
	array_filter(
		wc_get_products( [ 'type' => 'simple', 'status' => 'publish', 'limit' => 20, 'orderby' => 'ID', 'order' => 'ASC' ] ),
		static fn ( WC_Product $p ): bool => is_numeric( $p->get_regular_price() ) && (float) $p->get_regular_price() > 0
	)
);

if ( count( $products ) < 2 ) {
	$abort( 'need at least 2 published simple products with a price on the dev site', 2 );
}

[ $first, $second ] = [ $products[0]->get_id(), $products[1]->get_id() ];
echo "products: #{$first} / #{$second}\n";

// ---- 1) 作成の後で 5xx: 作成済みの remote_id で mapping を書き、印は残さない ----
$r = $run_export( $first, 'partial_push' );
$m = $mapping( $first );
$check( '1 作成後に止まっても export は完了する', 'completed' === ( $r['status'] ?? null ), wp_json_encode( $r ) );
$check( '1 mapping は書かれ、remote_id は作成済みの ZZV-PARTIAL-API', null !== $m && 'ZZV-PARTIAL-API' === $m['remote_id'], wp_json_encode( $m ) );
$check( '1 作成の後で止まったことを記録する（' . WarningCode::PUSH_INTERRUPTED_AFTER_CREATE . ' の警告ログが 1 件）', 1 === (int) ( $r['interrupted'] ?? 0 ), wp_json_encode( $r ) );
$check( '1 作成結果は確定しているので印（push intent）は残さない', [] === ( new PushIntentRepository() )->find_unresolved( $platform ) );

// ---- 2) 次の export: 作成ではなく更新。remote_id は変わらない ----
$r = $run_export( $first, null );
$m = $mapping( $first );
// ほかの商品も `ZZV-OTHER-*` で結んであるので更新に数えられる。作成が 0 であることだけを確かめる。
$check( '2 次の export は作成せず更新する（重複作成なし）', 0 === (int) ( $r['totals']['created'] ?? -1 ) && (int) ( $r['totals']['updated'] ?? 0 ) >= 1, wp_json_encode( $r['totals'] ?? [] ) );
$check( '2 remote_id は同じ（作成し直していない）', null !== $m && 'ZZV-PARTIAL-API' === $m['remote_id'], wp_json_encode( $m ) );

// ---- 3) 作成の後でレート制限: mapping を書いてから一時停止し、再開すると同じページを更新として送る ----
$r = $run_export( $second, 'partial_rate_limit' );
$m = $mapping( $second );
$check( '3 ジョブはレート制限で一時停止した（paused のログ）', (int) ( $r['paused'] ?? 0 ) >= 1, wp_json_encode( $r ) );
$check( '3 再開後に完了する', 'completed' === ( $r['status'] ?? null ), wp_json_encode( $r ) );
$check( '3 mapping の remote_id は作成済みの ZZV-PARTIAL-RL（作り直していない）', null !== $m && 'ZZV-PARTIAL-RL' === $m['remote_id'], wp_json_encode( $m ) );
// 一時停止したページの集計は再開で失われる（既知: backlog `fix-72-partial-push/R1-L3`）。ほかの商品も更新に数えられるので、作成が 0 であることを確かめる。
$check( '3 再開で同じ商品を更新として送る（作成 0）', 0 === (int) ( $r['totals']['created'] ?? -1 ) && (int) ( $r['totals']['updated'] ?? 0 ) >= 1, wp_json_encode( $r['totals'] ?? [] ) );
$check( '3 印（push intent）は残さない', [] === ( new PushIntentRepository() )->find_unresolved( $platform ) );

echo 0 === $failures ? "ALL PASS\n" : "{$failures} FAILURE(S)\n";
exit( 0 === $failures ? 0 : 1 );
