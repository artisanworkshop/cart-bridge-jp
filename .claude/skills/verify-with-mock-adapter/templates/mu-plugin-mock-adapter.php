<?php
/**
 * cbjp-verify-mock-adapter: managed by .claude/skills/verify-with-mock-adapter
 *
 * TEMPORARY. 手動検証専用: mock アダプタを「__PLATFORM_KEY__」というキーで `cbjp/adapters/register` に登録する。
 * 検証が終わったら `mock-adapter.sh uninstall` で削除すること（コミットしない）。
 *
 * - mock の中身（顧客・受注）は、オプション `cbjp_verify_seed`（配列。seed スクリプトが保存する）から組み立てる。
 *   形は { customers: [{remote_id,email,pref}], orders: [{number,billing_pref,shipping_pref}] }。
 *   別の形のデータが要るなら、この関数を検証用に書き換えてよい（テンプレートなので）。
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

				foreach ( $seed['customers'] ?? [] as $c ) {
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

				foreach ( $seed['orders'] ?? [] as $o ) {
					$orders[] = new CartBridgeJP\Canonical\CanonicalOrder(
						$o['number'],
						'processing',
						null,
						[],
						$address( (int) $o['shipping_pref'], '1000001', 'Verify 1-1-1' ),
						[],
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

				$adapters['__PLATFORM_KEY__'] = new CartBridgeJP\Tests\Fixtures\MockPlatformAdapter( [], $customers, $orders );

				return $adapters;
			},
			99
		);
	},
	20
);
