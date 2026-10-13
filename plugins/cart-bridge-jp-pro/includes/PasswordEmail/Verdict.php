<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\PasswordEmail;

/**
 * 1 人のユーザーへパスワード設定メールを送るかの判定（`RecipientPolicy::evaluate()`。R3-10）。
 */
final class Verdict {

	public const SEND = 'send';

	/** ユーザーが無い。 */
	public const NOT_FOUND = 'not_found';

	/** スタッフ・管理者・顧客以外のロールを持つ（`CustomerWriter::has_protected_role()`）。 */
	public const PROTECTED_ACCOUNT = 'protected';

	/** 取込みが作ったアカウントでない（メールの一致で採用した既存のアカウント・別の顧客に結び直したアカウント・推定の外）。 */
	public const NOT_CREATED_BY_IMPORT = 'not_created_by_import';

	/** 0.1.0 で作ったと推定したが、管理者が含めなかった。 */
	public const LEGACY_NOT_INCLUDED = 'legacy_not_included';

	/** 送った・送ろうとした記録がある（`SendRecord`）。 */
	public const ALREADY_SENT = 'already_sent';

	/** パスワードを設定し直した。 */
	public const PASSWORD_SET = 'password_set';

	/** メールアドレスの形が正しくない。 */
	public const INVALID_EMAIL = 'invalid_email';

	/** パスワードの再設定が許されていない（`wp_is_password_reset_allowed_for_user()`）。 */
	public const RESET_NOT_ALLOWED = 'reset_not_allowed';

	/**
	 * @param string $outcome           {@see self::SEND} か外した理由。
	 * @param bool   $legacy            0.1.0 で作ったと推定したアカウントか。
	 * @param bool   $password_unknown  パスワードを設定し直したかを判定できない（作成時の控えが無い）。ユーザー決定で送る。
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly bool $legacy = false,
		public readonly bool $password_unknown = false
	) {}

	public function sends(): bool {
		return self::SEND === $this->outcome;
	}
}
