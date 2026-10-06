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
}
