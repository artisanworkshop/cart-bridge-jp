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
	 * @param array<int,int>    $variant_local_ids `product` entityのみ使用（他entityは`[]`のまま）。
	 *   `CanonicalProduct::$variants`（`$item`が運ぶ配列）と同じ順序・同じ要素数で、各要素に対応する
	 *   Wooバリエーションの投稿ID（`WC_Product_Variation` ID）を運ぶ。`CanonicalProduct`自体は
	 *   ASP非中立モデルのためWoo固有のIDを持てない（アーキテクチャ原則2）ので、`warnings`と同じく
	 *   Reader時点のメタデータとしてここで運び、`Sync\Exporter`が`PushResult::$variant_remote_ids`と
	 *   zipして`cbjp_mappings`（'variant'）へ書き戻す（`docs/03-design-decisions.md` §10.2
	 *   「E2-3への申し送り」のバリエーションremote_id永続化経路）。
	 */
	public function __construct(
		public int $local_id,
		public CanonicalModel $item,
		public array $warnings = [],
		public bool $fully_resolved = true,
		public array $variant_local_ids = []
	) {}
}
