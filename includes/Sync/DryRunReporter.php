<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Canonical\CanonicalModel;

/**
 * dry-run時の書き込み先。実際にはWooに何も書き込まず、件数と警告を集計するのみ。
 */
final class DryRunReporter implements WooWriter {

	/**
	 * @var array<string,int>
	 */
	private array $counts = [
		WriteResult::OPERATION_CREATED => 0,
		WriteResult::OPERATION_UPDATED => 0,
		WriteResult::OPERATION_SKIPPED => 0,
	];

	/**
	 * @var array<int,string>
	 */
	private array $warnings = [];

	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		$operation = null === $existing_local_id ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		++$this->counts[ $operation ];

		return new WriteResult( 0, $operation );
	}

	/**
	 * @return array<string,int>
	 */
	public function counts(): array {
		return $this->counts;
	}

	/**
	 * @return array<int,string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	public function add_warning( string $warning ): void {
		$this->warnings[] = $warning;
	}
}
