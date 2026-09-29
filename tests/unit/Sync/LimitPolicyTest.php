<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PushIntentRepository;
use WP_UnitTestCase;

final class LimitPolicyTest extends WP_UnitTestCase {

	private MappingRepository $mappings;
	private PushIntentRepository $push_intents;
	private LimitPolicy $limits;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->mappings     = new MappingRepository();
		$this->push_intents = new PushIntentRepository();
		$this->limits       = new LimitPolicy( $this->mappings, $this->push_intents );
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/limits/product' );
		remove_all_filters( 'cbjp/limits/category' );
		remove_all_filters( 'cbjp/limits/pro_url' );
		parent::tear_down();
	}

	/**
	 * issue #55: 既定では Pro 版の案内先が無く、管理画面は Pro 版に触れない。
	 */
	public function test_pro_url_is_empty_by_default(): void {
		$this->assertSame( '', $this->limits->pro_url() );
	}

	/**
	 * @dataProvider valid_pro_urls
	 */
	public function test_pro_url_returns_a_valid_http_or_https_url( string $url, string $expected ): void {
		add_filter( 'cbjp/limits/pro_url', static fn () => $url );

		$this->assertSame( $expected, $this->limits->pro_url() );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function valid_pro_urls(): array {
		return [
			'https with a query string' => [ 'https://example.com/pro?utm_source=a&utm_medium=b', 'https://example.com/pro?utm_source=a&utm_medium=b' ],
			'http'                      => [ 'http://example.com/pro', 'http://example.com/pro' ],
			// `esc_url_raw()` は scheme を小文字にする（実測）。
			'upper-case scheme'         => [ 'HTTPS://EXAMPLE.COM/pro', 'https://EXAMPLE.COM/pro' ],
			'surrounding whitespace'    => [ "  https://example.com/pro\n", 'https://example.com/pro' ],
		];
	}

	/**
	 * フィルターの戻り値は外部コード由来の信頼境界（原則8）: http/https で host のある URL 以外は `''` に倒す。
	 *
	 * @dataProvider invalid_pro_urls
	 */
	public function test_pro_url_falls_back_to_empty_for_an_invalid_filter_value( mixed $value ): void {
		add_filter( 'cbjp/limits/pro_url', static fn () => $value );

		$this->assertSame( '', $this->limits->pro_url() );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function invalid_pro_urls(): array {
		return [
			'javascript scheme' => [ 'javascript:alert(1)' ],
			'data scheme'       => [ 'data:text/html,<script>alert(1)</script>' ],
			'ftp scheme'        => [ 'ftp://example.com/pro' ],
			'protocol-relative' => [ '//example.com/pro' ],
			// `esc_url_raw()` は scheme の無い値に `http://` を補って通してしまう。
			'no scheme'         => [ 'example.com/pro' ],
			'no host'           => [ 'https://' ],
			// scheme はあるが host が無い（`wp_parse_url()` は host を返さず、`esc_url_raw()` はそのまま通す。実測）。
			'scheme without //' => [ 'https:example.com/pro' ],
			'scheme with one /' => [ 'https:/example.com/pro' ],
			'whitespace only'   => [ '   ' ],
			'array'             => [ [ 'https://example.com/pro' ] ],
			'integer'           => [ 1 ],
			'null'              => [ null ],
			'object'            => [ new \stdClass() ],
		];
	}

	public function test_default_limits_match_the_design_doc_table(): void {
		$this->assertNull( $this->limits->limit_for( 'category' ) );
		$this->assertNull( $this->limits->limit_for( 'tag' ) );
		$this->assertSame( 50, $this->limits->limit_for( 'product' ) );
		$this->assertSame( 10, $this->limits->limit_for( 'customer' ) );
		$this->assertSame( 10, $this->limits->limit_for( 'order' ) );
		$this->assertSame( 10, $this->limits->limit_for( 'coupon' ) );
		// stock/review はサンプル商品へのメンバーシップで制限され、数値上限は持たない（§10.2）。
		$this->assertNull( $this->limits->limit_for( 'stock' ) );
		$this->assertNull( $this->limits->limit_for( 'review' ) );
	}

	public function test_is_exceeded_becomes_true_once_the_limit_is_reached(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->mappings->upsert( 'mock', 'order', (string) $i, $i, null );
		}

		$this->assertTrue( $this->limits->is_exceeded( 'mock', 'order' ) );
		$this->assertSame( 0, $this->limits->remaining( 'mock', 'order' ) );
	}

	public function test_is_not_exceeded_below_the_limit(): void {
		$this->mappings->upsert( 'mock', 'order', '1', 1, null );

		$this->assertFalse( $this->limits->is_exceeded( 'mock', 'order' ) );
		$this->assertSame( 9, $this->limits->remaining( 'mock', 'order' ) );
	}

	public function test_pro_filter_unlocks_a_limit(): void {
		add_filter( 'cbjp/limits/product', static fn() => null );

		$this->assertNull( $this->limits->limit_for( 'product' ) );
		$this->assertFalse( $this->limits->is_exceeded( 'mock', 'product' ) );
		$this->assertNull( $this->limits->remaining( 'mock', 'product' ) );
	}

	public function test_category_is_always_unlimited(): void {
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->mappings->upsert( 'mock', 'category', (string) $i, $i, null );
		}

		$this->assertFalse( $this->limits->is_exceeded( 'mock', 'category' ) );
	}

	/**
	 * D21-B（issue #73）: 未解決push intentは「作成済みかもしれない実体」として無料版の累積
	 * カウントに含める（枠を空けない）。
	 */
	public function test_unresolved_push_intents_count_toward_the_limit(): void {
		for ( $i = 1; $i <= 9; $i++ ) {
			$this->mappings->upsert( 'mock', 'order', (string) $i, $i, null );
		}

		$this->push_intents->begin( 'mock', 'order', 100, null, null );

		$this->assertSame( 10, $this->limits->used( 'mock', 'order' ) );
		$this->assertSame( 0, $this->limits->remaining( 'mock', 'order' ) );
		$this->assertTrue( $this->limits->is_exceeded( 'mock', 'order' ) );
	}

	public function test_resolving_a_push_intent_frees_up_the_slot_again(): void {
		for ( $i = 1; $i <= 9; $i++ ) {
			$this->mappings->upsert( 'mock', 'order', (string) $i, $i, null );
		}

		$this->push_intents->begin( 'mock', 'order', 100, null, null );
		$this->assertTrue( $this->limits->is_exceeded( 'mock', 'order' ) );

		$this->push_intents->delete( 'mock', 'order', 100 );

		$this->assertFalse( $this->limits->is_exceeded( 'mock', 'order' ) );
		$this->assertSame( 1, $this->limits->remaining( 'mock', 'order' ) );
	}
}
