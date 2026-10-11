<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\License;

use CartBridgeJP\Pro\License\LicenseClient;
use CartBridgeJP\Pro\License\LicenseConfig;
use CartBridgeJP\Pro\License\LicenseResult;
use CartBridgeJP\Pro\Tests\Fixtures\FakeLicenseServer;
use CartBridgeJP\Pro\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

/**
 * API Manager への要求の形と、応答のフェイルクローズな解釈（R3-8a）。応答は手元の API Manager 3.6.0 で実測したもの（`tests/fixtures/api-manager/`）。
 */
final class LicenseClientTest extends WP_UnitTestCase {

	private const KEY      = '0123456789abcdef0123456789abcdef01234567';
	private const INSTANCE = 'InstanceIdForTests0123456789abcd';

	private FakeLicenseServer $server;

	private LicenseClient $client;

	public function set_up(): void {
		parent::set_up();
		$this->server = FakeLicenseServer::install();
		$this->client = new LicenseClient( new LicenseConfig( 'https://example.com/', 1000 ) );
	}

	public function test_activate_posts_the_parameters_in_the_body_not_the_url(): void {
		$this->server->fixture( 'activate_success' );

		$this->assertTrue( $this->client->activate( self::KEY, self::INSTANCE )->is_success() );

		$this->assertCount( 1, $this->server->requests );
		$request = $this->server->requests[0];
		$this->assertSame( 'https://example.com/?wc-api=wc-am-api', $request['url'], 'キーを URL（アクセスログ）に載せない' );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( 0, $request['args']['redirection'], '転送先へキーを運ばない' );
		$this->assertTrue( $request['args']['reject_unsafe_urls'] );
		$this->assertSame(
			[
				'api_key'          => self::KEY,
				'instance'         => self::INSTANCE,
				'software_version' => CBJP_PRO_VERSION,
				'wc_am_action'     => 'activate',
				'product_id'       => '1000',
				'object'           => (string) preg_replace( '#^https?://#', '', home_url() ),
			],
			$request['args']['body']
		);
	}

	public function test_deactivate_and_status_send_their_actions(): void {
		$this->server->fixture( 'deactivate_success' )->fixture( 'status_active' );

		$this->client->deactivate( self::KEY, self::INSTANCE );
		$this->assertSame( 'deactivate', $this->server->requests[0]['args']['body']['wc_am_action'] );
		$this->assertSame( self::INSTANCE, $this->server->requests[0]['args']['body']['instance'] );

		$this->client->status( self::KEY, self::INSTANCE );
		$this->assertSame( 'status', $this->server->requests[1]['args']['body']['wc_am_action'] );
		$this->assertSame( self::KEY, $this->server->requests[1]['args']['body']['api_key'] );
	}

	public function test_does_not_send_without_a_configured_server(): void {
		$client = new LicenseClient( new LicenseConfig( '', 1000 ) );

		$this->assertSame( LicenseResult::NOT_CONFIGURED, $client->activate( self::KEY, self::INSTANCE )->code );
		$this->assertSame( LicenseResult::NOT_CONFIGURED, ( new LicenseClient( new LicenseConfig( 'https://example.com/', 0 ) ) )->status( self::KEY, self::INSTANCE )->code );
		$this->assertCount( 0, $this->server->requests );
	}

