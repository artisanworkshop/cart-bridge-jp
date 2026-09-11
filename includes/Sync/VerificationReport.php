<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Support\Money;
use CartBridgeJP\Woo\Tools\LocalEntityLookup;
use CartBridgeJP\Woo\Writer\OrderWriter;

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
	 * @return array{run_id:string,platform:string,type:string,currency:string,platform_currency:string,currency_mismatch:bool,entities:array<int,array<string,mixed>>}|null run が無ければ null。
	 */
	public function build( string $run_id ): ?array {
		$jobs = $this->jobs->find_by_run( $run_id );

		if ( [] === $jobs ) {
			return null;
		}

		$platform   = (string) $jobs[0]['platform'];
		$entities   = [];
		$currencies = [];

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
				$summary = $this->lookup->summarize_orders( $existing );

				// F1-7 より前に作られたジョブの totals_json には `remote_amount` が無い。0 として扱うと
				// 「ASP 側 0.00 / Woo 側 N」という偽の不一致になるため、キーが無ければ「不明」（null）にする。
				$row['remote_amount'] = $this->has_remote_amount( $job['totals_json'] ) ? Money::format_minor_units( $totals['remote_amount'] ) : null;
				$row['local_amount']  = Money::format_minor_units( $summary['total_minor'] );
				$currencies           = array_values( array_unique( array_merge( $currencies, $summary['currencies'] ) ) );
			}

			$entities[] = $row;
		}

		// Woo 側の通貨は「リンク済み受注に保存されている通貨」を正とする（`OrderWriter` は取込時の店舗通貨を
		// 受注に保存する。店舗通貨を後から変えても受注の通貨は変わらないため、現在の設定で判定すると
		// USD で取り込んだ受注を JPY として突合してしまう）。受注が無ければ現在の店舗通貨を表示用に返す。
		$currency = 1 === count( $currencies ) ? $currencies[0] : get_woocommerce_currency();

		return [
			'run_id'            => $run_id,
			'platform'          => $platform,
			'type'              => (string) $jobs[0]['type'],
			'currency'          => $currency,
			// ASP 側の金額の通貨。受注側の通貨と異なる（または受注間で通貨が混在する）場合、`OrderWriter` は
			// 数値をそのまま保存しているため両者は数値上一致しても同じ金額ではない（UI は金額突合を「不可」として扱う）。
			'platform_currency' => OrderWriter::PLATFORM_CURRENCY,
			'currency_mismatch' => [] !== $currencies && [ OrderWriter::PLATFORM_CURRENCY ] !== $currencies,
			'entities'          => $entities,
		];
	}

	private function has_remote_amount( ?string $totals_json ): bool {
		$decoded = null !== $totals_json ? json_decode( $totals_json, true ) : null;

		return is_array( $decoded ) && array_key_exists( 'remote_amount', $decoded );
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
