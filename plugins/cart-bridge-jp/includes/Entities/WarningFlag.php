<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities;

/**
 * 登録された種類の警告コードに付ける判定の印（`EntityType::warning_flags()`）。`Woo\WarningCode` の判定関数が、無料版の一覧に加えて
 * この印を見る（R3-6b1）。
 */
final class WarningFlag {

	/**
	 * `WarningCode::indicates_export_blocking()`: この警告が付いたアイテムは送らない。
	 */
	public const EXPORT_BLOCKING = 'export_blocking';

	/**
	 * `WarningCode::indicates_unresolved_reference()`: 参照が未解決のため checksum を保存しない（後で解決しうる）。
	 */
	public const UNRESOLVED_REFERENCE = 'unresolved_reference';

	/**
	 * `WarningCode::indicates_mapping_required()`: マッピング設定で解決する（CSV の note は `mapping_required`）。
	 */
	public const MAPPING_REQUIRED = 'mapping_required';

	/**
	 * `WarningCode::indicates_pending_export()`: 参照先を先にエクスポートすれば解決する（note は `reference_pending_export`）。
	 */
	public const PENDING_EXPORT = 'pending_export';

	/**
	 * `WarningCode::indicates_order_reference_unresolved()`: 参照先が未取込みか、取り込めない（note は `reference_unresolved`）。
	 */
	public const REFERENCE_UNRESOLVED = 'reference_unresolved';

	/**
	 * `WarningCode::indicates_pending_import()`: 参照先を先に取り込めば解決する（note は `reference_pending_import`）。
	 * 上の 3 つ（`MAPPING_REQUIRED`・`PENDING_EXPORT`・`REFERENCE_UNRESOLVED`）のどれかが付いたコードでは無視される（note はそちらが先に決まる）。
	 */
	public const PENDING_IMPORT = 'pending_import';

	private function __construct() {}
}