	/**
	 * @dataProvider server_errors
	 */
	public function test_reads_server_errors( string $fixture, string $action, string $message ): void {
		$this->server->fixture( $fixture );

		$result = $this->client->{$action}( self::KEY, self::INSTANCE );

		$this->assertSame( LicenseResult::SERVER_ERROR, $result->code );
		$this->assertSame( $message, $result->message );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function server_errors(): array {
		return [
			'already activated' => [
				'activate_error_already_activated',
				'activate',
				'Cannot activate license key. The license key has already been activated with the same unique instance ID sent with this request.',
			],
			'unknown key'       => [ 'activate_error_unknown_key', 'activate', 'A customer account does not exist for this license key.' ],
			// 「My Account」へのリンクのタグを除く。
			'no resources'      => [
				'activate_error_no_resources',
				'activate',
				'No licensing resources exist. Login to My Account to verify there are activations remaining, and the license key and product ID are correct.',
			],
			'deactivate'        => [ 'deactivate_error', 'deactivate', 'The license key could not be deactivated.' ],
		];
	}

	public function test_reads_the_status_of_an_active_site(): void {
		$this->server->fixture( 'status_active' );

		$result = $this->client->status( self::KEY, self::INSTANCE );

		$this->assertTrue( $result->is_success() );
		$this->assertSame(
			[
				'activated' => true,
				'expires'   => 'January 9, 2027',
			],
			$result->data
		);
	}

	public function test_reads_the_status_of_an_inactive_site(): void {
		$this->server->fixture( 'status_inactive' );

		$this->assertFalse( $this->client->status( self::KEY, self::INSTANCE )->data['activated'] );
	}

	/**
	 * 期限後はサーバーがそのサイトの有効化を消して `inactive` を返す（実測）。成功の応答として読み、有効化の状態の扱いは `LicenseManager` が決める。
	 */
	public function test_reads_the_status_after_the_update_period(): void {
		$this->server->fixture( 'status_expired' );

		$result = $this->client->status( self::KEY, self::INSTANCE );

		$this->assertTrue( $result->is_success() );
		$this->assertSame(
			[
				'activated' => false,
				'expires'   => 'Expired',
			],
			$result->data
		);
	}

	public function test_reads_a_successful_activation_and_deactivation(): void {
		$this->server->fixture( 'activate_success' )->fixture( 'deactivate_success' );

		$this->assertTrue( $this->client->activate( self::KEY, self::INSTANCE )->is_success() );
		$this->assertTrue( $this->client->deactivate( self::KEY, self::INSTANCE )->is_success() );
	}

	/**
	 * @dataProvider malformed_activation_responses
	 */
	public function test_does_not_read_a_malformed_activation_as_success( mixed $body ): void {
		$this->server->json( $body );

		$this->assertSame( LicenseResult::INVALID_RESPONSE, $this->client->activate( self::KEY, self::INSTANCE )->code );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function malformed_activation_responses(): array {
		$success = FixtureLoader::load( 'api-manager', 'activate_success' );

		return [
			'success as a string'   => [ array_merge( $success, [ 'success' => 'true' ] ) ],
			'success as 1'          => [ array_merge( $success, [ 'success' => 1 ] ) ],
			'success missing'       => [ array_diff_key( $success, [ 'success' => true ] ) ],
			'activated missing'     => [ array_diff_key( $success, [ 'activated' => true ] ) ],
			'activated as a string' => [ array_merge( $success, [ 'activated' => 'true' ] ) ],
			'a deactivation'        => [ FixtureLoader::load( 'api-manager', 'deactivate_success' ) ],
			'a list'                => [ [ true ] ],
			'a scalar'              => [ true ],
		];
	}

	public function test_does_not_read_a_deactivation_without_its_marker(): void {
		$this->server->fixture( 'activate_success' );

		$this->assertSame( LicenseResult::INVALID_RESPONSE, $this->client->deactivate( self::KEY, self::INSTANCE )->code );
	}

	/**
	 * @dataProvider malformed_status_responses
	 */
	public function test_does_not_read_a_malformed_status( mixed $body ): void {
		$this->server->json( $body );

		$this->assertSame( LicenseResult::INVALID_RESPONSE, $this->client->status( self::KEY, self::INSTANCE )->code );
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public static function malformed_status_responses(): array {
		$active = FixtureLoader::load( 'api-manager', 'status_active' );

		return [
			'markers disagree'      => [ array_merge( $active, [ 'status_check' => 'inactive' ] ) ],
			'activated as a string' => [ array_replace_recursive( $active, [ 'data' => [ 'activated' => '1' ] ] ) ],
			'status_check unknown'  => [ array_merge( $active, [ 'status_check' => 'expired' ] ) ],
			'status_check missing'  => [ array_diff_key( $active, [ 'status_check' => true ] ) ],
			'data missing'          => [ array_diff_key( $active, [ 'data' => true ] ) ],
			'success as a string'   => [ array_merge( $active, [ 'success' => 'true' ] ) ],
		];
	}

	public function test_a_status_without_expirations_has_no_expiry_text(): void {
		$active = FixtureLoader::load( 'api-manager', 'status_active' );
		unset( $active['data']['api_key_expirations'] );
		$this->server->json( $active );

		$this->assertNull( $this->client->status( self::KEY, self::INSTANCE )->data['expires'] );
	}

	public function test_reports_a_non_json_body(): void {
		$this->server->raw( '<html>Maintenance</html>' );

		$this->assertSame( LicenseResult::INVALID_RESPONSE, $this->client->activate( self::KEY, self::INSTANCE )->code );
	}

	public function test_reports_a_non_200_response(): void {
		$this->server->json( FixtureLoader::load( 'api-manager', 'activate_success' ), 500 );

		$result = $this->client->activate( self::KEY, self::INSTANCE );

		$this->assertSame( LicenseResult::HTTP_ERROR, $result->code );
		$this->assertSame( 'HTTP 500', $result->message );
	}

	public function test_reports_a_connection_error(): void {
		$this->server->error( 'cURL error 28: Operation timed out' );

		$result = $this->client->activate( self::KEY, self::INSTANCE );

		$this->assertSame( LicenseResult::HTTP_ERROR, $result->code );
		$this->assertSame( 'cURL error 28: Operation timed out', $result->message );
	}

	public function test_cleans_the_server_message(): void {
		$this->server->json(
			[
				'success' => false,
				'error'   => "<b>Bad</b>\x00 key\n\n" . str_repeat( 'x', 400 ),
			]
		);

		$message = $this->client->activate( self::KEY, self::INSTANCE )->message;

		$this->assertStringStartsWith( 'Bad key xxx', $message );
		$this->assertSame( 300, mb_strlen( $message ) );
	}

	public function test_falls_back_to_the_nested_error_message(): void {
		$this->server->json(
			[
				'success' => false,
				'data'    => [ 'error' => 'Nested message.' ],
			]
		);

		$this->assertSame( 'Nested message.', $this->client->activate( self::KEY, self::INSTANCE )->message );
	}
}
