<?php
/**
 * Pro の zip の中身を確かめる（R3-8c。PR #125 G1）。`bin/build-zip.sh --plugin cart-bridge-jp-pro` が、zip にする直前のディレクトリに対して
 * `php bin/check-pro-zip.php <ディレクトリ>` で呼ぶ。WordPress を読み込まない（タグのリリースのジョブでも動く）。
 *
 * 1. 名前空間: PHP のトークンで宣言を読み（改行・コメントを挟んだ宣言も拾い、コメントの中の文は拾わない）、`CartBridgeJP` で始まり
 *    `CartBridgeJP\Pro`（とその下）でないもの〔無料版の名前空間〕を止める。PHP と同じく大文字小文字は区別しない。`CartBridgeJP\Pro` の宣言が
 *    1 つも無いのも止める（検査が壊れた兆候）。
 * 2. リリースの設定: ヘッダーの `Version` と `CBJP_PRO_VERSION` が同じ。版 1.0.0 以上では、`CBJP_PRO_LICENSE_API_URL` の既定値が https で host があり
 *    資格情報を含まない、`CBJP_PRO_LICENSE_PRODUCT_ID` の既定値が正の整数、ヘッダーの `Update URI` が https で接続先と同じ host。
 *    ヘッダーの `Update URI` を入れたら版にかかわらず接続先と合わせる。Pro の `LicenseConfigTest` と同じ規則だが、`pro-v*` のタグのリリースは
 *    CI のテストを通らないので、zip を作るこのスクリプトでも止める。
 *
 * 問題は 1 行ずつ標準出力に出し、あれば 1、引数の誤り・読めないファイル・字句解析できないファイルがあれば 2 で終わる。
 *
 * @package CartBridgeJP
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.PHP.YodaConditions -- WordPress を読み込まない CLI の検査スクリプト。

const CBJP_ZIPCHECK_MAIN_FILE = 'cart-bridge-jp-pro.php';

/**
 * ディレクトリの下の PHP の名前空間の宣言（相対パス => 名前の一覧）。読めない・字句解析できないファイルがあれば例外。
 *
 * @return array<string,array<int,string>>
 * @throws RuntimeException 読めない・字句解析できないファイルがあるとき。
 */
function cbjp_zipcheck_namespaces( string $dir ): array {
	$declarations = [];
	$files        = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $files as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}

		$path = $file->getPathname();
		$code = file_get_contents( $path );

		if ( false === $code ) {
			throw new RuntimeException( "cannot read {$path}" );
		}

		try {
			$tokens = token_get_all( $code, TOKEN_PARSE );
		} catch ( ParseError $error ) {
			throw new RuntimeException( "cannot parse {$path}: " . $error->getMessage() );
		}

		$names = cbjp_zipcheck_namespace_names( $tokens );

		if ( [] !== $names ) {
			$declarations[ substr( $path, strlen( rtrim( $dir, '/' ) ) + 1 ) ] = $names;
		}
	}

	ksort( $declarations );

	return $declarations;
}

/**
 * トークンの並びから、名前空間の宣言の名前を順に返す（`namespace` の後の空白・コメントを読み飛ばす。名前の無い `namespace { }` は数えない）。
 *
 * @param array<int,mixed> $tokens `token_get_all()` の結果。
 * @return array<int,string>
 */
function cbjp_zipcheck_namespace_names( array $tokens ): array {
	$names = [];
	$count = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_NAMESPACE !== $tokens[ $i ][0] ) {
			continue;
		}

		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], [ T_STRING, T_NAME_QUALIFIED ], true ) ) {
				$names[] = $tokens[ $j ][1];
			}

			break;
		}
	}

	return $names;
}

/**
 * 名前空間の宣言の問題（無料版の名前空間・Pro の宣言が 1 つも無い）。
 *
 * @param array<string,array<int,string>> $declarations `cbjp_zipcheck_namespaces()` の結果。
 * @return array<int,string>
 */
function cbjp_zipcheck_namespace_problems( array $declarations ): array {
	$problems = [];
	$pro      = 0;

	foreach ( $declarations as $path => $names ) {
		foreach ( $names as $name ) {
			$lower = strtolower( $name );

			if ( 'cartbridgejp\\pro' === $lower || str_starts_with( $lower, 'cartbridgejp\\pro\\' ) ) {
				++$pro;
			} elseif ( 'cartbridgejp' === $lower || str_starts_with( $lower, 'cartbridgejp\\' ) ) {
				$problems[] = "{$path} declares the free plugin namespace {$name}";
			}
		}
	}

	if ( 0 === $pro ) {
		$problems[] = 'no CartBridgeJP\\Pro namespace declaration was found (the namespace check cannot be trusted)';
	}

	return $problems;
}

