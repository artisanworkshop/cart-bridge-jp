<?php
/**
 * measure-api-manager の共通処理。php/*.php から require_once する（`am.sh` が wp eval-file で実行する。WP の中で動く）。
 *
 * 安全規則（SKILL.md「安全規則」）:
 * - dev サイト（`site=<URL>` と home_url() が一致し、環境が local）でだけ動く（`cbjp_am_require_site()`）。
 * - 書き換える前に、消し残し（`cbjp_am_leftovers()`）が 0 であることを確かめる。setup と teardown は同じ範囲を数える。
 * - 作ったものは状態ファイル（`dist/api-manager/state.json`）に作るたびに書く。teardown は、投稿・注文・顧客を状態ファイルの ID と目印
 *   （投稿・注文の meta、顧客のログイン名）が一致するときだけ消す。API Manager のテーブル・option など（`cbjp_am_am_option_where()` ほか）と
 *   Pro のキーの記録は名前で消す（setup の前に 0 だったことを preflight で確かめているので、setup の後にできたもの）。
 * - 状態ファイルと dev サイトの DB をトークン（option `cbjp_am_probe_token`）で結び付ける。DB を作り直した後に古い状態ファイルで消さない
 *   （`cbjp_am_state_belongs_here()`）。状態ファイルが無い・DB と結び付かないのに残りがあれば、推測で消さない。
 * - 状態と出力はリポジトリのルートの `dist/api-manager/`（gitignore 済み。HTTP では読めない）にだけ書く。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/anon.php';

/** setup が作る投稿（製品・ページ）と注文に付ける目印の meta。 */
const CBJP_AM_MARKER_META = '_cbjp_am_probe';
/** setup が作る顧客のログイン名。 */
const CBJP_AM_BUYER_LOGIN = 'cbjp-am-buyer';
/** mu-plugin が販売サイトへの要求を記録する option。 */
const CBJP_AM_REQUEST_LOG = 'cbjp_am_probe_requests';
/** API Manager のプラグインのディレクトリ名。 */
const CBJP_AM_PLUGIN_SLUG = 'woocommerce-api-manager';
/** Pro の zip を置くアップロードのディレクトリ（ダウンロードの承認済みディレクトリの対象）。 */
const CBJP_AM_UPLOAD_SUBDIR = 'woocommerce_uploads/cbjp-am';
/** mu-plugin のファイル名（am.sh と同じ）。 */
const CBJP_AM_MU_FILE = 'cbjp-measure-api-manager.php';
/** 製品の名前（Pro のフィクスチャと同じ）。 */
const CBJP_AM_PRODUCT_NAME = 'Cart Bridge JP Pro';
/** 状態ファイルと dev サイトの DB を結び付けるトークンの option。 */
const CBJP_AM_TOKEN_OPTION = 'cbjp_am_probe_token';
/** API Manager の WP-Cron のフック・Action Scheduler の予定の名前（`kestrel_` は 3.7 から）。 */
const CBJP_AM_HOOK_PATTERN = '/wc_am|wcam|kestrel_api_manager/i';

/**
 * `key=value` の引数を読む。未知・重複のキー、`=` の無い引数は止める（rehearse-colorme の `cbjp_rh_args()` と同じ）。
 *
 * @param list<string> $args
 * @param list<string> $allowed
 * @return array<string,string>
 */
function cbjp_am_args( array $args, array $allowed ): array {
	$out = [];

	foreach ( $args as $arg ) {
		$pos = strpos( (string) $arg, '=' );

		if ( false === $pos || 0 === $pos ) {
			cbjp_am_abort( "argument must be key=value: {$arg}" );
		}

		$key = substr( $arg, 0, $pos );

		if ( ! in_array( $key, $allowed, true ) ) {
			cbjp_am_abort( "unknown argument '{$key}' (allowed: " . implode( ', ', $allowed ) . ')' );
		}

		if ( array_key_exists( $key, $out ) ) {
			cbjp_am_abort( "duplicate argument '{$key}'" );
		}

		$out[ $key ] = substr( $arg, $pos + 1 );
	}

	return $out;
}

