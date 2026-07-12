<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Support;

use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Support\RateLimiter;
use WP_UnitTestCase;

final class RateLimiterTest extends WP_UnitTestCase {

	private function make_limiter( int $capacity_per_minute ): RateLimiter {
		return new RateLimiter( 'test-platform-' . wp_generate_uuid4(), $capacity_per_minute );
	}

	public function test_try_consume_succeeds_within_capacity_and_fails_when_exhausted(): void {
		$limiter = $this->make_limiter( 3 );

		$this->assertTrue( $limiter->try_consume() );
		$this->assertTrue( $limiter->try_consume() );
		$this->assertTrue( $limiter->try_consume() );
		$this->assertFalse( $limiter->try_consume() );
	}

	public function test_wait_returns_immediately_when_tokens_are_available(): void {
		$limiter = $this->make_limiter( 5 );

		$started = microtime( true );
		$limiter->wait();
		$elapsed = microtime( true ) - $started;

		$this->assertLessThan( 1.0, $elapsed );
	}

	public function test_wait_throws_when_bucket_stays_exhausted_past_max_wait(): void {
		$limiter = $this->make_limiter( 1 );
		$this->assertTrue( $limiter->try_consume() );

		$this->expectException( RateLimitExhaustedException::class );
		$limiter->wait( 1, 1 );
	}
}
