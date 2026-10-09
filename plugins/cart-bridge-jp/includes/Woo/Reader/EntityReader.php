<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;

/**
 * `Woo\Writer\EntityWriter`（ASP→Woo書込）の対称形。Wooをカーソル走査し `CanonicalModel` へ
 * 変換する。プラットフォーム固有の分岐は持たない（アーキテクチャ原則1・2）。
 */
interface EntityReader {

	/**
	 * @param array<int,int>|null $only_local_ids 指定時はこのWooローカルIDのみを対象にする（ページングしない）。
	 *   `Sync\WooReader` は常に null を渡す（R3-6a で無料版のサンプル選定〔D15〕を外した）。テストがフィクスチャを
	 *   絞るのに使う汎用の絞り込みとして残している。
	 */
	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage;
}
