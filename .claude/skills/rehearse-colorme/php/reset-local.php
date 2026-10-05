<?php
/**
 * 開発サイトの Woo データと cbjp の進行データを消し、「空の Woo への移行」をやり直せる状態に戻す。
 * 引数: mode=preview（件数だけ。既定）| mode=yes（実行）
 *
 * 消すもの: 商品・バリエーション・受注（HPOS でも CRUD 経由）・顧客（role が customer だけ。管理者・ショップ管理者・実行ユーザーは消さない）・
 *   クーポン・商品カテゴリ（既定カテゴリを除く）・商品タグ・取込んだ画像（`_cbjp_source_url` 付きの添付）・
 *   `cbjp_*` テーブル（mapping・ジョブ・ログ・dry-run の明細・push intent）・サンプル選定とレート制限のオプション・
 *   ジョブの pending アクション（`cbjp_process_job`。日次のログ削除などの定期アクションは残す）。
 * 残すもの: ColorMe の接続（`cbjp_token_*`）・マッピング設定（`cbjp_settings_*`）・Export の設定・DB バージョン・WooCommerce の設定。
 *
 * 開発サイト専用: `wp_get_environment_type()` が local かつ home_url の host が localhost のときだけ動く。進行中のジョブがあれば止まる。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$cbjp_opts = cbjp_rh_args( $args, [ 'mode' ] );
$cbjp_mode = $cbjp_opts['mode'] ?? 'preview';

if ( ! in_array( $cbjp_mode, [ 'preview', 'yes' ], true ) ) {
	cbjp_rh_abort( "mode must be preview or yes: '{$cbjp_mode}'" );
}

if ( 'local' !== wp_get_environment_type() || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	cbjp_rh_abort( 'this script only runs on the local wp-env dev site (environment type local, host localhost).' );
}

global $wpdb;

$cbjp_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs WHERE status IN ('pending','running','paused')" );

if ( $cbjp_active > 0 ) {
	cbjp_rh_abort( "{$cbjp_active} job(s) are still pending/running/paused. Cancel the run first." );
}

$cbjp_product_ids    = get_posts(
	[
		'post_type'   => [ 'product', 'product_variation' ],
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	]
);
$cbjp_trash_ids      = get_posts(
	[
		'post_type'   => [ 'product', 'product_variation' ],
		'post_status' => 'trash',
		'numberposts' => -1,
		'fields'      => 'ids',
	]
);
$cbjp_product_ids    = array_values( array_unique( array_merge( $cbjp_product_ids, $cbjp_trash_ids ) ) );
$cbjp_order_ids      = wc_get_orders(
	[
		'limit'  => -1,
		'return' => 'ids',
		'status' => array_merge( array_keys( wc_get_order_statuses() ), [ 'trash', 'auto-draft', 'checkout-draft' ] ),
		'type'   => 'shop_order',
	]
);
$cbjp_current_user   = get_current_user_id();
$cbjp_customer_ids   = array_values(
	array_filter(
		array_map(
			'intval',
			get_users(
				[
					'role'   => 'customer',
					'fields' => 'ID',
				]
			)
		),
		static function ( int $id ) use ( $cbjp_current_user ): bool {
			$user = get_userdata( $id );

			// role が customer だけのユーザーに限る（他の role を併せ持つユーザーは消さない）。
			return $id !== $cbjp_current_user && false !== $user && [ 'customer' ] === array_values( $user->roles );
		}
	)
);
$cbjp_coupon_ids     = get_posts(
	[
		'post_type'   => 'shop_coupon',
		'post_status' => [ 'any', 'trash' ],
		'numberposts' => -1,
		'fields'      => 'ids',
	]
);
$cbjp_default_cat    = (int) get_option( 'default_product_cat', 0 );
$cbjp_cat_ids        = array_values(
	array_diff(
		array_map(
			'intval',
			get_terms(
				[
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'ids',
				]
			)
		),
		[ $cbjp_default_cat ]
	)
);
$cbjp_tag_ids        = array_map(
	'intval',
	get_terms(
		[
			'taxonomy'   => 'product_tag',
			'hide_empty' => false,
			'fields'     => 'ids',
		]
	)
);
$cbjp_attachment_ids = get_posts(
	[
		'post_type'   => 'attachment',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
		'meta_key'    => '_cbjp_source_url',
	]
); // phpcs:ignore WordPress.DB.SlowDBQuery
$cbjp_tables         = [ 'cbjp_dry_run_items', 'cbjp_logs', 'cbjp_jobs', 'cbjp_mappings', 'cbjp_push_intents' ];
$cbjp_options        = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP '^cbjp_(sample|export_sample|rate_limit)_'" );
$cbjp_actions        = as_get_scheduled_actions(
	[
		'hook'     => 'cbjp_process_job',
		'group'    => 'cart-bridge-jp',
		'status'   => 'pending',
		'per_page' => -1,
	],
	'ids'
);

echo "== reset-local ({$cbjp_mode}) ==\n";
echo '  products + variations: ' . count( $cbjp_product_ids ) . "\n";
echo '  orders: ' . count( $cbjp_order_ids ) . "\n";
echo '  customers (role customer only): ' . count( $cbjp_customer_ids ) . "\n";
echo '  coupons: ' . count( $cbjp_coupon_ids ) . "\n";
echo '  product_cat (except default): ' . count( $cbjp_cat_ids ) . ' | product_tag: ' . count( $cbjp_tag_ids ) . "\n";
echo '  imported attachments: ' . count( $cbjp_attachment_ids ) . "\n";
foreach ( $cbjp_tables as $cbjp_table ) {
	echo "  {$cbjp_table}: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$cbjp_table}" ) . " rows\n";
}
echo '  options: ' . ( [] === $cbjp_options ? '(none)' : implode( ' ', $cbjp_options ) ) . "\n";
echo '  pending cbjp_process_job actions: ' . count( $cbjp_actions ) . "\n";

if ( 'yes' !== $cbjp_mode ) {
	echo "preview only — run with mode=yes to delete.\n";

	return;
}

$cbjp_failed = 0;

foreach ( $cbjp_order_ids as $cbjp_id ) {
	$cbjp_order = wc_get_order( $cbjp_id );

	if ( ! $cbjp_order || ! $cbjp_order->delete( true ) ) {
		echo "  failed to delete order {$cbjp_id}\n";
		++$cbjp_failed;
	}
}

foreach ( $cbjp_product_ids as $cbjp_id ) {
	// variable 商品の削除で子のバリエーションも消えるので、先に消えたものは飛ばす。
	if ( null === get_post( $cbjp_id ) ) {
		continue;
	}

	$cbjp_product = wc_get_product( $cbjp_id );
	$cbjp_ok      = $cbjp_product ? $cbjp_product->delete( true ) : (bool) wp_delete_post( $cbjp_id, true );

	if ( ! $cbjp_ok ) {
		echo "  failed to delete product {$cbjp_id}\n";
		++$cbjp_failed;
	}
}

foreach ( $cbjp_coupon_ids as $cbjp_id ) {
	if ( ! wp_delete_post( $cbjp_id, true ) ) {
		echo "  failed to delete coupon {$cbjp_id}\n";
		++$cbjp_failed;
	}
}

foreach ( $cbjp_customer_ids as $cbjp_id ) {
	if ( ! wp_delete_user( $cbjp_id ) ) {
		echo "  failed to delete user {$cbjp_id}\n";
		++$cbjp_failed;
	}
}

foreach ( $cbjp_attachment_ids as $cbjp_id ) {
	if ( ! wp_delete_attachment( $cbjp_id, true ) ) {
		echo "  failed to delete attachment {$cbjp_id}\n";
		++$cbjp_failed;
	}
}

foreach ( [
	'product_cat' => $cbjp_cat_ids,
	'product_tag' => $cbjp_tag_ids,
] as $cbjp_taxonomy => $cbjp_ids ) {
	foreach ( $cbjp_ids as $cbjp_id ) {
		$cbjp_result = wp_delete_term( $cbjp_id, $cbjp_taxonomy );

		if ( true !== $cbjp_result ) {
			echo "  failed to delete {$cbjp_taxonomy} {$cbjp_id}\n";
			++$cbjp_failed;
		}
	}
}

foreach ( $cbjp_actions as $cbjp_action_id ) {
	ActionScheduler::store()->cancel_action( (int) $cbjp_action_id );
}

// 子の行（明細・ログ）を親（ジョブ）より先に消す（`.claude/rules/skill-scripts.md`）。
foreach ( $cbjp_tables as $cbjp_table ) {
	if ( false === $wpdb->query( "DELETE FROM {$wpdb->prefix}{$cbjp_table}" ) ) {
		echo "  failed to empty {$cbjp_table}: {$wpdb->last_error}\n";
		++$cbjp_failed;
	}
}

foreach ( $cbjp_options as $cbjp_option ) {
	delete_option( $cbjp_option );
	wp_cache_delete( $cbjp_option, 'options' );

	// 消えたことを読み直して確かめる。サンプルやレート制限の状態が残ると、次のリハーサルが古いサンプルで進む（G1-7）。
	if ( false !== get_option( $cbjp_option, false ) ) {
		echo "  failed to delete option {$cbjp_option}\n";
		++$cbjp_failed;
	}
}

wc_delete_product_transients();
wp_cache_flush();

echo 0 === $cbjp_failed ? "done.\n" : "done with {$cbjp_failed} failure(s).\n";

if ( $cbjp_failed > 0 ) {
	exit( 1 );
}
