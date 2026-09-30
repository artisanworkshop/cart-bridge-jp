<?php
// 例（issue #55 / R3-0h）: 無料版の Pro 案内（`LimitsUpsellNotice`）が出す件数の元になる値を、rest_do_request() で確かめる。
//   - dry-run の totals に `unchanged` が載り、「移行できる件数」（created + updated + unchanged）と「止まる件数」（processed − 移行できる件数）に分かれる
//   - 上限（seed の `limits.product`）で export が止まり、`/limits` の `used` と合わせて「未移行」が 1 件以上になる
//   - `/limits` の `pro_url` は seed の `pro_url` を `LimitPolicy::pro_url()` が検証した値になる（不正な URL は ''）
// 各ステップを PASS/FAIL で出し、失敗が1つでもあれば非ゼロで終了する。最後に mock を connected にして、管理画面で通知を目視する手順を出す。
//
// 前提:
//   - mock を非衝突キーで登録済み: mock-adapter.sh install mockv（実 platform の mapping・上限に触れないため colorme では登録しない）
//   - 前回の残りが無い（あれば cleanup.php を先に流す。残りがあると件数が変わるので、この先頭で中止する）
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/upsell-notice/verify-rest.php
// 続けて: 管理画面で目視（最後に出る手順）→ cleanup.php → mock-adapter.sh uninstall → mock-adapter.sh inspect（検証前と同じか確認）
//
// 作るもの（cleanup.php が消す）: Woo の商品 4 件（SKU `ZZV-UPSELL-*`。うち 1 件は価格なし＝export で止まる）、mockv の mapping・job・dry-run 明細、
// `cbjp_verify_seed` の `push`/`limits`/`pro_url` キー、mockv の偽トークン（`cbjp_token_mockv`。値は下の $token）、状態オプション `cbjp_verify_upsell`。
use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\ExportSampleSelector;
use CartBridgeJP\Sync\ExportSampleSet;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\WarningCode;

wp_set_current_user( 1 );

$platform   = 'mockv'; // install に渡したキーと同じにする。
$token      = 'verify-upsell'; // cleanup.php はこの値のトークンだけを消す。
$state_name = 'cbjp_verify_upsell';
$sku_prefix = 'ZZV-UPSELL-';
$failures   = 0;

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

// `cbjp_verify_seed` は他の example と共有するので、丸ごと上書きせずこの example のキーだけを差し替える（null はキーを外す）。
$set_seed = static function ( string $key, $value ): void {
	$seed = get_option( 'cbjp_verify_seed', [] );
	$seed = is_array( $seed ) ? $seed : [];

	if ( null === $value ) {
		unset( $seed[ $key ] );
	} else {
		$seed[ $key ] = $value;
	}

	update_option( 'cbjp_verify_seed', $seed );
	AdapterRegistry::reset_cache(); // `all()` は静的キャッシュ。seed を変えたら組み立て直させる。
};

// 途中で止まっても cleanup.php が作ったものを見つけられるよう、作るたびに状態オプションへ記録する。
$remember = static function ( string $key, $value ) use ( $state_name ): void {
	$state = get_option( $state_name, [] );
	$state = is_array( $state ) ? $state : [];

	$state[ $key ]   = is_array( $state[ $key ] ?? null ) ? $state[ $key ] : [];
	$state[ $key ][] = $value;

	update_option( $state_name, $state, false );
};

// product の run を1回走らせ、その run の product ジョブの totals を返す。`cbjp_process_job`（Action Scheduler）は CLI では自走しないので、
// **この run のジョブだけ**を job_id で引いて処理する（サイト全体の pending を流すと、他 platform のジョブを同期実行して実 API を叩きかねない）。
$run = static function ( string $type ) use ( $call, $platform, $remember ): array {
	$started = $call(
		'POST',
		'/cbjp/v1/runs',
		[
			'type'                         => $type,
			'platform'                     => $platform,
			'entities'                     => [ 'product' ],
			'acknowledge_production_write' => true,
		]
	);
	$run_id  = $started['data']['run_id'] ?? null;

	if ( ! is_string( $run_id ) ) {
		return [ 'error' => 'start_run failed: ' . wp_json_encode( $started ) ];
	}

	$remember( 'run_ids', [ $type, $run_id ] );

	$queued  = $call( 'GET', "/cbjp/v1/runs/{$run_id}" );
	$job_ids = array_map( static fn ( array $job ): int => (int) ( $job['id'] ?? 0 ), $queued['data']['jobs'] ?? [] );
	$drained = false;

	for ( $i = 0; $i < 50 && ! $drained; $i++ ) {
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
		return [ 'error' => 'the run still had pending jobs after 50 rounds' ];
	}

	$result = $call( 'GET', "/cbjp/v1/runs/{$run_id}" );
	$job    = $result['data']['jobs'][0] ?? null;

	if ( ! is_array( $job ) || 'completed' !== ( $job['status'] ?? null ) ) {
		return [ 'error' => 'the product job did not complete: ' . wp_json_encode( $result ) ];
	}

	return [
		'run_id' => $run_id,
		'totals' => $job['totals'],
	];
};

