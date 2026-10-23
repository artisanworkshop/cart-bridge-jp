<?php
/**
 * Pro のマニュアル用スクリーンショットの撮影専用のモック。`trial/`・`licensed/` の mu-plugin.php（共有スキル wporg-screenshots の
 * mu-plugin フック）が、wp-env のルートのマウント（wp-content/cbjp-dev）からこのファイルを読み込む。
 *
 * TEMPORARY. 撮影の間だけ tests サイトの全要求と WP-CLI に読み込まれ、撮影が終わると capture.sh が mu-plugin を外す。
 *
 * - Color Me Shop API（api.shop-pro.jp）への GET は、URL のファイル名と同じ匿名化済みフィクスチャで返す。探す順は、このディレクトリの
 *   fixtures/（撮影用に作った架空のクーポン）→ Pro の tests/fixtures/colorme/（顧客・受注・決済・配送）→ 無料版の tests/fixtures/colorme/
 *   （ショップ・商品・カテゴリ・グループ）。一覧（商品・顧客・受注）の 2 ページ目以降は空の一覧。GET 以外とフィクスチャの無い取得は WP_Error。
 *   実店舗・テストショップへは接続しない。
 * - Pro のライセンスサーバー・更新の配信（販売サイトの host）への要求は、すべて WP_Error にする。licensed の撮影は偽のキーで有効化の記録を
 *   作るので、`UpdateChecker` がそのキーを販売サイトへ送ろうとする（`.claude/rules/pro-license.md`）。
 * - 撮影向けの差し替え: グループを表示中にして名前を `Sale` にする・ショップをプレミアムプランにする（無料版の撮影と同じ）。
 * - tests サイトでは WP-Cron が自走しないので、Action Scheduler の「過去の予定が残っている」通知を出さない。
 *
 * 致命的エラーを出さない（tests サイトの全要求が落ちる。`.claude/rules/skill-scripts.md`）。
 *
 * @package CartBridgeJP
 */

defined( 'ABSPATH' ) || exit;

// setup.php・teardown.php は、このモックが読み込まれていること（＝実 API・販売サイトに出ていかないこと）を確かめてから動く。
if ( ! defined( 'CBJP_PRO_MANUAL_FIXTURES' ) ) {
	define( 'CBJP_PRO_MANUAL_FIXTURES', __DIR__ );
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! is_string( $url ) ) {
			return $pre;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) ) {
			return $pre;
		}

		$host = strtolower( $host );

		// 販売サイト（ライセンスサーバー・更新の配信）。定数が読めなくても既知の host は止める。
		$license_hosts = [ 'wc.artws.info' ];

		if ( defined( 'CBJP_PRO_LICENSE_API_URL' ) && is_string( CBJP_PRO_LICENSE_API_URL ) ) {
			$license_host = wp_parse_url( CBJP_PRO_LICENSE_API_URL, PHP_URL_HOST );

			if ( is_string( $license_host ) ) {
				$license_hosts[] = strtolower( $license_host );
			}
		}

		if ( in_array( $host, $license_hosts, true ) ) {
			return new WP_Error( 'cbjp_pro_manual_offline', 'The license server is not contacted while taking the manual screenshots: ' . $url );
		}

		if ( 'api.shop-pro.jp' !== $host ) {
			return $pre;
		}

		$method = is_array( $args ) && is_string( $args['method'] ?? null ) ? strtoupper( $args['method'] ) : 'GET';

		if ( 'GET' !== $method ) {
			return new WP_Error( 'cbjp_pro_manual_offline', 'Only GET is served while taking the manual screenshots: ' . $url );
		}

		$file = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$path = null;

		foreach ( [ __DIR__ . '/fixtures/', WP_PLUGIN_DIR . '/cart-bridge-jp-pro/tests/fixtures/colorme/', WP_PLUGIN_DIR . '/cart-bridge-jp/tests/fixtures/colorme/' ] as $dir ) {
			if ( is_readable( $dir . $file ) ) {
				$path = $dir . $file;
				break;
			}
		}

		$query = [];
		wp_parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$offset = is_scalar( $query['offset'] ?? null ) ? (int) $query['offset'] : 0;
		$lists  = [
			'products.json'  => 'products',
			'customers.json' => 'customers',
			'sales.json'     => 'sales',
		];

		if ( isset( $lists[ $file ] ) && ( $offset > 0 || null === $path ) ) {
			$data = [
				$lists[ $file ] => [],
				'meta'          => [ 'total' => 0 ],
			];
		} else {
			$data = null !== $path ? wp_json_file_decode( $path, [ 'associative' => true ] ) : null;
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'cbjp_pro_manual_offline', 'No fixture for this request while taking the manual screenshots: ' . $url );
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

		// フィクスチャの顧客は受注の購入者（非会員）で、3 人と 2 人が同じメールアドレス。Pro は会員だけを顧客として取り込むので、
		// 撮影では全員を会員にし、重複する 3 人に別の名前・アドレスを与える（すべて example.com）。
		if ( 'customers.json' === $file && is_array( $data['customers'] ?? null ) ) {
			$people = [
				'175270884' => [ '佐藤 一郎', 'サトウ イチロウ', 'ichiro@example.com' ],
				'174712086' => [ '高橋 恵子', 'タカハシ ケイコ', 'keiko@example.com' ],
				'174712085' => [ '田中 美咲', 'タナカ ミサキ', 'misaki@example.com' ],
			];

			foreach ( $data['customers'] as $i => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$data['customers'][ $i ]['member'] = true;
				$id                                = is_scalar( $row['id'] ?? null ) ? (string) $row['id'] : '';

				if ( isset( $people[ $id ] ) ) {
					[ $data['customers'][ $i ]['name'], $data['customers'][ $i ]['furigana'], $data['customers'][ $i ]['mail'] ] = $people[ $id ];
				}
			}
		}

		// マニュアルに写る名前を、開発用の名前（`フィクスチャ_…`・`テストカテゴリ`）から店舗らしい名前にする。ID で選び、無ければ何もしない。
		$names = [
			'products.json'   => [
				'products',
				'id',
				[
					'192817398' => '焼き菓子の詰め合わせ',
					'192817159' => 'オーガニックコットン T シャツ',
				],
			],
			'categories.json' => [
				'categories',
				'id_big',
				[
					'2993030' => '食品',
					'2993032' => 'ファッション',
				],
			],
		];

		if ( isset( $names[ $file ] ) && is_array( $data[ $names[ $file ][0] ] ?? null ) ) {
			[ $list_key, $id_key, $renames ] = $names[ $file ];

			foreach ( $data[ $list_key ] as $i => $row ) {
				$id = is_array( $row ) && is_scalar( $row[ $id_key ] ?? null ) ? (string) $row[ $id_key ] : '';

				if ( isset( $renames[ $id ] ) ) {
					$data[ $list_key ][ $i ]['name'] = $renames[ $id ];
				}
			}
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
