<?php
/**
 * cbjp-measure-api-manager: managed by .claude/skills/measure-api-manager
 *
 * TEMPORARY. 手元の WooCommerce API Manager の実測専用。wp-env の dev サイトだけに置き、Cart Bridge JP Pro のライセンスの要求を
 * dev サイトに入れた API Manager（コンテナの中の http://wordpress/）へ向ける。`am.sh teardown` が後片付けの後に削除する（コミットしない）。
 *
 * - Pro の接続先と製品 ID の定数を、Pro より先に定義する（Pro のメインファイルは定義済みなら上書きしない）。
 *   Pro のヘッダーに販売サイトの `Update URI` が入った後も、要求はこの定数の接続先へ行く（`update_plugins_{host}` の host はヘッダーのまま）。
 * - コンテナの名前（`wordpress`）はプライベートなアドレスに解決されるので、外部として許す（Pro は wp_safe_remote_post() で送る）。
 * - プラグインの更新・インストールの実行を止める。プラグインのディレクトリはリポジトリのマウントで、上書きすると作業ツリーが消える。
 * - 販売サイトへの要求（操作・待ち時間・cron かどうか）を記録する（`am.sh e2e check` が表示する）。
 *
 * @package CartBridgeJP
 */

defined( 'ABSPATH' ) || exit;

// `php/setup.php step=verify` と `php/e2e.php` は、この mu-plugin が読み込まれていることを確かめてから動く。
define( 'CBJP_AM_PROBE', true );
define( 'CBJP_PRO_LICENSE_API_URL', 'http://wordpress/' );
// `am.sh setup` が API の製品の ID で置き換える。
define( 'CBJP_PRO_LICENSE_PRODUCT_ID', (int) '__PRODUCT_ID__' );

add_filter(
	'http_request_host_is_external',
	static function ( $external, $host ) {
		// host は接続先の定数から取る（小文字の名前を文字列で書くと、WPCS の CapitalPDangit の自動修正が「WordPress」に変える）。
		return wp_parse_url( CBJP_PRO_LICENSE_API_URL, PHP_URL_HOST ) === $host ? true : $external;
	},
	10,
	2
);

add_filter( 'auto_update_plugin', '__return_false', 100 );

add_filter(
	'upgrader_pre_install',
	static function () {
		return new WP_Error( 'cbjp_am_probe_no_install', 'measure-api-manager: installing and updating plugins is disabled on this dev site (the plugin directories are the repository mounts).' );
	},
	1
);

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( is_string( $url ) && str_contains( $url, 'wc-api=wc-am-api' ) ) {
			$body  = is_array( $args ) && is_array( $args['body'] ?? null ) ? $args['body'] : [];
			$log   = get_option( 'cbjp_am_probe_requests', [] );
			$log   = is_array( $log ) ? $log : [];
			$log[] = [
				'action'  => is_string( $body['wc_am_action'] ?? null ) ? $body['wc_am_action'] : '?',
				'timeout' => is_array( $args ) ? ( $args['timeout'] ?? null ) : null,
				'cron'    => wp_doing_cron(),
			];
			update_option( 'cbjp_am_probe_requests', $log, false );
		}

		return $pre;
	},
	1,
	3
);
