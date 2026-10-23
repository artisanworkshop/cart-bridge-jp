<?php
/**
 * Pro のマニュアル用スクリーンショットの準備と片付けの共通処理。`trial/`・`licensed/` の setup.php・teardown.php（共有スキル
 * wporg-screenshots のフック。capture.sh が tests サイトの mu-plugins の下へ写して `wp eval-file` で実行する）が、wp-env のルートの
 * マウント（wp-content/cbjp-dev）から読み込む。tests サイトだけで動く。
 *
 * 撮影で作ったものは option `cbjp_pro_manual_screenshots`（autoload なし）に記録し、片付けは記録にあるものと、記録の印（偽のトークン・
 * 偽のライセンスキー）が自分のものと確かめられたものだけを消す。他人のトークン・ライセンス・言語パック・run には触れずに止まる。
 * 書き換える option（サイトの表示・対応付け・ライセンスの記録）は、書き換える前の DB の値を記録に控え、片付けがそのまま戻す。
 *
 * PHPUnit は tests サイトと同じ DB・同じ接頭辞を使い、起動時に戻すのはコアのテーブル（オプション・投稿・ターム・ユーザー）だけ。
 * 受注（HPOS）・配送ゾーン・プラグインの表の行は残るので、取り込んだ受注・作った配送ゾーン・紐づけ（cbjp_mappings）は必ず消す。
 *
 * @package CartBridgeJP
 */

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Pro\License\LicenseStore;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Sync\JobManager;

if ( ! defined( 'CBJP_PM_TOKEN' ) ) {
	// 撮影用の偽のトークン（無料版の撮影の `screenshot-dummy-token` と別の値。どちらの撮影の片付けも、相手のトークンを消さない）。
	define( 'CBJP_PM_TOKEN', 'pro-manual-dummy-token' );
	// licensed の撮影で有効化の記録に使う偽のキー（販売サイトへは送らない。モックが止める）。
	define( 'CBJP_PM_LICENSE_KEY', 'MANUAL-EXAMPLE-KEY-0000-1234' );
	define( 'CBJP_PM_STATE', 'cbjp_pro_manual_screenshots' );
	define( 'CBJP_PM_ZONE_NAME', '国内' );
	define( 'CBJP_PM_I18N_DIR', WP_CONTENT_DIR . '/cbjp-dev/dist/pro-manual-i18n/' );
}

$GLOBALS['cbjp_pm_who']    = 'setup';
$GLOBALS['cbjp_pm_run_id'] = null;

/**
 * 止める。自分が始めた run があれば、キャンセルしてから終わる（開いた run が残ると、次の撮影と PHPUnit の一部が 409 になる）。
 */
function cbjp_pm_fail( string $message ): void {
	$run = $GLOBALS['cbjp_pm_run_id'] ?? null;

	if ( is_string( $run ) ) {
		$GLOBALS['cbjp_pm_run_id'] = null;
		$cancel                    = cbjp_pm_rest( 'POST', "/cbjp/v1/runs/{$run}/cancel" );
		$message                  .= " The run {$run} was cancelled (HTTP {$cancel['status']}).";
	}

	fwrite( STDERR, $GLOBALS['cbjp_pm_who'] . ": {$message}\n" );
	exit( 1 );
}

/**
 * @param array<string,mixed> $params
 * @return array{status:int,data:mixed}
 */
function cbjp_pm_rest( string $method, string $route, array $params = [] ): array {
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
 * 撮影の記録。無ければ空。壊れた値（配列でない）は空と読まずに止まる（読み替えると、片付けに要る記録を setup が上書きし、teardown が成功と報告する）。
 *
 * @return array<string,mixed>
 */
function cbjp_pm_state(): array {
	wp_cache_delete( CBJP_PM_STATE, 'options' );
	$state = get_option( CBJP_PM_STATE, null );

	if ( null === $state ) {
		return [];
	}
	if ( ! is_array( $state ) ) {
		cbjp_pm_fail( 'the record of the manual screenshots (option ' . CBJP_PM_STATE . ') is broken: ' . wp_json_encode( $state ) . '. Not touching anything; check the tests site by hand.' );
	}

	return $state;
}

/**
 * 記録の一覧（`i18n`・`terms`・`runs`）。無ければ空。配列でなければ空と読まずに止まる（読み替えると、setup が上書きし、teardown が置いたもの・作ったものを残して成功と報告する）。
 *
 * @param array<string,mixed> $state
 * @return array<int|string,mixed>
 */
function cbjp_pm_state_list( array $state, string $key ): array {
	if ( ! array_key_exists( $key, $state ) ) {
		return [];
	}
	if ( ! is_array( $state[ $key ] ) ) {
		cbjp_pm_fail( "the record of the manual screenshots ({$key}) is broken: " . wp_json_encode( $state[ $key ] ) . '. Not touching anything; check the tests site by hand.' );
	}

	return $state[ $key ];
}

/**
 * option の DB の生の値。行が無ければ null。`get_option()` はこのリクエストのキャッシュに頼る（`delete_option()` は DB の削除が失敗しても
 * 「無い」と記録する）ので、消せた・戻せたことの確認には使わない。照会が失敗したら止まる。
 */
function cbjp_pm_option_raw( string $name ): ?string {
	global $wpdb;

	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );

	if ( '' !== $wpdb->last_error ) {
		cbjp_pm_fail( "could not read the option {$name}: " . $wpdb->last_error );
	}

	return is_array( $row ) ? (string) $row['option_value'] : null;
}

/**
 * option を消し、DB から行が消えたことを確かめる。
 */
function cbjp_pm_delete_option( string $name ): void {
	delete_option( $name );

	if ( null !== cbjp_pm_option_raw( $name ) ) {
		cbjp_pm_fail( "the option {$name} is still saved after deleting it." );
	}
}

/**
 * option を控えた値（`cbjp_pm_option_raw()` の値。null は「無かった」）に戻し、DB の値で確かめる。
 */
function cbjp_pm_restore_option( string $name, ?string $raw ): void {
	if ( null === $raw ) {
		cbjp_pm_delete_option( $name );
		return;
	}

	update_option( $name, maybe_unserialize( $raw ) );

	if ( cbjp_pm_option_raw( $name ) !== $raw ) {
		cbjp_pm_fail( "could not restore the option {$name} to its value before the screenshots." );
	}
}

/**
 * 書き換える前の option の値を、記録の `$key` に控える。控えが既にあれば（前の撮影が途中で止まった）、撮影の前の値を残すために書き換えない。
 *
 * @param array<int,string> $names 戻す順（片付けはこの順で戻す）。
 */
function cbjp_pm_remember_options( string $key, array $names ): void {
	$state = cbjp_pm_state();

	if ( array_key_exists( $key, $state ) ) {
		return;
	}

	$previous = [];
	foreach ( $names as $name ) {
		$previous[ $name ] = cbjp_pm_option_raw( $name );
	}

	$state[ $key ] = $previous;
	cbjp_pm_save_state( $state );
}

