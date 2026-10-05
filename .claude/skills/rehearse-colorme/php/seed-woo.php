<?php
/**
 * 開発サイトの Woo に、ColorMe 由来ではない（`_cbjp_*` メタの無い）商品・顧客を作る（名前は `ZZW-`、メールは `zzw-…@example.com`）。
 * 作成エクスポート（Woo → ColorMe の新規作成）で、値がどう送られるかを確かめるため（SKILL.md 手順 3）。
 * 引数: [extra-email=<address>]（任意。add_member の通知メールの有無〔要検証#17〕を確かめるために、ユーザーが受信できるアドレスで顧客を 1 件足す。
 *   このアドレスは repo・記録に書かない）
 *
 * - 単純（SKU なし）、可変 1 軸（1 バリエーションだけセール。`option_market_price` の税基準の確認用）、可変 2 軸（2 軸目のオプション直後に
 *   バリエーションが出そろわない実測〔rehearsal.md〕がエクスポートでも起きるか）、軽減税率、`zero-rate`（hidden 安全策〔issue #78〕の発動条件）。
 * - 顧客: 日本の住所の顧客（名・姓を Woo の欄に入れる。`_cbjp_full_name` が無いので「名 姓」の順で送られる既知の制限を見る）。
 * - 同じ名前・メールが既にあれば作らずに飛ばす。開発サイト専用（`reset-local` と同じ条件）。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts = cbjp_rh_args( $args, [ 'extra-email' ] );

if ( 'local' !== wp_get_environment_type() || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	cbjp_rh_abort( 'this script only runs on the local wp-env dev site (environment type local, host localhost).' );
}

$cbjp_category = get_term_by( 'name', 'ZZR-食品', 'product_cat' );
$cbjp_cat_ids  = $cbjp_category instanceof WP_Term ? [ (int) $cbjp_category->term_id ] : [];

$cbjp_exists = static function ( string $name ): ?int {
	$ids = get_posts( [ 'post_type' => 'product', 'post_status' => 'any', 'title' => $name, 'numberposts' => 1, 'fields' => 'ids' ] );

	return [] === $ids ? null : (int) $ids[0];
};

$cbjp_simple = static function ( string $name, string $price, string $tax_class, ?int $stock ) use ( $cbjp_exists, $cbjp_cat_ids ): void {
	if ( null !== $cbjp_exists( $name ) ) {
		echo "  skip {$name} (already exists)\n";
		return;
	}

	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( $price );
	$product->set_tax_class( $tax_class );
	$product->set_category_ids( $cbjp_cat_ids );
	$product->set_manage_stock( null !== $stock );
	$product->set_stock_quantity( $stock );
	$product->set_description( '<p>Woo で作った商品（リハーサル）。</p>' );
	$id = $product->save();

	// その税区分が店舗に無いと `set_tax_class()` は黙って標準（''）にする。軽減・ゼロ税率の商品が標準税率で作られると、
	// 作成エクスポートの確認（hidden 安全策・軽減税率の換算）が別の条件を見てしまうので止める。
	if ( wc_get_product( $id )->get_tax_class( 'edit' ) !== $tax_class ) {
		cbjp_rh_abort( "the tax class '{$tax_class}' does not exist on this site; {$name} (#{$id}) was saved with the standard class. Create the class (or delete the product) and retry." );
	}

	echo "  ok {$id} {$name}\n";
};

/**
 * @param array<string,array<int,string>> $axes 軸名 => 値。
 * @param array<string,array{regular:string,sale?:string,stock?:int,sku?:string}> $variations "値" または "値1/値2" => 内容。
 */
$cbjp_variable = static function ( string $name, array $axes, array $variations ) use ( $cbjp_exists, $cbjp_cat_ids ): void {
	if ( null !== $cbjp_exists( $name ) ) {
		echo "  skip {$name} (already exists)\n";
		return;
	}

	$product    = new WC_Product_Variable();
	$attributes = [];

	foreach ( $axes as $axis => $values ) {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( $axis );
		$attribute->set_options( $values );
		$attribute->set_visible( true );
		$attribute->set_variation( true );
		$attributes[] = $attribute;
	}

	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_attributes( $attributes );
	$product->set_category_ids( $cbjp_cat_ids );
	$id = $product->save();

	foreach ( $variations as $key => $def ) {
		$values    = explode( '/', $key );
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $id );
		$variation->set_attributes( array_combine( array_map( 'sanitize_title', array_keys( $axes ) ), $values ) );
		$variation->set_regular_price( $def['regular'] );
		$variation->set_sale_price( $def['sale'] ?? '' );
		$variation->set_manage_stock( isset( $def['stock'] ) );
		$variation->set_stock_quantity( $def['stock'] ?? null );
		$variation->set_sku( $def['sku'] ?? '' );
		$variation->set_status( 'publish' );
		$variation->save();
	}

	WC_Product_Variable::sync( $id );
	echo "  ok {$id} {$name}\n";
};

