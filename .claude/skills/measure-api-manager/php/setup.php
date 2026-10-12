<?php
/**
 * 実測の準備（`am.sh setup` が段階ごとに呼ぶ）。作るたびに状態ファイルへ書く（途中で止まっても `am.sh teardown` が消せるように）。
 * 引数: site=<dev サイトの URL> step=<init|tables|data|verify> [am_zip=… pro_zip=… pro_version=… new_version=…]（init だけ）
 *
 * - init:   状態ファイルを作る（承認済みディレクトリの今のモードを記録する）。API Manager を入れる前に呼ぶ
 * - tables: API Manager のテーブルがあるか。無ければ `missing tables:` を出して exit 3（`npx wp-env run` は終了コードを 1 にまとめるので、
 *           am.sh は出力で見分け、インストーラーを起こしてからもう一度呼ぶ）
 * - data:   API の製品・説明と変更履歴のページ・顧客・完了した注文 2 件・API のリソース 2 つ（キー A・B）を作る。最後に PRODUCT_ID=<id>
 * - verify: mu-plugin が読み込まれ、手元の API Manager が状態確認に答えることを確かめる
 *
 * R3-8c で手で行った手順をそのまま書いた（つまずいた箇所は SKILL.md「落とし穴」）。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$opts = cbjp_am_args( $args, [ 'site', 'step', 'am_zip', 'pro_zip', 'pro_version', 'new_version' ] );
cbjp_am_require_site( $opts );

$step = $opts['step'] ?? '';

switch ( $step ) {
	case 'init':
		if ( null !== cbjp_am_state() ) {
			cbjp_am_abort( 'a state file already exists (run: am.sh teardown)' );
		}

		foreach ( [ 'am_zip', 'pro_zip' ] as $key ) {
			if ( 1 !== preg_match( '/\A[A-Za-z0-9._-]+\.zip\z/', $opts[ $key ] ?? '' ) ) {
				cbjp_am_abort( "{$key}=<file name of a zip in dist/api-manager/> is required" );
			}
		}

		foreach ( [ 'pro_version', 'new_version' ] as $key ) {
			if ( 1 !== preg_match( '/\A[0-9]+\.[0-9]+\.[0-9]+\z/', $opts[ $key ] ?? '' ) ) {
				cbjp_am_abort( "{$key}=<X.Y.Z> is required" );
			}
		}

		if ( false !== get_option( CBJP_AM_TOKEN_OPTION ) ) {
			cbjp_am_abort( 'the probe token option already exists (an earlier run? run: am.sh teardown)' );
		}

		// 状態ファイルとこの DB を結び付けるトークン（R1-1）。状態ファイルを先に書く: option だけが残ると、状態ファイルの無い残りとして teardown が止まる。
		// wp_generate_password() は random_password フィルターで記号を足されうる（G1-8）。フィルターを通らない乱数にする。
		$token = bin2hex( random_bytes( 16 ) );

		cbjp_am_state_write(
			[
				'token'         => $token,
				'created_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'site'          => $opts['site'],
				'am_zip'        => $opts['am_zip'],
				'pro_zip'       => $opts['pro_zip'],
				'pro_version'   => $opts['pro_version'],
				'new_version'   => $opts['new_version'],
				// teardown が戻す値。setup の間はダウンロードのファイルを置くために承認を外す（R3-8c: 承認済みディレクトリに足しても、製品の保存で弾かれた）。
				'approved_mode' => cbjp_am_approved_register()->get_mode(),
			]
		);
		if ( ! add_option( CBJP_AM_TOKEN_OPTION, $token, '', false ) || get_option( CBJP_AM_TOKEN_OPTION ) !== $token ) {
			cbjp_am_abort( 'could not save the probe token (run: am.sh teardown)', 1 );
		}

		echo 'state: ', cbjp_am_state_path(), "\n";
		break;

	case 'tables':
		global $wpdb;

		if ( ! function_exists( 'WCAM' ) ) {
			cbjp_am_abort( 'API Manager is not active', 1 );
		}

		$missing = [];

		foreach ( [ 'wc_am_api_resource', 'wc_am_api_activation' ] as $table ) {
			if ( $wpdb->prefix . $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) ) ) {
				$missing[] = $table;
			}
		}

		if ( [] !== $missing ) {
			echo 'missing tables: ', implode( ', ', $missing ), "\n";
			exit( 3 );
		}

		echo "tables: ok\n";
		break;

	case 'data':
		$state = cbjp_am_state();

		if ( null === $state || isset( $state['product_id'] ) ) {
			cbjp_am_abort( 'run step=init first, and only once (the state file already lists a product)' );
		}

		if ( ! cbjp_am_state_belongs_here( $state ) ) {
			cbjp_am_abort( 'the state file does not belong to this dev-site database (run: am.sh teardown)', 1 );
		}

		if ( ! function_exists( 'WCAM' ) || ! function_exists( 'WC_AM_PRODUCT_DATA_STORE' ) || ! function_exists( 'WC_AM_ORDER' ) ) {
			cbjp_am_abort( 'API Manager is not active (or this version has no WC_AM_PRODUCT_DATA_STORE()/WC_AM_ORDER())', 1 );
		}

		$plugin = get_file_data( WP_PLUGIN_DIR . '/' . CBJP_AM_PLUGIN_SLUG . '/' . CBJP_AM_PLUGIN_SLUG . '.php', [ 'Version' => 'Version' ] );
		cbjp_am_state_set( 'am_version', (string) $plugin['Version'] );

		$register = cbjp_am_approved_register();
		$register->set_mode( $register::MODE_DISABLED );

		// Pro の zip（版を上げた写し）をダウンロードのファイルとして置く。
		$source = dirname( __DIR__, 4 ) . '/dist/api-manager/' . $state['pro_zip'];
		$upload = wp_upload_dir();
		$dir    = $upload['basedir'] . '/' . CBJP_AM_UPLOAD_SUBDIR;

		if ( ! is_file( $source ) ) {
			cbjp_am_abort( "the Pro zip is missing: {$source}", 1 );
		}

		if ( ! wp_mkdir_p( $dir ) || ! copy( $source, $dir . '/' . $state['pro_zip'] ) ) {
			cbjp_am_abort( "could not copy the Pro zip into {$dir}", 1 );
		}

		$file_url = $upload['baseurl'] . '/' . CBJP_AM_UPLOAD_SUBDIR . '/' . $state['pro_zip'];

		$product = new WC_Product_Simple();
		$product->set_name( CBJP_AM_PRODUCT_NAME );
		$product->set_regular_price( '10000' );
		$product->set_virtual( true );
		$product->set_downloadable( true );
		$download = new WC_Product_Download();
		$download->set_name( 'cart-bridge-jp-pro' );
		$download->set_file( $file_url );
		$download->set_id( md5( $file_url ) );
		$product->set_downloads( [ $download ] );
		$product->set_status( 'publish' );
		$product->update_meta_data( CBJP_AM_MARKER_META, 'yes' );
		$product_id = $product->save();

		if ( $product_id <= 0 ) {
			cbjp_am_abort( 'could not create the API product', 1 );
		}

		cbjp_am_state_set( 'product_id', $product_id );

		// 説明と変更履歴（information の sections）。説明の script は、Pro が kses することを確かめるため（R3-8c と同じ）。
		// 文はフィクスチャと同じ固定の文にする（compare は版を比べないので、--new-version に合わせると節が違って見える。R1-4）。
		$pages = [];

		foreach ( [ 'description' => '<p>Pro description</p><script>alert(1)</script>', 'changelog' => '<h4>0.1.1</h4><ul><li>Fix</li></ul>' ] as $section => $content ) {
			$page_id = wp_insert_post(
				[
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'cbjp-am-' . $section,
					'post_content' => $content,
					'meta_input'   => [ CBJP_AM_MARKER_META => 'yes' ],
				],
				true
			);

			if ( is_wp_error( $page_id ) ) {
				cbjp_am_abort( 'could not create the ' . $section . ' page: ' . $page_id->get_error_message(), 1 );
			}

			$pages[ $section ] = $page_id;
			cbjp_am_state_set( 'page_ids', array_values( $pages ) );
		}

		$meta = [
			'_is_api'                    => 'yes',
			'_api_resource_product_id'   => $product_id,
			'_api_resource_title'        => CBJP_AM_PRODUCT_NAME,
			'_api_new_version'           => $state['new_version'],
			'_api_tested_up_to'          => '7.1',
			'_api_version_required'      => '6.9',
			'_api_requires_php'          => '8.2',
			'_api_last_updated'          => '2026-10-12',
			'_api_plugin_url'            => home_url( '/cart-bridge-jp-pro/' ),
			'_api_author'                => '<a href="https://example.com/">Artisan Workshop</a>',
			'_api_upgrade_notice'        => 'Test upgrade notice <b>bold</b>',
			'_api_activations_unlimited' => 'yes',
			'_api_activations'           => 1,
			'_access_expires'            => 365,
			'_api_description'           => $pages['description'],
			'_api_changelog'             => $pages['changelog'],
		];

		foreach ( $meta as $key => $value ) {
			WC_AM_PRODUCT_DATA_STORE()->update_meta( $product_id, $key, $value );
		}

		$user_id = wp_insert_user(
			[
				'user_login' => CBJP_AM_BUYER_LOGIN,
				'user_email' => 'buyer@example.com',
				'user_pass'  => wp_generate_password( 32 ),
				'role'       => 'customer',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			cbjp_am_abort( 'could not create the customer: ' . $user_id->get_error_message(), 1 );
		}

		cbjp_am_state_set( 'user_id', $user_id );

		// 注文の作成・完了で WooCommerce がメールを送らないようにする（この要求の中だけ）。送ると dev サイトのメールのログ（WP Mail Logging）に残る。
		foreach ( WC()->mailer()->get_emails() as $email ) {
			add_filter( 'woocommerce_email_enabled_' . $email->id, '__return_false' );
		}

		$order_ids = [];

		for ( $i = 0; $i < 2; $i++ ) {
			// 目印と顧客は最初の保存で一緒に書く（記録した ID の注文に目印が無いと、teardown が消さずに止まり続ける。G1-9）。
			$order = new WC_Order();
			$order->set_customer_id( $user_id );
			$order->set_created_via( 'admin' );
			$order->update_meta_data( CBJP_AM_MARKER_META, 'yes' );

			if ( $order->save() <= 0 ) {
				cbjp_am_abort( 'could not create an order', 1 );
			}

			$order_ids[] = $order->get_id();
			cbjp_am_state_set( 'order_ids', $order_ids );

			$order->add_product( wc_get_product( $product_id ), 1 );
			$order->set_billing_email( 'buyer@example.com' );
			$order->calculate_totals();
			$order->save();
			$order->update_status( 'completed' );

			// 完了にしただけではリソースが作られないことがある（R3-8c。テーブルが無いまま注文を完了にした）。無ければ API Manager の注文の処理を走らせる。
			$has_resource = static fn( int $order_id ): bool => [] !== array_filter( cbjp_am_read_resources( $user_id ), static fn( array $r ): bool => $r['order_id'] === $order_id );

			if ( ! $has_resource( $order->get_id() ) ) {
				WC_AM_ORDER()->update_order( $order->get_id() );
			}
		}

		$resources = cbjp_am_read_resources( $user_id );

		if ( 2 !== count( $resources ) ) {
			cbjp_am_abort( 'expected two API resources (keys A and B), found ' . count( $resources ) . ' (run: am.sh teardown)', 1 );
		}

		cbjp_am_state_set( 'resources', $resources );
		cbjp_am_state_set( 'master_key', function_exists( 'WC_AM_USER' ) ? (string) WC_AM_USER()->get_master_api_key( $user_id ) : '' );

		echo 'created: product ', $product_id, ', pages ', implode( ',', $pages ), ', customer ', $user_id, ', orders ', implode( ',', $order_ids ),
			', keys ', implode( ',', array_map( static fn( array $r ): string => cbjp_am_mask( $r['key'] ), $resources ) ), "\n";
		echo 'PRODUCT_ID=', $product_id, "\n";
		break;

	case 'verify':
		$state = cbjp_am_ready_state();

		if ( ! defined( 'CBJP_AM_PROBE' ) || 'http://wordpress/' !== CBJP_PRO_LICENSE_API_URL || $state['product_id'] !== CBJP_PRO_LICENSE_PRODUCT_ID ) {
			cbjp_am_abort( 'the mu-plugin is not loaded, or it points Pro at another server or product', 1 );
		}

		$response = wp_remote_post(
			'http://wordpress/?wc-api=wc-am-api',
			[
				'timeout'     => 15,
				'redirection' => 0,
				'body'        => [
					'wc_am_action' => 'status',
					'product_id'   => (string) $state['product_id'],
					'api_key'      => $state['resources'][0]['key'],
					'instance'     => cbjp_am_instances()['one'],
					'object'       => (string) preg_replace( '#^https?://#i', '', home_url() ),
				],
			]
		);
		$body     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || true !== ( $body['success'] ?? null ) || 'inactive' !== ( $body['status_check'] ?? null ) ) {
			cbjp_am_abort( 'the local API Manager did not answer a status check as expected: ' . ( is_wp_error( $response ) ? $response->get_error_message() : substr( wp_remote_retrieve_body( $response ), 0, 300 ) ), 1 );
		}

		cbjp_am_clear_cache();
		echo 'verify: the mu-plugin is loaded and API Manager ', $state['am_version'] ?? '?', " answers\n";
		break;

	default:
		cbjp_am_abort( 'step=<init|tables|data|verify> is required' );
}
