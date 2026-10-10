<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe;

use CartBridgeJP\Adapters\AbstractPlatformAdapter;
use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\ColorMe\Transform\Cast;
use CartBridgeJP\Adapters\ColorMe\Transform\CategoryTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\ProductTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\StockTransformer;
use CartBridgeJP\Adapters\ColorMe\Transform\TagTransformer;
use CartBridgeJP\Adapters\ConnectionField;
use CartBridgeJP\Adapters\ConnectionResult;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PartialPushException;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Canonical\CanonicalTag;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\ExportOptions;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;

/**
 * カラーミーショップアダプタ（`01-plan-colorme.md`）。
 *
 * fetch系メソッドは全量のカーソル走査（`Sync\Importer`）と、ツールのID指定取得から呼ばれる。push系はE2-3（エクスポート）で`push_product`/
 * `push_stock`を実装済み。`push_category`はE2-3未着手ではなく、`capabilities()`が宣言するとおりColorMe側の制約（カテゴリ作成不可）により
 * 恒久的に`UnsupportedOperationException`のまま。`mapping_candidates()`はE2-1で実装済み（R3-6c1 からカテゴリだけ）。
 * 顧客・受注・クーポンは `ColorMeCommerceAdapter`（R3-6c1 でこのクラスから移した）。
 */
final class ColorMeAdapter extends AbstractPlatformAdapter {

	public const ID = 'colorme';

	private const RATE_LIMIT_PER_MINUTE = 100;

	/**
	 * `shop.json`の税設定を注入した`ProductTransformer`。`AdapterRegistry::get()`はプラットフォーム単位でアダプタインスタンスを
	 * 静的キャッシュするため、インスタンス単位にキャッシュする（同一プロセス内で処理される全ページで`shop.json`を
	 * 1回だけ叩けば足りる）。
	 */
	private ?ProductTransformer $product_transformer = null;

	/**
	 * 認証済みの API 呼び出しと応答の共通処理（`api()`）。
	 */
	private ?ColorMeApi $api = null;

	public function __construct(
		private readonly TokenStore $token_store = new TokenStore( self::ID ),
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * 認証済みの ColorMe API と応答の共通処理（R3-6b1）。アダプタ自身の取得・送信もこれを通す。顧客・受注・クーポン
	 * （`ColorMeCommerceAdapter`。R3-6c1）もこの口を使う（`docs/03-design-decisions.md` §10.0「Pro が使ってよい無料版の API」）。
	 * アダプタと同じ `TokenStore`・`Logger` を使う。
	 */
	public function api(): ColorMeApi {
		if ( null === $this->api ) {
			$this->api = new ColorMeApi( $this->token_store, $this->logger );
		}

		return $this->api;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return __( 'Color Me Shop', 'cart-bridge-jp' );
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			// カテゴリはColorMe側で作成不可（01-plan §5）。
			can_create_category: false,
			// 能力（プランで決まる）。実際に送るかは`should_push_images()`が設定と合わせて決める（D24）。
			can_push_images: $this->is_premium_plan(),
			// groupsをタグとして扱う。
			has_tags: true,
			has_reviews: false,
			has_variants: true,
			rate_limit_per_minute: self::RATE_LIMIT_PER_MINUTE,
			// 在庫管理の印は商品単位だけで、バリエーションに相当する項目が無い。混在する商品は Exporter が止める（D22）。
			supports_per_variant_stock_management: false,
			// D24: プレミアムのテストショップが無く実 API で未検証。UI が Beta 表示と既定オフにする。
			// 静的な宣言で、項目を出すかどうかは上の`can_push_images`（プラン）が決める。受注のエクスポートのベータは `CommerceCapabilities`（R3-6c1）。
			beta_features: [ Capabilities::BETA_IMAGE_PUSH ]
		);
	}

