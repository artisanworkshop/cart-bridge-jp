<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WriteResult;

/**
 * 単一エンティティ種別のWoo書込を担う。`Woo\WooRepository` からディスパッチされる。
 */
interface EntityWriter {

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult;

	/**
	 * `write()`と同じ検証・参照解決ロジックを使い、何も永続化せずに警告のみを求める
	 * （dry-run用。F1-6）。`write()`とロジックを共有する箇所は必ず同じprivateメソッドを
	 * 呼ぶこと（別実装にすると警告がdriftし、無料版dry-runの価値=D14を毀損する）。
	 * 保存を実際に試みないと判定できない警告（`Woo\WarningCode`のdocblock参照）は
	 * このメソッドからは出ない仕様とする。
	 */
	public function validate( CanonicalModel $item, ?int $existing_local_id ): ValidationResult;
}