/**
 * プラグインヘッダーの値（WP の `get_file_data()` と同じく、先頭 8 KB の `Name: value` の行。無ければ ''）。
 */
function cbjp_zipcheck_header( string $source, string $name ): string {
	$head = substr( $source, 0, 8192 );

	if ( 1 !== preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $head, $match ) ) {
		return '';
	}

	return trim( (string) preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] ) );
}

/**
 * メインファイルの `define( 'NAME', <既定値> )` の値（引用符を外した文字列。無ければ null）。`if ( ! defined() )` の中の既定値も読む。
 */
function cbjp_zipcheck_define( string $source, string $name ): ?string {
	$pattern = "/define\\(\\s*'" . preg_quote( $name, '/' ) . "'\\s*,\\s*('[^']*'|\"[^\"]*\"|[0-9]+)\\s*\\)/";

	return 1 === preg_match( $pattern, $source, $match ) ? trim( $match[1], "'\"" ) : null;
}

/**
 * https で host があり資格情報を含まない URL の host（小文字）。違えば null。
 */
function cbjp_zipcheck_https_host( string $url ): ?string {
	$parts = parse_url( trim( $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress を読み込まない。

	if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || '' === (string) ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return null;
	}

	return strtolower( (string) $parts['host'] );
}

/**
 * リリースの設定の問題（版・接続先・製品 ID・`Update URI`）。
 *
 * @return array<int,string>
 */
function cbjp_zipcheck_release_problems( string $source ): array {
	$problems = [];
	$version  = cbjp_zipcheck_header( $source, 'Version' );
	$constant = cbjp_zipcheck_define( $source, 'CBJP_PRO_VERSION' );

	if ( '' === $version || null === $constant || $version !== $constant ) {
		$problems[] = sprintf( 'the Version header (%s) and CBJP_PRO_VERSION (%s) differ', '' === $version ? 'missing' : $version, $constant ?? 'missing' );
	}

	$api_url    = (string) cbjp_zipcheck_define( $source, 'CBJP_PRO_LICENSE_API_URL' );
	$api_host   = cbjp_zipcheck_https_host( $api_url );
	$product_id = (string) cbjp_zipcheck_define( $source, 'CBJP_PRO_LICENSE_PRODUCT_ID' );
	$update_uri = cbjp_zipcheck_header( $source, 'Update URI' );
	$release    = '' !== $version && version_compare( $version, '1.0.0', '>=' );

	if ( $release && null === $api_host ) {
		$problems[] = 'CBJP_PRO_LICENSE_API_URL must be an https URL of the sales site for a 1.0.0 or later release';
	}

	if ( $release && ( 1 !== preg_match( '/^[1-9][0-9]{0,18}\z/', $product_id ) || (string) (int) $product_id !== $product_id ) ) {
		$problems[] = 'CBJP_PRO_LICENSE_PRODUCT_ID must be a positive integer for a 1.0.0 or later release';
	}

	if ( '' === $update_uri ) {
		if ( $release ) {
			$problems[] = 'the Update URI header is required for a 1.0.0 or later release';
		}
	} elseif ( null === $api_host || cbjp_zipcheck_https_host( $update_uri ) !== $api_host ) {
		$problems[] = 'the Update URI header must be an https URL on the host of CBJP_PRO_LICENSE_API_URL';
	}

	return $problems;
}

/**
 * ディレクトリ（zip の中身）を確かめる。戻り値は終了コード（0: 問題なし、1: 問題あり、2: 確かめられない）。
 */
function cbjp_zipcheck_run( string $dir ): int {
	$main = rtrim( $dir, '/' ) . '/' . CBJP_ZIPCHECK_MAIN_FILE;

	if ( ! is_dir( $dir ) || ! is_readable( $main ) ) {
		echo "cannot read {$main}\n";
		return 2;
	}

	try {
		$declarations = cbjp_zipcheck_namespaces( $dir );
	} catch ( RuntimeException $error ) {
		echo $error->getMessage(), "\n";
		return 2;
	}

	$source = file_get_contents( $main );

	if ( false === $source ) {
		echo "cannot read {$main}\n";
		return 2;
	}

	$problems = array_merge( cbjp_zipcheck_namespace_problems( $declarations ), cbjp_zipcheck_release_problems( $source ) );

	foreach ( $problems as $problem ) {
		echo $problem, "\n";
	}

	return [] === $problems ? 0 : 1;
}

// コマンドラインから直接呼ばれたときだけ走る（テストは関数を読み込んで使う）。
if ( 'cli' === PHP_SAPI && isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	if ( 2 !== count( $argv ) ) {
		echo "usage: php bin/check-pro-zip.php <unzipped plugin directory>\n";
		exit( 2 );
	}

	exit( cbjp_zipcheck_run( $argv[1] ) );
}
