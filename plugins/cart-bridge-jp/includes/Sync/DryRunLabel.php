<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\EntityTypeRegistry;

/**
 * `cbjp_dry_run_items.label`（CSV上の人が読める識別子）を組み立てる。`Support\Logger`の
 * 個人情報禁止ルール（docblock参照）をこのテーブルにも適用し、顧客名・メール等のPIIを
 * 含みうるフィールドは常に空文字にする。
 */
final class DryRunLabel {

	private function __construct() {}

	/**
	 * 種類（`Entities\EntityType::dry_run_label()`）が組み立てる。登録の無い種類は空。
	 */
	public static function for_entity( string $entity, CanonicalModel $item ): string {
		return self::for_type( EntityTypeRegistry::get( $entity ), $item );
	}

	/**
	 * ページごとに 1 回引いた種類で組み立てる（`Sync\Importer`・`Sync\Exporter`）。
	 */
	public static function for_type( ?EntityType $type, CanonicalModel $item ): string {
		return null === $type ? '' : $type->dry_run_label( $item );
	}
}
