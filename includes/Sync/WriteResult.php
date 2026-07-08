<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * `WooWriter::write()` の結果。
 */
final class WriteResult {

	public const OPERATION_CREATED = 'created';
	public const OPERATION_UPDATED = 'updated';
	public const OPERATION_SKIPPED = 'skipped';

	/**
	 * @param 'created'|'updated'|'skipped' $operation
	 * @param array<int,string>             $warnings
	 */
	public function __construct(
		public readonly int $local_id,
		public readonly string $operation,
		public readonly array $warnings = []
	) {}
}
