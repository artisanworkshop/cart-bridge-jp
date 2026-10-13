<?php
/**
 * 無料版の readme.txt を、リリースする版と照らし合わせる（R3-4a）。`release.yml`（`v*.*.*` のタグ）が GitHub Release の本文を作るときと、
 * `deploy-wporg.yml`（wordpress.org の SVN へ送る手動のワークフロー）が送る前に確かめるときに呼ぶ。WordPress を読み込まない（タグのリリースのジョブでも動く）。
 *
 *   php bin/readme-release.php <readme.txt> <version>
 *
 * 1. 版は `X.Y.Z`（数字だけ）。
 * 2. ヘッダーの `Stable tag` が版と同じ（wordpress.org は Stable tag の版を配る。プラグインヘッダーの Version との一致は `bin/build-zip.sh --version` と `ReadmeTest`）。
 * 3. `== Changelog ==` の節の中に `= <version> =` の見出しがちょうど 1 つあり、本文が空でない（`== Upgrade Notice ==` の同じ見出しは読まない）。
 *
 * 節・節の中の見出し・ヘッダーの区切りは wordpress.org の readme のパーサーと同じ規則で読む（`##` の見出し・行頭の空白・閉じの無い `== X` も節の見出し。
 * 規則が違うと、wordpress.org では別の節にある文を changelog として読み、リリースを通してしまう。R3-4a の review-loop R1-1）。同じ名前のヘッダーが複数あるのも止める。
 *
 * 問題が無ければ Changelog の本文（次の見出しの前まで。前後の空行を除く）を標準出力へ出して 0、問題は 1 行ずつ標準エラーへ出して 1、
 * 引数の誤り・読めないファイルは 2 で終わる。タグのリリースは CI のテストを通らないので、リリースを止める条件はこのスクリプトに置き、`ReadmeTest` も同じ関数で読む。
 *
 * @package CartBridgeJP
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.YodaConditions -- WordPress を読み込まない CLI の検査スクリプト（終了コードは整数）。

/**
 * wordpress.org がヘッダーとして読む名前（`Plugin_Directory\Readme\Parser::$valid_headers`。Plugin Check 2.1.0 の `includes/Lib/Readme/Parser.php` に同梱）。
 */
const CBJP_README_RELEASE_HEADERS = [ 'tested', 'tested up to', 'requires', 'requires at least', 'requires php', 'tags', 'contributors', 'donate link', 'stable tag', 'license', 'license uri' ];

/**
 * readme の行（wordpress.org と同じく、UTF-8 なら Unicode の改行〔`\f`・`\v`・U+0085・U+2028・U+2029 も〕で、そうでなければバイトの改行で分け、
 * 先頭の UTF-8 の BOM を除く）。
 *
 * @return array<int,string>
 */
function cbjp_readme_release_lines( string $readme ): array {
	$lines = 1 === preg_match( '//u', $readme ) ? preg_split( '/\R/u', $readme ) : preg_split( '/\R/', $readme );
	$lines = false === $lines ? [ '' ] : array_map( static fn( string $line ): string => rtrim( $line, "\r\n" ), $lines );

	if ( str_starts_with( $lines[0], "\xEF\xBB\xBF" ) ) {
		$lines[0] = substr( $lines[0], 3 );
	}

	return $lines;
}

/**
 * ヘッダーに見える行なら [ 小文字の名前, 値 ]、そうでなければ null（wordpress.org の `parse_possible_header()` と同じ規則）。
 *
 * @return array{0:string,1:string}|null
 */
function cbjp_readme_release_possible_header( string $line ): ?array {
	if ( ! str_contains( $line, ':' ) || str_starts_with( $line, '#' ) || str_starts_with( $line, '=' ) ) {
		return null;
	}

	[ $name, $value ] = explode( ':', $line, 2 );

	return [ strtolower( trim( $name, " \t*-\r\n" ) ), trim( $value, " \t*-\r\n" ) ];
}

/**
 * 節の見出しか（wordpress.org と同じく、前後の空白を除いた行が `==`、または `##` の後に `#` 以外の文字が続く行。`###`・`##` だけの行は節の中の見出し）。
 */
function cbjp_readme_release_is_section_heading( string $line ): bool {
	$trimmed = trim( $line );

	return str_starts_with( $trimmed, '==' ) || ( str_starts_with( $trimmed, '##' ) && isset( $trimmed[2] ) && '#' !== $trimmed[2] );
}

/**
 * readme をヘッダーと本文に分ける（wordpress.org のパーサーと同じ区切り）。タイトル（最初の空でない行。ヘッダーに見える行でなければ）と、
 * その直後の `===`・空の行を 1 行読み飛ばし、空の行を挟んでヘッダーを読む。ヘッダーに見えない行・空の行の後の知らない名前のヘッダーで終わる
 * （そこからが短い説明と本文）。
 *
 * @return array{0:array<string,array<int,string>>,1:array<int,string>} 名前（小文字）ごとのヘッダーの値の一覧（出てきた順）と、本文の行
 */
