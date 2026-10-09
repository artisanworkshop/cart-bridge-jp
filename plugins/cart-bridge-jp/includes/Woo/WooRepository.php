<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\EntityOrigin;
use CartBridgeJP\Woo\Support\SideEffectGuard;
use CartBridgeJP\Woo\Writer\EntityWriter;

/**
 * `Sync\WooWriter` の実装。entityごとの `Writer\EntityWriter` へディスパッチする。
 * 全ての書込は `SideEffectGuard` でラップし、メール送信・在庫増減等のWooCommerce標準の
 * 副作用を移行時に発生させない（D10）。
 */
final class WooRepository implements WooWriter {

	/**
	 * @param array<string,EntityWriter> $writers entity名 => Writer。
	 * @param string                     $platform 取込み元のプラットフォームID（D25 のガード。`Woo\Support\EntityOrigin::blocks_import()`）。
	 */
	public function __construct(
		private readonly SideEffectGuard $guard,
		private readonly array $writers,
		private readonly string $platform
	) {}

	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		$writer = $this->writers[ $entity ] ?? null;

		if ( null === $writer ) {
			// review（ColorMeは非対応）等、Writerが未登録のエンティティ。ジョブを落とさず
			// skippedとして扱う（アダプタのCapabilities宣言と矛盾する呼び出しに対する防御）。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::ENTITY_NOT_SUPPORTED ] );
		}

		// D25（issue #98）: mapping が指す実体がエクスポートで結ばれていれば（Woo で作ってエクスポートした実体）、取込みで上書きせず
		// 紐づけだけを保つ。local_id 0 を返すので`Sync\Importer`は mapping に触れない（エクスポートの checksum が残る）。
		// writer を呼ぶ前に止める（各 writer の中で止めると、`validate()`と`write()`の両方に同じ判定を置くことになる）。
		if ( EntityOrigin::blocks_import( $this->platform, $entity, $existing_local_id ) ) {
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::LINKED_BY_EXPORT_NOT_IMPORTED ] );
		}

		return $this->guard->run( static fn (): WriteResult => $writer->write( $item, $existing_local_id ) );
	}
}
