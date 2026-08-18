<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\Support\TaxClass;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Product_Variation;

/**
 * `CanonicalOrder` の明細・送料・手数料ラインを組み立てる（03 §5 D10）。
 * 合計はASP側の値をそのまま使い、Wooに再計算させない。
 */
final class OrderItemBuilder {

	public function __construct( private readonly ProductResolver $resolver ) {}

	/**
	 * @param array<string,mixed> $line_item
	 * @return array{item:WC_Order_Item_Product,warnings:array<int,string>}
	 */
	public function build_line_item( array $line_item ): array {
		$warnings          = [];
		$sku               = Value::string( $line_item['sku'] ?? null );
		$remote_product_id = Value::string( $line_item['remote_product_id'] ?? null );
		$product           = $this->resolver->resolve_by_sku_or_remote_id( $sku, $remote_product_id );

		$item = new WC_Order_Item_Product();
		// 注文時点の商品名を使う（`set_product()`は現在の商品名・価格で上書きしてしまうため使わない）。
		$item->set_name( Value::string( $line_item['name'] ?? null ) ?? __( 'Unknown product', 'cart-bridge-jp' ) );

		if ( null !== $product ) {
			if ( $product instanceof WC_Product_Variation ) {
				$item->set_product_id( $product->get_parent_id() );
				$item->set_variation_id( $product->get_id() );
			} else {
				$item->set_product_id( $product->get_id() );
			}
		} else {
			// 商品リンクを張らないカスタム行として作成する（D10 #3）。削除済み商品・突合不能な
			// 明細でも注文履歴の欠落を防ぐ。
			$warnings[] = WarningCode::with_detail( WarningCode::ORDER_LINE_PRODUCT_UNRESOLVED, $remote_product_id ?? '' );
			$item->add_meta_data( '_cbjp_remote_product_id', $remote_product_id ?? '', true );

			$image_url = Value::string( $line_item['image_url'] ?? null );

			if ( null !== $image_url ) {
				$item->add_meta_data( '_cbjp_image_url', $image_url, true );
			}
		}

		$quantity = Value::int( $line_item['quantity'] ?? null );

		if ( null === $quantity || $quantity <= 0 ) {
			// 数量が欠損・非数値・0以下の場合、1個として捏造すると実際の購入数と食い違う出荷指示に
			// なりうる（CLAUDE.md参照）。ColorMeの`OrderTransformer`は同じ理由で`product_num`
			// 欠損時に注文全体を弾いているが、Woo層は他ASPアダプタの出力も信頼境界として
			// 扱うため、ここでも黙って1個扱いにはしない。ただし明細自体を消すと注文履歴・
			// 返金記録が欠落する（D10 #3）ため、行は残しつつ数量が不確かである旨を警告する。
			// 0以下の値をそのまま`set_quantity()`に渡すと負/ゼロ数量の明細行になり、
			// 注文の集計・返金計算が破綻しうるため、欠損時と同じフェイルクローズ扱いにする。
			$quantity   = 1;
			$warnings[] = WarningCode::with_detail( WarningCode::ORDER_LINE_QUANTITY_INVALID, $remote_product_id ?? '' );
		}

		$item->set_quantity( $quantity );

		[ $excl_tax, $tax, $tax_warning ] = $this->split_line_amount( $line_item, $quantity );
		$item->set_subtotal( $excl_tax );
		$item->set_total( $excl_tax );
		// `set_subtotal_tax()`/`set_total_tax()`単体では次回読込時に値が失われる: WooCommerceは
		// アイテムの再構築時に `_line_tax_data`（`taxes`プロパティ）から`total_tax`/`subtotal_tax`を
		// 再導出する（`set_taxes()`が内部でこの2つを上書きする）ため、`taxes`配列自体を明示的に
		// 設定しないと保存直後は正しくても再読込後に0へ戻ってしまう。実税率行（`WC_Order_Item_Tax`）は
		// 作らない方針（03 §5「税の扱い」参照）のため、rate_id 0 のプレースホルダーキーに集約する。
		$item->set_taxes(
			[
				'total'    => [ 0 => $tax ],
				'subtotal' => [ 0 => $tax ],
			]
		);
		// ProductWriterの商品tax_classと同じ検証・フェイルクローズ規則を共有ヘルパーで適用する
		// （未設定のtax_classをそのまま渡すとWooCommerceが例外を投げるため）。
		$requested_tax_class                = true === Value::bool( $line_item['tax_reduced'] ?? null ) ? 'reduced-rate' : '';
		[ $tax_class, $tax_class_warnings ] = TaxClass::resolve( $requested_tax_class );
		$warnings                           = array_merge( $warnings, $tax_class_warnings );

		$item->set_tax_class( $tax_class );

		if ( null !== $tax_warning ) {
			$warnings[] = $tax_warning;
		}

		return [
			'item'     => $item,
			'warnings' => $warnings,
		];
	}

