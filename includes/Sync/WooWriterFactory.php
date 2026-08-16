<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * プラットフォームごとに `WooWriter` を組み立てる。`WooWriter::write()` 自体は
 * platform を受け取らないが、カテゴリ親・商品カテゴリ/タグ・受注の商品/顧客参照解決には
 * platform が必須（`cbjp_mappings` の複合キー）なため、ジョブ処理時に一度だけ解決する。
 */
interface WooWriterFactory {

	public function for_platform( string $platform ): WooWriter;
}
