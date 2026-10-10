<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ColorMe\ColorMeApi;
use CartBridgeJP\Adapters\ColorMe\Transform\Cast;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Pro\Adapters\ColorMe\Transform\CouponTransformer;
use CartBridgeJP\Pro\Adapters\ColorMe\Transform\CustomerTransformer;
use CartBridgeJP\Pro\Adapters\ColorMe\Transform\OrderTransformer;
use CartBridgeJP\Pro\Adapters\CommerceAdapter;
use CartBridgeJP\Pro\Adapters\CommerceCapabilities;
use CartBridgeJP\Pro\Canonical\CanonicalCoupon;
use CartBridgeJP\Pro\Canonical\CanonicalCustomer;
use CartBridgeJP\Pro\Canonical\CanonicalOrder;
use CartBridgeJP\Pro\Woo\CommerceWarningCode;
use CartBridgeJP\Pro\Woo\Support\OrderMethodMap;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- `CommerceAdapter` のシグネチャに合わせる（クーポンは送らない）。

/**
 * カラーミーショップの顧客・受注・クーポン（R3-6c1 で `ColorMeAdapter` から移した。本体は移しただけ）。
 * 認証済みの API と応答の共通処理は無料版のアダプタの `ColorMeAdapter::api()`（`ColorMeApi`）、プランは `is_premium_plan()` を使う
 * （`docs/03-design-decisions.md` §10.0「Pro が使ってよい無料版の API」）。
 *
 * `push_coupon()` は ColorMe 側の制約（クーポンは読取専用）で恒久的に `UnsupportedOperationException`。
 */
final class ColorMeCommerceAdapter extends CommerceAdapter {

	/**
	 * `GET /sales.json` の全量走査の起点に使う日付（カラーミーのサービス開始より確実に前）。
	 */
	private const HISTORY_FLOOR = '2000-01-01';

	/**
	 * `payments.json`/`deliveries.json` から組み立てた名称マップを持つ`OrderTransformer`。
	 * `AdapterRegistry::get()`はプラットフォーム単位でアダプタインスタンスを静的キャッシュするため、
	 * このキャッシュの実際の寿命は「同一PHPプロセス内で処理された全ジョブアクション」（Action
	 * Schedulerが1リクエストで複数アクションをまとめて実行する場合はページ横断で再利用される）。
	 * アクション毎に新規プロセスが割り当てられる実行環境ではプロセス毎に再取得される。
	 */
	private ?OrderTransformer $order_transformer = null;

	/**
	 * `push_order()`の明細価格解決にのみ使う店舗税区分（`shop.json`の`tax_type`）。import方向の
	 * `OrderTransformer::transform()`はこのデータを使わないため、`$order_transformer`（全fetch系
	 * メソッドが経由する共有インスタンス）には持たせず、push時にのみ独立して遅延取得・
	 * インスタンス単位でキャッシュする（`$order_transformer`/`$product_transformer`と同じ理由）。
	 */
	private ?string $order_tax_type = null;

	private bool $order_tax_type_loaded = false;

	public function __construct(
		private readonly ColorMeAdapter $adapter,
		private readonly Logger $logger = new Logger()
	) {}

	public function id(): string {
		return ColorMeAdapter::ID;
	}

	public function capabilities(): CommerceCapabilities {
		return new CommerceCapabilities(
			can_fetch_customers: true,
			can_update_customer: true,
			// `POST /v1/sales` はプレミアムプラン契約のショップのみ利用可（swagger.json）。
			can_create_order: $this->adapter->is_premium_plan(),
			has_coupons: true,
			// クーポンは読取のみ。
			can_create_coupon: false,
			// D24: プレミアムのテストショップが無く実 API で未検証。UI が Beta 表示と既定オフにする。項目を出すかは `can_create_order`（プラン）が決める。
			order_export_beta: true
		);
	}

