<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

use WC_Payment_Gateway;
use WC_Shipping_Method;
use WC_Shipping_Zone;
use WC_Shipping_Zones;
use WP_Term;

/**
 * `/settings/mappings/{platform}` UI向けのWoo側マッピング候補一覧（D19）。プラットフォーム非依存
 * （`Adapters\*`を一切importしない）のため、アダプタを介さず`RestController`から直接呼べる
 * （アーキテクチャ原則1に抵触しない。ASP側の候補は各アダプタの`mapping_candidates()`が持つ）。
 */
final class MappingCandidates {

	private function __construct() {}

	/**
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function categories(): array {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$candidates = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$candidates[] = [
				'id'   => (string) $term->term_id,
				// `$term->name`はDB保存時にエンティティ化された生の値（例: `Men &amp; Women`）を
				// そのまま返す。HTML出力ならブラウザが1回だけデコードするが、このJSON APIはReactの
				// テキストノードへそのまま渡るため、Reactが再度エスケープして「Men &amp; Women」が
				// 文字どおり表示されてしまう。ここでデコードしておく（G3指摘）。
				'name' => wp_specialchars_decode( $term->name, ENT_QUOTES ),
			];
		}

		return $candidates;
	}

	/**
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function payment_gateways(): array {
		$candidates = [];

		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			if ( ! $gateway instanceof WC_Payment_Gateway ) {
				continue;
			}

			$candidates[] = [
				'id'   => (string) $id,
				// 未登録のゲートウェイIDでもID自体をフォールバックとして返す仕様のため常に非空文字列
				// （`Woo\Support\MethodMap::payment_gateway_title()`と同じ呼び出し）。
				'name' => WC()->payment_gateways()->get_payment_gateway_name_by_id( (string) $id ),
			];
		}

		return $candidates;
	}

	/**
	 * ゾーン別配送方法インスタンスを`method_id:instance_id`形式（`MethodMap::split_shipping_method_id()`が
	 * 期待する形式）で返す。「地域が指定されていない場所」ゾーン（zone_id=0）は`get_zones()`に含まれない
	 * ため別途取得する。
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function shipping_methods(): array {
		$candidates = [];

		foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
			$zone_name = is_array( $zone ) ? Value::string( $zone['zone_name'] ?? null ) ?? '' : '';
			$methods   = is_array( $zone ) && is_array( $zone['shipping_methods'] ?? null ) ? $zone['shipping_methods'] : [];

			self::append_zone_methods( $candidates, $zone_name, $methods );
		}

		$rest_of_world = WC_Shipping_Zones::get_zone( 0 );

		if ( $rest_of_world instanceof WC_Shipping_Zone ) {
			self::append_zone_methods( $candidates, $rest_of_world->get_zone_name(), $rest_of_world->get_shipping_methods() );
		}

		return $candidates;
	}

	/**
	 * @param array<int,array{id:string,name:string}> $candidates
	 * @param array<int|string,mixed>                 $methods
	 */
	private static function append_zone_methods( array &$candidates, string $zone_name, array $methods ): void {
		foreach ( $methods as $method ) {
			if ( ! $method instanceof WC_Shipping_Method ) {
				continue;
			}

			$title = $method->get_title();
			$name  = '' !== $zone_name ? "{$zone_name}: {$title}" : $title;

			$candidates[] = [
				'id'   => "{$method->id}:{$method->instance_id}",
				'name' => $name,
			];
		}
	}

	/**
	 * WooCommerce Blocksのチェックアウト下書き注文が使う内部ステータス。`wc_get_order_statuses()`の
	 * 結果に含まれるが、`woocommerce_cleanup_draft_orders`（日次cron）が24時間経過したこのステータスの
	 * 注文を`WC_Order::delete(true)`で完全削除する（`DraftOrders::delete_expired_draft_orders()`）ため、
	 * マッピング候補に出すとASP受注をこのステータスへ意図せず対応付けた場合に受注が消滅しうる。
	 * `Woo\Writer\OrderWriter::apply_status()`が`is_disallowed_order_status()`経由でも参照しており、
	 * `status_map`にREST直PUT等で直接この値を書き込まれた場合の書込み側フェイルクローズも兼ねる
	 * （マッピング候補一覧からの除外だけでは、UIを経由しない直接PUTを防げないため。G1指摘）。
	 */
	private const EXCLUDED_ORDER_STATUSES = [ 'checkout-draft' ];

	/**
	 * `status_map`の値としてこのステータスを書き込んでよいかを判定する（`EXCLUDED_ORDER_STATUSES`参照）。
	 * `OrderWriter::apply_status()`が候補一覧の外側（直接REST PUT・過去に保存済みの設定値）からも
	 * このステータスへの書込みを防ぐために使う。
	 */
	public static function is_disallowed_order_status( string $status ): bool {
		return in_array( $status, self::EXCLUDED_ORDER_STATUSES, true );
	}

	/**
	 * @return array<int,array{id:string,name:string}>
	 */
	public static function order_statuses(): array {
		$candidates = [];

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			// OrderWriterが書き込む値の規約（`wc-`接頭辞なし）に合わせて除去する。
			$status = str_starts_with( $slug, 'wc-' ) ? substr( $slug, 3 ) : $slug;

			if ( self::is_disallowed_order_status( $status ) ) {
				continue;
			}

			$candidates[] = [
				'id'   => $status,
				'name' => Value::string( $label ) ?? $status,
			];
		}

		return $candidates;
	}
}
