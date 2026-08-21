<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\RateLimitExhaustedException;
use Throwable;

/**
 * 無料版サンプル選定ロジック（D15/`docs/03-design-decisions.md` §10.2）。
 *
 * 最新受注10件（`fetch_latest_orders`）を起点に、明細の remote_product_id と
 * 購入者（customer_ref）を抽出してサンプルセットを決定し、`cbjp_sample_{platform}`
 * （autoload無効）に保存する。再実行は同一セットを再利用する（クリーンアップ→再選定のみ変更可、§10.2 #7）。
 *
 * 受注が10件未満の場合は、通常の商品・顧客一覧の先頭ページ（新しい順ソート可能なら新しい順、
 * 不可ならAPI標準順）から残り枠を補完する（§10.2 #5後半）。顧客の一覧取得に対応しない
 * アダプタ（例: BASE。D12）や一時的なfetch失敗は補完をスキップし、受注由来の分だけで確定する
 * （Sync層にプラットフォーム固有知識を置かない原則。アーキテクチャ原則8）。
 */
final class SampleSelector {

	private const SAMPLE_ORDER_LIMIT = 10;
	private const PRODUCT_HARD_CAP   = 50;
	private const CUSTOMER_CAP       = 10;

	public function __construct(
		private readonly PlatformAdapter $adapter,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * 既存のサンプルセットがあればそれを返し、なければ新規に選定して永続化する。
	 */
	public function select_or_load( string $platform ): SampleSet {
		return $this->load( $platform ) ?? $this->select_and_persist( $platform );
	}

	public function load( string $platform ): ?SampleSet {
		$stored = get_option( $this->option_name( $platform ) );

		return is_array( $stored ) ? SampleSet::from_array( $stored ) : null;
	}

	/**
	 * サンプルクリーンアップツール（Phase 1 F1-7）から呼ばれる想定。
	 */
	public function clear( string $platform ): void {
		delete_option( $this->option_name( $platform ) );
	}

	private function select_and_persist( string $platform ): SampleSet {
		$orders = $this->adapter->fetch_latest_orders( self::SAMPLE_ORDER_LIMIT );

		$order_remote_ids   = [];
		$product_remote_ids = [];
		$customer_refs      = [];

		foreach ( $orders as $order ) {
			$order_remote_ids[] = $order->number;

			foreach ( $order->line_items as $line_item ) {
				// アダプタ実装がline_itemsの各要素を配列以外で返す可能性への防御（型宣言はドキュメント上の契約でしかないため）。
				$remote_product_id = is_array( $line_item ) ? ( $line_item['remote_product_id'] ?? null ) : null;

				if ( null !== $remote_product_id && '' !== $remote_product_id ) {
					$product_remote_ids[ (string) $remote_product_id ] = true;
				}
			}

			if ( null !== $order->customer_ref && '' !== $order->customer_ref ) {
				$customer_refs[ $order->customer_ref ] = true;
			}
		}

		$used_fallback = count( $orders ) < self::SAMPLE_ORDER_LIMIT;

		// PHPは数値文字列の配列キーをintに正規化するため、array_keys() の結果を
		// 明示的にstring化する（strict_types下のアダプタIFへ int が渡るのを防ぐ）。
		$product_ids  = array_slice( array_map( 'strval', array_keys( $product_remote_ids ) ), 0, self::PRODUCT_HARD_CAP );
		$customer_ids = array_slice( array_map( 'strval', array_keys( $customer_refs ) ), 0, self::CUSTOMER_CAP );

		if ( $used_fallback ) {
			// §10.2 #5後半: 受注（0件・10件未満いずれも）だけでは各エンティティ10件に満たない場合、
			// 通常一覧の先頭ページで残り枠を補完する。
			$product_ids  = $this->top_up_with_first_page( $product_ids, 'product', self::SAMPLE_ORDER_LIMIT );
			$customer_ids = $this->top_up_with_first_page( $customer_ids, 'customer', self::SAMPLE_ORDER_LIMIT );
		}

		$sample = new SampleSet(
			array_map( 'strval', $order_remote_ids ),
			$product_ids,
			$customer_ids,
			$used_fallback
		);

		// 受注・商品・顧客のすべてが空の場合は永続化しない: データが入り次第、次回実行で
		// 再選定できるようにする（§10.2 #7 の「クリーンアップ→再選定」を経ずに空サンプルが
		// 固定されるのを防ぐ）。
		if ( [] === $sample->order_remote_ids && [] === $sample->product_remote_ids && [] === $sample->customer_refs ) {
			return $sample;
		}

		$this->persist( $platform, $sample );

		return $sample;
	}

	/**
	 * `$existing`に既に含まれる件数が`$target`未満の場合のみ、通常一覧（`Cursor::start()`の
	 * 先頭ページ）から不足分を補う。重複は除外する。customer は
	 * `Capabilities::can_fetch_customers` が false のアダプタでは呼び出さず、
	 * いずれのエンティティも一覧取得自体が失敗（`UnsupportedOperationException`等）した場合は
	 * `Logger::warning()`に記録したうえで補完をスキップし`$existing`をそのまま返す（無言で
	 * スキップすると、本番で劣化したサンプルが永続化された原因を追跡できなくなる）。
	 * `RateLimitExhaustedException`は補完の失敗ではなくジョブの一時停止・再開（`JobManager`）に
	 * 委ねるべきシグナルのため、ここでは握りつぶさず再送出する。
	 *
	 * @param array<int,string> $existing
	 * @return array<int,string>
	 */
	private function top_up_with_first_page( array $existing, string $entity, int $target ): array {
		if ( count( $existing ) >= $target ) {
			return $existing;
		}

		if ( 'customer' === $entity && ! $this->adapter->capabilities()->can_fetch_customers ) {
			return $existing;
		}

		try {
			$page = 'product' === $entity
				? $this->adapter->fetch_products( Cursor::start() )
				: $this->adapter->fetch_customers( Cursor::start() );
		} catch ( RateLimitExhaustedException $exception ) {
			throw $exception;
		} catch ( Throwable $exception ) {
			// 個人情報禁止ルール（`Importer`の同種catch節と同じ方針）に従い、例外クラス名のみを
			// 記録する。ここで記録を怠ると、劣化したサンプルが永続化された原因を本番で
			// 追跡する手段が無くなる。
			$this->logger->warning(
				"Failed to top up the \"{$entity}\" sample from the first page of the normal list.",
				[
					'platform'  => $this->adapter->id(),
					'exception' => $exception::class,
				]
			);

			return $existing;
		}

		$seen = array_fill_keys( $existing, true );

		foreach ( $page->items as $item ) {
			if ( count( $existing ) >= $target ) {
				break;
			}

			$remote_id = $item->remote_id();

			if ( null === $remote_id || isset( $seen[ $remote_id ] ) ) {
				continue;
			}

			$seen[ $remote_id ] = true;
			$existing[]         = $remote_id;
		}

		return $existing;
	}

	private function persist( string $platform, SampleSet $sample ): void {
		update_option( $this->option_name( $platform ), $sample->to_array(), false );
	}

	private function option_name( string $platform ): string {
		return 'cbjp_sample_' . $platform;
	}
}
