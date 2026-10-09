<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Core;

use CartBridgeJP\Adapters\ColorMe\ColorMeClient;
use CartBridgeJP\Adapters\ColorMe\ColorMeOAuth;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Woo\WarningCatalog;
use CartBridgeJP\Woo\WarningCode;
use ReflectionClassConstant;
use WP_UnitTestCase;

/**
 * R3-3: wordpress.org の `readme.txt` を、プラグインヘッダー・`composer.json`・警告カタログ・スクリーンショットと揃える。
 * readme は配布物で、ヘッダーの値が食い違うと Plugin Check と申請で止まり、FAQ の対処の文言がカタログとずれると画面・CSV と違う案内になる。
 */
final class ReadmeTest extends WP_UnitTestCase {

	/**
	 * FAQ「エクスポートが止まる警告」に載せる警告コード（`docs/10` R3-3: D22・D23 ほか。止める警告の全種ではなく、dry-run の CSV に
	 * 出て出会いやすいもの。送信時にだけ出る警告〔`product_price_not_convertible` など。dry-run は `push_*()` を呼ばない〕は載せない）。
	 * FAQ の箇条書きはこの一覧と過不足なく同じ順で一致し、各行は `WarningCatalog` のエクスポートの説明（原因と対処）をそのまま使う。
	 */
	private const FAQ_EXPORT_BLOCKING_CODES = [
		WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED,
		WarningCode::VARIATION_ANY_ATTRIBUTE_UNSUPPORTED,
		WarningCode::TAX_CLASS_UNSUPPORTED,
		WarningCode::VARIATION_TAX_CLASS_UNSUPPORTED,
		WarningCode::TAX_STATUS_NOT_TAXABLE,
		WarningCode::PRICE_TAX_BASIS_UNRESOLVED,
		WarningCode::PRODUCT_PRICE_INVALID,
		WarningCode::ALL_VARIATIONS_EXCLUDED,
		WarningCode::VARIATION_AXIS_LIMIT_EXCEEDED,
		WarningCode::STOCK_PRODUCT_NOT_EXPORTED,
		WarningCode::PUSH_OUTCOME_UNCONFIRMED,
		WarningCode::CURRENCY_MISMATCH,
		WarningCode::ORDER_REFUNDED,
		WarningCode::ORDER_LINE_VARIATION_UNRESOLVED,
	];

	private const FAQ_EXPORT_BLOCKING_QUESTION = 'The export skipped a product, a stock row, or an order with a warning. How do I fix it?';

	private static ?string $readme = null;

	public function test_readme_headers_match_the_plugin_header(): void {
		$plugin = get_file_data(
			CBJP_FILE,
			[
				'name'     => 'Plugin Name',
				'version'  => 'Version',
				'wp'       => 'Requires at least',
				'php'      => 'Requires PHP',
				'license'  => 'License',
				'license_' => 'License URI',
			]
		);
		$readme = $this->readme_headers();

		$this->assertSame( 1, preg_match( '/\A=== (.+) ===\n/', self::readme(), $title ) );
		$this->assertSame( $plugin['name'], $title[1] );
		$this->assertSame( $plugin['wp'], $readme['Requires at least'] ?? null );
		$this->assertSame( $plugin['php'], $readme['Requires PHP'] ?? null );
		$this->assertSame( $plugin['license'], $readme['License'] ?? null );
		$this->assertSame( $plugin['license_'], $readme['License URI'] ?? null );

		// wordpress.org は Stable tag の版を配る。ヘッダーの Version・`CBJP_VERSION` と一緒に上げる（R3-4 で 1.0.0）。
		$this->assertSame( $plugin['version'], $readme['Stable tag'] ?? null );
		$this->assertSame( CBJP_VERSION, $plugin['version'] );
	}

	public function test_readme_has_the_headers_the_directory_requires(): void {
		$headers = $this->readme_headers();

		foreach ( [ 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ] as $name ) {
			$this->assertNotSame( '', $headers[ $name ] ?? '', "Missing readme header: {$name}" );
		}

		$this->assertTrue(
			version_compare( $headers['Tested up to'], $headers['Requires at least'], '>=' ),
			'Tested up to must not be older than Requires at least.'
		);

		// Contributors は wordpress.org のユーザー名に差し替えるまで仮の値（R3-4）。1.0.0 へ上げる時点で残っていれば止める。
		if ( version_compare( CBJP_VERSION, '1.0.0', '>=' ) ) {
			$this->assertStringNotContainsString( 'TODO', $headers['Contributors'] );
		}
	}

