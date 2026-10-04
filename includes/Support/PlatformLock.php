<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Support;

use InvalidArgumentException;

/**
 * プラットフォーム単位の短時間の排他ロック（R3-0i・issue #57）。
 *
 * run の開始・Retry・各種ツールの「同時実行の判定 → 状態変更」の区間を囲み、ほぼ同時に届いた要求が
 * 互いの変更前の状態を見て両方とも判定を通る（check-then-act の競合）のを防ぐ。run 全体は囲まない
 * （run の実行中は `cbjp_jobs` の進行中のジョブが同時実行の判定を担う）。
 *
 * 方式は core の `WP_Upgrader::create_lock()` と同じ「options への一意な `INSERT IGNORE`」。`GET_LOCK()` は
 * Galera クラスタや一部の DB プロキシで期待どおりに動かないため使わない。core との違い:
 * - 値は `"{期限の UNIX 時刻}|{UUID}"` で、取得時の値そのものをハンドルにする。解放は値の一致で消す
 *   （期限切れで他者が取り直したロックを、遅れて終わった元の保持者が消さない）。
 * - 期限切れの回収は値を比べて上書きする CAS（`TokenStore::acquire_refresh_lock()` と同じ）。core の
 *   「無条件に消して取り直す」は、同時に回収した 2 者が両方とも取得しうる。
 * - 期限は区間ごとに渡す。保持中のリクエストが致命的エラー・実行時間切れ・接続断で終わった場合も
 *   shutdown で解放する（`finally` はこれらで走らない）。期限はプロセスが強制終了されたときの安全網。
 *
 * 再入はできない（同じリクエストの中で同じプラットフォームのロックを入れ子に取ると、内側が取得に失敗する）。
 */
final class PlatformLock {

	/**
	 * ミリ秒で終わる区間（run の開始・Retry・エクスポート設定の保存）。
	 */
	public const TTL_SHORT = 60;

	/**
	 * ASP を呼びうる区間（ツールの 1 バッチ・push intent の解除）。期限は区間が最も長引く場合から決める（PR #96 G1-1・G2-1）:
	 * - HTTP 1 本は最悪約 540 秒（`HttpClient` の試行 4 回 × [レート制限の待ち 60 秒＋タイムアウト 30 秒]＋`Retry-After` の待ち 60 秒 × 3）。
	 * - 照会 1 件で HTTP は最大 3 本（ColorMe の受注は `sales/{id}` に加え、変換器の初回に `payments.json`・`deliveries.json`。
	 *   商品は初回に `shop.json` を足して 2 本、顧客は 1 本）＝最悪約 1,620 秒。
	 * - 県コード修復は 120 秒を過ぎたら新しい行に取りかからない（`PrefStateRepair::TIME_BUDGET_SECONDS`）ので、1 バッチは最長
	 *   120＋1,620 秒。push intent の解除は照会 1 件。クリーンアップ・再構築は ASP を呼ばない。
	 * これに余裕を持たせて core の `WP_Upgrader::create_lock()` の既定と同じ 1 時間にする（前提は `PlatformLockTest` が固定する）。
	 * 照会の遅い外部アダプタはこの見積もりの外。プロセスが強制終了されて残ったロックは、最長この時間プラットフォームを塞ぐ
	 * （致命的エラー・実行時間切れ・接続断は shutdown で解放する）。
	 * 取得できる期限の上限でもある（これに時計のずれの余裕 `CLOCK_SKEW_SECONDS` を足したより先の期限を持つ行は、
	 * 壊れた値として回収する）。
	 */
	public const TTL_LONG = 3600;

	/**
	 * 「期限が最長の TTL より先なら壊れた値」と判定するときの余裕。判定する側の `time()` は保持する側より
	 * 遅れうる（INSERT の前に読んだ時刻が秒境界をまたぐ・Web ノード間の時計のずれ）ため、余裕が無いと
	 * 取得したばかりの `TTL_LONG` のロックを壊れた値とみなして奪ってしまう。
	 */
	private const CLOCK_SKEW_SECONDS = 300;

	private const OPTION_PREFIX = 'cbjp_platform_lock_';

	/**
	 * このリクエストが保持しているロック（option 名 => ハンドル）。shutdown で解放する対象。
	 *
	 * @var array<string,string>
	 */
	private static array $held = [];

	private static bool $shutdown_registered = false;

