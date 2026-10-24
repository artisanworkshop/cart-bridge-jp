<?php
/**
 * wordpress.org 用スクリーンショットの準備と片付けの処理。同じディレクトリの setup.php・teardown.php（共有スキル wporg-screenshots の
 * フック。capture.sh が tests サイトの mu-plugins の下へ写して `wp eval-file` で実行する）が、wp-env のルートのマウント
 * （wp-content/cbjp-dev）から読み込む。tests サイトだけで動く。
 *
 * 撮影で作ったもの（始めた run・作ったカテゴリ）は option `cbjp_wporg_screenshots`（autoload なし）に記録し、片付けは記録にあるものと、
 * 偽のトークン（`screenshot-dummy-token`）だけを消す・キャンセルする。他人のトークン・run には触れずに止まる。
 * 書き換える option（サイトの表示・カテゴリの対応付け）は、書き換える前の DB の値を記録に控え、片付けがそのまま戻す。
 * 処理と照会の書き方は Pro のマニュアル用の撮影（`.claude/pro-manual-screenshots/shared/lib.php`。PR #152）と同じ。
 *
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

if ( ! defined( 'CBJP_SHOT_TOKEN' ) ) {
	// 撮影用の偽のトークン（Pro のマニュアルの撮影の `pro-manual-dummy-token` と別の値。どちらの撮影の片付けも、相手のトークンを消さない）。
	define( 'CBJP_SHOT_TOKEN', 'screenshot-dummy-token' );
	define( 'CBJP_SHOT_STATE', 'cbjp_wporg_screenshots' );
}

$GLOBALS['cbjp_shot_who']    = 'setup';
$GLOBALS['cbjp_shot_run_id'] = null;

/**
 * 止める。自分が始めた run があれば、キャンセルしてから終わる（開いた run が残ると、次の撮影と PHPUnit の一部が 409 になる）。
 * キャンセルできたと確かめられなければ、そう書いて終わる（記録にある run は cleanup がキャンセルし直す。記録する前に止まった run は Tools タブで）。
 */
function cbjp_shot_fail( string $message ): void {
	$run = $GLOBALS['cbjp_shot_run_id'] ?? null;

	if ( is_string( $run ) ) {
		$GLOBALS['cbjp_shot_run_id'] = null;
		$problem                     = cbjp_shot_cancel_run( $run );
		$message                    .= '' === $problem ? " The dry run {$run} was cancelled." : " Could not confirm that the dry run {$run} was cancelled: {$problem}. Run capture.sh cleanup or cancel it on the Tools tab.";
	}

	fwrite( STDERR, $GLOBALS['cbjp_shot_who'] . ": {$message}\n" );
	exit( 1 );
}

/**
 * @param array<string,mixed> $params
 * @return array{status:int,data:mixed}
 */
function cbjp_shot_rest( string $method, string $route, array $params = [] ): array {
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
}

/**
 * 撮影の記録（DB の行で読む）。無ければ空。`get_option()` は照会の失敗も「無い」と返すので使わない（照会が失敗したら止まる）。
 * 壊れた値（配列でない）は空と読まずに止まる（読み替えると、片付けに要る記録を setup が上書きし、teardown が成功と報告する）。
 *
 * @return array<string,mixed>
 */
function cbjp_shot_state(): array {
	$raw = cbjp_shot_option_raw( CBJP_SHOT_STATE );

	if ( null === $raw ) {
		return [];
	}

	$state = maybe_unserialize( $raw );
	if ( ! is_array( $state ) ) {
		cbjp_shot_fail( 'the record of the screenshots (option ' . CBJP_SHOT_STATE . ') is broken: ' . wp_json_encode( $state ) . '. Not touching anything; check the tests site by hand.' );
	}

	return $state;
}

/**
 * 記録の一覧（`terms`・`runs`）。無ければ空。配列でなければ空と読まずに止まる（読み替えると、setup が上書きし、teardown が作ったものを残して成功と報告する）。
 *
 * @param array<string,mixed> $state
 * @return array<int|string,mixed>
 */
function cbjp_shot_state_list( array $state, string $key ): array {
	if ( ! array_key_exists( $key, $state ) ) {
		return [];
	}
	if ( ! is_array( $state[ $key ] ) ) {
		cbjp_shot_fail( "the record of the screenshots ({$key}) is broken: " . wp_json_encode( $state[ $key ] ) . '. Not touching anything; check the tests site by hand.' );
	}

	return $state[ $key ];
}

/**
 * 記録の run の ID（撮影が始めた run）。一覧でない・空でない文字列でない ID があれば、壊れた記録として止まる。
 *
 * @param array<string,mixed> $state
 * @return array<int,string>
 */
function cbjp_shot_recorded_runs( array $state ): array {
	$runs = cbjp_shot_state_list( $state, 'runs' );

	foreach ( $runs as $run_id ) {
		if ( ! is_string( $run_id ) || '' === $run_id ) {
			cbjp_shot_fail( 'the record of the runs the screenshots started is broken: ' . wp_json_encode( $runs ) . '. Not touching anything; check the tests site by hand.' );
		}
	}

	return array_values( $runs );
}

