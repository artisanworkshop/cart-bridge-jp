<?php
/**
 * cbjp-wporg-screenshots: managed by .claude/skills/wporg-screenshots
 *
 * TEMPORARY. wordpress.org 用スクリーンショットの撮影専用。wp-env の tests サイトだけに置き、Color Me Shop API
 * （api.shop-pro.jp）への HTTP を匿名化済みフィクスチャ（tests/fixtures/colorme/）で返す。実店舗・テストショップへは接続しない。
 * 撮影が終わると `capture.sh` が削除する（tests サイトの mu-plugin は PHPUnit にも読み込まれるので、残さない。コミットしない）。
 *
 * - GET はパスの末尾のファイル名（`products.json` など）と同じ名前のフィクスチャを返す。一覧（商品・顧客・受注・クーポン）は
 *   2 ページ目以降と、フィクスチャの無いものを空の一覧で返す。GET 以外と、フィクスチャの無い取得は WP_Error にする。
 * - 撮影向けの差し替え: 顧客は全員を会員にする（フィクスチャは非会員で、取込みの対象にならない）。グループは表示中にして
 *   名前を `Sale` にする。ショップはプレミアムプランにする（Export タブにベータの機能を出す）。
 * - tests サイトでは WP-Cron が自走しないので、Action Scheduler の「過去の予定が残っている」通知を出さない。
 *
 * @package CartBridgeJP
 */

defined( 'ABSPATH' ) || exit;

// `php/setup.php` は、この mu-plugin が読み込まれていること（＝実 API に出ていかないこと）を確かめてから準備を始める。
define( 'CBJP_SCREENSHOT_FIXTURES', true );

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! is_string( $url ) || 'api.shop-pro.jp' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $pre;
		}

		$method = is_array( $args ) && is_string( $args['method'] ?? null ) ? strtoupper( $args['method'] ) : 'GET';

		if ( 'GET' !== $method ) {
			return new WP_Error( 'cbjp_screenshot_offline', 'Only GET is served while taking screenshots: ' . $url );
		}

		$file  = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$path  = WP_PLUGIN_DIR . '/cart-bridge-jp/tests/fixtures/colorme/' . $file;
		$query = [];
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$offset = is_scalar( $query['offset'] ?? null ) ? (int) $query['offset'] : 0;
		$lists  = [
			'products.json'     => 'products',
			'customers.json'    => 'customers',
			'sales.json'        => 'sales',
			'shop_coupons.json' => 'shop_coupons',
		];

		if ( isset( $lists[ $file ] ) && ( $offset > 0 || ! is_readable( $path ) ) ) {
			$data = [
				$lists[ $file ] => [],
				'meta'          => [ 'total' => 0 ],
			];
		} else {
			$data = is_readable( $path ) ? wp_json_file_decode( $path, [ 'associative' => true ] ) : null;
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cbjp_screenshot_offline', 'No fixture for this request while taking screenshots: ' . $url );
		}

		if ( 'customers.json' === $file && is_array( $data['customers'] ?? null ) ) {
			foreach ( $data['customers'] as $i => $row ) {
				if ( is_array( $row ) ) {
					$data['customers'][ $i ]['member'] = true;
				}
			}
		}

		if ( 'groups.json' === $file && is_array( $data['groups'] ?? null ) ) {
			foreach ( $data['groups'] as $i => $row ) {
				if ( is_array( $row ) ) {
					$data['groups'][ $i ]['display_state'] = 'showing';
					$data['groups'][ $i ]['name']          = 'Sale';
				}
			}
		}

		if ( 'shop.json' === $file && is_array( $data['shop'] ?? null ) ) {
			$data['shop']['contract_plan'] = 'premium';
		}

		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => (string) wp_json_encode( $data ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	},
	10,
	3
);

add_filter( 'action_scheduler_pastdue_actions_check_pre', '__return_false' );