	/**
	 * ロックを取得して `$callback` を実行し、終わったら（例外でも）解放する。
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 * @throws PlatformBusyException 同じプラットフォームのロックを別の操作が保持している場合。
	 */
	public function run( string $platform, int $ttl_seconds, callable $callback ): mixed {
		$handle = $this->acquire( $platform, $ttl_seconds );

		if ( null === $handle ) {
			throw new PlatformBusyException( $platform );
		}

		try {
			return $callback();
		} finally {
			$this->release( $platform, $handle );
		}
	}

	/**
	 * ロックを取得する。通常は `run()` を使う（取得したら必ず `release()` すること）。
	 *
	 * @return string|null 取得できたらハンドル（`release()` に渡す）。保持されていれば null。
	 */
	public function acquire( string $platform, int $ttl_seconds ): ?string {
		global $wpdb;

		if ( $ttl_seconds <= 0 || $ttl_seconds > self::TTL_LONG ) {
			throw new InvalidArgumentException( 'The lock TTL must be between 1 second and TTL_LONG.' );
		}

		$option = self::option_name( $platform );
		$now    = time();
		$handle = ( $now + $ttl_seconds ) . '|' . wp_generate_uuid4();

		if ( $this->insert( $option, $handle ) ) {
			return $this->hold( $option, $handle );
		}

		// object cache を通さずに読む（`get_option()` はキャッシュ済みの古い値・notoptions を返しうる）。
		// `get_var()` は空文字列を null に変えて「行が無い」と区別できないため、行ごと読む。
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 排他ロックの現在値を直接読む。
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			// INSERT と SELECT の間に解放された。1 回だけ取り直す（取れなければ他者が取った）。
			return $this->insert( $option, $handle ) ? $this->hold( $option, $handle ) : null;
		}

		$current    = (string) $row['option_value'];
		$expires_at = self::expires_at( $current );

		// 読めない値と、期限が最長の TTL（＋時計のずれの余裕）より先の値（壊れた値・時計の巻き戻り）は
		// 期限切れとして回収する。このクラスは常にその範囲の値を書くため、保持中とみなすと、壊れた行が
		// 誰にも解放されずプラットフォームを長期間（永久に）塞ぐ。
		if ( null !== $expires_at && $expires_at > $now && $expires_at <= $now + self::TTL_LONG + self::CLOCK_SKEW_SECONDS ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS による期限切れロックの原子的な回収。
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$handle,
				$option,
				$current
			)
		);

		return 1 === $updated ? $this->hold( $option, $handle ) : null;
	}

	/**
	 * ロックを解放する。ハンドルが一致するときだけ消す（期限切れで他者が取り直したロックは消さない）。
	 */
	public function release( string $platform, string $handle ): void {
		self::delete_if_held( self::option_name( $platform ), $handle );
	}

	/**
	 * このリクエストが保持しているロックをすべて解放する（shutdown 用）。
	 */
	public static function release_all(): void {
		foreach ( self::$held as $option => $handle ) {
			self::delete_if_held( $option, $handle );
		}
	}

	public static function option_name( string $platform ): string {
		// アダプタの登録キーは外部コードが決めるため長さ・文字を保証できない（option_name は 191 文字まで）。
		return self::OPTION_PREFIX . md5( $platform );
	}

	/**
	 * @phpstan-impure DB への書込み。同じ引数でも 1 回目と 2 回目で結果が変わる。
	 */
	private function insert( string $option, string $handle ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 排他ロックのため一意キーへの INSERT で原子的に作成する。
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option,
				$handle
			)
		);

		// false（接続断・Galera の certification 失敗等）も取得失敗に倒す。
		return 1 === $inserted;
	}

	private function hold( string $option, string $handle ): string {
		self::$held[ $option ] = $handle;

		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( [ self::class, 'release_all' ] );
			self::$shutdown_registered = true;
		}

		return $handle;
	}

	private static function delete_if_held( string $option, string $handle ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 自分のハンドルのときだけ消す比較付き DELETE。
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				$handle
			)
		);

		if ( ( self::$held[ $option ] ?? null ) === $handle ) {
			unset( self::$held[ $option ] );
		}
	}

	private static function expires_at( string $value ): ?int {
		return 1 === preg_match( '/\A(\d+)\|/', $value, $matches ) ? (int) $matches[1] : null;
	}
}
