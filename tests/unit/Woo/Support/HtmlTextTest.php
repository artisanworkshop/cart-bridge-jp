<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\HtmlText;
use WP_UnitTestCase;

final class HtmlTextTest extends WP_UnitTestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function plain_names(): array {
		return [
			'ampersand and unknown tag' => [ 'Tom & Jerry <set>' ],
			'lone angle brackets'       => [ 'x > y < z' ],
			'backslashes'               => [ 'A\\B C:\\path \\\\ end\\' ],
			'literal entity text'       => [ 'A &amp; B &hearts; &#9829; &#092;' ],
			'quotes'                    => [ "Men's 17\" set" ],
			'japanese'                  => [ '【限定】Ｔシャツ＆タオル ¥1,000' ],
			'empty'                     => [ '' ],
		];
	}

	/**
	 * @dataProvider plain_names
	 */
	public function test_round_trip_restores_the_plain_text( string $plain ): void {
		$this->assertSame( $plain, HtmlText::to_plain( HtmlText::from_plain( $plain ) ) );
	}

	/**
	 * kses の正規化を通っても変わらない形にする（`title_save_pre` の `wp_filter_kses` と同じ処理を直接当てる。
	 * 生の値が kses で変わることは次のテストで確かめる）。
	 *
	 * @dataProvider plain_names
	 */
	public function test_encoded_text_is_stable_under_kses( string $plain ): void {
		$encoded = HtmlText::from_plain( $plain );

		$this->assertSame( $encoded, wp_kses( $encoded, 'title_save_pre' ) );
	}

	public function test_kses_would_change_the_raw_text(): void {
		$this->assertNotSame( 'Tom & Jerry <set>', wp_kses( 'Tom & Jerry <set>', 'title_save_pre' ) );
	}

	public function test_only_ampersand_angle_brackets_and_backslash_are_encoded(): void {
		$this->assertSame( 'Tom &amp; Jerry &lt;set&gt;', HtmlText::from_plain( 'Tom & Jerry <set>' ) );
		$this->assertSame( 'A&#092;B', HtmlText::from_plain( 'A\\B' ) );
		$this->assertSame( 'A &amp;amp; B', HtmlText::from_plain( 'A &amp; B' ) );
		// 引用符は kses が変えないので符号化しない（`Men's` で商品を検索できるように）。
		$this->assertSame( "Men's 17\" set", HtmlText::from_plain( "Men's 17\" set" ) );
	}

	/**
	 * 制御文字は kses（`wp_kses_no_null()`）が WP-Cron でだけ消すので、先に消す。タブ・改行・復帰は kses も残すので残す。
	 */
	public function test_control_characters_are_removed_like_kses_does(): void {
		$encoded = HtmlText::from_plain( "A\x0BB\x1BC\x00D\tE\nF\rG" );

		$this->assertSame( "ABCD\tE\nF\rG", $encoded );
		$this->assertSame( $encoded, wp_kses( $encoded, 'title_save_pre' ) );
	}

	/**
	 * 不正な UTF-8 は U+FFFD にして残す（`htmlspecialchars()` は既定では空文字列を返し、名前が消える）。
	 */
	public function test_invalid_utf8_is_substituted_instead_of_emptying_the_name(): void {
		$this->assertSame( "A\u{FFFD}B &amp; C", HtmlText::from_plain( "A\xC3B & C" ) );
	}

	/**
	 * Woo で作られた名前（管理画面の生の値、kses や REST で実体参照になった値）は、表示どおりの文字へ戻す。
	 */
	public function test_woo_born_html_is_decoded_to_what_the_store_displays(): void {
		$this->assertSame( 'Tom & Jerry', HtmlText::to_plain( 'Tom &amp; Jerry' ) );
		$this->assertSame( 'Tom & Jerry <set>', HtmlText::to_plain( 'Tom & Jerry <set>' ) );
		$this->assertSame( "Men's \u{2026}", HtmlText::to_plain( 'Men&#039;s &hellip;' ) );
		$this->assertSame( 'A\\B', HtmlText::to_plain( 'A&#092;B' ) );
	}

	// --- sanitize_post_html()（issue #101）

	/**
	 * kses だけだと `<script>`・`<style>` の中身が文字として残る（R3-1 の P11 と同じ入力）。中身ごと除く。
	 */
	public function test_script_and_style_elements_are_removed_with_their_contents(): void {
		$html = '<p>説明</p><script>console.log("zzr")</script><style>.zzr{color:red}</style><p style="color:blue">装飾つき</p>';

		$this->assertSame( 'aconsole.log("zzr").zzr{color:red}b', wp_kses_post( 'a<script>console.log("zzr")</script><style>.zzr{color:red}</style>b' ), 'kses だけでは中身が残る（前提）' );
		$this->assertSame( '<p>説明</p><p style="color:blue">装飾つき</p>', HtmlText::sanitize_post_html( $html ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function script_style_variants(): array {
		return [
			'upper case'                   => [ 'a<SCRIPT>x()</SCRIPT>b', 'ab' ],
			'mixed case pair'              => [ 'a<Script>x()</sCRIPT>b', 'ab' ],
			'attributes'                   => [ 'a<script type="text/javascript" async data-x="1">x()</script>b', 'ab' ],
			'multi line'                   => [ "a<style media=\"all\">\n.x{\n  color:red;\n}\n</style>b", 'ab' ],
			'space before closing bracket' => [ 'a<script>x()</script >b', 'ab' ],
			'self-closing look'            => [ 'a<script/>x()</script>b', 'ab' ],
			'several elements'             => [ 'a<script>1</script>b<style>2</style>c<script>3</script>d', 'abcd' ],
			'closing tag text in string'   => [ 'a<script>var s="</script>";</script>b', 'a";b' ],
			'unclosed element'             => [ 'a<p>b</p><script>x();<p>c</p>', 'a<p>b</p>' ],
			'rebuilt after removal'        => [ 'a<scr<script></script>ipt>x()</scr<script></script>ipt>b', 'ab' ],
			'inside a comment'             => [ 'a<!-- <script>c()</script> -->b', 'a<!--  -->b' ],
		];
	}

	/**
	 * 大文字小文字・属性・改行・閉じタグの空白・閉じタグ無し（末尾まで。ブラウザも残りを中身として読む）・除いた後にできる要素。
	 *
	 * @dataProvider script_style_variants
	 */
	public function test_script_and_style_variants_are_removed( string $html, string $expected ): void {
		$this->assertSame( $expected, HtmlText::sanitize_post_html( $html ) );
	}

	/**
	 * タグ名の後ろが空白・`/`・`>` でないものは別の要素なので残す（kses の扱いのまま。許可されないタグとして外れ、中身は残る）。
	 */
	public function test_elements_whose_name_only_starts_with_script_or_style_are_kept(): void {
		$this->assertSame( 'akeptb', HtmlText::sanitize_post_html( 'a<scripts>kept</scripts>b' ) );
		$this->assertSame( 'akeptb', HtmlText::sanitize_post_html( 'a<script-x>kept</script-x>b' ) );
		$this->assertSame( 'akeptb', HtmlText::sanitize_post_html( 'a<styles>kept</styles>b' ) );
	}

	public function test_allowed_html_and_entities_are_kept_as_kses_leaves_them(): void {
		$html = '<p class="x">A &amp; B <a href="https://example.com/">link</a> <strong>強調</strong></p><!-- note -->';

		$this->assertSame( wp_kses_post( $html ), HtmlText::sanitize_post_html( $html ) );
		$this->assertSame( '', HtmlText::sanitize_post_html( '' ) );
	}

	/**
	 * `preg_replace()` が失敗した（PCRE の上限）ときは除去を諦め、入力をそのまま返す（説明を丸ごと失わない）。
	 * kses 自体も PCRE を使い、上限を下げると空文字列を返す（実測）ので、除去の段だけを確かめる。
	 */
	public function test_a_pcre_failure_returns_the_input_instead_of_an_empty_string(): void {
		$html = 'a<script>' . str_repeat( 'x', 5000 ) . '</script>b';

		// phpcs:ignore WordPress.PHP.IniSet.Risky -- PCRE の失敗を起こすためにテストの間だけ下げる。
		$previous_limit = ini_set( 'pcre.backtrack_limit', '10' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- JIT は backtrack_limit を見ないので切る。
		$previous_jit = ini_set( 'pcre.jit', '0' );

		try {
			$stripped = HtmlText::strip_script_and_style( $html );
			$error    = preg_last_error();
		} finally {
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- 元に戻す。
			ini_set( 'pcre.backtrack_limit', (string) $previous_limit );
			// phpcs:ignore WordPress.PHP.IniSet.Risky -- 元に戻す。
			ini_set( 'pcre.jit', (string) $previous_jit );
		}

		$this->assertSame( PREG_BACKTRACK_LIMIT_ERROR, $error, 'PCRE が失敗する条件になっている（前提）' );
		$this->assertSame( $html, $stripped );
		$this->assertSame( 'ab', HtmlText::strip_script_and_style( $html ), '上限を戻せば除ける' );
	}

	/**
	 * 除去の段は kses を掛けない（`<iframe>` のような他の許可されないタグは {@see HtmlText::sanitize_post_html()} の kses が外す）。
	 */
	public function test_strip_does_not_apply_kses(): void {
		$this->assertSame( 'a<iframe src="https://example.com/"></iframe>b', HtmlText::strip_script_and_style( 'a<iframe src="https://example.com/"></iframe><script>x()</script>b' ) );
	}
}
