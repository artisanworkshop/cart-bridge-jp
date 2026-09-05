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
use CartBridgeJP\Support\TokenStore;
use RuntimeException;
use Throwable;

/**
 * カラーミーショップアダプタ（`01-plan-colorme.md`）。
 *
 * fetch系メソッドは`docs/03-design-decisions.md` §10.2 の無料版サンプル選定〜Pro版の全量走査
 * 双方から呼ばれる。push系はE4-3（エクスポート）で実装する（それまでは
 * `UnsupportedOperationException`）。
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

	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function push_category( CanonicalCategory $category ): PushResult {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function push_order( CanonicalOrder $order ): PushResult {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
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
			throw new ApiException( 'ColorMe adapter is not connected.', 0, [] );
		}

		return ColorMeClient::for_access_token( $access_token );
	}
}