	/**
	 * 明細の税込単価(`price`)・税抜単価(`unit_price_excl_tax`)から線合計（税抜/税額）を導出する。
	 * `subtotal`（税込線合計）が優先情報源。税抜単価が欠損している場合は税抜/税込を分離できないため
	 * 税込金額をそのまま税抜側に入れ税額0とする（03 §5「税の扱い」。合計金額自体は崩さない）。
	 *
	 * @param array<string,mixed> $line_item
	 * @return array{0:string,1:string,2:?string}
	 */
	private function split_line_amount( array $line_item, int $quantity ): array {
		$unit_price_incl = Value::string( $line_item['price'] ?? null ) ?? '0';
		$line_total_incl = Value::string( $line_item['subtotal'] ?? null ) ?? wc_format_decimal( (float) $unit_price_incl * $quantity );
		$unit_price_excl = Value::string( $line_item['unit_price_excl_tax'] ?? null );

		if ( null === $unit_price_excl ) {
			return [ $line_total_incl, '0', WarningCode::ORDER_TAX_SPLIT_UNAVAILABLE ];
		}

		$line_total_excl = wc_format_decimal( (float) $unit_price_excl * $quantity );
		$tax_amount      = (float) $line_total_incl - (float) $line_total_excl;

		if ( $tax_amount < 0.0 ) {
			// `line_total_incl`（subtotal優先）と`line_total_excl`（unit_price_excl_tax×数量）は
			// ASP側の別フィールドから独立に導出しているため、行割引・端数処理の都合で整合しない
			// ことがある。負の税額をそのまま`set_taxes()`へ書き込むと注文の税合計・検証レポートが
			// 破綻するため、税抜/税込を分離できないケースと同様に税込金額を税抜側へ丸めて税額0に
			// フェイルクローズし、警告で可視化する（合計金額自体は崩さない）。
			return [ $line_total_incl, '0', WarningCode::ORDER_LINE_TAX_INCONSISTENT ];
		}

		return [ $line_total_excl, wc_format_decimal( $tax_amount ), null ];
	}

	/**
	 * @param array<string,mixed> $shipping
	 * @return array{item:WC_Order_Item_Shipping}
	 */
	public function build_shipping_item( array $shipping, ?string $mapped_method_id, ?string $mapped_title ): array {
		$method_name = Value::string( $shipping['method_name'] ?? null );
		$title       = $mapped_title ?? $method_name ?? __( 'Shipping', 'cart-bridge-jp' );

		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( $title );
		// `method_id`にはWooの配送方法ID（マッピング済みIDのみ）を設定する。未マッピングの
		// ASP側生IDをそのまま入れると、Woo標準の配送方法として実在しないIDが記録され、
		// 拡張機能等の配送方法判定処理が誤動作しうる。
		$item->set_method_id( $mapped_method_id ?? '' );
		$item->set_total( Value::string( $shipping['fee'] ?? null ) ?? '0' );

		return [ 'item' => $item ];
	}

	/**
	 * 決済手数料・ギフト包装料（熨斗/カード/ラッピングの合算）をFee行として組み立てる。
	 * 0円の項目は行を作らない。
	 *
	 * @param array<string,mixed> $payment
	 * @param array<string,mixed> $totals
	 * @return array<int,WC_Order_Item_Fee>
	 */
	public function build_fee_items( array $payment, array $totals ): array {
		$items = [];

		$payment_fee = Value::string( $payment['fee'] ?? null ) ?? '0';

		if ( 0.0 !== (float) $payment_fee ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Payment fee', 'cart-bridge-jp' ) );
			$fee->set_amount( $payment_fee );
			$fee->set_total( $payment_fee );
			$fee->set_tax_status( 'none' );
			$items[] = $fee;
		}

		$gift_charges = Value::string( $totals['gift_charges'] ?? null ) ?? '0';

		if ( 0.0 !== (float) $gift_charges ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( __( 'Gift wrapping', 'cart-bridge-jp' ) );
			$fee->set_amount( $gift_charges );
			$fee->set_total( $gift_charges );
			$fee->set_tax_status( 'none' );
			$items[] = $fee;
		}

		return $items;
	}
}
