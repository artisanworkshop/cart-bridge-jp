<?php
/**
 * 実測した応答を匿名化して Pro のフィクスチャ（`plugins/cart-bridge-jp-pro/tests/fixtures/api-manager/`）と比べる（`am.sh compare` が呼ぶ）。
 * 引数: site=<dev サイトの URL>
 *
 * 比べるのは、今の状態ファイルの setup で測った応答（`out/<版>/run.json`）だけ。前の setup の応答は、その setup の ID を知らないので
 * 匿名化できない（R1-5）。匿名化した応答は `dist/api-manager/anon/<版>/<フィクスチャ名>.json` に書く（フィクスチャと同じ書式）。フィクスチャは書き換えない
 * （上書きするかは人が差分を見て決める。`cp` で写す）。匿名化した後に手元の値（URL・ID・ハッシュ・キー）が残っていれば書かずに止める。
 * 比べない値（実行時間・日付・期限・版）は anon.php の `cbjp_am_comparable()`。
 * 終了コード: 0 = すべて同じ、1 = 違い・測っていない応答・漏れがある、2 = 使い方の誤り。
 *
 * @package CartBridgeJP
 */

require_once __DIR__ . '/_lib.php';

$opts = cbjp_am_args( $args, [ 'site' ] );
cbjp_am_require_site( $opts );

$state   = cbjp_am_ready_state();
$ctx     = cbjp_am_context( $state );
$version = (string) ( $state['am_version'] ?? '' );

if ( 1 !== preg_match( '/\A[0-9][0-9A-Za-z.-]*\z/', $version ) ) {
	cbjp_am_abort( "the state file has no readable API Manager version ('{$version}')", 1 );
}

$root     = dirname( __DIR__, 4 );
$fixtures = $root . '/plugins/cart-bridge-jp-pro/tests/fixtures/api-manager';
$out_dir  = cbjp_am_out_dir() . '/out/' . $version;
$anon_dir = cbjp_am_out_dir() . '/anon/' . $version;

if ( ! is_dir( $out_dir ) || ! cbjp_am_measured_in_this_setup( $out_dir, $state ) ) {
	cbjp_am_abort( "no measurements of API Manager {$version} from this setup (run: am.sh measure all)", 1 );
}

if ( ! is_dir( $anon_dir ) && ! wp_mkdir_p( $anon_dir ) ) {
	cbjp_am_abort( "could not create {$anon_dir}", 1 );
}

// 前回の匿名化した応答を消してから書く（今回は書かない〔漏れ〕ものが、前回の分として残らないように。R1-5）。
foreach ( (array) glob( $anon_dir . '/*.json' ) as $old ) {
	if ( is_string( $old ) && ! unlink( $old ) ) {
		cbjp_am_abort( "could not remove {$old}", 1 );
	}
}

$problems = 0;

foreach ( cbjp_am_fixture_map() as $fixture => $measured ) {
	$fixture_raw  = file_get_contents( "{$fixtures}/{$fixture}.json" );
	$measured_raw = is_file( "{$out_dir}/{$measured}.json" ) ? file_get_contents( "{$out_dir}/{$measured}.json" ) : false;

	if ( false === $fixture_raw ) {
		echo "MISSING FIXTURE {$fixture}\n";
		++$problems;
		continue;
	}

	if ( false === $measured_raw ) {
		echo "NOT MEASURED    {$fixture} (needs {$measured}.json)\n";
		++$problems;
		continue;
	}

	$measured_data = json_decode( $measured_raw, true );

	if ( ! is_array( $measured_data ) ) {
		echo "NOT JSON        {$fixture} ({$measured}.json)\n";
		++$problems;
		continue;
	}

	$anon  = cbjp_am_anonymize( $measured_data, $ctx );
	$leaks = cbjp_am_leaks( $anon, $ctx );

	if ( [] !== $leaks ) {
		echo "LEAK            {$fixture}: ", implode( '; ', $leaks ), " (not written)\n";
		++$problems;
		continue;
	}

	$json = cbjp_am_fixture_json( $anon );

	if ( null === $json || false === file_put_contents( "{$anon_dir}/{$fixture}.json", $json ) ) {
		cbjp_am_abort( "could not write {$anon_dir}/{$fixture}.json", 1 );
	}

	$diff = cbjp_am_diff( cbjp_am_comparable( json_decode( $fixture_raw, true ) ), cbjp_am_comparable( $anon ) );

	if ( [] === $diff ) {
		echo "same            {$fixture}\n";
		continue;
	}

	echo "DIFFERENT       {$fixture} (from {$measured}.json)\n";

	foreach ( $diff as $line ) {
		echo "                  {$line}\n";
	}

	++$problems;
}

echo "anonymized responses: {$anon_dir}\n";

if ( 0 !== $problems ) {
	echo "compare: {$problems} fixture(s) differ or could not be compared. Read the differences; to adopt a measured response, copy it from {$anon_dir} over the fixture and update the tests and docs/03 §10.7.\n";
	exit( 1 );
}

echo "compare: all fixtures match API Manager {$version}\n";
