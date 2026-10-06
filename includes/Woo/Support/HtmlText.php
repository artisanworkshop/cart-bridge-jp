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
	 * `<script>`・`<style>` の開始タグ。タグ名の直後は HTML の空白・`/`・`>` に限る（`<scripts>`・`<script-x>` は別の要素）。
	 */
	private const SCRIPT_STYLE_OPENER = '~\A<(script|style)(?=[\t\n\f\r />])~i';

	/**
	 * 閉じタグの名前の直後に来てよい文字（HTML の空白・`/`・`>`）。
	 */
	private const TAG_NAME_TERMINATORS = " \t\n\f\r/>";

	/**
	 * `<` の直後に来るとタグの始まりになる文字（{@see self::opens_markup()}）。
	 */
	private const MARKUP_OPENERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ/!?';

	private function __construct() {}

	/**
	 * 説明などの HTML を `wp_kses_post()` で浄化する。その前に `<script>`・`<style>` 要素を中身ごと除く。kses はこの 2 つのタグを
	 * 外すだけで中身の JS・CSS を文字として残し、商品ページに表示されてしまう（R3-1 で実測。issue #101）。
	 *
	 * - タグの区切りはブラウザに合わせる: `<` の直後が英字・`/`・`!`・`?` のときだけタグの始まりとし、最初の `>`（無ければ末尾）までを
	 *   1 つの区切りにする（`wp_kses_split()` と同じく、属性値の中の引用符・`>` は考えない）。それ以外の `<` は文字なので `&lt;` にして出す
	 *   （kses も `wp_pre_kses_less_than()` で同じ `<` を文字にする。生のまま出すと、除いた前後がつながってタグに見えることがある）。
	 *   文字列全体から `<script` を探すと、属性値（`<p title="<script>">`）・CDATA の中の文字まで要素の開始と見なし、後ろの説明を消してしまう
	 *   （review-loop R1-1）。区切りの先頭が `<script`・`<style` のものだけを開始タグとする。
	 * - 中身はブラウザと同じく生のテキストとして読み、最初の `</script`（直後が空白・`/`・`>`）で閉じる（JS の `a<b` をタグと見なさない）。
	 *   閉じタグに `>` が無ければ末尾までが閉じタグ（ブラウザも表示しない）。
	 * - 閉じタグが無ければ何も除かない（後ろの説明を失わない。kses がタグを外し、中身は文字として残る従来の結果になる）。
	 * - コメントは `-->` まで（無ければ末尾まで）。`<!-->`・`<!--->` はブラウザと同じくその場で閉じたコメントとし、`<!---->` にそろえて出す
	 *   （kses はそのままでは次の `-->` までをコメントと読み、後ろの説明を隠す）。kses は `<` を含むコメントを `wp_pre_kses_less_than()` で
	 *   文字にしてしまうので、コメントの中では区切りによらずどの `<script`・`<style` も開始タグとし、閉じタグはコメントの中だけで探す。
	 * - 区切りと閉じタグは `strpos()`・`stripos()` で探す（正規表現の遅延一致は長い説明で PCRE の上限に達し、閉じタグの無い開始タグが
	 *   多い入力では探し直しが二乗になる）。
	 * - 既知の限界（どれも R3-1c より前の kses だけの結果と同じかそれ以上）: 引用符で囲んだ属性値の中の `>` の後ろの `<script>`
	 *   （`<p title="a>b<script>">`）は開始タグと見なす。`<textarea>`・`<title>` の中（ブラウザでは文字）の `<script>` も開始タグと見なす。
	 *   スクリプトの中の `<!--<script` の後ろ（ブラウザは次の `</script>` で閉じない）は区別しない。
	 */
	public static function sanitize_post_html( string $html ): string {
		// kses は最初に制御文字を消してからタグを読む（`wp_kses()` の `wp_kses_no_null()`）。同じ順序にしないと、`<script\0>` を開始タグと
		// 見なさないまま kses が普通の `<script>` として外し、中身が残る（PR #106 G1-2）。
		return wp_kses_post( self::strip_script_and_style( wp_kses_no_null( $html, [ 'slash_zero' => 'keep' ] ), false ) );
	}

	private static function strip_script_and_style( string $html, bool $in_comment ): string {
		$stripped = '';
		$offset   = 0;
		$length   = strlen( $html );
		// 閉じタグが見つからなかった要素名。それより後ろの開始タグにも閉じタグは無いので探し直さない。
		$unclosed = [];

		while ( $offset < $length ) {
			$start = strpos( $html, '<', $offset );

			if ( false === $start ) {
				break;
			}

			$stripped .= substr( $html, $offset, $start - $offset );

			if ( $in_comment ) {
				// コメントの中: 区切りによらず `<` ごとに開始タグかを確かめる（文字の `<` はそのまま。kses が文字にする）。
				$token  = '<';
				$opened = substr( $html, $start, 8 );
			} elseif ( '<!-->' === substr( $html, $start, 5 ) || '<!--->' === substr( $html, $start, 6 ) ) {
				$stripped .= '<!---->';
				$offset    = $start + ( '<!-->' === substr( $html, $start, 5 ) ? 5 : 6 );
				continue;
			} elseif ( '<!--' === substr( $html, $start, 4 ) ) {
				$end    = strpos( $html, '-->', $start + 4 );
				$inner  = false === $end ? substr( $html, $start + 4 ) : substr( $html, $start + 4, $end - $start - 4 );
				$offset = false === $end ? $length : $end + 3;

				$stripped .= '<!--' . self::strip_script_and_style( $inner, true ) . ( false === $end ? '' : '-->' );
				continue;
			} elseif ( ! self::opens_markup( $html[ $start + 1 ] ?? '' ) ) {
				$stripped .= '&lt;';
				$offset    = $start + 1;
				continue;
			} else {
				$end    = strpos( $html, '>', $start );
				$token  = false === $end ? substr( $html, $start ) : substr( $html, $start, $end - $start + 1 );
				$opened = $token;
			}

			$offset = $start + strlen( $token );

			if ( 1 !== preg_match( self::SCRIPT_STYLE_OPENER, $opened, $opener ) ) {
				$stripped .= $token;
				continue;
			}

			$name   = strtolower( $opener[1] );
			$closer = isset( $unclosed[ $name ] ) ? null : self::closing_tag_end( $html, $name, $offset );

			if ( null === $closer ) {
				// 閉じタグが無い: 開始タグも中身も残す（kses に任せる）。
				$unclosed[ $name ] = true;
				$stripped         .= $token;
				continue;
			}

			$offset = $closer;
		}

		return $stripped . substr( $html, $offset );
	}

	/**
	 * `<` の直後の 1 文字が、ブラウザがタグ（終了タグ・コメント・宣言・処理命令を含む）の始まりとして読む文字か（ASCII の英字・`/`・`!`・`?`）。
	 */
	private static function opens_markup( string $next ): bool {
		return '' !== $next && str_contains( self::MARKUP_OPENERS, $next );
	}

	/**
	 * `$offset` 以降で最初の `</{$name}`（直後が空白・`/`・`>`）の閉じタグの終わり（`>` の次。`>` が無ければ末尾）。無ければ null。
	 */
	private static function closing_tag_end( string $html, string $name, int $offset ): ?int {
		$needle = '</' . $name;

		while ( true ) {
			$position = stripos( $html, $needle, $offset );

			if ( false === $position ) {
				return null;
			}

			$next = $html[ $position + strlen( $needle ) ] ?? '';

			if ( '' !== $next && str_contains( self::TAG_NAME_TERMINATORS, $next ) ) {
				$end = strpos( $html, '>', $position );

				return false === $end ? strlen( $html ) : $end + 1;
			}

			$offset = $position + 1;
		}
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
