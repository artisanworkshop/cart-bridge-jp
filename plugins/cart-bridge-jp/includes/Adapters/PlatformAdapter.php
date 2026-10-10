<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

use CartBridgeJP\Canonical\CanonicalCategory;
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
 * 顧客・受注・クーポンの取得・送信は R3-6c1 で Pro アドオンへ移した（Pro の `CommerceAdapter`。D27）。
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
	 * キーはマッピングの種類のキー（無料版は `category`）。該当エンティティ・機能を持たない
	 * プラットフォームはそのキーを省略するか空配列を返してよい。決済・配送・注文ステータス（Pro アドオン）の候補は、
	 * その種類が自分で返す（`Entities\MappingKind::platform_candidates()`。R3-6c1 でこのメソッドから外した）。
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

	public function fetch_stocks( Cursor $cursor ): Page;

	/**
	 * makeshopのみ対応。非対応プラットフォームは UnsupportedOperationException。
	 */
	public function fetch_reviews( Cursor $cursor ): Page;

	/**
	 * 商品をID指定で1件取得する。404はnullを返す（例外にしない）。送信の結果が不明な実体の確定
	 * （`Woo\Tools\PushIntentResolver`。D21-B）が、リモートに実体があるかを確かめるために使う。
	 */
	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct;

	/**
	 * capabilityで不可の場合は UnsupportedOperationException。
	 *
	 * **`push_*()`共通の契約（D21-A）**: `$remote_id`が null（作成）のとき、リモート側の作成が
	 * 確定した（remote_idが分かった）後に起きた例外は、`RateLimitExhaustedException`を含め
	 * どれも素のまま外へ出さず、{@see PartialPushException}（remote_id付き）に包んで投げること。
	 * 素のまま出すとmappingが残らず、次回のexportが同じ実体をもう一度作成して重複する。
	 * 作成そのものが失敗した場合（remote_idが分からない）は従来どおり素の例外でよい。
	 * 契約に従わない実装でも、`Sync\Exporter`は従来どおり動く（重複しうる状態は変わらない）。
	 *
	 * **画像（D24）**: `Capabilities::$can_push_images`が true のアダプタは、画像を
	 * `Support\ExportOptions::push_images_enabled( $this->id() )`が true のときだけ送ること
	 * （既定オフ。オフのときは送らず、商品に画像があれば`WarningCode::PRODUCT_IMAGES_NOT_PUSHED`を積む）。
	 * 外部のリモート側画像を上書きしうる操作のため、店舗が明示的に選んだときだけ動かす。
	 */
	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult;

	public function push_category( CanonicalCategory $category ): PushResult;

	public function push_stock( CanonicalStock $stock ): PushResult;
}
