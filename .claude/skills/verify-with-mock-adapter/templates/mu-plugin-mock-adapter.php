<?php
/**
 * cbjp-verify-mock-adapter: managed by .claude/skills/verify-with-mock-adapter
 *
 * TEMPORARY. 手動検証専用: mock アダプタを「__PLATFORM_KEY__」というキーで `cbjp/adapters/register` に登録する。
 * 検証が終わったら `mock-adapter.sh uninstall` で削除すること（コミットしない）。
 *
 * - mock の中身（顧客・受注）は、オプション `cbjp_verify_seed`（配列。seed スクリプトが保存する）から組み立てる。
 *   形は { customers: [{remote_id,email,pref}], orders: [{number,billing_pref,shipping_pref}],
 *   push: {enabled: bool, create_failure: 'ambiguous_5xx'|null} }。`push.enabled=true` で
 *   push_product()/push_customer()/push_order()/push_coupon() が成功を返すようになる
 *   （既定では全て`UnsupportedOperationException`で失敗する）。`push.create_failure='ambiguous_5xx'`は
 *   D21-B（issue #73）の「作成結果が不明」経路（`cbjp_push_intents`に印が残る）を再現する
 *   （作成経路`$remote_id===null`のみに効く。`tests/unit/Fixtures/MockPlatformAdapter`の
 *   `create_push_failure`参照）。別の形のデータが要るなら、この関数を検証用に書き換えてよい
 *   （テンプレートなので）。
 * - `capabilities`（配列。任意）があれば mock の `capabilities()` を上書きする（D24。Export タブの Beta 表示・
 *   既定オフ・非プレミアム相当の出し分けの確認用）。形は { can_create_order: bool, can_push_images: bool,
 *   beta_features: string[] }。省略したキーは mock の既定（`can_*` は true、`beta_features` は空）。
 *   例: プレミアム相当のベータ機能 → { can_create_order: true, can_push_images: true, beta_features: ['order_export', 'image_push'] } /
 *   非プレミアム相当 → { can_create_order: false, can_push_images: false, beta_features: ['order_export', 'image_push'] }。
 * - `mapping_candidates`（配列。任意）は mock の `mapping_candidates()` がそのまま返す ASP 側の候補（R3-0m の Mappings タブ・
 *   Import タブの事前チェックの確認用）。形は { payment: [{id,name}], shipping: [{id,name}], category: [...], status: [...] }
 *   （省略したキーは空。`RestController` が正規化する）。受注の `payment_method_id`/`payment_method_name`/`shipping_method_id`/
 *   `shipping_method_name`（任意）は canonical の `payment`/`shipping` の `method_id`/`method_name` になり、`payment_map`/`shipping_map`
 *   が未設定なら dry-run で `payment_method_unmapped`/`shipping_method_unmapped` が付く。
 * - `limits`（配列。任意）は無料版の上限（`cbjp/limits/{entity}`）を差し替える。形は { entity: int|null }（null は Pro 相当の解除）。
 *   `pro_url`（任意）は `cbjp/limits/pro_url` の戻り値にそのまま渡す（`LimitPolicy::pro_url()` が検証する）。どちらも**サイト全体に効く**
 *   ので、使い終わったらキーを外す（R3-0h の Pro 案内の検証用。`examples/upsell-notice/`）。
 * - クラス定義をこのファイルのトップレベルに書かない。mu-plugins は通常プラグインより先に読み込まれ、
 *   composer の autoloader がまだ無い。`plugins_loaded` のコールバック内で `new` すればよい。
 * - `CartBridgeJP\Tests\Fixtures\MockPlatformAdapter` は composer の autoload-dev（tests/unit/）。
 *   ホストで `composer install`（dev 依存込み）済みであること。
 */
