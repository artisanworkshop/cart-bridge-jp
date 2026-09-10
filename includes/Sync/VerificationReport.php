<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Support\Money;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;

/**
 * 移行後検証レポート（D17 / `docs/03-design-decisions.md` §10.4）。
 *
 * run（`type=import`）のジョブごとに、ASP側（この run で取得・書込した件数と、受注は
 * `Importer` が totals に累積した合計金額 `remote_amount`）と Woo側（mappings がリンクする実体の
 * 実在数と、受注は `WC_Order::get_total()` の合計）を並べる。ASP側の件数・金額は「この run で
 * 取得した全件」（無料版の上限でスキップした分を含む）、Woo側は「リンク済みで実在する全件」
 * なので、無料版では ASP 側が大きくなるのが正常。`missing`（mapping はあるが実体が無い）が 0 で
 * 件数・金額が一致すれば完全に整合している。
 */
final class VerificationReport {

	public function __construct(
		private readonly JobRepository $jobs,
		private readonly MappingRepository $mappings,
		private readonly LocalEntityLookup $lookup = new LocalEntityLookup()
	) {}

	/**
	 * @return array{run_id:string,platform:string,type:string,currency:string,entities:array<int,array<string,mixed>>}|null run が無ければ null。
	 */
	public function build( string $run_id ): ?array {
		$jobs = $this->jobs->find_by_run( $run_id );

		if ( [] === $jobs ) {
			return null;
		}

		$platform = (string) $jobs[0]['platform'];
		$entities = [];

		foreach ( $jobs as $job ) {
			$entity    = (string) $job['entity'];
			$totals    = $this->decode_totals( $job['totals_json'] );
			$local_ids = $this->mappings->local_ids( $platform, $entity );
			$existing  = $this->lookup->existing_ids( $entity, $local_ids );

			$row = [
				'entity'        => $entity,
				'status'        => (string) $job['status'],
				'processed'     => $totals['processed'],
				'written'       => $totals['created'] + $totals['updated'],
				'skipped'       => $totals['skipped'],
				'warned'        => $totals['warned'],
				'linked'        => count( $local_ids ),
				'existing'      => count( $existing ),
				'missing'       => count( $local_ids ) - count( $existing ),
				'remote_amount' => null,
				'local_amount'  => null,
			];

			if ( 'order' === $entity ) {
				$row['remote_amount'] = Money::format_minor_units( $totals['remote_amount'] );
				$row['local_amount']  = Money::format_minor_units( $this->lookup->sum_order_totals( $existing ) );
			}

			$entities[] = $row;
		}

		return [
			'run_id'   => $run_id,
			'platform' => $platform,
			'type'     => (string) $jobs[0]['type'],
			// `Writer\OrderWriter::apply_currency_and_tax_settings()` が受注に設定する通貨と同じ。
			'currency' => get_woocommerce_currency(),
			'entities' => $entities,
		];
	}

	/**
	 * @return array{total:int,processed:int,created:int,updated:int,skipped:int,warned:int,failed:int,remote_amount:int}
	 */
	private function decode_totals( ?string $totals_json ): array {
		$decoded = null !== $totals_json ? json_decode( $totals_json, true ) : null;
		$totals  = $this->jobs->empty_totals();

		if ( ! is_array( $decoded ) ) {
			return $totals;
		}

		foreach ( $totals as $key => $default ) {
			$value          = $decoded[ $key ] ?? $default;
			$totals[ $key ] = is_numeric( $value ) ? (int) $value : $default;
		}

		return $totals;
	}
}
