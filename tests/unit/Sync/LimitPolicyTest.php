<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use WP_UnitTestCase;

final class LimitPolicyTest extends WP_UnitTestCase {

	private MappingRepository $mappings;
	private LimitPolicy $limits;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->mappings = new MappingRepository();
		$this->limits   = new LimitPolicy( $this->mappings );
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/limits/product' );
		remove_all_filters( 'cbjp/limits/category' );
		parent::tear_down();
	}

	public function test_default_limits_match_the_design_doc_table(): void {
		$this->assertNull( $this->limits->limit_for( 'category' ) );
		$this->assertNull( $this->limits->limit_for( 'tag' ) );
		$this->assertSame( 50, $this->limits->limit_for( 'product' ) );
		$this->assertSame( 10, $this->limits->limit_for( 'customer' ) );
		$this->assertSame( 10, $this->limits->limit_for( 'order' ) );
		$this->assertSame( 10, $this->limits->limit_for( 'coupon' ) );
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
}
