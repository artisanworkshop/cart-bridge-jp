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

	/**
	 * dry-run用。`for_platform()`と同じ参照解決ロジックを使うが、何も永続化しない
	 * （`Woo\Writer\EntityWriter::validate()`経由。F1-6）。
	 */
	public function for_dry_run( string $platform ): WooWriter;
}
