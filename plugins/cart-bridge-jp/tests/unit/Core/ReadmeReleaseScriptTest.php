<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use WP_UnitTestCase;

/**
 * readme とリリースする版の照合（`bin/readme-release.php`。R3-4a）。`release.yml` が GitHub Release の本文を readme の Changelog の節から作り、
 * `deploy-wporg.yml` が wordpress.org へ送る前に同じ検査を通す。タグのリリースは CI のテストを通らないので、版の Stable tag と changelog の
 * 食い違いはこのスクリプトが止める。スクリプトはルートの `bin/`（テストのサイトでは `wp-content/cbjp-dev`）にある。
 */
final class ReadmeReleaseScriptTest extends WP_UnitTestCase {

	private const README = <<<'README'
=== Example Plugin ===
Contributors: example
Stable tag: 2.0.0
License: GPLv2 or later

Short description.

== Description ==

Description text.

= 3.0.0 =
Not a changelog entry.

== Changelog ==

= 2.0.0 =
* Two.
* Two again.

= 1.0.0 =
* One.

== Upgrade Notice ==

= 2.0.0 =
Upgrade to two.

= 3.0.0 =
Not a changelog entry either.
README;

	/** @var array<int,string> */
	private array $files = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once WP_CONTENT_DIR . '/cbjp-dev/bin/readme-release.php';
	}

	public function tear_down(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
		}
		parent::tear_down();
	}

	/**
	 * 同梱の readme の 1.0.0 の節（R3-3・R3-6d で用意した 7 項目）。Upgrade Notice の同じ版の文を含まない。
	 * 1.0.0 は公開せず 1.0.1 が wordpress.org の最初の版になったので、「First release on WordPress.org」は 1.0.1 の節の 1 行目にある（PR #146）。
	 */
	public function test_extracts_the_1_0_0_entry_of_the_readme(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- リポジトリ内のテキストファイルを読む（JSON ではない）。
		$readme = (string) file_get_contents( CBJP_PATH . 'readme.txt' );
		$entry  = cbjp_readme_release_changelog( $readme, '1.0.0' );

		$this->assertIsString( $entry );
		$lines = explode( "\n", $entry );
		$this->assertCount( 7, $lines );
		$this->assertStringStartsWith( '* Import categories, groups, products, and stock from Color Me Shop', $lines[0] );
		$this->assertStringNotContainsString( 'First release on WordPress.org', $entry );
		$this->assertStringStartsWith( '* First release on WordPress.org. ', (string) cbjp_readme_release_changelog( $readme, '1.0.1' ) );

		foreach ( $lines as $line ) {
			$this->assertStringStartsWith( '* ', $line );
		}

		$this->assertStringNotContainsString( '(the Cart Bridge JP Pro add-on does)', $entry, 'Upgrade Notice の文を読んでいる' );
	}

	/**
	 * 節は次の版の見出しか次の `== … ==` の前まで。前後の空行は除く。
	 */
	public function test_an_entry_ends_at_the_next_entry_or_section(): void {
		$this->assertSame( "* Two.\n* Two again.", cbjp_readme_release_changelog( self::README, '2.0.0' ) );
		$this->assertSame( '* One.', cbjp_readme_release_changelog( self::README, '1.0.0' ) );

		// 次の節が 2 つ目の `== Changelog ==` でも、その本文を足さない。
		$repeated = str_replace( '== Upgrade Notice ==', "== Changelog ==\n\nStray text.\n\n== Upgrade Notice ==", self::README );
		$this->assertSame( '* One.', cbjp_readme_release_changelog( $repeated, '1.0.0' ) );
	}

	/**
	 * Changelog の外（Description・Upgrade Notice）の同じ見出しは changelog の節ではない。
	 */
	public function test_reads_entries_only_inside_the_changelog(): void {
		$this->assertNull( cbjp_readme_release_changelog( self::README, '3.0.0' ) );

		$without_changelog = (string) preg_replace( '/== Changelog ==.*?(?=== Upgrade Notice ==)/s', '', self::README );
		$this->assertNull( cbjp_readme_release_changelog( $without_changelog, '2.0.0' ), 'Upgrade Notice の 2.0.0 を changelog として読んでいる' );
	}

	public function test_a_missing_or_empty_entry_is_null(): void {
		$this->assertNull( cbjp_readme_release_changelog( self::README, '4.0.0' ) );

		$empty = str_replace( "= 2.0.0 =\n* Two.\n* Two again.\n", "= 2.0.0 =\n  \n", self::README );
		$this->assertNull( cbjp_readme_release_changelog( $empty, '2.0.0' ) );

		$last = str_replace( "* One.\n", '', self::README );
		$this->assertNull( cbjp_readme_release_changelog( $last, '1.0.0' ), '節の最後の版の本文が空' );

		$this->assertSame(
			[ "the readme's Changelog entry '= 2.0.0 =' is empty" ],
			cbjp_readme_release_problems( $empty, '2.0.0' )
		);
	}

	/**
	 * 節の見出しは wordpress.org のパーサーと同じく、前後の空白を除いた行が `==` か `##`（`###` 以外）で始まる行。閉じの無い見出し・`##`・
	 * 行頭の空白・`===` も節の見出しで、その後の Upgrade Notice の見出しを changelog の節として読まない（R1-1: 以前は読み、リリースを通した）。
	 */
	public function test_section_headings_follow_the_wordpress_org_parser(): void {
		foreach ( [ ' == Upgrade Notice ==', '== Upgrade Notice', '## Upgrade Notice', '=== Upgrade Notice ===', "\t==Upgrade Notice==  " ] as $heading ) {
			$readme = str_replace( '== Upgrade Notice ==', $heading, self::README );
			$label  = (string) wp_json_encode( $heading );

			$this->assertSame( '* One.', cbjp_readme_release_changelog( $readme, '1.0.0' ), $label );
			$this->assertSame( [], cbjp_readme_release_changelog_entries( $readme, '3.0.0' ), $label );
			$this->assertSame( [], cbjp_readme_release_problems( $readme, '2.0.0' ), $label . '（Upgrade Notice の 2.0.0 を 2 つ目の節と読む）' );
		}

		// `###` は節の中の見出し（wordpress.org は節を分けない）。
		$nested = str_replace( '== Upgrade Notice ==', '### Upgrade Notice', self::README );
		$this->assertSame( '* One.', cbjp_readme_release_changelog( $nested, '1.0.0' ) );
	}

	/**
	 * Markdown の書き方（`## Changelog` と `### 2.0.0`）・別名の `Change log` も読む。
	 */
	public function test_reads_markdown_headings_and_the_change_log_alias(): void {
		$markdown = str_replace( [ '== Changelog ==', '= 2.0.0 =', '= 1.0.0 =' ], [ '## Changelog', '### 2.0.0', '#### 1.0.0' ], self::README );
		$this->assertSame( "* Two.\n* Two again.", cbjp_readme_release_changelog( $markdown, '2.0.0' ) );
		$this->assertSame( '* One.', cbjp_readme_release_changelog( $markdown, '1.0.0' ) );

		$alias = str_replace( '== Changelog ==', '== Change log ==', self::README );
		$this->assertSame( '* One.', cbjp_readme_release_changelog( $alias, '1.0.0' ) );
	}

	/**
	 * 同じ版の節が 2 つあると、どちらが本文か決められない（wordpress.org は両方を表示する）。
	 */
	public function test_a_duplicated_entry_stops_the_release(): void {
		$duplicated = str_replace( "= 1.0.0 =\n* One.\n", "= 2.0.0 =\n* Two once more.\n\n= 1.0.0 =\n* One.\n", self::README );

		$this->assertCount( 2, cbjp_readme_release_changelog_entries( $duplicated, '2.0.0' ) );
		$this->assertNull( cbjp_readme_release_changelog( $duplicated, '2.0.0' ) );
		$this->assertSame(
			[ "the readme's Changelog has more than one entry '= 2.0.0 ='" ],
			cbjp_readme_release_problems( $duplicated, '2.0.0' )
		);
	}

	/**
	 * 見出しの版は文字どおりに比べる（`.` を任意の 1 文字・前方一致として扱わない）。
	 */
	public function test_matches_the_version_exactly(): void {
		$this->assertNull( cbjp_readme_release_changelog( self::README, '2x0x0' ) );
		$this->assertNull( cbjp_readme_release_changelog( self::README, '2.0' ) );
		$this->assertNull( cbjp_readme_release_changelog( str_replace( '= 2.0.0 =', '= 12.0.0 =', self::README ), '2.0.0' ) );
		$this->assertSame( '* One.', cbjp_readme_release_changelog( self::README, '1.0.0' ) );
	}

	/**
	 * CRLF と CR だけの改行も LF と同じに読む（本文は LF で出す）。
	 */
	public function test_reads_crlf_and_cr_line_endings(): void {
		foreach ( [ "\r\n", "\r" ] as $newline ) {
			$readme = str_replace( "\n", $newline, self::README );
			$label  = (string) wp_json_encode( $newline );

			$this->assertSame( "* Two.\n* Two again.", cbjp_readme_release_changelog( $readme, '2.0.0' ), $label );
			$this->assertSame( '2.0.0', cbjp_readme_release_header( $readme, 'Stable tag' ), $label );
			$this->assertSame( [], cbjp_readme_release_problems( $readme, '2.0.0' ), $label );
		}
	}

	/**
	 * ヘッダーは wordpress.org と同じく、タイトルの後から短い説明の前まで（短い説明・節の中の `Stable tag:` の行をヘッダーと読まない）。名前の大文字小文字は区別しない。
	 */
	public function test_reads_the_stable_tag_from_the_header_only(): void {
		$this->assertSame( '2.0.0', cbjp_readme_release_header( self::README, 'Stable tag' ) );
		$this->assertSame( '2.0.0', cbjp_readme_release_header( str_replace( 'Stable tag:', 'Stable Tag :', self::README ), 'Stable tag' ) );
		$this->assertSame( '2.0.0', cbjp_readme_release_header( "\xEF\xBB\xBF" . self::README, 'Stable tag' ), 'BOM' );

		// タイトルの行は省ける（wordpress.org は最初の行がヘッダーならタイトル無しと読む）。BOM が付いていても最初のヘッダーを読む。
		$untitled = str_replace( "=== Example Plugin ===\nContributors: example\n", '', self::README );
		$this->assertSame( '2.0.0', cbjp_readme_release_header( $untitled, 'Stable tag' ), 'タイトル無し' );
		$this->assertSame( '2.0.0', cbjp_readme_release_header( "\xEF\xBB\xBF" . $untitled, 'Stable tag' ), 'タイトル無し・BOM' );
		$this->assertSame( '2.0.0', cbjp_readme_release_header( str_replace( "===\nContributors", "===\n\nContributors", self::README ), 'Stable tag' ), 'タイトルの後の空行' );

		$in_section = str_replace( [ "Stable tag: 2.0.0\n", "Description text.\n" ], [ '', "Stable tag: 2.0.0\n" ], self::README );
		$this->assertNull( cbjp_readme_release_header( $in_section, 'Stable tag' ) );

		$after_short_description = str_replace( [ "Stable tag: 2.0.0\n", "Short description.\n" ], [ '', "Short description.\nStable tag: 2.0.0\n" ], self::README );
		$this->assertNull( cbjp_readme_release_header( $after_short_description, 'Stable tag' ) );
		$this->assertSame( [ 'the readme has no Stable tag header' ], cbjp_readme_release_problems( $after_short_description, '2.0.0' ) );
	}

	/**
	 * ヘッダーの終わりも wordpress.org と同じ（R2-1）: 空の行の後の知らない名前の行（コロンを含む短い説明）と、空白だけの行で終わる。
	 * `0` だけの行は空の行として読み進める（`empty()`）。
	 */
	public function test_the_header_block_ends_like_wordpress_org(): void {
		$after_colon = str_replace( [ "Stable tag: 2.0.0\n", "Short description.\n" ], [ '', "Migrate products: import and export.\nStable tag: 2.0.0\n" ], self::README );
		$this->assertNull( cbjp_readme_release_header( $after_colon, 'Stable tag' ) );
		$this->assertSame( [ 'the readme has no Stable tag header' ], cbjp_readme_release_problems( $after_colon, '2.0.0' ) );

		$after_spaces = str_replace( "Stable tag: 2.0.0\nLicense: GPLv2 or later\n", "License: GPLv2 or later\n   \nStable tag: 2.0.0\n", self::README );
		$this->assertNull( cbjp_readme_release_header( $after_spaces, 'Stable tag' ) );

		$after_zero = str_replace( "Stable tag: 2.0.0\n", "Stable tag: 2.0.0\n0\nStable tag: 1.0.0\n", self::README );
		$this->assertSame( [ 'the readme has more than one Stable tag header' ], cbjp_readme_release_problems( $after_zero, '2.0.0' ) );
	}

	/**
	 * 行の区切りも wordpress.org と同じ（R2-2）: UTF-8 なら Unicode の改行（`\f`・`\v`・U+0085・U+2028・U+2029）も区切り。`##` だけの行は節の見出しではない。
	 */
	public function test_line_breaks_and_bare_markers_follow_wordpress_org(): void {
		foreach ( [ "\f", "\v", "\u{85}", "\u{2028}", "\u{2029}" ] as $separator ) {
			$readme = str_replace( "* One.\n\n== Upgrade Notice ==", "* One.{$separator}== Upgrade Notice ==", self::README );
			$label  = (string) wp_json_encode( $separator );

			$this->assertSame( '* One.', cbjp_readme_release_changelog( $readme, '1.0.0' ), $label );
			$this->assertSame( [], cbjp_readme_release_changelog_entries( $readme, '3.0.0' ), $label );
		}

		// UTF-8 でない readme もバイトの改行で読む。
		$latin1 = str_replace( 'Description text.', "Description text \xE9.", self::README );
		$this->assertSame( [], cbjp_readme_release_problems( $latin1, '2.0.0' ) );

		// `##` だけの行は節の中の見出しで、Changelog の後の版も Changelog のまま読む。
		$bare = str_replace( "= 1.0.0 =\n", "##\n\n= 1.0.0 =\n", self::README );
		$this->assertSame( '* One.', cbjp_readme_release_changelog( $bare, '1.0.0' ) );
	}

	/**
	 * 同じヘッダーが 2 つあると wordpress.org は後の値を使う。どちらを配るつもりか分からないので止める。
	 */
	public function test_a_duplicated_stable_tag_stops_the_release(): void {
		$duplicated = str_replace( "Stable tag: 2.0.0\n", "Stable tag: 2.0.0\nStable tag: 1.0.0\n", self::README );

		$this->assertSame( '1.0.0', cbjp_readme_release_header( $duplicated, 'Stable tag' ) );
		$this->assertSame( [ 'the readme has more than one Stable tag header' ], cbjp_readme_release_problems( $duplicated, '2.0.0' ) );
	}

	public function test_problems_name_what_stops_the_release(): void {
		$this->assertSame( [], cbjp_readme_release_problems( self::README, '2.0.0' ) );

		$this->assertSame( [ "the readme's Stable tag is 2.0.0, not 1.0.0" ], cbjp_readme_release_problems( self::README, '1.0.0' ) );
		$this->assertSame(
			[ 'the readme has no Stable tag header' ],
			cbjp_readme_release_problems( str_replace( "Stable tag: 2.0.0\n", '', self::README ), '2.0.0' )
		);
		$this->assertSame(
			[
				"the readme's Stable tag is 2.0.0, not 4.0.0",
				"the readme's Changelog has no entry '= 4.0.0 ='",
			],
			cbjp_readme_release_problems( self::README, '4.0.0' )
		);
		$this->assertSame(
			[ "the readme's Changelog has no entry '= 3.0.0 ='" ],
			cbjp_readme_release_problems( str_replace( 'Stable tag: 2.0.0', 'Stable tag: 3.0.0', self::README ), '3.0.0' )
		);

		foreach ( [ 'v2.0.0', '2.0', '2.0.0-beta', "2.0.0\n", '' ] as $version ) {
			$this->assertSame( [ "invalid version '{$version}' (expected X.Y.Z)" ], cbjp_readme_release_problems( self::README, $version ), (string) wp_json_encode( $version ) );
		}
	}

	/**
	 * CLI の本体: 問題が無ければ本文だけを標準出力へ（GitHub Release の本文になる）、問題は標準エラーへ。
	 */
	public function test_run_writes_the_entry_or_the_problems(): void {
		$path = $this->write_readme( self::README );

		[ $code, $out, $err ] = self::run_script( $path, '2.0.0' );
		$this->assertSame( 0, $code );
		$this->assertSame( "* Two.\n* Two again.\n", $out );
		$this->assertSame( '', $err );

		[ $code, $out, $err ] = self::run_script( $path, '1.0.0' );
		$this->assertSame( 1, $code );
		$this->assertSame( '', $out, '問題があるときは本文を出さない（リリースの本文にしない）' );
		$this->assertSame( "readme-release: the readme's Stable tag is 2.0.0, not 1.0.0\n", $err );

		[ $code, $out, $err ] = self::run_script( $path . '.missing', '2.0.0' );
		$this->assertSame( 2, $code );
		$this->assertSame( '', $out );
		$this->assertStringStartsWith( 'cannot read ', $err );
	}

	/**
	 * コマンドラインからの起動（`realpath( $argv[0] ) === __FILE__`・引数の数・`exit()` の終了コード）。release.yml・deploy-wporg.yml はこの形で呼ぶ（R1-L1）。
	 */
	public function test_the_command_line_entry_point(): void {
		$path = $this->write_readme( self::README );

		$this->assertSame( [ 0, "* Two.\n* Two again.\n", '' ], self::run_command( [ $path, '2.0.0' ] ) );
		$this->assertSame( [ 1, '', "readme-release: the readme's Stable tag is 2.0.0, not 1.0.0\n" ], self::run_command( [ $path, '1.0.0' ] ) );
		$this->assertSame( [ 2, '', "usage: php bin/readme-release.php <readme.txt> <version>\n" ], self::run_command( [ $path ] ) );
	}

	private function write_readme( string $contents ): string {
		$path = (string) tempnam( get_temp_dir(), 'cbjp-readme-release' );
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- 一時ファイルのテスト用の readme。
		$this->files[] = $path;

		return $path;
	}

	/**
	 * @return array{0:int,1:string,2:string} 終了コード・標準出力・標準エラー
	 */
	private static function run_script( string $path, string $version ): array {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- メモリのストリームで CLI の出力を受ける。
		$out  = fopen( 'php://memory', 'w+' );
		$err  = fopen( 'php://memory', 'w+' );
		$code = cbjp_readme_release_run( $path, $version, $out, $err );

		rewind( $out );
		rewind( $err );
		$result = [ $code, (string) stream_get_contents( $out ), (string) stream_get_contents( $err ) ];
		fclose( $out );
		fclose( $err );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return $result;
	}

	/**
	 * スクリプトを別の PHP のプロセスで実行する。
	 *
	 * @param array<int,string> $args スクリプトの引数。
	 * @return array{0:int,1:string,2:string} 終了コード・標準出力・標準エラー
	 */
	private static function run_command( array $args ): array {
		$command = array_merge( [ PHP_BINARY, WP_CONTENT_DIR . '/cbjp-dev/bin/readme-release.php' ], $args );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- テストで CLI の入口を別のプロセスとして走らせる（引数は配列で渡し、シェルを通さない）。
		$process = proc_open(
			$command,
			[
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);
		self::assertIsResource( $process );

		// phpcs:disable WordPress.WP.AlternativeFunctions -- 子のプロセスの出力を読む。
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return [ proc_close( $process ), $out, $err ];
	}
}