function cbjp_am_abort( string $message, int $code = 2 ): never {
	fwrite( STDERR, "ABORT: {$message}\n" );
	exit( $code );
}

/**
 * dev サイトでだけ動く: 引数 `site` と home_url() が一致し、環境が local であること（肯定形で確かめる）。
 *
 * @param array<string,string> $opts
 */
function cbjp_am_require_site( array $opts ): void {
	$site = $opts['site'] ?? '';

	if ( 1 !== preg_match( '#\Ahttp://localhost:[0-9]+\z#', $site ) ) {
		cbjp_am_abort( "site=<http://localhost:PORT> is required (got '{$site}')" );
	}

	if ( 'local' !== wp_get_environment_type() ) {
		cbjp_am_abort( 'refusing to run: the environment type is ' . wp_get_environment_type() . ', not local' );
	}

	if ( untrailingslashit( home_url() ) !== $site ) {
		cbjp_am_abort( 'refusing to run: home_url() is ' . home_url() . ", not {$site} (the dev site)" );
	}
}

/**
 * 状態と出力の置き場所（リポジトリのルートの dist/api-manager/。この PHP は <root>/.claude/skills/measure-api-manager/php/）。
 * ルートの .htaccess が HTTP を拒否する（bin/check-dev-mount.sh）。ここにも置いておく（am.sh は HTTP で読めないことを確かめてから動く）。
 */
function cbjp_am_out_dir(): string {
	$dir = dirname( __DIR__, 4 ) . '/dist/api-manager';

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		cbjp_am_abort( "could not create {$dir}" );
	}

	$files = [
		'.htaccess' => "# measure-api-manager: holds license keys of the local API Manager. Never serve this directory.\nRequire all denied\n",
		'index.php' => "<?php\n// Silence is golden.\n",
	];

	foreach ( $files as $name => $content ) {
		if ( ! is_file( "{$dir}/{$name}" ) && false === file_put_contents( "{$dir}/{$name}", $content ) ) {
			cbjp_am_abort( "could not write {$dir}/{$name}" );
		}
	}

	return $dir;
}

function cbjp_am_state_path(): string {
	return cbjp_am_out_dir() . '/state.json';
}

/**
 * 状態ファイルを読む。無ければ null。読めない・壊れているときは止める（無いと読まない）。
 *
 * @return array<string,mixed>|null
 */
function cbjp_am_state(): ?array {
	$path = cbjp_am_state_path();

	if ( ! file_exists( $path ) ) {
		return null;
	}

	$raw   = file_get_contents( $path );
	$state = false === $raw ? null : json_decode( $raw, true );

	if ( ! is_array( $state ) ) {
		cbjp_am_abort( "the state file is unreadable or broken: {$path} (inspect it; do not delete it while the dev site still holds what setup created)", 1 );
	}

	return $state;
}

/**
 * 状態ファイルを書く（エンコード・書き込みの失敗で止める）。
 *
 * @param array<string,mixed> $state
 */
