<?php
/**
 * measure-api-manager の匿名化・比較（php/anon.php）の回帰テスト。WordPress・wp-env・ネットワーク不要（ホストの php で走る）。
 * 使い方（リポジトリルートから）: php .claude/skills/measure-api-manager/php/test-anon.php
 *
 * Pro のフィクスチャ（tests/fixtures/api-manager/）を「dev サイトで測った応答」の形に戻し（手元の URL・ID・ハッシュ）、
 * 匿名化してフィクスチャと 1 文字も違わないことを確かめる（往復）。匿名化の規則を 1 つ外すと、その規則が効くフィクスチャで落ちる。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/anon.php';

$root     = dirname( __DIR__, 4 );
$fixtures = $root . '/plugins/cart-bridge-jp-pro/tests/fixtures/api-manager';
$fails    = 0;

$ok = static function ( string $label ): void {
	echo "ok   {$label}\n";
};

$fail = static function ( string $label, string $detail = '' ) use ( &$fails ): void {
	echo "FAIL {$label}" . ( '' === $detail ? '' : ": {$detail}" ) . "\n";
	++$fails;
};

$check = static function ( bool $condition, string $label, string $detail = '' ) use ( $ok, $fail ): void {
	if ( $condition ) {
		$ok( $label );
	} else {
		$fail( $label, $detail );
	}
};

if ( ! is_dir( $fixtures ) ) {
	$fail( 'fixtures directory', $fixtures );
	exit( 1 );
}

// dev サイトの値（手元の値）。リソース ID はフィクスチャ（1・2）と違う値にして、置き換えを素通りしないようにする。
$ctx = cbjp_am_anon_context(
	[
		'site_url'     => 'http://localhost:10010',
		'product_id'   => 867,
		'order_ids'    => [ 870, 871 ],
		'resource_ids' => [ 5, 6 ],
		'user_id'      => 116,
		'secrets'      => [ str_repeat( 'k1', 16 ), str_repeat( 'a1', 16 ) ],
	]
);

if ( null === $ctx ) {
	$fail( 'context: a valid context is accepted' );
	exit( 1 );
}

$ok( 'context: a valid context is accepted' );

/**
 * フィクスチャの値を、dev サイトで測ったときの値に戻す（匿名化の逆）。
 *
 * @param mixed $value
 * @return mixed
 */
function cbjp_am_test_localize( $value, string $key = '' ) {
	if ( is_array( $value ) ) {
		$out = [];

		foreach ( $value as $k => $v ) {
			$out[ $k ] = cbjp_am_test_localize( $v, (string) $k );
		}

		return $out;
	}

	if ( ! is_string( $value ) ) {
		return $value;
	}

	$ids = [
		'product_id'      => [
			'1000' => '867',
			'2000' => '99999',
		],
		'order_id'        => [
			'1001' => '870',
			'1002' => '871',
		],
		'api_resource_id' => [
			'1' => '5',
			'2' => '6',
		],
		'user_id'         => [ '1' => '116' ],
	];

	if ( isset( $ids[ $key ] ) ) {
		return $ids[ $key ][ $value ] ?? $value;
	}

	$value = str_replace( 'https://example.com', 'http://localhost:10010', $value );
	$value = (string) preg_replace( '/([?&]user_id=)1(?![0-9])/', '${1}116', $value );
	$value = (string) preg_replace( '/([?&]am_download_file=)1000/', '${1}867', $value );
	$value = (string) preg_replace( '/([?&]am_order=)1001/', '${1}870', $value );
	$value = (string) preg_replace( '/([?&]hname=)x+/', '${1}3f9a1c0d2b4e', $value );
	$value = (string) preg_replace( '/([?&]hkey=)x+/', '${1}8d1e6f0a9b2c4d7e', $value );
	$value = str_replace( 'cart-bridge-jp-pro-1000', 'cart-bridge-jp-pro-867', $value );

	return str_replace( 'product ID 2000', 'product ID 99999', $value );
}

