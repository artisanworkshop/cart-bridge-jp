<?php
/**
 * 更新の配信の実機確認（`am.sh e2e <step>` が呼ぶ。ヘッダーの `Update URI` の出し入れは am.sh が行う）。
 * 引数: site=<dev サイトの URL> step=<reset|activate|check|details|rows|expire|forget>
 *
 * - reset:    リソースを戻し、Pro のキーの記録・要求の記録・WP の更新の情報を消す
 * - activate: Pro の設定画面と同じ処理（LicenseManager::activate()）でキー A を有効化する
 * - check:    WP の更新の情報を捨てて wp_update_plugins() を走らせ、Pro の行（response / no_update）・販売サイトへの要求・手元の有効化を表示する
 * - details:  「詳細を表示」と同じ plugins_api( 'plugin_information' )
 * - rows:     プラグインの一覧の更新の行（wp_plugin_update_row()）の文
 * - expire:   キー A の期限を 1 時間前にする（期限後の package の無い更新と、その説明）
 * - forget:   手元の有効化の記録だけを消す（有効化していないサイトは販売サイトに問い合わせないこと）
 *
 * 更新の実行（Plugin_Upgrader）はしない。プラグインのディレクトリはリポジトリのマウントで、上書きすると作業ツリーが消える（mu-plugin も止める）。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$opts = cbjp_am_args( $args, [ 'site', 'step' ] );
cbjp_am_require_site( $opts );

$state = cbjp_am_ready_state();
$file  = 'cart-bridge-jp-pro/cart-bridge-jp-pro.php';

if ( ! defined( 'CBJP_AM_PROBE' ) || ! class_exists( \CartBridgeJP\Pro\License\LicenseManager::class ) ) {
	cbjp_am_abort( 'the mu-plugin and Cart Bridge JP Pro must be active', 1 );
}

$license_options = static function (): array {
	global $wpdb;

	return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'cbjp_pro_license_' ) . '%' ) );
};

switch ( $opts['step'] ?? '' ) {
	case 'reset':
		cbjp_am_reset_resources( $state );

		foreach ( array_merge( $license_options(), [ CBJP_AM_REQUEST_LOG ] ) as $option ) {
			delete_option( $option );
		}

		delete_site_transient( 'update_plugins' );
		echo 'reset: resources, Pro license options, request log, update_plugins (left: ', count( $license_options() ), ")\n";
		break;

	case 'activate':
		$result = \CartBridgeJP\Pro\License\LicenseManager::create()->activate( (string) $state['resources'][0]['key'] );
		echo 'activate: ', $result->code, ' ', $result->message, "\n";

		if ( ! $result->is_success() ) {
			exit( 1 );
		}

		break;

	case 'check':
		// ヘッダーに Update URI が無いと、WP は Pro の update_plugins_{host} を呼ばない（「問い合わせない」と区別できない。R2-2）。
		$header = get_file_data( CBJP_PRO_FILE, [ 'UpdateURI' => 'Update URI' ] );

		if ( '' === trim( (string) $header['UpdateURI'] ) ) {
			echo "check: the Pro header has no Update URI, so WordPress never asks Pro (run: am.sh e2e start)\n";
			exit( 1 );
		}

		delete_option( CBJP_AM_REQUEST_LOG );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$updates = get_site_transient( 'update_plugins' );

		// wp_update_plugins() は wordpress.org への要求が失敗すると、Update URI の段（update_plugins_{host}）の前に戻る。`checked` は
		// wordpress.org の応答を受けた後に入るので、無ければ「Pro が答えなかった」とも「要求しなかった」とも言えない（R1-2）。
		if ( ! is_object( $updates ) || ! isset( $updates->checked[ $file ] ) ) {
			echo "check: WordPress did not reach the Update URI step (the wordpress.org update check failed or did not run); nothing can be concluded\n";
			exit( 1 );
		}

		$row     = $updates->response[ $file ] ?? null;
		$quiet   = $updates->no_update[ $file ] ?? null;
		$entry   = $row ?? $quiet;

		echo 'in: ', null !== $row ? 'response (an update is offered)' : ( null !== $quiet ? 'no_update' : 'none (Pro added no entry: not activated, or no answer from the license server)' ), "\n";

		if ( null !== $entry ) {
			$copy = (array) $entry;

			if ( is_string( $copy['package'] ?? null ) && '' !== $copy['package'] ) {
				$copy['package'] = (string) preg_replace( '/([?&]h(name|key)=)[^&]+/', '$1…', $copy['package'] );
			}

			echo wp_json_encode( $copy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
		}

		echo 'requests to the license server: ', wp_json_encode( get_option( CBJP_AM_REQUEST_LOG, [] ) ), "\n";
		echo 'local activation: ', ( new \CartBridgeJP\Pro\License\LicenseStore() )->is_activated() ? 'yes' : 'no', "\n";
		break;

	case 'details':
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$api = plugins_api( 'plugin_information', [ 'slug' => 'cart-bridge-jp-pro' ] );

		if ( is_wp_error( $api ) ) {
			echo 'WP_Error: ', $api->get_error_code(), ' ', $api->get_error_message(), "\n";
			break;
		}

		$info = (array) $api;
		echo wp_json_encode(
			array_merge( $info, [ 'sections' => array_map( static fn( $html ): string => substr( (string) $html, 0, 120 ), (array) ( $info['sections'] ?? [] ) ) ] ),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		), "\n";
		break;

	case 'rows':
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$admins = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		);
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
		ob_start();
		wp_plugin_update_row( $file, get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false ) );
		$html = (string) ob_get_clean();
		echo '' === trim( $html ) ? '(no update row)' : trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) ), "\n";
		break;

	case 'expire':
		cbjp_am_expire( $state, 0, HOUR_IN_SECONDS );
		echo "expire: key A expired an hour ago (run check)\n";
		break;

	case 'forget':
		foreach ( $license_options() as $option ) {
			delete_option( $option );
		}

		echo "forget: the local license record was removed (run check: no request should reach the license server)\n";
		break;

	default:
		cbjp_am_abort( 'step=<reset|activate|check|details|rows|expire|forget> is required' );
}