/**
 * 記録の `$key` に控えた option を戻し、控えを消す。控えが無ければ（書き換える前に止まった）何もしない。
 */
function cbjp_pm_restore_options( string $key ): void {
	$state = cbjp_pm_state();

	if ( ! array_key_exists( $key, $state ) ) {
		return;
	}

	$previous = $state[ $key ];

	if ( ! is_array( $previous ) ) {
		cbjp_pm_fail( "the record of the options before the screenshots ({$key}) is broken: " . wp_json_encode( $previous ) . '.' );
	}
	foreach ( $previous as $name => $raw ) {
		if ( ! is_string( $name ) || ( null !== $raw && ! is_string( $raw ) ) ) {
			cbjp_pm_fail( "the record of the options before the screenshots ({$key}) is broken: " . wp_json_encode( $previous ) . '.' );
		}
	}

	foreach ( $previous as $name => $raw ) {
		cbjp_pm_restore_option( $name, $raw );
	}

	unset( $state[ $key ] );
	cbjp_pm_save_state( $state );
}

/**
 * @param array<string,mixed> $state
 */
function cbjp_pm_save_state( array $state ): void {
	if ( [] === $state ) {
		cbjp_pm_delete_option( CBJP_PM_STATE );
	} else {
		update_option( CBJP_PM_STATE, $state, false );
		wp_set_option_autoload( CBJP_PM_STATE, false );
	}

	if ( cbjp_pm_state() !== $state ) {
		cbjp_pm_fail( 'could not save the record of what the screenshots made (option ' . CBJP_PM_STATE . ').' );
	}
}

/**
 * 保存された `colorme` のトークンが無いか、撮影用の偽のトークンか。復号できない値は `TokenStore::get()` が null を返すので、生の値で有無を見る。
 */
function cbjp_pm_token_is_ours_or_absent(): bool {
	$raw = get_option( 'cbjp_token_colorme', null );

	if ( null === $raw || '' === $raw ) {
		return true;
	}

	return CBJP_PM_TOKEN === ( ( new TokenStore( 'colorme' ) )->get()['access_token'] ?? null );
}

/**
 * Pro のライセンスのキーが無いか、撮影用の偽のキーか（他人の有効化を上書き・削除しない）。
 */
function cbjp_pm_license_is_ours_or_absent(): bool {
	$raw = get_option( LicenseStore::KEY_OPTION, null );

	if ( null !== $raw && '' !== $raw ) {
		return CBJP_PM_LICENSE_KEY === ( new LicenseStore() )->key();
	}

	// キーが無いのに「有効化済み」の記録だけがある状態は、自分のものと確かめられない。
	return ! ( new LicenseStore() )->is_activated();
}

/**
 * setup の前提を肯定形で確かめる。どれかが欠ければ何も変えずに止まる。
 *
 * @param array<int,mixed> $args `wp eval-file` の引数（先頭が tests サイトの URL）。
 */
function cbjp_pm_guard_setup( array $args ): void {
	$expected = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';

	if ( '' === $expected || home_url() !== $expected ) {
		cbjp_pm_fail( 'home_url ' . home_url() . " is not the tests site '{$expected}'. Refusing to touch this site." );
	}
	if ( 'local' !== wp_get_environment_type() ) {
		cbjp_pm_fail( 'the environment type is not local.' );
	}
	if ( ! defined( 'CBJP_PRO_MANUAL_FIXTURES' ) ) {
		cbjp_pm_fail( 'the screenshot mock is not loaded, so requests would reach the real Color Me Shop API or the license server.' );
	}
	if ( ! function_exists( 'WC' ) || ! class_exists( AdapterRegistry::class ) || ! AdapterRegistry::get( 'colorme' ) instanceof ColorMeAdapter ) {
		cbjp_pm_fail( "WooCommerce or the free plugin is not active, or 'colorme' is not the bundled ColorMeAdapter." );
	}
	if ( ! defined( 'CBJP_PRO_VERSION' ) || ! class_exists( LicenseStore::class ) || ! \CartBridgeJP\Pro\Core\Plugin::instance()->is_booted() ) {
		cbjp_pm_fail( 'the Pro add-on is not loaded (shots.json activates cart-bridge-jp-pro).' );
	}
	$missing = array_diff( [ 'customer', 'order', 'coupon' ], EntityTypeRegistry::keys() );
	if ( [] !== $missing ) {
		cbjp_pm_fail( 'the Pro entity types are not registered: ' . implode( ', ', $missing ) . '.' );
	}
	if ( ! user_can( 1, 'manage_woocommerce' ) ) {
		cbjp_pm_fail( 'user 1 cannot manage WooCommerce on the tests site.' );
	}
	if ( ! cbjp_pm_token_is_ours_or_absent() ) {
		cbjp_pm_fail( 'a colorme token other than the manual screenshot dummy (or one that cannot be decrypted) is saved on the tests site. Refusing to overwrite it.' );
	}
	if ( ! cbjp_pm_license_is_ours_or_absent() ) {
		cbjp_pm_fail( 'a Pro license other than the manual screenshot dummy is saved on the tests site. Refusing to overwrite it.' );
	}

	wp_set_current_user( 1 );
}

/**
 * 撮影の間だけ書き換えるサイトの option（サイト名・通貨・国など。無料版の撮影と同じ値に、日本の店舗の表示〔ライセンスの画面の有効化の日時など〕を足した）。
 */
const CBJP_PM_SITE_OPTIONS = [
	'blogname'                       => 'Example Store',
	'woocommerce_currency'           => 'JPY',
	'woocommerce_default_country'    => 'JP:JP13',
	'woocommerce_onboarding_profile' => [ 'skipped' => true ],
	'woocommerce_coming_soon'        => 'no',
	'woocommerce_store_pages_only'   => 'no',
	'timezone_string'                => 'Asia/Tokyo',
	'date_format'                    => 'Y年n月j日',
	'time_format'                    => 'H:i',
];

/**
 * 写る値を撮影向けにする。前の値を控え、片付けが戻す。
 */
function cbjp_pm_prepare_site(): void {
	cbjp_pm_remember_options( 'site', array_keys( CBJP_PM_SITE_OPTIONS ) );

	foreach ( CBJP_PM_SITE_OPTIONS as $name => $value ) {
		update_option( $name, $value );
	}
}

/**
 * licensed の撮影: パスワード設定メールのリンクに要るマイアカウントのページを用意する（tests サイトには無い。無いと送信の節の代わりに
 * 警告が写る。`PasswordEmail\AccountLinks::problem()`）。作ったページと前の設定値を記録し、片付けが消して戻す。
 */