function cbjp_am_state_write( array $state ): void {
	$path = cbjp_am_state_path();
	$json = wp_json_encode( $state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( ! is_string( $json ) || false === file_put_contents( $path, $json . "\n" ) ) {
		cbjp_am_abort( "could not write {$path}", 1 );
	}
}

/**
 * 状態ファイルの 1 つのキーを書き換える（作るたびに呼ぶ。途中で止まっても teardown が消せるように）。
 *
 * @param mixed $value
 */
function cbjp_am_state_set( string $key, $value ): void {
	$state = cbjp_am_state();

	if ( null === $state ) {
		cbjp_am_abort( 'the state file is missing (run setup first)', 1 );
	}

	$state[ $key ] = $value;
	cbjp_am_state_write( $state );
}

/**
 * setup が済んだ状態（製品・顧客・リソースが記録されている）を読む。足りなければ止める。
 *
 * @return array<string,mixed>
 */
function cbjp_am_ready_state(): array {
	$state = cbjp_am_state();

	if ( null === $state ) {
		cbjp_am_abort( 'the state file is missing (run: am.sh setup --zip <API Manager zip>)', 1 );
	}

	if ( ! is_int( $state['product_id'] ?? null ) || ! is_int( $state['user_id'] ?? null ) || ! is_array( $state['resources'] ?? null ) || 2 !== count( $state['resources'] ) ) {
		cbjp_am_abort( 'setup has not finished (the state file has no product, customer, or two API resources). Run: am.sh teardown', 1 );
	}

	if ( ! cbjp_am_state_belongs_here( $state ) ) {
		cbjp_am_abort( 'the state file does not belong to this dev-site database (its token is missing here or differs). Run: am.sh teardown', 1 );
	}

	if ( ! function_exists( 'WCAM' ) ) {
		cbjp_am_abort( 'API Manager is not active', 1 );
	}

	return $state;
}

/**
 * 状態ファイルが、この dev サイトの DB で setup したものか（setup の init が option と状態ファイルの両方に同じトークンを書く）。
 *
 * @param array<string,mixed> $state
 */
function cbjp_am_state_belongs_here( array $state ): bool {
	$token = $state['token'] ?? null;

	return is_string( $token ) && 1 === preg_match( '/\A[A-Za-z0-9]{32}\z/', $token ) && get_option( CBJP_AM_TOKEN_OPTION ) === $token;
}

/**
 * API Manager の option（transient を含む）を選ぶ WHERE 句と値。数える（`cbjp_am_leftovers()`）と消す（teardown）が同じ範囲を使う。
 * 前方一致: 3.6 までの `woocommerce_api_manager_`・`wc_am_`、3.7 からの `kestrel_api_manager_`、応答のキャッシュの transient。
 * 名前に含む: バックグラウンド処理（`wp_<blog>_wcam_events_background_process`・`wp_wc_am_updater`）の `_batch_*` と、`_process_lock` の site transient。
 *
 * @return array{0:string,1:list<string>}
 */
function cbjp_am_am_option_where(): array {
	global $wpdb;

	$prefixes = [ 'woocommerce_api_manager', 'kestrel_api_manager', 'wc_am_', 'wcam_', '_transient_wc_am_', '_transient_timeout_wc_am_', '_site_transient_wc_am_', '_site_transient_timeout_wc_am_' ];
	$contains = [ 'wcam_events_background_process', 'wc_am_updater' ];
	$values   = array_merge(
		array_map( static fn( string $p ): string => $wpdb->esc_like( $p ) . '%', $prefixes ),
		array_map( static fn( string $c ): string => '%' . $wpdb->esc_like( $c ) . '%', $contains )
	);

	return [ implode( ' OR ', array_fill( 0, count( $values ), 'option_name LIKE %s' ) ), $values ];
}

/**
 * API Manager の usermeta（マスターキー `wc_am_master_api_key` など）を選ぶ WHERE 句と値。
 *
 * @return array{0:string,1:list<string>}
 */
function cbjp_am_am_usermeta_where(): array {
	global $wpdb;

	return [ 'meta_key LIKE %s OR meta_key LIKE %s', [ '%' . $wpdb->esc_like( 'wc_am_' ) . '%', '%' . $wpdb->esc_like( 'api_manager' ) . '%' ] ];
}

/**
 * API Manager の Action Scheduler の予定の ID。
 *
 * @return list<int>
 */
function cbjp_am_am_action_ids(): array {
	global $wpdb;

	$table = $wpdb->prefix . 'actionscheduler_actions';

	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		return [];
	}

	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT action_id FROM {$table} WHERE hook LIKE %s OR hook LIKE %s OR hook LIKE %s",
				'%' . $wpdb->esc_like( 'wc_am' ) . '%',
				'%' . $wpdb->esc_like( 'wcam' ) . '%',
				'%' . $wpdb->esc_like( 'kestrel_api_manager' ) . '%'
			)
		)
	);
}

