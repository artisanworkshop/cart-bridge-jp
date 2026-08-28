<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

/**
 * `EntityWriter::validate()` の結果。`Sync\WriteResult` と異なり、何も永続化しないため
 * local_id を持たない。
 *
 * 一部の警告コード（`WarningCode`のdocblock参照）は保存を実際に試みないと判定できず
 * （保存失敗・名前衝突の実体解決・画像ダウンロード等）、dry-run では出ない仕様とする
 * （検証ロジックの重複によるdrift、ネットワークI/O、実データ変更を避けるため）。
 */
final readonly class ValidationResult {

	/**
	 * @param 'created'|'updated'|'skipped' $operation
	 * @param array<int,string>             $warnings
	 */
	public function __construct(
		public string $operation,
		public array $warnings = []
	) {}
}