function cbjp_pm_ensure_account_page(): void {
	$page_id = (int) wc_get_page_id( 'myaccount' );

	if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
		return;
	}

	$state            = cbjp_pm_state();
	$state['account'] = [ 'previous' => cbjp_pm_option_raw( 'woocommerce_myaccount_page_id' ) ];
	cbjp_pm_save_state( $state );

	$created = wp_insert_post(
		[
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'マイアカウント',
			'post_content' => '<!-- wp:shortcode -->[woocommerce_my_account]<!-- /wp:shortcode -->',
		],
		true
	);

	if ( is_wp_error( $created ) || $created <= 0 ) {
		cbjp_pm_fail( 'could not create the My account page.' );
	}

	$state['account']['page_id'] = (int) $created;
	cbjp_pm_save_state( $state );
	update_option( 'woocommerce_myaccount_page_id', (int) $created );
	echo "account: created the My account page\n";
}

function cbjp_pm_remove_account_page(): void {
	$state   = cbjp_pm_state();
	$account = $state['account'] ?? null;

	if ( ! is_array( $account ) ) {
		return;
	}

	$previous = $account['previous'] ?? null;

	if ( null !== $previous && ! is_string( $previous ) ) {
		cbjp_pm_fail( 'the record of the My account page is broken: ' . wp_json_encode( $account ) . '.' );
	}

	if ( is_int( $account['page_id'] ?? null ) && 'page' === get_post_type( $account['page_id'] ) ) {
		if ( ! wp_delete_post( $account['page_id'], true ) ) {
			cbjp_pm_fail( "could not delete the My account page {$account['page_id']}." );
		}
	}

	cbjp_pm_restore_option( 'woocommerce_myaccount_page_id', $previous );

	unset( $state['account'] );
	cbjp_pm_save_state( $state );
	echo "account: removed the My account page\n";
}

/**
 * 無料版の日本語訳（build.sh が dist/pro-manual-i18n/ に作ったもの）を、言語パックの置き場所（WP_LANG_DIR/plugins/）に置く。
 * 同じ名前のファイルが記録に無いまま有れば（本物の言語パックなど）、記録にあっても中身が置いたときと違えば（言語パックの更新が置き換えた）止まる。
 * 置いたファイルは記録し、片付けが SHA-256 の一致を確かめてから消す。
 */
function cbjp_pm_install_translations(): void {
	$sources = glob( CBJP_PM_I18N_DIR . 'cart-bridge-jp-ja*' );
	$sources = is_array( $sources ) ? array_values( array_filter( $sources, static fn( string $f ): bool => (bool) preg_match( '/\.(mo|json|l10n\.php)\z/', $f ) ) ) : [];

	if ( [] === $sources ) {
		cbjp_pm_fail( 'no compiled translation in ' . CBJP_PM_I18N_DIR . ' (shots.json runs build.sh before shooting).' );
	}

	$dir = WP_LANG_DIR . '/plugins/';

	if ( ! wp_mkdir_p( $dir ) ) {
		cbjp_pm_fail( "could not create {$dir}." );
	}

	$state = cbjp_pm_state();
	$i18n  = cbjp_pm_state_list( $state, 'i18n' );

	foreach ( $sources as $source ) {
		$name = basename( $source );
		$dest = $dir . $name;

		if ( file_exists( $dest ) && ! isset( $i18n[ $name ] ) ) {
			cbjp_pm_fail( "{$dest} exists but was not placed by these screenshots (a language pack?). Refusing to overwrite it." );
		}
		// 前の撮影が置いたまま止まった後に、言語パックの更新などが置き換えていれば上書きしない（片付けも同じ確かめで止まる）。
		if ( file_exists( $dest ) && hash_file( 'sha256', $dest ) !== $i18n[ $name ] ) {
			cbjp_pm_fail( "{$dest} changed after the screenshots placed it (a language pack update?). Refusing to overwrite it." );
		}

		// 先に記録する（コピーの途中で止まっても片付けが探せるように）。値は置いた後の中身で書き直す。
		$i18n[ $name ] = (string) hash_file( 'sha256', $source );
		$state['i18n'] = $i18n;
		cbjp_pm_save_state( $state );

		if ( ! copy( $source, $dest ) || hash_file( 'sha256', $dest ) !== $i18n[ $name ] ) {
			cbjp_pm_fail( "could not copy {$name} to {$dir}." );
		}
	}

	echo 'translations: placed ' . count( $sources ) . " file(s) of the free plugin's Japanese translation\n";
}

/**
 * 置いた訳を外す。中身が置いたときと違うファイル（誰かが置き換えた）は消さずに止まる。
 */
function cbjp_pm_remove_translations(): void {
	$state = cbjp_pm_state();
	$i18n  = cbjp_pm_state_list( $state, 'i18n' );
	$count = count( $i18n );

	foreach ( $i18n as $name => $hash ) {
		$dest = WP_LANG_DIR . '/plugins/' . basename( (string) $name );

		if ( file_exists( $dest ) ) {
			if ( hash_file( 'sha256', $dest ) !== $hash ) {
				cbjp_pm_fail( "{$dest} changed after the screenshots placed it. Not deleting it." );
			}
			if ( ! unlink( $dest ) || file_exists( $dest ) ) {
				cbjp_pm_fail( "could not delete {$dest}." );
			}
		}

		unset( $i18n[ $name ] );
		$state['i18n'] = $i18n;
		cbjp_pm_save_state( $state );
	}

	unset( $state['i18n'] );
	cbjp_pm_save_state( $state );
	echo "translations: removed {$count} file(s)\n";
}

/**
 * 偽のトークンで `colorme` に接続した状態にする（スコープの記録は書かない＝旧版の 5 つを持つとみなされ、Pro の種類が使える）。
 */
function cbjp_pm_save_token(): void {
	( new TokenStore( 'colorme' ) )->save(
		[
			'access_token' => CBJP_PM_TOKEN,
			'settings'     => [
				'client_id'     => 'example-client-id',
				'client_secret' => 'example-client-secret',
			],
			'extras'       => [ 'contract_plan' => 'premium' ],
		]
	);

	if ( CBJP_PM_TOKEN !== ( ( new TokenStore( 'colorme' ) )->get()['access_token'] ?? null ) ) {
		cbjp_pm_fail( 'could not save the dummy token.' );
	}
}

/**
 * 偽のトークンを消す（片付けの前提で、撮影用か無いことを確かめてから呼ぶ）。`TokenStore::delete()` が消す 2 つの option が DB から消えたことを確かめる。
 */
function cbjp_pm_delete_token(): void {
	if ( null === cbjp_pm_option_raw( 'cbjp_token_colorme' ) ) {
		echo "token: none\n";
		return;
	}

	( new TokenStore( 'colorme' ) )->delete();

	foreach ( [ 'cbjp_token_colorme', 'cbjp_token_lock_colorme' ] as $name ) {
		if ( null !== cbjp_pm_option_raw( $name ) ) {
			cbjp_pm_fail( "the option {$name} is still saved after deleting the dummy token." );
		}
	}

	echo "token: deleted the dummy token\n";
}

