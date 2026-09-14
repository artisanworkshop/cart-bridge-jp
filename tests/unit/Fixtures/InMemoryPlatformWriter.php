<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\PlatformWriter;

/**
 * テスト用のインメモリ PlatformWriter。実際のアダプタへは push せず、呼び出しを記録する
 * （`InMemoryWriter` のエクスポート向け対称形）。
 */
final class InMemoryPlatformWriter implements PlatformWriter {

	private int $next_remote_id = 1;

	/**
	 * @var array<int,array{entity:string,item:CanonicalModel,remote_id:string}>
	 */
	public array $writes = [];

	public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
		$remote_id = $existing_remote_id ?? (string) $this->next_remote_id++;

		$this->writes[] = [
			'entity'    => $entity,
			'item'      => $item,
			'remote_id' => $remote_id,
		];

		$operation = null === $existing_remote_id ? PushResult::OPERATION_CREATED : PushResult::OPERATION_UPDATED;

		return new PushResult( $remote_id, $operation );
	}
}
