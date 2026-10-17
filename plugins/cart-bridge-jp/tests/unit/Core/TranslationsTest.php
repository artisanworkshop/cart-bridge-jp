<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use PO;
use Translation_Entry;
use WP_UnitTestCase;

/**
 * R3-2: 日本語訳（`languages/`）の整合。POT がソースと同じかは `bin/i18n.sh check`（CI）が確かめ、ここでは
 * PO が POT に揃っていること・訳の漏れと壊れを確かめる。
 *
 * 訳は配布物に同梱せず、translate.wordpress.org の言語パックで配る（wordpress.org の審査の指摘。issue #144）。リポジトリの POT と PO は
 * そこへ取り込む元で、無料版のコードが自分の訳を読まないこともここで確かめる。
 */
final class TranslationsTest extends WP_UnitTestCase {

	/**
	 * PHP の `sprintf()` と `@wordpress/i18n` の `sprintf()` が解釈する書式（`%%` を含む）。WP-CLI の make-pot が `php-format` と判定する形と同じ。
	 */
	private const PLACEHOLDER = "/%(?:%|(?:\\d+\\$)?[+-]?(?:[ 0]|'.)?-?\\d*(?:\\.\\d+)?[bcdeEfFgGosuxX])/";

	private static ?PO $po = null;

	private static ?PO $pot = null;

	public function test_po_has_the_same_strings_as_the_pot(): void {
		$this->assertSame( $this->string_keys( self::pot() ), $this->string_keys( self::po() ) );
	}

	public function test_po_is_japanese_with_one_plural_form(): void {
		$this->assertSame( 'ja', self::po()->get_header( 'Language' ) );
		$this->assertSame( 'nplurals=1; plural=0;', self::po()->get_header( 'Plural-Forms' ) );
	}

	public function test_every_string_is_translated(): void {
		$untranslated = [];

		foreach ( self::po()->entries as $entry ) {
			$forms = array_values( $entry->translations );

			if ( 1 !== count( $forms ) || '' === $forms[0] || in_array( 'fuzzy', $entry->flags, true ) ) {
				$untranslated[] = $entry->singular;
			}
		}

		$this->assertSame( [], $untranslated );
	}

	/**
	 * 訳の書式が原文と食い違うと、`sprintf()` が例外を投げる・値が入らない・別の値が入る（`%%` を `%` と書くと PHP 8 は `ValueError`）。
	 */
	public function test_translations_keep_the_placeholders_of_the_original(): void {
		$broken = [];

		foreach ( self::po()->entries as $entry ) {
			$originals = null === $entry->plural ? [ $entry->singular ] : [ $entry->singular, $entry->plural ];

			foreach ( $originals as $original ) {
				foreach ( $entry->translations as $translation ) {
					if ( self::placeholders( $original ) !== self::placeholders( $translation ) ) {
						$broken[] = "{$original} => {$translation}";
					}
				}
			}
		}

		$this->assertSame( [], $broken );
	}

	/**
	 * 翻訳は同梱せず、translate.wordpress.org の言語パックを WordPress が読む（wordpress.org の審査の指摘。issue #144）。
	 * ヘッダーの Domain Path は配布物に無いフォルダを指すことになる（Plugin Check の `plugin_header_nonexistent_domain_path`）。
	 */
	public function test_plugin_header_has_no_domain_path(): void {
		$this->assertSame( '', get_file_data( CBJP_FILE, [ 'domain_path' => 'Domain Path' ] )['domain_path'] );
	}