// この example の商品（SKU が接頭辞で始まるもの）。`wc_get_products()` の `sku` は部分一致（`WC_Product_Data_Store_CPT` が
// `compare => 'LIKE'` で組み立てる）なので、接頭辞かどうかは自分で確かめる。
$own_products = static function () use ( $sku_prefix ): array {
	return array_values(
		array_filter(
			wc_get_products(
				[
					'sku'    => $sku_prefix,
					'limit'  => -1,
					'status' => 'any',
				]
			),
			static fn ( $product ): bool => $product instanceof WC_Product && str_starts_with( (string) $product->get_sku(), $sku_prefix )
		)
	);
};

$limits_now = static function () use ( $call, $platform, $abort ): array {
	$result = $call( 'GET', '/cbjp/v1/limits', [ 'platform' => $platform ] );

	if ( 200 !== $result['status'] || ! is_array( $result['data']['entities']['product'] ?? null ) ) {
		$abort( 'GET /limits failed: ' . wp_json_encode( $result ), 1 );
	}

	return $result['data'];
};

// ---- 0) 前提 ----
// 実 export（`acknowledge_production_write=true`）を走らせるので、mock アダプタが登録され、かつ OAuth トークンが無い platform だけを許す
// （肯定形で判定する。実アダプタ・トークンの残る platform では実店舗へ書き込みうる。`.claude/rules/skill-scripts.md`）。
if ( false !== get_option( 'cbjp_token_' . $platform, false ) || ! AdapterRegistry::get( $platform ) instanceof MockPlatformAdapter ) {
	$abort( "'{$platform}' is not a registered mock adapter without a stored token — run: mock-adapter.sh install {$platform} (if a token of this example is left, run cleanup.php first)", 2 );
}

if ( ! is_array( get_option( 'cbjp_verify_seed', [] ) ) ) {
	$abort( 'option cbjp_verify_seed is not an array — run cleanup.php first (it removes the broken value), then retry', 2 );
}

// cleanup.php が消す範囲（intent・mapping・job・ログ・この example の商品と状態・seed のキー・mockv のサンプル/レート制限のオプション）と
// 同じ範囲を数え、残っていたら始めない（別の検証の途中の状態を上書き・削除しないため。push-intent-resolution も `push` キーを使う）。
global $wpdb;
$log_like = '%' . $wpdb->esc_like( '"platform":"' . $platform . '"' ) . '%';
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$leftover = count( ( new PushIntentRepository() )->find_unresolved( $platform ) )
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_mappings WHERE platform = %s", $platform ) )
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs WHERE platform = %s", $platform ) )
	+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_logs WHERE job_id IS NULL AND context_json LIKE %s", $log_like ) )
	+ count( $own_products() )
	+ ( false !== get_option( $state_name, false ) ? 1 : 0 )
	+ count( array_intersect( [ 'push', 'limits', 'pro_url' ], array_keys( get_option( 'cbjp_verify_seed', [] ) ) ) )
	+ count( array_filter( [ 'cbjp_export_sample_' . $platform, 'cbjp_sample_' . $platform, 'cbjp_rate_limit_' . $platform ], static fn ( string $option ): bool => false !== get_option( $option, false ) ) );
// phpcs:enable

if ( $leftover > 0 ) {
	$abort( "'{$platform}' already has intents, mappings, jobs, logs, {$sku_prefix}* products, {$state_name}, cbjp_verify_seed push/limits/pro_url or its sample/rate-limit options ({$leftover}) — run cleanup.php first, then retry", 2 );
}