/**
 * Pro のライセンスの option。片付けはこの順で戻す（状態を先に戻し、キーを最後に消す。途中で止まっても「キーの無い有効化」を残さない）。
 *
 * @return array<int,string>
 */
function cbjp_pm_license_options(): array {
	return [ LicenseStore::STATE_OPTION, LicenseStore::LAST_CHECK_OPTION, LicenseStore::INSTANCE_OPTION, LicenseStore::KEY_OPTION ];
}

/**
 * licensed の撮影: 偽のキーで有効化の記録を作る（サーバーには問い合わせない。更新の確認はモックが止める）。
 */
function cbjp_pm_activate_license(): void {
	// 有効化の前の option の値を控え、片付けがそのまま戻す（Pro の無効化は「未有効化」の状態と instance を残すので、そのままだと撮影の前と違う）。
	// 控えが無いのに偽のキーがある（記録を失った）なら、撮影の前の値が分からないので止まる。
	if ( ! array_key_exists( 'license', cbjp_pm_state() ) && null !== cbjp_pm_option_raw( LicenseStore::KEY_OPTION ) ) {
		cbjp_pm_fail( 'the dummy license key is saved but the record of the manual screenshots does not list it, so the license options before the screenshots are unknown. Delete the ' . implode( ', ', cbjp_pm_license_options() ) . ' options on the tests site first.' );
	}
	cbjp_pm_remember_options( 'license', cbjp_pm_license_options() );

	if ( ! ( new LicenseStore() )->save_activation( CBJP_PM_LICENSE_KEY, defined( 'CBJP_PRO_LICENSE_PRODUCT_ID' ) ? (int) CBJP_PRO_LICENSE_PRODUCT_ID : 0 ) ) {
		cbjp_pm_fail( 'could not save the dummy license activation.' );
	}

	// 有効化の状態を変えたら、前の更新の確認の結果を捨てる（`LicenseManager::forget_update_info()` と同じ）。
	delete_site_transient( 'update_plugins' );
	echo "license: activated with the dummy key\n";
}

/**
 * 偽のキーの有効化を消し、ライセンスの option を有効化の前の値に戻す。キーが撮影用でなければ止まる。
 */
function cbjp_pm_clear_license(): void {
	if ( ! array_key_exists( 'license', cbjp_pm_state() ) ) {
		return;
	}
	if ( ! cbjp_pm_license_is_ours_or_absent() ) {
		cbjp_pm_fail( 'the saved Pro license is not the manual screenshot dummy. Not clearing it.' );
	}

	cbjp_pm_restore_options( 'license' );
	delete_site_transient( 'update_plugins' );
	echo "license: cleared the dummy activation\n";
}

/**
 * 配送方法の対応付けに出す配送ゾーン「国内」と定額送料を作る（tests サイトにあるかもしれない別のゾーン〔テストの残り〕には頼らない）。ID を先に記録し、片付けが消す。
 */
function cbjp_pm_ensure_shipping_zone(): void {
	$state = cbjp_pm_state();

	// 壊れた ID を「無い」と読んで新しいゾーンの ID で上書きすると、前に作ったゾーンを片付けが見失う。
	if ( array_key_exists( 'zone_id', $state ) && ! is_int( $state['zone_id'] ) ) {
		cbjp_pm_fail( 'the record of the shipping zone is broken: ' . wp_json_encode( $state['zone_id'] ) . '. Not touching anything; check the tests site by hand.' );
	}
	if ( is_int( $state['zone_id'] ?? null ) ) {
		$existing = WC_Shipping_Zones::get_zone( $state['zone_id'] );

		if ( $existing instanceof WC_Shipping_Zone && CBJP_PM_ZONE_NAME === $existing->get_zone_name() ) {
			return;
		}
	}

	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( CBJP_PM_ZONE_NAME );
	$zone->set_zone_order( 0 );
	$zone->add_location( 'JP', 'country' );
	$zone->save();

	if ( $zone->get_id() <= 0 ) {
		cbjp_pm_fail( 'could not create the shipping zone.' );
	}

	$state['zone_id'] = $zone->get_id();
	cbjp_pm_save_state( $state );

	$instance_id = $zone->add_shipping_method( 'flat_rate' );

	if ( $instance_id <= 0 ) {
		cbjp_pm_fail( 'could not add the flat rate to the shipping zone.' );
	}

	update_option(
		"woocommerce_flat_rate_{$instance_id}_settings",
		[
			'title'      => '宅配便',
			'tax_status' => 'taxable',
			'cost'       => '800',
		]
	);
	echo "shipping: created the zone '" . CBJP_PM_ZONE_NAME . "' with a flat rate\n";
}

function cbjp_pm_remove_shipping_zone(): void {
	$state = cbjp_pm_state();

	if ( ! is_int( $state['zone_id'] ?? null ) ) {
		return;
	}

	$zone = WC_Shipping_Zones::get_zone( $state['zone_id'] );

	if ( $zone instanceof WC_Shipping_Zone && $zone->get_id() > 0 ) {
		if ( CBJP_PM_ZONE_NAME !== $zone->get_zone_name() ) {
			cbjp_pm_fail( "the shipping zone {$state['zone_id']} is not the one the screenshots made. Not deleting it." );
		}
		foreach ( $zone->get_shipping_methods() as $method ) {
			cbjp_pm_delete_option( "woocommerce_{$method->id}_{$method->instance_id}_settings" );
		}
		$zone->delete();

		$left = WC_Shipping_Zones::get_zone( $state['zone_id'] );
		if ( $left instanceof WC_Shipping_Zone && $left->get_id() > 0 ) {
			cbjp_pm_fail( "could not delete the shipping zone {$state['zone_id']}." );
		}
	}

	unset( $state['zone_id'] );
	cbjp_pm_save_state( $state );
	echo "shipping: removed the zone\n";
}

/**
 * 対応付け（カテゴリ・決済・配送・注文ステータス）を、REST の候補一覧から作って保存する（フィクスチャの ID を書き写さない）。
 * カテゴリはエクスポートの向き（Woo → カラーミー）なので、Woo のカテゴリを 2 つ作って先頭 2 つに対応させ、作ったタームを記録する。
 * 保存先の option の前の値（tests サイトに対応付けがあれば）を控え、片付けが戻す。
 */
