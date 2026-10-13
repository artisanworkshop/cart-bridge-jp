<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

/**
 * パスワード設定メールの 1 バッチの排他（R3-10）。同じ run のバッチが 2 つ同時に動かないようにする（Action Scheduler の 2 つのランナー・再開の
 * 直後に前のチェーンのバッチが残っていた、など）。無料版の `Support\PlatformLock` と同じ「options への一意な `INSERT IGNORE`」で、
 * 値は `"{期限の UNIX 時刻}|{乱数}"`。期限切れの行は値を比べて奪い（CAS）、解放は値が自分のものと一致するときだけ消す。
 */
final class SendLock {

	public const OPTION = 'cbjp_pro_password_email_lock';

	/**
	 * 期限。1 バッチ（`Sender::TIME_BUDGET_SECONDS`）より十分長く、Action Scheduler が in-progress を失敗扱いにする 5 分より短くはしない
	 * （処理中に期限が来て奪われないよう、送る前に {@see self::still_held()} で残りを確かめる）。
	 */
	public const TTL = 300;

	/** 送る前に残っているべき期限の余裕（1 通の送信がこれより長引いても、期限の後に送らない）。 */
	public const MARGIN = 60;

	private function __construct() {}

	/**
	 * @return string|null 取れたらハンドル（{@see self::release()} に渡す）。他のバッチが持っていれば null。
	 */
	public static function acquire(): ?string {
		$now    = time();
		$handle = ( $now + self::TTL ) . '|' . bin2hex( random_bytes( 16 ) );

		if ( self::insert( $handle ) ) {
			return $handle;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 排他の現在値を直接読む。
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ), ARRAY_A );

		if ( ! is_array( $row ) ) {
			// 読む前に解放された。1 回だけ取り直す。
			return self::insert( $handle ) ? $handle : null;
		}

		$current    = (string) $row['option_value'];
		$expires_at = self::expires_at( $current );

		// 期限内なら他のバッチが持っている。読めない値・期限が TTL より先の値（壊れた値・時計の巻き戻り）は回収する（永久に塞がない）。
		if ( null !== $expires_at && $expires_at > $now && $expires_at <= $now + self::TTL + self::MARGIN ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 期限切れの排他を CAS で原子的に回収する。
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$handle,
				self::OPTION,
				$current
			)
		);

		return 1 === $updated ? $handle : null;
	}

	/**
	 * 送ってよいだけの期限が残っているか（期限の後は他のバッチが奪いうる）。
	 */
	public static function still_held( string $handle ): bool {
		$expires_at = self::expires_at( $handle );

		return null !== $expires_at && time() + self::MARGIN < $expires_at;
	}

	public static function release( string $handle ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自分の排他だけを消す（期限切れで他のバッチが取り直した行は消さない）。
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, $handle ) );
		wp_cache_delete( self::OPTION, 'options' );
	}

	/**
	 * @phpstan-impure DB への書込み。
	 */
	private static function insert( string $handle ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 一意キーへの INSERT で原子的に取る。
		$inserted = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION, $handle )
		);

		return 1 === $inserted;
	}

	private static function expires_at( string $value ): ?int {
		if ( 1 !== preg_match( '/^([0-9]{1,12})\|[0-9a-f]{32}\z/', $value, $matches ) ) {
			return null;
		}

		return (int) $matches[1];
	}
}