/**
 * 記録の term の ID（撮影が作ったカテゴリ）。一覧でない・正の int でない ID があれば、壊れた記録として止まる。
 *
 * @param array<string,mixed> $state
 * @return array<int,int>
 */
function cbjp_shot_recorded_terms( array $state ): array {
	$terms = cbjp_shot_state_list( $state, 'terms' );

	foreach ( $terms as $term_id ) {
		if ( ! is_int( $term_id ) || $term_id <= 0 ) {
			cbjp_shot_fail( 'the record of the product categories the screenshots made is broken: ' . wp_json_encode( $terms ) . '. Not touching anything; check the tests site by hand.' );
		}
	}

	return array_values( $terms );
}

/**
 * 記録の `$key` に控える option の名前（戻す順）。控えはこの名前をちょうど持つ。
 *
 * @return array<int,string>
 */
function cbjp_shot_snapshot_names( string $key ): array {
	return match ( $key ) {
		'site'     => array_keys( CBJP_SHOT_SITE_OPTIONS ),
		'mappings' => [ 'cbjp_settings_colorme' ],
	};
}

/**
 * 記録の `$key` に控えた option の値（option の名前 => 書き換える前の DB の生の値。null は「無かった」）。控えが無ければ null。
 * 壊れていれば止まる。控える名前（`cbjp_shot_snapshot_names()`）の一部しか無い・ほかの名前がある控えも止まる（片付けが書き換えた option を残す・
 * 撮影が触れていない option を書き換えて、成功と報告しないように）。
 *
 * @param array<string,mixed> $state
 * @return array<string,?string>|null
 */
function cbjp_shot_snapshot( array $state, string $key ): ?array {
	if ( ! array_key_exists( $key, $state ) ) {
		return null;
	}

	$previous = $state[ $key ];

	if ( ! is_array( $previous ) ) {
		cbjp_shot_fail( "the record of the options before the screenshots ({$key}) is broken: " . wp_json_encode( $previous ) . '.' );
	}
	foreach ( $previous as $name => $raw ) {
		if ( ! is_string( $name ) || ( null !== $raw && ! is_string( $raw ) ) ) {
			cbjp_shot_fail( "the record of the options before the screenshots ({$key}) is broken: " . wp_json_encode( $previous ) . '.' );
		}
	}

	$listed   = array_keys( $previous );
	$expected = cbjp_shot_snapshot_names( $key );
	sort( $listed );
	sort( $expected );
	if ( $listed !== $expected ) {
		cbjp_shot_fail( "the record of the options before the screenshots ({$key}) lists " . implode( ', ', array_keys( $previous ) ) . ' instead of ' . implode( ', ', cbjp_shot_snapshot_names( $key ) ) . '. Not touching anything; check the tests site by hand.' );
	}

	return $previous;
}

/**
 * 記録を、何かを変える前にまとめて確かめる（壊れた一覧・控えを、書き換えた後や片付けの途中で見つけないように）。setup と teardown の最初に呼ぶ。
 */
function cbjp_shot_check_state(): void {
	$state = cbjp_shot_state();

	cbjp_shot_recorded_runs( $state );
	cbjp_shot_recorded_terms( $state );
	cbjp_shot_snapshot( $state, 'site' );
	cbjp_shot_snapshot( $state, 'mappings' );

	$unknown = array_diff( array_keys( $state ), [ 'runs', 'terms', 'site', 'mappings' ] );
	if ( [] !== $unknown ) {
		cbjp_shot_fail( 'the record of the screenshots has unknown entries: ' . implode( ', ', $unknown ) . '. Not touching anything; check the tests site by hand.' );
	}
}

/**
 * option の DB の生の値。行が無ければ null。`get_option()` はこのリクエストのキャッシュに頼る（`delete_option()` は DB の削除が失敗しても
 * 「無い」と記録する）ので、消せた・戻せたことの確認には使わない。照会が失敗したら止まる。
 */
function cbjp_shot_option_raw( string $name ): ?string {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );

	if ( '' !== $wpdb->last_error ) {
		cbjp_shot_fail( "could not read the option {$name}: " . $wpdb->last_error );
	}

	return is_array( $row ) ? (string) $row['option_value'] : null;
}

/**
 * option を消し、DB から行が消えたことを確かめる。
 */
function cbjp_shot_delete_option( string $name ): void {
	delete_option( $name );

	if ( null !== cbjp_shot_option_raw( $name ) ) {
		cbjp_shot_fail( "the option {$name} is still saved after deleting it." );
	}
}

/**
 * option を控えた値（`cbjp_shot_option_raw()` の値。null は「無かった」）に戻し、DB の値で確かめる。
 */
function cbjp_shot_restore_option( string $name, ?string $raw ): void {
	if ( null === $raw ) {
		cbjp_shot_delete_option( $name );
		return;
	}

	update_option( $name, maybe_unserialize( $raw ) );

	if ( cbjp_shot_option_raw( $name ) !== $raw ) {
		cbjp_shot_fail( "could not restore the option {$name} to its value before the screenshots." );
	}
}

/**
 * 書き換える前の option（`cbjp_shot_snapshot_names()`）の値を、記録の `$key` に控える。控えが既にあれば（前の撮影が途中で止まった）、
 * 撮影の前の値を残すために書き換えない（控えが壊れていない・名前が揃っていることは `cbjp_shot_snapshot()` が確かめる）。
 */
