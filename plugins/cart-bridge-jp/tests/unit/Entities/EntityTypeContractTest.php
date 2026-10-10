<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Entities;

use CartBridgeJP\Adapters\ColorMe\ColorMeApi;
use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\LinkSource;
use CartBridgeJP\Entities\MappingKind;
use CartBridgeJP\Entities\WooServices;
use ReflectionClass;
use ReflectionMethod;
use WP_UnitTestCase;

/**
 * Pro アドオンが使う無料版の拡張点（R3-6b1。継承する基底クラスは protected も含む。`docs/03-design-decisions.md` §10.0「Pro が使ってよい無料版の API」）の公開シグネチャを固定する
 * 契約テスト（`AbstractPlatformAdapterTest` と同じ方式。D20）。
 *
 * - 継承される基底クラス（`EntityType`・`MappingKind`・`LinkSource`）は、抽象メソッドの一覧も固定する。抽象メソッドを足すと、
 *   継承した外部の種類が読み込み時に fatal になる。新しいメソッドは既定実装つきで足す。
 * - v1.0.0 公開前は BASELINE を自由に更新してよい。公開後は既存のエントリを書き換えず、追記だけにする（レビューで確かめる）。
 */
final class EntityTypeContractTest extends WP_UnitTestCase {

	/**
	 * @var array<string,array<string,string>>
	 */
	private const BASELINE = [
		EntityType::class  => [
			'describe_local'       => '(int $local_id): array',
			'describe_warning'     => '(string $code, bool $import, string $row_entity): ?CartBridgeJP\Entities\WarningText',
			'dry_run_label'        => '(CartBridgeJP\Canonical\CanonicalModel $item): string',
			'existing_local_ids'   => '(array $local_ids): ?array',
			'export_description'   => '(CartBridgeJP\Adapters\PlatformAdapter $adapter): string',
			'fetch_by_remote_id'   => '(CartBridgeJP\Adapters\PlatformAdapter $adapter, string $remote_id): ?CartBridgeJP\Canonical\CanonicalModel',
			'fetch_page'           => '(CartBridgeJP\Adapters\PlatformAdapter $adapter, CartBridgeJP\Adapters\Cursor $cursor): CartBridgeJP\Adapters\Page',
			'is_export_beta'       => '(CartBridgeJP\Adapters\PlatformAdapter $adapter): bool',
			'is_linked_by_export'  => '(string $platform, int $local_id): bool',
			'key'                  => '(): string',
			'label'                => '(): string',
			'link_sources'         => '(): array',
			'local_amount_summary' => '(array $local_ids): ?array',
			'mapping_kinds'        => '(): array',
			'position'             => '(): int',
			'push'                 => '(CartBridgeJP\Adapters\PlatformAdapter $adapter, CartBridgeJP\Canonical\CanonicalModel $item, ?string $remote_id): CartBridgeJP\Adapters\PushResult',
			'reader'               => '(string $platform, CartBridgeJP\Entities\WooServices $services): ?CartBridgeJP\Woo\Reader\EntityReader',
			'records_push_intent'  => '(): bool',
			'remote_amount'        => '(CartBridgeJP\Canonical\CanonicalModel $item): ?int',
			'supports_export'      => '(CartBridgeJP\Adapters\PlatformAdapter $adapter): bool',
			'supports_import'      => '(CartBridgeJP\Adapters\PlatformAdapter $adapter): bool',
			'warning_flags'        => '(): array',
			'writer'               => '(string $platform, CartBridgeJP\Entities\WooServices $services): ?CartBridgeJP\Woo\Writer\EntityWriter',
		],
		MappingKind::class => [
			'applies_to'      => '(CartBridgeJP\Adapters\PlatformAdapter $adapter): bool',
			'description'     => '(): string',
			'import_notice'   => '(): bool',
			'key'             => '(): string',
			'label'           => '(): string',
			'map_key'         => 'final (): string',
			'no_targets_help' => '(): string',
			'position'        => '(): int',
			'source_heading'  => '(): string',
			'source_side'     => '(): string',
			'target_heading'  => '(): string',
			'unmapped_label'  => '(): string',
			'woo_candidates'  => '(): array',
		],
		LinkSource::class  => [
			'key'                  => '(): string',
			'label'                => '(): string',
			'meta_string'          => 'protected static (mixed $value): string',
			'ownership_meta_query' => 'protected static (string $platform): array',
			'position'             => '(): int',
			'scan'                 => '(string $platform, int $offset, int $limit): array',
		],
		WooServices::class => [
			'__construct'      => '(string $platform)',
			'mappings'         => '(): CartBridgeJP\Sync\MappingRepository',
			'media'            => '(): CartBridgeJP\Woo\Support\MediaImporter',
			'method_map'       => '(): CartBridgeJP\Woo\Support\MethodMap',
			'platform'         => '(): string',
			'product_resolver' => '(): CartBridgeJP\Woo\Support\ProductResolver',
			'variations'       => '(): CartBridgeJP\Woo\Writer\VariationWriter',
		],
		ColorMeApi::class  => [
			'__construct'           => '(CartBridgeJP\Support\TokenStore $token_store, CartBridgeJP\Support\Logger $logger)',
			'client'                => '(): CartBridgeJP\Adapters\ColorMe\ColorMeClient',
			'exact_int_or_null'     => 'static (mixed $value): ?int',
			'fetch_single'          => '(string $path, string $envelope_key): ?array',
			'id_name_map'           => '(string $path, string $envelope_key): array',
			'list_from'             => '(array $body, string $key): array',
			'log_transform_failure' => '(string $entity, array $raw, Throwable $exception): void',
			'next_cursor'           => '(int $offset, int $raw_count, ?int $total): ?CartBridgeJP\Adapters\Cursor',
			'raw_row_count'         => '(array $body, string $key): int',
			'total_from_meta'       => '(array $body): ?int',
			'transform_rows'        => '(array $raw_items, callable $transform, string $entity): array',
			'transform_rows_flat'   => '(array $raw_items, callable $transform, string $entity): array',
		],
	];