// 1. 対応表とフィクスチャの一覧が一致する（フィクスチャを足したら対応表にも足す）。
$files  = glob( $fixtures . '/*.json' );
$names  = array_map( static fn( string $f ): string => basename( $f, '.json' ), false === $files ? [] : $files );
$mapped = array_keys( cbjp_am_fixture_map() );
sort( $names );
sort( $mapped );
$check( $names === $mapped, 'map: every fixture has a measurement and every mapped fixture exists', 'fixtures=' . implode( ',', $names ) . ' map=' . implode( ',', $mapped ) );

// 2. 往復: 手元の値に戻したフィクスチャを匿名化すると、元のフィクスチャと同じ文字列になり、手元の値が残らない。
$localized_any = false;

foreach ( cbjp_am_fixture_map() as $fixture => $measured ) {
	$path = "{$fixtures}/{$fixture}.json";
	$raw  = is_file( $path ) ? file_get_contents( $path ) : false;

	if ( false === $raw ) {
		$fail( "round trip {$fixture}", 'cannot read the fixture' );
		continue;
	}

	$data      = json_decode( $raw, true );
	$localized = cbjp_am_test_localize( $data );

	if ( $localized !== $data ) {
		$localized_any = true;
	}

	$anon = cbjp_am_anonymize( $localized, $ctx );
	$json = cbjp_am_fixture_json( $anon );
	$check( $json === $raw, "round trip {$fixture}: anonymizing the local response gives the fixture", implode( '; ', cbjp_am_diff( $data, $anon ) ) );
	$check( [] === cbjp_am_leaks( $anon, $ctx ), "round trip {$fixture}: no local values remain", implode( '; ', cbjp_am_leaks( $anon, $ctx ) ) );
}

$check( $localized_any, 'round trip: the localized fixtures differ from the fixtures (the test is not a tautology)' );

// 3. 漏れの検出: 匿名化していない応答は、手元の URL・ID・ハッシュ・秘密を報告する。
$raw_update = [
	'data' => [
		'package' => [
			'id'      => 'cart-bridge-jp-pro-867',
			'url'     => 'http://localhost:10010/cart-bridge-jp-pro/',
			'package' => 'http://localhost:10010/?user_id=116&am_download_file=867&am_order=870&hname=3f9a1c0d2b4e&hkey=8d1e6f0a9b2c4d7e&hexpires=1792360276',
			'echo'    => 'key ' . str_repeat( 'k1', 16 ),
		],
	],
];
$leaks      = implode( "\n", cbjp_am_leaks( $raw_update, $ctx ) );

foreach ( [ 'the local site address', 'a key or instance', 'a download hash', 'a local ID (867)', 'a local ID (116)', 'a local ID (870)' ] as $expected ) {
	$check( str_contains( $leaks, $expected ), "leaks: reports {$expected}", $leaks );
}

$anon_update = cbjp_am_anonymize( $raw_update, $ctx );
$check( [] === cbjp_am_leaks( $anon_update, $ctx ), 'leaks: nothing remains after anonymizing', implode( '; ', cbjp_am_leaks( $anon_update, $ctx ) ) );
$check( 'key <redacted>' === $anon_update['data']['package']['echo'], 'anonymize: secrets are redacted', (string) $anon_update['data']['package']['echo'] );
$check(
	'https://example.com/?user_id=1&am_download_file=1000&am_order=1001&hname=xxxxxxxxxxxx&hkey=xxxxxxxxxxxxxxxx&hexpires=1792360276' === $anon_update['data']['package']['package'],
	'anonymize: the download URL',
	(string) $anon_update['data']['package']['package']
);

