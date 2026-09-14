<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Woo\WarningCode;
use WC_Coupon;
use WC_DateTime;
use WP_Query;

/**
 * `WC_Coupon`（投稿タイプ`shop_coupon`）を`CanonicalCoupon`へ変換する（`Woo\Writer\CouponWriter`の
 * 読出側対称形）。`wc_get_coupons()`ヘルパーは存在しないため`WP_Query`を直接使う。
 *
 * `CanonicalCoupon::$type`は`'fixed'|'percent'`の2値のみでWooの`discount_type`
 * （`percent`/`fixed_cart`/`fixed_product`）の3値目`fixed_product`（商品単位の定額値引き）を
 * 表現できない。`fixed_cart`（カート単位）へ丸めると割引の効き方が変わる金銭的リスクがあるため、
 * `fixed_product`は他の「Wooにはあるが運べない制限」（商品/カテゴリ/メールアドレス制限・
 * maximum_amount）と同じ`has_unsupported_restrictions=true`の扱いにする
 * （`Canonical\CanonicalCoupon`のdocblockが定める三値契約）。読出時点でも
 * `WarningCode::COUPON_RESTRICTIONS_UNSUPPORTED`を`ReadItem`の警告に積み、
 * `indicates_export_blocking()`でpush前に確実にスキップされるようにする。
 */
final class CouponReader implements EntityReader {

	private const PAGE_SIZE = 20;

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$args = [
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			// D15 §10.2「クーポン: 最新10件」＝新しい順（`docs/03-design-decisions.md`
			// 「APIが新しい順ソートを指定できる場合のみ新しい順」）。無料版は`LimitPolicy`が
			// カーソル走査で最初に出会った10件だけを新規pushの対象にするため、'ID'昇順（＝作成日
			// 昇順）のままだと店を長く運営しているほど古い（期限切れの可能性が高い）クーポンだけが
			// 無料枠を占有してしまう。`'date' => 'DESC'`単独だと`post_date`が同一秒（`CouponWriter`
			// による一括作成等）のクーポン間の順序がMySQL実装依存になりページ跨ぎで重複/欠落しうる
			// ため、`ID`を副ソートキーとして明示し決定的にする（R2レビュー指摘）。
			'orderby'        => [
				'date' => 'DESC',
				'ID'   => 'DESC',
			],
			'fields'         => 'ids',
			'posts_per_page' => self::PAGE_SIZE,
			'paged'          => (int) $cursor->get( 'page', 1 ),
			'no_found_rows'  => false,
		];

		if ( null !== $only_local_ids ) {
			if ( [] === $only_local_ids ) {
				return new ReadPage( [], null, 0 );
			}

			$args['post__in']       = $only_local_ids;
			$args['posts_per_page'] = count( $only_local_ids );
			$args['no_found_rows']  = true;
			unset( $args['paged'] );
		}

		$query = new WP_Query( $args );

		$items = array_values(
			array_map(
				fn ( int $coupon_id ): ReadItem => $this->to_read_item( new WC_Coupon( $coupon_id ) ),
				$query->posts
			)
		);

		$page          = (int) ( $args['paged'] ?? 1 );
		$has_next_page = null === $only_local_ids && $page < (int) $query->max_num_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, null !== $only_local_ids ? null : (int) $query->found_posts );
	}

	private function to_read_item( WC_Coupon $coupon ): ReadItem {
		[ $type, $type_unsupported ]  = $this->type( $coupon );
		$has_unsupported_restrictions = $type_unsupported || $this->has_native_restrictions( $coupon );

		$min_amount = $coupon->get_minimum_amount();

		$canonical = new CanonicalCoupon(
			$coupon->get_code(),
			$type,
			$coupon->get_amount(),
			is_numeric( $min_amount ) && (float) $min_amount > 0 ? $min_amount : null,
			$coupon->get_date_expires() instanceof WC_DateTime ? $coupon->get_date_expires()->date( DATE_ATOM ) : null,
			0 !== $coupon->get_usage_limit() ? $coupon->get_usage_limit() : null,
			[ 'name' => '' !== $coupon->get_description() ? $coupon->get_description() : null ],
			$coupon->get_free_shipping(),
			0 !== $coupon->get_usage_limit_per_user() ? $coupon->get_usage_limit_per_user() : null,
			$has_unsupported_restrictions
		);

		$warnings = $has_unsupported_restrictions ? [ WarningCode::COUPON_RESTRICTIONS_UNSUPPORTED ] : [];

		return new ReadItem( $coupon->get_id(), $canonical, $warnings );
	}

	/**
	 * @return array{0:'fixed'|'percent',1:bool} [Canonical型, 忠実に表現できないため制限扱いにするか]
	 */
	private function type( WC_Coupon $coupon ): array {
		return match ( $coupon->get_discount_type() ) {
			'percent' => [ 'percent', false ],
			'fixed_cart' => [ 'fixed', false ],
			// `fixed_product`（商品単位の定額値引き）と、その他の未知のdiscount_type
			// （他プラグインが登録した独自クーポンタイプ等）はカート単位の`fixed`と割引の
			// 効き方が異なるため、無警告で丸めずhas_unsupported_restrictionsへ倒す
			// （クラスdocblock参照）。
			default => [ 'fixed', true ],
		};
	}

	/**
	 * WooがネイティブでもつがCanonicalCouponには運ぶフィールドが無い制限
	 * （`Canonical\CanonicalCoupon`のdocblock参照）。商品/カテゴリ/メールアドレス制限・
	 * 上限金額に加え、「対象商品を1点のみに適用」「セール品を対象外」「他クーポンと併用不可」も
	 * 運べない軸のため同様に扱う（無警告で`false`にすると、これらの制限が働かない
	 * 「実質無制限クーポン」としてASP側に保存されうる金銭的リスクがある）。
	 */
	private function has_native_restrictions( WC_Coupon $coupon ): bool {
		if ( [] !== $coupon->get_product_ids() || [] !== $coupon->get_excluded_product_ids() ) {
			return true;
		}

		if ( [] !== $coupon->get_product_categories() || [] !== $coupon->get_excluded_product_categories() ) {
			return true;
		}

		if ( [] !== $coupon->get_email_restrictions() ) {
			return true;
		}

		if ( true === $coupon->get_exclude_sale_items() || true === $coupon->get_individual_use() ) {
			return true;
		}

		$limit_usage_to_x_items = $coupon->get_limit_usage_to_x_items();

		if ( is_numeric( $limit_usage_to_x_items ) && (int) $limit_usage_to_x_items > 0 ) {
			return true;
		}

		$maximum_amount = $coupon->get_maximum_amount();

		return is_numeric( $maximum_amount ) && (float) $maximum_amount > 0;
	}
}
