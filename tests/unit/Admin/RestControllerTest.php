<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Admin;

use CartBridgeJP\Adapters\AdapterRegistry;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

final class RestControllerTest extends WP_UnitTestCase {

	private WP_REST_Server $server;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $this->server );

		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
	}

	public function tear_down(): void {
		remove_all_filters( 'cbjp/adapters/register' );
		AdapterRegistry::reset_cache();
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WP core自身が使うグローバル変数名。
		parent::tear_down();
	}

	public function test_get_connections_is_empty_when_no_adapters_registered(): void {
		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
	}

	public function test_get_connections_lists_registered_adapters(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data );
		$this->assertSame( 'mock', $data[0]['platform'] );
		$this->assertFalse( $data[0]['connected'] );
	}

	public function test_unauthenticated_request_is_forbidden(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/cbjp/v1/connections' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_dry_run_start_and_poll_round_trip(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'dry_run',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$run_id = $response->get_data()['run_id'];
		$this->assertIsString( $run_id );

		$poll_request  = new WP_REST_Request( 'GET', "/cbjp/v1/runs/{$run_id}" );
		$poll_response = $this->server->dispatch( $poll_request );

		$this->assertSame( 200, $poll_response->get_status() );
		$this->assertSame( $run_id, $poll_response->get_data()['run_id'] );
	}

	public function test_import_type_is_not_yet_implemented(): void {
		add_filter(
			'cbjp/adapters/register',
			static function ( array $adapters ) {
				$adapters['mock'] = new MockPlatformAdapter();

				return $adapters;
			}
		);
		AdapterRegistry::reset_cache();

		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'import',
				'platform' => 'mock',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 501, $response->get_status() );
	}

	public function test_start_run_returns_404_for_an_unknown_platform(): void {
		$request = new WP_REST_Request( 'POST', '/cbjp/v1/runs' );
		$request->set_body_params(
			[
				'type'     => 'dry_run',
				'platform' => 'not-a-real-platform',
				'entities' => [ 'category' ],
			]
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'cbjp_unknown_platform', $response->as_error()->get_error_code() );
	}
}
