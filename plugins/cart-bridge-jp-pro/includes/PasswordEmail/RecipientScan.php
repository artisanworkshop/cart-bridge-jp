<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

/**
 * パスワード設定メールの候補（取込みの紐づけの印 `_cbjp_remote_id` を持つユーザー）を ID の順に読む（R3-10）。送る相手かは `RecipientPolicy` が決める。
 * 送信（`Sender`）はカーソルの次から少しずつ、画面の件数（{@see self::summarize()}）は全員を 500 件ずつキャッシュを温めて数え、数え終えたチャンクのキャッシュを捨てる
 * （捨てないとメモリが顧客数に比例して増え、1 万人前後で管理画面の上限を超えうる。R1-1）。
 */
final class RecipientScan {

	private const CHUNK = 500;

	private function __construct() {}

	/**
	 * `$after` より大きい候補の ID（昇順、最大 `$limit` 件）。照会の DB エラーなら null（`get_col()` は失敗しても空を返すので `$wpdb->last_error` で見る。
	 * 空のページを「候補を見尽くした」と読んで送信を完了にしない。PR #135 G3-1）。
	 *
	 * @return array<int,int>|null
	 */
	public static function ids_after( int $after, int $limit ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 候補の ID をカーソルの次から読む（WP_User_Query は「ID より大きい」を表せない）。
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT u.ID FROM {$wpdb->users} AS u INNER JOIN {$wpdb->usermeta} AS m ON m.user_id = u.ID AND m.meta_key = %s WHERE u.ID > %d ORDER BY u.ID ASC LIMIT %d",
				'_cbjp_remote_id',
				$after,
				$limit
			)
		);

		if ( '' !== $wpdb->last_error || ! is_array( $ids ) ) {
			return null;
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * 全候補を判定して数える（画面の件数）。送る相手の内訳（判定できない・0.1.0 の推定）と、外した理由ごとの件数、送る相手のうち 0.1.0 の推定の ID、
	 * `sending` のまま残った顧客・前の送信の失敗の ID。
	 *
	 * 候補を読めなかった（DB エラー）ときは、そこまでの件数で止め、`incomplete` を真にする。
	 *
	 * @return array{send:int,unknown:int,legacy_send:int,excluded:array<string,int>,legacy_ids:array<int,int>,sending_ids:array<int,int>,failed_ids:array<int,int>,incomplete:bool}
	 */
	public static function summarize( RecipientPolicy $policy ): array {
		$summary = [
			'incomplete'  => false,
			'send'        => 0,
			'unknown'     => 0,
			'legacy_send' => 0,
			'excluded'    => [],
			'legacy_ids'  => [],
			'sending_ids' => [],
			'failed_ids'  => [],
		];
		$after   = 0;

		do {
			$ids = self::ids_after( $after, self::CHUNK );

			if ( null === $ids ) {
				$summary['incomplete'] = true;
				break;
			}

			if ( [] === $ids ) {
				break;
			}

			// ユーザーとメタのキャッシュを温める（判定で 1 人ずつ読まない）。
			cache_users( $ids );

			foreach ( $ids as $user_id ) {
				$verdict = $policy->evaluate( $user_id );

				if ( Verdict::ALREADY_SENT === $verdict->outcome && SendRecord::STATE_SENDING === ( SendRecord::get( $user_id )['state'] ?? null ) ) {
					$summary['sending_ids'][] = $user_id;
				}

				if ( $verdict->sends() ) {
					++$summary['send'];
					$summary['unknown']     += $verdict->password_unknown ? 1 : 0;
					$summary['legacy_send'] += $verdict->legacy ? 1 : 0;

					if ( $verdict->legacy ) {
						$summary['legacy_ids'][] = $user_id;
					}

					if ( null !== SendRecord::failure( $user_id ) ) {
						$summary['failed_ids'][] = $user_id;
					}

					continue;
				}

				$summary['excluded'][ $verdict->outcome ] = ( $summary['excluded'][ $verdict->outcome ] ?? 0 ) + 1;
			}

			self::forget_users( $ids );
			$after = (int) end( $ids );
			$read  = count( $ids );
		} while ( self::CHUNK === $read );

		return $summary;
	}

	/**
	 * 読み終えたユーザーのキャッシュ（ユーザー・メタ・ログイン名などの索引）を捨てる（1 回の画面で全員を読むときのメモリを抑える）。
	 *
	 * @param array<int,int> $ids
	 */
	public static function forget_users( array $ids ): void {
		foreach ( $ids as $user_id ) {
			clean_user_cache( $user_id );
		}
	}
}