function cbjp_pm_save_mappings(): void {
	cbjp_pm_remember_options( 'mappings', [ 'cbjp_settings_colorme' ] );

	$candidates = cbjp_pm_rest( 'GET', '/cbjp/v1/settings/mappings/colorme' );

	if ( 200 !== $candidates['status'] || ! is_array( $candidates['data'] ) ) {
		cbjp_pm_fail( 'GET mappings returned HTTP ' . $candidates['status'] . ': ' . wp_json_encode( $candidates['data'] ) );
	}

	$asp  = is_array( $candidates['data']['asp_candidates'] ?? null ) ? $candidates['data']['asp_candidates'] : [];
	$woo  = is_array( $candidates['data']['woo_candidates'] ?? null ) ? $candidates['data']['woo_candidates'] : [];
	$rows = static function ( $list ): array {
		return is_array( $list ) ? array_values( array_filter( $list, static fn( $item ): bool => is_array( $item ) && is_scalar( $item['id'] ?? null ) ) ) : [];
	};

	// カテゴリ: Woo で作ったカテゴリ → カラーミーのカテゴリ。
	$state     = cbjp_pm_state();
	$terms     = cbjp_pm_state_list( $state, 'terms' );
	$asp_cats  = $rows( $asp['category'] ?? null );
	$categorys = [];
	foreach ( [ 'ギフト', '季節のおすすめ' ] as $i => $name ) {
		if ( ! isset( $asp_cats[ $i ] ) ) {
			break;
		}
		$term = term_exists( $name, 'product_cat' );
		if ( ! is_array( $term ) ) {
			$term = wp_insert_term( $name, 'product_cat' );
			if ( is_wp_error( $term ) ) {
				cbjp_pm_fail( "could not create the product category {$name}: " . $term->get_error_message() );
			}
			$terms[]        = (int) $term['term_id'];
			$state['terms'] = $terms;
			cbjp_pm_save_state( $state );
		}
		$categorys[ (string) $term['term_id'] ] = (string) $asp_cats[ $i ]['id'];
	}

	// 決済: 名前で WooCommerce の標準の決済に当てる（代引き → cod、振込 → bacs）。
	$woo_payments = array_column( $rows( $woo['payment'] ?? null ), 'id' );
	$payments     = [];
	foreach ( $rows( $asp['payment'] ?? null ) as $row ) {
		$name   = (string) ( $row['name'] ?? '' );
		$target = str_contains( $name, '代引' ) ? 'cod' : ( str_contains( $name, '振込' ) ? 'bacs' : null );
		if ( null !== $target && in_array( $target, $woo_payments, true ) ) {
			$payments[ (string) $row['id'] ] = $target;
		}
	}

	// 配送: 作ったゾーンの定額送料に当てる（候補の名前は「ゾーン名: 方法の名前」）。
	$ours      = array_values( array_filter( $rows( $woo['shipping'] ?? null ), static fn( array $item ): bool => str_starts_with( (string) ( $item['name'] ?? '' ), CBJP_PM_ZONE_NAME . ':' ) ) );
	$shippings = [];
	if ( isset( $ours[0] ) ) {
		foreach ( $rows( $asp['shipping'] ?? null ) as $row ) {
			$shippings[ (string) $row['id'] ] = (string) $ours[0]['id'];
		}
	}

	// 注文ステータス: 同じ名前（`wc-` の有無を問わない）の WooCommerce のステータスに当てる。
	$woo_statuses = array_column( $rows( $woo['status'] ?? null ), 'id' );
	$statuses     = [];
	foreach ( $rows( $asp['status'] ?? null ) as $row ) {
		$id = (string) $row['id'];
		foreach ( [ $id, "wc-{$id}" ] as $candidate ) {
			if ( in_array( $candidate, $woo_statuses, true ) ) {
				$statuses[ $id ] = $candidate;
				break;
			}
		}
	}

	$maps = [
		'category_map' => $categorys,
		'payment_map'  => $payments,
		'shipping_map' => $shippings,
		'status_map'   => $statuses,
	];
	foreach ( $maps as $key => $map ) {
		if ( [] === $map ) {
			cbjp_pm_fail( "{$key} would be empty (candidates: " . wp_json_encode( $candidates['data'] ) . ').' );
		}
	}

	$put = cbjp_pm_rest( 'PUT', '/cbjp/v1/settings/mappings/colorme', $maps );
	if ( 200 !== $put['status'] ) {
		cbjp_pm_fail( 'PUT mappings returned HTTP ' . $put['status'] . ': ' . wp_json_encode( $put['data'] ) );
	}
	echo 'mappings: ' . implode( ' ', array_map( static fn( $k, $m ) => $k . '=' . count( $m ), array_keys( $maps ), $maps ) ) . "\n";
}

/**
 * 作ったカテゴリを消し、対応付けを撮影の前の値に戻す。
 */
function cbjp_pm_remove_mappings(): void {
	$state = cbjp_pm_state();

	foreach ( cbjp_pm_state_list( $state, 'terms' ) as $term_id ) {
		if ( get_term( (int) $term_id, 'product_cat' ) instanceof WP_Term ) {
			$deleted = wp_delete_term( (int) $term_id, 'product_cat' );
			if ( true !== $deleted ) {
				cbjp_pm_fail( "could not delete the product category {$term_id}." );
			}
		}
	}

	if ( array_key_exists( 'terms', $state ) ) {
		unset( $state['terms'] );
		cbjp_pm_save_state( $state );
	}

	cbjp_pm_restore_options( 'mappings' );
	echo "mappings: removed\n";
}

/**
 * 開いたままの `colorme` の run のうち、撮影が始めたもの（記録にある run。前の撮影が途中で止まった）をキャンセルし、記録から外す。
 * 記録に無い run が開いていれば、他の人・テストの run なので、1 つもキャンセルせずに止まる。
 */
function cbjp_pm_cancel_open_runs(): void {
	$active = cbjp_pm_rest( 'GET', '/cbjp/v1/runs', [ 'platform' => 'colorme' ] );

	if ( 200 !== $active['status'] || ! is_array( $active['data']['runs'] ?? null ) ) {
		cbjp_pm_fail( 'GET /runs returned HTTP ' . $active['status'] . ': ' . wp_json_encode( $active['data'] ) );
	}

	$state = cbjp_pm_state();
	$ours  = cbjp_pm_state_list( $state, 'runs' );
	$open  = [];
	foreach ( $active['data']['runs'] as $run ) {
		$run_id = is_array( $run ) && is_string( $run['run_id'] ?? null ) ? $run['run_id'] : '';

		if ( '' === $run_id || ! in_array( $run_id, $ours, true ) ) {
			cbjp_pm_fail( 'a colorme run that the manual screenshots did not start is open: ' . wp_json_encode( $run ) . '. Not cancelling it; let it finish or cancel it on the Tools tab first.' );
		}

		$open[] = $run_id;
	}

	foreach ( $open as $run_id ) {
		$cancel = cbjp_pm_rest( 'POST', "/cbjp/v1/runs/{$run_id}/cancel" );

		if ( 200 !== $cancel['status'] ) {
			cbjp_pm_fail( "could not cancel the open run {$run_id} (HTTP {$cancel['status']})." );
		}

		echo "cancelled the open run {$run_id}\n";
	}

	if ( array_key_exists( 'runs', $state ) ) {
		unset( $state['runs'] );
		cbjp_pm_save_state( $state );
	}
}

