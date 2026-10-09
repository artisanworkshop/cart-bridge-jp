<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Canonical;

/**
 * ASP⇔Woo間のデータは必ずこのインターフェースを実装する正規化モデルを経由する。
 */
interface CanonicalModel {

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array;

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self;

	/**
	 * checksum算出用の正規化JSON（キー順に依存しない安定した文字列）。
	 */
	public function canonical_json(): string;

	/**
	 * `canonical_json()` の sha256。`cbjp_mappings.checksum` に保存し差分検出に使う。
	 */
	public function checksum(): string;

	/**
	 * リモート（ASP）側の一意識別子。`cbjp_mappings.remote_id` に使う。
	 * 専用フィールドを持たないモデル（Product/Customer/Coupon/Review）は
	 * アダプタが `extras['remote_id']` に格納した値を返し、未設定なら null。
	 */
	public function remote_id(): ?string;
}
