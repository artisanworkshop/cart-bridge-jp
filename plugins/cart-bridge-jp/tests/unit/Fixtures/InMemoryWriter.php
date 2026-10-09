<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WriteResult;

/**
 * テスト用のインメモリ WooWriter。実際のWooCommerceへは書き込まず、呼び出しを記録する。
 */
final class InMemoryWriter implements WooWriter {

	private int $next_id = 1;

	/**
	 * @var array<int,array{entity:string,item:CanonicalModel,local_id:int}>
	 */
	public array $writes = [];

	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		$local_id = $existing_local_id ?? $this->next_id++;

		$this->writes[] = [
			'entity'   => $entity,
			'item'     => $item,
			'local_id' => $local_id,
		];

		$operation = null === $existing_local_id ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		return new WriteResult( $local_id, $operation );
	}
}
