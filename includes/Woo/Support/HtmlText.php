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
 *
 * 説明などの HTML は {@see self::sanitize_post_html()} で浄化する（取込みの変換と Writer の保存の両方。issue #101）。
 */
final class HtmlText {

	/**
	 * `<script>`・`<style>` 要素（開始タグから閉じタグまで）。タグ名の直後は空白・`/`・`>` に限る（`<scripts>`・`<script-x>` は
	 * 別の要素）。閉じタグが無ければ末尾まで（ブラウザも残り全体を中身として読み、表示しない）。大文字小文字と改行を問わない
	 * （後方参照 `\1` も `i` で大文字小文字を区別しない）。
	 */
	private const SCRIPT_STYLE_ELEMENT = '#<(script|style)(?=[\s/>])[^>]*>.*?(?:</\1(?=[\s/>])[^>]*>|\z)#is';

	private function __construct() {}

	/**
	 * 説明などの HTML を `wp_kses_post()` で浄化する。その前に `<script>`・`<style>` 要素を中身ごと除く（{@see self::strip_script_and_style()}）。
	 * kses はこの 2 つのタグを外すだけで中身の JS・CSS を文字として残し、商品ページに表示されてしまう（R3-1 で実測。issue #101）。
	 */
	public static function sanitize_post_html( string $html ): string {
		return wp_kses_post( self::strip_script_and_style( $html ) );
	}

	/**
	 * `<script>`・`<style>` 要素を中身ごと除く（kses は掛けない）。
	 *
	 * - 除いた後に要素ができる入力（`<scr<script></script>ipt>…</script>`）があるので、変化しなくなるまで繰り返す。
	 * - `preg_replace()` が失敗した（PCRE の上限に達した）ときは除去を諦め、その時点の文字列を返す（説明を丸ごと失わない。
	 *   kses を掛けると中身の文字が残る従来の結果になる）。
	 */
	public static function strip_script_and_style( string $html ): string {
		do {
			$previous = $html;
			$stripped = preg_replace( self::SCRIPT_STYLE_ELEMENT, '', $html );

			if ( null === $stripped ) {
				return $previous;
			}

			$html = $stripped;
		} while ( $html !== $previous );

		return $html;
	}

	/**
	 * `&` `<` `>` を実体参照にし（既存の実体参照も二重に符号化する）、バックスラッシュを `&#092;` にする。
	 *
	 * - 制御文字（タブ・改行・復帰を除く `\x00-\x1F`）は除く。kses の `wp_kses_no_null()` が WP-Cron でだけ消すので
	 *   （管理者では残る）、先に消して同じ結果にする（実体参照にしても kses が `&amp;#11;` に書き換える）。
	 * - 二重に符号化するので、文字どおり `&amp;` を含む名前も {@see self::to_plain()} で元どおりに戻る。
	 * - 引用符は kses が変えないので符号化しない（`Men&#039;s` にすると `Men's` で商品を検索できなくなる）。
	 * - バックスラッシュは `wp_insert_post()` の `wp_unslash()` がランナーによらず消すので、数値実体参照で守る。
	 *   `&#092;` は kses が数値実体参照を正規化した形（3 桁）なので保存で変わらない。`&` の符号化より後に置換する
	 *   （先に置換すると `&amp;#092;` になる）。
	 */
	public static function from_plain( string $text ): string {
		$text = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text );

		// `ENT_SUBSTITUTE`: 不正な UTF-8 を U+FFFD にする（無いと `htmlspecialchars()` が空文字列を返し、名前が消える）。
		return str_replace( '\\', '&#092;', htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8', true ) );
	}

	/**
	 * HTML の文字列を表示どおりの平文にする（{@see self::from_plain()} の逆。除いた制御文字と U+FFFD にした不正な
	 * UTF-8、保存時の `title_save_pre` の前後の空白の trim は戻らない）。
	 *
	 * Woo で作られた名前は生の `&` のことも、kses や REST で `&amp;` などの実体参照になっていることもあるので、
	 * 5 種類だけを戻す `wp_specialchars_decode()` ではなく全ての実体参照を戻す。タグは除かない（管理者が名前に
	 * 書いた `<…>` は管理画面に文字として出ているので、そのまま送る）。
	 */
	public static function to_plain( string $html ): string {
		return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
