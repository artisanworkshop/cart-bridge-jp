<?php
/**
 * 無料版の readme.txt を、リリースする版と照らし合わせる（R3-4a）。`release.yml`（`v*.*.*` のタグ）が GitHub Release の本文を作るときと、
 * `deploy-wporg.yml`（wordpress.org の SVN へ送る手動のワークフロー）が送る前に確かめるときに呼ぶ。WordPress を読み込まない（タグのリリースのジョブでも動く）。
 *
 *   php bin/readme-release.php <readme.txt> <version>
 *
 * 1. 版は `X.Y.Z`（数字だけ）。
 * 2. ヘッダーの `Stable tag` が版と同じ（wordpress.org は Stable tag の版を配る。プラグインヘッダーの Version との一致は `bin/build-zip.sh --version` と `ReadmeTest`）。
 * 3. `== Changelog ==` の節の中に `= <version> =` の見出しがあり、本文が空でない（`== Upgrade Notice ==` の同じ見出しは読まない）。
 *
 * 問題が無ければ Changelog の本文（次の `= … =`・`== … ==` の見出しの前まで。前後の空行を除く）を標準出力へ出して 0、問題は 1 行ずつ標準エラーへ出して 1、
 * 引数の誤り・読めないファイルは 2 で終わる。タグのリリースは CI のテストを通らないので、リリースを止める条件はこのスクリプトに置き、`ReadmeTest` も同じ関数で読む。
 *
 * @package CartBridgeJP
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.YodaConditions -- WordPress を読み込まない CLI の検査スクリプト（終了コードは整数）。

/**
 * readme の改行を LF にそろえる。
 */
function cbjp_readme_release_normalize( string $readme ): string {
	return str_replace( [ "\r\n", "\r" ], "\n", $readme );
}

/**
 * 節の見出し（`== Name ==`）なら名前、そうでなければ null。
 */
function cbjp_readme_release_section_name( string $line ): ?string {
	if ( 1 === preg_match( '/^==\s*([^=].*?)\s*==\s*$/', $line, $match ) ) {
		return $match[1];
	}

	return null;
}

/**
 * 節の中の見出し（`= Name =`。`==` で始まる行は除く）なら名前、そうでなければ null。
 */
function cbjp_readme_release_subsection_name( string $line ): ?string {
	if ( 1 === preg_match( '/^=\s*([^=].*?)\s*=\s*$/', $line, $match ) ) {
		return $match[1];
	}

	return null;
}

/**
 * ヘッダーの値（タイトルの行から最初の節の見出しまでの `Name: value` の行。名前は大文字小文字を区別しない）。無ければ null。
 */
function cbjp_readme_release_header( string $readme, string $name ): ?string {
	foreach ( explode( "\n", cbjp_readme_release_normalize( $readme ) ) as $line ) {
		if ( null !== cbjp_readme_release_section_name( $line ) ) {
			break;
		}

		if ( 1 === preg_match( '/^' . preg_quote( $name, '/' ) . ':(.*)$/i', $line, $match ) ) {
			return trim( $match[1] );
		}
	}

	return null;
}

/**
 * `== Changelog ==` の節の `= <version> =` の本文（前後の空行を除く）。見出しが無い・本文が空なら null。
 */
function cbjp_readme_release_changelog( string $readme, string $version ): ?string {
	$section = null;
	$body    = null;

	foreach ( explode( "\n", cbjp_readme_release_normalize( $readme ) ) as $line ) {
		$section_name = cbjp_readme_release_section_name( $line );

		if ( null !== $section_name ) {
			if ( null !== $body ) {
				break;
			}

			$section = strtolower( $section_name );
			continue;
		}

		if ( 'changelog' !== $section ) {
			continue;
		}

		$subsection = cbjp_readme_release_subsection_name( $line );

		if ( null !== $subsection ) {
			if ( null !== $body ) {
				break;
			}

			if ( $subsection === $version ) {
				$body = [];
			}
			continue;
		}

		if ( null !== $body ) {
			$body[] = rtrim( $line );
		}
	}

	if ( null === $body ) {
		return null;
	}

	$text = trim( implode( "\n", $body ), "\n" );

	return '' === trim( $text ) ? null : $text;
}

/**
 * 版をリリースできない理由の一覧（空ならリリースできる）。
 *
 * @return array<int,string>
 */
function cbjp_readme_release_problems( string $readme, string $version ): array {
	if ( 1 !== preg_match( '/^\d+\.\d+\.\d+\z/', $version ) ) {
		return [ "invalid version '{$version}' (expected X.Y.Z)" ];
	}

	$problems   = [];
	$stable_tag = cbjp_readme_release_header( $readme, 'Stable tag' );

	if ( $stable_tag !== $version ) {
		$problems[] = null === $stable_tag
			? 'the readme has no Stable tag header'
			: "the readme's Stable tag is {$stable_tag}, not {$version}";
	}

	if ( null === cbjp_readme_release_changelog( $readme, $version ) ) {
		$problems[] = "the readme's Changelog has no entry '= {$version} =' (or the entry is empty)";
	}

	return $problems;
}

/**
 * readme を確かめ、Changelog の本文を `$out` へ、問題を `$err` へ書く。戻り値は終了コード（0: 問題なし、1: 問題あり、2: 読めない）。
 *
 * @param string   $path    readme.txt のパス。
 * @param string   $version リリースする版。
 * @param resource $out     本文の出力先。
 * @param resource $err     問題の出力先。
 */
function cbjp_readme_release_run( string $path, string $version, $out, $err ): int {
	$readme = is_file( $path ) && is_readable( $path ) ? file_get_contents( $path ) : false;

	if ( false === $readme ) {
		fwrite( $err, "cannot read {$path}\n" );
		return 2;
	}

	$problems = cbjp_readme_release_problems( $readme, $version );

	foreach ( $problems as $problem ) {
		fwrite( $err, "readme-release: {$problem}\n" );
	}

	if ( [] !== $problems ) {
		return 1;
	}

	fwrite( $out, cbjp_readme_release_changelog( $readme, $version ) . "\n" );

	return 0;
}

// コマンドラインから直接呼ばれたときだけ走る（テストは関数を読み込んで使う）。
if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	if ( 3 !== count( $argv ) ) {
		fwrite( STDERR, "usage: php bin/readme-release.php <readme.txt> <version>\n" );
		exit( 2 );
	}

	exit( cbjp_readme_release_run( $argv[1], $argv[2], STDOUT, STDERR ) );
}
