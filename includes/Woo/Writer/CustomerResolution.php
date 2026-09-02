<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

/**
 * `CustomerWriter::resolve_target()` の戻り値。既存WPユーザーの解決（mapping / email突合）は
 * DB読取のみで完結するため、`write()`/`validate()`の両方がdriftなく共有できる。
 * `$is_new`がtrueの場合、`$user_id`は常にnull（新規作成はまだ試みていない）。
 */
final readonly class CustomerResolution {

	/**
	 * @param 'created'|'updated' $operation
	 */
	public function __construct(
		public ?int $user_id,
		public string $operation,
		public bool $is_new,
		public ?string $reuse_warning
	) {}
}
