<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Support\TokenStore;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\ProductReader;
use CartBridgeJP\Woo\Support\MethodMap;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;
use WC_Tax;

/**
 * Wooの実データ → `ProductReader` → `ColorMeAdapter::push_product()` を通して、ColorMeへ送られる金額を
 * 検証する結合テスト（Reader単体・Transformer/Adapter単体のテストでは、`CanonicalProduct`の配列
 * プロパティの意味論のズレ＝層間ズレを検出できないため。issue #59 / #60）。
 */
final class ColorMeExportPricesTest extends WooTestCase {

	private const PLATFORM = 'colorme';

	/**
	 * 税計算ON・税抜入力・基準所在地JP・税率10%のWoo店舗、税込設定（`tax_type=included`）のColorMe店舗。
	 * Red(定価1000・セール800)/Blue(定価1000)のバリエーション商品が、税込の売価（Red=880・定価1100、
	 * Blue=1100）としてColorMeへ送られる。
	 */
	public function test_tax_exclusive_woo_store_with_on_sale_variation_pushes_tax_inclusive_prices(): void {
		update_option( 'woocommerce_currency', 'JPY' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		update_option( 'woocommerce_default_country', 'JP' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );

		WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => 'JP',
				'tax_rate_state'    => '',
				'tax_rate'          => '10.0000',
				'tax_rate_name'     => 'JP',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_class'    => '',
			]
		);

		$parent_id = $this->create_variable_product();

		$reader = new ProductReader( self::PLATFORM, new MethodMap( self::PLATFORM ), $this->mappings );
		$item   = $reader->query( Cursor::start(), [ $parent_id ] )->items[0];

		$this->assertFalse( \CartBridgeJP\Woo\WarningCode::indicates_export_blocking( $item->warnings ) );

		$captured = [];
		$adapter  = $this->make_adapter( $captured );
		$adapter->push_product( $item->item, null );

		$red = $this->find_put( $captured, 'products/900/variants/9001.json' );
		$this->assertSame( 880, $red['variant']['option_price'] );
		$this->assertSame( 1100, $red['variant']['option_market_price'] );

		$blue = $this->find_put( $captured, 'products/900/variants/9002.json' );
		$this->assertSame( 1100, $blue['variant']['option_price'] );
		$this->assertArrayNotHasKey( 'option_market_price', $blue['variant'] );

		// 商品レベルの代表価格（最安の定価。セールは焼き付けない）も税込へ換算されている。
		$create = $this->find_request( $captured, 'POST', 'products.json' );
		$this->assertSame( 1100, $create['product']['sales_price'] );
	}

	private function create_variable_product(): int {
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Color' );
		$attribute->set_options( [ 'Red', 'Blue' ] );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent = new WC_Product_Variable();
		$parent->set_name( 'Shirt' );
		$parent->set_attributes( [ $attribute ] );
		$parent_id = $parent->save();

		foreach ( [
			'Red'  => '800',
			'Blue' => null,
		] as $color => $sale_price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_attributes( [ 'color' => $color ] );
			$variation->set_sku( 'VAR-' . strtoupper( $color ) );
			$variation->set_regular_price( '1000' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 5 );

			if ( null !== $sale_price ) {
				$variation->set_sale_price( $sale_price );
			}

			$variation->save();
		}

		WC_Product_Variable::sync( $parent_id );

		return $parent_id;
	}

	/**
	 * @param array<int,array{method:string,url:string,body:?array<string,mixed>}> $captured
	 */
	private function make_adapter( array &$captured ): ColorMeAdapter {
		$token_store = new TokenStore( 'test-colorme-export-prices-' . wp_generate_uuid4() );
		$token_store->save( [ 'access_token' => 'token' ] );

		$product_gets = 0;

		add_filter(
			'pre_http_request',
			function ( $preempt, array $parsed_args, string $url ) use ( &$captured, &$product_gets ) {
				$method  = strtoupper( (string) ( $parsed_args['method'] ?? 'GET' ) );
				$body    = is_string( $parsed_args['body'] ?? null ) ? json_decode( $parsed_args['body'], true ) : null;
				$request = [
					'method' => $method,
					'url'    => $url,
					'body'   => is_array( $body ) ? $body : null,
				];

				$captured[] = $request;

				$respond = static fn ( array $payload, int $status = 200 ): array => [
					'response' => [ 'code' => $status ],
					'headers'  => [ 'content-type' => 'application/json' ],
					'body'     => (string) wp_json_encode( $payload ),
				];

				if ( 'GET' === $method && str_contains( $url, 'shop.json' ) ) {
					return $respond( [ 'shop' => [ 'tax_type' => 'included' ] ] );
				}

				if ( 'POST' === $method && str_ends_with( $url, 'products.json' ) ) {
					return $respond( [ 'product' => [ 'id' => 900 ] ] );
				}

				if ( 'PUT' === $method && str_ends_with( $url, 'products/900.json' ) ) {
					return $respond( [ 'product' => [ 'id' => 900 ] ] );
				}

				if ( 'POST' === $method && str_contains( $url, 'products/900/options.json' ) ) {
					return $respond( [ 'option' => [ 'id' => 1 ] ], 201 );
				}

				if ( 'GET' === $method && str_ends_with( $url, 'products/900.json' ) ) {
					++$product_gets;

					// 1回目: 軸追加前（バリエーション無し）。2回目: 軸追加で自動生成された状態。
					return $respond(
						[
							'product' => [
								'id'       => 900,
								'options'  => [],
								'variants' => 1 === $product_gets ? [] : [
									[
										'id'            => 9001,
										'option1_value' => 'Red',
										'option1'       => [
											'id'    => 1,
											'name'  => 'Color',
											'value' => 'Red',
										],
										'option2'       => null,
									],
									[
										'id'            => 9002,
										'option1_value' => 'Blue',
										'option1'       => [
											'id'    => 1,
											'name'  => 'Color',
											'value' => 'Blue',
										],
										'option2'       => null,
									],
								],
							],
						]
					);
				}

				if ( 'PUT' === $method && str_contains( $url, 'products/900/variants/' ) ) {
					return $respond( [ 'variant' => [ 'id' => 1 ] ] );
				}

				return new \WP_Error( 'unexpected_request', "Unhandled ColorMe request: {$method} {$url}" );
			},
			10,
			3
		);

		return new ColorMeAdapter( $token_store );
	}

	/**
	 * @param array<int,array{method:string,url:string,body:?array<string,mixed>}> $captured
	 * @return array<string,mixed>
	 */
	private function find_put( array $captured, string $url_needle ): array {
		return $this->find_request( $captured, 'PUT', $url_needle );
	}

	/**
	 * @param array<int,array{method:string,url:string,body:?array<string,mixed>}> $captured
	 * @return array<string,mixed>
	 */
	private function find_request( array $captured, string $method, string $url_needle ): array {
		foreach ( $captured as $request ) {
			if ( $method === $request['method'] && str_contains( $request['url'], $url_needle ) && null !== $request['body'] ) {
				return $request['body'];
			}
		}

		$this->fail( "No captured {$method} request matching {$url_needle}" );
	}
}
