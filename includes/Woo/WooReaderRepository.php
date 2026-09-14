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

	public function read( string $entity, Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$reader = $this->readers[ $entity ] ?? null;

		if ( null === $reader ) {
			// `JobManager::filter_and_order_export_entities()`が未実装entityを弾く（PR-A時点は
			// product以外を要求しない）ため、通常はここに到達しない。到達した場合は
			// Reader未実装という実装バグであり、空ページ（skippedにもならない）で
			// 誤魔化さずここで気付けるようにする。
			throw new RuntimeException( "No Woo reader registered for entity \"{$entity}\"." );
		}

		return $reader->query( $cursor, $only_local_ids );
	}
}
