<?php
/**
 * 取込み後のスナップショット（side=both）で、ColorMe の値と Woo の値を主要項目ごとに突き合わせる。読み取りのみ。
 * 引数: label=<name>
 *
 * Woo 側は `_cbjp_remote_id` メタ（商品・バリエーション・顧客）で ColorMe の id と結ぶ。
 * - `MISMATCH`: 変換規則（docs/03・`ProductTransformer`）から見て食い違う値。原因を調べる対象。
 * - `NOTE`: 規則どおりだが往復で問題になりうる変換（仮 SKU・在庫 null→0・会員限定の公開状態など）。
 * - `MISSING`: ColorMe にあるのに Woo に無い（会員以外の顧客・取込みが除外したものを含む）。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts = cbjp_rh_args( $args, [ 'label' ] );
$cbjp_snap = cbjp_rh_load_snapshot( $cbjp_opts['label'] ?? '' );

if ( ! is_array( $cbjp_snap['colorme'] ?? null ) || ! is_array( $cbjp_snap['woo'] ?? null ) ) {
	cbjp_rh_abort( 'the snapshot needs both sides (take it with side=both)' );
}

$cbjp_shop      = $cbjp_snap['shop'];
$cbjp_counts    = [
	'MISMATCH' => 0,
	'NOTE'     => 0,
	'MISSING'  => 0,
];
$cbjp_report    = static function ( string $kind, string $where, string $message ) use ( &$cbjp_counts ): void {
	++$cbjp_counts[ $kind ];
	echo "  {$kind} {$where}: {$message}\n";
};
$cbjp_by_remote = static function ( array $rows, callable $filter ): array {
	$out = [];

	foreach ( $rows as $row ) {
		$remote = $row['meta']['_cbjp_remote_id'] ?? null;

		if ( null !== $remote && '' !== $remote && $filter( $row ) ) {
			$out[ (string) $remote ] = $row;
		}
	}

	return $out;
};
// 税抜 → 税込（`round_off` だけを扱う。他の丸めの店舗では null を返し、価格の突合を飛ばす）。
$cbjp_incl = static function ( $net, bool $reduced ) use ( $cbjp_shop ): ?int {
	if ( ! is_int( $net ) || 'excluded' !== ( $cbjp_shop['tax_type'] ?? null ) || 'round_off' !== ( $cbjp_shop['tax_rounding_method'] ?? null ) ) {
		return 'included' === ( $cbjp_shop['tax_type'] ?? null ) && is_int( $net ) ? $net : null;
	}

	$rate = (int) ( $reduced ? $cbjp_shop['reduce_tax_rate'] : $cbjp_shop['tax'] );

	return intdiv( $net * ( 100 + $rate ) + 50, 100 );
};
$cbjp_s    = static fn ( $v ): string => (string) wp_json_encode( $v, JSON_UNESCAPED_UNICODE );

$cbjp_woo_products   = $cbjp_by_remote( $cbjp_snap['woo']['products'], static fn ( array $p ): bool => 'variation' !== $p['type'] );
$cbjp_woo_variations = $cbjp_by_remote( $cbjp_snap['woo']['products'], static fn ( array $p ): bool => 'variation' === $p['type'] );

echo "== products ==\n";
foreach ( $cbjp_snap['colorme']['products'] as $cbjp_id => $cbjp_cm ) {
	$cbjp_where = "product {$cbjp_id} " . $cbjp_s( $cbjp_cm['name'] ?? '' );
	$cbjp_woo   = $cbjp_woo_products[ (string) $cbjp_id ] ?? null;

	if ( null === $cbjp_woo ) {
		$cbjp_report( 'MISSING', $cbjp_where, 'no Woo product with this _cbjp_remote_id' );
		continue;
	}

	if ( $cbjp_woo['name'] !== $cbjp_cm['name'] ) {
		$cbjp_report( 'MISMATCH', $cbjp_where, 'name ' . $cbjp_s( $cbjp_woo['name'] ) );
	}

	$cbjp_model = is_string( $cbjp_cm['model_number'] ?? null ) ? trim( $cbjp_cm['model_number'] ) : '';
	if ( '' === $cbjp_model ) {
		$cbjp_report( 'NOTE', $cbjp_where, 'model_number is blank in ColorMe; Woo SKU = ' . $cbjp_s( $cbjp_woo['sku'] ) . ' (would be sent back as model_number on export)' );
	} elseif ( $cbjp_woo['sku'] !== $cbjp_model ) {
		$cbjp_report( 'MISMATCH', $cbjp_where, 'sku ' . $cbjp_s( $cbjp_woo['sku'] ) . ' vs model_number ' . $cbjp_s( $cbjp_model ) );
	}

	$cbjp_state = $cbjp_cm['display_state'] ?? null;
	$cbjp_want  = in_array( $cbjp_state, [ 'showing', 'sale_for_members' ], true ) ? 'publish' : 'private';
	if ( $cbjp_woo['status'] !== $cbjp_want ) {
		$cbjp_report( 'NOTE', $cbjp_where, "display_state {$cbjp_state} → status {$cbjp_woo['status']} (forced private: sale window / sold out / price / digital)" );
	}
	if ( in_array( $cbjp_state, [ 'sale_for_members', 'showing_for_members' ], true ) ) {
		$cbjp_report( 'NOTE', $cbjp_where, "display_state {$cbjp_state} has no Woo equivalent (status {$cbjp_woo['status']})" );
	}

	$cbjp_reduced = true === ( $cbjp_cm['tax_reduced'] ?? null );
	if ( ( $cbjp_reduced ? 'reduced-rate' : '' ) !== $cbjp_woo['tax_class'] ) {
		$cbjp_report( 'MISMATCH', $cbjp_where, 'tax_class ' . $cbjp_s( $cbjp_woo['tax_class'] ) . ' vs tax_reduced ' . $cbjp_s( $cbjp_cm['tax_reduced'] ?? null ) );
	}

	$cbjp_variants = is_array( $cbjp_cm['variants'] ?? null ) ? $cbjp_cm['variants'] : [];

	if ( [] === $cbjp_variants ) {
		$cbjp_sales_incl = $cbjp_cm['sales_price_including_tax'] ?? null;
		$cbjp_list_incl  = $cbjp_incl( $cbjp_cm['price'] ?? null, $cbjp_reduced );
		$cbjp_on_sale    = null !== $cbjp_list_incl && is_int( $cbjp_sales_incl ) && $cbjp_list_incl > $cbjp_sales_incl;
		$cbjp_want_reg   = $cbjp_on_sale ? $cbjp_list_incl : $cbjp_sales_incl;
		$cbjp_want_sale  = $cbjp_on_sale ? $cbjp_sales_incl : null;

		if ( (string) $cbjp_want_reg !== (string) $cbjp_woo['regular_price'] || (string) $cbjp_want_sale !== (string) $cbjp_woo['sale_price'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_where, "price: Woo regular={$cbjp_woo['regular_price']} sale={$cbjp_woo['sale_price']} vs expected regular={$cbjp_want_reg} sale=" . $cbjp_s( $cbjp_want_sale ) . " (ColorMe price={$cbjp_s( $cbjp_cm['price'] ?? null )} sales_price={$cbjp_s( $cbjp_cm['sales_price'] ?? null )})" );
		}
	}

	if ( true === ( $cbjp_cm['stock_managed'] ?? null ) ) {
		// variable 商品の在庫は Woo ではバリエーション側で管理する（親の manage_stock は false）。下のバリエーションの突合で見る。
		if ( [] === $cbjp_variants && true !== $cbjp_woo['manage_stock'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_where, 'stock_managed but Woo manage_stock=' . $cbjp_s( $cbjp_woo['manage_stock'] ) );
		} elseif ( [] === $cbjp_variants && null === ( $cbjp_cm['stocks'] ?? null ) ) {
			$cbjp_report( 'NOTE', $cbjp_where, 'stocks is null in ColorMe; Woo stock = ' . $cbjp_s( $cbjp_woo['stock_quantity'] ) . ' (would be sent back as 0)' );
		} elseif ( [] === $cbjp_variants && $cbjp_cm['stocks'] !== $cbjp_woo['stock_quantity'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_where, 'stock ' . $cbjp_s( $cbjp_woo['stock_quantity'] ) . ' vs stocks ' . $cbjp_s( $cbjp_cm['stocks'] ) );
		}
	}

	foreach ( [
		'expl'        => 'description',
		'simple_expl' => 'short_description',
	] as $cbjp_cm_key => $cbjp_woo_key ) {
		$cbjp_from = (string) ( $cbjp_cm[ $cbjp_cm_key ] ?? '' );
		if ( $cbjp_from !== (string) $cbjp_woo[ $cbjp_woo_key ] ) {
			$cbjp_report( 'NOTE', $cbjp_where, "{$cbjp_cm_key} changed on import (" . strlen( $cbjp_from ) . ' → ' . strlen( (string) $cbjp_woo[ $cbjp_woo_key ] ) . ' bytes; sanitized)' );
		}
	}

	foreach ( $cbjp_variants as $cbjp_variant ) {
		$cbjp_vid    = (string) ( $cbjp_variant['id'] ?? '' );
		$cbjp_vwhere = "{$cbjp_where} variant {$cbjp_vid} " . $cbjp_s( $cbjp_variant['title'] ?? '' );
		$cbjp_wv     = $cbjp_woo_variations[ $cbjp_vid ] ?? null;

		if ( null === $cbjp_wv ) {
			$cbjp_report( 'MISSING', $cbjp_vwhere, 'no Woo variation with this _cbjp_remote_id' );
			continue;
		}

		$cbjp_vmodel = is_string( $cbjp_variant['model_number'] ?? null ) ? trim( $cbjp_variant['model_number'] ) : '';
		if ( '' === $cbjp_vmodel ) {
			$cbjp_report( 'NOTE', $cbjp_vwhere, 'variant model_number blank; Woo SKU = ' . $cbjp_s( $cbjp_wv['sku'] ) );
		} elseif ( $cbjp_wv['sku'] !== $cbjp_vmodel ) {
			$cbjp_report( 'MISMATCH', $cbjp_vwhere, 'sku ' . $cbjp_s( $cbjp_wv['sku'] ) . ' vs model_number ' . $cbjp_s( $cbjp_vmodel ) );
		}

		if ( (string) ( $cbjp_variant['option_price_including_tax'] ?? '' ) !== (string) $cbjp_wv['regular_price'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_vwhere, "regular_price {$cbjp_wv['regular_price']} vs option_price_including_tax " . $cbjp_s( $cbjp_variant['option_price_including_tax'] ?? null ) );
		}
		if ( null === ( $cbjp_variant['option_price'] ?? null ) ) {
			$cbjp_report( 'NOTE', $cbjp_vwhere, 'option_price is null (inherits the product price); Woo stores ' . $cbjp_s( $cbjp_wv['regular_price'] ) );
		}

		if ( true === ( $cbjp_cm['stock_managed'] ?? null ) ) {
			if ( null === ( $cbjp_variant['stocks'] ?? null ) ) {
				$cbjp_report( 'NOTE', $cbjp_vwhere, 'variant stocks null; Woo stock = ' . $cbjp_s( $cbjp_wv['stock_quantity'] ) );
			} elseif ( $cbjp_variant['stocks'] !== $cbjp_wv['stock_quantity'] ) {
				$cbjp_report( 'MISMATCH', $cbjp_vwhere, 'stock ' . $cbjp_s( $cbjp_wv['stock_quantity'] ) . ' vs stocks ' . $cbjp_s( $cbjp_variant['stocks'] ) );
			}
		}
	}
}

echo "== customers ==\n";
// 県は実装の対応表（`AddressMapper`）を使わず、ColorMe の `pref_name` から JIS X 0401 の番号を引いて確かめる（独立した基準にするため）。
$cbjp_jis           = array_flip(
	[
		1 => '北海道',
		'青森県',
		'岩手県',
		'宮城県',
		'秋田県',
		'山形県',
		'福島県',
		'茨城県',
		'栃木県',
		'群馬県',
		'埼玉県',
		'千葉県',
		'東京都',
		'神奈川県',
		'新潟県',
		'富山県',
		'石川県',
		'福井県',
		'山梨県',
		'長野県',
		'岐阜県',
		'静岡県',
		'愛知県',
		'三重県',
		'滋賀県',
		'京都府',
		'大阪府',
		'兵庫県',
		'奈良県',
		'和歌山県',
		'鳥取県',
		'島根県',
		'岡山県',
		'広島県',
		'山口県',
		'徳島県',
		'香川県',
		'愛媛県',
		'高知県',
		'福岡県',
		'佐賀県',
		'長崎県',
		'熊本県',
		'大分県',
		'宮崎県',
		'鹿児島県',
		'沖縄県',
	]
);
$cbjp_woo_customers = $cbjp_by_remote( $cbjp_snap['woo']['customers'], static fn ( array $c ): bool => true );
foreach ( $cbjp_snap['colorme']['customers'] as $cbjp_id => $cbjp_cm ) {
	$cbjp_where = "customer {$cbjp_id}";
	$cbjp_woo   = $cbjp_woo_customers[ (string) $cbjp_id ] ?? null;

	if ( null === $cbjp_woo ) {
		if ( true !== ( $cbjp_cm['member'] ?? null ) ) {
			$cbjp_report( 'NOTE', $cbjp_where, 'not a member (guest buyer); import skips non-members by design' );
		} else {
			$cbjp_report( 'MISSING', $cbjp_where, 'no Woo customer for this member' );
		}
		continue;
	}

	$cbjp_checks = [
		'mail'     => [ $cbjp_cm['mail'] ?? null, $cbjp_woo['email'] ],
		'postal'   => [ $cbjp_cm['postal'] ?? null, $cbjp_woo['billing']['postcode'] ?? null ],
		'pref'     => [ isset( $cbjp_jis[ $cbjp_cm['pref_name'] ?? '' ] ) ? sprintf( 'JP%02d', $cbjp_jis[ $cbjp_cm['pref_name'] ] ) : '', $cbjp_woo['billing']['state'] ?? null ],
		'address1' => [ $cbjp_cm['address1'] ?? null, $cbjp_woo['billing']['address_1'] ?? null ],
		'address2' => [ (string) ( $cbjp_cm['address2'] ?? '' ), (string) ( $cbjp_woo['billing']['address_2'] ?? '' ) ],
		'hojin'    => [ (string) ( $cbjp_cm['hojin'] ?? '' ), (string) ( $cbjp_woo['billing']['company'] ?? '' ) ],
		'name'     => [ $cbjp_cm['name'] ?? null, $cbjp_woo['meta']['_cbjp_full_name'] ?? null ],
	];

	foreach ( $cbjp_checks as $cbjp_key => [ $cbjp_from, $cbjp_to ] ) {
		if ( (string) $cbjp_from !== (string) $cbjp_to ) {
			$cbjp_report( 'MISMATCH', $cbjp_where, "{$cbjp_key} " . $cbjp_s( $cbjp_to ) . ' vs ColorMe ' . $cbjp_s( $cbjp_from ) );
		}
	}
}

echo "== orders ==\n";
$cbjp_woo_orders = [];
foreach ( $cbjp_snap['woo']['orders'] as $cbjp_order ) {
	$cbjp_remote = $cbjp_order['meta']['_cbjp_remote_order_id'] ?? null;

	if ( null !== $cbjp_remote ) {
		$cbjp_woo_orders[ (string) $cbjp_remote ] = $cbjp_order;
	}
}
foreach ( $cbjp_snap['colorme']['sales'] as $cbjp_id => $cbjp_cm ) {
	$cbjp_woo = $cbjp_woo_orders[ (string) $cbjp_id ] ?? null;

	if ( null === $cbjp_woo ) {
		$cbjp_report( 'MISSING', "sale {$cbjp_id}", 'no Woo order with _cbjp_remote_order_id' );
		continue;
	}

	if ( (string) ( $cbjp_cm['total_price'] ?? '' ) !== (string) (int) round( (float) $cbjp_woo['total'] ) ) {
		$cbjp_report( 'MISMATCH', "sale {$cbjp_id}", "total {$cbjp_woo['total']} vs total_price " . $cbjp_s( $cbjp_cm['total_price'] ?? null ) );
	}
}

echo 'summary: ' . wp_json_encode( $cbjp_counts ) . "\n";
