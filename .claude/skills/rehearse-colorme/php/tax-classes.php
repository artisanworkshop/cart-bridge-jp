<?php
/**
 * 開発サイトの WooCommerce の既定の税区分を、日本語でインストールした状態（「軽減税」「免税」。スラッグは URL エンコード）と
 * 英語でインストールした状態（`reduced-rate`・`zero-rate`）で切り替える（issue #102・D26 の確認用。SKILL.md 手順 0）。
 * WooCommerce は既定の税区分を有効化したときの言語の名前から作るので、英語でインストールした開発サイトでは日本語の店舗の状態を作れない。
 * 引数: mode=preview（既定。現状と、移す税率・商品・受注明細の件数だけ）| mode=ja | mode=en
 *
 * 1 つの対（英語 → 日本語、または逆）ごとに、(1) 移し先の税区分を作る（無ければ）、(2) 移し元の税率を移し先へ付け替える
 * （`WC_Tax::_update_tax_rate()`。生の SQL では WooCommerce の税のキャッシュが無効化されない）、(3) 移し元の税区分の商品・バリエーション・
 * 受注明細を CRUD で移し先へ保存し直す（`WC_Tax::delete_tax_class_by()` は商品・明細の `_tax_class` を書き換えず、存在しない税区分は
 * 読込時に黙って標準 `''` になるため、消すだけでは軽減税率の商品がすべて標準に見えて確認にならない）、(4) 移し元の税区分を消す。
 * 移し元が無い対は飛ばす（既にその状態）。最後に、移し元を指す税率・商品・明細が残っていないことを確かめる（残れば終了コード 1）。
 * 開発サイト専用（`reset-local` と同じ条件）。進行中のジョブがあれば止まる。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts = cbjp_rh_args( $args, [ 'mode' ] );
$cbjp_mode = $cbjp_opts['mode'] ?? 'preview';

if ( ! in_array( $cbjp_mode, [ 'preview', 'ja', 'en' ], true ) ) {
	cbjp_rh_abort( "mode must be preview, ja or en: '{$cbjp_mode}'" );
}

if ( 'local' !== wp_get_environment_type() || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	cbjp_rh_abort( 'this script only runs on the local wp-env dev site (environment type local, host localhost).' );
}

global $wpdb;

$cbjp_active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs WHERE status IN ('pending','running','paused')" );

if ( $cbjp_active > 0 ) {
	cbjp_rh_abort( "{$cbjp_active} job(s) are still pending/running/paused. Cancel the run first." );
}

// 既定の税区分の名前（英語 => 日本語）。WooCommerce の日本語訳（`Reduced rate` → 軽減税、`Zero rate` → 免税）。
$cbjp_names = [
	'Reduced rate' => '軽減税',
	'Zero rate'    => '免税',
];

/**
 * 移し元の税区分を指すもの（税率の ID・商品とバリエーションの ID・受注明細の ID）。
 *
 * @return array{rates:array<int,int>,products:array<int,int>,items:array<int,int>}
 */
$cbjp_refs = static function ( string $slug ): array {
	global $wpdb;

	return [
		'rates'    => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s ORDER BY tax_rate_id", $slug ) ) ),
		'products' => array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tax_class' WHERE m.meta_value = %s AND p.post_type IN ('product', 'product_variation') ORDER BY p.ID",
					$slug
				)
			)
		),
		'items'    => array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE meta_key = '_tax_class' AND meta_value = %s ORDER BY order_item_id", $slug ) ) ),
	];
};

echo '== tax classes: ' . implode( ', ', array_map( static fn ( string $slug ): string => '' === $slug ? "''" : rawurldecode( $slug ), WC_Tax::get_tax_class_slugs() ) ) . "\n";

$cbjp_left = 0;

