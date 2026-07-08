<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

/**
 * プラットフォームごとのトークンバケット式レート制限。
 *
 * 状態は `wp_options`（`cbjp_rate_limit_{platform}`, autoload無効）に保存し、
 * Action Scheduler の並列実行に対応するため楽観的排他制御（CAS: compare-and-swap）で
 * 更新の競合を回避する。
 */
final class RateLimiter {

	private const POLL_INTERVAL_MICROSECONDS = 200_000;
	private const MAX_CAS_ATTEMPTS           = 20;

	public function __construct(
		private readonly string $platform,
		private readonly int $capacity_per_minute
	) {}

	/**
	 * トークンを即座に消費できれば true、枯渇していれば false を返す（待機はしない）。
	 */
	public function try_consume( int $tokens = 1 ): bool {
		global $wpdb;

		$option_name = $this->option_name();

		for ( $attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CASのためオプションキャッシュを介さず直接読む必要がある。
			$current_raw = $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name )
			);

			$now = time();

			if ( null === $current_raw ) {
				$bucket = [
					'tokens'     => $this->capacity_per_minute,
					'updated_at' => $now,
				];

				if ( ! $this->insert_if_absent( $option_name, $bucket ) ) {
					continue;
				}

				$current_raw = wp_json_encode( $bucket );
			} else {
				$decoded = json_decode( (string) $current_raw, true );
				$bucket  = is_array( $decoded ) ? $decoded : [
					'tokens'     => $this->capacity_per_minute,
					'updated_at' => $now,
				];
				$bucket  = $this->refill( $bucket, $now );
			}

			if ( $bucket['tokens'] < $tokens ) {
				return false;
			}

			$bucket['tokens'] -= $tokens;
			$new_raw           = wp_json_encode( $bucket );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CASによる原子的更新のため直接クエリが必要。
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					$new_raw,
					$option_name,
					$current_raw
				)
			);

			if ( $updated > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * トークンを確保できるまで待機する。Action Scheduler内での実行を前提に同期的にスリープする。
	 *
	 * @throws RateLimitExhaustedException 最大待機時間を超えた場合。呼び出し側（JobManager）はジョブを paused にする。
	 */
	public function wait( int $tokens = 1, int $max_wait_seconds = 60 ): void {
		$start = microtime( true );

		while ( ! $this->try_consume( $tokens ) ) {
			if ( microtime( true ) - $start >= $max_wait_seconds ) {
				throw new RateLimitExhaustedException( $this->platform );
			}

			usleep( self::POLL_INTERVAL_MICROSECONDS );
		}
	}

	/**
	 * @param array{tokens:int,updated_at:int} $bucket
	 * @return array{tokens:int,updated_at:int}
	 */
	private function refill( array $bucket, int $now ): array {
		$elapsed_minutes = max( 0, $now - $bucket['updated_at'] ) / 60;

		if ( $elapsed_minutes <= 0 ) {
			return $bucket;
		}

		$refill_amount = (int) floor( $elapsed_minutes * $this->capacity_per_minute );

		if ( $refill_amount <= 0 ) {
			return $bucket;
		}

		return [
			'tokens'     => min( $this->capacity_per_minute, $bucket['tokens'] + $refill_amount ),
			'updated_at' => $now,
		];
	}

	/**
	 * @param array{tokens:int,updated_at:int} $bucket
	 */
	private function insert_if_absent( string $option_name, array $bucket ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 初期化行の作成もCAS方式の一部。
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option_name,
				wp_json_encode( $bucket )
			)
		);

		return false !== $result && $result > 0;
	}

	private function option_name(): string {
		return 'cbjp_rate_limit_' . $this->platform;
	}
}