update_option( $state_name, [], false );

// ---- 1) 準備: 移行できる商品 3 件と、価格が無く export で止まる商品 1 件。product の上限を 2 に下げ、push を有効にする ----
foreach ( [ [ 'VALID-1', '1000' ], [ 'VALID-2', '1200' ], [ 'VALID-3', '1500' ], [ 'NOPRICE', '' ] ] as [ $suffix, $price ] ) {
	$product = new WC_Product_Simple();
	$product->set_name( "{$sku_prefix}{$suffix}" );
	$product->set_sku( $sku_prefix . $suffix );
	$product->set_regular_price( $price );
	$product->set_status( 'publish' );
	$product->save();
	$remember( 'product_ids', $product->get_id() );
}

$set_seed( 'push', [ 'enabled' => true ] );
$set_seed( 'limits', [ 'product' => 2 ] );
$set_seed( 'pro_url', null );

// ---- 2) dry-run: 「移行できる」と「止まる」に分かれる ----
$dry = $run( 'dry_run_export' );

if ( isset( $dry['error'] ) ) {
	$abort( $dry['error'], 1 );
}

$t          = $dry['totals'];
$migratable = (int) ( $t['created'] ?? 0 ) + (int) ( $t['updated'] ?? 0 ) + (int) ( $t['unchanged'] ?? 0 );
$blocked    = (int) ( $t['processed'] ?? 0 ) - $migratable;
// unchanged はここでは 0 のまま: 作った商品は mockv の mapping を持たず、export 後も既定カテゴリが未マッピングで checksum が保存されない
// （`verify-with-mock-adapter` の落とし穴）。値の意味は ExporterTest/ImporterTest が担当し、ここでは REST の totals に載ることだけを見る。
$check( '2 dry-run の totals に unchanged が載る（整数）', is_int( $t['unchanged'] ?? null ), wp_json_encode( $t ) );
$check( '2 止まる件数（processed − 移行できる件数）が 1 件以上', $blocked >= 1, "migratable={$migratable} blocked={$blocked}" );

// 集計だけでは dev サイトの既存商品が結果を隠しうる（既存の止まる商品があれば NOPRICE が移行できるに変わっても通る）。作った商品ごとに
// dry-run の明細（export 方向は `existing_local_id` に Woo の商品 ID が入る）を確かめる。
$state = get_option( $state_name, [] );
$ids   = is_array( $state ) && is_array( $state['product_ids'] ?? null ) ? array_map( 'intval', $state['product_ids'] ) : [];
$rows  = [];

foreach ( ( new DryRunItemRepository() )->list_after( $dry['run_id'], 0, 1000, 'product' ) as $row ) {
	$rows[ (int) $row['existing_local_id'] ] = $row;
}

$check( '2 作った商品 4 件が記録されている', 4 === count( $ids ), wp_json_encode( $ids ) );

foreach ( $ids as $id ) {
	$product = wc_get_product( $id );
	$sku     = $product ? (string) $product->get_sku() : "#{$id}";
	$row     = $rows[ $id ] ?? null;

	if ( str_ends_with( $sku, 'NOPRICE' ) ) {
		$check( "2 {$sku} は止まる（skipped・" . WarningCode::PRODUCT_PRICE_INVALID . '）', null !== $row && 'skipped' === $row['operation'] && str_contains( (string) $row['warnings_json'], WarningCode::PRODUCT_PRICE_INVALID ), wp_json_encode( $row ) );
	} else {
		$check( "2 {$sku} は移行できる（created）", null !== $row && 'created' === $row['operation'], wp_json_encode( $row ) );
	}
}

$check( '2 unchanged は skipped の内訳（skipped 以下）', (int) ( $t['unchanged'] ?? 0 ) <= (int) ( $t['skipped'] ?? 0 ), wp_json_encode( $t ) );

// ---- 3) export: 上限 2 で止まり、「未移行」が残る ----
// 無料版の export は `ExportSampleSelector` のサンプルだけを対象にする。受注が 10 件以上ある dev サイトでは受注の商品だけで決まり、作った商品が
// 入る保証が無い（別の商品が止まっていれば used が 0 になり、正しい挙動でも落ちる）。サンプルを作った 4 件に固定する（PR #88 G2-1）。
// 開始前の残り検査がこのオプションの不在を確かめており、cleanup.php が消す。
update_option( ExportSampleSelector::option_name_for( $platform ), ( new ExportSampleSet( [], $ids, [], false ) )->to_array(), false );

