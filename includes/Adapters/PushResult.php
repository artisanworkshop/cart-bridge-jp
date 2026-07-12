<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * push* メソッドの結果。
 */
final readonly class PushResult {

	public const OPERATION_CREATED = 'created';
	public const OPERATION_UPDATED = 'updated';
	public const OPERATION_SKIPPED = 'skipped';

	/**
	 * @param 'created'|'updated'|'skipped' $operation
	 * @param array<int,string>             $warnings
	 */
	public function __construct(
		public string $remote_id,
		public string $operation,
		public array $warnings = []
	) {}
}
