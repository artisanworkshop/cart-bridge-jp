<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Canonical\CanonicalModel;

/**
 * `EntityReader::query()` が1件ごとに返す読出結果。
 *
 * `$warnings`/`$fully_resolved` は読出時点での参照解決状況（例: 商品カテゴリの
 * `category_map` 未設定）を運ぶ。`CanonicalModel::checksum()` の対象（`to_array()`/`extras`）に
 * 含めると、参照解決状況が変わるだけで実データが変わらないアイテムのchecksumが変動し、
 * 冪等性判定（`Sync\Exporter::process_items()`のchecksum一致スキップ）が壊れるため、
 * Canonicalモデル本体とは別の経路で運ぶ。
 */
final readonly class ReadItem {

	/**
	 * @param array<int,string> $warnings `Woo\WarningCode` の定数（`with_detail()`形式可）。
	 */
	public function __construct(
		public int $local_id,
		public CanonicalModel $item,
		public array $warnings = [],
		public bool $fully_resolved = true
	) {}
}
