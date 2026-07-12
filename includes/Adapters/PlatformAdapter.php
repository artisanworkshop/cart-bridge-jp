<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Canonical\CanonicalTag;

/**
 * 各ASP（colorme/makeshop/base）が実装するインターフェース（`docs/03-design-decisions.md` §2 確定版）。
 *
 * 注: 設計ドキュメントではメソッド名をcamelCaseで表記しているが、本インターフェースは
 * WordPress Coding Standards（snake_case）に合わせて変換している。パラメータ・戻り値・
 * 意味論は設計ドキュメントと同一。
 */
interface PlatformAdapter {

	/**
	 * 'colorme' | 'makeshop' | 'base'
	 */
	public function id(): string;

	public function label(): string;

	public function capabilities(): Capabilities;

	public function test_connection(): ConnectionResult;

	/**
	 * 接続設定スキーマの宣言。UIが動的にフォームを生成する。
	 *
	 * @return array<int,ConnectionField>
	 */
	public function connection_fields(): array;

	public function fetch_products( Cursor $cursor ): Page;

	/**
	 * @return array<int,CanonicalCategory>
	 */
	public function fetch_categories(): array;

	/**
	 * @return array<int,CanonicalTag> colorme: groups
	 */
	public function fetch_tags(): array;

	public function fetch_customers( Cursor $cursor ): Page;

	public function fetch_orders( Cursor $cursor ): Page;

	public function fetch_stocks( Cursor $cursor ): Page;

	public function fetch_coupons( Cursor $cursor ): Page;

	/**
	 * makeshopのみ対応。非対応プラットフォームは UnsupportedOperationException。
	 */
	public function fetch_reviews( Cursor $cursor ): Page;

	/**
	 * 無料版サンプル選定用（D15）。新しい順で最新 $limit 件を返す。
	 *
	 * @return array<int,CanonicalOrder>
	 */
	public function fetch_latest_orders( int $limit ): array;

	/**
	 * 無料版サンプル選定用（D15）。404はnullを返す（例外にしない）。
	 */
	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct;

	/**
	 * 無料版サンプル選定用（D15）。base: UnsupportedOperationException（D12。受注購入者から抽出）。
	 */
	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer;

	/**
	 * capabilityで不可の場合は UnsupportedOperationException。
	 */
	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult;

	public function push_category( CanonicalCategory $category ): PushResult;

	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult;

	public function push_order( CanonicalOrder $order ): PushResult;

	public function push_stock( CanonicalStock $stock ): PushResult;

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult;
}