/**
 * API Manager の WP-Cron のフック。
 *
 * @return list<string>
 */
function cbjp_am_am_cron_hooks(): array {
	$found = [];

	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( 1 === preg_match( CBJP_AM_HOOK_PATTERN, (string) $hook ) ) {
				$found[] = (string) $hook;
			}
		}
	}

	return array_values( array_unique( $found ) );
}

/**
 * 匿名化の文脈（anon.php）を状態ファイルから作る。
 *
 * @param array<string,mixed> $state
 * @return array<string,mixed>
 */
function cbjp_am_context( array $state ): array {
	$secrets = [];

	foreach ( $state['resources'] as $resource ) {
		$secrets[] = (string) $resource['key'];
	}

	$secrets[] = (string) ( $state['master_key'] ?? '' );
	$secrets   = array_merge( $secrets, array_values( cbjp_am_instances() ) );
	$ctx       = cbjp_am_anon_context(
		[
			'site_url'     => untrailingslashit( home_url() ),
			'product_id'   => $state['product_id'],
			'order_ids'    => array_map( 'intval', (array) ( $state['order_ids'] ?? [] ) ),
			'resource_ids' => array_map( static fn( array $r ): int => (int) $r['id'], $state['resources'] ),
			'user_id'      => $state['user_id'],
			'secrets'      => array_values( array_filter( $secrets, static fn( string $s ): bool => '' !== $s ) ),
		]
	);

	if ( null === $ctx ) {
		cbjp_am_abort( 'the state file does not hold what anonymizing needs (site, product, orders, resources, customer, keys)', 1 );
	}

	return $ctx;
}

/**
 * 要求に使う instance（Pro の instance と同じ 32 文字の 16 進）。
 *
 * @return array<string,string>
 */
function cbjp_am_instances(): array {
	return [
		'one'   => str_repeat( 'a1', 16 ),
		'two'   => str_repeat( 'b2', 16 ),
		'three' => str_repeat( 'c3', 16 ),
		'four'  => str_repeat( 'd4', 16 ),
	];
}

/**
 * 消し残しの数。setup の前（preflight）と teardown の後で同じ範囲を数える。0 でない項目があれば、dev サイトに何かが残っている。
 * API Manager の分は名前のパターンで数える（preflight で 0 なので、あればこのスキルが入れたもの）。
 *
 * @param array<string,mixed>|null $state
 * @return array<string,int>
 */