	/**
	 * 商品画像を実際にアップロードするか（D24）。プレミアムプラン契約（`POST /v1/products/{id}/images` は
	 * プレミアム限定。要検証#1〔03 §9〕確定済み）**かつ** Export タブで「画像をアップロードする」が
	 * オンのときだけ true。既定はオフで、オフのときは`push_product()`が`PRODUCT_IMAGES_NOT_PUSHED`を積む。
	 * `capabilities()->can_push_images`（能力＝プラン）とは別物: 設定がオフでも UI は「この店舗で画像を
	 * アップロードできる」ことを知る必要があるため、能力自体は設定で変えない。
	 * `is_premium_plan()`が未接続・未キャッシュで安全側（false）に倒れる点はそのまま引き継ぐ。
	 */
	private function should_push_images(): bool {
		return $this->is_premium_plan() && ExportOptions::push_images_enabled( self::ID );
	}

	/**
	 * ショップがプレミアムプラン契約か。商品画像のアップロード（`POST /v1/products/{id}/images`）はプレミアム限定
	 * （`tests/fixtures/colorme/swagger.json`）。
	 * `test_connection()` が `shop.json` から取得・キャッシュした契約プランを見て動的に判定する。
	 * 未接続・未キャッシュの場合は安全側（false）に倒す。Pro アドオンが受注のエクスポート（`POST /v1/sales` もプレミアム限定）の可否を
	 * 決めるのにも使う（R3-6b1 で public）。
	 */
	public function is_premium_plan(): bool {
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
				__( 'From your Color Me Shop developer app registration.', 'cart-bridge-jp' )
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
				__( 'Connect to Color Me Shop', 'cart-bridge-jp' ),
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
		$body        = $this->api()->client()->get(
			'products.json',
			[
				'limit'  => ColorMeApi::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw         = $this->api()->list_from( $body, 'products' );
		$transformer = $this->product_transformer();
		$items       = $this->api()->transform_rows( $raw, static fn ( array $item ): CanonicalProduct => $transformer->transform( $item ), 'product' );
		$total       = $this->api()->total_from_meta( $body );

		// customer/order/stockと同じ理由（`meta.total`は生の行数であり、`list_from()`の非配列行
		// フィルタや`ProductTransformer::transform()`の変換失敗（例: `variants`欠損によるスキーマ
		// 崩壊）で`items`件数がそれと1:1対応するとは限らない）。ページング終端の判定にだけ使い、
		// 進捗率の分母として`Page`側には報告しない。
		return new Page( $items, $this->api()->next_cursor( $offset, $this->api()->raw_row_count( $body, 'products' ), $total ), null );
	}

	/**
	 * `/settings/mappings/colorme` UI向けのASP側候補一覧（D19）。カテゴリは既存の取得経路を再利用する。
	 * 決済・配送・注文ステータスは、その種類（Pro アドオン）が自分で返す（`Entities\MappingKind::platform_candidates()`。R3-6c1）。
	 *
	 * @return array<string,array<int,array{id:string,name:string}>>
	 */
	public function mapping_candidates(): array {
		return [
			'category' => self::category_candidates( $this->fetch_categories() ),
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
	 * @return array<int,CanonicalCategory>
	 */
	public function fetch_categories(): array {
		$body        = $this->api()->client()->get( 'categories.json' );
		$raw         = $this->api()->list_from( $body, 'categories' );
		$transformer = new CategoryTransformer();

		return $this->api()->transform_rows_flat( $raw, static fn ( array $item ): array => $transformer->transform( $item ), 'category' );
	}

	/**
	 * @return array<int,CanonicalTag>
	 */
	public function fetch_tags(): array {
		$body        = $this->api()->client()->get( 'groups.json' );
		$raw         = $this->api()->list_from( $body, 'groups' );
		$transformer = new TagTransformer();

		return $this->api()->transform_rows( $raw, static fn ( array $item ): ?CanonicalTag => $transformer->transform( $item ), 'tag' );
	}

	/**
	 * 商品一覧（`GET /products.json`）から在庫を導出する全量走査。`GET /stocks.json` はバリエーションIDを返さず
	 * `CanonicalStock::remote_id()` が衝突するため使わない（`StockTransformer` docblock参照）。
	 */
	public function fetch_stocks( Cursor $cursor ): Page {
		$offset        = (int) $cursor->get( 'offset', 0 );
		$body          = $this->api()->client()->get(
			'products.json',
			[
				'limit'  => ColorMeApi::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw           = $this->api()->list_from( $body, 'products' );
		$transformer   = new StockTransformer();
		$items         = $this->api()->transform_rows_flat( $raw, static fn ( array $item ): array => $transformer->transform( $item ), 'stock' );
		$product_total = $this->api()->total_from_meta( $body );

		// `Page::$total`は進捗率表示用の最終processed件数の見込み（`items`の累積件数と対になる）。
		// `$items`はバリエーション単位に展開済み（1商品→複数件）で商品件数と一致しないため、
		// `meta.total`（商品件数）をそのまま`Page`側の`total`として報告すると進捗が100%を
		// 超えて表示されてしまう。ページング終端の判定にだけ商品件数ベースの値を使い、
		// `Page`には報告しない。
		return new Page( $items, $this->api()->next_cursor( $offset, $this->api()->raw_row_count( $body, 'products' ), $product_total ), null );
	}

	public function fetch_reviews( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		$product = $this->api()->fetch_single( 'products/' . rawurlencode( $remote_id ) . '.json', 'product' );

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
			$this->api()->log_transform_failure( 'product', $product, $exception );

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
	 *
	 * 例外: 作成（`$remote_id === null`）が確定した後の追いPUT〜画像の処理が
	 * `RateLimitExhaustedException`等の例外で止まった場合は、`PartialPushException`（作成された
	 * remote_id付き）に包んで投げる（D21-A。issue #72）。`Sync\Exporter`がそのremote_idを
	 * checksum=nullでmappingに書き、次回exportをPOSTではなくPUTにして重複作成を防ぐ。
	 * 更新（既存remote_idへのPUT）は包まない（mappingが既にあるため）。
	 *
	 * 送れない商品（標準・軽減以外の税区分、価格を 1 件も換算できない。`ProductTransformer::push_blocker()`）は、
	 * 作成も更新もせず remote_id を空にした`skipped`で返す（R3-1d、issue #78。顧客の送信〔`ColorMeCommerceAdapter::push_customer()`〕の必須項目の欠けと同じ形。
	 * `Sync\Exporter`は作成なら intent を消し、更新なら既存の mapping・checksum に触れず次回に再試行する）。
	 * 以前は作成時だけ hidden にしていたが、更新では効かず次のエクスポートで課税商品として公開されていた。
	 */
	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult {
		$transformer = $this->product_transformer();
		$blocker     = $transformer->push_blocker( $product );

		if ( null !== $blocker ) {
			return new PushResult( '', PushResult::OPERATION_SKIPPED, [ $blocker ] );
		}

		if ( null === $remote_id ) {
			$body      = $this->api()->client()->post( 'products.json', [ 'product' => $transformer->to_create_payload( $product ) ] );
			$operation = PushResult::OPERATION_CREATED;
		} else {
			$body      = $this->api()->client()->put( "products/{$remote_id}.json", [ 'product' => $transformer->to_update_payload( $product ) ] );
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

		if ( null !== $remote_id ) {
			// 更新（既存remote_idへのPUT）は包まない。mappingが既にあり、checksumが一致するまで
			// 次回exportも同じPUTになるため、途中で例外が出ても重複は作られない。
			return $this->finish_product_push( $transformer, $product, $product_remote_id, $operation, false );
		}

		try {
			return $this->finish_product_push( $transformer, $product, $product_remote_id, $operation, true );
		} catch ( Throwable $exception ) {
			// 作成（POST）が確定してremote_idが分かった後の例外（`RateLimitExhaustedException`を含む）は、
			// 素のまま出すと`Sync\Exporter`がmappingを書けず、次回exportが同じ商品をもう一度POSTして
			// 重複させる（D21-A。issue #72）。remote_idを`PartialPushException`で運び、次回はPUTにする。
			throw new PartialPushException( $product_remote_id, $exception );
		}
	}

	/**
	 * `push_product()`の、商品本体（POST/PUT products）が成功してremote_idが確定した後の処理
	 * （追いPUT・バリエーション同期・画像push）。作成経路（`$is_create`）ではこの中で起きた例外を
	 * 呼び出し元が`PartialPushException`に包む（`push_product()`のcatch参照）ため、ここでは
	 * `RateLimitExhaustedException`を含む例外を握り潰さず素のまま投げてよい。
	 *
	 * @param string $operation `PushResult::OPERATION_CREATED` | `OPERATION_UPDATED`。
	 */
	private function finish_product_push( ProductTransformer $transformer, CanonicalProduct $product, string $product_remote_id, string $operation, bool $is_create ): PushResult {
		$warnings = [];

		if ( $is_create ) {
			// 新規作成はPOSTが受け付けない項目（category_id_small/group_ids/stocks）を
			// 反映するための追いPUTを行う。この追いPUTの失敗は商品自体の作成成功を無効にしない。
			$details_failure   = [
				'retryable' => false,
				'terminal'  => false,
			];
			$follow_up_payload = $transformer->to_update_payload( $product );

			try {
				$this->api()->client()->put( "products/{$product_remote_id}.json", [ 'product' => $follow_up_payload ] );
			} catch ( RateLimitExhaustedException $exception ) {
				// レート制限はジョブ全体を一時停止すべきシグナル（`Sync\Exporter`のPR #40 G1-1
				// 専用catch）のため、ここでは握り潰さずそのまま再スローする。作成経路では呼び出し元
				// （`push_product()`）が`PartialPushException`に包んでremote_idごと運ぶ（D21-A）。
				throw $exception;
			} catch ( Throwable $exception ) {
				self::record_failure( $details_failure, $exception );
			}

			self::append_failure_warning( $warnings, $details_failure, WarningCode::PRODUCT_DETAILS_PUSH_INCOMPLETE, WarningCode::PRODUCT_DETAILS_PUSH_FAILED );
		}

		$variant_remote_ids = [] !== $product->variants
			? $this->sync_variants( $product_remote_id, $product, $warnings )
			: [];

		if ( $this->should_push_images() ) {
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
			$body = $this->api()->client()->get( "products/{$product_remote_id}.json" );
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
				$this->api()->client()->post(
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
				$this->api()->client()->post(
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

		$transformer = $this->product_transformer();
		$regular     = $transformer->to_push_amount( Cast::to_string_or_null( $variant['price'] ?? null ), $product->tax_class );
		$sale_raw    = Cast::to_string_or_null( $variant['sale_price'] ?? null );

		if ( null === $sale_raw ) {
			if ( null !== $regular ) {
				$payload['option_price'] = $regular;
			}
		} else {
			// セール中: `option_price`（販売価格）＝実売価格、`option_market_price`（定価）＝通常価格
			// （商品レベルの`sales_price`/`price`＝`ProductTransformer::push_prices()`と同じ意味論。
			// swagger `productVariantUpdateRequest`）。`CanonicalProduct::$variants`は外部アダプタ
			// 境界のため、`ProductReader::valid_sale_price()`を通っている保証が無い。実売価格を
			// 換算できない、0以下、または通常価格を超える（通常価格も換算できず比較できない場合を
			// 含む）ときは、0円・負値・定価超えの販売価格や、通常価格を販売価格として送らないよう
			// 価格フィールドを両方省く（ColorMe側の既存値を保持。`null`明示だと商品レベルの価格へ
			// 戻り誤る。issue #60。通常価格とセール価格が換算の丸めで等しくなる場合は許容する）。
			$sale = $transformer->to_push_amount( $sale_raw, $product->tax_class );

			if ( null !== $sale && null !== $regular && $sale > 0 && $sale <= $regular ) {
				$payload['option_price']        = $sale;
				$payload['option_market_price'] = $regular;
			}
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
			$this->api()->client()->put( "products/{$product_remote_id}/variants/{$variant_remote_id}.json", [ 'variant' => $payload ] );

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
	 * 呼び出し元=`push_product()`が`should_push_images()`で事前に判定する）。Wooの画像はこの
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
				$this->api()->client()->post_multipart(
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
	 * ColorMeには在庫専用の書込みエンドポイントが無い（`GET /v1/stocks`はGETのみ）ため、商品/
	 * バリエーション更新APIを叩く。単純商品・管理外バリエーション（フェイルクローズでskip）は
	 * 1リクエストのみだが、管理中バリエーションは商品側`stock_managed`の明示PUT→バリエーション
	 * 本体PUTの2リクエストになる（G1ゲート、Codex指摘。要検証#19）。いずれの経路も
	 * 顧客・受注の送信（`ColorMeCommerceAdapter`）と同じ理由で`is_retryable_failure()`等の部分完了
	 * パターンは使わず、例外は`Sync\Exporter::process_items()`の汎用catchへそのまま委ねる
	 * （2リクエスト目が失敗しても1リクエスト目は冪等なため、次回exportで両方とも再試行される。
	 * `docs/03-design-decisions.md`§10.2「E2-3 PR-D」参照）。ColorMe側で削除済み（404）の場合も
	 * 同様に素通しする（stale mapping全般の設計は別途。issue #47）。
	 */
	public function push_stock( CanonicalStock $stock ): PushResult {
		if ( null !== $stock->variant_ref ) {
			$payload = StockTransformer::to_variant_payload( $stock );

			if ( null === $payload ) {
				return new PushResult( '', PushResult::OPERATION_SKIPPED, [ WarningCode::STOCK_VARIANT_UNMANAGED_NOT_PUSHABLE ] );
			}

			// バリエーション更新スキーマに`stock_managed`相当のフィールドが無く、商品全体が
			// `stock_managed=false`のまま`variant.stocks`だけ送っても反映されるかはswagger記載
			// からは確定できない（要検証#19）。反映されない場合に`variant.stocks`が無警告で
			// 無視されると恒久的な在庫未同期になるため、確実にColorMe側で在庫管理を有効化した
			// 状態でバリエーションの数量を送る（`ProductTransformer::base_payload()`の
			// `stock_managed`常時送信と同じ思想。review-loop G1でCodexが指摘）。
			$this->api()->client()->put( "products/{$stock->product_ref}.json", [ 'product' => [ 'stock_managed' => true ] ] );
			$this->api()->client()->put( "products/{$stock->product_ref}/variants/{$stock->variant_ref}.json", [ 'variant' => $payload ] );

			return new PushResult( $stock->remote_id(), PushResult::OPERATION_UPDATED );
		}

		$this->api()->client()->put( "products/{$stock->product_ref}.json", [ 'product' => StockTransformer::to_product_payload( $stock ) ] );

		return new PushResult( $stock->remote_id(), PushResult::OPERATION_UPDATED );
	}

	/**
	 * 定価（`price`）の税込換算に必要な店舗税設定（`shop.tax_type`/`tax`/`reduce_tax_rate`/
	 * `tax_rounding_method`）を`GET /v1/shop.json`から注入する（03 §9 #16）。値が欠損・非期待型の
	 * 場合はnullのまま`ProductTransformer`へ渡し、同クラス側のフェイルクローズ
	 * （既知の許可値のみ肯定判定・不明時は換算せず現行フォールバック）に委ねる。
	 */
	private function product_transformer(): ProductTransformer {
		if ( null === $this->product_transformer ) {
			$shop = $this->api()->client()->get( 'shop.json' )['shop'] ?? [];
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
	 * `shop.tax`/`shop.reduce_tax_rate`（パーセント表記の税率）用のバリデーション。
	 * swagger上はinteger型だが、`Cast::to_int_or_null()`は小数（例: `8.9`）を`(int)`丸めで
	 * 黙って通してしまうため、`exact_int_or_null()`と同じ理由（`meta.total`参照）で厳密な
	 * 整数のみを受け付ける。さらに負値・非現実的に大きい値（プロキシ異常等でのスキーマ崩壊）
	 * を弾き、現実的な税率レンジ（0〜100%）外の値は換算不可としてフェイルクローズする
	 * （レビュー指摘: PR #24。誤った税率でもっともらしいが誤った定価を計算してしまうことを防ぐ）。
	 */
	private static function valid_tax_rate_or_null( mixed $value ): ?int {
		$rate = ColorMeApi::exact_int_or_null( $value );

		return null !== $rate && $rate >= 0 && $rate <= 100 ? $rate : null;
	}
}
