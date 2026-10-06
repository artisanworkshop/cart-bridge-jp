<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * 平文（Canonical の名前）と、Woo が HTML として扱う文字列（商品の post_title・ターム名）の相互変換。
 *
 * WordPress は投稿のタイトルを HTML として保存・表示する（`the_title()` はエスケープしない）。保存時の kses は
 * 実行するユーザーの権限で変わり、WP-Cron（未ログイン）では `title_save_pre` の `wp_filter_kses` が `&` を `&amp;` にして
 * 許可されないタグを除き、管理画面から動く非同期ランナー（unfiltered_html あり）ではそのまま残る（R3-1、issue #99）。
 * そこで取込みは名前を {@see self::from_plain()} で実体参照にしてから保存し、どちらのランナーでも同じ値・同じ表示にする。
 * エクスポートは {@see self::to_plain()} で平文へ戻して送る。ターム名は WP が常に `&amp;` で保存する
 * （`pre_term_name` の `_wp_specialchars`）ので、読み出す側だけが戻す。
 */
final class HtmlText {

	private function __construct() {}

	/**
	 * `&` `<` `>` を実体参照にし（既存の実体参照も二重に符号化する）、バックスラッシュを `&#092;` にする。
	 *
	 * - 二重に符号化するので、文字どおり `&amp;` を含む名前も {@see self::to_plain()} で元どおりに戻る。
	 * - 引用符は kses が変えないので符号化しない（`Men&#039;s` にすると `Men's` で商品を検索できなくなる）。
	 * - バックスラッシュは `wp_insert_post()` の `wp_unslash()` がランナーによらず消すので、数値実体参照で守る。
	 *   `&#092;` は kses が数値実体参照を正規化した形（3 桁）なので保存で変わらない。`&` の符号化より後に置換する
	 *   （先に置換すると `&amp;#092;` になる）。
	 */
	public static function from_plain( string $text ): string {
		return str_replace( '\\', '&#092;', htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', true ) );
	}

	/**
	 * HTML の文字列を表示どおりの平文にする（{@see self::from_plain()} の厳密な逆）。
	 *
	 * Woo で作られた名前は生の `&` のことも、kses や REST で `&amp;` などの実体参照になっていることもあるので、
	 * 5 種類だけを戻す `wp_specialchars_decode()` ではなく全ての実体参照を戻す。タグは除かない（管理者が名前に
	 * 書いた `<…>` は管理画面に文字として出ているので、そのまま送る）。
	 */
	public static function to_plain( string $html ): string {
		return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
