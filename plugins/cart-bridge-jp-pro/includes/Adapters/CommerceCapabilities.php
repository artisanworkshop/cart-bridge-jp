<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Adapters;

use CartBridgeJP\Adapters\Capabilities;

/**
 * 接続先の顧客・受注・クーポンの能力（`CommerceAdapter::capabilities()`。R3-6c1 で無料版の `Capabilities` から移した）。
 * 能力（プラン・API の可否）の宣言で、店舗の設定では変えない（`Capabilities` と同じ。`.claude/rules/adapters-colorme.md`）。
 * 実体の種類（`Entities\Commerce\*Type`）が取込み・エクスポートの可否とベータの表示に使う。
 *
 * 名前付き引数で作る（位置を動かしても呼び出しが黙って意味を変えないように）。新しい引数は末尾に既定値つきで足す。
 */
final readonly class CommerceCapabilities {

	/**
	 * @param bool $can_fetch_customers 顧客を一覧で取得できる（BASE は顧客一覧 API が無い。D12）。
	 * @param bool $can_update_customer 顧客を作成・更新できる（エクスポート）。
	 * @param bool $can_create_order    受注を作成できる（エクスポート。ColorMe はプレミアムプランだけ）。
	 * @param bool $has_coupons         クーポンを持つ（取込み）。
	 * @param bool $can_create_coupon   クーポンを作成できる（エクスポート）。
	 * @param bool $order_export_beta   受注のエクスポートがベータ（D24。画面が「Beta」と表示し、既定では選ばない）。
	 */
	public function __construct(
		public bool $can_fetch_customers,
		public bool $can_update_customer,
		public bool $can_create_order,
		public bool $has_coupons,
		public bool $can_create_coupon,
		public bool $order_export_beta = false
	) {}
}