function cbjp_shot_remember_options( string $key ): void {
	$state = cbjp_shot_state();

	if ( null !== cbjp_shot_snapshot( $state, $key ) ) {
		return;
	}

	$previous = [];
	foreach ( cbjp_shot_snapshot_names( $key ) as $name ) {
		$previous[ $name ] = cbjp_shot_option_raw( $name );
	}

	$state[ $key ] = $previous;
	cbjp_shot_save_state( $state );
}

/**
 * 記録の `$key` に控えた option を戻し、控えを消す。控えが無ければ（書き換える前に止まった）何もしない。
 */
function cbjp_shot_restore_options( string $key ): void {
	$state    = cbjp_shot_state();
	$previous = cbjp_shot_snapshot( $state, $key );

	if ( null === $previous ) {
		return;
	}

	foreach ( $previous as $name => $raw ) {
		cbjp_shot_restore_option( $name, $raw );
	}

	unset( $state[ $key ] );
	cbjp_shot_save_state( $state );
}

/**
 * @param array<string,mixed> $state
 */
function cbjp_shot_save_state( array $state ): void {
	// `update_option()` は新しい値をこのリクエストのキャッシュの値と比べ、同じなら書かない。DB の値と比べさせる。
	wp_cache_delete( CBJP_SHOT_STATE, 'options' );

	if ( [] === $state ) {
		cbjp_shot_delete_option( CBJP_SHOT_STATE );
	} else {
		update_option( CBJP_SHOT_STATE, $state, false );
		wp_set_option_autoload( CBJP_SHOT_STATE, false );
	}

	if ( cbjp_shot_state() !== $state ) {
		cbjp_shot_fail( 'could not save the record of what the screenshots made (option ' . CBJP_SHOT_STATE . ').' );
	}
}

/**
 * 保存された `colorme` のトークンが無いか、撮影用の偽のトークンか。復号できない値は `TokenStore::get()` が null を返すので、生の値で有無を見る。
 * 空の値の行も「無い」と読まない（setup が偽のトークンで上書きし、teardown が行ごと消すので、元の行に戻らない）。
 */
function cbjp_shot_token_is_ours_or_absent(): bool {
	$raw = cbjp_shot_option_raw( 'cbjp_token_colorme' );

	if ( null === $raw ) {
		return true;
	}

	return CBJP_SHOT_TOKEN === ( ( new TokenStore( 'colorme' ) )->get()['access_token'] ?? null );
}

/**
 * readme の画面は無料版だけ（R3-6d）。登録された実体の種類がこれに収まることを確かめる（Pro 以外の拡張が種類を足していても、無料版の画面ではなくなる）。
 */
const CBJP_SHOT_FREE_TYPES = [ 'category', 'tag', 'product', 'stock', 'review' ];

/**
 * setup の前提を肯定形で確かめる。どれかが欠ければ何も変えずに止まる。
 *
 * @param array<int,mixed> $args `wp eval-file` の引数（tests サイトの URL・dry-run するエンティティ〔カンマ区切り。`shots.json` の `setup_args`〕）。
 * @return array<int,string> dry-run するエンティティ。
 */
function cbjp_shot_guard_setup( array $args ): array {
	$expected = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
	$entities = isset( $args[1] ) && is_string( $args[1] ) ? array_values( array_filter( explode( ',', $args[1] ) ) ) : [];

	if ( '' === $expected || home_url() !== $expected ) {
		cbjp_shot_fail( 'home_url ' . home_url() . " is not the tests site '{$expected}'. Refusing to touch this site." );
	}
	if ( ! defined( 'CBJP_SCREENSHOT_FIXTURES' ) ) {
		cbjp_shot_fail( 'the screenshot mu-plugin is not loaded, so requests would reach the real Color Me Shop API.' );
	}
	if ( ! function_exists( 'WC' ) || ! class_exists( AdapterRegistry::class ) || ! AdapterRegistry::get( 'colorme' ) instanceof ColorMeAdapter ) {
		cbjp_shot_fail( "WooCommerce or the free plugin is not active, or 'colorme' is not the bundled ColorMeAdapter." );
	}
	// Pro が有効だと顧客・受注・クーポンの選択肢と決済・配送のマッピングの節が写る（R3-6d）。
	if ( defined( 'CBJP_PRO_VERSION' ) ) {
		cbjp_shot_fail( 'the Pro add-on is loaded. The screenshots show the free plugin alone (shots.json deactivates cart-bridge-jp-pro).' );
	}
	$extra = array_diff( EntityTypeRegistry::keys(), CBJP_SHOT_FREE_TYPES );
	if ( [] !== $extra ) {
		cbjp_shot_fail( "entity types other than the free plugin's are registered: " . implode( ', ', $extra ) . '.' );
	}
	if ( [] === $entities ) {
		cbjp_shot_fail( 'no entities to dry-run were given.' );
	}
	if ( ! user_can( 1, 'manage_woocommerce' ) ) {
		cbjp_shot_fail( 'user 1 cannot manage WooCommerce on the tests site.' );
	}
	// 別のトークン（誰かが tests サイトを店舗につないだ）を上書きすると元の接続が失われる（PR #112 G2-1・G2-2）。
	if ( ! cbjp_shot_token_is_ours_or_absent() ) {
		cbjp_shot_fail( 'a colorme token other than the screenshot dummy (or one that cannot be decrypted) is saved on the tests site. Refusing to overwrite it.' );
	}

	wp_set_current_user( 1 );

	return $entities;
}

