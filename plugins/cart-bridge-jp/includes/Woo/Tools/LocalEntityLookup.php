<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Support\Money;
use WC_Order;

/**
 * mappings が指すローカルID群のうち、Woo側に実在するものを確認する（移行後検証レポート=D17）。
 *
 * 受注は要件どおりWooCommerce CRUD（`wc_get_order()`）のみで扱い、`wp_posts`/`wc_orders`を直接
 * 読まない。HPOS以外の構成（互換モード）でも同じ結果になるよう、`wc_get_orders()`のID指定引数
 * （HPOSでは`post__in`→`id`エイリアス、CPTでは別扱い）には依存せず1件ずつ読む。
 * 商品/クーポン/ターム/ユーザーはコアのクエリ関数でチャンク単位に確認する。
 */
final class LocalEntityLookup {

	private const CHUNK_SIZE = 200;

	/**
	 * @param array<int,int> $ids
	 * @return array<int,int> 実在するIDのみ（重複除去）。
	 */
	public function existing_ids( string $entity, array $ids ): array {
		$existing = [];

		foreach ( array_chunk( $this->normalize_ids( $ids ), self::CHUNK_SIZE ) as $chunk ) {
			$existing = array_merge( $existing, $this->existing_chunk( $entity, $chunk ) );
		}

		return $existing;
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
	 * @param array<int,int> $chunk
	 * @return array<int,int>
	 */
	private function existing_chunk( string $entity, array $chunk ): array {
		return match ( $entity ) {
			'product'  => $this->existing_post_ids( [ 'product' ], $chunk ),
			'variant'  => $this->existing_post_ids( [ 'product_variation' ], $chunk ),
			// stockのlocal_idは在庫を書き込んだ商品またはバリエーション（`Writer\StockWriter`）。
			'stock'    => $this->existing_post_ids( [ 'product', 'product_variation' ], $chunk ),
			'coupon'   => $this->existing_post_ids( [ 'shop_coupon' ], $chunk ),
			'category' => $this->existing_term_ids( 'product_cat', $chunk ),
			'tag'      => $this->existing_term_ids( 'product_tag', $chunk ),
			'customer' => $this->existing_user_ids( $chunk ),
			'order'    => $this->existing_order_ids( $chunk ),
			'review'   => $this->existing_comment_ids( $chunk ),
			default    => [],
		};
	}

	/**
	 * @param array<int,string> $post_types
	 * @param array<int,int>    $chunk
	 * @return array<int,int>
	 */
	private function existing_post_ids( array $post_types, array $chunk ): array {
		$ids = get_posts(
			[
				'post_type'      => $post_types,
				'post__in'       => $chunk,
				'post_status'    => 'any',
				'posts_per_page' => count( $chunk ),
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			]
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * @param array<int,int> $chunk
	 * @return array<int,int>
	 */
	private function existing_term_ids( string $taxonomy, array $chunk ): array {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'include'    => $chunk,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		return is_array( $terms ) ? array_map( 'intval', $terms ) : [];
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
	 * @param array<int,int> $chunk
	 * @return array<int,int>
	 */
	private function existing_comment_ids( array $chunk ): array {
		$ids = get_comments(
			[
				'comment__in' => $chunk,
				'fields'      => 'ids',
				'number'      => count( $chunk ),
			]
		);

		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
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
