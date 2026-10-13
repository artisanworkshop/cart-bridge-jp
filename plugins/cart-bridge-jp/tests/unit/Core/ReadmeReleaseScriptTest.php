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
	 */
	public function test_extracts_the_1_0_0_entry_of_the_readme(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- リポジトリ内のテキストファイルを読む（JSON ではない）。
		$readme = (string) file_get_contents( CBJP_PATH . 'readme.txt' );
		$entry  = cbjp_readme_release_changelog( $readme, '1.0.0' );

		$this->assertIsString( $entry );
		$lines = explode( "\n", $entry );
		$this->assertCount( 7, $lines );
		$this->assertStringStartsWith( '* First release on WordPress.org: ', $lines[0] );

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
	 * ヘッダーはタイトルから最初の `== … ==` の前まで（節の中の `Stable tag:` の行をヘッダーと読まない）。名前の大文字小文字は区別しない。
	 */
	public function test_reads_the_stable_tag_from_the_header_only(): void {
		$this->assertSame( '2.0.0', cbjp_readme_release_header( self::README, 'Stable tag' ) );
		$this->assertSame( '2.0.0', cbjp_readme_release_header( str_replace( 'Stable tag:', 'Stable Tag:', self::README ), 'Stable tag' ) );

		$moved = str_replace( [ "Stable tag: 2.0.0\n", "Description text.\n" ], [ '', "Stable tag: 2.0.0\n" ], self::README );
		$this->assertNull( cbjp_readme_release_header( $moved, 'Stable tag' ) );
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
				"the readme's Changelog has no entry '= 4.0.0 =' (or the entry is empty)",
			],
			cbjp_readme_release_problems( self::README, '4.0.0' )
		);
		$this->assertSame(
			[ "the readme's Changelog has no entry '= 3.0.0 =' (or the entry is empty)" ],
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
}