/**
 * 撮影の間だけ書き換えるサイトの option（サイト名・通貨・国。オンボーディングとストアの「近日公開」を外す）。
 * ユーザー 1 の言語は capture.sh が `shots.json` の `locale`（既定 en_US）で先に英語にする（`wait_for` は英語の文言）。
 */
const CBJP_SHOT_SITE_OPTIONS = [
	'blogname'                       => 'Example Store',
	'woocommerce_currency'           => 'JPY',
	'woocommerce_default_country'    => 'JP:JP13',
	'woocommerce_onboarding_profile' => [ 'skipped' => true ],
	'woocommerce_coming_soon'        => 'no',
	'woocommerce_store_pages_only'   => 'no',
];

/**
 * サイトを撮影向けにする。書き換える前の値を控え、片付けが戻す。
 */
function cbjp_shot_prepare_site(): void {
	cbjp_shot_remember_options( 'site' );

	foreach ( CBJP_SHOT_SITE_OPTIONS as $name => $value ) {
		update_option( $name, $value );
	}
}

/**
 * 偽のトークンで `colorme` に接続した状態にする（tests サイトだけ）。
 */
function cbjp_shot_save_token(): void {
	( new TokenStore( 'colorme' ) )->save(
		[
			'access_token' => CBJP_SHOT_TOKEN,
			'settings'     => [
				'client_id'     => 'example-client-id',
				'client_secret' => 'example-client-secret',
			],
			'extras'       => [ 'contract_plan' => 'premium' ],
		]
	);

	if ( CBJP_SHOT_TOKEN !== ( ( new TokenStore( 'colorme' ) )->get()['access_token'] ?? null ) ) {
		cbjp_shot_fail( 'could not save the dummy token.' );
	}
}

/**
 * 偽のトークンを消す（片付けの前提で、撮影用か無いことを確かめてから呼ぶ）。`TokenStore::delete()` が消す 2 つの option が DB から消えたことを確かめる。
 * トークンが無くても更新のロックの行が残っていれば消す（前の片付けがトークンだけ消してロックを消せずに止まったとき、やり直しで残さない）。
 */
function cbjp_shot_delete_token(): void {
	if ( null === cbjp_shot_option_raw( 'cbjp_token_colorme' ) && null === cbjp_shot_option_raw( 'cbjp_token_lock_colorme' ) ) {
		echo "token: none\n";
		return;
	}

	( new TokenStore( 'colorme' ) )->delete();

	foreach ( [ 'cbjp_token_colorme', 'cbjp_token_lock_colorme' ] as $name ) {
		if ( null !== cbjp_shot_option_raw( $name ) ) {
			cbjp_shot_fail( "the option {$name} is still saved after deleting the dummy token." );
		}
	}

	echo "token: deleted the dummy token\n";
}

/**
 * Mappings タブのために作る商品のカテゴリーの名前（片付けは、記録の ID の term がこの名前のときだけ消す）。
 */
const CBJP_SHOT_TERM_NAMES = [ 'Apparel', 'Accessories' ];

/**
 * Mappings タブに出す対応を、REST の候補一覧から作って保存する（フィクスチャの ID を書き写さない）。無料版のマッピングはカテゴリだけで、
 * Woo のカテゴリを 2 つ作って Color Me Shop の先頭 2 つに対応させ、作ったタームを記録する（決済・配送・注文ステータスは Pro の節。R3-6d）。
 * 同じ名前のカテゴリが既にあれば記録せずに使う（撮影が作ったと確かめられないので、片付けは消さない）。保存先の option の前の値を控え、片付けが戻す。
 */
function cbjp_shot_save_mappings(): void {
	cbjp_shot_remember_options( 'mappings' );

	$candidates = cbjp_shot_rest( 'GET', '/cbjp/v1/settings/mappings/colorme' );

	if ( 200 !== $candidates['status'] || ! is_array( $candidates['data'] ) ) {
		cbjp_shot_fail( 'GET mappings returned HTTP ' . $candidates['status'] . ': ' . wp_json_encode( $candidates['data'] ) );
	}

	$asp = is_array( $candidates['data']['asp_candidates'] ?? null ) ? $candidates['data']['asp_candidates'] : [];
	$ids = static function ( $list ): array {
		return is_array( $list ) ? array_values( array_filter( array_map( static fn( $item ) => is_array( $item ) ? (string) ( $item['id'] ?? '' ) : '', $list ) ) ) : [];
	};

	$state    = cbjp_shot_state();
	$terms    = cbjp_shot_recorded_terms( $state );
	$asp_cats = $ids( $asp['category'] ?? null );
	$category = [];
	foreach ( CBJP_SHOT_TERM_NAMES as $i => $name ) {
		if ( ! isset( $asp_cats[ $i ] ) ) {
			break;
		}
		$term = term_exists( $name, 'product_cat' );
		if ( ! is_array( $term ) ) {
			$term = wp_insert_term( $name, 'product_cat' );
			if ( is_wp_error( $term ) ) {
				cbjp_shot_fail( "could not create the product category {$name}: " . $term->get_error_message() );
			}
			$terms[]        = (int) $term['term_id'];
			$state['terms'] = $terms;
			cbjp_shot_save_state( $state );
		}
		$category[ (string) $term['term_id'] ] = $asp_cats[ $i ];
	}

	$maps = [
		'category_map' => $category,
	];
	foreach ( $maps as $key => $map ) {
		if ( [] === $map ) {
			cbjp_shot_fail( "{$key} would be empty (candidates: " . wp_json_encode( $candidates['data'] ) . ').' );
		}
	}

	$put = cbjp_shot_rest( 'PUT', '/cbjp/v1/settings/mappings/colorme', $maps );
	if ( 200 !== $put['status'] ) {
		cbjp_shot_fail( 'PUT mappings returned HTTP ' . $put['status'] . ': ' . wp_json_encode( $put['data'] ) );
	}
	echo 'mappings: ' . implode( ' ', array_map( static fn( $k, $m ) => $k . '=' . count( $m ), array_keys( $maps ), $maps ) ) . "\n";
}

