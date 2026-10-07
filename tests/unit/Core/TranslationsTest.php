<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use MO;
use PO;
use Translation_Entry;
use WP_UnitTestCase;

/**
 * R3-2: 同梱の日本語訳（`languages/`）の整合。POT がソースと同じかは `bin/i18n.sh check`（CI）が確かめ、ここでは
 * PO が POT に揃っていること・訳の漏れと壊れ・生成物（.mo / .l10n.php / JSON）が PO と同じこと・実行時に読み込まれることを確かめる。
 */
final class TranslationsTest extends WP_UnitTestCase {

	private const DOMAIN = 'cart-bridge-jp';

	/**
	 * PHP の `sprintf()` と `@wordpress/i18n` の `sprintf()` が解釈する書式（`%%` を含む）。WP-CLI の make-pot が `php-format` と判定する形と同じ。
	 */
	private const PLACEHOLDER = "/%(?:%|(?:\\d+\\$)?[+-]?(?:[ 0]|'.)?-?\\d*(?:\\.\\d+)?[bcdeEfFgGosuxX])/";

	private static ?PO $po = null;

	private static ?PO $pot = null;

	public function tear_down(): void {
		remove_all_filters( 'locale' );
		unload_textdomain( self::DOMAIN, true );
		wp_deregister_script( 'cbjp-translations-test' );
		parent::tear_down();
	}

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

	public function test_mo_matches_the_po(): void {
		$mo = new MO();
		$this->assertTrue( $mo->import_from_file( $this->path( 'cart-bridge-jp-ja.mo' ) ) );

		// make-mo も複数形の原文を単数形だけで書く（`php_file_key()` と同じく WP は単数形で引き直す）ので、キーに複数形を含めない。
		$this->assertSame( $this->translations( self::po()->entries ), $this->translations( $mo->entries ) );
	}

	public function test_php_translation_file_matches_the_po(): void {
		$data = include $this->path( 'cart-bridge-jp-ja.l10n.php' );

		$this->assertIsArray( $data );
		$this->assertIsArray( $data['messages'] ?? null );

		$expected = [];

		foreach ( self::po()->entries as $entry ) {
			$expected[ self::php_file_key( $entry ) ] = implode( "\0", $entry->translations );
		}

		ksort( $expected );
		$actual = $data['messages'];
		ksort( $actual );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * `wp_set_script_translations()` は、スクリプトの相対パス（`build/index.js`）の md5 を名前に持つ JSON を探す。
	 * `src/*.tsx` から抜いた参照で作ると名前が食い違い、管理画面が英語のままになる（R3-2）。
	 */
	public function test_script_translations_are_a_single_json_named_after_the_build(): void {
		$files = glob( $this->path( 'cart-bridge-jp-ja-*.json' ) );

		$this->assertSame( [ $this->path( 'cart-bridge-jp-ja-' . md5( 'build/index.js' ) . '.json' ) ], $files );
	}

	public function test_script_translations_match_the_js_strings_of_the_po(): void {
		$json = wp_json_file_decode( $this->path( 'cart-bridge-jp-ja-' . md5( 'build/index.js' ) . '.json' ), [ 'associative' => true ] );

		// make-json は Jed の既定のドメイン `messages` で書く（WP の `wp_set_script_translations()` の読込みはどちらも扱う）。
		$this->assertIsArray( $json );
		$this->assertSame( 'messages', $json['domain'] ?? null );

		$messages = $json['locale_data']['messages'] ?? null;
		$this->assertIsArray( $messages );
		unset( $messages[''] );

		$expected = [];

		foreach ( self::po()->entries as $entry ) {
			if ( self::is_js( $entry ) ) {
				$expected[ ( null !== $entry->context ? $entry->context . "\4" : '' ) . $entry->singular ] = array_values( $entry->translations );
			}
		}

		ksort( $expected );
		ksort( $messages );

		$this->assertNotSame( [], $expected );
		$this->assertSame( $expected, $messages );
	}

	/**
	 * `Plugin::boot()` の `load_plugin_textdomain()` が登録したパスから、日本語の訳が読み込まれる（.l10n.php か .mo）。
	 * サイトの言語の `locale` フィルターは優先度 1 で足す（CLAUDE.md。`WP_Locale_Switcher` の切替を上書きしないため）。
	 */
	public function test_php_strings_are_translated_when_the_locale_is_japanese(): void {
		$expected = self::po()->entries['Unknown platform.']->translations[0];
		$this->assertNotSame( 'Unknown platform.', $expected );

		$this->use_japanese();

		$this->assertSame( $expected, __( 'Unknown platform.', 'cart-bridge-jp' ) );
	}

	public function test_admin_script_translations_load_when_the_locale_is_japanese(): void {
		$expected = self::po()->entries['Save settings']->translations[0];

		$this->use_japanese();
		wp_register_script( 'cbjp-translations-test', CBJP_URL . 'build/index.js', [], '1', true );

		$json = load_script_textdomain( 'cbjp-translations-test', self::DOMAIN, CBJP_PATH . 'languages' );

		$this->assertIsString( $json );
		$decoded = json_decode( $json, true );
		$this->assertSame( [ $expected ], $decoded['locale_data']['messages']['Save settings'] ?? null );
	}

	private function use_japanese(): void {
		add_filter( 'locale', static fn (): string => 'ja', 1 );
		unload_textdomain( self::DOMAIN, true );
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

	/**
	 * @param array<string,Translation_Entry> $entries
	 * @return array<string,array<int,string>>
	 */
	private function translations( array $entries ): array {
		$translations = [];

		foreach ( $entries as $entry ) {
			$translations[ self::php_file_key( $entry ) ] = array_values( $entry->translations );
		}

		ksort( $translations );

		return $translations;
	}

	/**
	 * `.l10n.php` の `messages` のキー。文脈は `\4` でつなぐ。WP-CLI の make-php は複数形も単数形だけをキーにするが、
	 * WP は「単数形\0複数形」で見つからなければ単数形で引き直す（`WP_Translation_Controller::translate_plural()`）。
	 */
	private static function php_file_key( Translation_Entry $entry ): string {
		return null !== $entry->context ? $entry->context . "\4" . $entry->singular : $entry->singular;
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