// ID の欄の、知らない値はフィクスチャに残さない（0）。2 件目の注文は 1002。
$ids = cbjp_am_anonymize(
	[
		'order_id'        => '871',
		'product_id'      => '555',
		'api_resource_id' => 6,
		'user_id'         => '7',
	],
	$ctx
);
$check(
	[
		'order_id'        => '1002',
		'product_id'      => '0',
		'api_resource_id' => 2,
		'user_id'         => '0',
	] === $ids,
	'anonymize: ID fields map known IDs and blank unknown ones',
	json_encode( $ids )
);

// dev サイトの ID が偶然フィクスチャの値と同じでも、漏れとは報告しない。
$same = cbjp_am_anon_context( array_merge( $ctx, [ 'product_id' => 1000 ] ) );
$check( null !== $same && [] === cbjp_am_leaks( [ 'id' => 'cart-bridge-jp-pro-1000' ], $same ), 'leaks: an ID equal to its fixture value is not a leak' );

// 4. 文脈の検証: 形の違う値は受けない（匿名化しないで止める）。
$bad = [
	'site_url without scheme' => [ 'site_url' => 'localhost:10010' ],
	'site_url with a path'    => [ 'site_url' => 'http://localhost:10010/wp' ],
	'product_id as string'    => [ 'product_id' => '867' ],
	'empty order_ids'         => [ 'order_ids' => [] ],
	'zero resource id'        => [ 'resource_ids' => [ 0 ] ],
	'short secret'            => [ 'secrets' => [ 'abc' ] ],
	'secrets not a list'      => [ 'secrets' => [ 'a' => str_repeat( 'k', 32 ) ] ],
];

foreach ( $bad as $label => $override ) {
	$check( null === cbjp_am_anon_context( array_merge( $ctx, $override ) ), "context: rejects {$label}" );
}

$check( null === cbjp_am_anon_context( 'not an array' ), 'context: rejects a non-array' );

// 5. 比べない値: 実行時間・日付・ダウンロードの期限・版。「Expired」のような日付でない文は比べる。
$a = cbjp_am_comparable(
	[
		'api_call_execution_time' => '0.01 seconds',
		'next_payment'            => 'Renewable until: October 24, 2026',
		'friendly'                => 'January 9, 2027',
		'package'                 => 'https://example.com/?hexpires=1792360276&remote_url=no',
		'new_version'             => '0.1.1',
		'expired'                 => 'Expired',
	]
);
$b = cbjp_am_comparable(
	[
		'api_call_execution_time' => '0.02 seconds',
		'next_payment'            => 'Renewable until: March 1, 2028',
		'friendly'                => 'May 30, 2030',
		'package'                 => 'https://example.com/?hexpires=1800000000&remote_url=no',
		'new_version'             => '0.2.0',
		'expired'                 => 'Expired',
	]
);
$check( [] === cbjp_am_diff( $a, $b ), 'comparable: volatile values are not compared', implode( '; ', cbjp_am_diff( $a, $b ) ) );
$check( [] !== cbjp_am_diff( cbjp_am_comparable( [ 'x' => 'Expired' ] ), cbjp_am_comparable( [ 'x' => 'January 9, 2027' ] ) ), 'comparable: "Expired" and a date still differ' );

// 6. 差分: 片方だけのキーと値の違いを報告し、キーの順序は比べない。
$diff = cbjp_am_diff(
	[
		'a' => 1,
		'b' => [ 'c' => 'x' ],
		'd' => true,
	],
	[
		'b' => [ 'c' => 'y' ],
		'a' => 1,
		'e' => false,
	]
);
$check(
	[ 'b.c: fixture="x" measured="y"', 'd: only in the fixture (true)', 'e: only in the measured response (false)' ] === $diff,
	'diff: reports changed values and one-sided keys',
	implode( '; ', $diff )
);
$check(
	[] === cbjp_am_diff(
		[
			'a' => 1,
			'b' => 2,
		],
		[
			'b' => 2,
			'a' => 1,
		]
	),
	'diff: key order is not compared'
);

if ( 0 !== $fails ) {
	echo "test-anon: {$fails} failure(s)\n";
	exit( 1 );
}

echo "test-anon: all passed\n";
