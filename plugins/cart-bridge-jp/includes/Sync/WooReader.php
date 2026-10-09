<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Woo\Reader\ReadPage;

/**
 * `Exporter` の読出元インターフェース（`WooWriter` の対称形）。実装は `Woo\WooReaderRepository` が
 * entityごとの `Woo\Reader\EntityReader` へディスパッチする。読出はdry-run/実行を問わず
 * 副作用が無いため、`WooWriter`と異なりdry-run専用の実装は無い。
 */
interface WooReader {

	/**
	 * @param string          $entity 'product'（PR-Bで customer/order/stock/coupon を追加）
	 * @param array<int,int>|null $only_local_ids
	 */
	public function read( string $entity, Cursor $cursor, ?array $only_local_ids ): ReadPage;
}
