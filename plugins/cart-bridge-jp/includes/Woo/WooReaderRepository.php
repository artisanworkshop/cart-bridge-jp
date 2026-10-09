<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Sync\WooReader;
use CartBridgeJP\Woo\Reader\EntityReader;
use CartBridgeJP\Woo\Reader\ReadPage;
use RuntimeException;

/**
 * `Sync\WooReader` の実装。entityごとの `Reader\EntityReader` へディスパッチする
 * （`WooRepository` の読出側対称形）。読出は副作用を持たないため `SideEffectGuard` は不要。
 */
final class WooReaderRepository implements WooReader {

	/**
	 * @param array<string,EntityReader> $readers entity名 => Reader。
	 */
	public function __construct( private readonly array $readers ) {}

	public function read( string $entity, Cursor $cursor ): ReadPage {
		$reader = $this->readers[ $entity ] ?? null;

		if ( null === $reader ) {
			// エクスポートできる種類（`Entities\EntityType::supports_export()`。`JobManager::start_run()`が絞り込む）は
			// Reader を持つ契約なので、通常はここに到達しない。到達した場合は種類の実装の誤り（Reader を返さない・
			// 組み立てに失敗した。後者は`WooReaderRepositoryFactory`が記録する）であり、空ページ（skippedにもならない）で
			// 誤魔化さずここで気付けるようにする。
			throw new RuntimeException( "No Woo reader registered for entity \"{$entity}\"." );
		}

		return $reader->query( $cursor, null );
	}
}
