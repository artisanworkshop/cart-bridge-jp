<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\PlatformAdapter;

/**
 * 無料版サンプル選定ロジック（D15/`docs/03-design-decisions.md` §10.2）。
 *
 * 最新受注10件（`fetch_latest_orders`）を起点に、明細の remote_product_id と
 * 購入者（customer_ref）を抽出してサンプルセットを決定し、`cbjp_sample_{platform}`
 * （autoload無効）に保存する。再実行は同一セットを再利用する（クリーンアップ→再選定のみ変更可、§10.2 #7）。
 *
 * 受注が10件未満の場合の「残り枠を商品・顧客で補完」（§10.2 #5後半）は、
 * 実アダプタが揃うPhase 1以降に各エンティティの通常一覧取得と合わせて実装する。
 * Phase 0時点では `used_fallback` フラグを立てるところまでを提供する。
 */
final class SampleSelector {

	private const SAMPLE_ORDER_LIMIT = 10;
	private const PRODUCT_HARD_CAP   = 50;
	private const CUSTOMER_CAP       = 10;

	public function __construct( private readonly PlatformAdapter $adapter ) {}

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

		if ( [] === $orders ) {
			// 空セットは永続化しない: 受注が入り次第、次回実行で再選定できるようにする
			// （§10.2 #7 の「クリーンアップ→再選定」を経ずに空サンプルが固定されるのを防ぐ）。
			return new SampleSet( [], [], [], true );
		}

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

		// PHPは数値文字列の配列キーをintに正規化するため、array_keys() の結果を
		// 明示的にstring化する（strict_types下のアダプタIFへ int が渡るのを防ぐ）。
		$sample = new SampleSet(
			array_map( 'strval', $order_remote_ids ),
			array_slice( array_map( 'strval', array_keys( $product_remote_ids ) ), 0, self::PRODUCT_HARD_CAP ),
			array_slice( array_map( 'strval', array_keys( $customer_refs ) ), 0, self::CUSTOMER_CAP ),
			count( $orders ) < self::SAMPLE_ORDER_LIMIT
		);

		$this->persist( $platform, $sample );

		return $sample;
	}

	private function persist( string $platform, SampleSet $sample ): void {
		update_option( $this->option_name( $platform ), $sample->to_array(), false );
	}

	private function option_name( string $platform ): string {
		return 'cbjp_sample_' . $platform;
	}
}
