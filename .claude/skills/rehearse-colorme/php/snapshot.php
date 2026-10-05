<?php
/**
 * ColorMe と Woo の現状を `.rehearsal/<label>.json` に保存する。読み取りのみ。
 * 引数: shop=<login_id> label=<name> [side=both|colorme|woo]
 *
 * - colorme: 商品（一覧の行はオプション・バリエーションを含む詳細と同じ項目）・会員・クーポン・受注（2000-01-01 以降）
 * - woo: 商品・バリエーション・顧客（role customer）・受注・クーポンの、往復で比べる項目と `_cbjp_*` メタ、`cbjp_mappings`、マッピング設定
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts  = cbjp_rh_args( $args, [ 'shop', 'label', 'side' ] );
$cbjp_shop  = cbjp_rh_require_shop( $cbjp_opts );
$cbjp_label = cbjp_rh_label( $cbjp_opts['label'] ?? '' );
$cbjp_side  = $cbjp_opts['side'] ?? 'both';

if ( ! in_array( $cbjp_side, [ 'both', 'colorme', 'woo' ], true ) ) {
	cbjp_rh_abort( 'side must be both, colorme or woo' );
}

$cbjp_snapshot = [
	'label'    => $cbjp_label,
	'taken_at' => gmdate( 'c' ),
	'shop'     => array_intersect_key( $cbjp_shop, array_flip( [ 'tax_type', 'tax', 'reduce_tax_rate', 'tax_rounding_method', 'contract_plan' ] ) ),
];

$cbjp_key_by_id = static function ( array $rows ): array {
	$out = [];

	foreach ( $rows as $row ) {
		$out[ (string) ( $row['id'] ?? '' ) ] = $row;
	}

	ksort( $out );

	return $out;
};

if ( 'woo' !== $cbjp_side ) {
	$cbjp_client              = cbjp_rh_client();
	$cbjp_snapshot['colorme'] = [
		'products'  => $cbjp_key_by_id( cbjp_rh_get_all( $cbjp_client, 'products.json', 'products' ) ),
		'customers' => $cbjp_key_by_id( cbjp_rh_get_all( $cbjp_client, 'customers.json', 'customers' ) ),
		'coupons'   => $cbjp_key_by_id( (array) ( $cbjp_client->get( 'shop_coupons.json' )['shop_coupons'] ?? [] ) ),
		'sales'     => $cbjp_key_by_id( cbjp_rh_get_all( $cbjp_client, 'sales.json', 'sales', [ 'after' => '2000-01-01' ] ) ),
	];
}

if ( 'colorme' !== $cbjp_side ) {
	global $wpdb;

	$cbjp_meta = static function ( int $id, string $type ): array {
		$all = 'user' === $type ? get_user_meta( $id ) : get_post_meta( $id );
		$out = [];

		foreach ( is_array( $all ) ? $all : [] as $key => $values ) {
			if ( str_starts_with( (string) $key, '_cbjp_' ) ) {
				$out[ $key ] = maybe_unserialize( $values[0] ?? null );
			}
		}

		ksort( $out );

		return $out;
	};

	$cbjp_product_row = static function ( WC_Product $p ) use ( $cbjp_meta ): array {
		$attributes = [];

		foreach ( $p->get_attributes( 'edit' ) as $name => $attribute ) {
			$attributes[ $name ] = is_object( $attribute ) && method_exists( $attribute, 'get_options' ) ? $attribute->get_options() : $attribute;
		}

		return [
			'id'                 => $p->get_id(),
			'parent_id'          => $p->get_parent_id( 'edit' ),
			'type'               => $p->get_type(),
			'name'               => $p->get_name( 'edit' ),
			'status'             => $p->get_status( 'edit' ),
			'catalog_visibility' => $p->get_catalog_visibility( 'edit' ),
			'sku'                => $p->get_sku( 'edit' ),
			'regular_price'      => $p->get_regular_price( 'edit' ),
			'sale_price'         => $p->get_sale_price( 'edit' ),
			'tax_class'          => $p->get_tax_class( 'edit' ),
			'tax_status'         => $p->get_tax_status( 'edit' ),
			'manage_stock'       => $p->get_manage_stock( 'edit' ),
			'stock_quantity'     => $p->get_stock_quantity( 'edit' ),
			'stock_status'       => $p->get_stock_status( 'edit' ),
			'low_stock_amount'   => $p->get_low_stock_amount( 'edit' ),
			'weight'             => $p->get_weight( 'edit' ),
			'virtual'            => $p->get_virtual( 'edit' ),
			'menu_order'         => $p->get_menu_order( 'edit' ),
			'description'        => $p->get_description( 'edit' ),
			'short_description'  => $p->get_short_description( 'edit' ),
			'categories'         => wp_get_post_terms( $p->get_id(), 'product_cat', [ 'fields' => 'names' ] ),
			'tags'               => wp_get_post_terms( $p->get_id(), 'product_tag', [ 'fields' => 'names' ] ),
			'attributes'         => $attributes,
			'image_id'           => $p->get_image_id( 'edit' ),
			'gallery_image_ids'  => $p->get_gallery_image_ids( 'edit' ),
			'meta'               => $cbjp_meta( $p->get_id(), 'post' ),
		];
	};

	$cbjp_products = [];
	foreach ( wc_get_products(
		[
			'limit'   => -1,
			'status'  => [ 'publish', 'private', 'draft', 'pending' ],
			'orderby' => 'ID',
			'order'   => 'ASC',
		]
	) as $cbjp_product ) {
		$cbjp_products[ (string) $cbjp_product->get_id() ] = $cbjp_product_row( $cbjp_product );

		if ( $cbjp_product instanceof WC_Product_Variable ) {
			foreach ( $cbjp_product->get_children() as $cbjp_child_id ) {
				$cbjp_child = wc_get_product( $cbjp_child_id );

				if ( $cbjp_child ) {
					$cbjp_products[ (string) $cbjp_child_id ] = $cbjp_product_row( $cbjp_child );
				}
			}
		}
	}

	$cbjp_customers = [];
	foreach ( get_users(
		[
			'role'    => 'customer',
			'orderby' => 'ID',
		]
	) as $cbjp_user ) {
		$cbjp_customer                             = new WC_Customer( $cbjp_user->ID );
		$cbjp_customers[ (string) $cbjp_user->ID ] = [
			'id'           => $cbjp_user->ID,
			'email'        => $cbjp_user->user_email,
			'display_name' => $cbjp_user->display_name,
			'first_name'   => $cbjp_customer->get_first_name( 'edit' ),
			'last_name'    => $cbjp_customer->get_last_name( 'edit' ),
			'billing'      => $cbjp_customer->get_billing( 'edit' ),
			'shipping'     => $cbjp_customer->get_shipping( 'edit' ),
			'meta'         => $cbjp_meta( $cbjp_user->ID, 'user' ),
		];
	}

	$cbjp_orders = [];
	foreach ( wc_get_orders(
		[
			'limit'   => -1,
			'status'  => 'any',
			'type'    => 'shop_order',
			'orderby' => 'ID',
			'order'   => 'ASC',
		]
	) as $cbjp_order ) {
		$cbjp_items = [];
		foreach ( $cbjp_order->get_items( [ 'line_item', 'shipping', 'fee', 'coupon' ] ) as $cbjp_item ) {
			$cbjp_items[] = [
				'type'       => $cbjp_item->get_type(),
				'name'       => $cbjp_item->get_name(),
				'qty'        => $cbjp_item->get_quantity(),
				'total'      => $cbjp_item->get_total(),
				'total_tax'  => $cbjp_item->get_total_tax(),
				'product_id' => $cbjp_item instanceof WC_Order_Item_Product ? $cbjp_item->get_product_id() : null,
				'variation'  => $cbjp_item instanceof WC_Order_Item_Product ? $cbjp_item->get_variation_id() : null,
				'method'     => $cbjp_item instanceof WC_Order_Item_Shipping ? $cbjp_item->get_method_id() . ':' . $cbjp_item->get_instance_id() : null,
			];
		}
		$cbjp_orders[ (string) $cbjp_order->get_id() ] = [
			'id'             => $cbjp_order->get_id(),
			'status'         => $cbjp_order->get_status(),
			'customer_id'    => $cbjp_order->get_customer_id(),
			'total'          => $cbjp_order->get_total(),
			'total_tax'      => $cbjp_order->get_total_tax(),
			'shipping_total' => $cbjp_order->get_shipping_total(),
			'discount_total' => $cbjp_order->get_discount_total(),
			'payment_method' => $cbjp_order->get_payment_method(),
			'date_created'   => $cbjp_order->get_date_created() ? $cbjp_order->get_date_created()->format( 'c' ) : null,
			'billing'        => $cbjp_order->get_address( 'billing' ),
			'shipping'       => $cbjp_order->get_address( 'shipping' ),
			'items'          => $cbjp_items,
		];
		$cbjp_order_meta                               = [];
		foreach ( $cbjp_order->get_meta_data() as $cbjp_m ) {
			if ( str_starts_with( (string) $cbjp_m->key, '_cbjp_' ) ) {
				$cbjp_order_meta[ $cbjp_m->key ] = $cbjp_m->value;
			}
		}
		ksort( $cbjp_order_meta );
		$cbjp_orders[ (string) $cbjp_order->get_id() ]['meta'] = $cbjp_order_meta;
	}

	$cbjp_coupons = [];
	foreach ( get_posts(
		[
			'post_type'   => 'shop_coupon',
			'post_status' => 'any',
			'numberposts' => -1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		]
	) as $cbjp_post ) {
		$cbjp_coupon                             = new WC_Coupon( $cbjp_post->ID );
		$cbjp_coupons[ (string) $cbjp_post->ID ] = [
			'id'                   => $cbjp_post->ID,
			'code'                 => $cbjp_coupon->get_code( 'edit' ),
			'status'               => $cbjp_post->post_status,
			'discount_type'        => $cbjp_coupon->get_discount_type( 'edit' ),
			'amount'               => $cbjp_coupon->get_amount( 'edit' ),
			'minimum_amount'       => $cbjp_coupon->get_minimum_amount( 'edit' ),
			'date_expires'         => $cbjp_coupon->get_date_expires( 'edit' ) ? $cbjp_coupon->get_date_expires( 'edit' )->format( 'c' ) : null,
			'usage_limit'          => $cbjp_coupon->get_usage_limit( 'edit' ),
			'usage_limit_per_user' => $cbjp_coupon->get_usage_limit_per_user( 'edit' ),
			'meta'                 => $cbjp_meta( $cbjp_post->ID, 'post' ),
		];
	}

	$cbjp_snapshot['woo'] = [
		'products'  => $cbjp_products,
		'customers' => $cbjp_customers,
		'orders'    => $cbjp_orders,
		'coupons'   => $cbjp_coupons,
		'mappings'  => $wpdb->get_results( "SELECT platform, entity_type, remote_id, local_id, checksum IS NULL AS checksum_null FROM {$wpdb->prefix}cbjp_mappings ORDER BY platform, entity_type, remote_id", ARRAY_A ),
		// 決済・配送などのマッピング設定（`check-import` が受注の決済・配送の対応を確かめる）。配列でない壊れた値は空として扱う。
		'settings'  => is_array( get_option( 'cbjp_settings_colorme', [] ) ) ? get_option( 'cbjp_settings_colorme', [] ) : [],
	];
}

$cbjp_path = cbjp_rh_out_dir() . "/{$cbjp_label}.json";
cbjp_rh_write_json( $cbjp_path, $cbjp_snapshot );

echo "saved .rehearsal/{$cbjp_label}.json\n";
foreach ( [ 'colorme', 'woo' ] as $cbjp_s ) {
	foreach ( (array) ( $cbjp_snapshot[ $cbjp_s ] ?? [] ) as $cbjp_k => $cbjp_rows ) {
		echo "  {$cbjp_s}.{$cbjp_k}: " . count( (array) $cbjp_rows ) . "\n";
	}
}