	/**
	 * wordpress.org は 5 個を超えるタグを使わない。
	 */
	public function test_readme_has_at_most_five_tags(): void {
		$tags = array_map( 'trim', explode( ',', $this->readme_headers()['Tags'] ?? '' ) );

		$this->assertNotContains( '', $tags );
		$this->assertLessThanOrEqual( 5, count( $tags ) );
		$this->assertSame( $tags, array_values( array_unique( $tags ) ) );
	}

	/**
	 * 短い説明は 150 文字まで・マークアップなし（超えた分は一覧で切られる）。
	 */
	public function test_short_description_fits_the_directory_limits(): void {
		$short = $this->short_description();

		$this->assertNotSame( '', $short );
		$this->assertLessThanOrEqual( 150, mb_strlen( $short ) );
		$this->assertSame( 0, preg_match( '/[<>*`\[\]]/', $short ), 'The short description must not contain markup.' );
	}

	/**
	 * v1.0 は Color Me Shop だけに対応する（D18、`docs/03` §7）。BASE（v2.0）・MakeShop（v3.0）は公開時に追記する。
	 */
	public function test_descriptions_name_only_the_supported_platform(): void {
		$header   = get_file_data( CBJP_FILE, [ 'description' => 'Description' ] )['description'];
		$composer = wp_json_file_decode( CBJP_PATH . 'composer.json', [ 'associative' => true ] );

		$this->assertIsArray( $composer );
		$this->assertSame( $header, $composer['description'] ?? null );

		foreach ( [ $header, self::readme() ] as $text ) {
			$this->assertStringContainsString( 'Color Me Shop', $text );
			$this->assertSame( 0, preg_match( '/MakeShop|\bBASE\b/', $text ) );
		}
	}

	/**
	 * FAQ の対処は R3-0k の警告カタログ（dry-run の CSV の `message`・`action` 列）と同じ文言にする（`docs/10` R3-3）。
	 * カタログの文言を変えたら、readme の FAQ も同じ PR で直す。
	 */
	public function test_export_blocking_faq_uses_the_warning_catalog_text(): void {
		$expected = [];

		foreach ( self::FAQ_EXPORT_BLOCKING_CODES as $code ) {
			$description = WarningCatalog::describe( $code, WarningCatalog::EXPORT );
			$line        = "* `{$code}` – {$description['message']}";

			if ( '' !== $description['action'] ) {
				$line .= " **Fix**: {$description['action']}";
			}

			// 「warnings that stop an item from being exported」と書くので、止める警告だけを載せる。
			$this->assertSame( WarningCatalog::SEVERITY_BLOCKING, $description['severity'], $code );
			$expected[] = $line;
		}

		// 答えの箇条書きはすべてカタログから作った行と一致する（形の違う行・古い文言の行を残さない）。
		$bullets = array_values( preg_grep( '/^\* /', explode( "\n", $this->faq_answer( self::FAQ_EXPORT_BLOCKING_QUESTION ) ) ) );
		$this->assertSame( $expected, $bullets );
	}

	/**
	 * 無料版の上限（D15）を readme に書いた数字は、`LimitPolicy` の既定値と同じ（Pro 版のフィルターで変わる前の値）。
	 */
	public function test_free_version_limits_match_the_limit_policy(): void {
		$limits = ( new ReflectionClassConstant( LimitPolicy::class, 'DEFAULT_LIMITS' ) )->getValue();

		$this->assertIsArray( $limits );

		foreach ( [ $this->subsection( 'Description', 'Free version limits' ), $this->faq_answer( 'What does the free version migrate?' ) ] as $text ) {
			$this->assertStringContainsString( "from the latest {$limits['order']} orders", $text );
			$this->assertStringContainsString( "their products (up to {$limits['product']})", $text );
			$this->assertStringContainsString( "their customers (up to {$limits['customer']})", $text );
		}

		$this->assertStringContainsString( "Coupons are limited to {$limits['coupon']}.", $this->subsection( 'Description', 'Free version limits' ) );
		$this->assertStringContainsString( "up to {$limits['coupon']} coupons", $this->faq_answer( 'What does the free version migrate?' ) );
	}

	/**
	 * ガイドライン（外部サービスの開示）: プラグインが接続する Color Me Shop API の起点（`https://<host>`）を「External services」節に
	 * コードとして書く（節の末尾の規約の URL もホストを含むので、ホスト名だけでは本文から接続先の説明が消えても通ってしまう）。
	 */
	public function test_external_services_section_names_the_hosts_the_plugin_contacts(): void {
		$section = $this->subsection( 'Description', 'External services' );
		$urls    = [
			( new ReflectionClassConstant( ColorMeClient::class, 'DEFAULT_BASE_URL' ) )->getValue(),
			( new ReflectionClassConstant( ColorMeOAuth::class, 'AUTHORIZE_URL' ) )->getValue(),
			( new ReflectionClassConstant( ColorMeOAuth::class, 'TOKEN_URL' ) )->getValue(),
		];

		foreach ( $urls as $url ) {
			$host = wp_parse_url( (string) $url, PHP_URL_HOST );

			$this->assertIsString( $host );
			$this->assertStringContainsString( "`https://{$host}`", $section );
		}
	}

