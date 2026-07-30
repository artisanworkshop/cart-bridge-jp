<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters\ColorMe;

use CartBridgeJP\Adapters\Capabilities;
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
use CartBridgeJP\Support\TokenStore;

/**
 * カラーミーショップアダプタ（`01-plan-colorme.md`）。
 *
 * このクラスは F1-2 時点では接続まわり（capabilities/connection_fields/test_connection）のみを
 * 実装するシェルであり、fetch系・push系メソッドは F1-5（インポート結合）・E4-3（エクスポート）で順次実装する。
 * それまでは UnsupportedOperationException を投げる（Capabilities宣言と矛盾しないための防御）。
 */
final class ColorMeAdapter implements PlatformAdapter {

	public const ID = 'colorme';

	private const RATE_LIMIT_PER_MINUTE = 100;

	public function __construct(
		private readonly TokenStore $token_store = new TokenStore( self::ID )
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
			true,  // can_create_order
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
		$extras = is_array( $payload['extras'] ?? null ) ? $payload['extras'] : [];
		unset( $extras['contract_plan'] );

		if ( isset( $shop['contract_plan'] ) && is_string( $shop['contract_plan'] ) ) {
			$extras['contract_plan'] = $shop['contract_plan'];
		}

		$payload['extras'] = $extras;

		if ( [] === $extras ) {
			unset( $payload['extras'] );
		}

		$this->token_store->save( $payload );

		$shop_name = is_string( $shop['title'] ?? null ) && '' !== $shop['title']
			? $shop['title']
			: null;

		return ConnectionResult::success( $shop_name );
	}

	public function fetch_products( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	/**
	 * @return array<int,CanonicalCategory>
	 */
	public function fetch_categories(): array {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	/**
	 * @return array<int,CanonicalTag>
	 */
	public function fetch_tags(): array {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_customers( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_orders( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_stocks( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_coupons( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_reviews( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	/**
	 * @return array<int,CanonicalOrder>
	 */
	public function fetch_latest_orders( int $limit ): array {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
	}

	public function fetch_customer_by_remote_id( string $remote_id ): ?CanonicalCustomer {
		throw new UnsupportedOperationException( self::ID, __FUNCTION__ );
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
}
