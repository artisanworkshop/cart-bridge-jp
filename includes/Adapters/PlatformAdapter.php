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
 *
 * 外部（Pro版・サードパーティ）実装は本インターフェースを直接 implements せず
 * {@see AbstractPlatformAdapter} を継承すること（D20）。v1.0.0 公開後はここへの
 * 破壊的変更をしない。新しいメソッドは `AbstractPlatformAdapter` に既定実装を添えて追加する。
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

	/**
	 * `/settings/mappings/{platform}` UIが選択肢を動的に描画するための、ASP側マッピング候補一覧
	 * （D19。`connection_fields()`と同じ「自己記述スキーマをUIが消費する」設計）。
	 * キーは `category`/`payment`/`shipping`/`status`。該当エンティティ・機能を持たない
	 * プラットフォームはそのキーを省略するか空配列を返してよい。
	 *
	 * @return array<string,array<int,array{id:string,name:string}>>
	 */
	public function mapping_candidates(): array;

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
	 * 受注をID指定で1件取得する。404はnullを返す（例外にしない）。県コード修復ツール
	 * （`Woo\Tools\PrefStateRepair`。issue #46）が、インポート済み受注の住所の権威値を
	 * 再取得するために使う。期間指定付きの一覧取得と違い、ID指定の単一取得は日付範囲の
	 * 暗黙の絞り込み（カラーミー: 直近7日。03 §9 #14）の影響を受けない。
	 * 未対応のASPは UnsupportedOperationException。
	 */
	public function fetch_order_by_remote_id( string $remote_id ): ?CanonicalOrder;

	/**
	 * capabilityで不可の場合は UnsupportedOperationException。
	 *
	 * **`push_*()`共通の契約（D21-A）**: `$remote_id`が null（作成）のとき、リモート側の作成が
	 * 確定した（remote_idが分かった）後に起きた例外は、`RateLimitExhaustedException`を含め
	 * どれも素のまま外へ出さず、{@see PartialPushException}（remote_id付き）に包んで投げること。
	 * 素のまま出すとmappingが残らず、次回のexportが同じ実体をもう一度作成して重複する。
	 * 作成そのものが失敗した場合（remote_idが分からない）は従来どおり素の例外でよい。
	 * 契約に従わない実装でも、`Sync\Exporter`は従来どおり動く（重複しうる状態は変わらない）。
	 */
	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult;

	public function push_category( CanonicalCategory $category ): PushResult;

	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult;

	/**
	 * `$remote_id`が非nullの場合、対応ASPが受注の内容更新（明細・決済/配送方法の変更）を
	 * 実サポートしない限り、実装はAPIを呼ばず`PushResult('', PushResult::OPERATION_SKIPPED, [...])`
	 * を返すこと（再作成すると重複した受注ができるため）。
	 */
	public function push_order( CanonicalOrder $order, ?string $remote_id ): PushResult;

	public function push_stock( CanonicalStock $stock ): PushResult;

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult;
}
