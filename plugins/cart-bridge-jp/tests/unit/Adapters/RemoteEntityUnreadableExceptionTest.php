<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters;

use CartBridgeJP\Adapters\RemoteEntityUnreadableException;
use RuntimeException;
use WP_UnitTestCase;

/**
 * R3-11: ID 指定取得で接続先が返した項目を読めないことを表す例外。
 */
final class RemoteEntityUnreadableExceptionTest extends WP_UnitTestCase {

	/**
	 * 文に式展開する値（プラットフォーム ID・種類のキー）は外部の拡張が渡しうるので、エスケープする（PR #136 G1-1）。
	 */
	public function test_escapes_the_interpolated_values(): void {
		$exception = new RemoteEntityUnreadableException( '<b>mock</b>', 'a"b' );

		$this->assertSame( 'Platform "&lt;b&gt;mock&lt;/b&gt;" returned the "a&quot;b" item for the given ID, but it could not be read.', $exception->getMessage() );
	}

	public function test_keeps_the_previous_exception(): void {
		$previous  = new RuntimeException( 'broken row' );
		$exception = new RemoteEntityUnreadableException( 'mock', 'product', $previous );

		$this->assertSame( $previous, $exception->getPrevious() );
		$this->assertNull( ( new RemoteEntityUnreadableException( 'mock', 'product' ) )->getPrevious() );
	}
}
