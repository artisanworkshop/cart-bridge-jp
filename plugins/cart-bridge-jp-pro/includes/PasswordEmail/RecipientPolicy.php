<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

use CartBridgeJP\Pro\Woo\Writer\CustomerWriter;
use WP_User;

/**
 * パスワード設定メールを送る相手の判定（R3-10。`docs/03` §10.5）。画面の件数と、送る直前（`Sender`）の両方で同じ判定を使う。
 *
 * 送るのは次のすべてを満たすユーザー（判定の順。外した理由は最初に当たったもの）:
 * 1. 存在し、保護するアカウント（スタッフ・管理者・顧客以外のロール。`CustomerWriter::has_protected_role()`）でない
 * 2. 取込みで作ったアカウント: 作成の印が今の紐づけ（`_cbjp_platform`・`_cbjp_remote_id`）と一致する（`CustomerWriter::is_created_for()`）か、
 *    0.1.0 で作ったと推定した（`LegacyAccountEstimator`）うえで管理者が含めた
 * 3. 送った・送ろうとした記録が無い（`SendRecord`）
 * 4. パスワードを設定し直していない: 作成時の控え（`CustomerWriter::CREATED_PASSWORD_META`）があれば今の値と一致する。控えが無ければ
 *    WP・WooCommerce の再設定が書く `default_password_nag` が無い。どちらも無ければ判定できないので送る（ユーザー決定 2026-10-13。件数を別に数える）
 * 5. メールアドレスの形が正しく、パスワードの再設定が許されている
 */
final class RecipientPolicy {

	/**
	 * @param array<int,int> $legacy_ids     0.1.0 で作ったと推定したアカウント（`LegacyAccountEstimator::estimate()`）。
	 * @param bool           $include_legacy 推定したアカウントにも送るか（管理者が選ぶ。既定は送らない）。
	 */
	public function __construct(
		private readonly array $legacy_ids = [],
		private readonly bool $include_legacy = false
	) {}

	public function evaluate( int $user_id ): Verdict {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return new Verdict( Verdict::NOT_FOUND );
		}

		if ( CustomerWriter::has_protected_role( $user_id ) ) {
			return new Verdict( Verdict::PROTECTED_ACCOUNT );
		}

		$legacy = false;

		if ( ! $this->created_by_import( $user_id ) ) {
			if ( ! in_array( $user_id, $this->legacy_ids, true ) || '' !== self::string_meta( $user_id, CustomerWriter::CREATED_BY_IMPORT_META ) ) {
				return new Verdict( Verdict::NOT_CREATED_BY_IMPORT );
			}

			if ( ! $this->include_legacy ) {
				return new Verdict( Verdict::LEGACY_NOT_INCLUDED, true );
			}

			$legacy = true;
		}

		if ( null !== SendRecord::get( $user_id ) ) {
			return new Verdict( Verdict::ALREADY_SENT, $legacy );
		}

		$password = self::password_state( $user_id );

		if ( 'set' === $password ) {
			return new Verdict( Verdict::PASSWORD_SET, $legacy );
		}

		if ( ! is_email( $user->user_email ) ) {
			return new Verdict( Verdict::INVALID_EMAIL, $legacy );
		}

		if ( true !== wp_is_password_reset_allowed_for_user( $user ) ) {
			return new Verdict( Verdict::RESET_NOT_ALLOWED, $legacy );
		}

		return new Verdict( Verdict::SEND, $legacy, 'unknown' === $password );
	}

	/**
	 * 作成の印が今の紐づけと一致するか（取込みがこの顧客のために作り、今もその顧客に結ばれている）。
	 */
	private function created_by_import( int $user_id ): bool {
		$platform = self::string_meta( $user_id, '_cbjp_platform' );
		$remote   = self::string_meta( $user_id, '_cbjp_remote_id' );

		return '' !== $remote && CustomerWriter::is_created_for( $user_id, $platform, $remote );
	}

	/**
	 * パスワードを設定し直したか: `set`（設定し直した）・`unchanged`（作成時のまま）・`unknown`（判定できない）。
	 */
	private static function password_state( int $user_id ): string {
		if ( metadata_exists( 'user', $user_id, CustomerWriter::CREATED_PASSWORD_META ) ) {
			$created = get_user_meta( $user_id, CustomerWriter::CREATED_PASSWORD_META, true );
			$current = CustomerWriter::password_fingerprint( $user_id );

			// 控えが読めない・今のハッシュが無いときは「設定し直した」に倒す（送らない側）。
			if ( ! is_string( $created ) || null === $current || ! hash_equals( $created, $current ) ) {
				return 'set';
			}

			return 'unchanged';
		}

		// 控えの無いアカウント（この版より前の取込み・0.1.0）: WP の `reset_password()` と WooCommerce の再設定は `default_password_nag` を false（空）で書く
		// （取込みの `wc_create_new_customer()` はパスワードを渡すので書かない。渡さないと「生成されたパスワードのまま」の印として '1' を書く。R1-L7）。
		// プロフィールの画面から変えた人は見分けられない。
		if ( metadata_exists( 'user', $user_id, 'default_password_nag' ) && '1' !== get_user_meta( $user_id, 'default_password_nag', true ) ) {
			return 'set';
		}

		return 'unknown';
	}

	private static function string_meta( int $user_id, string $key ): string {
		$value = get_user_meta( $user_id, $key, true );

		return is_string( $value ) ? $value : '';
	}
}
