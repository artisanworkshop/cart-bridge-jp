<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\MethodMap;
use WP_UnitTestCase;

/**
 * マッピング設定の汎用の読取り（R3-6c1 で受注の決済・配送・ステータスを `OrderMethodMap` へ分け、`lookup()`・`reverse_lookup()` を
 * Pro アドオンも使う口として公開した）。
 */
final class MethodMapTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'cbjp_settings_colorme' );

		parent::tear_down();
	}

	public function test_lookup_reads_the_value_of_a_map_key(): void {
		update_option(
			'cbjp_settings_colorme',
			[
				'category_map' => [ '15' => '200' ],
				'gizmo_map'    => [ 'red' => 'blue' ],
			]
		);
		$map = new MethodMap( 'colorme' );

		$this->assertSame( '200', $map->mapped_asp_category_id( '15' ) );
		$this->assertSame( 'blue', $map->lookup( 'gizmo_map', 'red' ) );
		$this->assertNull( $map->lookup( 'gizmo_map', 'green' ) );
		$this->assertNull( $map->lookup( 'missing_map', 'red' ) );
	}

	public function test_lookup_treats_a_broken_setting_as_unmapped(): void {
		update_option( 'cbjp_settings_colorme', [ 'gizmo_map' => 'not-a-map' ] );

		$this->assertNull( ( new MethodMap( 'colorme' ) )->lookup( 'gizmo_map', 'red' ) );

		update_option( 'cbjp_settings_colorme', 'not-an-array' );

		$this->assertNull( ( new MethodMap( 'colorme' ) )->lookup( 'gizmo_map', 'red' ) );
		$this->assertNull( ( new MethodMap( 'colorme' ) )->reverse_lookup( 'gizmo_map', 'blue' ) );
	}

	public function test_reverse_lookup_resolves_only_a_unique_match(): void {
		update_option(
			'cbjp_settings_colorme',
			[
				'gizmo_map' => [
					'red'    => 'blue',
					'orange' => 'yellow',
					'pink'   => 'yellow',
				],
			]
		);
		$map = new MethodMap( 'colorme' );

		$this->assertSame( 'red', $map->reverse_lookup( 'gizmo_map', 'blue' ) );
		$this->assertNull( $map->reverse_lookup( 'gizmo_map', 'yellow' ), '複数の ASP 側の値が同じ Woo 側の値を指すと曖昧（D19）' );
		$this->assertNull( $map->reverse_lookup( 'gizmo_map', 'purple' ) );
	}
}
