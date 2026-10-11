<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Core;

use WP_UnitTestCase;

/**
 * Pro の zip の検査（`bin/check-pro-zip.php`。`bin/build-zip.sh --plugin cart-bridge-jp-pro` が呼ぶ。R3-8c・PR #125 G1）。
 * `pro-v*` のタグのリリースは CI のテストを通らないので、版 1.0.0 以上のリリースの設定（接続先・製品 ID・`Update URI`）と、
 * 無料版の名前空間を同梱しないことを zip を作るときに止める。スクリプトはルートの `bin/`（テストのサイトでは `wp-content/cbjp-dev`）にある。
 */
final class ProZipCheckTest extends WP_UnitTestCase {

	private const CONFIGURED = [
		'version'    => '1.0.0',
		'api_url'    => 'https://store.example.com/',
		'product_id' => '1000',
		'update_uri' => 'https://store.example.com/cart-bridge-jp-pro/',
	];

	/** @var array<int,string> */
	private array $dirs = [];

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		require_once WP_CONTENT_DIR . '/cbjp-dev/bin/check-pro-zip.php';
	}

	public function tear_down(): void {
		foreach ( $this->dirs as $dir ) {
			self::remove_dir( $dir );
		}
		parent::tear_down();
	}

	public function test_the_current_main_file_passes(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- リポジトリ内の PHP ファイルを読む（JSON ではない）。
		$this->assertSame( [], cbjp_zipcheck_release_problems( (string) file_get_contents( CBJP_PRO_FILE ) ) );
	}

	public function test_reads_the_header_like_wordpress(): void {
		$source = self::main_source( self::CONFIGURED );

		$this->assertSame( get_file_data( CBJP_PRO_FILE, [ 'Version' => 'Version' ] )['Version'], cbjp_zipcheck_header( (string) file_get_contents( CBJP_PRO_FILE ), 'Version' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同上。
		$this->assertSame( '1.0.0', cbjp_zipcheck_header( $source, 'Version' ) );
		$this->assertSame( 'https://store.example.com/cart-bridge-jp-pro/', cbjp_zipcheck_header( $source, 'Update URI' ) );
		$this->assertSame( '', cbjp_zipcheck_header( self::main_source( [ 'update_uri' => '' ] + self::CONFIGURED ), 'Update URI' ) );
	}

	public function test_a_configured_release_passes(): void {
		$this->assertSame( [], cbjp_zipcheck_release_problems( self::main_source( self::CONFIGURED ) ) );
		$this->assertSame( [], cbjp_zipcheck_release_problems( self::main_source( [ 'update_uri' => 'https://STORE.example.com/' ] + self::CONFIGURED ) ), 'host の大文字小文字は区別しない' );
	}

	public function test_stops_an_unconfigured_release(): void {
		$problems = cbjp_zipcheck_release_problems(
			self::main_source(
				[
					'api_url'    => '',
					'product_id' => '0',
					'update_uri' => '',
				] + self::CONFIGURED
			)
		);

		$this->assertCount( 3, $problems );
		$this->assertStringContainsString( 'CBJP_PRO_LICENSE_API_URL', $problems[0] );
		$this->assertStringContainsString( 'CBJP_PRO_LICENSE_PRODUCT_ID', $problems[1] );
		$this->assertStringContainsString( 'Update URI header is required', $problems[2] );
	}

	/**
	 * @dataProvider broken_release_settings
	 *
	 * @param array<string,string> $settings
	 */
	public function test_stops_a_release_with_a_broken_setting( array $settings, string $expected ): void {
		$problems = cbjp_zipcheck_release_problems( self::main_source( $settings + self::CONFIGURED ) );

		// 接続先の URL が使えないときは、`Update URI` も接続先と合わないとして一緒に出る。
		$this->assertNotSame( [], array_filter( $problems, static fn ( string $problem ): bool => str_contains( $problem, $expected ) ), implode( ' / ', $problems ) );
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function broken_release_settings(): array {
		return [
			'http license server'        => [ [ 'api_url' => 'http://store.example.com/' ], 'CBJP_PRO_LICENSE_API_URL' ],
			'credentials in the url'     => [ [ 'api_url' => 'https://user:pass@store.example.com/' ], 'CBJP_PRO_LICENSE_API_URL' ],
			'product id with junk'       => [ [ 'product_id' => '0042' ], 'CBJP_PRO_LICENSE_PRODUCT_ID' ],
			'product id overflow'        => [ [ 'product_id' => '9999999999999999999' ], 'CBJP_PRO_LICENSE_PRODUCT_ID' ],
			'http update uri'            => [ [ 'update_uri' => 'http://store.example.com/cart-bridge-jp-pro/' ], 'Update URI header must be' ],
			'update uri on another host' => [ [ 'update_uri' => 'https://updates.example.net/' ], 'Update URI header must be' ],
		];
	}

	public function test_lets_a_pre_release_leave_the_settings_empty_but_not_mismatched(): void {
		$empty = [
			'version'    => '0.2.0',
			'api_url'    => '',
			'product_id' => '0',
			'update_uri' => '',
		];

		$this->assertSame( [], cbjp_zipcheck_release_problems( self::main_source( $empty ) ) );
		$this->assertCount( 1, cbjp_zipcheck_release_problems( self::main_source( [ 'update_uri' => 'https://store.example.com/' ] + $empty ) ), 'ヘッダーを入れたら版にかかわらず接続先と合わせる' );
	}

	public function test_stops_a_version_header_that_differs_from_the_constant(): void {
		$source = str_replace( "define( 'CBJP_PRO_VERSION', '1.0.0' );", "define( 'CBJP_PRO_VERSION', '1.0.1' );", self::main_source( self::CONFIGURED ) );

		$problems = cbjp_zipcheck_release_problems( $source );

		$this->assertCount( 1, $problems );
		$this->assertStringContainsString( 'differ', $problems[0] );
	}

	/**
	 * `define()` は PHP のトークンで読む。コメントアウトした設定は読まない（PR #125 G2-B2: 正規表現では、実際の既定値が空でもコメントの中の
	 * 設定を読んで 1.0.0 のリリースを通しえた）。同じ名前が 2 回ある・値が式なら読めない（null）。
	 */
	public function test_reads_defines_from_tokens_and_ignores_comments(): void {
		$commented = "<?php\n// define( 'CBJP_PRO_LICENSE_API_URL', 'https://store.example.com/' );\n/* define( 'CBJP_PRO_LICENSE_PRODUCT_ID', 1000 ); */\n"
			. "define( 'CBJP_PRO_LICENSE_API_URL', '' );\ndefine( 'CBJP_PRO_LICENSE_PRODUCT_ID', 0 );\n";

		$this->assertSame( '', cbjp_zipcheck_define( $commented, 'CBJP_PRO_LICENSE_API_URL' ) );
		$this->assertSame( '0', cbjp_zipcheck_define( $commented, 'CBJP_PRO_LICENSE_PRODUCT_ID' ) );
		$this->assertSame( 'https://x.example.com/', cbjp_zipcheck_define( "<?php\n\\define ( 'A', 'https://x.example.com/' ) ;\n", 'A' ) );
		$this->assertSame( "it's", cbjp_zipcheck_define( "<?php define( 'A', 'it\\'s' );\n", 'A' ) );
		$this->assertNull( cbjp_zipcheck_define( "<?php define( 'A', 'x' );\ndefine( 'A', 'y' );\n", 'A' ), '2 回ある' );
		$this->assertNull( cbjp_zipcheck_define( "<?php define( 'A', \"x\" );\n", 'A' ), '二重引用符は読まない' );
		$this->assertNull( cbjp_zipcheck_define( "<?php define( 'A', B . 'x' );\n", 'A' ), '式は読まない' );
		$this->assertNull( cbjp_zipcheck_define( "<?php \$o->define( 'A', 'x' );\n", 'A' ), 'メソッドは定数の定義ではない' );
		// 実際に効く定義が式・名前の読めない形なら、別の場所のリテラルの定義を読まない（PR #125 G3-B1）。
		$this->assertNull( cbjp_zipcheck_define( "<?php if ( false ) { define( 'A', 'x' ); }\ndefine( 'A', getenv( 'A' ) );\n", 'A' ), '値が式' );
		$this->assertNull( cbjp_zipcheck_define( "<?php if ( false ) { define( 'A', 'x' ); }\ndefine( 'A', '' . '' );\n", 'A' ), '値が文字列の連結' );
		$this->assertNull( cbjp_zipcheck_define( "<?php if ( false ) { define( 'A', 'x' ); }\ndefine( \"A\", '' );\n", 'A' ), '名前が二重引用符' );
		$this->assertNull( cbjp_zipcheck_define( "<?php if ( false ) { define( 'A', 'x' ); }\ndefine( 'A' . '', '' );\n", 'A' ), '名前が式' );
		$this->assertNull( cbjp_zipcheck_define( "<?php define( 'A', 'x', true );\n", 'A' ), '3 つ目の引数' );
		$this->assertNull( cbjp_zipcheck_define( "<?php define( 'B', 'x' );\n", 'A' ) );

		// 1.0.0 で、コメントの中だけに設定があるメインファイルは止まる。
		$source = str_replace(
			"\tdefine( 'CBJP_PRO_LICENSE_API_URL', 'https://store.example.com/' );",
			"\t// define( 'CBJP_PRO_LICENSE_API_URL', 'https://store.example.com/' );\n\tdefine( 'CBJP_PRO_LICENSE_API_URL', '' );",
			self::main_source( self::CONFIGURED )
		);
		$this->assertNotSame( [], array_filter( cbjp_zipcheck_release_problems( $source ), static fn ( string $problem ): bool => str_contains( $problem, 'CBJP_PRO_LICENSE_API_URL' ) ) );
	}

	/**
	 * PHP のトークンで読むので、改行・コメントを挟んだ宣言も拾い、コメントの中の文は拾わない（PR #125 G1-B1: 行単位の grep は複数行の宣言を見落とした）。
	 */
	public function test_reads_namespace_declarations_from_tokens(): void {
		$names = static fn ( string $code ): array => cbjp_zipcheck_namespace_names( token_get_all( $code, TOKEN_PARSE ) );

		$this->assertSame( [ 'CartBridgeJP\\Core' ], $names( "<?php\nnamespace\nCartBridgeJP\\Core;\n" ) );
		$this->assertSame( [ 'CartBridgeJP\\Sync' ], $names( "<?php namespace /* x */ CartBridgeJP\\Sync;\n" ) );
		$this->assertSame( [ 'CartBridgeJP\\Pro\\Zz' ], $names( "<?php\n// namespace CartBridgeJP\\Core;\nnamespace CartBridgeJP\\Pro\\Zz;\n" ) );
		$this->assertSame( [ 'CartBridgeJP', 'CartBridgeJP\\Pro' ], $names( "<?php\nnamespace CartBridgeJP { }\nnamespace CartBridgeJP\\Pro { }\nnamespace { }\n" ) );
		$this->assertSame( [], $names( "<?php\necho namespace\\strlen( 'x' );\n" ), '名前空間の相対名は宣言ではない' );
	}

	public function test_stops_free_plugin_namespaces_and_a_missing_pro_namespace(): void {
		$this->assertSame(
			[],
			cbjp_zipcheck_namespace_problems(
				[
					'a.php' => [ 'CartBridgeJP\\Pro' ],
					'b.php' => [ 'CartBridgeJP\\Pro\\License', 'CARTBRIDGEJP\\PRO\\Zz' ],
				]
			)
		);

		foreach ( [ 'CartBridgeJP', 'CartBridgeJP\\Core', 'cartbridgejp\\core', 'CartBridgeJP\\Product', 'CartBridgeJP\\ProX' ] as $free ) {
			$this->assertSame(
				[ "z.php declares the free plugin namespace {$free}" ],
				cbjp_zipcheck_namespace_problems(
					[
						'a.php' => [ 'CartBridgeJP\\Pro' ],
						'z.php' => [ $free ],
					]
				),
				$free
			);
		}

		$this->assertSame(
			[],
			cbjp_zipcheck_namespace_problems(
				[
					'a.php'                 => [ 'CartBridgeJP\\Pro' ],
					'vendor/composer/x.php' => [ 'Composer\\Autoload' ],
				]
			),
			'ほかの名前空間は対象外'
		);
		$this->assertStringContainsString( 'no CartBridgeJP\\Pro namespace declaration', cbjp_zipcheck_namespace_problems( [ 'vendor/composer/x.php' => [ 'Composer\\Autoload' ] ] )[0] );
	}

	public function test_checks_a_directory(): void {
		$dir = $this->plugin_dir( self::main_source( [ 'version' => '0.1.0' ] + self::CONFIGURED ), "<?php\nnamespace CartBridgeJP\\Pro\\Core;\n" );

		$this->assertSame( 0, $this->run_check( $dir ) );

		file_put_contents( $dir . '/includes/Free.php', "<?php\nnamespace\nCartBridgeJP\\Sync;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- 一時ディレクトリのテスト用のファイル。
		$this->assertSame( 1, $this->run_check( $dir ) );

		file_put_contents( $dir . '/includes/Free.php', "<?php\nfunction ( { \n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- 同上。
		$this->assertSame( 2, $this->run_check( $dir ), '字句解析できないファイルは確かめられない' );

		$this->assertSame( 2, $this->run_check( $dir . '/missing' ) );
	}

	private function run_check( string $dir ): int {
		ob_start();
		$code = cbjp_zipcheck_run( $dir );
		ob_end_clean();

		return $code;
	}

	/**
	 * Pro のメインファイルの形（ヘッダーと `define()`）の文字列。
	 *
	 * @param array<string,string> $settings
	 */
	private static function main_source( array $settings ): string {
		$update_uri = '' === $settings['update_uri'] ? '' : " * Update URI: {$settings['update_uri']}\n";
		$product_id = $settings['product_id'];

		return "<?php\n/**\n * Plugin Name: Cart Bridge JP Pro\n * Version: {$settings['version']}\n * Text Domain: cart-bridge-jp-pro\n{$update_uri} *\n * @package CartBridgeJP\\Pro\n */\n\n"
			. "namespace CartBridgeJP\\Pro;\n\n"
			. "define( 'CBJP_PRO_VERSION', '{$settings['version']}' );\n"
			. "if ( ! defined( 'CBJP_PRO_LICENSE_API_URL' ) ) {\n\tdefine( 'CBJP_PRO_LICENSE_API_URL', '{$settings['api_url']}' );\n}\n"
			. "if ( ! defined( 'CBJP_PRO_LICENSE_PRODUCT_ID' ) ) {\n\tdefine( 'CBJP_PRO_LICENSE_PRODUCT_ID', {$product_id} );\n}\n";
	}

	private function plugin_dir( string $main, string $plugin_code ): string {
		$dir = get_temp_dir() . 'cbjp-zipcheck-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir . '/includes' );
		file_put_contents( $dir . '/cart-bridge-jp-pro.php', $main ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- 一時ディレクトリのテスト用のファイル。
		file_put_contents( $dir . '/includes/Plugin.php', $plugin_code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- 同上。
		$this->dirs[] = $dir;

		return $dir;
	}

	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- 一時ディレクトリの片付け。
			} else {
				wp_delete_file( $item->getPathname() );
			}
		}

		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- 同上。
	}
}