$exp = $run( 'export' );

if ( isset( $exp['error'] ) ) {
	$abort( $exp['error'], 1 );
}

$limits        = $limits_now();
$product_limit = $limits['entities']['product'];
$used          = (int) ( $product_limit['used'] ?? 0 );
$not_migrated  = max( 0, $migratable - $used );
$check( '3 /limits の product は seed の上限 2 を返す（無料版のまま）', 2 === ( $product_limit['limit'] ?? null ) && false === ( $product_limit['unlocked'] ?? null ), wp_json_encode( $product_limit ) );
$e = $exp['totals'];
$check( '3 export はサンプルの 4 件だけを処理する', 4 === (int) ( $e['processed'] ?? -1 ), wp_json_encode( $e ) );
$check( '3 export は上限 2 で止まる（作成 2、used 2）', 2 === (int) ( $e['created'] ?? -1 ) && 2 === $used, "used={$used} export=" . wp_json_encode( $e ) );
$check( '3 「未移行」（移行できる件数 − used）が 1 件以上残る＝通知の行が出る', $not_migrated >= 1, "migratable={$migratable} used={$used}" );

// ---- 4) pro_url: 既定は ''、有効な URL はそのまま、不正な値は '' ----
$check( '4 pro_url は既定で空（Pro 版に触れない）', '' === ( $limits['pro_url'] ?? null ), wp_json_encode( $limits['pro_url'] ?? null ) );

foreach (
	[
		[ 'https://example.com/pro?utm_source=verify', 'https://example.com/pro?utm_source=verify' ],
		[ 'javascript:alert(1)', '' ],
		[ 'https:example.com/pro', '' ],
		[ [ 'https://example.com/pro' ], '' ],
	] as [ $value, $expected ]
) {
	$set_seed( 'pro_url', $value );
	$got = $limits_now()['pro_url'] ?? null;
	$check( '4 pro_url ' . wp_json_encode( $value ) . ' → ' . wp_json_encode( $expected ), $expected === $got, 'got ' . wp_json_encode( $got ) );
}

$set_seed( 'pro_url', null );

// ---- 5) 管理画面で目視する準備: mock を connected にする（偽トークン。実 colorme は使わない） ----
( new TokenStore( $platform ) )->save( [ 'access_token' => $token ] );
$check( '5 mockv を connected にした（管理画面の platform 選択に出る）', ( new TokenStore( $platform ) )->is_connected() );

echo "\n" . ( 0 === $failures ? 'ALL PASS' : "{$failures} FAILED" ) . "\n\n";
echo "管理画面で通知を目視する（任意）:\n";
echo "  1. http://localhost:<port>/wp-admin/admin.php?page=cart-bridge-jp#/export を開き、ブラウザのコンソールで次を実行してから cmd+r:\n";
echo "     localStorage.setItem('cbjp_run_dry_run_export_{$platform}', '{$dry['run_id']}'); localStorage.setItem('cbjp_run_export_{$platform}', '{$exp['run_id']}');\n";
echo "  2. Export タブ先頭のカードの PLATFORM で「Mock Platform」を選ぶ → Export results に「Products: … not migrated yet. … cannot be migrated as is. …」\n";
echo "     （pro_url が空なので見出しは Pro 版に触れない）\n";
echo "  3. Pro 版の案内を見るには pro_url を入れて cmd+r（外すと元に戻る）:\n";
echo "     npx wp-env run cli wp eval '\$s = get_option( \"cbjp_verify_seed\" ); \$s[\"pro_url\"] = \"https://example.com/pro\"; update_option( \"cbjp_verify_seed\", \$s );'\n";
echo "  4. 終わったらコンソールで: localStorage.removeItem('cbjp_run_dry_run_export_{$platform}'); localStorage.removeItem('cbjp_run_export_{$platform}');\n\n";
echo "次: cleanup.php で痕跡を撤去し、mock-adapter.sh uninstall → inspect で検証前に戻ったことを確認する\n";
exit( 0 === $failures ? 0 : 1 );
