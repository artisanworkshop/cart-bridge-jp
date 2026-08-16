<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WriteResult;
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
	 */
	public function __construct(
		private readonly SideEffectGuard $guard,
		private readonly array $writers
	) {}

	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		$writer = $this->writers[ $entity ] ?? null;

		if ( null === $writer ) {
			// review（ColorMeは非対応）等、Writerが未登録のエンティティ。ジョブを落とさず
			// skippedとして扱う（アダプタのCapabilities宣言と矛盾する呼び出しに対する防御）。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::ENTITY_NOT_SUPPORTED ] );
		}

		return $this->guard->run( static fn (): WriteResult => $writer->write( $item, $existing_local_id ) );
	}
}
