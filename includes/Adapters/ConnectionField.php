<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * 接続設定フォームの1項目。UIが `connectionFields()` を見て動的にフォームを生成する
 * （例: makeshopは endpoint+token、colorme/baseは OAuthボタン）。
 */
final readonly class ConnectionField {

	/**
	 * @param 'text'|'password'|'oauth_button' $type
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $type,
		public bool $required,
		public ?string $help = null
	) {}
}
