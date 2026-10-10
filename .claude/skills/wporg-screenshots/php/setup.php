<?php
/**
 * wordpress.org 用スクリーンショットの準備。wp-env の tests サイトだけで、`capture.sh` が `wp eval-file` で実行する。
 *
 * 引数: <tests サイトの home_url> <dry-run するエンティティ（カンマ区切り）>
 *
 * 1. 前提を肯定形で確かめる: home_url が引数と一致する（＝ tests サイト）／撮影用 mu-plugin が読み込まれている（＝ Color Me Shop API へは
 *    出ていかずフィクスチャが返る）／`colorme` が実 `ColorMeAdapter` で登録されている／Pro アドオンが読み込まれておらず、登録された実体の
 *    種類が無料版のものだけ（readme の画面は無料版だけ。`capture.sh` が Pro を無効にする。R3-6d）／ユーザー 1 が `manage_woocommerce` を持つ／
 *    `colorme` のトークンが無いか、このスキルの偽のトークンである（別のトークンなら何も変えずに止まる。上書きすると元の接続が失われる）。
 * 2. サイトを撮影向けにする: サイト名 `Example Store`、通貨 JPY、ストアの国 JP:JP13、オンボーディングとストアの「近日公開」を外す、
 *    ユーザー 1 の言語を英語にする（`wait_for` は英語の文言）。
 * 3. 偽のトークンで `colorme` に接続した状態にする（`TokenStore`。tests サイトだけ）。
 * 4. Mappings タブに出す対応を、REST の候補一覧から作って保存する（フィクスチャの ID は書き写さない）。無料版のマッピングはカテゴリだけで、
 *    Woo のカテゴリ `Apparel`・`Accessories` を作って Color Me Shop の先頭 2 つに対応させる（決済・配送・注文ステータスは Pro の節。R3-6d）。
 * 5. 開いたままの `colorme` の run（前回の撮影が途中で止まったもの）をキャンセルしてから dry-run を始め、Action Scheduler の
 *    ジョブをここで処理し（規約は `.claude/rules/skill-scripts.md`）、すべてのジョブが完了したことを確かめる。途中で止まるときは
 *    自分の run をキャンセルしてから終わる（開いた run が残ると、次の撮影と、同じ DB を使う PHPUnit の一部が 409 になる）。
 * 6. 最後に `RUN_ID=<run_id>` と `COOKIES=<JSON>`（ユーザー 1 のログイン Cookie。1 時間で切れる）を出力する。
 *
 * 何度実行してもよい（カテゴリは名前で再利用し、マッピングは丸ごと置き換え、dry-run は毎回新しく始める）。
 * PHPUnit は tests サイトと同じ DB・同じ接頭辞を使い、起動時に戻すのはコアのテーブル（オプション・投稿・ターム・ユーザー）だけ。
 * プラグインと WooCommerce の独自テーブル（ジョブ・dry-run の明細・ログ・Action Scheduler）の行は残る。
 *
 * @package CartBridgeJP
 */

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\JobManager;

global $wpdb;

