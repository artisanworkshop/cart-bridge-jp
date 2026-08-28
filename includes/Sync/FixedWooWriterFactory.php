<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * テスト用: platformに関わらず常に同一の `WooWriter`（`InMemoryWriter` 等）を返す。
 */
final class FixedWooWriterFactory implements WooWriterFactory {

	/**
	 * `$dry_run_writer`省略時は`DryRunReporter`（何も永続化しないダミー実装）を都度new
	 * する。テスト用の`$writer`（`InMemoryWriter`等）はwrite()呼び出しを記録する契約が
	 * あるため、dry-run時に誤って同じインスタンスを共有すると「dry-runは実writerを
	 * 呼ばない」ことの検証ができなくなる。
	 */
	public function __construct(
		private readonly WooWriter $writer,
		private readonly ?WooWriter $dry_run_writer = null
	) {}

	public function for_platform( string $platform ): WooWriter {
		return $this->writer;
	}

	public function for_dry_run( string $platform ): WooWriter {
		return $this->dry_run_writer ?? new DryRunReporter();
	}
}
