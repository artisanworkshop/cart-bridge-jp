<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

/**
 * パスワード設定メールの送信の記録（ユーザーごとのメタ。R3-10）。
 *
 * 記録は 2 段: 送る前に `sending:{run}:{時刻}` を書き、送れたら `sent:{run}:{時刻}` にする（送る前に書くので、処理の途中で落ちても同じ顧客へ 2 通目を送らない。
 * `sending` のまま残った顧客は画面に出し、管理者が {@see self::clear_sending()} で未送信に戻す）。送れなかった顧客は記録を消して失敗の記録
 * （`{run}:{時刻}:{理由}`）を残し、次の送信で送り直す。どちらも `_cbjp_pro_*` で、取込みの extras は書けない（`CustomerWriter::writable_extras()`）。
 */
final class SendRecord {

	public const META        = '_cbjp_pro_password_email';
	public const FAILED_META = '_cbjp_pro_password_email_failed';

	public const STATE_SENDING = 'sending';
	public const STATE_SENT    = 'sent';

	private const PATTERN        = '/^(sending|sent):([0-9a-f]{32}):([0-9]{1,12})\z/';
	private const FAILED_PATTERN = '/^([0-9a-f]{32}):([0-9]{1,12}):([a-z_]{1,40})\z/';

	private function __construct() {}

	/**
	 * 送信の記録。無ければ null。形の違う値も「何かを送った・送ろうとした」として送り直さない側に倒す: `sent:` で始まる値は `sent`（「未送信に戻す」の対象の
	 * SQL〔{@see self::unsent_user_ids()}〕と同じ区切り。戻せないのに「途中で止まった」と出さない。PR #135 G2-1）、それ以外は `sending`（画面で未送信に戻せる）。
	 *
	 * @return array{state:string,run_id:string,at:int}|null
	 */
	public static function get( int $user_id ): ?array {
		if ( ! metadata_exists( 'user', $user_id, self::META ) ) {
			return null;
		}

		$value = get_user_meta( $user_id, self::META, true );

		if ( is_string( $value ) && 1 === preg_match( self::PATTERN, $value, $matches ) ) {
			return [
				'state'  => $matches[1],
				'run_id' => $matches[2],
				'at'     => (int) $matches[3],
			];
		}

		return [
			'state'  => is_string( $value ) && str_starts_with( $value, self::STATE_SENT . ':' ) ? self::STATE_SENT : self::STATE_SENDING,
			'run_id' => '',
			'at'     => 0,
		];
	}

	/**
	 * 送る前の記録を書き、DB から読み直して書けたかを確かめる（メタのフィルターに断られた・DB の失敗で書けないまま送ると、最大 1 通を守れない。PR #135 G1-6）。
	 *
	 * @return string|null 書いた値（{@see self::mark_sent()}・{@see self::mark_failed()} に渡す）。書けなければ null。
	 */
	public static function mark_sending( int $user_id, string $run_id ): ?string {
		$value = self::STATE_SENDING . ':' . $run_id . ':' . time();

		update_user_meta( $user_id, self::META, $value );
		wp_cache_delete( $user_id, 'user_meta' );

		return get_user_meta( $user_id, self::META, true ) === $value ? $value : null;
	}

	/**
	 * 送れた: 記録がまだ自分の書いた `$sending` のときだけ `sent` にし、失敗の記録を消す（送信が長引く間に管理者が未送信に戻し、別の run が送った記録を
	 * 書き換えない。PR #135 G2-2）。
	 *
	 * @return bool 書き換えたか。
	 */
	public static function mark_sent( int $user_id, string $sending, string $run_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 自分の書いた値のときだけ書き換える（CAS）。直後にキャッシュを捨てる。
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s AND meta_value = %s", self::STATE_SENT . ':' . $run_id . ':' . time(), $user_id, self::META, $sending ) );
		wp_cache_delete( $user_id, 'user_meta' );

		if ( ! is_int( $updated ) || $updated < 1 ) {
			return false;
		}

		delete_user_meta( $user_id, self::FAILED_META );

		return true;
	}

	/**
	 * 送れなかった: 記録がまだ自分の書いた `$sending` のときだけ消して、失敗の記録を残す（次の送信の相手に戻る）。書き換えられていれば何もしない
	 * （別の run が送った記録を消して 2 通目を送らせない。PR #135 G2-2）。
	 *
	 * @return bool 失敗を記録したか。
	 */
	public static function mark_failed( int $user_id, string $sending, string $run_id, string $reason ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 自分の書いた値のときだけ消す（CAS）。直後にキャッシュを捨てる。
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND meta_value = %s", $user_id, self::META, $sending ) );
		wp_cache_delete( $user_id, 'user_meta' );

		if ( ! is_int( $deleted ) || $deleted < 1 ) {
			return false;
		}

		update_user_meta( $user_id, self::FAILED_META, $run_id . ':' . time() . ':' . $reason );

		return true;
	}

	/**
	 * 前の送信で送れなかった記録。無い・読めなければ null。
	 *
	 * @return array{run_id:string,at:int,reason:string}|null
	 */
	public static function failure( int $user_id ): ?array {
		$value = get_user_meta( $user_id, self::FAILED_META, true );

		if ( ! is_string( $value ) || 1 !== preg_match( self::FAILED_PATTERN, $value, $matches ) ) {
			return null;
		}

		return [
			'run_id' => $matches[1],
			'at'     => (int) $matches[2],
			'reason' => $matches[3],
		];
	}

	/**
	 * 送れた記録（`sent:`）以外の送信の記録（`sending`・形の崩れた値）を持つユーザーの ID（昇順）。「未送信に戻す」が全候補・送れた顧客を読まずに済むように、
	 * 記録の行から引く（R1-1・R2-4）。
	 *
	 * @return array<int,int>
	 */
	public static function unsent_user_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- 管理者の操作で、送れた記録以外の記録を持つユーザーだけを引く。
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value NOT LIKE %s ORDER BY user_id ASC", self::META, $wpdb->esc_like( self::STATE_SENT . ':' ) . '%' ) );

		return array_map( 'intval', is_array( $ids ) ? $ids : [] );
	}

	/**
	 * `sending` のまま残った記録を消す（管理者が「未送信に戻す」を選んだとき）。送れた記録（`sent`）は消さない。
	 *
	 * @return bool 消したか。
	 */
	public static function clear_sending( int $user_id ): bool {
		global $wpdb;

		wp_cache_delete( $user_id, 'user_meta' );
		$record = self::get( $user_id );

		if ( null === $record || self::STATE_SENDING !== $record['state'] ) {
			return false;
		}

		// 読んだ値のままのときだけ消す（読んだ後にバッチが `sent` に書き換えていれば消さない。`delete_user_meta()` は行を選んでから ID で消すので、
		// その間の書換えも消してしまう。PR #135 G1-2）。
		$raw = maybe_serialize( get_user_meta( $user_id, self::META, true ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- 読んだ値のときだけ消す（CAS）。直後にキャッシュを捨てる。
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s AND meta_value = %s", $user_id, self::META, $raw ) );
		wp_cache_delete( $user_id, 'user_meta' );

		return is_int( $deleted ) && $deleted > 0;
	}
}