function cbjp_am_leftovers( ?array $state ): array {
	global $wpdb;

	$counts = [];

	// API Manager のテーブル・option（transient を含む）・usermeta・WP-Cron・Action Scheduler の予定（teardown が消す範囲と同じ関数）。
	[ $option_where, $option_values ] = cbjp_am_am_option_where();
	[ $meta_where, $meta_values ]     = cbjp_am_am_usermeta_where();

	$counts['am_tables']            = count( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_am_' ) . '%' ) ) );
	$counts['am_options']           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$option_where}", $option_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$counts['am_usermeta']          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE {$meta_where}", $meta_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$counts['am_cron']              = count( cbjp_am_am_cron_hooks() );
	$counts['am_scheduled_actions'] = count( cbjp_am_am_action_ids() );

	$counts['am_plugin_dir'] = is_dir( WP_PLUGIN_DIR . '/' . CBJP_AM_PLUGIN_SLUG ) ? 1 : 0;

	// このスキルが作るもの: Pro のキーの記録（E2E）・要求の記録・目印のある投稿と注文・顧客・承認済みディレクトリ・アップロード。
	$counts['probe_token']         = false === get_option( CBJP_AM_TOKEN_OPTION ) ? 0 : 1;
	$counts['pro_license_options'] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name = %s",
			$wpdb->esc_like( 'cbjp_pro_license_' ) . '%',
			CBJP_AM_REQUEST_LOG
		)
	);
	$counts['marked_posts']        = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", CBJP_AM_MARKER_META ) );
	$counts['marked_orders']       = count(
		wc_get_orders(
			[
				'limit'    => -1,
				'return'   => 'ids',
				'status'   => array_merge( array_keys( wc_get_order_statuses() ), [ 'trash' ] ),
				'meta_key' => CBJP_AM_MARKER_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			]
		)
	);
	$counts['buyer']               = false === get_user_by( 'login', CBJP_AM_BUYER_LOGIN ) ? 0 : 1;
	$dirs_table                    = $wpdb->prefix . 'wc_product_download_directories';
	$counts['approved_dirs']       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$dirs_table} WHERE url LIKE %s", '%' . $wpdb->esc_like( '/cbjp-am/' ) . '%' ) );
	$counts['upload_dir']          = is_dir( wp_upload_dir()['basedir'] . '/' . CBJP_AM_UPLOAD_SUBDIR ) ? 1 : 0;

	// 状態ファイルにある ID がまだあるか（目印の meta が消えた場合の備え）と、承認済みディレクトリのモードが setup の前の値に戻ったか。
	if ( null !== $state ) {
		$ids = array_filter( array_merge( [ $state['product_id'] ?? 0 ], (array) ( $state['page_ids'] ?? [] ) ), 'is_int' );

		$counts['state_posts']  = count( array_filter( $ids, static fn( int $id ): bool => null !== get_post( $id ) ) );
		$counts['state_orders'] = count( array_filter( array_filter( (array) ( $state['order_ids'] ?? [] ), 'is_int' ), static fn( int $id ): bool => false !== wc_get_order( $id ) ) );

		// 製品を作った・消したときに WooCommerce が積む、属性の索引の更新の予定（引数が製品 ID）。
		if ( is_int( $state['product_id'] ?? null ) ) {
			$counts['wc_lookup_actions'] = count( cbjp_am_lookup_action_ids( $state['product_id'] ) );
		}

		if ( is_string( $state['approved_mode'] ?? null ) ) {
			$counts['approved_mode_changed'] = cbjp_am_approved_register()->get_mode() === $state['approved_mode'] ? 0 : 1;
		}
	}

	$counts['mu_plugin'] = file_exists( WPMU_PLUGIN_DIR . '/' . CBJP_AM_MU_FILE ) ? 1 : 0;

	return $counts;
}

/**
 * 製品の属性の索引の更新の予定（WooCommerce が製品の保存・削除で Action Scheduler に積む。引数は [製品 ID, 操作]）の ID。
 *
 * @return list<int>
 */
function cbjp_am_lookup_action_ids( int $product_id ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'actionscheduler_actions';

	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
		return [];
	}

	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT action_id FROM {$table} WHERE hook = %s AND args LIKE %s",
				'woocommerce_run_product_attribute_lookup_update_callback',
				$wpdb->esc_like( '[' . $product_id . ',' ) . '%'
			)
		)
	);
}

/**
 * Action Scheduler の予定を、そのログと一緒に消す。消せなかった ID の一覧を返す。
 *
 * @param list<int> $action_ids
 * @return list<int>
 */
function cbjp_am_delete_actions( array $action_ids ): array {
	global $wpdb;

	$failed = [];

	foreach ( $action_ids as $action_id ) {
		try {
			\ActionScheduler::store()->delete_action( $action_id );
			$wpdb->delete( $wpdb->prefix . 'actionscheduler_logs', [ 'action_id' => $action_id ] );
		} catch ( \Exception $e ) {
			$failed[] = $action_id;
		}
	}

	return $failed;
}

/**
 * WooCommerce のダウンロードの承認済みディレクトリ。
 *
 * @return \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register
 */
function cbjp_am_approved_register() {
	return wc_get_container()->get( \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::class );
}

/**
 * 自分のリソースを setup の直後に戻す: 有効化を消し、期限と有効の印を戻し、API Manager の応答のキャッシュを捨てる。
 * 戻ったことを数え直して確かめる。
 *
 * @param array<string,mixed> $state
 */