	/**
	 * 継承される基底クラスの抽象メソッド（ここに無いメソッドは既定実装を持つこと）。
	 *
	 * @var array<string,array<int,string>>
	 */
	private const ABSTRACT_METHODS = [
		EntityType::class  => [ 'key', 'label', 'position' ],
		MappingKind::class => [ 'key', 'label', 'position', 'source_side', 'woo_candidates' ],
		LinkSource::class  => [ 'key', 'label', 'position', 'scan' ],
	];

	public function test_public_signatures_match_the_baseline(): void {
		foreach ( self::BASELINE as $class => $expected ) {
			$actual = [];

			foreach ( ( new ReflectionClass( $class ) )->getMethods( ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED ) as $method ) {
				$actual[ $method->getName() ] = self::describe( $method );
			}

			ksort( $actual );
			ksort( $expected );

			$this->assertSame( $expected, $actual, "{$class} の公開シグネチャが BASELINE と違います（v1.0.0 公開後は既存のメソッドを変えず、既定実装つきで足す）。" );
		}
	}

	public function test_only_the_documented_methods_are_abstract(): void {
		foreach ( self::ABSTRACT_METHODS as $class => $expected ) {
			$abstract = [];

			foreach ( ( new ReflectionClass( $class ) )->getMethods() as $method ) {
				if ( $method->isAbstract() ) {
					$abstract[] = $method->getName();
				}
			}

			sort( $abstract );
			sort( $expected );

			$this->assertSame( $expected, $abstract, "{$class} の抽象メソッドが増減しました（継承した外部の種類が fatal になる）。" );
		}
	}

	private static function describe( ReflectionMethod $method ): string {
		$parameters = [];

		foreach ( $method->getParameters() as $parameter ) {
			$type         = $parameter->getType();
			$parameters[] = trim(
				( null !== $type ? (string) $type . ' ' : '' )
				. ( $parameter->isPassedByReference() ? '&' : '' )
				. ( $parameter->isVariadic() ? '...' : '' )
				. '$' . $parameter->getName()
				. ( $parameter->isDefaultValueAvailable() ? ' = ' . var_export( $parameter->getDefaultValue(), true ) : '' ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- シグネチャの文字列化。
			);
		}

		$return    = $method->getReturnType();
		$modifiers = ( $method->isProtected() ? 'protected ' : '' ) . ( $method->isFinal() ? 'final ' : '' ) . ( $method->isStatic() ? 'static ' : '' ) . ( $method->returnsReference() ? '&' : '' );
		$signature = '(' . implode( ', ', $parameters ) . ')';

		if ( null !== $return ) {
			$signature .= ': ' . (string) $return;
		}

		return $modifiers . $signature;
	}
}
