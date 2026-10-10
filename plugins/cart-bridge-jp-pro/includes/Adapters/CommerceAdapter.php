<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Adapters;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PartialPushException;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Pro\Canonical\CanonicalCoupon;
use CartBridgeJP\Pro\Canonical\CanonicalCustomer;
use CartBridgeJP\Pro\Canonical\CanonicalOrder;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- 既定実装は引数を使わない（継承したアダプタが使う）。

/**
 * 接続先 1 つの顧客・受注・クーポンの取得・送信（R3-6c1 で無料版の `PlatformAdapter` から移した）。プラットフォームごとに 1 つ実装し
 * （ColorMe は `ColorMe\ColorMeCommerceAdapter`）、`CommerceAdapters` に登録する。実体の種類（`Entities\Commerce\*Type`）は
 * `CommerceAdapters::get()` で引くだけで、プラットフォームで分岐しない（原則 1）。
 *
 * 各メソッドの契約は移す前の `PlatformAdapter` と同じ（push の D21-A、ID 指定取得の 404 は null、受注の更新は送らずにスキップ…）。
 * 既定実装は `UnsupportedOperationException`（D20 と同じ書き方。null・空配列など正常な結果と区別できない値を既定にしない）。
 * 候補（`*_candidates()`）は画面の選択肢なので、既定は空（候補なし）。
 */
abstract class CommerceAdapter {

	/**
	 * 無料版のアダプタの `PlatformAdapter::id()` と同じ値（`CommerceAdapters` が照合する）。
	 */
	abstract public function id(): string;

	abstract public function capabilities(): CommerceCapabilities;

	/**
	 * 顧客の全量走査。`CommerceCapabilities::$can_fetch_customers` が偽の接続先は UnsupportedOperationException。
	 */
	public function fetch_customers( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function fetch_orders( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function fetch_coupons( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	/**
	 * 顧客を ID で 1 件取得する。404 は null（例外にしない）。送信の結果が不明な実体の確定（`Woo\Tools\PushIntentResolver`。D21-B）が使う。
	 * base: UnsupportedOperationException（D12。受注購入者から抽出）。
	 */
	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	/**
	 * 受注を ID で 1 件取得する。404 は null。期間指定付きの一覧取得と違い、ID 指定の単一取得は日付範囲の暗黙の絞り込み
	 * （カラーミー: 直近 7 日。03 §9 #14）の影響を受けない。HTTP の本数は `Entities\EntityType::fetch_by_remote_id()` の上限に収める
	 * （`Support\PlatformLock::TTL_LONG` の見積もり）。
	 */
	public function fetch_order_by_remote_id( string $remote_id ): ?CanonicalOrder {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	/**
	 * `$remote_id` が null なら作成、そうでなければ更新。作成が確定した後の例外は `PartialPushException`（remote_id 付き）に包む（D21-A。
	 * `PlatformAdapter::push_product()` の docblock）。
	 */
	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	/**
	 * `$remote_id` が非 null の場合、接続先が受注の内容更新（明細・決済/配送方法の変更）を実サポートしない限り、API を呼ばず
	 * `PushResult( '', PushResult::OPERATION_SKIPPED, [...] )` を返すこと（再作成すると重複した受注ができるため）。
	 */
	public function push_order( CanonicalOrder $order, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	/**
	 * 決済方法のマッピングの ASP 側の候補（`Entities\Commerce\PaymentMappingKind::platform_candidates()`）。
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public function payment_candidates(): array {
		return [];
	}

	/**
	 * 配送方法のマッピングの ASP 側の候補。
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public function shipping_candidates(): array {
		return [];
	}

	/**
	 * 注文ステータスのマッピングの ASP 側の候補（取込みの変換が返しうる正規化後の値）。
	 *
	 * @return array<int,array{id:string,name:string}>
	 */
	public function status_candidates(): array {
		return [];
	}
}