foreach ( $cbjp_names as $cbjp_en => $cbjp_ja ) {
	[ $cbjp_from_name, $cbjp_to_name ] = 'en' === $cbjp_mode ? [ $cbjp_ja, $cbjp_en ] : [ $cbjp_en, $cbjp_ja ];

	if ( 'preview' === $cbjp_mode ) {
		foreach ( [ $cbjp_en, $cbjp_ja ] as $cbjp_name ) {
			$cbjp_slug = sanitize_title( $cbjp_name );
			$cbjp_ref  = $cbjp_refs( $cbjp_slug );
			$cbjp_has  = in_array( $cbjp_slug, WC_Tax::get_tax_class_slugs(), true ) ? 'exists' : 'missing';
			echo "  {$cbjp_name} ({$cbjp_has}): rates=" . count( $cbjp_ref['rates'] ) . ' products=' . count( $cbjp_ref['products'] ) . ' order_items=' . count( $cbjp_ref['items'] ) . "\n";
		}

		continue;
	}

	$cbjp_from = sanitize_title( $cbjp_from_name );
	$cbjp_to   = sanitize_title( $cbjp_to_name );

	if ( ! in_array( $cbjp_from, WC_Tax::get_tax_class_slugs(), true ) ) {
		echo "  skip {$cbjp_from_name}: not on this site\n";
		continue;
	}

	if ( ! in_array( $cbjp_to, WC_Tax::get_tax_class_slugs(), true ) ) {
		$cbjp_created = WC_Tax::create_tax_class( $cbjp_to_name );

		if ( ! is_array( $cbjp_created ) || $cbjp_to !== $cbjp_created['slug'] ) {
			cbjp_rh_abort( "could not create the tax class {$cbjp_to_name}" );
		}
	}

	$cbjp_ref = $cbjp_refs( $cbjp_from );

	foreach ( $cbjp_ref['rates'] as $cbjp_rate_id ) {
		WC_Tax::_update_tax_rate( $cbjp_rate_id, [ 'tax_rate_class' => $cbjp_to ] );
	}

	foreach ( $cbjp_ref['products'] as $cbjp_id ) {
		$cbjp_product = wc_get_product( $cbjp_id );

		if ( ! $cbjp_product instanceof WC_Product ) {
			cbjp_rh_abort( "could not load product #{$cbjp_id}" );
		}

		$cbjp_product->set_tax_class( $cbjp_to );
		$cbjp_product->save();
	}

	foreach ( $cbjp_ref['items'] as $cbjp_item_id ) {
		$cbjp_item = WC_Order_Factory::get_order_item( $cbjp_item_id );

		if ( ! $cbjp_item instanceof WC_Order_Item || ! method_exists( $cbjp_item, 'set_tax_class' ) ) {
			cbjp_rh_abort( "order item #{$cbjp_item_id} has the tax class but cannot be updated" );
		}

		$cbjp_item->set_tax_class( $cbjp_to );
		$cbjp_item->save();
	}

	WC_Tax::delete_tax_class_by( 'slug', $cbjp_from );

	$cbjp_rest = $cbjp_refs( $cbjp_from );
	$cbjp_left_now = count( $cbjp_rest['rates'] ) + count( $cbjp_rest['products'] ) + count( $cbjp_rest['items'] )
		+ ( in_array( $cbjp_from, WC_Tax::get_tax_class_slugs(), true ) ? 1 : 0 );
	$cbjp_left    += $cbjp_left_now;

	echo "  {$cbjp_from_name} → {$cbjp_to_name}: rates=" . count( $cbjp_ref['rates'] ) . ' products=' . count( $cbjp_ref['products'] ) . ' order_items=' . count( $cbjp_ref['items'] )
		. ( 0 === $cbjp_left_now ? '' : " LEFT={$cbjp_left_now}" ) . "\n";
}

if ( 'preview' !== $cbjp_mode ) {
	echo '== tax classes now: ' . implode( ', ', array_map( 'rawurldecode', WC_Tax::get_tax_class_slugs() ) ) . "\n";
	echo '== classes with the JP 8% rate: ' . implode( ', ', array_map( 'rawurldecode', cbjp_rh_jp_reduced_classes( cbjp_rh_tax_rate_rows() ) ) ) . "\n";
}

if ( $cbjp_left > 0 ) {
	fwrite( STDERR, "FAILED: {$cbjp_left} reference(s) to the old classes are left\n" );
	exit( 1 );
}
