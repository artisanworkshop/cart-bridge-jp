<?php
/**
 * 後片付け（`am.sh teardown` が呼ぶ）。状態ファイルがこの DB と結び付いている（トークンが一致する）ときだけ消す。投稿・注文・顧客は状態ファイルの ID と
 * 目印が一致するものだけ、API Manager の分と Pro のキーの記録は名前で消す（preflight で 0 だったので setup の後にできたもの）。
 * 状態ファイルが無い・結び付かないのに残りがあれば、推測で消さずに止める。
 * 引数: site=<dev サイトの URL> step=<data|am>
 *
 * - data: 注文 → 製品・ページ → 顧客、Pro のキーの記録・要求の記録・WP の更新の情報、承認済みディレクトリ（モードを setup の前の値に戻す）、アップロード。
 *         API Manager が有効な間に行う（注文の削除で API Manager が自分のリソースを片付ける）
 * - am:   API Manager を無効化・削除した後（am.sh が行う）に、そのテーブル・option・usermeta・WP-Cron・Action Scheduler の予定を消して
 *         書き換え規則を作り直し、消し残しを数え直す（mu-plugin を除いて 0 でなければ exit 1）。
 *         API Manager が有効なまま option を消すと、次の要求でインストーラー（init の check_version）がテーブルを作り直す。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$opts = cbjp_am_args( $args, [ 'site', 'step' ] );
cbjp_am_require_site( $opts );

if ( ! in_array( $opts['step'] ?? '', [ 'data', 'am' ], true ) ) {
	cbjp_am_abort( 'step=<data|am> is required' );
}

global $wpdb;

$state = cbjp_am_state();

// 状態ファイルが無い、またはこの DB で setup したものでない（トークンが無い・違う。R1-1）: 残りが無ければ何もしない。あれば推測で消さずに止まる。
if ( null === $state || ! cbjp_am_state_belongs_here( $state ) ) {
	// 結び付かない状態ファイルの ID は、この DB では別の投稿・注文を指しうるので数えに使わない（名前と目印だけで数える。R2-1）。
	$counts = cbjp_am_leftovers( null );
	unset( $counts['mu_plugin'] );

	if ( 0 === array_sum( $counts ) ) {
		echo null === $state ? "teardown: no state file and nothing left\n" : "teardown: the state file does not belong to this database, and nothing is left\n";
		exit( 0 );
	}

	cbjp_am_abort(
		( null === $state ? 'no state file' : 'the state file does not belong to this dev-site database (its token is missing or differs)' )
			. ', but the dev site holds ' . wp_json_encode( array_filter( $counts ) ) . '. Not guessing what to remove: check what it is and remove it by hand',
		1
	);
}

$failures = [];

switch ( $opts['step'] ?? '' ) {
	case 'data':
		$user_id = is_int( $state['user_id'] ?? null ) ? $state['user_id'] : 0;

		foreach ( array_filter( (array) ( $state['order_ids'] ?? [] ), 'is_int' ) as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( false === $order ) {
				continue;
			}

			if ( 'yes' !== $order->get_meta( CBJP_AM_MARKER_META ) || $order->get_customer_id() !== $user_id ) {
				$failures[] = "order {$order_id} is not the probe order (marker or customer differs); left as is";
				continue;
			}

			if ( ! $order->delete( true ) ) {
				$failures[] = "could not delete order {$order_id}";
			}
		}

		$product_id = is_int( $state['product_id'] ?? null ) ? $state['product_id'] : 0;
		$product    = $product_id > 0 ? wc_get_product( $product_id ) : null;

		if ( $product instanceof WC_Product ) {
			if ( CBJP_AM_PRODUCT_NAME === $product->get_name() && 'yes' === $product->get_meta( CBJP_AM_MARKER_META ) ) {
				if ( ! $product->delete( true ) ) {
					$failures[] = "could not delete product {$product_id}";
				}
			} else {
				$failures[] = "product {$product_id} is not the probe product; left as is";
			}
		}

		// 製品の保存・削除で WooCommerce が積んだ属性の索引の更新の予定。製品に属性は無いので索引に行は無く、走らせても何もしない。
		// その ID の投稿がもう無い（このスキルの製品を消した・前の teardown で消した）ときだけ消す。別物の製品が残っていれば触らない（R1-1）。
		// 索引に行が残っていれば（このスキルの製品ではありえない）、予定は消さずに残りとして報告する。
		if ( $product_id > 0 && null === get_post( $product_id ) ) {
			$lookup_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_or_parent_id = %d", $product_id ) );

			if ( 0 !== $lookup_rows ) {
				$failures[] = "the attributes lookup still has {$lookup_rows} row(s) for product {$product_id}; its scheduled updates were kept";
			} else {
				foreach ( cbjp_am_delete_actions( cbjp_am_lookup_action_ids( $product_id ) ) as $action_id ) {
					$failures[] = "could not delete the scheduled action {$action_id}";
				}
			}
		}

		foreach ( array_filter( (array) ( $state['page_ids'] ?? [] ), 'is_int' ) as $page_id ) {
			if ( null === get_post( $page_id ) ) {
				continue;
			}

			if ( 'yes' !== get_post_meta( $page_id, CBJP_AM_MARKER_META, true ) ) {
				$failures[] = "page {$page_id} is not a probe page; left as is";
			} elseif ( ! wp_delete_post( $page_id, true ) ) {
				$failures[] = "could not delete page {$page_id}";
			}
		}

		if ( $user_id > 0 && false !== get_userdata( $user_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';

			if ( CBJP_AM_BUYER_LOGIN !== get_userdata( $user_id )->user_login ) {
				$failures[] = "user {$user_id} is not the probe customer; left as is";
			} elseif ( ! wp_delete_user( $user_id ) ) {
				$failures[] = "could not delete user {$user_id}";
			}
		}

		// Pro のキーの記録（E2E で有効化したもの。preflight で無いことを確かめてから始めた）・要求の記録・手元の API Manager の package が入った更新の情報。
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'cbjp_pro_license_' ) . '%' ) ) as $option ) {
			delete_option( $option );
		}

		delete_option( CBJP_AM_REQUEST_LOG );
		delete_site_transient( 'update_plugins' );

		$register   = cbjp_am_approved_register();
		$dirs_table = $wpdb->prefix . 'wc_product_download_directories';

		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT url_id FROM {$dirs_table} WHERE url LIKE %s", '%' . $wpdb->esc_like( '/cbjp-am/' ) . '%' ) ) as $dir_id ) {
			$register->delete_by_id( (int) $dir_id );
		}

		if ( is_string( $state['approved_mode'] ?? null ) && $register->get_mode() !== $state['approved_mode'] ) {
			$register->set_mode( $state['approved_mode'] );
		}

		// アップロードは setup が置いた zip だけを消す。ほかのファイルがあれば消さずに残りとして報告する。
		$dir = wp_upload_dir()['basedir'] . '/' . CBJP_AM_UPLOAD_SUBDIR;

		if ( is_dir( $dir ) ) {
			$placed = is_string( $state['pro_zip'] ?? null ) ? $dir . '/' . basename( $state['pro_zip'] ) : '';

			if ( '' !== $placed && is_file( $placed ) && ! unlink( $placed ) ) {
				$failures[] = "could not delete {$placed}";
			}

			$rest = array_diff( (array) scandir( $dir ), [ '.', '..' ] );

			if ( [] !== $rest ) {
				$failures[] = "{$dir} holds files this skill did not place: " . implode( ', ', $rest );
			} elseif ( ! rmdir( $dir ) ) {
				$failures[] = "could not remove {$dir}";
			}
		}

		break;

	case 'am':
		if ( function_exists( 'WCAM' ) || is_dir( WP_PLUGIN_DIR . '/' . CBJP_AM_PLUGIN_SLUG ) ) {
			cbjp_am_abort( 'deactivate and delete API Manager first (am.sh teardown does it)', 1 );
		}

		foreach ( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'wc_am_' ) . '%' ) ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// 数える範囲（cbjp_am_leftovers()）と同じ関数で消す。
		[ $option_where, $option_values ] = cbjp_am_am_option_where();
		[ $meta_where, $meta_values ]     = cbjp_am_am_usermeta_where();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE {$option_where}", $option_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE {$meta_where}", $meta_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( cbjp_am_am_cron_hooks() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		foreach ( cbjp_am_delete_actions( cbjp_am_am_action_ids() ) as $action_id ) {
			$failures[] = "could not delete the scheduled action {$action_id}";
		}

		flush_rewrite_rules();
		wp_cache_flush();

		$counts = cbjp_am_leftovers( $state );
		unset( $counts['mu_plugin'], $counts['probe_token'] );
		echo 'leftovers: ', wp_json_encode( $counts ), "\n";

		if ( 0 !== array_sum( $counts ) ) {
			$failures[] = 'the dev site still holds ' . wp_json_encode( array_filter( $counts ) );
		} elseif ( ! delete_option( CBJP_AM_TOKEN_OPTION ) ) {
			// トークンはほかがすべて消えた後に消す（途中で止まったときに、次の teardown が同じ状態ファイルで続きを片付けられるように）。
			$failures[] = 'could not delete the probe token option';
		}

		break;

	default:
		cbjp_am_abort( 'step=<data|am> is required' );
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "teardown: {$failure}\n" );
	}

	exit( 1 );
}

echo 'teardown ', $opts['step'], ": done\n";
