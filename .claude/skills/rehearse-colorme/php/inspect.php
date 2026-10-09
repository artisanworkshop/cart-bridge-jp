<?php
/**
 * 店舗（税設定・プラン・件数）とローカル（mapping・ジョブ・Woo の実体・`cbjp_*` オプション）の現状を出す。読み取りのみ。
 * 引数: shop=<login_id>
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts   = cbjp_rh_args( $args, [ 'shop' ] );
$cbjp_shop   = cbjp_rh_require_shop( $cbjp_opts );
$cbjp_client = cbjp_rh_client();

echo "== ColorMe shop ==\n";
foreach ( [ 'login_id', 'contract_plan', 'tax_type', 'tax', 'reduce_tax_rate', 'tax_rounding_method' ] as $cbjp_key ) {
	echo "  {$cbjp_key}: " . wp_json_encode( $cbjp_shop[ $cbjp_key ] ?? null ) . "\n";
}

$cbjp_counts = [
	'products'  => [ 'products.json', 'products', [] ],
	'customers' => [ 'customers.json', 'customers', [] ],
	'sales'     => [ 'sales.json', 'sales', [ 'after' => '2000-01-01' ] ],
];
foreach ( $cbjp_counts as $cbjp_label => [ $cbjp_path, $cbjp_key, $cbjp_query ] ) {
	$cbjp_body = $cbjp_client->get( $cbjp_path, array_merge( $cbjp_query, [ 'limit' => 1 ] ) );
	echo "  {$cbjp_label}: " . wp_json_encode( $cbjp_body['meta']['total'] ?? null ) . "\n";
}
foreach ( [
	'categories.json'   => 'categories',
	'groups.json'       => 'groups',
	'shop_coupons.json' => 'shop_coupons',
	'payments.json'     => 'payments',
	'deliveries.json'   => 'deliveries',
] as $cbjp_path => $cbjp_key ) {
	$cbjp_rows = $cbjp_client->get( $cbjp_path )[ $cbjp_key ] ?? [];
	$cbjp_rows = is_array( $cbjp_rows ) ? $cbjp_rows : [];
	echo "  {$cbjp_key}: " . count( $cbjp_rows ) . "\n";
	foreach ( $cbjp_rows as $cbjp_row ) {
		$cbjp_row = is_array( $cbjp_row ) ? $cbjp_row : [];
		$cbjp_id  = $cbjp_row['id'] ?? ( $cbjp_row['id_big'] ?? '?' );
		echo "    - {$cbjp_id} " . ( $cbjp_row['name'] ?? $cbjp_row['code'] ?? '' );
		foreach ( is_array( $cbjp_row['children'] ?? null ) ? $cbjp_row['children'] : [] as $cbjp_child ) {
			echo ' / ' . ( $cbjp_child['id_small'] ?? '?' ) . ' ' . ( $cbjp_child['name'] ?? '' );
		}
		echo "\n";
	}
}

global $wpdb;
echo "== local (dev site) ==\n";
echo '  environment: ' . wp_get_environment_type() . ' / ' . home_url() . "\n";
echo '  woo tax: calc_taxes=' . get_option( 'woocommerce_calc_taxes' ) . ' prices_include_tax=' . get_option( 'woocommerce_prices_include_tax' )
	. ' rates=' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates" )
	. ' classes=' . implode( ',', array_map( 'rawurldecode', WC_Tax::get_tax_class_slugs() ) ) . "\n";
// D26: 税区分ごとの JP の税率による分類（プラグインの判定）と、税率の表から直接求めた 8% の税区分（check-import・seed-woo の期待値）。
echo '  woo tax classes (plugin classification): ' . implode(
	', ',
	array_map(
		static fn ( string $slug ): string => ( '' === $slug ? "''" : rawurldecode( $slug ) ) . '=' . CartBridgeJP\Woo\Support\TaxClass::classify( $slug ),
		array_merge( [ '' ], WC_Tax::get_tax_class_slugs() )
	)
) . "\n";
echo '  classes with the JP 8% rate (tax rate table): ' . implode( ', ', array_map( 'rawurldecode', cbjp_rh_jp_reduced_classes( cbjp_rh_tax_setup() ) ?? [ '(cannot be reproduced)' ] ) ) . "\n";
echo "  mappings by platform/entity:\n";
foreach ( $wpdb->get_results( "SELECT platform, entity_type, COUNT(*) c, SUM(checksum IS NULL) n FROM {$wpdb->prefix}cbjp_mappings GROUP BY platform, entity_type", ARRAY_A ) as $cbjp_row ) {
	echo "    {$cbjp_row['platform']} / {$cbjp_row['entity_type']}: {$cbjp_row['c']} (checksum null: {$cbjp_row['n']})\n";
}
echo '  jobs: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs" )
	. ' (active: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_jobs WHERE status IN ('pending','running','paused')" ) . ')'
	. ' | push intents: ' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_push_intents" ) . "\n";
echo '  products: ' . count(
	wc_get_products(
		[
			'limit'  => -1,
			'return' => 'ids',
			'status' => [ 'publish', 'private', 'draft', 'pending', 'future' ],
		]
	)
)
	. ' | customers (role customer): ' . count(
		get_users(
			[
				'role'   => 'customer',
				'fields' => 'ID',
			]
		)
	)
	. ' | orders: ' . count(
		wc_get_orders(
			[
				'limit'  => -1,
				'return' => 'ids',
				'status' => 'any',
			]
		)
	)
	. ' | coupons: ' . count(
		get_posts(
			[
				'post_type'   => 'shop_coupon',
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		)
	)
	. ' | product_cat: ' . (int) wp_count_terms(
		[
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		]
	)
	. ' | product_tag: ' . (int) wp_count_terms(
		[
			'taxonomy'   => 'product_tag',
			'hide_empty' => false,
		]
	) . "\n";
echo '  cbjp_* options: ' . implode( ' ', $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'cbjp\\_%' ORDER BY option_name" ) ) . "\n";
