<?php
/**
 * テストショップに、リハーサル用の商品・会員を API で投入する（名前・メールは `ZZR-`／`zzr-` で始める。後片付けの目印）。
 * 引数: shop=<login_id> [part=all|products|customers] [categories=require|skip]
 *
 * - カテゴリ・グループは API で作れないので、先に管理画面で `ZZR-` で始まる名前で作っておく（SKILL.md 手順 0）。
 *   `categories=require`（既定）では、`ZZR-` の大カテゴリ・小カテゴリ・グループが 1 つも無ければ止まる。
 * - 同じ名前の `ZZR-` 商品・同じメールの会員が既にあれば作らずに飛ばす（途中で失敗した投入は再実行で残りだけを入れる）。
 *   ただし作成後の設定（在庫・カテゴリ・オプション）の途中で失敗した商品は飛ばされるので、FAIL の行を見て手で直す。
 * - 往復で値が変わりうる箇所（SKILL.md「往復リスク」）を突く商品を含める。作れなかったものは FAIL として出し、続ける。
 * - 商品の作成 API は在庫・小カテゴリ・グループを受け付けないので、作成後に `PUT /products/{id}` で設定する。
 *   オプションは `POST /products/{id}/options`、バリエーションの価格・在庫・型番は `PUT /products/{id}/variants/{id}`。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$cbjp_opts   = cbjp_rh_args( $args, [ 'shop', 'part', 'categories' ] );
$cbjp_shop   = cbjp_rh_require_shop( $cbjp_opts );
$cbjp_part   = $cbjp_opts['part'] ?? 'all';
$cbjp_client = cbjp_rh_client();

if ( ! in_array( $cbjp_part, [ 'all', 'products', 'customers' ], true ) || ! in_array( $cbjp_opts['categories'] ?? 'require', [ 'require', 'skip' ], true ) ) {
	cbjp_rh_abort( 'part must be all|products|customers, categories must be require|skip' );
}

$cbjp_failures = 0;
$cbjp_fail     = static function ( string $what, Throwable $e ) use ( &$cbjp_failures ): void {
	++$cbjp_failures;
	echo "  FAIL {$what}: " . get_class( $e ) . ': ' . $e->getMessage() . "\n";
};

// ---- 既存の投入分と、管理画面で作ったカテゴリ・グループ ----
// 名前（商品）・メール（会員）→ id。同じものは作り直さない（途中で失敗した投入を再実行で埋める）。
$cbjp_existing_products  = [];
$cbjp_existing_customers = [];
foreach ( cbjp_rh_get_all( $cbjp_client, 'products.json', 'products' ) as $cbjp_row ) {
	if ( str_starts_with( (string) ( $cbjp_row['name'] ?? '' ), 'ZZR-' ) ) {
		$cbjp_existing_products[ (string) $cbjp_row['name'] ] = (int) ( $cbjp_row['id'] ?? 0 );
	}
}
foreach ( cbjp_rh_get_all( $cbjp_client, 'customers.json', 'customers' ) as $cbjp_row ) {
	if ( str_starts_with( (string) ( $cbjp_row['mail'] ?? '' ), 'zzr-' ) ) {
		$cbjp_existing_customers[ (string) $cbjp_row['mail'] ] = (int) ( $cbjp_row['id'] ?? 0 );
	}
}

$cbjp_bigs   = [];
$cbjp_smalls = [];
foreach ( (array) ( $cbjp_client->get( 'categories.json' )['categories'] ?? [] ) as $cbjp_cat ) {
	if ( ! is_array( $cbjp_cat ) || ! str_starts_with( (string) ( $cbjp_cat['name'] ?? '' ), 'ZZR-' ) ) {
		continue;
	}

	$cbjp_bigs[] = (int) $cbjp_cat['id_big'];

	foreach ( is_array( $cbjp_cat['children'] ?? null ) ? $cbjp_cat['children'] : [] as $cbjp_child ) {
		if ( is_array( $cbjp_child ) && isset( $cbjp_child['id_small'] ) ) {
			$cbjp_smalls[] = [ (int) $cbjp_cat['id_big'], (int) $cbjp_child['id_small'] ];
		}
	}
}
$cbjp_groups = [];
foreach ( (array) ( $cbjp_client->get( 'groups.json' )['groups'] ?? [] ) as $cbjp_group ) {
	if ( is_array( $cbjp_group ) && str_starts_with( (string) ( $cbjp_group['name'] ?? '' ), 'ZZR-' ) ) {
		$cbjp_groups[] = (int) $cbjp_group['id'];
	}
}

echo 'ZZR- categories: big=' . wp_json_encode( $cbjp_bigs ) . ' small=' . wp_json_encode( $cbjp_smalls ) . ' groups=' . wp_json_encode( $cbjp_groups ) . "\n";

if ( 'customers' !== $cbjp_part && 'require' === ( $cbjp_opts['categories'] ?? 'require' ) && ( [] === $cbjp_bigs || [] === $cbjp_smalls || [] === $cbjp_groups ) ) {
	cbjp_rh_abort( 'create ZZR- big/small categories and groups in the shop admin first (or pass categories=skip).' );
}

$cbjp_big   = static fn ( int $i ): ?int => [] === $cbjp_bigs ? null : $cbjp_bigs[ $i % count( $cbjp_bigs ) ];
$cbjp_small = $cbjp_smalls[0] ?? null;

// ---- 商品 ----
// 各要素: create（POST の product）、update（作成後の PUT。在庫・小カテゴリ・グループ）、options（[軸名 => [値...]]）、
// variants（[ "値1" または "値1/値2" => variant の PUT 内容 ]）。
$cbjp_html = '<p>リハーサル用の説明です。</p><table><tr><td>表</td></tr></table>'
	. '<iframe src="https://www.youtube.com/embed/zzr-placeholder" width="560" height="315"></iframe>'
	. '<script>console.log("zzr")</script><style>.zzr{color:red}</style><p style="color:blue">装飾つき</p>';

$cbjp_products = [
	'P01 standard'                           => [
		'create' => [
			'model_number'  => 'ZZR-SKU-01',
			'sales_price'   => 1000,
			'stock_managed' => true,
		],
		'update' => [ 'stocks' => 10 ],
	],
	'P02 blank model number'                 => [
		'create' => [
			'sales_price'   => 1234,
			'stock_managed' => false,
		],
	],
	'P03 empty-string model number'          => [
		'create' => [
			'model_number'  => '',
			'sales_price'   => 1235,
			'stock_managed' => false,
		],
	],
	'P04 list price (sale)'                  => [
		'create' => [
			'model_number'  => 'ZZR-SKU-04',
			'price'         => 8000,
			'sales_price'   => 6000,
			'stock_managed' => true,
		],
		'update' => [ 'stocks' => 5 ],
	],
	'P05 reduced tax with list price'        => [
		'create' => [
			'model_number'  => 'ZZR-SKU-05',
			'price'         => 1500,
			'sales_price'   => 1250,
			'tax_reduced'   => true,
			'stock_managed' => false,
		],
	],
	'P06 reduced tax rounding'               => [
		'create' => [
			'model_number'  => 'ZZR-SKU-06',
			'sales_price'   => 1231,
			'tax_reduced'   => true,
			'stock_managed' => false,
		],
	],
	'P07 hidden'                             => [
		'create' => [
			'model_number'  => 'ZZR-SKU-07',
			'sales_price'   => 700,
			'display_state' => 'hidden',
			'stock_managed' => false,
		],
	],
	'P08 showing for members'                => [
		'create' => [
			'model_number'  => 'ZZR-SKU-08',
			'sales_price'   => 800,
			'display_state' => 'showing_for_members',
			'stock_managed' => false,
		],
	],
	'P09 sale for members'                   => [
		'create' => [
			'model_number'  => 'ZZR-SKU-09',
			'sales_price'   => 900,
			'display_state' => 'sale_for_members',
			'stock_managed' => false,
		],
	],
	'P10 managed stock never set'            => [
		'create' => [
			'model_number'  => 'ZZR-SKU-10',
			'sales_price'   => 1100,
			'stock_managed' => true,
		],
	],
	'P11 html description'                   => [
		'create' => [
			'model_number'  => 'ZZR-SKU-11',
			'sales_price'   => 1100,
			'stock_managed' => false,
			'expl'          => $cbjp_html,
			'simple_expl'   => '簡易説明 <b>太字</b> & 記号',
		],
	],
	'P12 name Tom & Jerry <set>'             => [
		'create' => [
			'model_number'  => 'ZZR-SKU-12',
			'sales_price'   => 1200,
			'stock_managed' => false,
		],
	],
	'P13 members price and cost'             => [
		'create' => [
			'model_number'    => 'ZZR-SKU-13',
			'sales_price'     => 1300,
			'members_price'   => 1200,
			'cost'            => 600,
			'stock_managed'   => false,
			'smartphone_expl' => 'スマホ向け説明',
		],
	],
	'P14 one axis mixed variants'            => [
		'create'   => [
			'model_number'  => 'ZZR-SKU-14',
			'sales_price'   => 1200,
			'stock_managed' => true,
		],
		'options'  => [ 'サイズ' => [ 'S', 'M', 'L' ] ],
		'variants' => [
			'S' => [ 'stocks' => 3 ],
			'M' => [
				'stocks'       => 4,
				'option_price' => 1500,
				'model_number' => 'ZZR-V14-M',
			],
			'L' => [
				'stocks'              => 5,
				'option_price'        => 1800,
				'option_market_price' => 2000,
				'model_number'        => 'ZZR-V14-L',
				'weight'              => 300,
			],
		],
	],
	'P15 two axes'                           => [
		'create'   => [
			'model_number'  => 'ZZR-SKU-15',
			'sales_price'   => 2000,
			'stock_managed' => true,
		],
		'options'  => [
			'カラー' => [ '赤', '青' ],
			'サイズ' => [ 'S', 'M' ],
		],
		'variants' => [
			'赤/S' => [
				'stocks'       => 1,
				'option_price' => 2000,
				'model_number' => 'ZZR-V15-RS',
			],
			'赤/M' => [
				'stocks'       => 2,
				'option_price' => 2100,
				'model_number' => 'ZZR-V15-RM',
			],
			'青/S' => [
				'stocks'       => 3,
				'option_price' => 2000,
				'model_number' => 'ZZR-V15-BS',
			],
			'青/M' => [
				'stocks'       => 0,
				'option_price' => 2100,
				'model_number' => 'ZZR-V15-BM',
			],
		],
	],
	'P16 managed variants never stocked'     => [
		'create'  => [
			'model_number'  => 'ZZR-SKU-16',
			'sales_price'   => 1600,
			'stock_managed' => true,
		],
		'options' => [ '味' => [ '甘口', '辛口' ] ],
	],
	'P17 unmanaged variants with own prices' => [
		'create'   => [
			'model_number'  => 'ZZR-SKU-17',
			'sales_price'   => 1000,
			'stock_managed' => false,
		],
		'options'  => [ '容量' => [ '100ml', '200ml' ] ],
		'variants' => [
			'100ml' => [
				'option_price' => 1100,
				'model_number' => 'ZZR-V17-100',
			],
			'200ml' => [
				'option_price' => 1900,
				'model_number' => 'ZZR-V17-200',
			],
		],
	],
	'P18 small category'                     => [
		'create' => [
			'model_number'  => 'ZZR-SKU-18',
			'sales_price'   => 1800,
			'stock_managed' => true,
		],
		'update' => [ 'stocks' => 7 ],
		'small'  => true,
	],
	'P19 in groups'                          => [
		'create' => [
			'model_number'  => 'ZZR-SKU-19',
			'sales_price'   => 1900,
			'stock_managed' => false,
		],
		'groups' => true,
	],
];

for ( $cbjp_i = 20; $cbjp_i <= 55; $cbjp_i++ ) {
	$cbjp_products[ sprintf( 'P%02d filler', $cbjp_i ) ] = [
		'create' => [
			'model_number'  => sprintf( 'ZZR-SKU-%02d', $cbjp_i ),
			'sales_price'   => 500 + 37 * $cbjp_i,
			'stock_managed' => 0 === $cbjp_i % 2,
		],
		'update' => 0 === $cbjp_i % 2 ? [ 'stocks' => $cbjp_i % 9 ] : [],
	];
}

if ( 'customers' !== $cbjp_part ) {
	echo '== products (' . count( $cbjp_products ) . ") ==\n";
	$cbjp_index = 0;

	foreach ( $cbjp_products as $cbjp_name => $cbjp_def ) {
		$cbjp_payload         = $cbjp_def['create'];
		$cbjp_payload['name'] = "ZZR-{$cbjp_name}";
		$cbjp_category        = $cbjp_big( $cbjp_index++ );

		if ( isset( $cbjp_existing_products[ $cbjp_payload['name'] ] ) ) {
			echo "  skip {$cbjp_existing_products[ $cbjp_payload['name'] ]} {$cbjp_payload['name']} (already exists)\n";
			continue;
		}

		if ( null !== $cbjp_category ) {
			$cbjp_payload['category_id_big'] = ! empty( $cbjp_def['small'] ) && null !== $cbjp_small ? $cbjp_small[0] : $cbjp_category;
		}

		try {
			$cbjp_id = (int) ( $cbjp_client->post( 'products.json', [ 'product' => $cbjp_payload ] )['product']['id'] ?? 0 );
		} catch ( Throwable $e ) {
			$cbjp_fail( "create {$cbjp_name}", $e );
			continue;
		}

		if ( $cbjp_id <= 0 ) {
			$cbjp_fail( "create {$cbjp_name}", new RuntimeException( 'no product id in the response' ) );
			continue;
		}

		$cbjp_update = $cbjp_def['update'] ?? [];

		if ( ! empty( $cbjp_def['small'] ) && null !== $cbjp_small ) {
			// 小カテゴリだけを送ると「指定したカテゴリidが無効です」で拒否される（実測）。大カテゴリと対で送る。
			$cbjp_update['category_id_big']   = $cbjp_small[0];
			$cbjp_update['category_id_small'] = $cbjp_small[1];
		}

		if ( ! empty( $cbjp_def['groups'] ) && [] !== $cbjp_groups ) {
			$cbjp_update['group_ids'] = $cbjp_groups;
		}

		try {
			if ( [] !== $cbjp_update ) {
				$cbjp_client->put( "products/{$cbjp_id}.json", [ 'product' => $cbjp_update ] );
			}

			foreach ( $cbjp_def['options'] ?? [] as $cbjp_axis => $cbjp_values ) {
				$cbjp_client->post(
					"products/{$cbjp_id}/options.json",
					[
						'option' => [
							'name'   => $cbjp_axis,
							'values' => array_map( static fn ( string $v ): array => [ 'name' => $v ], $cbjp_values ),
						],
					]
				);
			}

			if ( [] !== ( $cbjp_def['variants'] ?? [] ) ) {
				// 2 つ目のオプションを作った直後の取得では、バリエーションがまだ 1 軸のことがある（実測。P15 で値の組が 1 つも
				// 対応せず、PUT を 1 件も送らないまま成功扱いになった）。全ての組が出そろうまで取り直し、そろわなければ FAIL にする。
				$cbjp_by_key = [];

				for ( $cbjp_try = 0; $cbjp_try < 5 && count( $cbjp_by_key ) < count( $cbjp_def['variants'] ); $cbjp_try++ ) {
					if ( $cbjp_try > 0 ) {
						sleep( 2 );
					}

					$cbjp_by_key = [];
					$cbjp_detail = $cbjp_client->get( "products/{$cbjp_id}.json" )['product'] ?? [];

					foreach ( (array) ( $cbjp_detail['variants'] ?? [] ) as $cbjp_variant ) {
						$cbjp_key = implode( '/', array_filter( [ $cbjp_variant['option1_value'] ?? null, $cbjp_variant['option2_value'] ?? null ], static fn ( $v ): bool => null !== $v && '' !== $v ) );

						if ( isset( $cbjp_def['variants'][ $cbjp_key ] ) ) {
							$cbjp_by_key[ $cbjp_key ] = (int) $cbjp_variant['id'];
						}
					}
				}

				if ( count( $cbjp_by_key ) < count( $cbjp_def['variants'] ) ) {
					throw new RuntimeException( 'only ' . count( $cbjp_by_key ) . ' of ' . count( $cbjp_def['variants'] ) . ' variants appeared: ' . implode( ', ', array_keys( $cbjp_by_key ) ) );
				}

				foreach ( $cbjp_by_key as $cbjp_key => $cbjp_variant_id ) {
					$cbjp_client->put( "products/{$cbjp_id}/variants/{$cbjp_variant_id}.json", [ 'variant' => $cbjp_def['variants'][ $cbjp_key ] ] );
				}
			}
		} catch ( Throwable $e ) {
			$cbjp_fail( "set up {$cbjp_name} (#{$cbjp_id})", $e );
			continue;
		}

		echo "  ok {$cbjp_id} ZZR-{$cbjp_name}\n";
	}
}

// ---- 会員 ----
// pref_id は ColorMe の番号（JIS と一致しない県がある。`AddressMapper::PREF_ID_TO_JIS_NUMBER`）。48 は海外。
$cbjp_customers = [
	'C01' => [
		'name'     => 'ZZR-山田 太郎',
		'furigana' => 'ヤマダ タロウ',
		'pref_id'  => 13,
		'postal'   => '1000001',
		'address1' => '千代田区千代田1-1',
		'address2' => 'ZZRビル101',
		'tel'      => '03-0000-0001',
	],
	'C02' => [
		'name'     => 'ZZR-秋田 花子',
		'pref_id'  => 4,
		'postal'   => '0100001',
		'address1' => '秋田市中通1-1',
		'tel'      => '018-000-0002',
	],
	'C03' => [
		'name'     => 'ZZR-静岡 次郎',
		'pref_id'  => 19,
		'postal'   => '4200001',
		'address1' => '静岡市葵区1-1',
		'tel'      => '054-000-0003',
	],
	'C04' => [
		'name'     => 'ZZR-沖縄 三郎',
		'pref_id'  => 47,
		'postal'   => '9000001',
		'address1' => '那覇市1-1',
		'tel'      => '098-000-0004',
	],
	'C05' => [
		'name'     => 'ZZR-海外 四郎',
		'pref_id'  => 48,
		'postal'   => '0000000',
		'address1' => '1 Example Street, Example City',
		'tel'      => '0000000005',
	],
	'C06' => [
		'name'     => 'ZZR-法人 五郎',
		'pref_id'  => 27,
		'postal'   => '5300001',
		'address1' => '大阪市北区1-1',
		'tel'      => '06-0000-0006',
		'hojin'    => 'ZZR株式会社',
		'busho'    => '開発部',
	],
	'C07' => [
		'name'     => 'ZZR-誕生 六子',
		'pref_id'  => 1,
		'postal'   => '0600001',
		'address1' => '札幌市中央区1-1',
		'tel'      => '011-000-0007',
		'birthday' => '1990-01-31',
	],
	'C08' => [
		'name'                  => 'ZZR-購読 七海',
		'pref_id'               => 26,
		'postal'                => '6000001',
		'address1'              => '京都市下京区1-1',
		'tel'                   => '075-000-0008',
		'receive_mail_magazine' => true,
	],
	'C09' => [
		'name'                  => 'ZZR-拒否 八雲',
		'pref_id'               => 40,
		'postal'                => '8100001',
		'address1'              => '福岡市中央区1-1',
		'tel'                   => '092-000-0009',
		'receive_mail_magazine' => false,
	],
	'C10' => [
		'name'     => 'ZZR-空白なし九',
		'pref_id'  => 14,
		'postal'   => '2310001',
		'address1' => '横浜市中区1-1',
		'tel'      => '0450000010',
	],
	'C11' => [
		'name'     => 'ZZR-備考 十',
		'pref_id'  => 23,
		'postal'   => '4600001',
		'address1' => '名古屋市中区1-1',
		'tel'      => '052-000-0011',
		'fax'      => '052-000-1011',
		'other'    => '備考のテキスト',
	],
	'C12' => [
		'name'     => 'ZZR-兵庫 十一',
		'pref_id'  => 28,
		'postal'   => '6500001',
		'address1' => '神戸市中央区1-1',
		'tel'      => '078-000-0012',
		'furigana' => 'ヒョウゴ ジュウイチ',
	],
];

if ( 'products' !== $cbjp_part ) {
	echo '== customers (' . count( $cbjp_customers ) . ") ==\n";

	foreach ( $cbjp_customers as $cbjp_key => $cbjp_customer ) {
		$cbjp_customer['mail']       = 'zzr-' . strtolower( $cbjp_key ) . '@example.com';
		$cbjp_customer['add_member'] = true;

		if ( isset( $cbjp_existing_customers[ $cbjp_customer['mail'] ] ) ) {
			echo "  skip {$cbjp_existing_customers[ $cbjp_customer['mail'] ]} {$cbjp_customer['mail']} (already exists)\n";
			continue;
		}

		try {
			$cbjp_id = (int) ( $cbjp_client->post( 'customers.json', [ 'customer' => $cbjp_customer ] )['customer']['id'] ?? 0 );
			echo "  ok {$cbjp_id} {$cbjp_customer['name']} <{$cbjp_customer['mail']}>\n";
		} catch ( Throwable $e ) {
			$cbjp_fail( "customer {$cbjp_key}", $e );
		}
	}
}

echo 0 === $cbjp_failures ? "done.\n" : "done with {$cbjp_failures} failure(s).\n";