/**
 * run を始め、Action Scheduler のジョブをここで処理し、すべてのジョブが完了したことを確かめて run の ID を返す
 * （無料版の撮影の setup.php と同じ処理。claim はフック名だけで取る。`.claude/rules/skill-scripts.md`）。
 *
 * @param string            $type     `dry_run` か `import`。
 * @param array<int,string> $entities
 */
function cbjp_pm_run( string $type, array $entities ): string {
	global $wpdb;

	$started = cbjp_pm_rest(
		'POST',
		'/cbjp/v1/runs',
		[
			'type'     => $type,
			'platform' => 'colorme',
			'entities' => $entities,
		]
	);
	$run_id  = $started['data']['run_id'] ?? null;

	if ( ! is_string( $run_id ) || '' === $run_id ) {
		cbjp_pm_fail( "POST /runs ({$type}) returned HTTP " . $started['status'] . ': ' . wp_json_encode( $started['data'] ) );
	}

	$GLOBALS['cbjp_pm_run_id'] = $run_id;

	// 始めた run を記録する（強制終了で開いたまま残っても、次の撮影・片付けが自分の run と見分けてキャンセルする）。
	$state           = cbjp_pm_state();
	$state['runs']   = cbjp_pm_state_list( $state, 'runs' );
	$state['runs'][] = $run_id;
	cbjp_pm_save_state( $state );

	$jobs_of_run = static function () use ( $run_id ): array {
		$result = cbjp_pm_rest( 'GET', "/cbjp/v1/runs/{$run_id}" );
		if ( 200 !== $result['status'] || ! is_array( $result['data']['jobs'] ?? null ) ) {
			cbjp_pm_fail( 'GET /runs/{id} returned HTTP ' . $result['status'] . ': ' . wp_json_encode( $result['data'] ) );
		}
		return array_values( array_filter( $result['data']['jobs'], 'is_array' ) );
	};
	$job_ids     = array_map( static fn( array $job ): int => (int) ( $job['id'] ?? 0 ), $jobs_of_run() );

	$store    = ActionScheduler::store();
	$runner   = ActionScheduler::runner();
	$deadline = time() + 5 * MINUTE_IN_SECONDS;
	$orphaned = 0;
	$stopped  = '';

	while ( true ) {
		$current = $jobs_of_run();
		$open    = array_filter( $current, static fn( array $job ): bool => in_array( $job['status'] ?? null, [ 'pending', 'running', 'paused' ], true ) );

		if ( [] === $open ) {
			break;
		}
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
				$other = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cbjp_jobs WHERE id = %d", $job_id ), ARRAY_A );
				// 照会の失敗は「行が無い（古いアクション）」と区別できないので、他の run のアクションを処理しないよう止まる。
				if ( '' !== $wpdb->last_error ) {
					$store->release_claim( $claim );
					cbjp_pm_fail( "could not read the job {$job_id} of a claimed action: " . $wpdb->last_error );
				}
				if ( null === $other || in_array( $other['status'] ?? null, [ 'completed', 'failed', 'cancelled' ], true ) ) {
					$runner->process_action( (int) $action_id, 'CBJP manual screenshots (stale)' );
				} else {
					$store->unclaim_action( (string) $action_id );
				}
				continue;
			}

			$runner->process_action( (int) $action_id, 'CBJP manual screenshots' );
			++$processed;
		}

		$store->release_claim( $claim );

		if ( 0 === $processed ) {
			$live = 0;
			foreach ( $job_ids as $job_id ) {
				foreach ( [ ActionScheduler_Store::STATUS_PENDING, ActionScheduler_Store::STATUS_RUNNING ] as $status ) {
					$live += count(
						as_get_scheduled_actions(
							[
								'hook'     => JobManager::ACTION_HOOK,
								'args'     => [ 'job_id' => $job_id ],
								'status'   => $status,
								'per_page' => 1,
							],
							'ids'
						)
					);
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
		echo "{$type} " . ( $job['entity'] ?? '?' ) . ' ' . ( $job['status'] ?? '?' ) . ' ' . wp_json_encode( $job['totals'] ?? null ) . "\n";
		if ( 'completed' !== ( $job['status'] ?? null ) ) {
			$not_done[] = ( $job['entity'] ?? '?' ) . '=' . ( $job['status'] ?? '?' );
		}
	}

	$missing = array_diff( $entities, array_map( static fn( array $job ): string => (string) ( $job['entity'] ?? '' ), $jobs ) );
	if ( [] !== $missing ) {
		cbjp_pm_fail( 'no job was created for: ' . implode( ', ', $missing ) . '.' );
	}
	if ( [] !== $not_done || '' !== $stopped ) {
		cbjp_pm_fail( "the {$type} did not complete for every entity: " . implode( ', ', $not_done ) . ( '' !== $stopped ? " ({$stopped})" : '' ) . '.' );
	}

	$GLOBALS['cbjp_pm_run_id'] = null;

	return $run_id;
}

/**
 * ストアの件数（取込みの前提と、取り込んだものを消した後の確かめに使う）。`get_posts()`・`get_users()`・`wc_get_orders()` は照会の失敗を
 * 空の結果で返すので使わず、件数を SQL で数える。照会が失敗したら止まる（空と読まない）。状態（ゴミ箱・下書き）を問わずに数える。
 *
 * @return array<string,int>
 */
function cbjp_pm_store_counts(): array {
	global $wpdb;

	$count = static function ( string $what, string $sql ) use ( $wpdb ): int {
		$value = $wpdb->get_var( $sql );

		if ( '' !== $wpdb->last_error || null === $value ) {
			cbjp_pm_fail( "could not count the {$what}: " . $wpdb->last_error );
		}

		return (int) $value;
	};

	// 受注は HPOS の表と投稿の表（同期・旧来の保存先）の両方。HPOS の表が無いサイトでは投稿の表だけ。
	$orders_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name();
	$has_hpos     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $orders_table ) ) );
	if ( '' !== $wpdb->last_error ) {
		cbjp_pm_fail( 'could not look for the HPOS orders table: ' . $wpdb->last_error );
	}

	return [
		'colorme links'      => $count( 'colorme links', $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_mappings WHERE platform = %s", 'colorme' ) ),
		'products'           => $count( 'products', "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product', 'product_variation')" ),
		'coupons'            => $count( 'coupons', "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon'" ),
		'customers'          => $count( 'customers', $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $wpdb->get_blog_prefix() . 'capabilities', '%' . $wpdb->esc_like( '"customer"' ) . '%' ) ),
		'orders'             => $count( 'orders', "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" )
			+ ( null === $has_hpos ? 0 : $count( 'HPOS orders', "SELECT COUNT(*) FROM {$orders_table} WHERE type = 'shop_order'" ) ),
		// 既定のカテゴリ（未分類）は WooCommerce が持つものなので数えない。
		'product categories' => $count( 'product categories', $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_cat' AND term_id <> %d", (int) get_option( 'default_product_cat', 0 ) ) ),
		'product tags'       => $count( 'product tags', "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'product_tag'" ),
	];
}

