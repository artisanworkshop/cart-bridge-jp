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
	 * 送信の記録。無ければ null。形の違う値も「何かを送った・送ろうとした」として `sending` に倒す（送り直さない側。画面で未送信に戻せる）。
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
			'state'  => self::STATE_SENDING,
			'run_id' => '',
			'at'     => 0,
		];
	}

	public static function mark_sending( int $user_id, string $run_id ): void {
		update_user_meta( $user_id, self::META, self::STATE_SENDING . ':' . $run_id . ':' . time() );
	}

	public static function mark_sent( int $user_id, string $run_id ): void {
		update_user_meta( $user_id, self::META, self::STATE_SENT . ':' . $run_id . ':' . time() );
		delete_user_meta( $user_id, self::FAILED_META );
	}

	/**
	 * 送れなかった: 送信の記録を消し、失敗の記録を残す（次の送信の相手に戻る）。
	 */
	public static function mark_failed( int $user_id, string $run_id, string $reason ): void {
		delete_user_meta( $user_id, self::META );
		update_user_meta( $user_id, self::FAILED_META, $run_id . ':' . time() . ':' . $reason );
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
	 * `sending` のまま残った記録を消す（管理者が「未送信に戻す」を選んだとき）。送れた記録（`sent`）は消さない。
	 *
	 * @return bool 消したか。
	 */
	public static function clear_sending( int $user_id ): bool {
		$record = self::get( $user_id );

		if ( null === $record || self::STATE_SENDING !== $record['state'] ) {
			return false;
		}

		return delete_user_meta( $user_id, self::META );
	}
}
