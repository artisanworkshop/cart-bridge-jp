<?php
/**
 * 状態ファイルと消し残しの数を表示する（何も変えない）。`am.sh status` が呼ぶ。
 * 引数: site=<dev サイトの URL>
 *
 * 出力の最後の行は `LEFTOVERS=<合計>`（mu-plugin を除く。am.sh が読む）。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$opts = cbjp_am_args( $args, [ 'site' ] );
cbjp_am_require_site( $opts );

$state  = cbjp_am_state();
$counts = cbjp_am_leftovers( $state );

if ( null === $state ) {
	echo "state: none\n";
} else {
	echo 'state: ', wp_json_encode(
		[
			'created_at'    => $state['created_at'] ?? null,
			'am_version'    => $state['am_version'] ?? null,
			'pro_version'   => $state['pro_version'] ?? null,
			'new_version'   => $state['new_version'] ?? null,
			'product_id'    => $state['product_id'] ?? null,
			'user_id'       => $state['user_id'] ?? null,
			'order_ids'     => $state['order_ids'] ?? null,
			'resources'     => array_map(
				static fn( $r ): array => is_array( $r ) ? [
					'id'  => $r['id'] ?? null,
					'key' => cbjp_am_mask( (string) ( $r['key'] ?? '' ) ),
				] : [],
				(array) ( $state['resources'] ?? [] )
			),
			'approved_mode' => $state['approved_mode'] ?? null,
		],
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
	), "\n";
}

echo 'api manager: ', function_exists( 'WCAM' ) ? 'active' : ( is_dir( WP_PLUGIN_DIR . '/' . CBJP_AM_PLUGIN_SLUG ) ? 'installed (inactive)' : 'absent' ), "\n";
echo 'approved directories mode: ', cbjp_am_approved_register()->get_mode(), "\n";
echo 'leftovers: ', wp_json_encode( $counts ), "\n";

unset( $counts['mu_plugin'] );
echo 'LEFTOVERS=', array_sum( $counts ), "\n";