	/**
	 * キャプションの番号と `.wordpress-org/screenshot-N.png` が 1 から欠けずに対応する（SVN の assets/ へ置くファイル）。
	 */
	public function test_screenshot_captions_match_the_asset_files(): void {
		preg_match_all( '/^(\d+)\. \S/m', $this->section( 'Screenshots' ), $captions );
		$numbers = array_map( 'intval', $captions[1] );

		$this->assertNotSame( [], $numbers );
		$this->assertSame( range( 1, count( $numbers ) ), $numbers );

		foreach ( $numbers as $number ) {
			$this->assertFileExists( CBJP_PATH . ".wordpress-org/screenshot-{$number}.png" );
		}

		$this->assertSame( [], glob( CBJP_PATH . '.wordpress-org/screenshot-' . ( count( $numbers ) + 1 ) . '.*' ) );
	}

	/**
	 * readme は配布物に入れ、ディレクトリのアセット（SVN の assets/ へ置く）は zip に入れない（`bin/build-zip.sh`〔CI の Distribution ジョブと release.yml〕は `.distignore` で rsync する）。
	 */
	public function test_distignore_ships_the_readme_but_not_the_directory_assets(): void {
		$lines = array_map( 'trim', (array) file( CBJP_PATH . '.distignore', FILE_IGNORE_NEW_LINES ) );

		$this->assertContains( '.wordpress-org/', $lines );
		$this->assertNotContains( 'readme.txt', $lines );
	}

	private static function readme(): string {
		if ( null === self::$readme ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- リポジトリ内のテキストファイルを読む（JSON ではないので wp_json_file_decode() は使えない）。
			$contents = file_get_contents( CBJP_PATH . 'readme.txt' );
			self::assertIsString( $contents );
			self::$readme = str_replace( "\r\n", "\n", $contents );
		}

		return self::$readme;
	}

	/**
	 * 1 つ目の `== … ==` より前（タイトル・ヘッダー・短い説明）。
	 */
	private function preamble(): string {
		return preg_split( '/^== .+ ==$/m', self::readme(), 2 )[0];
	}

	/**
	 * タイトルの段落（タイトルの行とヘッダーの行）だけを読む。短い説明の「…: …」をヘッダーと取り違えない。
	 *
	 * @return array<string,string>
	 */
	private function readme_headers(): array {
		preg_match_all( '/^([A-Za-z][A-Za-z ]*):[ \t]*(.*)$/m', $this->preamble_paragraphs()[0] ?? '', $matches, PREG_SET_ORDER );
		$headers = [];

		foreach ( $matches as $match ) {
			$headers[ $match[1] ] = trim( $match[2] );
		}

		return $headers;
	}

	/**
	 * ヘッダーの後の最初の段落。
	 */
	private function short_description(): string {
		return trim( $this->preamble_paragraphs()[1] ?? '' );
	}

	/**
	 * @return array<int,string> 1 つ目の `== … ==` より前を空行で区切った段落（0: タイトルとヘッダー、1: 短い説明）
	 */
	private function preamble_paragraphs(): array {
		return array_map( 'strval', (array) preg_split( '/\n\s*\n/', trim( $this->preamble() ) ) );
	}

	private function section( string $name ): string {
		$sections = $this->split_by_headings( self::readme(), '/^== (.+) ==$/m' );

		if ( ! isset( $sections[ $name ] ) ) {
			$this->fail( "Missing readme section: {$name}" );
		}

		return $sections[ $name ];
	}

	/**
	 * 節の中の `= … =` の小見出しから、次の小見出しまで。FAQ では質問とその答え。
	 */
	private function subsection( string $section, string $heading ): string {
		$subsections = $this->split_by_headings( $this->section( $section ), '/^= (.+) =$/m' );

		if ( ! isset( $subsections[ $heading ] ) ) {
			$this->fail( "Missing readme subsection: {$section} > {$heading}" );
		}

		return $subsections[ $heading ];
	}

	/**
	 * @return array<string,string> 見出し => 次の見出しまでの本文
	 */
	private function split_by_headings( string $text, string $pattern ): array {
		$parts = (array) preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$count = count( $parts );
		$map   = [];

		for ( $i = 1; $i + 1 < $count; $i += 2 ) {
			$map[ (string) $parts[ $i ] ] = (string) $parts[ $i + 1 ];
		}

		return $map;
	}

	private function faq_answer( string $question ): string {
		return $this->subsection( 'Frequently Asked Questions', $question );
	}
}