	/**
	 * 無料版のコードは自分の訳を読まない: `load_plugin_textdomain()` などを呼ばず、`wp_set_script_translations()` にパスを渡さない
	 * （審査は `phpcs:ignore` の注釈を見ずに `load_plugin_textdomain()` を指摘した。issue #144）。
	 */
	public function test_plugin_code_does_not_load_bundled_translations(): void {
		$files = [ CBJP_FILE, CBJP_PATH . 'uninstall.php' ];

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( CBJP_PATH . 'includes', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$this->assertGreaterThan( 50, count( $files ), 'includes/ の PHP を読めていない' );

		$found = [];

		foreach ( $files as $path ) {
			foreach ( self::translation_loading_calls( (string) file_get_contents( $path ) ) as $call ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local source file of the plugin.
				$found[] = str_replace( CBJP_PATH, '', $path ) . ': ' . $call;
			}
		}

		$this->assertSame( [], $found );
	}

	/**
	 * `languages/` に残すのは、translate.wordpress.org へ取り込む元の POT と日本語の PO だけ（配布物には入らない。`.distignore`・`bin/build-zip.sh`）。
	 */
	public function test_languages_directory_holds_only_the_import_sources(): void {
		$files = array_map( 'basename', (array) glob( $this->path( '*' ) ) );
		sort( $files );

		$this->assertSame( [ 'cart-bridge-jp-ja.po', 'cart-bridge-jp.pot' ], $files );
	}

	/**
	 * 訳を読む関数の呼び出しと、パスを渡した `wp_set_script_translations()`（引数が 3 つ以上）を返す。
	 *
	 * @return array<int,string>
	 */
	private static function translation_loading_calls( string $source ): array {
		$tokens = token_get_all( $source, TOKEN_PARSE );
		$calls  = [];

		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], [ T_STRING, T_NAME_FULLY_QUALIFIED ], true ) ) {
				continue;
			}

			$name = strtolower( ltrim( $token[1], '\\' ) );

			if ( in_array( $name, [ 'load_plugin_textdomain', 'load_textdomain', 'load_script_textdomain', 'load_muplugin_textdomain' ], true ) ) {
				$calls[] = $name;
			} elseif ( 'wp_set_script_translations' === $name && self::argument_count( $tokens, $index ) > 2 ) {
				$calls[] = $name . ' with a path';
			}
		}

		return $calls;
	}

	/**
	 * `$tokens[ $index ]` の関数名に続く呼び出しの引数の数（いちばん外の括弧の中のカンマで数える）。呼び出しでなければ 0。
	 *
	 * @param array<int,array{0:int,1:string,2:int}|string> $tokens
	 */
	private static function argument_count( array $tokens, int $index ): int {
		$ignored = [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ];
		$depth   = 0;
		$commas  = 0;
		$empty   = true;

		for ( $i = $index + 1, $n = count( $tokens ); $i < $n; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) && in_array( $token[0], $ignored, true ) ) {
				continue;
			}

			$text = is_array( $token ) ? $token[1] : $token;

			if ( 0 === $depth ) {
				if ( '(' !== $text ) {
					return 0;
				}
				$depth = 1;
				continue;
			}

			// 二重引用符の文字列の中で式を囲む波括弧の開きも、閉じの波括弧と対にして数える。
			if ( in_array( $text, [ '(', '[', '{', '${' ], true ) ) {
				++$depth;
			} elseif ( in_array( $text, [ ')', ']', '}' ], true ) ) {
				--$depth;
				if ( 0 === $depth ) {
					return $empty ? 0 : $commas + 1;
				}
			} elseif ( 1 === $depth && ',' === $text ) {
				++$commas;
			}

			$empty = false;
		}

		return 0;
	}

	private function path( string $file ): string {
		return CBJP_PATH . 'languages/' . $file;
	}

	private static function po(): PO {
		if ( null === self::$po ) {
			self::$po = self::read( 'cart-bridge-jp-ja.po' );
		}

		return self::$po;
	}

	private static function pot(): PO {
		if ( null === self::$pot ) {
			self::$pot = self::read( 'cart-bridge-jp.pot' );
		}

		return self::$pot;
	}

	private static function read( string $file ): PO {
		require_once ABSPATH . WPINC . '/pomo/po.php';

		$po = new PO();
		self::assertTrue( $po->import_from_file( CBJP_PATH . 'languages/' . $file ), "Cannot read {$file}." );

		return $po;
	}

	/**
	 * 文字列の一覧（msgctxt・msgid・msgid_plural）と、JS から参照されるか（JSON に入るか）。
	 *
	 * @return array<string,bool>
	 */
	private function string_keys( PO $po ): array {
		$keys = [];

		foreach ( $po->entries as $entry ) {
			$keys[ (string) $entry->context . "\4" . $entry->singular . "\0" . (string) $entry->plural ] = self::is_js( $entry );
		}

		ksort( $keys );

		return $keys;
	}

	private static function is_js( Translation_Entry $entry ): bool {
		foreach ( $entry->references as $reference ) {
			if ( 1 === preg_match( '#^build/.+\.js(?::\d+)?$#', $reference ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<int,string>
	 */
	private static function placeholders( string $text ): array {
		preg_match_all( self::PLACEHOLDER, $text, $matches );
		$found = $matches[0];
		sort( $found );

		return $found;
	}
}