function cbjp_readme_release_parse( string $readme ): array {
	$lines = cbjp_readme_release_lines( $readme );
	$count = count( $lines );
	$index = 0;

	while ( $index < $count && '' === trim( $lines[ $index ] ) ) {
		++$index;
	}

	$first = $index < $count ? cbjp_readme_release_possible_header( $lines[ $index ] ) : null;

	if ( $index < $count && ( null === $first || ! in_array( $first[0], CBJP_README_RELEASE_HEADERS, true ) ) ) {
		++$index;

		if ( $index < $count && '' === trim( $lines[ $index ], '=-' ) ) {
			++$index;
		}
	}

	while ( $index < $count && '' === trim( $lines[ $index ] ) ) {
		++$index;
	}

	$headers    = [];
	$last_blank = false;

	for ( ; $index < $count; $index++ ) {
		$header = cbjp_readme_release_possible_header( $lines[ $index ] );

		if ( null === $header ) {
			// wordpress.org の `empty( $line )`（空白だけの行はヘッダーの終わり。`0` だけの行は空の行）。
			if ( in_array( $lines[ $index ], [ '', '0' ], true ) ) {
				$last_blank = true;
				continue;
			}
			break;
		}

		if ( in_array( $header[0], CBJP_README_RELEASE_HEADERS, true ) ) {
			$headers[ $header[0] ][] = $header[1];
		} elseif ( $last_blank ) {
			break;
		}

		$last_blank = false;
	}

	return [ $headers, array_slice( $lines, $index ) ];
}

/**
 * ヘッダーの値（名前は大文字小文字を区別しない）。同じ名前が複数あれば、wordpress.org と同じく最後の値。無ければ null。
 */
function cbjp_readme_release_header( string $readme, string $name ): ?string {
	$values = cbjp_readme_release_parse( $readme )[0][ strtolower( $name ) ] ?? [];

	return [] === $values ? null : $values[ count( $values ) - 1 ];
}

/**
 * Changelog の節（`== Changelog ==`・`## Changelog`。別名 `Change log`）の中の、見出しが版と同じ節の本文の一覧（出てきた順。前後の空行を除く）。
 * 節の中の見出しは `=`・`#` で始まる行（wordpress.org が節の本文を見出しで分ける規則）で、`= 1.0.0 =` も `### 1.0.0` も `1.0.0` と読む。
 *
 * @return array<int,string>
 */
function cbjp_readme_release_changelog_entries( string $readme, string $version ): array {
	$section = '';
	$entries = [];
	$body    = null;

	foreach ( cbjp_readme_release_parse( $readme )[1] as $line ) {
		$trimmed    = trim( $line );
		$is_section = cbjp_readme_release_is_section_heading( $line );
		$is_heading = $is_section || ( 'changelog' === $section && ( str_starts_with( $trimmed, '=' ) || str_starts_with( $trimmed, '#' ) ) );

		if ( $is_heading && null !== $body ) {
			$entries[] = trim( implode( "\n", $body ), "\n" );
			$body      = null;
		}

		if ( $is_section ) {
			$section = strtolower( str_replace( ' ', '_', trim( $line, "#= \t" ) ) );
			$section = 'change_log' === $section ? 'changelog' : $section;
			continue;
		}

		if ( $is_heading ) {
			if ( trim( $trimmed, $trimmed[0] . " \t" ) === $version ) {
				$body = [];
			}
			continue;
		}

		if ( null !== $body ) {
			$body[] = rtrim( $line );
		}
	}

	if ( null !== $body ) {
		$entries[] = trim( implode( "\n", $body ), "\n" );
	}

	return $entries;
}

/**
 * Changelog の版の節の本文。節がちょうど 1 つで本文が空でなければその本文、そうでなければ null。
 */
function cbjp_readme_release_changelog( string $readme, string $version ): ?string {
	$entries = cbjp_readme_release_changelog_entries( $readme, $version );

	return 1 === count( $entries ) && '' !== trim( $entries[0] ) ? $entries[0] : null;
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

	$problems    = [];
	$stable_tags = cbjp_readme_release_parse( $readme )[0]['stable tag'] ?? [];

	if ( [] === $stable_tags ) {
		$problems[] = 'the readme has no Stable tag header';
	} elseif ( 1 < count( $stable_tags ) ) {
		$problems[] = 'the readme has more than one Stable tag header';
	} elseif ( $stable_tags[0] !== $version ) {
		$problems[] = "the readme's Stable tag is {$stable_tags[0]}, not {$version}";
	}

	$entries = cbjp_readme_release_changelog_entries( $readme, $version );

	if ( [] === $entries ) {
		$problems[] = "the readme's Changelog has no entry '= {$version} ='";
	} elseif ( 1 < count( $entries ) ) {
		$problems[] = "the readme's Changelog has more than one entry '= {$version} ='";
	} elseif ( '' === trim( $entries[0] ) ) {
		$problems[] = "the readme's Changelog entry '= {$version} =' is empty";
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
