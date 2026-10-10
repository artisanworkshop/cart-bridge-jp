<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Woo\Tools;

use CartBridgeJP\Support\Money;
use WC_Order;

/**
 * mappings が指す顧客（ユーザー）・受注のうち、Woo側に実在するものを確認する（移行後検証レポート=D17。R3-6c1 で
 * `LocalEntityLookup` から分けた）。
 *
 * 受注は要件どおりWooCommerce CRUD（`wc_get_order()`）のみで扱い、`wp_posts`/`wc_orders`を直接
 * 読まない。HPOS以外の構成（互換モード）でも同じ結果になるよう、`wc_get_orders()`のID指定引数
 * （HPOSでは`post__in`→`id`エイリアス、CPTでは別扱い）には依存せず1件ずつ読む。
 * ユーザーはコアのクエリ関数でチャンク単位に確認する。
 */
final class CommerceLookup {

	private const CHUNK_SIZE = 200;

	/**
	 * @param array<int,int> $ids
	 * @return array<int,int>
	 */
	public function existing_users( array $ids ): array {
		return $this->in_chunks( $ids, fn ( array $chunk ): array => $this->existing_user_ids( $chunk ) );
	}

	/**
	 * 受注（ゴミ箱・返金は含まない）。
	 *
	 * @param array<int,int> $ids
	 * @return array<int,int>
	 */
	public function existing_orders( array $ids ): array {
		return $this->in_chunks( $ids, fn ( array $chunk ): array => $this->existing_order_ids( $chunk ) );
	}

	/**
	 * 実在する受注の合計金額（`WC_Order::get_total()`）を1/100単位で合算し、受注に保存されている
	 * 通貨の一覧も返す（店舗通貨は後から変更されうるため、突合の通貨判定は受注側の値を正とする）。
	 *
	 * @param array<int,int> $order_ids
	 * @return array{total_minor:int,currencies:array<int,string>}
	 */
	public function summarize_orders( array $order_ids ): array {
		$sum        = 0;
		$currencies = [];

		foreach ( $this->normalize_ids( $order_ids ) as $order_id ) {
			$order = $this->load_order( $order_id );

			if ( null !== $order ) {
				$sum                                 += Money::to_minor_units( $order->get_total() ) ?? 0;
				$currencies[ $order->get_currency() ] = true;
			}
		}

		return [
			'total_minor' => $sum,
			'currencies'  => array_keys( $currencies ),
		];
	}

	/**
	 * @param array<int,int> $ids
	 * @return array<int,int>
	 */
	private function normalize_ids( array $ids ): array {
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * 重複を除いた ID を `CHUNK_SIZE` 件ずつ `$lookup` に渡し、結果をつなぐ（`LocalEntityLookup` と同じ）。
	 *
	 * @param array<int,int>                       $ids
	 * @param callable(array<int,int>):array<int,int> $lookup
	 * @return array<int,int>
	 */
	private function in_chunks( array $ids, callable $lookup ): array {
		$existing = [];

		foreach ( array_chunk( $this->normalize_ids( $ids ), self::CHUNK_SIZE ) as $chunk ) {
			$existing = array_merge( $existing, $lookup( $chunk ) );
		}

		return $existing;
	}

	/**
	 * @param array<int,int> $chunk
	 * @return array<int,int>
	 */
	private function existing_user_ids( array $chunk ): array {
		$ids = get_users(
			[
				'include' => $chunk,
				'fields'  => 'ID',
				'number'  => count( $chunk ),
			]
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @param array<int,int> $chunk
	 * @return array<int,int>
	 */
	private function existing_order_ids( array $chunk ): array {
		$existing = [];

		foreach ( $chunk as $order_id ) {
			if ( null !== $this->load_order( $order_id ) ) {
				$existing[] = $order_id;
			}
		}

		return $existing;
	}

	/**
	 * ゴミ箱の受注・返金（`shop_order_refund`）は「存在しない」扱いにする。
	 */
	private function load_order( int $order_id ): ?WC_Order {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || 'trash' === $order->get_status() ) {
			return null;
		}

		return $order;
	}
}
