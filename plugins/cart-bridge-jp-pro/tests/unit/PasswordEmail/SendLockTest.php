<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\PasswordEmail;

use CartBridgeJP\Pro\PasswordEmail\SendLock;
use WP_UnitTestCase;

/**
 * パスワード設定メールの 1 バッチの排他（R3-10）。
 */
final class SendLockTest extends WP_UnitTestCase {

	public function test_only_one_holder_at_a_time(): void {
		$first = SendLock::acquire();

		$this->assertNotNull( $first );
		$this->assertNull( SendLock::acquire() );

		SendLock::release( $first );

		$this->assertNotNull( SendLock::acquire() );
	}

	/**
	 * 期限切れ・読めない値・期限が先すぎる値（壊れた値・時計の巻き戻り）は回収する（永久に塞がない）。
	 *
	 * @dataProvider reclaimable_values
	 */
	public function test_reclaims_a_lock_that_cannot_be_held( string $value ): void {
		$this->write_lock( $value );

		$this->assertNotNull( SendLock::acquire() );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function reclaimable_values(): array {
		return [
			'expired'     => [ ( time() - 1 ) . '|' . str_repeat( 'a', 32 ) ],
			'unreadable'  => [ 'broken' ],
			'far ahead'   => [ ( time() + SendLock::TTL + SendLock::MARGIN + 600 ) . '|' . str_repeat( 'a', 32 ) ],
			'empty value' => [ '' ],
		];
	}

	public function test_does_not_release_a_lock_held_by_another_batch(): void {
		$held = SendLock::acquire();

		SendLock::release( time() . '|' . str_repeat( 'b', 32 ) );

		$this->assertNull( SendLock::acquire() );
		$this->assertNotNull( $held );
	}

	/**
	 * 期限の手前（余裕より短い）では、もう送らない（期限の後は他のバッチが奪いうる）。
	 */
	public function test_tells_whether_enough_time_is_left(): void {
		$this->assertTrue( SendLock::still_held( ( time() + SendLock::TTL ) . '|' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( SendLock::still_held( ( time() + SendLock::MARGIN ) . '|' . str_repeat( 'a', 32 ) ) );
		$this->assertFalse( SendLock::still_held( 'broken' ) );
	}

	private function write_lock( string $value ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", SendLock::OPTION, $value ) );
	}
}
