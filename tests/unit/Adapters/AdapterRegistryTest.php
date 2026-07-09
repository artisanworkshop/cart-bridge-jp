<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_UnitTestCase;

final class AdapterRegistryTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		parent::tear_down();
	}

	public function test_all_is_empty_when_nothing_registered(): void {
		AdapterRegistry::reset_cache();

		$this->assertSame( [], AdapterRegistry::all() );
		$this->assertNull( AdapterRegistry::get( 'colorme' ) );
		$this->assertFalse( AdapterRegistry::has( 'colorme' ) );
	}

	public function test_registers_adapter_via_filter(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$this->assertTrue( AdapterRegistry::has( 'mock' ) );
		$this->assertInstanceOf( PlatformAdapter::class, AdapterRegistry::get( 'mock' ) );
	}
}