	/**
	 * 決済方法の候補（`GET /payments.json`。E2-1・D19）。
	 */
	public function payment_candidates(): array {
		return self::id_name_candidates( $this->api()->id_name_map( 'payments.json', 'payments' ) );
	}

	/**
	 * 配送方法の候補（`GET /deliveries.json`）。
	 */
	public function shipping_candidates(): array {
		return self::id_name_candidates( $this->api()->id_name_map( 'deliveries.json', 'deliveries' ) );
	}

	/**
	 * 注文ステータスの候補（`OrderTransformer::status()` が返しうる 4 つの正規化後の値の固定リスト。API 呼び出し不要）。
	 */
	public function status_candidates(): array {
		return self::fixed_status_candidates();
	}

	private function api(): ColorMeApi {
		return $this->adapter->api();
	}

	public function fetch_customers( Cursor $cursor ): Page {
		$offset      = (int) $cursor->get( 'offset', 0 );
		$body        = $this->api()->client()->get(
			'customers.json',
			[
				'limit'  => ColorMeApi::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw         = $this->api()->list_from( $body, 'customers' );
		$transformer = new CustomerTransformer();
		$items       = $this->api()->transform_rows( $raw, static fn ( array $item ): ?CanonicalCustomer => $transformer->transform( $item ), 'customer' );
		$row_total   = $this->api()->total_from_meta( $body );

		// `meta.total`は生レスポンスの顧客件数であり、`CustomerTransformer`が非会員・email欠損の
		// 行をnullで除外した後の`items`件数とは一致しない（`fetch_stocks()`のバリエーション展開と
		// 同種の乖離）。ページング終端の判定にだけ使い、進捗率の分母として`Page`側には報告しない。
		return new Page( $items, $this->api()->next_cursor( $offset, $this->api()->raw_row_count( $body, 'customers' ), $row_total ), null );
	}

	/**
	 * 全量走査。`after`未指定だと直近7日間しか検索されないため
	 * （03 §9 #14）、`HISTORY_FLOOR`を明示して全履歴を対象にする。
	 */
	public function fetch_orders( Cursor $cursor ): Page {
		$offset = (int) $cursor->get( 'offset', 0 );
		$body   = $this->api()->client()->get(
			'sales.json',
			[
				'after'  => self::HISTORY_FLOOR,
				'limit'  => ColorMeApi::PAGE_SIZE,
				'offset' => $offset,
			]
		);
		$raw    = $this->api()->list_from( $body, 'sales' );
		// `order_transformer()`（初回呼び出し時にpayments.json/deliveries.jsonを叩く）を
		// 行単位のtry節の外で解決する。理由は`fetch_order_by_remote_id()`と同じ。
		$transformer = $this->order_transformer();
		$items       = $this->api()->transform_rows( $raw, static fn ( array $item ): CanonicalOrder => $transformer->transform( $item ), 'order' );
		$row_total   = $this->api()->total_from_meta( $body );

		// customer同様、`OrderTransformer`が変換失敗行（id/make_date/total_price欠損）を除外した
		// 後の`items`件数は`meta.total`（生の受注件数）と一致しうるとは限らない。
		return new Page( $items, $this->api()->next_cursor( $offset, $this->api()->raw_row_count( $body, 'sales' ), $row_total ), null );
	}

	/**
	 * `GET /shop_coupons.json` にページングパラメータが無い（swagger）ため常に1ページで完結する。
	 */
	public function fetch_coupons( Cursor $cursor ): Page {
		$body        = $this->api()->client()->get( 'shop_coupons.json' );
		$raw         = $this->api()->list_from( $body, 'shop_coupons' );
		$transformer = new CouponTransformer();
		$items       = $this->api()->transform_rows( $raw, static fn ( array $item ): ?CanonicalCoupon => $transformer->transform( $item ), 'coupon' );

		return new Page( $items, null, count( $items ) );
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		$customer = $this->api()->fetch_single( 'customers/' . rawurlencode( $remote_id ) . '.json', 'customer' );

		if ( null === $customer ) {
			return null;
		}

		try {
			return ( new CustomerTransformer() )->transform( $customer );
		} catch ( Throwable $exception ) {
			$this->api()->log_transform_failure( 'customer', $customer, $exception );

			return null;
		}
	}

	/**
	 * `GET /sales/{id}.json`（単一取得）。一覧の`GET /sales.json`と違い`after`/`before`による
	 * 直近7日の暗黙の絞り込みを受けない（03 §9 #14）ため、古い受注でも取得できる。
	 */
	public function fetch_order_by_remote_id( string $remote_id ): ?CanonicalOrder {
		$sale = $this->api()->fetch_single( 'sales/' . rawurlencode( $remote_id ) . '.json', 'sale' );

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
			$this->api()->log_transform_failure( 'order', $sale, $exception );

			return null;
		}
	}

	/**
	 * `push_product()`と同じ規約: 本体リクエストの失敗はここで捕まえず`Sync\Exporter`の汎用catchへ
	 * 委ねる（1件の異常でページ全体を止めない）。顧客はColorMeのAPI上POST/PUTとも1リクエストで
	 * 完結し（`push_product()`のような追いPUT/バリエーション/画像の多段リクエストが無い）ため、
	 * 部分完了の警告分類は不要。新規作成に必須の`pref_id`/`postal`/`address1`/`tel`が
	 * Woo顧客の請求先情報から解決できない場合は`CustomerTransformer::to_create_payload()`が
	 * `null`を返すため、送信自体を行わずフェイルクローズでスキップする
	 * （`CommerceWarningCode::CUSTOMER_REQUIRED_FIELD_MISSING`。422を送って恒久的な4xxを積み重ねない）。
	 * 更新も名前と住所が無いと422になる（swagger と違う。issue #100）ので、`to_update_payload()`の`null`で同じくスキップする。
	 * remote_id は空で返す（`push_order()`の更新スキップと同じ形。`Sync\Exporter`は既存の mapping に触れず checksum もキャッシュしないので、
	 * 店舗が住所を補えば次回のエクスポートで送る）。
	 */
	public function push_customer( CanonicalCustomer $customer, ?string $remote_id ): PushResult {
		$transformer = new CustomerTransformer();
		$payload     = null === $remote_id ? $transformer->to_create_payload( $customer ) : $transformer->to_update_payload( $customer );

		if ( null === $payload ) {
			return new PushResult( '', PushResult::OPERATION_SKIPPED, [ CommerceWarningCode::CUSTOMER_REQUIRED_FIELD_MISSING ] );
		}

		if ( null === $remote_id ) {
			$body      = $this->api()->client()->post( 'customers.json', [ 'customer' => $payload ] );
			$operation = PushResult::OPERATION_CREATED;
		} else {
			$body      = $this->api()->client()->put( "customers/{$remote_id}.json", [ 'customer' => $payload ] );
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
			return new PushResult( '', PushResult::OPERATION_SKIPPED, array_merge( [ CommerceWarningCode::ORDER_UPDATE_NOT_SUPPORTED ], self::informational_warnings( $order ) ) );
		}

		// `order_transformer()`（import方向`transform()`と共有）は`payments.json`/`deliveries.json`の
		// 名称マップを構築するが、`to_create_payload()`はこれらを使わない。共有インスタンスを経由すると
		// exportジョブでも無駄な2リクエストが発生するため、ここでは独立した軽量インスタンスを使う。
		$result = ( new OrderTransformer() )->to_create_payload( $order, new OrderMethodMap( new MethodMap( ColorMeAdapter::ID ) ), $this->order_tax_type() );

		// discount/feeの情報提供警告はブロック要因ではないため、push成功・スキップのいずれでも
		// 同じ理由（割引・手数料を運ぶAPIフィールドが無い）で積む。
		$informational_warnings = self::informational_warnings( $order );

		if ( null === $result['payload'] ) {
			return new PushResult( '', PushResult::OPERATION_SKIPPED, array_merge( self::order_skip_warnings( $result ), $informational_warnings ) );
		}

		// 過去のWoo受注を複製するのであって新規注文ではないため、既定（在庫引き当て）のまま
		// だとColorMe側の現在庫を実売と無関係に消費してしまう（在庫同期は別途push_stock()の責務）。
		$body = $this->api()->client()->post( 'sales.json?reserve_stocks=false', [ 'sale' => $result['payload'] ] );

		$order_remote_id = Cast::to_string_or_null( $body['sale']['id'] ?? null );

		if ( null === $order_remote_id ) {
			// `push_product()`/`push_customer()`と同じ理由: remote_idが取得できない「成功」応答を
			// そのまま返すとmappingsに書き込めず、次回exportが常に新規作成扱いになり重複が
			// 発生し続ける。
			throw new RuntimeException( 'ColorMe order push response is missing the sale id.' );
		}

		// `ORDER_PLACED_AT_NOT_PRESERVED`は新規作成が成功した場合のみ（=このタイミングで初めて
		// 実際に日時が失われる事象が発生するため）。skip経路では新たに何も作成されないので付けない。
		$informational_warnings[] = CommerceWarningCode::ORDER_PLACED_AT_NOT_PRESERVED;

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
			$warnings[] = CommerceWarningCode::ORDER_DISCOUNT_NOT_PUSHED;
		}

		if ( OrderTransformer::has_non_representable_charges( $order ) ) {
			$warnings[] = CommerceWarningCode::ORDER_FEE_NOT_PUSHED;
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
			$warnings[] = CommerceWarningCode::ORDER_LINE_ITEMS_EMPTY;
		}

		if ( $result['line_price_unresolved'] ) {
			$warnings[] = CommerceWarningCode::ORDER_LINE_PRICE_UNRESOLVED;
		}

		if ( null !== $result['unmapped_payment_method_id'] ) {
			$warnings[] = WarningCode::with_detail( CommerceWarningCode::PAYMENT_METHOD_UNMAPPED, $result['unmapped_payment_method_id'] );
		}

		if ( null !== $result['unmapped_shipping_method_id'] ) {
			$warnings[] = WarningCode::with_detail( CommerceWarningCode::SHIPPING_METHOD_UNMAPPED, $result['unmapped_shipping_method_id'] );
		}

		if ( $result['shipping_address_incomplete'] ) {
			$warnings[] = CommerceWarningCode::ORDER_SHIPPING_ADDRESS_INCOMPLETE;
		}

		return $warnings;
	}

