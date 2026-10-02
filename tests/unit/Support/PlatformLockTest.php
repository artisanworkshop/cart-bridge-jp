<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Support;

use CartBridgeJP\Support\PlatformBusyException;
use CartBridgeJP\Support\PlatformLock;
use DomainException;
use InvalidArgumentException;
use WP_UnitTestCase;

/**
 * 別のリクエストが保持しているロックは、options の行を直接書いて表現する（テストのトランザクションは別の
 * 接続から見えないため、本当の並行はここでは作れない。wp-env で 2 プロセスを同時に走らせて確認する）。
 */
final class PlatformLockTest extends WP_UnitTestCase {

	private PlatformLock $lock;

	public function set_up(): void {
		parent::set_up();
		$this->lock = new PlatformLock();
	}

	public function tear_down(): void {
		remove_all_filters( 'query' );
		PlatformLock::release_all();
		parent::tear_down();
	}

	private function stored_value( string $platform ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", PlatformLock::option_name( $platform ) ) );

		return null === $value ? null : (string) $value;
	}

	private function hold_elsewhere( string $platform, string $value ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => PlatformLock::option_name( $platform ),
				'option_value' => $value,
				'autoload'     => 'no',
			]
		);
	}

	public function test_a_second_acquire_fails_while_the_lock_is_held(): void {
		$handle = $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );

		$this->assertNotNull( $handle );
		$this->assertSame( $handle, $this->stored_value( 'colorme' ) );
		$this->assertNull( $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT ) );
	}

	public function test_each_platform_has_its_own_lock(): void {
		$this->assertNotNull( $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT ) );
		$this->assertNotNull( $this->lock->acquire( 'base', PlatformLock::TTL_SHORT ) );
	}

	public function test_release_frees_the_lock(): void {
		$handle = (string) $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );

		$this->lock->release( 'colorme', $handle );

		$this->assertNull( $this->stored_value( 'colorme' ) );
		$this->assertNotNull( $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT ) );
	}

	public function test_a_lock_held_elsewhere_is_not_taken_before_it_expires(): void {
		$value = ( time() + 30 ) . '|someone-else';
		$this->hold_elsewhere( 'colorme', $value );

		$this->assertNull( $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT ) );
		$this->assertSame( $value, $this->stored_value( 'colorme' ) );
	}

	public function test_an_expired_lock_is_reclaimed(): void {
		$this->hold_elsewhere( 'colorme', ( time() - 1 ) . '|crashed-request' );

		$handle = $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );

		$this->assertNotNull( $handle );
		$this->assertSame( $handle, $this->stored_value( 'colorme' ) );
	}

	/**
	 * 期限切れで回収された元の保持者が遅れて解放しても、新しい保持者のロックは消えない。
	 */
	public function test_releasing_with_a_stale_handle_keeps_the_new_holders_lock(): void {
		$stale = ( time() - 1 ) . '|slow-request';
		$this->hold_elsewhere( 'colorme', $stale );
		$handle = $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );

		$this->lock->release( 'colorme', $stale );

		$this->assertSame( $handle, $this->stored_value( 'colorme' ) );
	}

	/**
	 * @return array<string,array{0:callable():string}>
	 */
	public static function unusable_values(): array {
		return [
			'not a lock value'               => [ static fn (): string => 'garbage' ],
			'empty'                          => [ static fn (): string => '' ],
			// 整数の上限を超えた値（PHP_INT_MAX に飽和する）。
			'overflowing expiry'             => [ static fn (): string => '99999999999999999999|x' ],
			// 最長の TTL より先の期限は、このクラスが書かない値（壊れた値・時計の巻き戻り）。
			'expiry beyond the longest lock' => [ static fn (): string => ( time() + PlatformLock::TTL_LONG + 120 ) . '|x' ],
			'expiry without the separator'   => [ static fn (): string => (string) ( time() + 30 ) ],
		];
	}

	/**
	 * @dataProvider unusable_values
	 * @param callable():string $value
	 */
	public function test_an_unusable_value_is_reclaimed_instead_of_blocking_forever( callable $value ): void {
		$this->hold_elsewhere( 'colorme', $value() );

		$this->assertNotNull( $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT ) );
	}

	/**
	 * `$needle` を含む SQL が実行される直前に 1 回だけ `$interrupt` を実行する（別の要求の割り込み）。
	 */
	private function before_query( string $needle, callable $interrupt ): void {
		$filter = null;
		$filter = static function ( string $query ) use ( $needle, $interrupt, &$filter ): string {
			if ( str_contains( $query, $needle ) ) {
				remove_filter( 'query', $filter );
				$interrupt();
			}

			return $query;
		};
		add_filter( 'query', $filter );
	}

	/**
	 * 期限切れのロックを 2 つの要求がほぼ同時に回収しようとしたら、先に書き換えた側だけが取得する（CAS）。
	 */
	public function test_a_reclaim_loses_when_another_request_reclaims_first(): void {
		global $wpdb;

		$this->hold_elsewhere( 'colorme', ( time() - 1 ) . '|crashed-request' );
		$winner = ( time() + 60 ) . '|other-reclaimer';
		$this->before_query(
			"UPDATE {$wpdb->options} SET option_value",
			static function () use ( $wpdb, $winner ): void {
				$wpdb->update( $wpdb->options, [ 'option_value' => $winner ], [ 'option_name' => PlatformLock::option_name( 'colorme' ) ] );
			}
		);

		$this->assertNull( $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT ) );
		$this->assertSame( $winner, $this->stored_value( 'colorme' ) );
	}

	/**
	 * INSERT に失敗してから値を読むまでの間に解放されたロックは、もう一度 INSERT して取る。
	 */
	public function test_a_lock_released_between_the_insert_and_the_read_is_taken(): void {
		global $wpdb;

		$this->hold_elsewhere( 'colorme', ( time() + 30 ) . '|finishing-request' );
		$this->before_query(
			"SELECT option_value FROM {$wpdb->options}",
			static function () use ( $wpdb ): void {
				$wpdb->delete( $wpdb->options, [ 'option_name' => PlatformLock::option_name( 'colorme' ) ] );
			}
		);

		$handle = $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );

		$this->assertNotNull( $handle );
		$this->assertSame( $handle, $this->stored_value( 'colorme' ) );
	}

	public function test_run_returns_the_callback_value_and_releases_the_lock(): void {
		$held_inside = null;

		$result = $this->lock->run(
			'colorme',
			PlatformLock::TTL_SHORT,
			function () use ( &$held_inside ): string {
				$held_inside = $this->stored_value( 'colorme' );

				return 'done';
			}
		);

		$this->assertSame( 'done', $result );
		$this->assertNotNull( $held_inside );
		$this->assertNull( $this->stored_value( 'colorme' ) );
	}

	public function test_run_releases_the_lock_when_the_callback_throws(): void {
		try {
			$this->lock->run(
				'colorme',
				PlatformLock::TTL_SHORT,
				static function (): void {
					throw new DomainException( 'boom' );
				}
			);
			$this->fail( 'The exception should propagate.' );
		} catch ( DomainException $exception ) {
			// RuntimeException で受けると fail() の AssertionFailedError まで捕まえてしまうため、別系統の例外を使う。
			$this->assertSame( 'boom', $exception->getMessage() );
		}

		$this->assertNull( $this->stored_value( 'colorme' ) );
	}

	public function test_run_throws_without_calling_back_while_the_lock_is_held(): void {
		$this->hold_elsewhere( 'colorme', ( time() + 30 ) . '|someone-else' );
		$called = false;

		try {
			$this->lock->run(
				'colorme',
				PlatformLock::TTL_SHORT,
				static function () use ( &$called ): void {
					$called = true;
				}
			);
			$this->fail( 'PlatformBusyException should be thrown.' );
		} catch ( PlatformBusyException $exception ) {
			$this->assertSame( 'colorme', $exception->platform() );
		}

		$this->assertFalse( $called );
	}

	/**
	 * shutdown（致命的エラー・実行時間切れで `finally` が走らない場合）に、このリクエストのロックだけを解放する。
	 */
	public function test_release_all_releases_only_the_locks_this_request_holds(): void {
		$this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );
		$other = ( time() + 30 ) . '|another-request';
		$this->hold_elsewhere( 'base', $other );

		PlatformLock::release_all();

		$this->assertNull( $this->stored_value( 'colorme' ) );
		$this->assertSame( $other, $this->stored_value( 'base' ) );
	}

	/**
	 * 解放済みのロックは shutdown の対象から外れる（期限切れで他者が取り直した行を shutdown で消さない）。
	 */
	public function test_release_all_skips_locks_already_released(): void {
		$handle = (string) $this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );
		$this->lock->release( 'colorme', $handle );
		$this->hold_elsewhere( 'colorme', ( time() + 30 ) . '|next-holder' );

		PlatformLock::release_all();

		$this->assertNotNull( $this->stored_value( 'colorme' ) );
	}

	public function test_the_lock_row_is_not_autoloaded(): void {
		global $wpdb;

		$this->lock->acquire( 'colorme', PlatformLock::TTL_SHORT );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", PlatformLock::option_name( 'colorme' ) ) );

		$this->assertNotContains( $autoload, wp_autoload_values_to_autoload() );
	}

	public function test_a_long_platform_key_still_fits_the_option_name(): void {
		$platform = str_repeat( 'p', 300 );

		$this->assertLessThanOrEqual( 191, strlen( PlatformLock::option_name( $platform ) ) );
		$this->assertNotNull( $this->lock->acquire( $platform, PlatformLock::TTL_SHORT ) );
		$this->assertNull( $this->lock->acquire( $platform, PlatformLock::TTL_SHORT ) );
	}

	/**
	 * @return array<string,array{0:int}>
	 */
	public static function invalid_ttls(): array {
		return [
			'zero'                 => [ 0 ],
			'longer than TTL_LONG' => [ PlatformLock::TTL_LONG + 1 ],
		];
	}

	/**
	 * @dataProvider invalid_ttls
	 */
	public function test_the_ttl_must_be_within_the_supported_range( int $ttl ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->lock->acquire( 'colorme', $ttl );
	}
}
