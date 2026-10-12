<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Core;

use MO;
use PO;
use Translation_Entry;
use WP_UnitTestCase;

/**
 * R3-8d: Pro に同梱する日本語訳（`languages/`）の整合。無料版の `TranslationsTest`（R3-2）と同じ観点で、POT がソースと同じかは
 * `bin/i18n.sh check`（CI。両プラグイン）が確かめ、ここでは PO が POT に揃っていること・訳の漏れと壊れ・生成物（.mo / .l10n.php）が
 * PO と同じこと・実行時に読み込まれることを確かめる。Pro は JS を持たないので、管理画面の JSON は作らない。
 */
final class TranslationsTest extends WP_UnitTestCase {

	private const DOMAIN = 'cart-bridge-jp-pro';

	/**
	 * PHP の `sprintf()` が解釈する書式（`%%` を含む）。WP-CLI の make-pot が `php-format` と判定する形と同じ（無料版の `TranslationsTest` と同じ）。
	 */
	private const PLACEHOLDER = "/%(?:%|(?:\\d+\\$)?[+-]?(?:[ 0]|'.)?-?\\d*(?:\\.\\d+)?[bcdeEfFgGosuxX])/";

	private static ?PO $po = null;

	private static ?PO $pot = null;

	public function tear_down(): void {
		remove_all_filters( 'locale' );
		unload_textdomain( self::DOMAIN, true );
		parent::tear_down();
	}

	public function test_po_has_the_same_strings_as_the_pot(): void {
		$this->assertSame( $this->string_keys( self::pot() ), $this->string_keys( self::po() ) );
		$this->assertNotSame( [], $this->string_keys( self::po() ) );
	}

	public function test_po_is_japanese_with_one_plural_form(): void {
		$this->assertSame( 'ja', self::po()->get_header( 'Language' ) );
		$this->assertSame( 'nplurals=1; plural=0;', self::po()->get_header( 'Plural-Forms' ) );
		$this->assertSame( self::DOMAIN, self::po()->get_header( 'X-Domain' ) );
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
		$this->assertTrue( $mo->import_from_file( $this->path( self::DOMAIN . '-ja.mo' ) ) );

		$this->assertSame( $this->translations( self::po()->entries ), $this->translations( $mo->entries ) );
	}

	public function test_php_translation_file_matches_the_po(): void {
		$data = include $this->path( self::DOMAIN . '-ja.l10n.php' );

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
	 * Pro は JS を持たない（設定画面は PHP で描く）。JS の文字列・管理画面の JSON があれば、`bin/i18n.sh` の設定（Pro は make-json を走らせない）と食い違っている。
	 */
	public function test_there_are_no_script_translations(): void {
		$js = array_filter( self::pot()->entries, static fn ( Translation_Entry $entry ): bool => self::is_js( $entry ) );

		$this->assertSame( [], array_keys( $js ) );
		$this->assertSame( [], glob( $this->path( '*.json' ) ) );
	}

	/**
	 * 訳のパスは `cbjp_pro_bootstrap()`（WooCommerce・無料版が無い／無料版が古いと起動しない）とは別の `plugins_loaded` で登録する。
	 * ガードの中で登録すると、前提が欠けたときの通知が日本語の管理者にも英語で出る（backlog `r3-7-monorepo/G1-1`。無料版は PR #109 G1-1）。
	 */
	public function test_text_domain_is_registered_outside_the_guarded_bootstrap(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', 'CartBridgeJP\\Pro\\cbjp_pro_load_textdomain' ) );
		$this->assertNotFalse( has_action( 'plugins_loaded', 'CartBridgeJP\\Pro\\cbjp_pro_bootstrap' ) );
	}

	/**
	 * `cbjp_pro_load_textdomain()` の `load_plugin_textdomain()` が登録したパスから、日本語の訳が読み込まれる（.l10n.php か .mo）。
	 * 無料版から移した文字列（種類名）と R3-8a で足した文字列（設定画面）の両方。サイトの言語の `locale` フィルターは優先度 1 で足す（CLAUDE.md）。
	 */
	public function test_php_strings_are_translated_when_the_locale_is_japanese(): void {
		$customers   = self::translation_of( 'Customers' );
		$license_key = self::translation_of( 'License key' );

		$this->use_japanese();

		$this->assertSame( $customers, __( 'Customers', 'cart-bridge-jp-pro' ) );
		$this->assertSame( $license_key, __( 'License key', 'cart-bridge-jp-pro' ) );
	}

	/**
	 * 前提が欠けたとき（Pro のクラスを読み込まない）の通知も訳す（backlog `r3-7-monorepo/G1-1`）。
	 */
	public function test_requirements_notice_is_translated_when_the_locale_is_japanese(): void {
		$expected = self::translation_of( 'Cart Bridge JP Pro requires WooCommerce and Cart Bridge JP to be installed and active.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->use_japanese();

		ob_start();
		\CartBridgeJP\Pro\cbjp_pro_render_missing_requirements_notice();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( esc_html( $expected ), $output );
	}

	/**
	 * 訳した文のつなぎ（`%1$s %2$s`）は日本語では空白を挟まない（設定画面の結果の文。「。 」を残さない）。
	 */
	public function test_sentences_are_joined_without_a_space_in_japanese(): void {
		$this->assertSame( '%1$s%2$s', self::translation_of( '%1$s %2$s' ) );
	}

	private function use_japanese(): void {
		add_filter( 'locale', static fn (): string => 'ja', 1 );
		unload_textdomain( self::DOMAIN, true );
	}

	/**
	 * PO の訳（原文と同じなら、読み込めたかを見分けられないので失敗にする）。
	 */
	private static function translation_of( string $original ): string {
		$entry = self::po()->entries[ $original ] ?? null;
		self::assertInstanceOf( Translation_Entry::class, $entry, "{$original} is not in the PO." );

		$translation = (string) ( $entry->translations[0] ?? '' );
		self::assertNotSame( '', $translation );
		self::assertNotSame( $original, $translation, "{$original} is not translated into a different text." );

		return $translation;
	}

	private function path( string $file ): string {
		return CBJP_PRO_PATH . 'languages/' . $file;
	}

	private static function po(): PO {
		if ( null === self::$po ) {
			self::$po = self::read( self::DOMAIN . '-ja.po' );
		}

		return self::$po;
	}

	private static function pot(): PO {
		if ( null === self::$pot ) {
			self::$pot = self::read( self::DOMAIN . '.pot' );
		}

		return self::$pot;
	}

	private static function read( string $file ): PO {
		require_once ABSPATH . WPINC . '/pomo/po.php';

		$po = new PO();
		self::assertTrue( $po->import_from_file( CBJP_PRO_PATH . 'languages/' . $file ), "Cannot read {$file}." );

		return $po;
	}

	/**
	 * 文字列の一覧（msgctxt・msgid・msgid_plural）と、JS から参照されるか。
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
	 * `.l10n.php` の `messages` のキー。文脈は `\4` でつなぐ。WP-CLI の make-php は複数形も単数形だけをキーにする（無料版の `TranslationsTest` と同じ）。
	 */
	private static function php_file_key( Translation_Entry $entry ): string {
		return null !== $entry->context ? $entry->context . "\4" . $entry->singular : $entry->singular;
	}

	private static function is_js( Translation_Entry $entry ): bool {
		foreach ( $entry->references as $reference ) {
			if ( 1 === preg_match( '#\.js(?::\d+)?$#', $reference ) ) {
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