	public function push_coupon( CanonicalCoupon $coupon, ?string $remote_id ): PushResult {
		throw new UnsupportedOperationException( ColorMeAdapter::ID, __FUNCTION__ );
	}

	private function order_transformer(): OrderTransformer {
		if ( null === $this->order_transformer ) {
			$this->order_transformer = new OrderTransformer(
				$this->api()->id_name_map( 'payments.json', 'payments' ),
				$this->api()->id_name_map( 'deliveries.json', 'deliveries' )
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
			$shop = $this->api()->client()->get( 'shop.json' )['shop'] ?? [];
			$shop = is_array( $shop ) ? $shop : [];

			$this->order_tax_type        = Cast::to_string_or_null( $shop['tax_type'] ?? null );
			$this->order_tax_type_loaded = true;
		}

		return $this->order_tax_type;
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
	private static function fixed_status_candidates(): array {
		return [
			[
				'id'   => 'pending',
				'name' => __( 'Unpaid', 'cart-bridge-jp-pro' ),
			],
			[
				'id'   => 'processing',
				'name' => __( 'Paid (not shipped)', 'cart-bridge-jp-pro' ),
			],
			[
				'id'   => 'completed',
				'name' => __( 'Shipped', 'cart-bridge-jp-pro' ),
			],
			[
				'id'   => 'cancelled',
				'name' => __( 'Cancelled', 'cart-bridge-jp-pro' ),
			],
		];
	}
}
