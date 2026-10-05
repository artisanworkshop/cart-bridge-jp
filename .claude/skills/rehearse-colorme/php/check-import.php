<?php
/**
 * 取込み後のスナップショット（side=both）で、ColorMe の値と Woo の値を主要項目ごとに突き合わせる。読み取りのみ。
 * 引数: label=<name>
 *
 * Woo 側は `_cbjp_remote_id` メタ（商品・バリエーション・顧客。受注は `_cbjp_remote_order_id`）で ColorMe の id と結ぶ。
 * 見る項目: 商品（名前・型番・公開状態・税区分・価格・在庫と在庫管理・説明・重複）、バリエーション（型番・価格・セール価格・在庫）、
 * 会員（メール・郵便番号・県・住所・法人名・電話・名前）、受注（合計・税額・決済と配送のマッピング先）。
 * - `MISMATCH`: 変換規則（docs/03・`ProductTransformer`）から見て食い違う値。原因を調べる対象。
 * - `NOTE`: 規則どおりだが往復で問題になりうる変換（仮 SKU・在庫 null→0・会員限定の公開状態など）。
 * - `MISSING`: ColorMe にあるのに Woo に無い（会員以外の顧客は `NOTE`）。
 * `MISMATCH` か `MISSING` が 1 件でもあれば終了コード 1。
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
// ColorMe の id → Woo の実体。同じ id に Woo の実体が 2 件以上あれば重複作成なので MISMATCH にする（後勝ちで黙って 1 件にしない）。
// 他の platform の実体（`_cbjp_platform` が colorme 以外）は数えない。
$cbjp_by_remote = static function ( string $kind, array $rows, callable $filter ) use ( $cbjp_report ): array {
	$out = [];

	foreach ( $rows as $row ) {
		$remote   = $row['meta']['_cbjp_remote_id'] ?? null;
		$platform = $row['meta']['_cbjp_platform'] ?? null;

		if ( null === $remote || '' === $remote || ( null !== $platform && 'colorme' !== $platform ) || ! $filter( $row ) ) {
			continue;
		}

		if ( isset( $out[ (string) $remote ] ) ) {
			$cbjp_report( 'MISMATCH', "{$kind} {$remote}", 'duplicate Woo entities for one ColorMe id: #' . $out[ (string) $remote ]['id'] . ' and #' . $row['id'] );
			continue;
		}

		$out[ (string) $remote ] = $row;
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
// 在庫管理ありの商品・バリエーション: 取込みは Woo の `manage_stock=true` にし、在庫が null（未設定）なら 0 にする
// （`ProductTransformer` の `stock()`／バリエーションの在庫。`.claude/rules/adapters-colorme.md`）。フラグと期待する数量の両方を見る。
// null から 0 への変換そのものは往復リスク 3 の実例として NOTE にする（G2-2）。
$cbjp_check_stock = static function ( string $where, $stocks, array $woo ) use ( $cbjp_report, $cbjp_s ): void {
	if ( true !== $woo['manage_stock'] ) {
		$cbjp_report( 'MISMATCH', $where, 'stock is managed in ColorMe, but Woo manage_stock=' . $cbjp_s( $woo['manage_stock'] ) );
		return;
	}

	$want = is_int( $stocks ) ? max( 0, $stocks ) : 0;

	if ( $want !== $woo['stock_quantity'] ) {
		$cbjp_report( 'MISMATCH', $where, 'stock ' . $cbjp_s( $woo['stock_quantity'] ) . ' vs expected ' . $want . ' (ColorMe stocks ' . $cbjp_s( $stocks ) . ')' );
	} elseif ( null === $stocks ) {
		$cbjp_report( 'NOTE', $where, 'stocks is null in ColorMe; Woo stock = 0 (would be sent back as 0)' );
	}
};

$cbjp_woo_products   = $cbjp_by_remote( 'product', $cbjp_snap['woo']['products'], static fn ( array $p ): bool => 'variation' !== $p['type'] );
$cbjp_woo_variations = $cbjp_by_remote( 'variant', $cbjp_snap['woo']['products'], static fn ( array $p ): bool => 'variation' === $p['type'] );

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
	// 公開してよいのは showing／sale_for_members だけ（`ProductTransformer`）。非公開であるべき商品の公開は fail-open（原則 9）なので MISMATCH。
	// 逆向き（公開のはずが private）は、販売期間外・売切れ非表示・価格不明・デジタル商品などの強制非公開なので NOTE。
	if ( 'private' === $cbjp_want && 'private' !== $cbjp_woo['status'] ) {
		$cbjp_report( 'MISMATCH', $cbjp_where, "display_state {$cbjp_state} must not be public in Woo, but status is {$cbjp_woo['status']}" );
	} elseif ( $cbjp_woo['status'] !== $cbjp_want ) {
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

		if ( ! is_int( $cbjp_sales_incl ) ) {
			$cbjp_report( 'NOTE', $cbjp_where, 'price check skipped: no sales_price_including_tax in ColorMe' );
		} elseif ( null !== ( $cbjp_cm['price'] ?? null ) && null === $cbjp_list_incl ) {
			// 定価の税込換算をこのスクリプトが扱えない税設定（`round_off` 以外の丸めなど）。期待値を作れないので突合しない
			// （定価を無いものとして比べると、正しく取り込んだセール商品を MISMATCH と誤報する）。
			$cbjp_report( 'NOTE', $cbjp_where, 'price check skipped: this script cannot convert the list price under the shop tax settings' );
		} elseif ( (string) $cbjp_want_reg !== (string) $cbjp_woo['regular_price'] || (string) $cbjp_want_sale !== (string) $cbjp_woo['sale_price'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_where, "price: Woo regular={$cbjp_woo['regular_price']} sale={$cbjp_woo['sale_price']} vs expected regular={$cbjp_want_reg} sale=" . $cbjp_s( $cbjp_want_sale ) . " (ColorMe price={$cbjp_s( $cbjp_cm['price'] ?? null )} sales_price={$cbjp_s( $cbjp_cm['sales_price'] ?? null )})" );
		}
	}

	// 取込みは明示の false 以外（欠損・null を含む）を「在庫管理あり」に倒す（`ProductTransformer::is_stock_managed()` のフェイルクローズ）。
	// 同じ判定で、管理なしの向きと管理ありの向き（在庫数）の両方を見る（G1-3）。
	$cbjp_managed = false !== ( $cbjp_cm['stock_managed'] ?? null );

	if ( ! $cbjp_managed && [] === $cbjp_variants && true === $cbjp_woo['manage_stock'] ) {
		$cbjp_report( 'MISMATCH', $cbjp_where, 'ColorMe does not manage stock, but Woo manage_stock=true (stock ' . $cbjp_s( $cbjp_woo['stock_quantity'] ) . ')' );
	}

	if ( $cbjp_managed ) {
		// variable 商品の在庫は Woo ではバリエーション側で管理する（親の manage_stock は false）。下のバリエーションの突合で見る。
		if ( [] === $cbjp_variants ) {
			$cbjp_check_stock( $cbjp_where, $cbjp_cm['stocks'] ?? null, $cbjp_woo );
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

		// バリエーションは remote_id で全体から引くので、別の可変商品に付いていても型番・価格・在庫の照合は通ってしまう。親を確かめる（G3-B1）。
		if ( (int) $cbjp_wv['parent_id'] !== (int) $cbjp_woo['id'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_vwhere, 'the Woo variation belongs to product #' . (int) $cbjp_wv['parent_id'] . ', not #' . (int) $cbjp_woo['id'] );
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
		if ( '' !== (string) $cbjp_wv['sale_price'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_vwhere, 'Woo variation has a sale price ' . $cbjp_s( $cbjp_wv['sale_price'] ) . ' (import does not set variation sale prices)' );
		}

		if ( ! $cbjp_managed && true === $cbjp_wv['manage_stock'] ) {
			$cbjp_report( 'MISMATCH', $cbjp_vwhere, 'ColorMe does not manage stock, but the Woo variation manage_stock=true' );
		}

		if ( null === ( $cbjp_variant['option_price'] ?? null ) ) {
			$cbjp_report( 'NOTE', $cbjp_vwhere, 'option_price is null (inherits the product price); Woo stores ' . $cbjp_s( $cbjp_wv['regular_price'] ) );
		}

		if ( $cbjp_managed ) {
			$cbjp_check_stock( $cbjp_vwhere, $cbjp_variant['stocks'] ?? null, $cbjp_wv );
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
$cbjp_woo_customers = $cbjp_by_remote( 'customer', $cbjp_snap['woo']['customers'], static fn ( array $c ): bool => true );
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
		// 取込みは `tel`、空なら `tel_mobile` を電話にする（`CustomerTransformer`）。
		'tel'      => [ (string) ( ( $cbjp_cm['tel'] ?? '' ) !== '' && null !== ( $cbjp_cm['tel'] ?? null ) ? $cbjp_cm['tel'] : ( $cbjp_cm['tel_mobile'] ?? '' ) ), (string) ( $cbjp_woo['billing']['phone'] ?? '' ) ],
		'name'     => [ $cbjp_cm['name'] ?? null, $cbjp_woo['meta']['_cbjp_full_name'] ?? null ],
	];

	foreach ( $cbjp_checks as $cbjp_key => [ $cbjp_from, $cbjp_to ] ) {
		if ( (string) $cbjp_from !== (string) $cbjp_to ) {
			$cbjp_report( 'MISMATCH', $cbjp_where, "{$cbjp_key} " . $cbjp_s( $cbjp_to ) . ' vs ColorMe ' . $cbjp_s( $cbjp_from ) );
		}
	}
}

echo "== orders ==\n";
$cbjp_settings     = is_array( $cbjp_snap['woo']['settings'] ?? null ) ? $cbjp_snap['woo']['settings'] : [];
$cbjp_payment_map  = is_array( $cbjp_settings['payment_map'] ?? null ) ? $cbjp_settings['payment_map'] : [];
$cbjp_shipping_map = is_array( $cbjp_settings['shipping_map'] ?? null ) ? $cbjp_settings['shipping_map'] : [];

if ( ! isset( $cbjp_snap['woo']['settings'] ) ) {
	echo "  (this snapshot has no mapping settings; payment/shipping are not checked — take it again)\n";
}
$cbjp_woo_orders = [];
foreach ( $cbjp_snap['woo']['orders'] as $cbjp_order ) {
	$cbjp_remote = $cbjp_order['meta']['_cbjp_remote_order_id'] ?? null;

	if ( null === $cbjp_remote ) {
		continue;
	}

	// 商品・会員と同じく、同じ ColorMe の受注に Woo の受注が 2 件以上あれば重複作成（後勝ちで黙って 1 件にしない）。
	if ( isset( $cbjp_woo_orders[ (string) $cbjp_remote ] ) ) {
		$cbjp_report( 'MISMATCH', "sale {$cbjp_remote}", 'duplicate Woo orders for one ColorMe sale: #' . $cbjp_woo_orders[ (string) $cbjp_remote ]['id'] . ' and #' . $cbjp_order['id'] );
		continue;
	}

	$cbjp_woo_orders[ (string) $cbjp_remote ] = $cbjp_order;
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

	// 税額。ColorMe の `tax` は商品分だけで、送料・手数料の税を含む税額は `totals`（標準＋軽減）にある（取込みはこちらを使う。
	// `totals` が無い古い受注は `tax`）。軽減税率の明細の税（8%）は、`totals.reduced_tax_amount` を通してここに入る。
	// 期待値は取込みの規則（`OrderTransformer::tax()`）と同じ: 分割受注は 0、`totals.normal_tax_amount` があれば標準＋軽減（軽減の欠損は 0）、無ければ `tax`。
	$cbjp_totals   = is_array( $cbjp_cm['totals'] ?? null ) ? $cbjp_cm['totals'] : null;
	$cbjp_is_split = is_array( $cbjp_cm['segment'] ?? null ) && true === ( $cbjp_cm['segment']['splitted'] ?? null );
	$cbjp_tax_want = match ( true ) {
		$cbjp_is_split => 0,
		null !== $cbjp_totals && isset( $cbjp_totals['normal_tax_amount'] ) => (int) $cbjp_totals['normal_tax_amount'] + (int) ( $cbjp_totals['reduced_tax_amount'] ?? 0 ),
		default => $cbjp_cm['tax'] ?? null,
	};
	if ( (string) $cbjp_tax_want !== (string) (int) round( (float) $cbjp_woo['total_tax'] ) ) {
		$cbjp_report( 'MISMATCH', "sale {$cbjp_id}", "total_tax {$cbjp_woo['total_tax']} vs ColorMe tax " . $cbjp_s( $cbjp_tax_want ) . ' (totals: normal + reduced, or tax)' );
	}

	// 決済・配送のマッピング（スナップショットに含めた設定の `payment_map`／`shipping_map`）。未設定の id は突合しない（取込みの警告が担当）。
	$cbjp_payment_want = $cbjp_payment_map[ (string) ( $cbjp_cm['payment_id'] ?? '' ) ] ?? null;
	if ( null !== $cbjp_payment_want && $cbjp_payment_want !== $cbjp_woo['payment_method'] ) {
		$cbjp_report( 'MISMATCH', "sale {$cbjp_id}", "payment_method {$cbjp_woo['payment_method']} vs payment_map {$cbjp_payment_want}" );
	}

	// 取込みは先頭の配送先だけを配送方法にする（`OrderTransformer::shipping()`）。マッピング値の `flat_rate` のような
	// インスタンス番号の無い形は `flat_rate:0` として保存される（`MethodMap::split_shipping_method_id()`）ので、同じ形にそろえて比べる。
	$cbjp_methods   = array_values( array_filter( array_map( static fn ( array $i ): ?string => 'shipping' === $i['type'] ? $i['method'] ?? null : null, $cbjp_woo['items'] ) ) );
	$cbjp_delivery  = is_array( $cbjp_cm['sale_deliveries'] ?? null ) && is_array( $cbjp_cm['sale_deliveries'][0] ?? null ) ? $cbjp_cm['sale_deliveries'][0] : null;
	$cbjp_ship_want = null !== $cbjp_delivery ? ( $cbjp_shipping_map[ (string) ( $cbjp_delivery['delivery_id'] ?? '' ) ] ?? null ) : null;

	if ( is_string( $cbjp_ship_want ) && ! str_contains( $cbjp_ship_want, ':' ) ) {
		$cbjp_ship_want .= ':0';
	}

	if ( null !== $cbjp_ship_want && ! in_array( $cbjp_ship_want, $cbjp_methods, true ) ) {
		$cbjp_report( 'MISMATCH', "sale {$cbjp_id}", 'shipping methods ' . $cbjp_s( $cbjp_methods ) . " do not include shipping_map {$cbjp_ship_want}" );
	}

	if ( is_array( $cbjp_cm['sale_deliveries'] ?? null ) && count( $cbjp_cm['sale_deliveries'] ) > 1 ) {
		$cbjp_report( 'NOTE', "sale {$cbjp_id}", 'multiple delivery destinations; import keeps only the first one as the shipping method' );
	}
}

echo 'summary: ' . wp_json_encode( $cbjp_counts ) . "\n";

// 食い違い・欠落があれば非ゼロで終える（出力を人が読まなくても、続く手順や確認スクリプトが失敗として扱えるように。G1-1）。
if ( $cbjp_counts['MISMATCH'] > 0 || $cbjp_counts['MISSING'] > 0 ) {
	exit( 1 );
}
