<?php
// 例（issue #55 / R3-0h）: verify-rest.php が作ったものだけを撤去する。
// 実行: mock-adapter.sh run .claude/skills/verify-with-mock-adapter/examples/upsell-notice/cleanup.php
// 続けて: mock-adapter.sh uninstall → mock-adapter.sh inspect（検証前と同じか確認）。`inspect` は logs・jobs・intents・商品を数えないので、
//         この出力の「left」の行（すべて 0 であること）も見る。ブラウザの localStorage に入れた run_id は手で消す（verify-rest.php の出力 4.）。
//
// **mock アダプタが登録されているときだけ実行する**（許可する側で判定する。`.claude/rules/skill-scripts.md`）: 下の $platform に mock 以外の
// アダプタが登録されている・アダプタが未登録（mock を uninstall した後など）なら拒否する。トークンは「無い」か「この example が保存した値」
// だけを許し、それ以外（実 OAuth のトークンなど）が残っていれば拒否する。mapping・job を消すので、実 platform に向けると実データを壊す。
use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;

global $wpdb;

$platform   = 'mockv';
$token      = 'verify-upsell'; // verify-rest.php と同じ値。
$state_name = 'cbjp_verify_upsell';
$sku_prefix = 'ZZV-UPSELL-';

// オプションの有無で分ける: 保存済みでも復号できないトークン（要再接続の実 OAuth など）は `get()` が null を返すため、
// null を「トークン無し」と読むと他人のトークンを消してしまう。復号できて値がこの example のものと一致するときだけ許す。
$has_token     = false !== get_option( 'cbjp_token_' . $platform, false );
$stored_token  = $has_token ? ( new TokenStore( $platform ) )->get() : null;
$token_is_ours = ! $has_token || ( is_array( $stored_token ) && $token === ( $stored_token['access_token'] ?? null ) );

if ( ! AdapterRegistry::get( $platform ) instanceof MockPlatformAdapter || ! $token_is_ours ) {
	echo "REFUSED: '{$platform}' is not a registered mock adapter, or it holds a token this example did not store. Nothing was deleted. If the mock is uninstalled, run: mock-adapter.sh install {$platform} — then cleanup.php, then uninstall.\n";
	exit( 2 );
}

$deleted = [];

// 偽トークン（あればこの example の値と確認済み）。
$deleted['token'] = delete_option( 'cbjp_token_' . $platform ) ? 1 : 0;

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

// 商品: 状態オプションに記録した ID と、SKU の接頭辞で見つかるもの（保存の後・記録の前に止まった場合の取り残し。PR #88 G1-3）の両方。
// どちらも SKU がこの example の接頭辞で始まるものだけを消す。
$state       = get_option( $state_name, [] );
$product_ids = is_array( $state ) && is_array( $state['product_ids'] ?? null ) ? array_filter( $state['product_ids'], 'is_int' ) : [];
$targets     = [];

foreach ( $product_ids as $id ) {
	$targets[ $id ] = wc_get_product( $id );
}

foreach ( $own_products() as $product ) {
	$targets[ $product->get_id() ] = $product;
}

$deleted['products'] = 0;

foreach ( $targets as $product ) {
	if ( $product instanceof WC_Product && str_starts_with( (string) $product->get_sku(), $sku_prefix ) ) {
		$product->delete( true );
		++$deleted['products'];
	}
}

// mockv の行（push-intent-resolution/cleanup.php と同じ範囲。あちらはトークンが残っていると拒否するため、ここで消す）。
$prefix = $wpdb->prefix;
$like   = '%' . $wpdb->esc_like( '"platform":"' . $platform . '"' ) . '%';
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$job_ids             = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) ) );
$deleted['intents']  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_push_intents WHERE platform = %s", $platform ) );
$deleted['mappings'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_mappings WHERE platform = %s", $platform ) );
$deleted['jobs']     = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) );
$deleted['logs']     = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$prefix}cbjp_logs WHERE job_id IS NULL AND context_json LIKE %s", $like ) );

if ( [] !== $job_ids ) {
	// ジョブに紐づくログ・dry-run 明細（IN の中身は上で int 化した ID だけ）。
	$in                       = implode( ',', $job_ids );
	$deleted['job_logs']      = (int) $wpdb->query( "DELETE FROM {$prefix}cbjp_logs WHERE job_id IN ({$in})" );
	$deleted['dry_run_items'] = (int) $wpdb->query( "DELETE FROM {$prefix}cbjp_dry_run_items WHERE job_id IN ({$in})" );
}
// phpcs:enable

// `cbjp_verify_seed` は他の example と共有するので、この example が書くキーだけを外す。
$seed = get_option( 'cbjp_verify_seed', null );

if ( is_array( $seed ) ) {
	unset( $seed['push'], $seed['limits'], $seed['pro_url'] );

	if ( [] === $seed ) {
		delete_option( 'cbjp_verify_seed' );
	} else {
		update_option( 'cbjp_verify_seed', $seed, false );
	}
} elseif ( null !== $seed ) {
	delete_option( 'cbjp_verify_seed' ); // 配列でない壊れた値（mu-plugin は読み飛ばすが、残す理由も無い）。
}

foreach ( [ 'cbjp_export_sample_' . $platform, 'cbjp_sample_' . $platform, 'cbjp_rate_limit_' . $platform, $state_name ] as $option ) {
	delete_option( $option );
}

echo 'deleted: ' . wp_json_encode( $deleted ) . "\n";

$seed_after = get_option( 'cbjp_verify_seed', [] );
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
$left = [
	'token'     => false !== get_option( 'cbjp_token_' . $platform, false ) ? 1 : 0,
	'products'  => count( $own_products() ),
	'intents'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_push_intents WHERE platform = %s", $platform ) ),
	'mappings'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_mappings WHERE platform = %s", $platform ) ),
	'jobs'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_jobs WHERE platform = %s", $platform ) ),
	'logs'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$prefix}cbjp_logs WHERE context_json LIKE %s", $like ) ),
	'seed_keys' => is_array( $seed_after ) ? count( array_intersect( [ 'push', 'limits', 'pro_url' ], array_keys( $seed_after ) ) ) : 0,
	'state'     => false !== get_option( $state_name, false ) ? 1 : 0,
];
// phpcs:enable
echo 'left: ' . wp_json_encode( $left ) . "\n";
exit( 0 === array_sum( $left ) ? 0 : 1 );