/**
 * licensed の取込みの前提: 取り込んだものを片付けで「`colorme` の紐づけが指す実体」として消すので、紐づけ・商品・クーポン・顧客・受注・
 * 商品のカテゴリーとタグが 1 件も無い（＝消すのは撮影で作ったものだけ）ことを確かめる。PHPUnit の後の tests サイトはこの状態。
 */
function cbjp_pm_guard_import(): void {
	$found = array_filter( cbjp_pm_store_counts() );

	if ( [] !== $found ) {
		cbjp_pm_fail( 'the tests site already has ' . implode( ', ', array_keys( $found ) ) . '. The licensed screenshots import into an empty store and delete what they import afterwards, so they do not start here (run PHPUnit or clean the tests site first).' );
	}
}

/**
 * 取り込んだ実体（`colorme` の紐づけが指すもの）と紐づけを消す。取込みの前に「何も無い」ことを確かめているので、紐づけが指すのは撮影で作ったもの。
 * 知らない種類の紐づけがあれば何も消さずに止まる。
 */
function cbjp_pm_remove_imported(): void {
	global $wpdb;

	$state = cbjp_pm_state();

	if ( true !== ( $state['imported'] ?? null ) ) {
		return;
	}

	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT entity_type, local_id FROM {$wpdb->prefix}cbjp_mappings WHERE platform = %s", 'colorme' ), ARRAY_A );
	if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
		cbjp_pm_fail( 'could not read the colorme links: ' . $wpdb->last_error );
	}

	$by_type = [];
	foreach ( $rows as $row ) {
		$by_type[ (string) $row['entity_type'] ][] = (int) $row['local_id'];
	}

	// `variant` はバリエーション、`stock` は在庫の紐づけ（指すのは商品かバリエーション）。親の商品より先に消す。
	$order   = [ 'order', 'coupon', 'customer', 'stock', 'variant', 'product', 'tag', 'category' ];
	$unknown = array_diff( array_keys( $by_type ), $order );
	if ( [] !== $unknown ) {
		cbjp_pm_fail( 'unknown kinds of colorme links: ' . implode( ', ', $unknown ) . '. Not deleting anything; add them to cbjp_pm_remove_imported().' );
	}

	require_once ABSPATH . 'wp-admin/includes/user.php';

	$deleted = [];
	foreach ( $order as $type ) {
		foreach ( array_unique( $by_type[ $type ] ?? [] ) as $id ) {
			$ok = true;
			switch ( $type ) {
				case 'order':
					$object = wc_get_order( $id );
					if ( $object ) {
						$ok = $object->delete( true );
					}
					break;
				case 'coupon':
					if ( 'shop_coupon' === get_post_type( $id ) ) {
						$ok = (bool) wp_delete_post( $id, true );
					}
					break;
				case 'customer':
					$user = get_userdata( $id );
					if ( $user instanceof WP_User ) {
						if ( [ 'customer' ] !== array_values( $user->roles ) ) {
							cbjp_pm_fail( "the linked user {$id} is not only a customer. Not deleting it." );
						}
						$ok = wp_delete_user( $id );
					}
					break;
				case 'stock':
				case 'variant':
				case 'product':
					$object = wc_get_product( $id );
					if ( $object && get_post( $id ) instanceof WP_Post ) {
						$ok = $object->delete( true );
					}
					break;
				case 'tag':
				case 'category':
					$taxonomy = 'tag' === $type ? 'product_tag' : 'product_cat';
					if ( get_term( $id, $taxonomy ) instanceof WP_Term ) {
						$ok = true === wp_delete_term( $id, $taxonomy );
					}
					break;
			}
			if ( true !== $ok ) {
				cbjp_pm_fail( "could not delete the imported {$type} {$id}. The colorme links are kept so that cleanup can run again." );
			}
			$deleted[ $type ] = ( $deleted[ $type ] ?? 0 ) + 1;
		}
	}

	if ( class_exists( \Automattic\WooCommerce\Caches\OrderCountCache::class ) ) {
		( new \Automattic\WooCommerce\Caches\OrderCountCache() )->flush( 'shop_order' );
	}

	$wpdb->delete( "{$wpdb->prefix}cbjp_mappings", [ 'platform' => 'colorme' ], [ '%s' ] );
	if ( '' !== $wpdb->last_error ) {
		cbjp_pm_fail( 'could not delete the colorme links: ' . $wpdb->last_error );
	}

	// 消し残しを数え直す（取込みの前提と同じ範囲。対応付けのために作ったカテゴリは、片付けがこれより前に消す）。
	$left = array_filter( cbjp_pm_store_counts() );
	if ( [] !== $left ) {
		cbjp_pm_fail( 'after deleting the imported data, these remain: ' . wp_json_encode( $left ) . '.' );
	}

	unset( $state['imported'] );
	cbjp_pm_save_state( $state );
	echo 'imported: deleted ' . wp_json_encode( $deleted ) . "\n";
}

/**
 * プラグインが無効なときに、撮影の記録・トークン・ライセンスのキーか有効化の記録・開いた `colorme` の job が DB に残っているか
 * （クラスが読み込まれていないので、行を直接読む）。残っていれば、モックを外した後にプラグインを有効化すると、
 * 予定済みのアクションや更新の確認が本物の Color Me Shop API・販売サイトに届きうる。
 *
 * @return array<int,string> 残っているもの。
 */
function cbjp_pm_inactive_leftovers(): array {
	global $wpdb;

	$left = [];

	if ( null !== cbjp_pm_option_raw( CBJP_PM_STATE ) ) {
		$left[] = 'the record of the manual screenshots';
	}
	if ( null !== cbjp_pm_option_raw( 'cbjp_token_colorme' ) ) {
		$left[] = 'a colorme token';
	}

	// `LicenseStore::KEY_OPTION`・`STATE_OPTION`（Pro が無効だとクラスが無い）。撮影の前は無いか未有効化だった（setup の前提）。
	$key   = cbjp_pm_option_raw( 'cbjp_pro_license_key' );
	$state = cbjp_pm_option_raw( 'cbjp_pro_license_state' );
	$state = null === $state ? null : maybe_unserialize( $state );
	if ( ( null !== $key && '' !== $key ) || ( is_array( $state ) && 'yes' === ( $state['activated'] ?? null ) ) ) {
		$left[] = 'a Pro license key or activation';
	}

	$table  = $wpdb->prefix . 'cbjp_jobs';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	if ( '' !== $wpdb->last_error ) {
		cbjp_pm_fail( "could not look for the table {$table}: " . $wpdb->last_error );
	}
	if ( $table === $exists ) {
		$open = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE platform = %s AND status IN ('pending', 'running', 'paused')", 'colorme' ) );
		if ( '' !== $wpdb->last_error || null === $open ) {
			cbjp_pm_fail( 'could not count the open colorme jobs: ' . $wpdb->last_error );
		}
		if ( (int) $open > 0 ) {
			$left[] = "{$open} open colorme job(s)";
		}
	}

	return $left;
}

