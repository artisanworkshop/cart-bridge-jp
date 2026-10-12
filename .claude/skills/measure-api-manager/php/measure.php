<?php
/**
 * 手元の API Manager へ生の要求を送り、応答を `dist/api-manager/out/<API Manager の版>/<名前>.json` に保存する（`am.sh measure` が呼ぶ）。
 * 引数: site=<dev サイトの URL> group=<all|グループ名[,グループ名…]>
 *
 * 要求は Pro と同じく本文で送り、転送しない。各グループの初めに自分のリソースを setup の直後に戻す（有効化を消し、期限を戻し、
 * API Manager の応答のキャッシュを捨てる）。期限切れ・猶予期間の後は `access_expires` の書き換えで作る。
 * 猶予期間の後にリソースが消える処理（API Manager の背景処理）は再現できない（SKILL.md「測る場面」）。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$opts = cbjp_am_args( $args, [ 'site', 'group' ] );
cbjp_am_require_site( $opts );

$state    = cbjp_am_ready_state();
$i        = cbjp_am_instances();
$key_a    = (string) $state['resources'][0]['key'];
$key_b    = (string) $state['resources'][1]['key'];
$product  = (string) $state['product_id'];
$unknown  = str_repeat( 'f', 40 );
$version  = (string) $state['pro_version'];
$activate = static fn( string $key, string $instance, string $product_id = '' ): array => [
	'wc_am_action'     => 'activate',
	'product_id'       => '' === $product_id ? $product : $product_id,
	'api_key'          => $key,
	'instance'         => $instance,
	'software_version' => $version,
];
$simple   = static fn( string $action, string $key, string $instance ): array => [
	'wc_am_action' => $action,
	'product_id'   => $product,
	'api_key'      => $key,
	'instance'     => $instance,
];
// update・information は Pro と同じ欄を送る（LicenseClient::plugin_params()）。
$plugin = static fn( string $action, string $key, string $instance, string $product_id = '', string $plugin_version = '' ): array => array_filter(
	[
		'wc_am_action' => $action,
		'product_id'   => '' === $product_id ? $product : $product_id,
		'api_key'      => $key,
		'instance'     => $instance,
		'plugin_name'  => 'cart-bridge-jp-pro/cart-bridge-jp-pro.php',
		'slug'         => 'cart-bridge-jp-pro',
		'version'      => '' === $plugin_version ? $version : $plugin_version,
	],
	static fn( string $v ): bool => '' !== $v
);
$hour  = HOUR_IN_SECONDS;
$month = 30 * DAY_IN_SECONDS;

// グループ: [ 名前 => 手順の list ]。手順は [ 'call', 出力名, 本文 ] か [ 'expire', リソースの添字, 何秒前 ]。
$groups = [
	// 有効化の前: 未有効化の instance・キー無し・未知のキー・製品 ID 違い。
	'before'         => [
		[ 'call', 'status_not_activated', $simple( 'status', $key_a, $i['one'] ) ],
		[ 'call', 'update_not_activated', $plugin( 'update', $key_a, $i['one'] ) ],
		[ 'call', 'information_not_activated', $plugin( 'information', $key_a, $i['one'] ) ],
		[ 'call', 'update_no_key', $plugin( 'update', '', '' ) ],
		[ 'call', 'update_unknown_product', $plugin( 'update', $key_a, $i['one'], (string) CBJP_AM_UNKNOWN_PRODUCT_ID ) ],
		[ 'call', 'update_unknown_key', $plugin( 'update', $unknown, $i['three'] ) ],
		[ 'call', 'activate_unknown_key', $activate( $unknown, $i['three'] ) ],
		[ 'call', 'activate_unknown_product', $activate( $key_a, $i['one'], (string) CBJP_AM_UNKNOWN_PRODUCT_ID ) ],
	],
	// 有効化・同じ instance での再有効化・状態確認・更新の確認（古い版と最新の版）・詳細。
	'activate'       => [
		[ 'call', 'activate_success', $activate( $key_a, $i['one'] ) ],
		[ 'call', 'activate_again_same_key', $activate( $key_a, $i['one'] ) ],
		[ 'call', 'status_active', $simple( 'status', $key_a, $i['one'] ) ],
		[ 'call', 'update_active', $plugin( 'update', $key_a, $i['one'] ) ],
		[ 'call', 'information_active', $plugin( 'information', $key_a, $i['one'] ) ],
		[ 'call', 'update_active_newest', $plugin( 'update', $key_a, $i['one'], '', (string) $state['new_version'] ) ],
	],
	// 期限内のキーの交換（同じ instance で別のキー。3.8.x は通る、3.6.0 は断った）。
	'exchange'       => [
		[ 'call', 'exchange_activate_a', $activate( $key_a, $i['one'] ) ],
		[ 'call', 'activate_exchange_in_period', $activate( $key_b, $i['one'] ) ],
		[ 'call', 'status_b_after_exchange', $simple( 'status', $key_b, $i['one'] ) ],
		[ 'call', 'status_a_after_exchange', $simple( 'status', $key_a, $i['one'] ) ],
		[ 'call', 'deactivate_b', $simple( 'deactivate', $key_b, $i['one'] ) ],
	],
	// 無効化と、その後の状態確認・無効化のやり直し。同じキーで別のサイト（instance）も有効化しておく（無効化しても、別のサイトの有効化は残る。
	// R3-8a のフィクスチャ deactivate_success・status_inactive はこの状態で測った: total_activations が 1 のまま）。
	'deactivate'     => [
		[ 'call', 'deactivate_activate_a', $activate( $key_a, $i['one'] ) ],
		[ 'call', 'deactivate_activate_a_other_site', $activate( $key_a, $i['two'] ) ],
		[ 'call', 'deactivate_success', $simple( 'deactivate', $key_a, $i['one'] ) ],
		[ 'call', 'status_after_deactivate', $simple( 'status', $key_a, $i['one'] ) ],
		[ 'call', 'deactivate_again', $simple( 'deactivate', $key_a, $i['one'] ) ],
	],
	// 期限後に、更新の確認が先に来た場合（サーバーが有効化を消して package を空で返す）。
	'expired_update' => [
		[ 'call', 'expired_update_activate_a', $activate( $key_a, $i['one'] ) ],
		[ 'expire', 0, $hour ],
		[ 'call', 'update_expired', $plugin( 'update', $key_a, $i['one'] ) ],
		[ 'call', 'information_expired', $plugin( 'information', $key_a, $i['one'] ) ],
		[ 'call', 'status_after_expired_update', $simple( 'status', $key_a, $i['one'] ) ],
	],
	// 期限後に、状態確認が先に来た場合。猶予期間（既定 14 日）の中の新しい instance の有効化と、期限後のキーの交換も。
	'expired_status' => [
		[ 'call', 'expired_status_activate_a', $activate( $key_a, $i['one'] ) ],
		[ 'expire', 0, $hour ],
		[ 'call', 'status_expired', $simple( 'status', $key_a, $i['one'] ) ],
		[ 'call', 'deactivate_expired', $simple( 'deactivate', $key_a, $i['one'] ) ],
		[ 'call', 'update_expired_after', $plugin( 'update', $key_a, $i['one'] ) ],
		[ 'call', 'activate_expired_new_instance', $activate( $key_a, $i['two'] ) ],
		[ 'call', 'activate_exchange_after_expiry', $activate( $key_b, $i['one'] ) ],
	],
	// 猶予期間の後（期限を 30 日前にしただけ。リソースを消す背景処理は走らないので、本番とは違いうる）。
	'after_grace'    => [
		[ 'call', 'after_grace_activate_a', $activate( $key_a, $i['one'] ) ],
		[ 'expire', 0, $month ],
		[ 'call', 'status_after_grace', $simple( 'status', $key_a, $i['one'] ) ],
		[ 'call', 'update_after_grace', $plugin( 'update', $key_a, $i['one'] ) ],
		[ 'call', 'activate_after_grace', $activate( $key_a, $i['four'] ) ],
		[ 'call', 'deactivate_after_grace', $simple( 'deactivate', $key_a, $i['one'] ) ],
	],
];

$requested = 'all' === ( $opts['group'] ?? '' ) ? array_keys( $groups ) : array_filter( explode( ',', $opts['group'] ?? '' ) );

if ( [] === $requested ) {
	cbjp_am_abort( 'group=<all|' . implode( '|', array_keys( $groups ) ) . '>[,…] is required' );
}

foreach ( $requested as $name ) {
	if ( ! isset( $groups[ $name ] ) ) {
		cbjp_am_abort( "unknown group '{$name}' (groups: " . implode( ', ', array_keys( $groups ) ) . ')' );
	}
}

$am_version = (string) ( $state['am_version'] ?? '' );

if ( 1 !== preg_match( '/\A[0-9][0-9A-Za-z.-]*\z/', $am_version ) ) {
	cbjp_am_abort( "the state file has no readable API Manager version ('{$am_version}')", 1 );
}

$out_dir = cbjp_am_out_dir() . '/out/' . $am_version;

if ( ! is_dir( $out_dir ) && ! wp_mkdir_p( $out_dir ) ) {
	cbjp_am_abort( "could not create {$out_dir}", 1 );
}

// 全部を測るとき・前の setup で測った応答が残っているときは消す（別の setup の ID の応答と混ざって比べないように。R1-5）。
if ( 'all' === $opts['group'] || ! cbjp_am_measured_in_this_setup( $out_dir, $state ) ) {
	foreach ( (array) glob( $out_dir . '/*.json' ) as $old ) {
		if ( is_string( $old ) && ! unlink( $old ) ) {
			cbjp_am_abort( "could not remove {$old}", 1 );
		}
	}
}

// どの setup で測ったか（compare は、今の状態ファイルの setup で測った応答だけを、その ID で匿名化する。R1-5）。
cbjp_am_write_run_marker( $out_dir, $state );

$object   = (string) preg_replace( '#^https?://#i', '', home_url() );
$failures = 0;

foreach ( $requested as $name ) {
	echo "== {$name}\n";
	cbjp_am_reset_resources( $state );

	foreach ( $groups[ $name ] as $step ) {
		if ( 'expire' === $step[0] ) {
			cbjp_am_expire( $state, $step[1], $step[2] );
			echo "   (resource {$step[1]} expired " . human_time_diff( time() - $step[2] ) . " ago)\n";
			continue;
		}

		[ , $label, $body ] = $step;

		// 前の応答を先に消す（この要求が失敗したとき、古い応答が今の setup の応答として compare に残らないように。G2-1）。
		if ( is_file( "{$out_dir}/{$label}.json" ) && ! unlink( "{$out_dir}/{$label}.json" ) ) {
			cbjp_am_abort( "could not remove the previous response {$out_dir}/{$label}.json", 1 );
		}

		$response = wp_remote_post(
			'http://wordpress/?wc-api=wc-am-api',
			[
				'timeout'     => 15,
				'redirection' => 0,
				'body'        => array_merge( [ 'object' => $object ], $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			echo "   {$label}: WP_Error ", $response->get_error_message(), "\n";
			++$failures;
			continue;
		}

		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( false === file_put_contents( "{$out_dir}/{$label}.json", $raw ) ) {
			cbjp_am_abort( "could not write {$out_dir}/{$label}.json", 1 );
		}

		if ( ! is_array( $data ) ) {
			echo "   {$label}: HTTP ", wp_remote_retrieve_response_code( $response ), ' not JSON: ', substr( $raw, 0, 120 ), "\n";
			++$failures;
			continue;
		}

		// API Manager はエラーも HTTP 200 で返す。200 以外は、応答の形が合っていても測定の失敗にする（保存した本文には状態が入らない。G1-3）。
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			echo "   {$label}: HTTP ", wp_remote_retrieve_response_code( $response ), " (expected 200)\n";
			++$failures;
			continue;
		}

		$package = $data['data']['package']['package'] ?? null;
		$summary = array_filter(
			[
				'success'      => $data['success'] ?? null,
				'error'        => isset( $data['error'] ) ? substr( wp_strip_all_tags( (string) $data['error'] ), 0, 90 ) : null,
				'activated'    => $data['activated'] ?? ( $data['data']['activated'] ?? null ),
				'deactivated'  => $data['deactivated'] ?? null,
				'status_check' => $data['status_check'] ?? null,
				'new_version'  => $data['data']['package']['new_version'] ?? null,
				'package'      => null === $package ? null : ( '' === $package ? 'empty' : 'url' ),
			],
			static fn( $v ): bool => null !== $v
		);
		echo "   {$label}: HTTP ", wp_remote_retrieve_response_code( $response ), ' ', wp_json_encode( $summary, JSON_UNESCAPED_SLASHES ), "\n";
	}
}

cbjp_am_reset_resources( $state );
echo "saved to {$out_dir} (the resources were reset)\n";

if ( 0 !== $failures ) {
	echo "measure: {$failures} request(s) failed\n";
	exit( 1 );
}
