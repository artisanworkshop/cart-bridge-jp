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
			'upper case'                     => [ 'a<SCRIPT>x()</SCRIPT>b', 'ab' ],
			'mixed case pair'                => [ 'a<Script>x()</sCRIPT>b', 'ab' ],
			'attributes'                     => [ 'a<script type="text/javascript" async data-x="1">x()</script>b', 'ab' ],
			'multi line'                     => [ "a<style media=\"all\">\n.x{\n  color:red;\n}\n</style>b", 'ab' ],
			'space before closing bracket'   => [ 'a<script>x()</script >b', 'ab' ],
			'slash after closing name'       => [ 'a<script>y()</script/>b', 'ab' ],
			'attribute on closing tag'       => [ 'a<script>y()</script foo>b', 'ab' ],
			'longer name is not a closer'    => [ 'a<script>x</scripts>y()</script>b', 'ab' ],
			'self-closing look'              => [ 'a<script/>x()</script>b', 'ab' ],
			'several elements'               => [ 'a<script>1</script>b<style>2</style>c<script>3</script>d', 'abcd' ],
			'less-than inside the script'    => [ 'a<script>if (a<b && c>d) { x(); }</script>b', 'ab' ],
			'closing tag text in string'     => [ 'a<script>var s="</script>";</script>b', 'a";b' ],
			'joined text is not a tag'       => [ 'a<script>1</script>script>2</script>b', 'ascript&gt;2b' ],
			'closing tag without bracket'    => [ 'a<script>x()</script b', 'a' ],
			'form feed after the name'       => [ "a<script\f>x()</script\f>b", 'ab' ],
			'null byte in the opener'        => [ "a<script\0>alert(1)</script>b", 'ab' ],
			'null byte in the closer'        => [ "a<script>alert(1)</script\0>b", 'ab' ],
			'control character in the name'  => [ "a<scr\x01ipt>alert(1)</script>b", 'ab' ],
			'inside a comment'               => [ 'a<!-- <script>c()</script> -->b', 'a<!--  -->b' ],
			'inside a multi-line comment'    => [ "a<!--\n<script>c()</script>\n-->b", "a<!--\n\n-->b" ],
			'less-than as text before'       => [ 'A < B<script>x()</script>C', 'A &lt; BC' ],
			'less-than with a later bracket' => [ 'A < B<script>x()</script>C > D', 'A &lt; BC &gt; D' ],
			'japanese text with less-than'   => [ "容量 < 500ml\n<style>.foo{color:red}</style>end", "容量 &lt; 500ml\nend" ],
			'double less-than'               => [ '<<script>x()</script>tail', '&lt;tail' ],
			'less-than and a digit'          => [ '<3 <script>x()</script>', '&lt;3 ' ],
		];
	}

	/**
	 * 大文字小文字・属性・改行・閉じタグの形（空白・`/`・属性。`</scripts>` は閉じタグではない）・中身の `<`・コメントの中。
	 * 中身の文字列に `</script>` があれば、ブラウザと同じくそこで閉じる。
	 *
	 * @dataProvider script_style_variants
	 */
	public function test_script_and_style_variants_are_removed( string $html, string $expected ): void {
		$this->assertSame( $expected, HtmlText::sanitize_post_html( $html ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function contents_that_must_survive(): array {
		return [
			'script text in an attribute'        => [ '<p title="<script>">keep me</p><p>after</p>', '<p>after</p>' ],
			'style text in an image alt'         => [ '<img alt="<style>" src="https://example.com/x.png">visible text<p>more</p>', 'visible text<p>more</p>' ],
			'script text in CDATA'               => [ 'a<![CDATA[<script>]]>visible<p>x</p>', 'visible<p>x</p>' ],
			'unclosed style in a comment'        => [ '<!-- <style> --> Visible description here. <b>bold</b>', ' Visible description here. <b>bold</b>' ],
			'unclosed element'                   => [ 'a<p>b</p><script>x();<p>c</p>', '<p>c</p>' ],
			'attribute script before a real one' => [ '<p title="<script>">keep me</p><p>after</p><script>x()</script>', '<p>after</p>' ],
		];
	}

	/**
	 * 本物の開始タグでない `<script>`・`<style>`（属性値・CDATA・閉じていないコメントの中）と、閉じタグの無い要素は除かない。
	 * 正規表現で文字列全体を探すと、ここから後ろ（末尾まで）の説明を消していた（review-loop R1-1）。kses だけの結果と同じになる。
	 *
	 * @dataProvider contents_that_must_survive
	 */
	public function test_contents_outside_real_script_and_style_elements_survive( string $html, string $survivor ): void {
		$sanitized = HtmlText::sanitize_post_html( $html );

		$this->assertStringContainsString( $survivor, $sanitized );

		if ( str_ends_with( $html, '<script>x()</script>' ) ) {
			$this->assertStringNotContainsString( 'x()', $sanitized, '後ろの本物の要素は除く' );
		} else {
			$this->assertSame( wp_kses_post( $html ), $sanitized, 'kses だけの結果と同じ（何も除かない）' );
		}
	}

	/**
	 * タグ名の後ろが空白・`/`・`>` でないものは別の要素なので残す（kses の扱いのまま。許可されないタグとして外れ、中身は残る）。
	 */
	public function test_elements_whose_name_only_starts_with_script_or_style_are_kept(): void {
		$this->assertSame( 'akeptb', HtmlText::sanitize_post_html( 'a<scripts>kept</scripts>b' ) );
		$this->assertSame( 'akeptb', HtmlText::sanitize_post_html( 'a<script-x>kept</script-x>b' ) );
		$this->assertSame( 'akeptb', HtmlText::sanitize_post_html( 'a<styles>kept</styles>b' ) );
		// 後ろに本物の要素があっても、`<scripts>` から本物の閉じタグまでを 1 つの要素と見なさない。
		$this->assertSame( 'akeptbc', HtmlText::sanitize_post_html( 'a<scripts>kept</scripts>b<script>x()</script>c' ) );
		$this->assertSame( 'akeptbc', HtmlText::sanitize_post_html( 'a<style-x>kept</style-x>b<style>p{}</style>c' ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function comments_hiding_scripts(): array {
		return [
			'abruptly closed comment'      => [ '<!--><script>x()-->y()</script>tail' ],
			'abruptly closed comment dash' => [ '<!---><script>x()</script>tail' ],
			'comment opener in a comment'  => [ '<!-- <!-- <script>x()</script> -->tail' ],
			'attribute in a comment'       => [ '<!-- <p title="<script>">x()</script> -->tail' ],
		];
	}

	/**
	 * `<!-->`・`<!--->` はブラウザと同じくその場で閉じたコメントとし（kses は次の `-->` までを隠す）、コメントの中では区切りによらず
	 * `<script>` を開始タグと見なす（kses は `<` を含むコメントを文字にする）。JS が文字として出ず、後ろの説明が残ること
	 * （コメントの残りの見え方は kses の扱いのままなので、正確な文字列では確かめない。review-loop R2-1）。
	 *
	 * @dataProvider comments_hiding_scripts
	 */
	public function test_scripts_around_comments_do_not_leak_and_the_rest_survives( string $html ): void {
		$sanitized = HtmlText::sanitize_post_html( $html );

		$this->assertStringNotContainsString( 'x()', $sanitized );
		$this->assertStringNotContainsString( 'y()', $sanitized );
		$this->assertStringEndsWith( 'tail', $sanitized );
	}

	/**
	 * `<!-->`・`<!--->` の後ろの文字を残さず、コメントとして読み飛ばさない（kses は空のコメントを消す）。
	 */
	public function test_abruptly_closed_comments_keep_the_following_text(): void {
		$this->assertSame( 'tail', HtmlText::sanitize_post_html( '<!-->tail' ) );
		$this->assertSame( 'tail', HtmlText::sanitize_post_html( '<!--->tail' ) );
		$this->assertSame( '<p>a</p>tail--&gt;', HtmlText::sanitize_post_html( '<!--><p>a</p>tail-->' ), '後ろの `-->` までを隠さない' );
	}

	/**
	 * 閉じタグの無い開始タグが多い入力でも、閉じタグを探し直さない（探し直すと二乗になり、約 180KB で 1.6 秒かかった。review-loop R2-3）。
	 * 時間で確かめる（この約 370KB の入力は、探し直さなければ 0.1 秒前後、探し直すと数秒。テストの並行実行で 1 秒を超えたことがあるので 3 秒にする）。
	 */
	public function test_many_unclosed_openers_are_handled_in_linear_time(): void {
		$html    = str_repeat( '<script>', 25000 ) . str_repeat( '<style>', 25000 );
		$started = microtime( true );

		$this->assertSame( '', HtmlText::sanitize_post_html( $html ) );
		$this->assertLessThan( 3.0, microtime( true ) - $started );
	}

	/**
	 * ブラウザが表示する文字（ColorMe のストアフロントは商品名を見出しにエスケープせずに出す。R3-1f）。
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function visible_texts(): array {
		return [
			'unknown tag'                 => [ 'Tom & Jerry <set>', 'Tom & Jerry' ],
			'decoration'                  => [ '<span style="color:red">【送料無料】</span>Tシャツ', '【送料無料】Tシャツ' ],
			'line break'                  => [ '商品名<br>サブタイトル', '商品名 サブタイトル' ],
			'self-closing line break'     => [ 'A<br />B', 'A B' ],
			'closing line break'          => [ 'A</br>B', 'A B' ],
			'spaces around a line break'  => [ "A \t<BR>\n B", 'A B' ],
			'block elements'              => [ '<div>Cotton</div><div>Shirt</div>', 'Cotton Shirt' ],
			'paragraphs'                  => [ '<p>A</p><p>B</p>', 'A B' ],
			'list items'                  => [ '<ul><li>A</li><li>B</li></ul>', 'A B' ],
			'horizontal rule'             => [ 'A<hr>B', 'A B' ],
			'inline elements stay joined' => [ '<span>A</span><b>B</b>', 'AB' ],
			'named entity'                => [ 'Q&amp;A &hearts;', 'Q&A ♥' ],
			'numeric entities'            => [ '&#9829;&#x2665;', '♥♥' ],
			'no-break spaces are kept'    => [ 'A&nbsp;&nbsp;B', "A\u{00A0}\u{00A0}B" ],
			'lone ampersand'              => [ 'AT&T', 'AT&T' ],
			'less-than before a digit'    => [ '1<2', '1<2' ],
			'less-than before a space'    => [ '容量 < 500ml', '容量 < 500ml' ],
			'script contents'             => [ 'A<script>alert(1)</script>B', 'AB' ],
			'style contents'              => [ 'A<style>.x{color:red}</style>B', 'AB' ],
			'comment'                     => [ 'A<!-- note -->B', 'AB' ],
			'unclosed tag'                => [ 'A <b', 'A' ],
			'whitespace left by tags'     => [ " <b> A </b>\t\n<i>B</i>  C ", 'A B C' ],
			'template contents'           => [ 'A<template>internal</template>B', 'AB' ],
			'nested templates'            => [ 'A<template><b>x</b><template>y</template>z</template>B', 'AB' ],
			'unclosed template'           => [ 'A<template>rest', 'A' ],
			'noscript contents'           => [ 'A<noscript>B</noscript>C', 'AC' ],
			'stray template closer'       => [ 'A</template>B<template>C</template>D', 'ABD' ],
			'mismatched hidden closer'    => [ 'A<template>x</noscript>y</template>B', 'AB' ],
			'noscript is raw text'        => [ 'A<noscript><template>x</noscript>y</template>z</noscript>B', 'AyzB' ],
			'noscript inside template'    => [ 'A<template><noscript>x</template>y</noscript>z</template>B', 'AB' ],
			'closers inside noscript'     => [ 'A<noscript>x</b>y</noscript>B', 'AB' ],
			'unclosed noscript'           => [ 'A<noscript>rest', 'A' ],
			'spaces in pre are collapsed' => [ "<pre>A  B\nC</pre>", 'A B C' ],
			'tags only'                   => [ '<b></b>', '' ],
		];
	}

	/**
	 * @dataProvider visible_texts
	 */
	public function test_visible_text_is_what_a_browser_displays( string $html, string $expected ): void {
		$this->assertSame( $expected, HtmlText::visible_text( $html ) );
	}

	/**
	 * `<` も `&` も無い文字列は何も変えない（空白の連続・前後の空白も。既存の取込みの checksum を変えないため）。
	 */
	public function test_visible_text_leaves_text_without_markup_untouched(): void {
		foreach ( [ '', '  A  B  ', "A\tB\nC", 'x > y', '【限定】Ｔシャツ' ] as $text ) {
			$this->assertSame( $text, HtmlText::visible_text( $text ) );
		}
	}

	public function test_visible_text_keeps_invalid_utf8_text_instead_of_failing(): void {
		$this->assertStringContainsString( '(y z', HtmlText::visible_text( "x\xC3(y <i>z</i>" ) );
	}

	public function test_allowed_html_and_entities_are_kept_as_kses_leaves_them(): void {
		$html = '<p class="x">A &amp; B <a href="https://example.com/">link</a> <strong>強調</strong></p><!-- note -->';

		$this->assertSame( wp_kses_post( $html ), HtmlText::sanitize_post_html( $html ) );
		$this->assertSame( '', HtmlText::sanitize_post_html( '' ) );
	}
}
