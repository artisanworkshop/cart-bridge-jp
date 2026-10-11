<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\License;

/**
 * ライセンスの操作（有効化・無効化・状態確認・キーの保存）の結果（R3-8a）。
 *
 * 失敗は理由のコードと、画面に出す文（サーバーの文はタグを除いたもの）を持つ。文は出力の時にエスケープする。
 */
final class LicenseResult {

	public const OK = 'ok';

	/** 接続先（URL・製品 ID）が設定されていない。要求は送っていない。 */
	public const NOT_CONFIGURED = 'not_configured';

	/** 入力されたキーの形が違う。要求は送っていない。 */
	public const INVALID_KEY = 'invalid_key';

	/** 今の状態ではその操作ができない（保存したキーが無い・有効化していない など）。要求は送っていない。 */
	public const NOT_ALLOWED = 'not_allowed';

	/** このサイトにライセンスの情報（instance・キー）を保存できなかった（DB の失敗）。サーバーには何も頼んでいない。 */
	public const STORAGE_ERROR = 'storage_error';

	/** サーバーは有効化・無効化を受け付けたが、このサイトに記録できなかった（DB の失敗）。 */
	public const NOT_RECORDED = 'not_recorded';

	/** 通信の失敗・200 以外の応答。 */
	public const HTTP_ERROR = 'http_error';

	/** 応答を解釈できない（JSON でない・`success` が実 bool でない・操作ごとの印が無い）。 */
	public const INVALID_RESPONSE = 'invalid_response';

	/** サーバーが `success: false` を返した。 */
	public const SERVER_ERROR = 'server_error';

	/**
	 * @param array<string,mixed> $data 成功したときの値（状態確認の `activated`・`expires`）。
	 */
	private function __construct(
		public readonly string $code,
		public readonly string $message,
		public readonly array $data
	) {}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function success( array $data = [] ): self {
		return new self( self::OK, '', $data );
	}

	public static function failure( string $code, string $message = '' ): self {
		return new self( $code, $message, [] );
	}

	public function is_success(): bool {
		return self::OK === $this->code;
	}
}
