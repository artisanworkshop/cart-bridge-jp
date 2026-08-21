<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo;

use CartBridgeJP\Woo\WarningCode;
use WP_UnitTestCase;

final class WarningCodeTest extends WP_UnitTestCase {

	public function test_with_detail_omits_separator_for_empty_detail(): void {
		$this->assertSame( 'sku_duplicate', WarningCode::with_detail( 'sku_duplicate', '' ) );
	}

	public function test_split_round_trips_with_detail(): void {
		$warning = WarningCode::with_detail( WarningCode::SKU_DUPLICATE, 'ABC-1' );

		$this->assertSame( [ WarningCode::SKU_DUPLICATE, 'ABC-1' ], WarningCode::split( $warning ) );
	}

	/**
	 * detail自体（画像URL等）に`:`が含まれていても、最初の`:`でのみ分割されるため
	 * detail部分は壊れずに復元できる。
	 */
	public function test_split_handles_colon_inside_detail(): void {
		$detail  = 'https://shop.example.com/img.jpg';
		$warning = WarningCode::with_detail( WarningCode::IMAGE_DOWNLOAD_FAILED, $detail );

		$this->assertSame( [ WarningCode::IMAGE_DOWNLOAD_FAILED, $detail ], WarningCode::split( $warning ) );
	}

	public function test_split_returns_null_detail_when_no_separator(): void {
		$this->assertSame( [ WarningCode::ENTITY_NOT_SUPPORTED, null ], WarningCode::split( WarningCode::ENTITY_NOT_SUPPORTED ) );
	}
}
