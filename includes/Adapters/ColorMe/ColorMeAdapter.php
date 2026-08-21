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
	 * `payments.json`/`deliveries.json` から組み立てた名称マップを持つ`OrderTransformer`。
	 * `AdapterRegistry::get()`はプラットフォーム単位でアダプタインスタンスを静的キャッシュするため、
	 * このキャッシュの実際の寿命は「同一PHPプロセス内で処理された全ジョブアクション」（Action
	 * Schedulerが1リクエストで複数アクションをまとめて実行する場合はページ横断で再利用される）。
	 * アクション毎に新規プロセスが割り当てられる実行環境ではプロセス毎に再取得される。
	 */
	private ?OrderTransformer $order_transformer = null;

	public function __construct(
		private readonly TokenStore $token_store = new TokenStore( self::ID ),
		private readonly ?ColorMeClient $client_override = null,
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
		$transformer = new ProductTransformer();
		$items       = $this->transform_rows( $raw, static fn ( array $item ): CanonicalProduct => $transformer->transform( $item ), 'product' );
		$total       = $this->total_from_meta( $body );

		return new Page( $items, $this->next_cursor( $offset, count( $raw ), $total ), $total );
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
		return new Page( $items, $this->next_cursor( $offset, count( $raw ), $row_total ), null );
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
		return new Page( $items, $this->next_cursor( $offset, count( $raw ), $row_total ), null );
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
		return new Page( $items, $this->next_cursor( $offset, count( $raw ), $product_total ), null );
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
	 * @return array<int,CanonicalOrder>
	 */
	public function fetch_latest_orders( int $limit ): array {
		$orders       = $this->transform_rows( $this->fetch_sales_raw( [ 'limit' => $limit ] ), fn ( array $item ): CanonicalOrder => $this->order_transformer()->transform( $item ), 'order' );
		$orders_count = count( $orders );
		$window_days  = self::LATEST_ORDERS_INITIAL_WINDOW_DAYS;

		// 取得件数ではなく変換に成功した件数で判定する。行欠損（id/make_date/total_price欠損）で
		// `transform_rows()`が一部の行を落とした場合、取得件数だけを見ていると`$limit`件揃った
		// ように誤認して探索を打ち切ってしまい、より過去に遡れば集まったはずの有効な受注を
		// 取りこぼす。
		while ( $orders_count < $limit ) {
			$window_days *= 4;
			$after        = $this->history_floor_or_days_ago( $window_days );
			$orders       = $this->transform_rows(
				$this->fetch_sales_raw(
					[
						'limit' => $limit,
						'after' => $after,
					]
				),
				fn ( array $item ): CanonicalOrder => $this->order_transformer()->transform( $item ),
				'order'
			);
			$orders_count = count( $orders );

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

		try {
			return ( new ProductTransformer() )->transform( $product );
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

		return is_array( $item ) ? $item : null;
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
	 * @param array<string,mixed> $body
	 * @return array<int,array<string,mixed>>
	 */
	private function list_from( array $body, string $key ): array {
		$list = $body[ $key ] ?? null;

		if ( ! is_array( $list ) ) {
			return [];
		}

		return array_values( array_filter( $list, 'is_array' ) );
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function total_from_meta( array $body ): ?int {
		$meta = $body['meta'] ?? null;

		return is_array( $meta ) ? Cast::to_int_or_null( $meta['total'] ?? null ) : null;
	}

	/**
	 * `meta.total`が得られる場合はそれで終端判定し、得られない場合（categories/groups/
	 * shop_couponsの単発取得を除くページング系）はページサイズ未満の取得件数を終端の合図にする。
	 */
	private function next_cursor( int $offset, int $fetched_count, ?int $total ): ?Cursor {
		// 0件取得時は無条件に終端とする。`meta.total`がoffsetより大きい値を報告していても
		// （並行削除や`list_from()`によるmalformed要素の除去等で0件になった場合）offsetを
		// 進めるすべが無く、同じoffsetのCursorを返すとJobManagerが同一ページを無限に
		// 再エンキューし続けてしまう。
		if ( 0 === $fetched_count ) {
			return null;
		}

		if ( null !== $total ) {
			return ( $offset + $fetched_count ) < $total ? new Cursor( [ 'offset' => $offset + $fetched_count ] ) : null;
		}

		return $fetched_count < self::PAGE_SIZE ? null : new Cursor( [ 'offset' => $offset + $fetched_count ] );
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
				'remote_id' => Cast::to_string_or_null( $raw['id'] ?? $raw['id_big'] ?? null ),
				'exception' => $exception::class,
			]
		);
	}

	private function client(): ColorMeClient {
		if ( null !== $this->client_override ) {
			return $this->client_override;
		}

		$access_token = (string) ( $this->token_store->get()['access_token'] ?? '' );

		if ( '' === $access_token ) {
			throw new ApiException( 'ColorMe adapter is not connected.', 0, [] );
		}

		return ColorMeClient::for_access_token( $access_token );
	}
}
