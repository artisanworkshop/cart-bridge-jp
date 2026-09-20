<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe;

use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\ColorMe\Transform\Cast;
use CartBridgeJP\Adapters\ColorMe\Transform\CategoryTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\CouponTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\CustomerTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\OrderTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\ProductTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\StockTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\TagTransformer;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Adapters\ConnectionResult;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Canonical\CanonicalTag;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;

/**
 * カラーミーショップアダプタ（`01-plan-colorme.md`）。
 *
 * fetch系メソッドは`docs/03-design-decisions.md` §10.2 の無料版サンプル選定〜Pro版の全量走査
 * 双方から呼ばれる。push系はE2-3（エクスポート）で実装する（それまでは
 * `UnsupportedOperationException`）。`mapping_candidates()`はE2-1で実装済み。
 */
final class ColorMeAdapter implements PlatformAdapter {

	public const ID = 'colorme';

	private const RATE_LIMIT_PER_MINUTE = 100;

	/**
	 * 商品・顧客・在庫の一覧APIページサイズ。`products.json`/`stocks.json` の上限（50）に合わせる
	 * （`customers.json`/`sales.json` は上限100だが、全エンドポイント共通の値に揃える）。
	 */
	private const PAGE_SIZE = 50;

	/**
	 * `GET /sales.json` の全量走査・`fetchLatestOrders` の探索終端に使う日付
	 * （カラーミーのサービス開始より確実に前）。
	 */
	private const HISTORY_FLOOR = '2000-01-01';

	/**
	 * `fetchLatestOrders` の初回探索窓（日数）。`after`/`before` 省略時のAPIデフォルトと同じ
	 * 直近7日間から開始し、不足していれば4倍ずつ過去へ広げる（03 §9 #14 / §10.2 #1）。
	 */
	private const LATEST_ORDERS_INITIAL_WINDOW_DAYS = 7;

	/**
	 * `GET /sales.json` の `limit` 上限（swagger）。`fetchLatestOrders` が要求件数を広げる際の上限に使う。
	 */
	private const SALES_MAX_REQUEST_LIMIT = 100;

	/**
	 * `payments.json`/`deliveries.json` から組み立てた名称マップを持つ`OrderTransformer`。
	 * `AdapterRegistry::get()`はプラットフォーム単位でアダプタインスタンスを静的キャッシュするため、
	 * このキャッシュの実際の寿命は「同一PHPプロセス内で処理された全ジョブアクション」（Action
	 * Schedulerが1リクエストで複数アクションをまとめて実行する場合はページ横断で再利用される）。
	 * アクション毎に新規プロセスが割り当てられる実行環境ではプロセス毎に再取得される。
	 */
	private ?OrderTransformer $order_transformer = null;

	/**
	 * `shop.json`の税設定を注入した`ProductTransformer`。`$order_transformer`と同じ理由で
	 * インスタンス単位にキャッシュする（同一プロセス内で処理される全ページで`shop.json`を
	 * 1回だけ叩けば足りる）。
	 */
	private ?ProductTransformer $product_transformer = null;

	/**
	 * `push_order()`の明細価格解決にのみ使う店舗税区分（`shop.json`の`tax_type`）。import方向の
	 * `OrderTransformer::transform()`はこのデータを使わないため、`$order_transformer`（全fetch系
	 * メソッドが経由する共有インスタンス）には持たせず、push時にのみ独立して遅延取得・
	 * インスタンス単位でキャッシュする（`$order_transformer`/`$product_transformer`と同じ理由）。
	 */
	private ?string $order_tax_type = null;

	private bool $order_tax_type_loaded = false;