/**
 * 片付けの前提。tests サイトで、トークンとライセンスが撮影のものか無いことを確かめる。プラグインか WooCommerce が無効なら、
 * 片付けられないので、撮影の記録・トークン・ライセンス・開いた job が残っていれば止まる（無効化はデータを消さない）。
 *
 * @param array<int,mixed> $args
 * @return bool 片付けを続けるか（false なら何も残っていない）。
 */
function cbjp_pm_guard_teardown( array $args ): bool {
	$GLOBALS['cbjp_pm_who'] = 'teardown';
	$expected               = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';

	if ( '' === $expected || home_url() !== $expected ) {
		cbjp_pm_fail( 'home_url ' . home_url() . " is not the tests site '{$expected}'. Refusing to touch this site." );
	}

	$active = function_exists( 'WC' ) && class_exists( TokenStore::class ) && class_exists( LicenseStore::class );

	if ( ! $active ) {
		// 壊れた記録も「残っている」と読む（DB の行の有無で見る）。
		$left = cbjp_pm_inactive_leftovers();
		if ( [] !== $left ) {
			cbjp_pm_fail( 'WooCommerce, the free plugin or the Pro add-on is inactive on the tests site, but ' . implode( ', ', $left ) . ' remain(s). Activate them (npx wp-env run tests-cli wp plugin activate woocommerce cart-bridge-jp cart-bridge-jp-pro) while the mock is still installed, then run capture.sh cleanup again.' );
		}
		echo "teardown: the plugins are not active and nothing of the manual screenshots remains\n";
		return false;
	}

	// 何かを消す・キャンセルする前に、トークンとライセンスの両方が撮影のものか無いことを確かめる。
	if ( ! cbjp_pm_token_is_ours_or_absent() ) {
		cbjp_pm_fail( 'a colorme token other than the manual screenshot dummy is saved on the tests site. Not cancelling runs or deleting it.' );
	}
	if ( ! cbjp_pm_license_is_ours_or_absent() ) {
		cbjp_pm_fail( 'a Pro license other than the manual screenshot dummy is saved on the tests site. Not cancelling runs or deleting anything.' );
	}

	wp_set_current_user( 1 );

	return true;
}

/**
 * 撮影する種類（在庫は dry-run に含めない。dry-run は商品を書かないので、在庫は全件が「商品がまだ無い」で見送られて写る。無料版の撮影と同じ）。
 */
const CBJP_PM_DRY_RUN_ENTITIES = [ 'category', 'tag', 'product', 'customer', 'order', 'coupon' ];
const CBJP_PM_IMPORT_ENTITIES  = [ 'category', 'tag', 'product', 'stock', 'customer', 'order', 'coupon' ];

/**
 * trial（ライセンスキーを有効化していない Pro）の準備: 接続・対応付け・全種類の dry-run。`RUN_ID=` を出す。
 *
 * @param array<int,mixed> $args
 */
function cbjp_pm_setup_trial( array $args ): void {
	cbjp_pm_guard_setup( $args );

	if ( ( new LicenseStore() )->is_activated() ) {
		cbjp_pm_fail( 'the Pro license is activated (a licensed run was not cleaned up?). Run capture.sh cleanup with the licensed config first.' );
	}

	cbjp_pm_cancel_open_runs();
	cbjp_pm_prepare_site();
	cbjp_pm_install_translations();
	cbjp_pm_save_token();
	cbjp_pm_ensure_shipping_zone();
	cbjp_pm_save_mappings();

	$run_id = cbjp_pm_run( 'dry_run', CBJP_PM_DRY_RUN_ENTITIES );

	echo "RUN_ID={$run_id}\n";
}

/**
 * licensed（偽のキーで有効化した Pro）の準備: 空のストアに、接続・対応付け・dry-run・取込みまで行う。
 * `DRY_RUN_ID=`・`IMPORT_RUN_ID=` を出す。取り込んだものは片付けが消す。
 *
 * @param array<int,mixed> $args
 */
function cbjp_pm_setup_licensed( array $args ): void {
	cbjp_pm_guard_setup( $args );
	cbjp_pm_cancel_open_runs();
	cbjp_pm_guard_import();
	cbjp_pm_prepare_site();
	cbjp_pm_install_translations();
	cbjp_pm_save_token();
	cbjp_pm_ensure_shipping_zone();
	cbjp_pm_save_mappings();
	cbjp_pm_activate_license();
	cbjp_pm_ensure_account_page();

	$dry_run_id = cbjp_pm_run( 'dry_run', CBJP_PM_DRY_RUN_ENTITIES );

	// 取込みを始める前に記録する（途中で止まっても、片付けが紐づけの指す実体を消す）。
	$state             = cbjp_pm_state();
	$state['imported'] = true;
	cbjp_pm_save_state( $state );

	$import_run_id = cbjp_pm_run( 'import', CBJP_PM_IMPORT_ENTITIES );

	echo "DRY_RUN_ID={$dry_run_id}\n";
	echo "IMPORT_RUN_ID={$import_run_id}\n";
}

/**
 * 片付け（trial・licensed 共通）。撮影の記録にあるものだけを消し、最後に記録が空になったことを確かめる。
 *
 * @param array<int,mixed> $args
 */
function cbjp_pm_teardown( array $args ): void {
	if ( ! cbjp_pm_guard_teardown( $args ) ) {
		return;
	}

	cbjp_pm_cancel_open_runs();
	// 対応付けのために作ったカテゴリを先に消す（取り込んだものを消した後の確かめが、商品のカテゴリーを 0 件と数えるように）。
	cbjp_pm_remove_mappings();
	cbjp_pm_remove_imported();
	cbjp_pm_remove_account_page();
	cbjp_pm_remove_shipping_zone();
	cbjp_pm_clear_license();
	cbjp_pm_delete_token();
	cbjp_pm_remove_translations();
	cbjp_pm_restore_options( 'site' );

	$left = cbjp_pm_state();
	if ( [] !== $left ) {
		cbjp_pm_fail( 'the record of the manual screenshots still lists: ' . implode( ', ', array_keys( $left ) ) . '.' );
	}

	echo "teardown: done\n";
}