/**
 * 商品のカテゴリーの term の名前と term_taxonomy_id（DB の行で読む）。無ければ null。`get_term()` は照会の失敗も「無い」（null）と返すので、
 * 「もう無い」と読んで消すのを飛ばさないよう使わない。照会が失敗したら止まる。
 *
 * @return array{name:string,term_taxonomy_id:int}|null
 */
function cbjp_shot_product_cat( int $term_id ): ?array {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT t.name, tt.term_taxonomy_id FROM {$wpdb->terms} AS t INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = %s",
			$term_id,
			'product_cat'
		),
		ARRAY_A
	);

	if ( '' !== $wpdb->last_error ) {
		cbjp_shot_fail( "could not read the product category {$term_id}: " . $wpdb->last_error );
	}

	return is_array( $row ) ? [
		'name'             => (string) $row['name'],
		'term_taxonomy_id' => (int) $row['term_taxonomy_id'],
	] : null;
}

/**
 * WooCommerce の既定の商品カテゴリー（option `default_product_cat`。DB の行で読む）。無ければ 0。WooCommerce はインストールで term_taxonomy_id を、
 * 管理画面の「既定にする」で term_id を書くので、呼び出し側はどちらとも比べる。照会の失敗（`get_option()` では 0 と区別できない）・数でない値は止まる。
 */
function cbjp_shot_default_product_cat(): int {
	$raw = cbjp_shot_option_raw( 'default_product_cat' );

	if ( null === $raw ) {
		return 0;
	}
	if ( 1 !== preg_match( '/\A[0-9]+\z/', $raw ) ) {
		cbjp_shot_fail( 'the default product category (option default_product_cat) is not an ID: ' . wp_json_encode( $raw ) . '. Not deleting anything.' );
	}

	return (int) $raw;
}

/**
 * 作ったカテゴリを消し、対応付けを撮影の前の値に戻す。
 */
function cbjp_shot_remove_mappings(): void {
	$state   = cbjp_shot_state();
	$terms   = cbjp_shot_recorded_terms( $state );
	$default = cbjp_shot_default_product_cat();

	// 消す前にすべて確かめる。撮影が作った名前でない term・WooCommerce の既定のカテゴリー（`wp_delete_term()` は product_cat の既定を守らない）は、1 つも消さずに止まる。
	foreach ( $terms as $term_id ) {
		$cat = cbjp_shot_product_cat( $term_id );

		if ( null !== $cat && ( in_array( $default, [ $term_id, $cat['term_taxonomy_id'] ], true ) || ! in_array( $cat['name'], CBJP_SHOT_TERM_NAMES, true ) ) ) {
			cbjp_shot_fail( "the product category {$term_id} ({$cat['name']}) is not one that the screenshots made. Not deleting anything." );
		}
	}

	foreach ( $terms as $term_id ) {
		if ( null === cbjp_shot_product_cat( $term_id ) ) {
			continue;
		}
		if ( true !== wp_delete_term( $term_id, 'product_cat' ) || null !== cbjp_shot_product_cat( $term_id ) ) {
			cbjp_shot_fail( "could not delete the product category {$term_id}." );
		}
	}

	if ( array_key_exists( 'terms', $state ) ) {
		unset( $state['terms'] );
		cbjp_shot_save_state( $state );
	}

	cbjp_shot_restore_options( 'mappings' );
	echo "mappings: removed\n";
}

/**
 * 開いた（pending・running・paused のジョブを持つ）`colorme` の run の ID（DB の行で読む。古い順）。`GET /runs` の一覧
 * （`JobRepository::find_active_runs_for_platform()`）は照会の失敗も空の一覧として 200 で返すので、「開いた run は無い」と読んで記録を消さないよう使わない。
 * 照会が失敗したら止まる。
 *
 * @return array<int,string>
 */
function cbjp_shot_open_run_ids(): array {
	global $wpdb;

	$run_ids = $wpdb->get_col( $wpdb->prepare( "SELECT run_id FROM {$wpdb->prefix}cbjp_jobs WHERE platform = %s AND status IN ('pending', 'running', 'paused') GROUP BY run_id ORDER BY MIN(id) ASC", 'colorme' ) );

	if ( '' !== $wpdb->last_error || ! is_array( $run_ids ) ) {
		cbjp_shot_fail( 'could not read the open colorme runs: ' . $wpdb->last_error );
	}

	return array_map( 'strval', $run_ids );
}