echo "== products ==\n";
$cbjp_simple( 'ZZW-1 simple without SKU', '1980', '', 5 );
$cbjp_simple( 'ZZW-2 reduced rate', '1080', 'reduced-rate', null );
$cbjp_simple( 'ZZW-3 zero rate class', '500', 'zero-rate', null );
$cbjp_variable(
	'ZZW-4 one axis with a sale variation',
	[ 'サイズ' => [ 'S', 'M' ] ],
	[
		'S' => [ 'regular' => '2200', 'stock' => 3, 'sku' => 'ZZW-4-S' ],
		'M' => [ 'regular' => '3300', 'sale' => '2750', 'stock' => 4, 'sku' => 'ZZW-4-M' ],
	]
);
$cbjp_variable(
	'ZZW-5 two axes',
	[
		'カラー' => [ '赤', '青' ],
		'サイズ' => [ 'S', 'M' ],
	],
	[
		'赤/S' => [ 'regular' => '1100', 'stock' => 1, 'sku' => 'ZZW-5-RS' ],
		'赤/M' => [ 'regular' => '1210', 'stock' => 2, 'sku' => 'ZZW-5-RM' ],
		'青/S' => [ 'regular' => '1100', 'stock' => 3, 'sku' => 'ZZW-5-BS' ],
		'青/M' => [ 'regular' => '1210', 'stock' => 4, 'sku' => 'ZZW-5-BM' ],
	]
);

echo "== customers ==\n";
$cbjp_customers = [
	'zzw-c1@example.com' => [ '花子', 'ZZW-渡辺', 'JP27', '5300001', '大阪市北区1-1', '06-0000-1001' ],
];

if ( isset( $cbjp_opts['extra-email'] ) ) {
	if ( ! is_email( $cbjp_opts['extra-email'] ) ) {
		cbjp_rh_abort( 'extra-email is not a valid email address' );
	}

	$cbjp_customers[ $cbjp_opts['extra-email'] ] = [ '通知', 'ZZW-確認', 'JP13', '1000001', '千代田区千代田1-1', '03-0000-1002' ];
}

foreach ( $cbjp_customers as $cbjp_email => [ $cbjp_first, $cbjp_last, $cbjp_state, $cbjp_postcode, $cbjp_address, $cbjp_phone ] ) {
	if ( email_exists( $cbjp_email ) ) {
		echo "  skip {$cbjp_last} {$cbjp_first} (already exists)\n";
		continue;
	}

	$cbjp_customer = new WC_Customer();
	$cbjp_customer->set_email( $cbjp_email );
	$cbjp_customer->set_username( sanitize_user( strtok( $cbjp_email, '@' ) . '-' . wp_generate_password( 6, false ) ) );
	$cbjp_customer->set_password( wp_generate_password( 24 ) );
	$cbjp_customer->set_first_name( $cbjp_first );
	$cbjp_customer->set_last_name( $cbjp_last );
	$cbjp_customer->set_billing_first_name( $cbjp_first );
	$cbjp_customer->set_billing_last_name( $cbjp_last );
	$cbjp_customer->set_billing_country( 'JP' );
	$cbjp_customer->set_billing_state( $cbjp_state );
	$cbjp_customer->set_billing_postcode( $cbjp_postcode );
	$cbjp_customer->set_billing_address_1( $cbjp_address );
	$cbjp_customer->set_billing_phone( $cbjp_phone );
	$cbjp_customer->set_billing_email( $cbjp_email );
	$cbjp_id = $cbjp_customer->save();
	echo "  ok {$cbjp_id} {$cbjp_last} {$cbjp_first}\n";
}
