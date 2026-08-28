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
 * `Sync\WooWriter` の実装だが `EntityWriter::write()` は一切呼ばず `validate()` のみを呼ぶ
 * （F1-6のdry-run。実writerと同じ参照解決・値検証ロジックを使い、何も永続化せず
 * 実警告を返す）。メソッド名`write()`は`Sync\WooWriter`契約に従うためのものだが、
 * このクラス自身が呼ぶのは常に`validate()`であるため、実装上dry-runが実データを
 * 書き込む経路は構造的に存在しない。
 */
final class DryRunRepository implements WooWriter {

	/**
	 * @param array<string,EntityWriter> $writers entity名 => Writer（`WooRepositoryFactory`と同じ組み立て）。
	 */
	public function __construct(
		private readonly SideEffectGuard $guard,
		private readonly array $writers
	) {}

	public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		$writer = $this->writers[ $entity ] ?? null;

		if ( null === $writer ) {
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::ENTITY_NOT_SUPPORTED ] );
		}

		// `validate()`は何も永続化しないため`SideEffectGuard`は本来不要だが、サードパーティ
		// プラグインがWooCommerceのセッター系フック（`woocommerce_product_set_stock_status`等）で
		// 副作用を起こす可能性への多重防御として、実writerと同じくガードで包む。
		$result = $this->guard->run( static fn () => $writer->validate( $item, $existing_local_id ) );

		// 何も永続化しないため local_id は常に0（`Importer::process_items()`のmappings書込契約:
		// local_id===0はmappingsを書かせない）。`fully_resolved`（デフォルトtrue）は
		// local_id===0の結果に対しては`Importer`が参照しない（checksumキャッシュ判定は
		// `$did_persist`がtrueの場合のみ行われるため）ため指定不要。
		return new WriteResult( 0, $result->operation, $result->warnings );
	}
}