/**
 * run をキャンセルし、ジョブがすべて閉じた（完了・失敗・キャンセル）ことを照会し直して確かめる。キャンセルの REST は DB の書き換えの結果を見ずに
 * 200 を返すので、200 だけでは成功とみなさない（開いたジョブを残したまま記録を消すと、片付けがモックを外した後にジョブが実 API へ進みうる）。
 * `cbjp_shot_fail()` からも呼ぶので、止めずに結果を返す。
 *
 * @return string キャンセルできたと確かめられなかった理由。確かめられたら空。
 */
function cbjp_shot_cancel_run( string $run_id ): string {
	$cancel = cbjp_shot_rest( 'POST', "/cbjp/v1/runs/{$run_id}/cancel" );
	if ( 200 !== $cancel['status'] ) {
		return "the cancel request returned HTTP {$cancel['status']}";
	}

	$after = cbjp_shot_rest( 'GET', "/cbjp/v1/runs/{$run_id}" );
	if ( 200 !== $after['status'] || ! is_array( $after['data']['jobs'] ?? null ) ) {
		return "the run could not be read after cancelling it (HTTP {$after['status']})";
	}

	$still = array_filter( $after['data']['jobs'], static fn( $job ): bool => ! is_array( $job ) || ! in_array( $job['status'] ?? null, [ 'completed', 'failed', 'cancelled' ], true ) );
	if ( [] !== $still ) {
		return 'it still has open jobs after cancelling it: ' . wp_json_encode( array_values( $still ) );
	}

	return '';
}

/**
 * 開いたままの `colorme` の run のうち、撮影が始めたもの（記録にある run。前の撮影が途中で止まった）をキャンセルし、記録から外す。
 * 記録に無い run が開いていれば、他の人・テストの run なので、1 つもキャンセルせずに止まる。キャンセルできたと確かめられなければ、記録を残して止まる。
 */
function cbjp_shot_cancel_open_runs(): void {
	$state = cbjp_shot_state();
	$ours  = cbjp_shot_recorded_runs( $state );
	$open  = cbjp_shot_open_run_ids();

	foreach ( $open as $run_id ) {
		if ( ! in_array( $run_id, $ours, true ) ) {
			cbjp_shot_fail( "a colorme run that the screenshots did not start is open: {$run_id}. Not cancelling it; let it finish or cancel it on the Tools tab first." );
		}
	}

	foreach ( $open as $run_id ) {
		$problem = cbjp_shot_cancel_run( $run_id );
		if ( '' !== $problem ) {
			cbjp_shot_fail( "could not cancel the open run {$run_id}: {$problem}." );
		}

		echo "cancelled the open run {$run_id}\n";
	}

	if ( array_key_exists( 'runs', $state ) ) {
		unset( $state['runs'] );
		cbjp_shot_save_state( $state );
	}
}

/**
 * dry-run を始め、Action Scheduler のジョブをここで処理し、すべてのジョブが完了したことを確かめて run の ID を返す。
 * claim はフック名だけで取り（グループで絞らない）、引数の job_id で自分の run のアクションか見分ける（`.claude/rules/skill-scripts.md`。
 * rehearse-colorme の run.php と同じ）。他の run のアクションは、ジョブが閉じている（完了・失敗・キャンセル）か存在しなければ処理して片付け、
 * 開いていれば手放す。処理できるアクションが無いのにジョブが開いているときは、paused からの再開待ち（予定時刻が先）なので待つ。
 *
 * @param array<int,string> $entities
 */
