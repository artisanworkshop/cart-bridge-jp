<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * `cbjp_logs` の保持期間クリーンアップ（日次、Action Scheduler）。
 */
final class LogCleanup {

	public const ACTION_HOOK            = 'cbjp_cleanup_logs';
	public const DEFAULT_RETENTION_DAYS = 30;

	public function __construct( private readonly LogRepository $logs ) {}

	/**
	 * `plugins_loaded` 等から一度だけ呼び、日次スケジュールを保証する。
	 */
	public function schedule(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::ACTION_HOOK, [], 'cart-bridge-jp' ) ) {
			return;
		}

		as_schedule_recurring_action( time(), DAY_IN_SECONDS, self::ACTION_HOOK, [], 'cart-bridge-jp' );
	}

	/**
	 * Action Schedulerのアクションコールバック本体。
	 */
	public function run(): void {
		/**
		 * ログ保持日数。
		 *
		 * @param int $retention_days
		 */
		$retention_days = (int) apply_filters( 'cbjp/logs/retention_days', self::DEFAULT_RETENTION_DAYS );

		if ( $retention_days <= 0 ) {
			return;
		}

		$this->logs->delete_older_than( $retention_days );
	}
}
