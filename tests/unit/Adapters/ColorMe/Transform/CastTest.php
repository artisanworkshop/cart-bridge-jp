<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\Cast;
use WP_UnitTestCase;

final class CastTest extends WP_UnitTestCase {

	public function test_to_string_or_null_normalizes_empty_string_to_null(): void {
		$this->assertNull( Cast::to_string_or_null( '' ) );
		$this->assertNull( Cast::to_string_or_null( null ) );
		$this->assertSame( '0', Cast::to_string_or_null( 0 ) );
		$this->assertSame( 'abc', Cast::to_string_or_null( 'abc' ) );
	}

	public function test_to_string_or_null_rejects_non_scalar(): void {
		$this->assertNull( Cast::to_string_or_null( [ 'a' ] ) );
	}

	public function test_to_int_or_null_handles_numeric_strings_and_floats(): void {
		$this->assertSame( 5, Cast::to_int_or_null( 5 ) );
		$this->assertSame( 5, Cast::to_int_or_null( '5' ) );
		$this->assertSame( 5, Cast::to_int_or_null( 5.9 ) );
		$this->assertNull( Cast::to_int_or_null( 'not-a-number' ) );
		$this->assertNull( Cast::to_int_or_null( null ) );
	}

	public function test_to_bool_or_null_preserves_null_instead_of_coercing_to_false(): void {
		$this->assertNull( Cast::to_bool_or_null( null ) );
		$this->assertTrue( Cast::to_bool_or_null( true ) );
		$this->assertFalse( Cast::to_bool_or_null( false ) );
		// 文字列やintはbool以外なのでnullとして扱う（誤ってtrue/falseに丸めない）。
		$this->assertNull( Cast::to_bool_or_null( 1 ) );
	}

	public function test_money_formats_without_thousands_separator(): void {
		$this->assertSame( '3080', Cast::money( 3080 ) );
		$this->assertSame( '0', Cast::money( null ) );
	}

	public function test_unix_to_iso_converts_to_utc(): void {
		$this->assertSame( '2026-07-01T00:00:00+00:00', Cast::unix_to_iso( 1782864000 ) );
		$this->assertNull( Cast::unix_to_iso( null ) );
	}

	public function test_first_non_empty_returns_first_present_candidate(): void {
		$this->assertSame( 'b', Cast::first_non_empty( null, '', 'b', 'c' ) );
		$this->assertNull( Cast::first_non_empty( null, '' ) );
	}

	public function test_sanitize_html_strips_disallowed_tags(): void {
		// wp_kses_post はタグ自体を除去するが、script/style以外の要素は中のテキストを残す。
		$this->assertSame( 'safe <strong>text</strong>alert(1)', Cast::sanitize_html( 'safe <strong>text</strong><script>alert(1)</script>' ) );
		$this->assertSame( 'safe <strong>text</strong>', Cast::sanitize_html( 'safe <strong>text</strong><iframe src="https://evil.example.com"></iframe>' ) );
		$this->assertNull( Cast::sanitize_html( null ) );
	}

	public function test_category_ref_combines_big_and_small(): void {
		$this->assertSame( '100', Cast::category_ref( 100, 0 ) );
		$this->assertSame( '100', Cast::category_ref( 100, null ) );
		$this->assertSame( '100-5', Cast::category_ref( 100, 5 ) );
		$this->assertNull( Cast::category_ref( null, 5 ) );
	}
}
