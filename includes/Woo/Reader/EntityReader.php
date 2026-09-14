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
	 * @param array<int,int>|null $only_local_ids 指定時はこのWooローカルIDのみを対象にする
	 *   （無料版サンプル選定用。D15 §10.2 #8）。
	 */
	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage;
}