function cbjp_shot_run( array $entities ): string {
	global $wpdb;

	$started = cbjp_shot_rest(
		'POST',
		'/cbjp/v1/runs',
		[
			'type'     => 'dry_run',
			'platform' => 'colorme',
			'entities' => $entities,
		]
	);
	$run_id  = $started['data']['run_id'] ?? null;

	if ( ! is_string( $run_id ) || '' === $run_id ) {
		cbjp_shot_fail( 'POST /runs returned HTTP ' . $started['status'] . ': ' . wp_json_encode( $started['data'] ) );
	}

	$GLOBALS['cbjp_shot_run_id'] = $run_id;

	// 始めた run を記録する（強制終了で開いたまま残っても、次の撮影・片付けが自分の run と見分けてキャンセルする）。
	$state           = cbjp_shot_state();
	$state['runs']   = cbjp_shot_recorded_runs( $state );
	$state['runs'][] = $run_id;
	cbjp_shot_save_state( $state );

	$jobs_of_run = static function () use ( $run_id ): array {
		$result = cbjp_shot_rest( 'GET', "/cbjp/v1/runs/{$run_id}" );
		if ( 200 !== $result['status'] || ! is_array( $result['data']['jobs'] ?? null ) ) {
			cbjp_shot_fail( 'GET /runs/{id} returned HTTP ' . $result['status'] . ': ' . wp_json_encode( $result['data'] ) );
		}
		return array_values( array_filter( $result['data']['jobs'], 'is_array' ) );
	};
	$job_ids     = array_map( static fn( array $job ): int => (int) ( $job['id'] ?? 0 ), $jobs_of_run() );

	$store    = ActionScheduler::store();
	$runner   = ActionScheduler::runner();
	$deadline = time() + 5 * MINUTE_IN_SECONDS;
	$orphaned = 0;
	// 止めるときはループを抜けてから、エンティティごとの状態を出して判定する（どのジョブが止まったか分かるように。run.php と同じ）。
	$stopped = '';

	while ( true ) {
		$current = $jobs_of_run();
		$open    = array_filter( $current, static fn( array $job ): bool => in_array( $job['status'] ?? null, [ 'pending', 'running', 'paused' ], true ) );

		if ( [] === $open ) {
			break;
		}
		// 1 つのジョブが失敗すると、後ろのジョブはアクションの無い pending のまま進まない（JobManager は完了したときだけ次へ進める）。
		if ( [] !== array_filter( $current, static fn( array $job ): bool => 'failed' === ( $job['status'] ?? null ) ) ) {
			$stopped = 'a job failed, so the jobs after it cannot start';
			break;
		}
		if ( time() > $deadline ) {
			$stopped = 'it did not finish within 5 minutes';
			break;
		}

		$claim     = $store->stake_claim( 20, null, [ JobManager::ACTION_HOOK ] );
		$processed = 0;

		foreach ( $claim->get_actions() as $action_id ) {
			$action = $store->fetch_action( (string) $action_id );
			$args   = $action->get_args();
			$job_id = is_array( $args ) ? (int) ( $args['job_id'] ?? 0 ) : 0;

			if ( ! in_array( $job_id, $job_ids, true ) ) {
				// `get_var()` は空文字の値でも null を返し「行が無い」と区別できないので、`get_row()` で行の有無を見る（CLAUDE.md）。
				$other = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cbjp_jobs WHERE id = %d", $job_id ), ARRAY_A );
				// 照会の失敗は「行が無い（古いアクション）」と区別できないので、他の run のアクションを処理しないよう止まる。
				if ( '' !== $wpdb->last_error ) {
					$store->release_claim( $claim );
					cbjp_shot_fail( "could not read the job {$job_id} of a claimed action: " . $wpdb->last_error );
				}
				if ( null === $other || in_array( $other['status'] ?? null, [ 'completed', 'failed', 'cancelled' ], true ) ) {
					$runner->process_action( (int) $action_id, 'CBJP screenshots (stale)' );
				} else {
					$store->unclaim_action( (string) $action_id );
				}
				continue;
			}

			$runner->process_action( (int) $action_id, 'CBJP screenshots' );
			++$processed;
		}

		$store->release_claim( $claim );

		if ( 0 === $processed ) {
			// 自分のジョブのアクションが 1 つも無い（pending も実行中も無い）のにジョブが開いているなら、待っても進まない。
			// `as_get_scheduled_actions()` は照会が失敗しても空を返すので、`$wpdb->last_error` で見分けて止まる（「アクションが無い」と読まない）。
			$live = 0;
			foreach ( $job_ids as $job_id ) {
				foreach ( [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ] as $status ) {
					$found = as_get_scheduled_actions(
						[
							'hook'     => JobManager::ACTION_HOOK,
							'args'     => [ 'job_id' => $job_id ],
							'status'   => $status,
							'per_page' => 1,
						],
						'ids'
					);
					if ( '' !== $wpdb->last_error || ! is_array( $found ) ) {
						cbjp_shot_fail( "could not look for the actions of the job {$job_id}: " . $wpdb->last_error );
					}
					$live += count( $found );
				}
			}
			$orphaned = 0 === $live ? $orphaned + 1 : 0;
			if ( $orphaned >= 3 ) {
				$stopped = 'open jobs have no pending or running action, so the run cannot progress';
				break;
			}
			sleep( 5 );
		}
	}

	$jobs     = $jobs_of_run();
	$not_done = [];
	foreach ( $jobs as $job ) {
		echo ( $job['entity'] ?? '?' ) . ' ' . ( $job['status'] ?? '?' ) . ' ' . wp_json_encode( $job['totals'] ?? null ) . "\n";
		if ( 'completed' !== ( $job['status'] ?? null ) ) {
			$not_done[] = ( $job['entity'] ?? '?' ) . '=' . ( $job['status'] ?? '?' );
		}
	}

	$missing = array_diff( $entities, array_map( static fn( array $job ): string => (string) ( $job['entity'] ?? '' ), $jobs ) );
	if ( [] !== $missing ) {
		cbjp_shot_fail( 'no job was created for: ' . implode( ', ', $missing ) . ' (requested: ' . implode( ', ', $entities ) . '). Check setup_args in shots.json.' );
	}
	if ( [] !== $not_done || '' !== $stopped ) {
		cbjp_shot_fail( 'the dry run did not complete for every entity: ' . implode( ', ', $not_done ) . ( '' !== $stopped ? " ({$stopped})" : '' ) . '.' );
	}

	$GLOBALS['cbjp_shot_run_id'] = null;

	return $run_id;
}

