<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\ColorMe\ColorMeApi;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\TokenStore;
use RuntimeException;
use WP_UnitTestCase;

/**
 * `ColorMeApi`（R3-6b1。`ColorMeAdapter` から切り出した、認証済みの呼び出しと応答の共通処理）。
 * 振る舞いの大半は `ColorMeAdapterTest` がアダプタ経由で確かめている。ここでは Pro アドオンが直接使う口としての契約を固定する。
 */
final class ColorMeApiTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * @return array{0:ColorMeAdapter,1:TokenStore}
	 */
	private function make_adapter(): array {
		$token_store = new TokenStore( 'test-colorme-api-' . wp_generate_uuid4() );

		return [ new ColorMeAdapter( $token_store ), $token_store ];
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function respond( int $status, array $body ): void {
		add_filter(
			'pre_http_request',
			static fn (): array => [
				'headers'  => [],
				'body'     => (string) wp_json_encode( $body ),
				'response' => [
					'code'    => $status,
					'message' => '',
				],
				'cookies'  => [],
			]
		);
	}

	public function test_api_is_memoized_per_adapter(): void {
		[ $adapter ] = $this->make_adapter();

		$this->assertSame( $adapter->api(), $adapter->api() );
	}

	public function test_client_reads_the_token_on_every_call(): void {
		[ $adapter, $token_store ] = $this->make_adapter();

		// 未接続でも口自体は取れる。クライアントを取った位置で未接続を知らせる。
		$api = $adapter->api();

		try {
			$api->client();
			$this->fail( '未接続なのに例外が出ませんでした。' );
		} catch ( ApiException $exception ) {
			$this->assertTrue( $exception->context()['not_connected'] ?? false );
		}

		$token_store->save( [ 'access_token' => 'token' ] );

		$api->client();
		$this->addToAssertionCount( 1 );
	}

	public function test_fetch_single_returns_null_for_404(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		$this->respond( 404, [ 'errors' => [] ] );

		$this->assertNull( $adapter->api()->fetch_single( 'customers/1.json', 'customer' ) );
	}

	public function test_fetch_single_throws_when_the_envelope_is_missing(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		$this->respond( 200, [ 'unexpected' => [] ] );

		$this->expectException( RuntimeException::class );

		$adapter->api()->fetch_single( 'customers/1.json', 'customer' );
	}

	public function test_fetch_single_returns_the_envelope(): void {
		[ $adapter, $token_store ] = $this->make_adapter();
		$token_store->save( [ 'access_token' => 'token' ] );
		$this->respond( 200, [ 'customer' => [ 'id' => 1 ] ] );

		$this->assertSame( [ 'id' => 1 ], $adapter->api()->fetch_single( 'customers/1.json', 'customer' ) );
	}

	public function test_paging_helpers(): void {
		[ $adapter ] = $this->make_adapter();
		$api         = $adapter->api();
		$body        = [
			'customers' => [ [ 'id' => 1 ], 'broken', [ 'id' => 2 ] ],
			'meta'      => [ 'total' => 5 ],
		];

		$this->assertSame( [ [ 'id' => 1 ], [ 'id' => 2 ] ], $api->list_from( $body, 'customers' ) );
		$this->assertSame( 3, $api->raw_row_count( $body, 'customers' ) );
		$this->assertSame( 5, $api->total_from_meta( $body ) );
		$this->assertSame( 3, $api->next_cursor( 0, 3, 5 )?->get( 'offset' ) );
		$this->assertNull( $api->next_cursor( 3, 2, 5 ) );
		$this->assertNull( $api->next_cursor( 0, 0, 5 ) );
		$this->assertSame( 50, ColorMeApi::PAGE_SIZE );
	}

	public function test_list_from_rejects_a_missing_envelope(): void {
		[ $adapter ] = $this->make_adapter();

		$this->expectException( RuntimeException::class );

		$adapter->api()->list_from( [], 'customers' );
	}

	/**
	 * Pro が渡すコールバックが配列以外を返しても、その行の変換失敗として飛ばし、ほかの行は残す（ページ全体を落とさない）。
	 */
	public function test_transform_rows_flat_skips_a_row_whose_callback_returns_a_non_array(): void {
		[ $adapter ] = $this->make_adapter();
		$rows        = [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3 ] ];

		$result = $adapter->api()->transform_rows_flat(
			$rows,
			static fn ( array $raw ): mixed => 2 === $raw['id'] ? 'not an array' : [ 'row-' . $raw['id'] ],
			'customer'
		);

		$this->assertSame( [ 'row-1', 'row-3' ], $result );
	}
}
