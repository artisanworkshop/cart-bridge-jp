<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures\Gizmo;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\WarningText;
use CartBridgeJP\Entities\WooServices;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Writer\EntityWriter;
use RuntimeException;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `EntityType` のシグネチャに合わせる。

/**
 * 判定・一覧のメソッドがすべて例外を投げる外部の種類（R3-6b1。例外の隔離のテスト用）。キーと位置だけは返す。
 */
final class ThrowingEntityType extends EntityType {

	public function key(): string {
		return 'boom';
	}

	public function label(): string {
		throw new RuntimeException( 'label' );
	}

	public function position(): int {
		return 35;
	}

	public function supports_import( PlatformAdapter $adapter ): bool {
		throw new RuntimeException( 'supports_import' );
	}

	public function supports_export( PlatformAdapter $adapter ): bool {
		throw new RuntimeException( 'supports_export' );
	}

	public function writer( string $platform, WooServices $services ): ?EntityWriter {
		throw new RuntimeException( 'writer' );
	}

	public function reader( string $platform, WooServices $services ): ?EntityReader {
		throw new RuntimeException( 'reader' );
	}

	public function describe_local( int $local_id ): array {
		throw new RuntimeException( 'describe_local' );
	}

	public function link_sources(): array {
		throw new RuntimeException( 'link_sources' );
	}

	public function existing_local_ids( array $local_ids ): ?array {
		throw new RuntimeException( 'existing_local_ids' );
	}

	public function warning_flags(): array {
		throw new RuntimeException( 'warning_flags' );
	}

	public function describe_warning( string $code, bool $import, string $row_entity ): ?WarningText {
		throw new RuntimeException( 'describe_warning' );
	}

	public function mapping_kinds(): array {
		throw new RuntimeException( 'mapping_kinds' );
	}

	public function dry_run_label( CanonicalModel $item ): string {
		throw new RuntimeException( 'dry_run_label' );
	}
}