/**
 * プラグインが無効なときに、撮影の記録・トークン（とその更新のロック）・開いた `colorme` の job が DB に残っているか（クラスが読み込まれていないので、行を直接読む）。
 * 残っていれば、モックを外した後にプラグインを有効化すると、予定済みのアクションが本物の Color Me Shop API に届きうる（PR #112 G3）。
 *
 * @return array<int,string> 残っているもの。
 */
function cbjp_shot_inactive_leftovers(): array {
	global $wpdb;

	$left = [];

	if ( null !== cbjp_shot_option_raw( CBJP_SHOT_STATE ) ) {
		$left[] = 'the record of the screenshots';
	}
	if ( null !== cbjp_shot_option_raw( 'cbjp_token_colorme' ) ) {
		$left[] = 'a colorme token';
	}
	// 有効なときの片付け（`cbjp_shot_delete_token()`）は、トークンが無くても更新のロックの行を消す。無効なときも残りとして数える。
	if ( null !== cbjp_shot_option_raw( 'cbjp_token_lock_colorme' ) ) {
		$left[] = 'the refresh lock of the colorme token';
	}

	$table  = $wpdb->prefix . 'cbjp_jobs';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	if ( '' !== $wpdb->last_error ) {
		cbjp_shot_fail( "could not look for the table {$table}: " . $wpdb->last_error );
	}
	if ( $table === $exists ) {
		$open = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE platform = %s AND status IN ('pending', 'running', 'paused')", 'colorme' ) );
		if ( '' !== $wpdb->last_error || null === $open ) {
			cbjp_shot_fail( 'could not count the open colorme jobs: ' . $wpdb->last_error );
		}
		if ( (int) $open > 0 ) {
			$left[] = "{$open} open colorme job(s)";
		}
	}

	return $left;
}

/**
 * 片付けの前提。tests サイトで、トークンが撮影のものか無いことを確かめる。プラグインか WooCommerce が無効なら、片付けられないので、
 * 撮影の記録・トークン（とその更新のロック）・開いた job が残っていれば止まる（無効化はデータを消さない）。何も残っていなければ何もしない（PHPUnit の後など）。
 *
 * @param array<int,mixed> $args
 * @return bool 片付けを続けるか（false なら何も残っていない）。
 */
function cbjp_shot_guard_teardown( array $args ): bool {
	$GLOBALS['cbjp_shot_who'] = 'teardown';
	$expected                 = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';

	if ( '' === $expected || home_url() !== $expected ) {
		cbjp_shot_fail( 'home_url ' . home_url() . " is not the tests site '{$expected}'. Refusing to touch this site." );
	}

	if ( ! function_exists( 'WC' ) || ! class_exists( TokenStore::class ) ) {
		// 壊れた記録も「残っている」と読む（DB の行の有無で見る）。
		$left = cbjp_shot_inactive_leftovers();
		if ( [] !== $left ) {
			cbjp_shot_fail( 'the plugin or WooCommerce is inactive on the tests site, but ' . implode( ', ', $left ) . ' remain(s). Activate them (npx wp-env run tests-cli wp plugin activate woocommerce cart-bridge-jp) while the mu-plugin is still installed, then run capture.sh cleanup again.' );
		}
		echo "teardown: the plugin is not active and nothing of the screenshots remains\n";
		return false;
	}

	if ( ! user_can( 1, 'manage_woocommerce' ) ) {
		cbjp_shot_fail( 'user 1 cannot manage WooCommerce on the tests site.' );
	}
	// 何かを消す・キャンセルする前に、トークンの持ち主を確かめる（PR #112 G2-3）。
	if ( ! cbjp_shot_token_is_ours_or_absent() ) {
		cbjp_shot_fail( 'a colorme token other than the screenshot dummy (or one that cannot be decrypted) is saved on the tests site. Not cancelling runs or deleting it.' );
	}

	wp_set_current_user( 1 );

	return true;
}

/**
 * 準備: 撮影向けのサイト・偽のトークン・カテゴリの対応付け・dry-run。`RUN_ID=` を出す（capture.sh が `shots.json` の `{RUN_ID}`
 * 〔Import タブが開く run の localStorage〕に入れる）。
 *
 * @param array<int,mixed> $args
 */
function cbjp_shot_setup( array $args ): void {
	$entities = cbjp_shot_guard_setup( $args );

	cbjp_shot_check_state();
	cbjp_shot_cancel_open_runs();
	cbjp_shot_prepare_site();
	cbjp_shot_save_token();
	cbjp_shot_save_mappings();

	$run_id = cbjp_shot_run( $entities );

	echo "RUN_ID={$run_id}\n";
}

/**
 * 片付け。撮影の記録にあるものだけを消し、最後に記録が空になったことを確かめる。
 *
 * @param array<int,mixed> $args
 */
function cbjp_shot_teardown( array $args ): void {
	if ( ! cbjp_shot_guard_teardown( $args ) ) {
		return;
	}

	cbjp_shot_check_state();
	cbjp_shot_cancel_open_runs();
	cbjp_shot_remove_mappings();
	cbjp_shot_delete_token();
	cbjp_shot_restore_options( 'site' );

	$left = cbjp_shot_state();
	if ( [] !== $left ) {
		cbjp_shot_fail( 'the record of the screenshots still lists: ' . implode( ', ', array_keys( $left ) ) . '.' );
	}

	echo "teardown: done\n";
}