function cbjp_am_reset_resources( array $state ): void {
	global $wpdb;

	$ids = array_map( static fn( array $r ): int => (int) $r['id'], $state['resources'] );
	$in  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_am_api_activation WHERE api_resource_id IN ({$in})", $ids ) );

	foreach ( $state['resources'] as $resource ) {
		$wpdb->update(
			"{$wpdb->prefix}wc_am_api_resource",
			[
				'access_expires'    => (int) $resource['access_expires'],
				'active'            => 1,
				'activations_total' => 0,
			],
			[ 'api_resource_id' => (int) $resource['id'] ]
		);
	}

	cbjp_am_clear_cache();

	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_am_api_activation WHERE api_resource_id IN ({$in})", $ids ) );
	// phpcs:enable

	if ( 0 !== $left ) {
		cbjp_am_abort( "could not reset the activations of the probe resources ({$left} left)", 1 );
	}
}

/**
 * リソース（0 = キー A、1 = キー B）の期限を、今から $seconds_ago 秒前にする（期限切れ・猶予期間の後を作る）。
 *
 * @param array<string,mixed> $state
 */
function cbjp_am_expire( array $state, int $index, int $seconds_ago ): void {
	global $wpdb;

	$id      = (int) $state['resources'][ $index ]['id'];
	$updated = $wpdb->update( "{$wpdb->prefix}wc_am_api_resource", [ 'access_expires' => time() - $seconds_ago ], [ 'api_resource_id' => $id ] );

	if ( 1 !== $updated ) {
		cbjp_am_abort( "could not expire the probe resource {$id}", 1 );
	}

	cbjp_am_clear_cache();
}

/**
 * API Manager の応答・DB のキャッシュ（`wc_am_` の transient）を捨てる。本番では最大 60 分残る（`docs/03` §10.7「R3-8c の実装」）。
 */
function cbjp_am_clear_cache(): void {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wc_am_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wc_am_' ) . '%'
		)
	);
	wp_cache_flush();
}

/**
 * 顧客のリソース（キー A・B）を API Manager のテーブルから読む。
 *
 * @return list<array{id:int,order_id:int,key:string,access_expires:int}>
 */
function cbjp_am_read_resources( int $user_id ): array {
	global $wpdb;

	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT api_resource_id, order_id, product_order_api_key, access_expires FROM {$wpdb->prefix}wc_am_api_resource WHERE user_id = %d ORDER BY order_id", $user_id ),
		ARRAY_A
	);

	return array_map(
		static fn( array $row ): array => [
			'id'             => (int) $row['api_resource_id'],
			'order_id'       => (int) $row['order_id'],
			'key'            => (string) $row['product_order_api_key'],
			'access_expires' => (int) $row['access_expires'],
		],
		is_array( $rows ) ? $rows : []
	);
}

/**
 * 応答の置き場所に、どの setup で測ったかを書く（`run.json`。状態ファイルの `created_at`）。
 *
 * @param array<string,mixed> $state
 */
function cbjp_am_write_run_marker( string $out_dir, array $state ): void {
	$json = wp_json_encode( [ 'setup_created_at' => (string) ( $state['created_at'] ?? '' ) ] );

	if ( ! is_string( $json ) || false === file_put_contents( $out_dir . '/run.json', $json . "\n" ) ) {
		cbjp_am_abort( "could not write {$out_dir}/run.json", 1 );
	}
}

/**
 * 応答の置き場所が、今の状態ファイルの setup で測ったものか。
 *
 * @param array<string,mixed> $state
 */
function cbjp_am_measured_in_this_setup( string $out_dir, array $state ): bool {
	$raw    = is_file( $out_dir . '/run.json' ) ? file_get_contents( $out_dir . '/run.json' ) : false;
	$marker = false === $raw ? null : json_decode( $raw, true );

	return is_array( $marker ) && is_string( $state['created_at'] ?? null ) && '' !== $state['created_at'] && ( $marker['setup_created_at'] ?? null ) === $state['created_at'];
}

/**
 * キーを表示用に縮める（先頭 4 文字）。
 */
function cbjp_am_mask( string $secret ): string {
	return substr( $secret, 0, 4 ) . '…';
}
