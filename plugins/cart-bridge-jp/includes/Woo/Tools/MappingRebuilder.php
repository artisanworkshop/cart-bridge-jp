<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Entities\EntityTypeRegistry;
use CartBridgeJP\Entities\LinkSource;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Sync\MappingRepository;
use InvalidArgumentException;
use Throwable;

/**
 * リンク再構築ツール（D16 / `docs/03-design-decisions.md` §10.3）。
 *
 * 再インストール・DB移設等で `cbjp_mappings` が失われた場合に、各 Writer が Woo 側の実体へ必ず
 * 書き込む `_cbjp_platform` + `_cbjp_remote_id`（受注は `_cbjp_remote_order_number` =
 * `CanonicalOrder::remote_id()`）メタを走査して mappings を復元する。SKU / email による突合は
 * 「本プラグイン外で作られた Woo データを ASP に紐付ける」動作になり誤リンクのリスクがあるため
 * 行わない（メタが無いデータは対象外）。
 *
 * checksum は null で upsert する（`Sync\Importer` が次回 import 時に必ず再検証する）。
 * stock は product/variant の mapping から次回 import 時に再解決され、review は v1.0 に Writer が
 * 無いため、どちらも再構築の対象外。
 *
 * 1回の `run()` は予算（`$budget` 件）まで走査して cursor を返し、呼び出し側がループする。
 * upsert は走査結果を変えないため offset ページングで安定に継続できる。
 *
 * 走査する実体は実体の種類が持つ（`Entities\EntityType::link_sources()`。R3-6b1）。走査順は `LinkSource::position()`
 * （category・tag・product・variant・coupon・customer・order）。cursor は `{entity, offset}` で、entity は走査中の LinkSource のキー。
 * 1 つの LinkSource の走査が例外を投げたら、記録してその種類を飛ばす（外部の種類が、ほかの種類の復元を止めないように）。
 */
final class MappingRebuilder {

	public const DEFAULT_BUDGET = 200;

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * @return array{counts:array<string,int>,cursor:?string} `cursor` が null なら完了。
	 *
	 * @throws InvalidArgumentException cursor が不正な場合。
	 */
	public function run( string $platform, ?string $cursor = null, int $budget = self::DEFAULT_BUDGET ): array {
		$sources            = EntityTypeRegistry::link_sources();
		$keys               = array_map( static fn ( LinkSource $source ): string => $source->key(), $sources );
		[ $index, $offset ] = $this->decode_cursor( $cursor, $keys );

		$counts    = array_fill_keys( $keys, 0 );
		$remaining = max( 1, $budget );
		$count     = count( $sources );

		while ( $index < $count && $remaining > 0 ) {
			$entity = $keys[ $index ];

			try {
				$result = $sources[ $index ]->scan( $platform, $offset, $remaining );
			} catch ( Throwable $exception ) {
				$result = $exception::class;
			}

			$scanned = is_array( $result ) ? ( $result['scanned'] ?? null ) : null;
			$rows    = is_array( $result ) ? ( $result['rows'] ?? null ) : null;

			// 外部の LinkSource の戻り値は信用しない（原則 8）。例外・形の違う結果・ありえない件数（求めた件数より多く走査した、
			// 走査した件数より多くの行を返した）は記録してその種類を飛ばす。件数を信じると、予算を超えたり、保存する offset が
			// まだ見ていない実体を追い越して、以後の再構築がそれらを飛ばし続けたりする。
			if ( ! is_int( $scanned ) || $scanned < 0 || $scanned > $remaining || ! is_array( $rows ) || count( $rows ) > $scanned ) {
				$this->logger->error(
					'Mapping rebuild skipped a source that failed to scan.',
					[
						'platform'  => $platform,
						'entity'    => $entity,
						'exception' => is_string( $result ) ? $result : null,
					]
				);

				++$index;
				$offset = 0;
				continue;
			}

			foreach ( $rows as $local_id => $remote_id ) {
				if ( ! is_int( $local_id ) || ! is_string( $remote_id ) || '' === $remote_id ) {
					continue;
				}

				$this->mappings->upsert( $platform, $entity, $remote_id, $local_id, null );
				++$counts[ $entity ];
			}

			// cursor の前進判定は「クエリが返した件数」（所有権フィルタで除外した分を含む）で行う。
			// upsert 対象の件数で判定すると、除外行が多いページで走査し切ったと誤認して残りを飛ばす。
			if ( $scanned < $remaining ) {
				// 要求件数に満たない＝このエンティティは走査し切った。
				++$index;
				$offset = 0;
			} else {
				$offset += $scanned;
			}

			$remaining -= $scanned;
		}

		$next_cursor = $index < $count ? $this->encode_cursor( $keys[ $index ], $offset ) : null;

		$this->logger->info(
			'Mapping rebuild batch finished.',
			[
				'platform' => $platform,
				'counts'   => $counts,
				'done'     => null === $next_cursor,
			]
		);

		return [
			'counts' => $counts,
			'cursor' => $next_cursor,
		];
	}

	/**
	 * @param array<int,string> $keys 走査順の LinkSource のキー。
	 * @return array{0:int,1:int} [走査順のインデックス, offset]
	 *
	 * @throws InvalidArgumentException
	 */
	private function decode_cursor( ?string $cursor, array $keys ): array {
		if ( null === $cursor || '' === $cursor ) {
			return [ 0, 0 ];
		}

		$decoded = json_decode( $cursor, true );
		$entity  = is_array( $decoded ) ? ( $decoded['entity'] ?? null ) : null;
		$offset  = is_array( $decoded ) ? ( $decoded['offset'] ?? null ) : null;
		$index   = is_string( $entity ) ? array_search( $entity, $keys, true ) : false;

		if ( false === $index || ! is_int( $offset ) || $offset < 0 ) {
			throw new InvalidArgumentException( 'Invalid rebuild cursor.' );
		}

		return [ $index, $offset ];
	}

	private function encode_cursor( string $entity, int $offset ): string {
		return (string) wp_json_encode(
			[
				'entity' => $entity,
				'offset' => $offset,
			]
		);
	}
}
