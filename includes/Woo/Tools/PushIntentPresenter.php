<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Tools;

use CartBridgeJP\Woo\Support\HtmlText;
use WC_Coupon;
use WC_Order;
use WC_Product;
use WP_User;

/**
 * `GET /push-intents/{platform}` が表示する、未解決push intentのローカル実体の説明を組み立てる
 * （D21-B。`docs/03-design-decisions.md` §10.2「解除UIとREST」）。店舗がColorMe管理画面で
 * 実体を探す手がかり（受注番号・日時・合計／商品名・SKU／顧客メール）を返す。PII（メール・
 * 受注番号等）を含むが、これは画面とREST応答専用。`Support\Logger`へは絶対に渡さないこと
 * （個人情報禁止ルール）。
 *
 * HPOS対応必須（CLAUDE.md）: 受注は`wc_get_order()`/`WC_Order::get_edit_order_url()`のみを使い、
 * `get_edit_post_link()`は使わない。
 */
final class PushIntentPresenter {

	/**
	 * @return array{exists:bool, edit_url:?string, details:array<string,mixed>}
	 */
	public function describe( string $entity_type, int $local_id ): array {
		return match ( $entity_type ) {
			'product' => $this->describe_product( $local_id ),
			'customer' => $this->describe_customer( $local_id ),
			'order' => $this->describe_order( $local_id ),
			'coupon' => $this->describe_coupon( $local_id ),
			default => [
				'exists'   => false,
				'edit_url' => null,
				'details'  => [],
			],
		};
	}

	/**
	 * @return array{exists:bool, edit_url:?string, details:array<string,mixed>}
	 */
	private function describe_product( int $local_id ): array {
		$product = wc_get_product( $local_id );

		if ( ! $product instanceof WC_Product ) {
			return $this->missing();
		}

		return [
			'exists'   => true,
			'edit_url' => get_edit_post_link( $local_id, 'raw' ),
			'details'  => [
				// Woo の名前は HTML。画面は文字として出すので平文へ戻す（issue #99）。
				'name' => HtmlText::to_plain( $product->get_name() ),
				'sku'  => '' !== $product->get_sku() ? $product->get_sku() : null,
			],
		];
	}

	/**
	 * @return array{exists:bool, edit_url:?string, details:array<string,mixed>}
	 */
	private function describe_customer( int $local_id ): array {
		$user = get_userdata( $local_id );

		if ( ! $user instanceof WP_User ) {
			return $this->missing();
		}

		return [
			'exists'   => true,
			'edit_url' => get_edit_user_link( $local_id ),
			'details'  => [
				'email' => $user->user_email,
			],
		];
	}

	/**
	 * @return array{exists:bool, edit_url:?string, details:array<string,mixed>}
	 */
	private function describe_order( int $local_id ): array {
		$order = wc_get_order( $local_id );

		if ( ! $order instanceof WC_Order || 'trash' === $order->get_status() ) {
			return $this->missing();
		}

		$date_created = $order->get_date_created();

		return [
			'exists'   => true,
			'edit_url' => $order->get_edit_order_url(),
			'details'  => [
				'number'       => $order->get_order_number(),
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
				'date_created' => null !== $date_created ? $date_created->date( DATE_ATOM ) : null,
			],
		];
	}

	/**
	 * @return array{exists:bool, edit_url:?string, details:array<string,mixed>}
	 */
	private function describe_coupon( int $local_id ): array {
		if ( 'shop_coupon' !== get_post_type( $local_id ) ) {
			return $this->missing();
		}

		$coupon = new WC_Coupon( $local_id );

		return [
			'exists'   => true,
			'edit_url' => get_edit_post_link( $local_id, 'raw' ),
			'details'  => [
				'code' => $coupon->get_code(),
			],
		];
	}

	/**
	 * @return array{exists:bool, edit_url:?string, details:array<string,mixed>}
	 */
	private function missing(): array {
		return [
			'exists'   => false,
			'edit_url' => null,
			'details'  => [],
		];
	}
}