$cbjp_shot_rest = static function ( string $method, string $route, array $params = [] ): array {
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

// 自分の run を始めた後に止まるときは、その run をキャンセルしてから終わる。
$cbjp_shot_run_id = null;
$cbjp_shot_fail   = static function ( string $message ) use ( &$cbjp_shot_run_id, $cbjp_shot_rest ): void {
	if ( is_string( $cbjp_shot_run_id ) ) {
		$cancel   = $cbjp_shot_rest( 'POST', "/cbjp/v1/runs/{$cbjp_shot_run_id}/cancel" );
		$message .= " The dry run {$cbjp_shot_run_id} was cancelled (HTTP {$cancel['status']}).";
	}
	fwrite( STDERR, "setup: {$message}\n" );
	exit( 1 );
};

$cbjp_shot_expected = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
$cbjp_shot_entities = isset( $args[1] ) && is_string( $args[1] ) ? array_values( array_filter( explode( ',', $args[1] ) ) ) : [];

if ( '' === $cbjp_shot_expected || home_url() !== $cbjp_shot_expected ) {
	$cbjp_shot_fail( 'home_url ' . home_url() . " is not the tests site '{$cbjp_shot_expected}'. Refusing to touch this site." );
}
if ( ! defined( 'CBJP_SCREENSHOT_FIXTURES' ) ) {
	$cbjp_shot_fail( 'the screenshot mu-plugin is not loaded, so requests would reach the real Color Me Shop API.' );
}
if ( ! AdapterRegistry::get( 'colorme' ) instanceof ColorMeAdapter ) {
	$cbjp_shot_fail( "'colorme' is not registered as the bundled ColorMeAdapter." );
}
// readme の画面は無料版だけ（R3-6d）。Pro が有効だと顧客・受注・クーポンの選択肢と決済・配送のマッピングの節が写る。
// 種類の一覧が無料版の種類に収まることも確かめる（Pro 以外の拡張が種類を足していても、無料版の画面ではなくなる）。
$cbjp_shot_free_types = [ 'category', 'tag', 'product', 'stock', 'review' ];
if ( defined( 'CBJP_PRO_VERSION' ) ) {
	$cbjp_shot_fail( 'the Pro add-on is loaded. The screenshots show the free plugin alone (capture.sh deactivates cart-bridge-jp-pro).' );
}
if ( [] !== array_diff( EntityTypeRegistry::keys(), $cbjp_shot_free_types ) ) {
	$cbjp_shot_fail( 'entity types other than the free plugin\'s are registered: ' . implode( ', ', array_diff( EntityTypeRegistry::keys(), $cbjp_shot_free_types ) ) . '.' );
}
if ( [] === $cbjp_shot_entities ) {
	$cbjp_shot_fail( 'no entities to dry-run were given.' );
}
if ( ! user_can( 1, 'manage_woocommerce' ) ) {
	$cbjp_shot_fail( 'user 1 cannot manage WooCommerce on the tests site.' );
}
// 何かを変える前に `colorme` のトークンの持ち主を確かめる。無いか、このスキルの偽のトークンのときだけ進める（PR #112 G2-1・G2-2）。
// 別のトークン（誰かが tests サイトを店舗につないだ）を上書きすると元の接続が失われ、その run までキャンセルしてしまう。
// 復号できない値は `TokenStore::get()` が null を返すので、オプションの有無を生の値で見る。
$cbjp_shot_raw_token = get_option( 'cbjp_token_colorme', null );
if ( null !== $cbjp_shot_raw_token && '' !== $cbjp_shot_raw_token ) {
	$cbjp_shot_saved = ( new TokenStore( 'colorme' ) )->get();
	if ( 'screenshot-dummy-token' !== ( $cbjp_shot_saved['access_token'] ?? null ) ) {
		$cbjp_shot_fail( 'a colorme token other than the screenshot dummy (or one that cannot be decrypted) is saved on the tests site. Refusing to cancel its runs or overwrite it.' );
	}
}
wp_set_current_user( 1 );

// 前回の撮影が途中で止まって開いたままの run を閉じる（tests サイトだけ。上のガードを通った後）。
$cbjp_shot_active = $cbjp_shot_rest( 'GET', '/cbjp/v1/runs', [ 'platform' => 'colorme' ] );
if ( 200 !== $cbjp_shot_active['status'] || ! is_array( $cbjp_shot_active['data']['runs'] ?? null ) ) {
	$cbjp_shot_fail( 'GET /runs returned HTTP ' . $cbjp_shot_active['status'] . ': ' . wp_json_encode( $cbjp_shot_active['data'] ) );
}
foreach ( $cbjp_shot_active['data']['runs'] as $cbjp_shot_open_run ) {
	$cbjp_shot_open_id = is_array( $cbjp_shot_open_run ) && is_string( $cbjp_shot_open_run['run_id'] ?? null ) ? $cbjp_shot_open_run['run_id'] : '';
	$cbjp_shot_cancel  = '' === $cbjp_shot_open_id ? null : $cbjp_shot_rest( 'POST', "/cbjp/v1/runs/{$cbjp_shot_open_id}/cancel" );
	if ( null === $cbjp_shot_cancel || 200 !== $cbjp_shot_cancel['status'] ) {
		$cbjp_shot_fail( 'could not cancel the open run ' . wp_json_encode( $cbjp_shot_open_run ) . '.' );
	}
	echo "cancelled the open run {$cbjp_shot_open_id}\n";
}

// 2. サイトを撮影向けにする。
update_option( 'blogname', 'Example Store' );
update_option( 'woocommerce_currency', 'JPY' );
update_option( 'woocommerce_default_country', 'JP:JP13' );
update_option( 'woocommerce_onboarding_profile', [ 'skipped' => true ] );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
// 管理画面の言語はユーザー 1 の設定で決まる（ブラウザの言語ではない）。`wait_for` は英語なので英語に固定する（PR #112 G2-B1）。
update_user_meta( 1, 'locale', 'en_US' );

// 3. 偽のトークンで接続した状態にする。
( new TokenStore( 'colorme' ) )->save(
	[
		'access_token' => 'screenshot-dummy-token',
		'settings'     => [
			'client_id'     => 'example-client-id',
			'client_secret' => 'example-client-secret',
		],
		'extras'       => [ 'contract_plan' => 'premium' ],
	]
);

// 4. 候補一覧から対応を作って保存する（フィクスチャの ID を書き写さない）。
$cbjp_shot_candidates = $cbjp_shot_rest( 'GET', '/cbjp/v1/settings/mappings/colorme' );
if ( 200 !== $cbjp_shot_candidates['status'] ) {
	$cbjp_shot_fail( 'GET mappings returned HTTP ' . $cbjp_shot_candidates['status'] . ': ' . wp_json_encode( $cbjp_shot_candidates['data'] ) );
}
$cbjp_shot_asp = $cbjp_shot_candidates['data']['asp_candidates'] ?? [];
$cbjp_shot_ids = static function ( $list ): array {
	return is_array( $list ) ? array_values( array_filter( array_map( static fn( $item ) => is_array( $item ) ? (string) ( $item['id'] ?? '' ) : '', $list ) ) ) : [];
};

$cbjp_shot_asp_categories = $cbjp_shot_ids( $cbjp_shot_asp['category'] ?? null );
$cbjp_shot_category_map   = [];
foreach ( [ 'Apparel', 'Accessories' ] as $cbjp_shot_i => $cbjp_shot_name ) {
	if ( ! isset( $cbjp_shot_asp_categories[ $cbjp_shot_i ] ) ) {
		break;
	}
	$cbjp_shot_term = term_exists( $cbjp_shot_name, 'product_cat' );
	if ( ! is_array( $cbjp_shot_term ) ) {
		$cbjp_shot_term = wp_insert_term( $cbjp_shot_name, 'product_cat' );
	}
	if ( is_wp_error( $cbjp_shot_term ) ) {
		$cbjp_shot_fail( "could not create the product category {$cbjp_shot_name}: " . $cbjp_shot_term->get_error_message() );
	}
	$cbjp_shot_category_map[ (string) $cbjp_shot_term['term_id'] ] = $cbjp_shot_asp_categories[ $cbjp_shot_i ];
}

$cbjp_shot_mappings = [
	'category_map' => $cbjp_shot_category_map,
];
foreach ( $cbjp_shot_mappings as $cbjp_shot_key => $cbjp_shot_map ) {
	if ( [] === $cbjp_shot_map ) {
		$cbjp_shot_fail( "{$cbjp_shot_key} would be empty (candidates: " . wp_json_encode( $cbjp_shot_candidates['data'] ) . ').' );
	}
}
$cbjp_shot_put = $cbjp_shot_rest( 'PUT', '/cbjp/v1/settings/mappings/colorme', $cbjp_shot_mappings );
if ( 200 !== $cbjp_shot_put['status'] ) {
	$cbjp_shot_fail( 'PUT mappings returned HTTP ' . $cbjp_shot_put['status'] . ': ' . wp_json_encode( $cbjp_shot_put['data'] ) );
}
echo 'mappings: ' . implode( ' ', array_map( static fn( $k, $m ) => $k . '=' . count( $m ), array_keys( $cbjp_shot_mappings ), $cbjp_shot_mappings ) ) . "\n";

// 5. dry-run を始め、ジョブをここで処理する。claim はフック名だけで取る（グループで絞らない。.claude/rules/skill-scripts.md）。
$cbjp_shot_started = $cbjp_shot_rest(
	'POST',
	'/cbjp/v1/runs',
	[
		'type'     => 'dry_run',
		'platform' => 'colorme',
		'entities' => $cbjp_shot_entities,
	]
);
$cbjp_shot_run_id = $cbjp_shot_started['data']['run_id'] ?? null;
if ( ! is_string( $cbjp_shot_run_id ) || '' === $cbjp_shot_run_id ) {
	$cbjp_shot_fail( 'POST /runs returned HTTP ' . $cbjp_shot_started['status'] . ': ' . wp_json_encode( $cbjp_shot_started['data'] ) );
}

$cbjp_shot_jobs_of_run = static function () use ( &$cbjp_shot_run_id, $cbjp_shot_rest, $cbjp_shot_fail ): array {
	$result = $cbjp_shot_rest( 'GET', "/cbjp/v1/runs/{$cbjp_shot_run_id}" );
	if ( 200 !== $result['status'] || ! is_array( $result['data']['jobs'] ?? null ) ) {
		$cbjp_shot_fail( 'GET /runs/{id} returned HTTP ' . $result['status'] . ': ' . wp_json_encode( $result['data'] ) );
	}
	return array_values( array_filter( $result['data']['jobs'], 'is_array' ) );
};
$cbjp_shot_job_ids = array_map( static fn( array $job ): int => (int) ( $job['id'] ?? 0 ), $cbjp_shot_jobs_of_run() );

// claim はフック名だけで取り、引数の job_id で自分の run のアクションか見分ける（rehearse-colorme の run.php と同じ）。
// 他の run のアクションは、ジョブが閉じている（完了・失敗・キャンセル）か存在しなければ処理して片付け、開いていれば手放す。
// 処理できるアクションが無いのにジョブが開いているときは、paused からの再開待ち（予定時刻が先）なので待つ。
$cbjp_shot_store    = ActionScheduler::store();
$cbjp_shot_runner   = ActionScheduler::runner();
$cbjp_shot_deadline = time() + 5 * MINUTE_IN_SECONDS;
$cbjp_shot_orphaned = 0;
// 止めるときはループを抜けてから、エンティティごとの状態を出して判定する（どのジョブが止まったか分かるように。run.php と同じ）。
$cbjp_shot_stopped = '';
while ( true ) {
	$cbjp_shot_current = $cbjp_shot_jobs_of_run();
	$cbjp_shot_open    = array_filter( $cbjp_shot_current, static fn( array $job ): bool => in_array( $job['status'] ?? null, [ 'pending', 'running', 'paused' ], true ) );
	if ( [] === $cbjp_shot_open ) {
		break;
	}
	// 1 つのジョブが失敗すると、後ろのジョブはアクションの無い pending のまま進まない（JobManager は完了したときだけ次へ進める）。
	if ( [] !== array_filter( $cbjp_shot_current, static fn( array $job ): bool => 'failed' === ( $job['status'] ?? null ) ) ) {
		$cbjp_shot_stopped = 'a job failed, so the jobs after it cannot start';
		break;
	}
	if ( time() > $cbjp_shot_deadline ) {
		$cbjp_shot_stopped = 'it did not finish within 5 minutes';
		break;
	}

	$cbjp_shot_claim     = $cbjp_shot_store->stake_claim( 20, null, [ JobManager::ACTION_HOOK ] );
	$cbjp_shot_processed = 0;
	foreach ( $cbjp_shot_claim->get_actions() as $cbjp_shot_action_id ) {
		$cbjp_shot_action = $cbjp_shot_store->fetch_action( (string) $cbjp_shot_action_id );
		$cbjp_shot_args   = $cbjp_shot_action->get_args();
		$cbjp_shot_job_id = is_array( $cbjp_shot_args ) ? (int) ( $cbjp_shot_args['job_id'] ?? 0 ) : 0;

		if ( ! in_array( $cbjp_shot_job_id, $cbjp_shot_job_ids, true ) ) {
			// `get_var()` は空文字の値でも null を返し「行が無い」と区別できないので、`get_row()` で行の有無を見る（CLAUDE.md）。
			$cbjp_shot_other = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cbjp_jobs WHERE id = %d", $cbjp_shot_job_id ), ARRAY_A );
			if ( null === $cbjp_shot_other || in_array( $cbjp_shot_other['status'] ?? null, [ 'completed', 'failed', 'cancelled' ], true ) ) {
				$cbjp_shot_runner->process_action( (int) $cbjp_shot_action_id, 'CBJP screenshots (stale)' );
			} else {
				$cbjp_shot_store->unclaim_action( (string) $cbjp_shot_action_id );
			}
			continue;
		}

		$cbjp_shot_runner->process_action( (int) $cbjp_shot_action_id, 'CBJP screenshots' );
		++$cbjp_shot_processed;
	}
	$cbjp_shot_store->release_claim( $cbjp_shot_claim );

	if ( 0 === $cbjp_shot_processed ) {
		// 自分のジョブのアクションが 1 つも無い（pending も実行中も無い）のにジョブが開いているなら、待っても進まない。
		$cbjp_shot_live = 0;
		foreach ( $cbjp_shot_job_ids as $cbjp_shot_job_id ) {
			foreach ( [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ] as $cbjp_shot_status ) {
				$cbjp_shot_live += count(
					as_get_scheduled_actions(
						[
							'hook'     => JobManager::ACTION_HOOK,
							'args'     => [ 'job_id' => $cbjp_shot_job_id ],
							'status'   => $cbjp_shot_status,
							'per_page' => 1,
						],
						'ids'
					)
				);
			}
		}
		$cbjp_shot_orphaned = 0 === $cbjp_shot_live ? $cbjp_shot_orphaned + 1 : 0;
		if ( $cbjp_shot_orphaned >= 3 ) {
			$cbjp_shot_stopped = 'open jobs have no pending or running action, so the run cannot progress';
			break;
		}
		sleep( 5 );
	}
}

$cbjp_shot_jobs     = $cbjp_shot_jobs_of_run();
$cbjp_shot_not_done = [];
foreach ( $cbjp_shot_jobs as $cbjp_shot_job ) {
	echo ( $cbjp_shot_job['entity'] ?? '?' ) . ' ' . ( $cbjp_shot_job['status'] ?? '?' ) . ' ' . wp_json_encode( $cbjp_shot_job['totals'] ?? null ) . "\n";
	if ( 'completed' !== ( $cbjp_shot_job['status'] ?? null ) ) {
		$cbjp_shot_not_done[] = ( $cbjp_shot_job['entity'] ?? '?' ) . '=' . ( $cbjp_shot_job['status'] ?? '?' );
	}
}
$cbjp_shot_job_entities = array_map( static fn( array $job ): string => (string) ( $job['entity'] ?? '' ), $cbjp_shot_jobs );
$cbjp_shot_missing      = array_diff( $cbjp_shot_entities, $cbjp_shot_job_entities );
if ( [] !== $cbjp_shot_missing ) {
	$cbjp_shot_fail( 'no job was created for: ' . implode( ', ', $cbjp_shot_missing ) . ' (requested: ' . implode( ', ', $cbjp_shot_entities ) . '). Check dry_run_entities in shots.json.' );
}
if ( [] !== $cbjp_shot_not_done || '' !== $cbjp_shot_stopped ) {
	$cbjp_shot_fail( 'the dry run did not complete for every entity: ' . implode( ', ', $cbjp_shot_not_done ) . ( '' !== $cbjp_shot_stopped ? " ({$cbjp_shot_stopped})" : '' ) . '.' );
}

// 6. 撮影に使う値を出す。
$cbjp_shot_expires = time() + HOUR_IN_SECONDS;
echo "RUN_ID={$cbjp_shot_run_id}\n";
echo 'COOKIES=' . wp_json_encode(
	[
		[
			'name'  => AUTH_COOKIE,
			'value' => wp_generate_auth_cookie( 1, $cbjp_shot_expires, 'auth' ),
			'path'  => ADMIN_COOKIE_PATH,
		],
		[
			'name'  => LOGGED_IN_COOKIE,
			'value' => wp_generate_auth_cookie( 1, $cbjp_shot_expires, 'logged_in' ),
			'path'  => COOKIEPATH,
		],
	]
) . "\n";