	public function __construct(
		private readonly TokenStore $token_store = new TokenStore( self::ID ),
		private readonly Logger $logger = new Logger()
	) {}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Color Me Shop', 'cart-bridge-jp' );
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			false, // can_create_category: カテゴリはColorMe側で作成不可（01-plan §5）。
			$this->is_premium_plan(), // can_create_order: `POST /v1/sales` はプレミアムプラン契約のショップのみ利用可（swagger.json）。
			true,  // can_fetch_customers
			true,  // can_update_customer
			$this->can_push_images(),
			false, // can_create_coupon: クーポンは読取のみ
			true,  // has_coupons
			true,  // has_tags: groupsをタグとして扱う
			false, // has_reviews
			true,  // has_variants
			self::RATE_LIMIT_PER_MINUTE
		);
	}

	/**
	 * 要検証#1（03 §9）確定済み: `POST /v1/products/{id}/images` はプレミアムプラン契約のショップのみ利用可。
	 * `test_connection()` が `shop.json` から取得・キャッシュした契約プランを見て動的に判定する。
	 * 未接続・未キャッシュの場合は安全側（false）に倒す。
	 */
	private function can_push_images(): bool {
		return $this->is_premium_plan();
	}

	/**
	 * `POST /v1/sales`（受注作成）はプレミアムプラン契約のショップのみ利用可
	 * （`tests/fixtures/colorme/swagger.json` createSale説明）。
	 * `test_connection()` が `shop.json` から取得・キャッシュした契約プランを見て動的に判定する。
	 * 未接続・未キャッシュの場合は安全側（false）に倒す。
	 */
	private function is_premium_plan(): bool {
		$extras = $this->token_store->get()['extras'] ?? [];

		return is_array( $extras ) && 'premium' === ( $extras['contract_plan'] ?? null );
	}

	public function connection_fields(): array {
		return [
			new ConnectionField(
				'client_id',
				__( 'Client ID', 'cart-bridge-jp' ),
				'text',
				true,
				__( 'From your ColorMe Shop developer app registration.', 'cart-bridge-jp' )
			),
			new ConnectionField(
				'client_secret',
				__( 'Client Secret', 'cart-bridge-jp' ),
				'password',
				true,
				null
			),
			new ConnectionField(
				'authorize',
				__( 'Connect to ColorMe Shop', 'cart-bridge-jp' ),
				'oauth_button',
				false,
				null
			),
		];
	}

	public function test_connection(): ConnectionResult {
		$payload      = $this->token_store->get();
		$access_token = (string) ( $payload['access_token'] ?? '' );

		if ( '' === $access_token ) {
			return ConnectionResult::failure( __( 'Not connected yet.', 'cart-bridge-jp' ) );
		}

		try {
			$shop = ColorMeClient::for_access_token( $access_token )->get( 'shop.json' )['shop'] ?? [];
		} catch ( ApiException $exception ) {
			return ConnectionResult::failure( $exception->getMessage() );
		}

		// HTTP 200でも中身が想定形でない（プロキシ応答等）場合は成功扱いにしない。
		// `id` はGET /shop.json のShopスキーマ必須フィールド。
		if ( ! is_array( $shop ) || ! isset( $shop['id'] ) ) {
			return ConnectionResult::failure(
				__( 'The platform returned an unexpected response. Please try again.', 'cart-bridge-jp' )
			);
		}

		// 成功した接続テストごとにcontract_planキャッシュを最新レスポンスへ同期する。
		// スキーマ上contract_planは必須ではないため、レスポンスに含まれない場合は
		// 過去の値（例: premium）を破棄し、capabilities()が古い契約情報を
		// 広告し続けないようにする。
		//
		// /shop.json の応答待ちの間に、別リクエストのOAuthコールバックが新しい
		// トークンを保存している可能性がある。「読み取り→比較→書き込み」を
		// この場で組み立てると比較後の割り込みに勝てないため、TokenStore側の
		// CAS（保存中のトークンがテストしたトークンと一致する場合のみ原子的に
		// extrasを更新）に委ね、並行する再認可をここで巻き戻さないようにする。
		// CASがfalseを返した場合はテスト対象のトークンが既に古い（別ショップの
		// 可能性もある）ため、成功扱いにせず再テストを促す。
		$still_current = $this->token_store->update_extras_if_token_matches(
			$access_token,
			static function ( array $extras ) use ( $shop ): array {
				unset( $extras['contract_plan'] );

				if ( isset( $shop['contract_plan'] ) && is_string( $shop['contract_plan'] ) ) {
					$extras['contract_plan'] = $shop['contract_plan'];
				}

				return $extras;
			}
		);

		if ( ! $still_current ) {
			return ConnectionResult::failure(
				__( 'The connection changed while testing. Please try again.', 'cart-bridge-jp' )
			);
		}

		$shop_name = is_string( $shop['title'] ?? null ) && '' !== $shop['title']
			? $shop['title']
			: null;

		return ConnectionResult::success( $shop_name );
	}

	public function fetch_products( Cursor $cursor ): Page {
		$offset      = (int) $cursor->get( 'offset', 0 );
		$body        = $this->client()->get(
			'products.json',
			[
				'limit'  => self::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw         = $this->list_from( $body, 'products' );
		$transformer = $this->product_transformer();
		$items       = $this->transform_rows( $raw, static fn ( array $item ): CanonicalProduct => $transformer->transform( $item ), 'product' );
		$total       = $this->total_from_meta( $body );

		// customer/order/stockと同じ理由（`meta.total`は生の行数であり、`list_from()`の非配列行
		// フィルタや`ProductTransformer::transform()`の変換失敗（例: `variants`欠損によるスキーマ
		// 崩壊）で`items`件数がそれと1:1対応するとは限らない）。ページング終端の判定にだけ使い、
		// 進捗率の分母として`Page`側には報告しない。
		return new Page( $items, $this->next_cursor( $offset, $this->raw_row_count( $body, 'products' ), $total ), null );
	}

	/**
	 * `/settings/mappings/colorme` UI向けのASP側候補一覧（D19）。カテゴリ・決済・配送は
	 * 既存の取得経路を再利用し、注文ステータスは`OrderTransformer::status()`が返しうる
	 * 4つのcanonical値の固定リスト（API呼び出し不要）。
	 *
	 * @return array<string,array<int,array{id:string,name:string}>>
	 */
	public function mapping_candidates(): array {
		return [
			'category' => self::category_candidates( $this->fetch_categories() ),
			'payment'  => self::id_name_candidates( $this->id_name_map( 'payments.json', 'payments' ) ),
			'shipping' => self::id_name_candidates( $this->id_name_map( 'deliveries.json', 'deliveries' ) ),
			'status'   => self::status_candidates(),
		];
	}

	/**
	 * @param array<int,CanonicalCategory> $categories
	 * @return array<int,array{id:string,name:string}>
	 */
	private static function category_candidates( array $categories ): array {
		return array_map(
			static fn ( CanonicalCategory $category ): array => [
				'id'   => $category->id,
				'name' => $category->name,
			],
			$categories
		);
	}

	/**
	 * @param array<int,string> $map id => name（`id_name_map()`の戻り値）
	 * @return array<int,array{id:string,name:string}>
	 */
	private static function id_name_candidates( array $map ): array {
		$candidates = [];

		foreach ( $map as $id => $name ) {
			$candidates[] = [
				'id'   => (string) $id,
				'name' => $name,
			];
		}

		return $candidates;
	}

	/**
	 * @return array<int,array{id:string,name:string}>
	 */
	private static function status_candidates(): array {
		return [
			[
				'id'   => 'pending',
				'name' => __( 'Unpaid', 'cart-bridge-jp' ),
			],
			[
				'id'   => 'processing',
				'name' => __( 'Paid (not shipped)', 'cart-bridge-jp' ),
			],
			[
				'id'   => 'completed',
				'name' => __( 'Shipped', 'cart-bridge-jp' ),
			],
			[
				'id'   => 'cancelled',
				'name' => __( 'Cancelled', 'cart-bridge-jp' ),
			],
		];
	}

	/**
	 * @return array<int,CanonicalCategory>
	 */
	public function fetch_categories(): array {
		$body        = $this->client()->get( 'categories.json' );
		$raw         = $this->list_from( $body, 'categories' );
		$transformer = new CategoryTransformer();

		return $this->transform_rows_flat( $raw, static fn ( array $item ): array => $transformer->transform( $item ), 'category' );
	}

	/**
	 * @return array<int,CanonicalTag>
	 */
	public function fetch_tags(): array {
		$body        = $this->client()->get( 'groups.json' );
		$raw         = $this->list_from( $body, 'groups' );
		$transformer = new TagTransformer();

		return $this->transform_rows( $raw, static fn ( array $item ): ?CanonicalTag => $transformer->transform( $item ), 'tag' );
	}

	public function fetch_customers( Cursor $cursor ): Page {
		$offset      = (int) $cursor->get( 'offset', 0 );
		$body        = $this->client()->get(
			'customers.json',
			[
				'limit'  => self::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw         = $this->list_from( $body, 'customers' );
		$transformer = new CustomerTransformer();
		$items       = $this->transform_rows( $raw, static fn ( array $item ): ?CanonicalCustomer => $transformer->transform( $item ), 'customer' );
		$row_total   = $this->total_from_meta( $body );

		// `meta.total`は生レスポンスの顧客件数であり、`CustomerTransformer`が非会員・email欠損の
		// 行をnullで除外した後の`items`件数とは一致しない（`fetch_stocks()`のバリエーション展開と
		// 同種の乖離）。ページング終端の判定にだけ使い、進捗率の分母として`Page`側には報告しない。
		return new Page( $items, $this->next_cursor( $offset, $this->raw_row_count( $body, 'customers' ), $row_total ), null );
	}

	/**
	 * 全量走査（Pro版・dry-run用）。`after`未指定だと直近7日間しか検索されないため
	 * （03 §9 #14）、`HISTORY_FLOOR`を明示して全履歴を対象にする。
	 */
	public function fetch_orders( Cursor $cursor ): Page {
		$offset    = (int) $cursor->get( 'offset', 0 );
		$body      = $this->client()->get(
			'sales.json',
			[
				'after'  => self::HISTORY_FLOOR,
				'limit'  => self::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw       = $this->list_from( $body, 'sales' );
		$items     = $this->transform_rows( $raw, fn ( array $item ): CanonicalOrder => $this->order_transformer()->transform( $item ), 'order' );
		$row_total = $this->total_from_meta( $body );

		// customer同様、`OrderTransformer`が変換失敗行（id/make_date/total_price欠損）を除外した
		// 後の`items`件数は`meta.total`（生の受注件数）と一致しうるとは限らない。
		return new Page( $items, $this->next_cursor( $offset, $this->raw_row_count( $body, 'sales' ), $row_total ), null );
	}

	/**
	 * §10.2 #4: 無料版の在庫取込はサンプル商品のID指定取得結果から導出するため、この全量走査は
	 * dry-run・Pro版のみで使われる。`GET /stocks.json` はバリエーションIDを返さず
	 * `CanonicalStock::remote_id()` が衝突するため使わない（`StockTransformer` docblock参照）。
	 */
	public function fetch_stocks( Cursor $cursor ): Page {
		$offset        = (int) $cursor->get( 'offset', 0 );
		$body          = $this->client()->get(
			'products.json',
			[
				'limit'  => self::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw           = $this->list_from( $body, 'products' );
		$transformer   = new StockTransformer();
		$items         = $this->transform_rows_flat( $raw, static fn ( array $item ): array => $transformer->transform( $item ), 'stock' );
		$product_total = $this->total_from_meta( $body );

		// `Page::$total`は進捗率表示用の最終processed件数の見込み（`items`の累積件数と対になる）。
		// `$items`はバリエーション単位に展開済み（1商品→複数件）で商品件数と一致しないため、
		// `meta.total`（商品件数）をそのまま`Page`側の`total`として報告すると進捗が100%を
		// 超えて表示されてしまう。ページング終端の判定にだけ商品件数ベースの値を使い、
		// `Page`には報告しない。
		return new Page( $items, $this->next_cursor( $offset, $this->raw_row_count( $body, 'products' ), $product_total ), null );
	}

	/**
	 * `GET /shop_coupons.json` にページングパラメータが無い（swagger）ため常に1ページで完結する。
	 */
	public function fetch_coupons( Cursor $cursor ): Page {
		$body        = $this->client()->get( 'shop_coupons.json' );
		$raw         = $this->list_from( $body, 'shop_coupons' );
		$transformer = new CouponTransformer();
		$items       = $this->transform_rows( $raw, static fn ( array $item ): ?CanonicalCoupon => $transformer->transform( $item ), 'coupon' );

		return new Page( $items, null, count( $items ) );
	}

	public function fetch_reviews( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	/**
	 * 無料版サンプル選定用（D15）。`GET /sales.json` は`after`/`before`省略時に直近7日間しか
	 * 検索しない（03 §9 #14）ため、不足していれば探索窓を4倍ずつ過去へ広げて再取得する。
	 * 各リクエストは前回より広い窓での取得（常に現在時刻を`before`側の起点とする上位集合）に
	 * なるため、レスポンスのマージは不要で最後の取得結果をそのまま使う。
	 *
	 * 探索窓を広げるだけでは、APIが新しい順に返す上位`$limit`件の中に変換失敗行（id/make_date/
	 * total_price欠損）が永続的に含まれるケース（その行がどれだけ過去へ遡っても同じ上位集合の
	 * 一部であり続ける）を救えない。不足件数の分だけ要求`limit`自体も広げ、より多くの候補の中から
	 * 有効な受注を拾えるようにする（API上限=100まで）。
	 *
	 * @return array<int,CanonicalOrder>
	 */
	public function fetch_latest_orders( int $limit ): array {
		$request_limit = $limit;
		$orders        = $this->transform_rows( $this->fetch_sales_raw( [ 'limit' => $request_limit ] ), fn ( array $item ): CanonicalOrder => $this->order_transformer()->transform( $item ), 'order' );
		$orders_count  = count( $orders );
		$window_days   = self::LATEST_ORDERS_INITIAL_WINDOW_DAYS;

		// 取得件数ではなく変換に成功した件数で判定する。行欠損（id/make_date/total_price欠損）で
		// `transform_rows()`が一部の行を落とした場合、取得件数だけを見ていると`$limit`件揃った
		// ように誤認して探索を打ち切ってしまい、より過去に遡れば集まったはずの有効な受注を
		// 取りこぼす。
		while ( $orders_count < $limit ) {
			$window_days  *= 4;
			$after         = $this->history_floor_or_days_ago( $window_days );
			$request_limit = min( self::SALES_MAX_REQUEST_LIMIT, $request_limit + ( $limit - $orders_count ) );
			$orders        = $this->transform_rows(
				$this->fetch_sales_raw(
					[
						'limit' => $request_limit,
						'after' => $after,
					]
				),
				fn ( array $item ): CanonicalOrder => $this->order_transformer()->transform( $item ),
				'order'
			);
			$orders_count  = count( $orders );

			if ( self::HISTORY_FLOOR === $after ) {
				break;
			}
		}

		usort( $orders, static fn ( CanonicalOrder $a, CanonicalOrder $b ): int => $b->placed_at <=> $a->placed_at );

		return array_slice( $orders, 0, $limit );
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		$product = $this->fetch_single_by_remote_id( 'products/' . rawurlencode( $remote_id ) . '.json', 'product' );

		if ( null === $product ) {
			return null;
		}

		// `product_transformer()`（初回呼び出し時に`shop.json`を叩く）をtry節の外で解決する。
		// 中に置くと、shop.json取得失敗（認証切れ等の基盤障害）がこの商品「1件」の変換失敗と
		// 誤って混同され、ジョブ全体を失敗させリトライに委ねるべき障害が静かに握り潰されてしまう
		// （CLAUDE.md「境界データはフェイルクローズで検証」の趣旨に反する静かな部分移行を招く）。
		$transformer = $this->product_transformer();

		try {
			return $transformer->transform( $product );
		} catch ( Throwable $exception ) {
			$this->log_transform_failure( 'product', $product, $exception );

			return null;
		}
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		$customer = $this->fetch_single_by_remote_id( 'customers/' . rawurlencode( $remote_id ) . '.json', 'customer' );

		if ( null === $customer ) {
			return null;
		}

		try {
			return ( new CustomerTransformer() )->transform( $customer );
		} catch ( Throwable $exception ) {
			$this->log_transform_failure( 'customer', $customer, $exception );

			return null;
		}
	}

	/**
	 * `GET /sales/{id}.json`（単一取得）。一覧の`GET /sales.json`と違い`after`/`before`による
	 * 直近7日の暗黙の絞り込みを受けない（03 §9 #14）ため、古い受注でも取得できる。
	 */
	public function fetch_order_by_remote_id( string $remote_id ): ?CanonicalOrder {
		$sale = $this->fetch_single_by_remote_id( 'sales/' . rawurlencode( $remote_id ) . '.json', 'sale' );

		if ( null === $sale ) {
			return null;
		}

		// `order_transformer()`（初回呼び出し時に`payments.json`/`deliveries.json`を叩く）をtry節の
		// 外で解決する。中に置くと認証切れ等の基盤障害がこの受注「1件」の変換失敗と混同され、
		// 呼び出し側（ジョブ・ツール）が拾うべき障害が静かに握り潰される
		// （`fetch_product_by_remote_id()`と同じ理由）。
		$transformer = $this->order_transformer();

		try {
			return $transformer->transform( $sale );
		} catch ( Throwable $exception ) {
			$this->log_transform_failure( 'order', $sale, $exception );

			return null;
		}
	}

	/**
	 * swagger実測（`docs/reviews/feat/e2-3-push-product/`計画参照）: `POST /products`は
	 * 13項目（category_id_small/group_ids/stocks/variants/weight/画像を含まない）のみ受け付け、
	 * `PUT /products/{id}`は加えて`category_id_small`/`group_ids`/`stocks`（simple商品のみ）を
	 * 受け付ける。バリエーション作成に専用POSTは無く、`POST /options`（軸追加）/
	 * `POST /options/{id}/values`（値追加）で自動生成されたものを`PUT /variants/{id}`で
	 * 個別に価格/型番/在庫設定する多段階リクエスト列になる。
	 *
	 * 商品本体（POST/PUT products）の失敗のみ例外を投げ`Sync\Exporter`の汎用catchに委ねる。
	 * それ以降のサブリクエスト（追いPUT/バリエーション/画像）の失敗は商品全体を失敗させず、
	 * `WarningCode`（`indicates_unresolved_reference()`対象）を積んで部分完了として返す
	 * （`docs/03-design-decisions.md` §10.2「E2-3への申し送り」の部分完了契約。次回exportで
	 * checksumがキャッシュされないため自動的に再試行される）。
	 */
	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult {
		$transformer = $this->product_transformer();
		$warnings    = [];

		if ( null === $remote_id ) {
			$body      = $this->client()->post( 'products.json', [ 'product' => $transformer->to_create_payload( $product ) ] );
			$operation = PushResult::OPERATION_CREATED;
		} else {
			$body      = $this->client()->put( "products/{$remote_id}.json", [ 'product' => $transformer->to_update_payload( $product ) ] );
			$operation = PushResult::OPERATION_UPDATED;
		}

		$product_remote_id = Cast::to_string_or_null( $body['product']['id'] ?? null ) ?? $remote_id;

		if ( null === $product_remote_id ) {
			// 200/201応答でも商品IDが取得できない場合はスキーマ崩壊とみなし、
			// `list_from()`等と同じ理由でジョブ全体をリトライ可能な失敗にする
			// （remote_idが無いまま「成功」を返すとmappingsに書き込めず、次回exportが
			// 常に新規作成扱いになり重複が発生し続ける）。
			throw new RuntimeException( 'ColorMe product push response is missing the product id.' );
		}

		if ( ! isset( $body['product']['id'] ) ) {
			$this->logger->warning( 'ColorMe product push response was missing the product id; falling back to the known remote_id.', [ 'remote_id' => $product_remote_id ] );
		}

		$create_payload         = $transformer->to_create_payload( $product );
		$needs_hidden_safeguard = $transformer->requires_hidden_safeguard( $product, $create_payload );

		if ( $needs_hidden_safeguard ) {
			// 価格を一切換算できなかった、または`tax_class`が既知の値以外
			// （`ProductTransformer::requires_hidden_safeguard()`のdocblock参照）。
			// `to_create_payload()`が既にdisplay_stateをhiddenへ強制しているため商品自体は
			// 非公開で作成/更新済みだが、解決後の再exportで正しい状態へ戻すためchecksumを
			// キャッシュさせない。
			$warnings[] = WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE;
		}

		if ( null === $remote_id ) {
			// 新規作成はPOSTが受け付けない項目（category_id_small/group_ids/stocks）を
			// 反映するための追いPUTを行う。この追いPUTの失敗は商品自体の作成成功を無効にしない。
			$details_failure   = [
				'retryable' => false,
				'terminal'  => false,
			];
			$follow_up_payload = $transformer->to_update_payload( $product );

			if ( $needs_hidden_safeguard ) {
				// R2レビュー指摘で`to_create_payload()`のみに限定したhidden強制を、この直後の
				// 追いPUTが`to_update_payload()`のshowingでそのまま上書きしてしまっていた
				// （R3レビュー指摘: 安全策が実質0秒しか効かない）。追いPUTでも同じ判定を反映する。
				$follow_up_payload['display_state'] = 'hidden';
			}

			try {
				$this->client()->put( "products/{$product_remote_id}.json", [ 'product' => $follow_up_payload ] );
			} catch ( RateLimitExhaustedException $exception ) {
				// レート制限はジョブ全体を一時停止すべきシグナル（`Sync\Exporter`のPR #40 G1-1
				// 専用catch）のため、ここでは握り潰さずそのまま再スローする。
				throw $exception;
			} catch ( Throwable $exception ) {
				self::record_failure( $details_failure, $exception );
			}

			self::append_failure_warning( $warnings, $details_failure, WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE, WarningCode::PRODUCT_DETAILS_PUSH_FAILED );
		}

		$variant_remote_ids = [] !== $product->variants
			? $this->sync_variants( $product_remote_id, $product, $warnings )
			: [];

		if ( $this->can_push_images() ) {
			$this->push_images( $product_remote_id, $product, $warnings );
		} elseif ( [] !== $product->images ) {
			$warnings[] = WarningCode::PRODUCT_IMAGES_NOT_PUSHED;
		}

		return new PushResult( $product_remote_id, $operation, $warnings, $variant_remote_ids );
	}

	/**
	 * サブリクエスト（追いPUT・オプション/値追加・バリエーションPUT・画像push）の失敗が
	 * 再試行で解決しうるか判定する。429/5xx/通信断（`ApiException`以外のThrowable）は
	 * retry-worthy、それ以外の4xx（422の入力エラー・403の権限エラー・404等）は再試行しても
	 * 解決しない終端状態とみなす（R1レビュー指摘: 4xxもretry対象に含めると恒久的な失敗が
	 * 毎回同じ無駄なリクエスト列を繰り返す）。`RateLimitExhaustedException`は呼び出し元が
	 * 専用catchで先に再スローする契約のため、ここには到達しない。
	 */
	private static function is_retryable_failure( Throwable $exception ): bool {
		if ( ! $exception instanceof ApiException ) {
			return true;
		}

		$status = $exception->status_code();

		return 0 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * @param array{retryable:bool,terminal:bool} $failure
	 */
	private static function record_failure( array &$failure, Throwable $exception ): void {
		if ( self::is_retryable_failure( $exception ) ) {
			$failure['retryable'] = true;
		} else {
			$failure['terminal'] = true;
		}
	}

	/**
	 * @param array<int,string>                    $warnings
	 * @param array{retryable:bool,terminal:bool}   $failure
	 */
	private static function append_failure_warning( array &$warnings, array $failure, string $retryable_code, string $terminal_code ): void {
		if ( $failure['retryable'] ) {
			$warnings[] = $retryable_code;
		} elseif ( $failure['terminal'] ) {
			$warnings[] = $terminal_code;
		}
	}

	/**
	 * variable商品のバリエーション同期。ColorMeのバリエーションは商品オプション（軸）・
	 * オプション値の追加で自動生成される方式のため、既存の軸・値を`GET /products/{id}`で読み、
	 * 不足分だけ`POST /options`/`POST /options/{id}/values`で追加してから再取得し、
	 * 軸名をキーにした`{軸名: 値}`の組でCanonicalの各バリエーションと突合する（スロット位置
	 * =`option1`/`option2`ではなく名前で突合する。R1レビュー指摘: ColorMe側の既存オプションの
	 * スロット割当（作成順で決まる）とWoo側の軸抽出順が食い違う更新ケースでは、スロット位置
	 * 比較だと軸名は正しく解決されてもバリエーションが恒久的に未確定になる）。
	 *
	 * @param array<int,string> $warnings 呼び出し元と共有する警告配列（参照渡しの代わりに戻り値で反映）。
	 * @return array<int,string> `$product->variants`と同じ順序・同じ要素数のremote_id一覧
	 *   （空文字列=未確定/失敗）。
	 */
	private function sync_variants( string $product_remote_id, CanonicalProduct $product, array &$warnings ): array {
		$failure = [
			'retryable' => false,
			'terminal'  => false,
		];

		$current = $this->fetch_product_detail( $product_remote_id, $failure );

		if ( null === $current ) {
			self::append_failure_warning( $warnings, $failure, WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE, WarningCode::PRODUCT_VARIANT_PUSH_FAILED );

			return array_fill( 0, count( $product->variants ), '' );
		}

		$existing_options = is_array( $current['options'] ?? null ) ? $current['options'] : [];

		$axis1_name = self::first_axis_value( $product->variants, 'option1_name' );
		$axis2_name = self::first_axis_value( $product->variants, 'option2_name' );

		if ( null !== $axis1_name && null !== $axis2_name && $axis1_name === $axis2_name ) {
			// 軸名の衝突は`axis_map_from_pairs()`が最終突合の時点で検出しフェイルクローズするが、
			// それより前にここで`POST /options`を実行してしまうと、結局突合できず捨てる
			// バリエーション用のオプション・直積バリエーションをColorMe側へ作成してしまう
			// （原則4によりこちらから削除できない余剰データ。G3レビュー指摘, Copilot）。
			// ここで先に検出し、リモートを一切変更せずに終端失敗とする。
			$failure['terminal'] = true;
			self::append_failure_warning( $warnings, $failure, WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE, WarningCode::PRODUCT_VARIANT_PUSH_FAILED );

			return array_fill( 0, count( $product->variants ), '' );
		}

		if ( null !== $axis1_name ) {
			$this->ensure_option_values( $product_remote_id, $axis1_name, self::axis_values( $product->variants, 'option1_value' ), $existing_options, $failure );
		}

		if ( null !== $axis2_name ) {
			$this->ensure_option_values( $product_remote_id, $axis2_name, self::axis_values( $product->variants, 'option2_value' ), $existing_options, $failure );
		}

		$refreshed = $this->fetch_product_detail( $product_remote_id, $failure );

		if ( null === $refreshed ) {
			self::append_failure_warning( $warnings, $failure, WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE, WarningCode::PRODUCT_VARIANT_PUSH_FAILED );

			return array_fill( 0, count( $product->variants ), '' );
		}

		if ( ! is_array( $refreshed['variants'] ?? null ) ) {
			// `variants`キー自体が欠損・非配列の場合（スキーマ崩壊）を、正当な「バリエーション
			// 0件」と区別する（G2レビュー指摘, Copilot Suppressed comments）。区別しないと
			// `fetch_product_detail()`の`product`エンベロープ検証はここを通過するのに、
			// 全バリエーションが未確定・終端警告のまま親のchecksumがキャッシュされてしまう。
			$failure['retryable'] = true;
			self::append_failure_warning( $warnings, $failure, WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE, WarningCode::PRODUCT_VARIANT_PUSH_FAILED );

			return array_fill( 0, count( $product->variants ), '' );
		}

		$raw_remote_variants = $refreshed['variants'];
		$remote_variants     = array_values( array_filter( $raw_remote_variants, 'is_array' ) );

		if ( count( $remote_variants ) !== count( $raw_remote_variants ) ) {
			// `variants`配列自体は配列だが、一部の要素が非配列（valid行と壊れた行が混在する
			// スキーマ異常）の場合、`array_filter()`が黙って壊れた行を落とすと件数がローカルと
			// 偶然一致し無警告のまま解決済み扱いになりうる（G3レビュー指摘, Copilot）。
			$failure['retryable'] = true;
		}

		$remote_by_key = [];

		foreach ( $remote_variants as $remote_variant ) {
			$remote_id = Cast::to_string_or_null( $remote_variant['id'] ?? null );

			if ( null === $remote_id ) {
				continue;
			}

			$axis_map = self::remote_variant_axis_map( $remote_variant );

			if ( null === $axis_map ) {
				// 軸名が衝突しキーを一意に決定できない（R2レビュー指摘）。誤対応付けを避けるため
				// この1件を突合対象から除外する（どのローカルバリエーションからも見つからず、
				// surplusとして扱われる＝実害はremote側に販売可能なまま残ることの警告のみ）。
				continue;
			}

			$remote_by_key[ self::variant_key( $axis_map ) ] = $remote_id;
		}

		if ( count( $remote_variants ) > count( $product->variants ) ) {
			// ColorMeはオプション追加で全組み合わせ（直積）を自動生成するため、Woo側に対応する
			// バリエーションが無い組み合わせがリモートに残りうる（原則4によりこちらから削除できない）。
			$warnings[] = WarningCode::PRODUCT_VARIANT_SURPLUS_ON_REMOTE;
		}

		$result = [];

		foreach ( $product->variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				$failure['terminal'] = true;
				$result[]            = '';
				continue;
			}

			$axis_map = self::variant_axis_map( $variant );

			if ( null === $axis_map ) {
				// 軸名が衝突しキーを一意に決定できない（R2レビュー指摘）。同名の異なる軸を
				// 持つ別のバリエーションと誤って同じキーに解決され、SKU/価格/在庫が入れ替わって
				// pushされる事故を避けるため、突合自体を諦めて未確定のままにする。
				$failure['terminal'] = true;
				$result[]            = '';
				continue;
			}

			$key               = self::variant_key( $axis_map );
			$variant_remote_id = $remote_by_key[ $key ] ?? null;

			if ( null === $variant_remote_id ) {
				$failure['terminal'] = true;
				$result[]            = '';
				continue;
			}

			if ( $this->push_variant_details( $product_remote_id, $variant_remote_id, $product, $variant, $failure ) ) {
				$result[] = $variant_remote_id;
			} else {
				$result[] = '';
			}
		}

		self::append_failure_warning( $warnings, $failure, WarningCode::PRODUCT_VARIANT_PUSH_INCOMPLETE, WarningCode::PRODUCT_VARIANT_PUSH_FAILED );

		return $result;
	}

	/**
	 * @param array{retryable:bool,terminal:bool} $failure
	 * @return ?array<string,mixed>
	 */
	private function fetch_product_detail( string $product_remote_id, array &$failure ): ?array {
		try {
			$body = $this->client()->get( "products/{$product_remote_id}.json" );
		} catch ( RateLimitExhaustedException $exception ) {
			throw $exception;
		} catch ( Throwable $exception ) {
			self::record_failure( $failure, $exception );

			return null;
		}

		$product = $body['product'] ?? null;

		if ( ! is_array( $product ) ) {
			// 200応答でも`product`エンベロープが欠損・非配列の場合はスキーマ崩壊とみなす
			// （R3レビュー指摘: 従来はここで`$failure`に何も記録せず、`sync_variants()`が
			// 警告を一切積まないまま商品本体のchecksumだけがキャッシュされ、バリエーション
			// 未同期が恒久的に再試行されなくなっていた）。商品本体は既に作成/更新済み
			// （remote_id確定）のため、ここで例外を投げてpush_product()全体を失敗させると
			// mappings書込前にremote_idが失われ次回exportで重複作成されうる（`list_from()`と
			// 異なり、ここは書込パスの途中のためretryable failureとして記録するに留める）。
			$failure['retryable'] = true;

			return null;
		}

		return $product;
	}

	/**
	 * `$axis_values`のうち既存オプション（`name`一致）にまだ無い値を追加する。該当オプション
	 * 自体が無ければ`POST /options`で全値まとめて新規作成する（1商品最大2オプションのため、
	 * 既に無関係な2オプションが存在する場合は422で失敗しうる＝falseを返す。フェイルクローズ）。
	 * `values`は swagger 実測でオブジェクトの配列（`[{"name":"赤"}, ...]`）であり、
	 * `GET /products/{id}`レスポンス側の文字列配列とはリクエスト/レスポンスでスキーマが異なる
	 * （R1レビュー指摘: 文字列配列のまま送ると422になりバリエーションが1件も作られない）。
	 *
	 * @param array<int,array<string,mixed>>      $existing_options
	 * @param array<int,string>                   $axis_values
	 * @param array{retryable:bool,terminal:bool}  $failure
	 */
	private function ensure_option_values( string $product_remote_id, string $axis_name, array $axis_values, array $existing_options, array &$failure ): bool {
		$existing = null;

		foreach ( $existing_options as $option ) {
			if ( is_array( $option ) && Cast::to_string_or_null( $option['name'] ?? null ) === $axis_name ) {
				$existing = $option;
				break;
			}
		}

		if ( null === $existing ) {
			try {
				$this->client()->post(
					"products/{$product_remote_id}/options.json",
					[
						'option' => [
							'name'   => $axis_name,
							'values' => array_map( static fn ( string $value ): array => [ 'name' => $value ], $axis_values ),
						],
					]
				);

				return true;
			} catch ( RateLimitExhaustedException $exception ) {
				throw $exception;
			} catch ( Throwable $exception ) {
				self::record_failure( $failure, $exception );

				return false;
			}
		}

		$option_id = Cast::to_string_or_null( $existing['id'] ?? null );

		if ( null === $option_id ) {
			$failure['terminal'] = true;

			return false;
		}

		$existing_values = is_array( $existing['values'] ?? null ) ? Cast::strings( $existing['values'] ) : [];
		$ok              = true;

		foreach ( $axis_values as $value ) {
			if ( in_array( $value, $existing_values, true ) ) {
				continue;
			}

			try {
				$this->client()->post(
					"products/{$product_remote_id}/options/{$option_id}/values.json",
					[ 'option_value' => [ 'name' => $value ] ]
				);
			} catch ( RateLimitExhaustedException $exception ) {
				throw $exception;
			} catch ( Throwable $exception ) {
				self::record_failure( $failure, $exception );
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * @param array<int,array<string,mixed>> $variants
	 */
	private static function first_axis_value( array $variants, string $name_key ): ?string {
		foreach ( $variants as $variant ) {
			$name = is_array( $variant ) ? Cast::to_string_or_null( $variant[ $name_key ] ?? null ) : null;

			if ( null !== $name ) {
				return $name;
			}
		}

		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $variants
	 * @return array<int,string> 重複なし・出現順。
	 */
	private static function axis_values( array $variants, string $value_key ): array {
		$values = [];

		foreach ( $variants as $variant ) {
			$value = is_array( $variant ) ? Cast::to_string_or_null( $variant[ $value_key ] ?? null ) : null;

			if ( null !== $value && ! in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/**
	 * ローカル（Woo）側バリエーションの`{軸名: 値}`マップ。`option1_name`/`option2_name`が
	 * 表すスロットはこの商品内の抽出順でしかなく、ColorMe側の既存オプションのスロット割当とは
	 * 独立（`ensure_option_values()`が軸を名前で解決するのと同じ理由）。
	 *
	 * @param array<string,mixed> $variant `CanonicalProduct::$variants`の1要素。
	 * @return ?array<string,string> 2軸が同じ名前を持つ（`Woo\Support\VariationAxisResolver::
	 *   attribute_label()`はラベル重複を排除しない）場合はnull（信頼できるキーを組み立てられない。
	 *   R2レビュー指摘: 素朴に連想配列へ書くと後勝ちで潰れ、異なる値を持つ複数バリエーションが
	 *   同じキーに衝突し誤対応付けを起こす）。
	 */
	private static function variant_axis_map( array $variant ): ?array {
		return self::axis_map_from_pairs(
			[
				[ Cast::to_string_or_null( $variant['option1_name'] ?? null ), Cast::to_string_or_null( $variant['option1_value'] ?? null ) ],
				[ Cast::to_string_or_null( $variant['option2_name'] ?? null ), Cast::to_string_or_null( $variant['option2_value'] ?? null ) ],
			]
		);
	}

	/**
	 * リモート（`GET /products/{id}`レスポンス）側バリエーションの`{軸名: 値}`マップ。
	 * ネストされた`option1`/`option2`オブジェクト（`{id,name,value_id,value}`）の`name`/`value`
	 * を使う（フラットな`option1_value`/`option2_value`はスロット位置の情報しか持たない）。
	 *
	 * @param array<string,mixed> $remote_variant
	 * @return ?array<string,string> `variant_axis_map()`と同じ理由でnullになりうる。
	 */
	private static function remote_variant_axis_map( array $remote_variant ): ?array {
		$pairs = [];

		foreach ( [ 'option1', 'option2' ] as $slot ) {
			$option = $remote_variant[ $slot ] ?? null;

			$pairs[] = is_array( $option )
				? [ Cast::to_string_or_null( $option['name'] ?? null ), Cast::to_string_or_null( $option['value'] ?? null ) ]
				: [ null, null ];
		}

		return self::axis_map_from_pairs( $pairs );
	}

	/**
	 * @param array<int,array{0:?string,1:?string}> $pairs [name, value] の組。
	 * @return ?array<string,string> 軸名の衝突、部分指定（名前のみ/値のみ）、全スロット未使用の
	 *   いずれかを検出した場合はnull（信頼できるキーを組み立てられない。呼び出し元がフェイル
	 *   クローズする）。
	 */
	private static function axis_map_from_pairs( array $pairs ): ?array {
		$map = [];

		foreach ( $pairs as [ $name, $value ] ) {
			if ( null === $name && null === $value ) {
				// このスロット自体が未使用（軸そのものが存在しない）。正当な状態のため無視する。
				continue;
			}

			if ( null === $name || null === $value ) {
				// 名前だけ・値だけの部分指定（例: Wooの「Any <属性>」ワイルドカードは値が空文字列
				// →nullに変換される〈CLAUDE.md既知の変換〉が、属性自体は割り当てられているため
				// 名前は残る）。無視すると2軸の変種が1軸相当のキーに潰れ、他の変種と衝突しうる
				// （R2レビュー指摘, Copilot）。安全にキーを組み立てられないためフェイルクローズする。
				return null;
			}

			if ( array_key_exists( $name, $map ) ) {
				return null;
			}

			$map[ $name ] = $value;
		}

		return [] !== $map ? $map : null;
	}

	/**
	 * `{軸名: 値}`マップを順序非依存の安定した文字列キーへ変換する。
	 *
	 * @param array<string,string> $axis_map
	 */
	private static function variant_key( array $axis_map ): string {
		ksort( $axis_map );

		return (string) wp_json_encode( $axis_map );
	}

	/**
	 * @param array<string,mixed>                 $variant `CanonicalProduct::$variants`の1要素。
	 * @param array{retryable:bool,terminal:bool}  $failure
	 */
	private function push_variant_details( string $product_remote_id, string $variant_remote_id, CanonicalProduct $product, array $variant, array &$failure ): bool {
		$payload = [];

		$sku = Cast::to_string_or_null( $variant['sku'] ?? null );

		if ( null !== $sku ) {
			$payload['model_number'] = $sku;
		}

		$price = $this->product_transformer()->to_push_amount( Cast::to_string_or_null( $variant['price'] ?? null ), $product->tax_class );

		if ( null !== $price ) {
			$payload['option_price'] = $price;
		}

		$stock = $variant['stock'] ?? null;

		if ( is_int( $stock ) ) {
			$payload['stocks'] = $stock;
		}

		$weight = $variant['weight'] ?? null;

		if ( is_int( $weight ) ) {
			$payload['weight'] = $weight;
		}

		if ( [] === $payload ) {
			return true;
		}

		try {
			$this->client()->put( "products/{$product_remote_id}/variants/{$variant_remote_id}.json", [ 'variant' => $payload ] );

			return true;
		} catch ( RateLimitExhaustedException $exception ) {
			throw $exception;
		} catch ( Throwable $exception ) {
			self::record_failure( $failure, $exception );

			return false;
		}
	}

	/**
	 * 画像push（`POST /products/{id}/images`、`multipart/form-data`、プレミアムプラン限定。
	 * 呼び出し元=`push_product()`が`can_push_images()`で事前に判定する）。Wooの画像はこの
	 * プラグインが動くWordPressサイト自身のメディア（`Woo\Reader\ProductReader::images()`が
	 * 返す`src`はローカルURL）のため、`wp_remote_get()`でバイナリを取得してからカラーミーへ
	 * 再アップロードする（`Support\HttpClient`はJSON body専用のためここは生のWP HTTP APIを使う）。
	 * 1件の失敗は商品全体を失敗させず、警告のみ積んで処理を続ける。
	 *
	 * @param array<int,string> $warnings 呼び出し元と共有する警告配列（参照渡しの代わりに戻り値で反映）。
	 */
	private function push_images( string $product_remote_id, CanonicalProduct $product, array &$warnings ): void {
		$failure = [
			'retryable' => false,
			'terminal'  => false,
		];

		foreach ( $product->images as $image ) {
			if ( ! is_array( $image ) ) {
				$failure['terminal'] = true;
				continue;
			}

			$src      = Cast::to_string_or_null( $image['src'] ?? null );
			$position = $image['position'] ?? null;

			if ( null === $src || ! is_int( $position ) || $position < 0 || $position > 49 ) {
				$failure['terminal'] = true;
				continue;
			}

			$binary = self::fetch_image_binary( $src, $failure );

			if ( null === $binary ) {
				continue;
			}

			try {
				$this->client()->post_multipart(
					"products/{$product_remote_id}/images.json",
					'image',
					self::image_filename( $src, $position ),
					$binary,
					[ 'position' => $position ]
				);
			} catch ( RateLimitExhaustedException $exception ) {
				throw $exception;
			} catch ( Throwable $exception ) {
				self::record_failure( $failure, $exception );
			}
		}

		self::append_failure_warning( $warnings, $failure, WarningCode::PRODUCT_IMAGE_PUSH_INCOMPLETE, WarningCode::PRODUCT_IMAGE_PUSH_FAILED );
	}

	/**
	 * @param array{retryable:bool,terminal:bool} $failure
	 */
	private static function fetch_image_binary( string $url, array &$failure ): ?string {
		$response = wp_remote_get( $url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $response ) ) {
			// Wooサイト自身へのローカルHTTP取得の失敗（タイムアウト・接続断等）は一時的な事情に
			// 起因しうるため再試行対象にする。
			$failure['retryable'] = true;

			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			// R3レビュー指摘（Copilot Suppressed comments）: 404/403等の恒久的な失敗
			// （添付の削除等）を一律retryableにすると、checksumが永久にキャッシュされず
			// 毎回同じ無駄な取得を繰り返す。`is_retryable_failure()`と同じ基準（429/5xx/
			// 通信断のみretryable）で分類する。
			if ( 429 === $status || $status >= 500 ) {
				$failure['retryable'] = true;
			} else {
				$failure['terminal'] = true;
			}

			return null;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( '' === $body ) {
			// 200応答でも本文が空の場合（プロキシ異常等）、以前はここで失敗を記録せずnullを
			// 返していた。`push_images()`が警告を一切積まずcheckされないため、`Exporter`が
			// 親商品のchecksumをキャッシュし画像が永久にpushされなくなる（G2レビュー指摘, Codex）。
			// 状況不明のため安全側でretryable扱いにする。
			$failure['retryable'] = true;

			return null;
		}

		return $body;
	}

	private static function image_filename( string $url, int $position ): string {
		$path     = wp_parse_url( $url, PHP_URL_PATH );
		$basename = is_string( $path ) ? wp_basename( $path ) : '';

		return '' !== $basename ? $basename : "image-{$position}.jpg";
	}

	public function push_category( CanonicalCategory $category ): PushResult {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	/**
	 * `push_product()`と同じ規約: 本体リクエストの失敗はここで捕まえず`Sync\Exporter`の汎用catchへ
	 * 委ねる（1件の異常でページ全体を止めない）。顧客はColorMeのAPI上POST/PUTとも1リクエストで
	 * 完結し（`push_product()`のような追いPUT/バリエーション/画像の多段リクエストが無い）ため、
	 * 部分完了の警告分類は不要。新規作成に必須の`pref_id`/`postal`/`address1`/`tel`が
	 * Woo顧客の請求先情報から解決できない場合は`CustomerTransformer::to_create_payload()`が
	 * `null`を返すため、送信自体を行わずフェイルクローズでスキップする
	 * （`WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING`。422を送って恒久的な4xxを積み重ねない）。
	 */
	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		$transformer = new CustomerTransformer();

		if ( null === $remote_id ) {
			$payload = $transformer->to_create_payload( $customer );

			if ( null === $payload ) {
				return new PushResult( '', PushResult::OPERATION_SKIPPED, [ WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING ] );
			}

			$body      = $this->client()->post( 'customers.json', [ 'customer' => $payload ] );
			$operation = PushResult::OPERATION_CREATED;
		} else {
			$body      = $this->client()->put( "customers/{$remote_id}.json", [ 'customer' => $transformer->to_update_payload( $customer ) ] );
			$operation = PushResult::OPERATION_UPDATED;
		}

		$customer_remote_id = Cast::to_string_or_null( $body['customer']['id'] ?? null ) ?? $remote_id;

		if ( null === $customer_remote_id ) {
			// `push_product()`と同じ理由: remote_idが取得できない「成功」応答をそのまま返すと
			// mappingsに書き込めず、次回exportが常に新規作成扱いになり重複が発生し続ける。
			throw new RuntimeException( 'ColorMe customer push response is missing the customer id.' );
		}

		if ( ! isset( $body['customer']['id'] ) ) {
			// `push_product()`と同じ理由: 更新（PUT）応答に`id`が無く既知`remote_id`へ
			// フォールバックした場合、ColorMe側のスキーマ変化を検知できるよう記録しておく。
			$this->logger->warning( 'ColorMe customer push response was missing the customer id; falling back to the known remote_id.', [ 'remote_id' => $customer_remote_id ] );
		}

		return new PushResult( $customer_remote_id, $operation );
	}

	public function push_order( CanonicalOrder $order, ?string $remote_id ): PushResult {
		if ( null !== $remote_id ) {
			// ColorMeの`PUT /sales/{id}`は入金状態・配送情報の一部しか更新できず、明細・決済/配送
			// 方法の変更はできない（swagger実測）。再`POST /sales`すると重複した受注が作成されて
			// しまうため、既にエクスポート済みの受注はAPIを一切呼ばずスキップする
			// （`docs/03-design-decisions.md` §10.2「E2-3 push_order」参照）。
			// `OrderTransformer::has_discount()`/`has_non_representable_charges()`は`shop.json`等の
			// I/Oを一切伴わない純粋な判定（`$order`のフィールドのみ参照）のため、この早期returnでも
			// 呼べる（Copilot指摘: 当初はこの経路で情報提供の警告が一切積まれず、割引・手数料が
			// 運べないという情報が既存受注の再エクスポートのたびに欠落していた）。
			return new PushResult( '', PushResult::OPERATION_SKIPPED, array_merge( [ WarningCode::ORDER_UPDATE_NOT_SUPPORTED ], self::informational_warnings( $order ) ) );
		}

		// `order_transformer()`（import方向`transform()`と共有）は`payments.json`/`deliveries.json`の
		// 名称マップを構築するが、`to_create_payload()`はこれらを使わない。共有インスタンスを経由すると
		// exportジョブでも無駄な2リクエストが発生するため、ここでは独立した軽量インスタンスを使う。
		$result = ( new OrderTransformer() )->to_create_payload( $order, new MethodMap( self::ID ), $this->order_tax_type() );

		// discount/feeの情報提供警告はブロック要因ではないため、push成功・スキップのいずれでも
		// 同じ理由（割引・手数料を運ぶAPIフィールドが無い）で積む。
		$informational_warnings = self::informational_warnings( $order );

		if ( null === $result['payload'] ) {
			return new PushResult( '', PushResult::OPERATION_SKIPPED, array_merge( self::order_skip_warnings( $result ), $informational_warnings ) );
		}

		// 過去のWoo受注を複製するのであって新規注文ではないため、既定（在庫引き当て）のまま
		// だとColorMe側の現在庫を実売と無関係に消費してしまう（在庫同期は別途push_stock()の責務）。
		$body = $this->client()->post( 'sales.json?reserve_stocks=false', [ 'sale' => $result['payload'] ] );

		$order_remote_id = Cast::to_string_or_null( $body['sale']['id'] ?? null );

		if ( null === $order_remote_id ) {
			// `push_product()`/`push_customer()`と同じ理由: remote_idが取得できない「成功」応答を
			// そのまま返すとmappingsに書き込めず、次回exportが常に新規作成扱いになり重複が
			// 発生し続ける。
			throw new RuntimeException( 'ColorMe order push response is missing the sale id.' );
		}

		// `ORDER_PLACED_AT_NOT_PRESERVED`は新規作成が成功した場合のみ（=このタイミングで初めて
		// 実際に日時が失われる事象が発生するため）。skip経路では新たに何も作成されないので付けない。
		$informational_warnings[] = WarningCode::ORDER_PLACED_AT_NOT_PRESERVED;

		return new PushResult( $order_remote_id, PushResult::OPERATION_CREATED, $informational_warnings );
	}

	/**
	 * ブロック要因ではなく情報提供のみの警告（割引・手数料が運べない）。`push_order()`の
	 * 早期return（既存remote_id指定時）・通常のskip・成功のいずれの経路でも同じ判定を使う。
	 *
	 * @return array<int,string>
	 */
	private static function informational_warnings( CanonicalOrder $order ): array {
		$warnings = [];

		if ( OrderTransformer::has_discount( $order ) ) {
			$warnings[] = WarningCode::ORDER_DISCOUNT_NOT_PUSHED;
		}

		if ( OrderTransformer::has_non_representable_charges( $order ) ) {
			$warnings[] = WarningCode::ORDER_FEE_NOT_PUSHED;
		}

		return $warnings;
	}

	/**
	 * `OrderTransformer::to_create_payload()`が`payload=null`を返した理由を対応する警告へ翻訳する。
	 * `line_items_unresolved`（明細が1行以上あるが未解決）は警告を積まない: `Woo\Reader\
	 * OrderReader`が既にreadItemの警告へ積んでおり`Sync\Exporter`が結果と無関係にマージするため
	 * （同メソッドのdocblock参照）。`line_items_empty`（明細0行）はreadItemの警告が一切無いため
	 * 専用コードで積む。
	 *
	 * @param array{payload:?array<string,mixed>,line_items_unresolved:bool,unmapped_payment_method_id:?string,unmapped_shipping_method_id:?string,shipping_address_incomplete:bool,line_items_empty:bool,line_price_unresolved:bool,discount_not_pushed:bool,fee_not_pushed:bool} $result
	 * @return array<int,string>
	 */
	private static function order_skip_warnings( array $result ): array {
		$warnings = [];

		if ( $result['line_items_empty'] ) {
			$warnings[] = WarningCode::ORDER_LINE_ITEMS_EMPTY;
		}

		if ( $result['line_price_unresolved'] ) {
			$warnings[] = WarningCode::ORDER_LINE_PRICE_UNRESOLVED;
		}

		if ( null !== $result['unmapped_payment_method_id'] ) {
			$warnings[] = WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, $result['unmapped_payment_method_id'] );
		}

		if ( null !== $result['unmapped_shipping_method_id'] ) {
			$warnings[] = WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, $result['unmapped_shipping_method_id'] );
		}

		if ( $result['shipping_address_incomplete'] ) {
			$warnings[] = WarningCode::ORDER_SHIPPING_ADDRESS_INCOMPLETE;
		}

		return $warnings;
	}

	public function push_stock( CanonicalStock $stock ): PushResult {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	/**
	 * `id.json`単体取得エンドポイント共通のラッパー。404は契約どおりnullに変換する。
	 *
	 * @return ?array<string,mixed>
	 */
	private function fetch_single_by_remote_id( string $path, string $envelope_key ): ?array {
		try {
			$body = $this->client()->get( $path );
		} catch ( ApiException $exception ) {
			if ( 404 === $exception->status_code() ) {
				return null;
			}

			throw $exception;
		}

		$item = $body[ $envelope_key ] ?? null;

		if ( is_array( $item ) ) {
			return $item;
		}

		// 200応答でも envelope キー自体が欠損、またはその中身が期待した配列でない場合
		// （スキーマ変更・プロキシ異常等）を無言でnullにすると、404（=正当な削除済み）と
		// 区別が付かなくなる。`run_sample_page()`はnullを「対象が存在しない」として黙って
		// スキップするため、そのままだとサンプル対象が診断もリトライも無く欠落したまま
		// ジョブが「完了」してしまう。例外を投げ`JobManager`の`catch(Throwable)`でジョブを
		// 失敗させる（`list_from()`と同じ方針）。
		throw new RuntimeException( "ColorMe \"{$path}\" returned a 200 response but its \"{$envelope_key}\" envelope was missing or not an array." );
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_sales_raw( array $query ): array {
		return $this->list_from( $this->client()->get( 'sales.json', $query ), 'sales' );
	}

	/**
	 * `$window_days`日前の日付が`HISTORY_FLOOR`より過去になったら`HISTORY_FLOOR`に丸める
	 * （探索の終端を明示するため）。
	 */
	private function history_floor_or_days_ago( int $window_days ): string {
		$candidate = gmdate( 'Y-m-d', time() - $window_days * DAY_IN_SECONDS );

		return $candidate < self::HISTORY_FLOOR ? self::HISTORY_FLOOR : $candidate;
	}

	private function order_transformer(): OrderTransformer {
		if ( null === $this->order_transformer ) {
			$this->order_transformer = new OrderTransformer(
				$this->id_name_map( 'payments.json', 'payments' ),
				$this->id_name_map( 'deliveries.json', 'deliveries' )
			);
		}

		return $this->order_transformer;
	}

	/**
	 * `push_order()`の明細価格解決に必要な店舗税設定（`shop.tax_type`）を`GET /v1/shop.json`から
	 * 取得する（`product_transformer()`と同じ取得パターン）。値が欠損・非期待型の場合は`null`の
	 * まま`OrderTransformer::to_create_payload()`へ渡し、同メソッド側のフェイルクローズ
	 * （既知の許可値のみ肯定判定・不明時は明細価格を省略しColorMeのカタログ価格適用に委ねる）に
	 * 任せる。
	 */
	private function order_tax_type(): ?string {
		if ( ! $this->order_tax_type_loaded ) {
			$shop = $this->client()->get( 'shop.json' )['shop'] ?? [];
			$shop = is_array( $shop ) ? $shop : [];

			$this->order_tax_type        = Cast::to_string_or_null( $shop['tax_type'] ?? null );
			$this->order_tax_type_loaded = true;
		}

		return $this->order_tax_type;
	}

	/**
	 * 定価（`price`）の税込換算に必要な店舗税設定（`shop.tax_type`/`tax`/`reduce_tax_rate`/
	 * `tax_rounding_method`）を`GET /v1/shop.json`から注入する（03 §9 #16）。値が欠損・非期待型の
	 * 場合はnullのまま`ProductTransformer`へ渡し、同クラス側のフェイルクローズ
	 * （既知の許可値のみ肯定判定・不明時は換算せず現行フォールバック）に委ねる。
	 */
	private function product_transformer(): ProductTransformer {
		if ( null === $this->product_transformer ) {
			$shop = $this->client()->get( 'shop.json' )['shop'] ?? [];
			$shop = is_array( $shop ) ? $shop : [];

			$this->product_transformer = new ProductTransformer(
				Cast::to_string_or_null( $shop['tax_type'] ?? null ),
				self::valid_tax_rate_or_null( $shop['tax'] ?? null ),
				self::valid_tax_rate_or_null( $shop['reduce_tax_rate'] ?? null ),
				Cast::to_string_or_null( $shop['tax_rounding_method'] ?? null )
			);
		}

		return $this->product_transformer;
	}

	/**
	 * `sale`は`payment_id`/`delivery_id`のみを持ち名称を含まないため、`OrderTransformer`が
	 * 参照する`id => name`マップをここで組み立てる（同クラスdocblock参照）。
	 *
	 * @return array<int,string>
	 */
	private function id_name_map( string $path, string $envelope_key ): array {
		$rows = $this->list_from( $this->client()->get( $path ), $envelope_key );
		$map  = [];

		foreach ( $rows as $row ) {
			$id   = Cast::to_int_or_null( $row['id'] ?? null );
			$name = Cast::to_string_or_null( $row['name'] ?? null );

			if ( null !== $id && null !== $name ) {
				$map[ $id ] = $name;
			}
		}

		return $map;
	}

	/**
	 * 一覧エンベロープキー（例: `products`）はAPI契約上必ず配列で返る前提。キー自体の欠損や
	 * 非配列値はショップの仕様変更・プロキシ異常等によるスキーマ崩壊であり、`[]`（正当な0件）と
	 * 区別せず返すと、呼び出し元がページ終端と誤認しジョブを「完了」させてしまい、
	 * データ欠落がリトライ可能な失敗として表面化しない（フェイルクローズ原則。CLAUDE.md）。
	 * ここで例外を投げ`JobManager`の`catch(Throwable)`でジョブを失敗させる。
	 *
	 * @param array<string,mixed> $body
	 * @return array<int,array<string,mixed>>
	 */
	private function list_from( array $body, string $key ): array {
		$list = $body[ $key ] ?? null;

		if ( ! is_array( $list ) ) {
			throw new RuntimeException( "ColorMe API response is missing the expected \"{$key}\" list envelope." );
		}

		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function total_from_meta( array $body ): ?int {
		$meta = $body['meta'] ?? null;

		return is_array( $meta ) ? self::exact_int_or_null( $meta['total'] ?? null ) : null;
	}

	/**
	 * `meta.total`はページング終端の境界値として使うため、`Cast::to_int_or_null()`の暗黙の
	 * 切り捨て（例: 50.5→50件目までしか無いページを「50件で完了」と誤認）をそのまま許すと、
	 * 実際にはより多くの行が残るページを誤って終端と判定しかねない。整数として厳密に
	 * 表現できる値のみ受け付け、小数はnullに倒す（null＝「総件数不明」として`next_cursor()`が
	 * 空ページに達するまで継続する）。
	 */
	private static function exact_int_or_null( mixed $value ): ?int {
		if ( is_string( $value ) && is_numeric( $value ) ) {
			$value = $value + 0;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		return is_float( $value ) && (float) (int) $value === $value ? (int) $value : null;
	}

	/**
	 * `shop.tax`/`shop.reduce_tax_rate`（パーセント表記の税率）用のバリデーション。
	 * swagger上はinteger型だが、`Cast::to_int_or_null()`は小数（例: `8.9`）を`(int)`丸めで
	 * 黙って通してしまうため、`exact_int_or_null()`と同じ理由（`meta.total`参照）で厳密な
	 * 整数のみを受け付ける。さらに負値・非現実的に大きい値（プロキシ異常等でのスキーマ崩壊）
	 * を弾き、現実的な税率レンジ（0〜100%）外の値は換算不可としてフェイルクローズする
	 * （レビュー指摘: PR #24。誤った税率でもっともらしいが誤った定価を計算してしまうことを防ぐ）。
	 */
	private static function valid_tax_rate_or_null( mixed $value ): ?int {
		$rate = self::exact_int_or_null( $value );

		return null !== $rate && $rate >= 0 && $rate <= 100 ? $rate : null;
	}

	/**
	 * `list_from()`はis_array()フィルタ後の配列を返すため、非配列要素が混入したページでは
	 * その件数がAPI側の実際のページ内行数より少なくなりうる。カーソルのoffset計算を
	 * フィルタ後の件数で行うと、次ページのoffsetがAPI側の絶対位置より手前になり、
	 * 除外された行を含むページと次のページが重複し、重複取込・重複書込を招く
	 * （upsertのため実害は軽微だが、レート制限を無駄に消費する）。offset計算には
	 * 必ずフィルタ前の生の行数を使う。
	 *
	 * @param array<string,mixed> $body
	 */
	private function raw_row_count( array $body, string $key ): int {
		$list = $body[ $key ] ?? null;

		return is_array( $list ) ? count( $list ) : 0;
	}

	/**
	 * `meta.total`が得られる場合はそれで終端判定し、得られない場合（categories/groups/
	 * shop_couponsの単発取得を除くページング系）はページサイズ未満の取得件数を終端の合図にする。
	 *
	 * `$raw_count`は`list_from()`によるフィルタ前の生の行数（`raw_row_count()`）を渡すこと。
	 * フィルタ後の件数を渡すと、次ページのoffsetがAPI側の絶対位置より手前になり重複取得を招く
	 * （offset計算はAPI側のページ内行数と対応させる必要があるため）。
	 */
	private function next_cursor( int $offset, int $raw_count, ?int $total ): ?Cursor {
		// 0件取得時は無条件に終端とする。`meta.total`がoffsetより大きい値を報告していても
		// （並行削除等で0件になった場合）offsetを進めるすべが無く、同じoffsetのCursorを返すと
		// JobManagerが同一ページを無限に再エンキューし続けてしまう。
		if ( 0 === $raw_count ) {
			return null;
		}

		$next_offset = $offset + $raw_count;

		// `meta.total`が負値、またはここまでの累計行数（`$next_offset`）にも満たない不整合な値
		// （スキーマ崩壊・プロキシ異常等）の場合は、totalを信頼できないとみなし「総件数不明」と
		// 同じ扱い（下のフォールバック＝空ページに達するまで継続）に倒す。不整合なtotalを
		// そのまま終端判定に使うと、実際にはまだ残っている行を含むページを「完了」と誤認し、
		// 静かな部分移行を招く（フェイルクローズ原則）。
		if ( null !== $total && $total >= $next_offset ) {
			return $next_offset < $total ? new Cursor( [ 'offset' => $next_offset ] ) : null;
		}

		// `meta.total`が得られない（または上記で信頼できないと判定された）場合、「取得件数が
		// ページサイズ未満＝最終ページ」とは推測しない（APIが実際にはページサイズ分の行を
		// 返していても、`$raw_count`自体がそのままページサイズと一致しない構成のエンドポイントが
		// ありうるため）。0件になるまで走査を続ける（安全側=継続に倒す）。
		return new Cursor( [ 'offset' => $next_offset ] );
	}

	/**
	 * 1行の変換失敗（例: id欠損の`RuntimeException`）でページ全体を落とさないための共通ラッパー。
	 * `Importer`の1件例外保護は`WooWriter::write()`周りにしか無く、fetch/transform段はここで担う。
	 *
	 * @template T
	 *
	 * @param array<int,array<string,mixed>>   $raw_items
	 * @param callable(array<string,mixed>):?T $transform
	 * @return array<int,T>
	 */
	private function transform_rows( array $raw_items, callable $transform, string $entity ): array {
		return $this->transform_rows_flat(
			$raw_items,
			static function ( array $raw ) use ( $transform ): array {
				$item = $transform( $raw );

				return null !== $item ? [ $item ] : [];
			},
			$entity
		);
	}

	/**
	 * `transform_rows()`の1件=0..N件版（category/stockのように1行から複数モデルを生成する場合）。
	 *
	 * @template T
	 *
	 * @param array<int,array<string,mixed>>             $raw_items
	 * @param callable(array<string,mixed>):array<int,T> $transform
	 * @return array<int,T>
	 */
	private function transform_rows_flat( array $raw_items, callable $transform, string $entity ): array {
		$result = [];

		foreach ( $raw_items as $raw ) {
			try {
				$items = $transform( $raw );
			} catch ( Throwable $exception ) {
				$this->log_transform_failure( $entity, $raw, $exception );
				continue;
			}

			array_push( $result, ...$items );
		}

		return $result;
	}

	/**
	 * `Support\Logger`の個人情報禁止ルール（`Importer`の同種catch節と同じ方針）に従い、
	 * remote_idと例外クラス名のみを記録する（例外メッセージ自体は含めない）。
	 *
	 * @param array<string,mixed> $raw
	 */
	private function log_transform_failure( string $entity, array $raw, Throwable $exception ): void {
		$this->logger->error(
			"Failed to transform a ColorMe \"{$entity}\" row.",
			[
				// `??`は空文字を「設定済み」とみなし`id_big`へフォールバックしない
				// （`id`が空文字の壊れた行でカテゴリー由来の`id_big`を拾えなくなる）ため、
				// `Cast::first_non_empty()`で空文字も未設定として扱う。
				'remote_id' => Cast::first_non_empty( $raw['id'] ?? null, $raw['id_big'] ?? null ),
				'exception' => $exception::class,
			]
		);
	}

	private function client(): ColorMeClient {
		$access_token = (string) ( $this->token_store->get()['access_token'] ?? '' );

		if ( '' === $access_token ) {
			// ステータス 0 は通信断・JSON 破損でも使われるため、呼び出し側（県コード修復ツール等）が
			// 「再接続が必要」と区別できるよう、未接続であることを文脈で明示する。
			throw new ApiException( 'ColorMe adapter is not connected.', 0, [ 'not_connected' => true ] );
		}

		return ColorMeClient::for_access_token( $access_token );
	}
}