add_action(
	'plugins_loaded',
	static function () {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$seed      = get_option( 'cbjp_verify_seed', [] );
				$customers = [];
				$orders    = [];

				// 壊れた値（配列以外・配列でない行）は読み飛ばす。mu-plugin の致命的エラー（TypeError 等）は
				// 開発サイトの全リクエストを落とし、WP を起動する `mock-adapter.sh run`/`inspect`（cleanup を含む）まで動かなくなるため
				// （`uninstall` はホスト側でファイルを消すだけなので効く）。
				$rows = static function ( string $key ) use ( $seed ): array {
					$list = is_array( $seed ) && is_array( $seed[ $key ] ?? null ) ? $seed[ $key ] : [];

					return array_filter( $list, 'is_array' );
				};

				// ColorMe の住所形（`AddressMapper::to_woo()` が解釈するキー）。他のプラットフォームなら合わせて変える。
				$address = static fn ( int $pref, string $postal, string $line ): array => [
					'name'      => 'Verify User',
					'email'     => 'verify@example.com',
					'postal'    => $postal,
					'pref_id'   => $pref,
					'pref_name' => 'Placeholder',
					'address1'  => $line,
					'address2'  => null,
					'tel'       => '0312345678',
					'country'   => 'JP',
				];

				foreach ( $rows( 'customers' ) as $c ) {
					$customers[] = new CartBridgeJP\Canonical\CanonicalCustomer(
						$c['email'],
						'Verify User',
						null,
						null,
						null,
						$address( (int) $c['pref'], '1000001', 'Verify 1-1-1' ),
						'0312345678',
						null,
						null,
						null,
						[ 'remote_id' => $c['remote_id'] ]
					);
				}

				// 受注の決済/配送方法（任意。R3-0m）。文字列以外は読み飛ばす（mu-plugin の fatal を避ける既存方針）。
				$method = static fn ( array $o, string $key ): ?string => is_string( $o[ $key ] ?? null ) ? $o[ $key ] : null;

				foreach ( $rows( 'orders' ) as $o ) {
					$orders[] = new CartBridgeJP\Canonical\CanonicalOrder(
						$o['number'],
						'processing',
						null,
						[],
						array_merge(
							$address( (int) $o['shipping_pref'], '1000001', 'Verify 1-1-1' ),
							[
								'method_id'   => $method( $o, 'shipping_method_id' ),
								'method_name' => $method( $o, 'shipping_method_name' ),
							]
						),
						[
							'method_id'   => $method( $o, 'payment_method_id' ),
							'method_name' => $method( $o, 'payment_method_name' ),
						],
						[
							'total'        => '1000',
							'tax'          => '0',
							'shipping_fee' => '0',
							'discount'     => '0',
						],
						'2026-01-01T00:00:00+00:00',
						null,
						[
							'remote_id'         => $o['number'],
							'customer_snapshot' => $address( (int) $o['billing_pref'], '1000001', 'Verify 1-1-1' ),
						]
					);
				}

				// D21-B（issue #73）検証用: `push`が配列でない・`enabled`が真偽値でない場合は
				// 無効（push_*()は全てUnsupportedOperationExceptionのまま）として読み飛ばす
				// （mu-pluginのfatalを避ける既存方針。`$rows()`と同じ考え方）。
				$push_seed        = is_array( $seed ) && is_array( $seed['push'] ?? null ) ? $seed['push'] : [];
				$push_enabled     = true === ( $push_seed['enabled'] ?? false );
				$create_push_fail = 'ambiguous_5xx' === ( $push_seed['create_failure'] ?? null )
					? new CartBridgeJP\Support\ApiException( 'Simulated 5xx (verify-with-mock-adapter)', 500 )
					: null;

				// D24 検証用: `capabilities` が配列のときだけ上書きする（配列でなければ既定の mock。mu-plugin の fatal を
				// 避ける既存方針）。`can_*` は**キーが無いときだけ**既定の true、キーがあれば厳密な
				// `true ===` 比較で、真偽値以外（`'true'`・`1`・`null` など）は false（型違いを「できる」と読まない側に倒す。
				// `?? true` にすると `null` がキー欠損と同じ扱いになり true になる）。
				$caps_seed      = is_array( $seed ) && is_array( $seed['capabilities'] ?? null ) ? $seed['capabilities'] : null;
				$caps_override  = null;
				$caps_bool      = static fn ( string $key ): bool => ! is_array( $caps_seed ) || ! array_key_exists( $key, $caps_seed ) || true === $caps_seed[ $key ];
				$caps_beta_seed = null !== $caps_seed && is_array( $caps_seed['beta_features'] ?? null ) ? $caps_seed['beta_features'] : [];

				if ( null !== $caps_seed ) {
					$caps_override = new CartBridgeJP\Adapters\Capabilities(
						true,                             // can_create_category
						$caps_bool( 'can_create_order' ),
						true,                             // can_fetch_customers
						true,                             // can_update_customer
						$caps_bool( 'can_push_images' ),
						true,                             // can_create_coupon
						true,                             // has_coupons
						true,                             // has_tags
						true,                             // has_reviews
						true,                             // has_variants
						600,
						false,                            // supports_per_variant_stock_management
						$caps_beta_seed
					);
				}

				// R3-0m 検証用: `mapping_candidates` が配列のときだけ mock の `mapping_candidates()` に渡す（配列でなければ既定の空）。
				$candidates_seed = is_array( $seed ) && is_array( $seed['mapping_candidates'] ?? null ) ? $seed['mapping_candidates'] : null;

				// `platform_id` は登録キーと同じ値にする。Importer/Exporter/JobManager は mapping・上限のキーを登録キーではなく
				// `$adapter->id()` から決める（既定の 'mock' のままだと、別キーで登録しても mapping が 'mock' 名前空間へ書かれる）。
				$adapters['__PLATFORM_KEY__'] = new CartBridgeJP\Tests\Fixtures\MockPlatformAdapter(
					customers: $customers,
					orders: $orders,
					push_products_supported: $push_enabled,
					push_others_supported: $push_enabled,
					create_push_failure: $create_push_fail,
					capabilities_override: $caps_override,
					mapping_candidates_override: $candidates_seed,
					platform_id: '__PLATFORM_KEY__'
				);

				return $adapters;
			},
			99
		);

		// 無料版の上限（`cbjp/limits/{entity}`）と Pro 版の案内先（`cbjp/limits/pro_url`）を seed で差し替える（R3-0h の検証用）。
		// **サイト全体（全 platform）に効く**ので、使い終わったら seed のキーを外す（`examples/upsell-notice/cleanup.php`）。
		// 値はフィルターが呼ばれた時点で読む: 同じプロセス内で seed を書き換えても効く（`get_option()` のキャッシュは `update_option()` で更新される）。
		// 型の違う値は読み飛ばして元の値を返す（mu-plugin の fatal を避ける既存方針）。
		$seed_key = static function ( string $key, &$value ): bool {
			$seed = get_option( 'cbjp_verify_seed', [] );

			if ( ! is_array( $seed ) || ! array_key_exists( $key, $seed ) ) {
				return false;
			}

			$value = $seed[ $key ];

			return true;
		};

		foreach ( [ 'category', 'tag', 'product', 'customer', 'order', 'stock', 'coupon', 'review' ] as $entity ) {
			// `limits`: { entity: int|null }。int はその件数、null は Pro 相当（上限なし）。キーが無い・型が違う値は元の上限のまま。
			add_filter(
				"cbjp/limits/{$entity}",
				static function ( $limit ) use ( $entity, $seed_key ) {
					$limits = null;

					if ( ! $seed_key( 'limits', $limits ) || ! is_array( $limits ) || ! array_key_exists( $entity, $limits ) ) {
						return $limit;
					}

					return is_int( $limits[ $entity ] ) || null === $limits[ $entity ] ? $limits[ $entity ] : $limit;
				},
				20
			);
		}

		// `pro_url`: そのまま返す（文字列以外・不正な URL も渡す。`LimitPolicy::pro_url()` の検証を通すため）。キーが無ければ元の値。
		add_filter(
			'cbjp/limits/pro_url',
			static function ( $url ) use ( $seed_key ) {
				$value = null;

				return $seed_key( 'pro_url', $value ) ? $value : $url;
			},
			20
		);
	},
	20
);
