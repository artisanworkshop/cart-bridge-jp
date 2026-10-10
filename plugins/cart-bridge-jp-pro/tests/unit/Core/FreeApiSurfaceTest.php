<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Core;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_UnitTestCase;

/**
 * Pro のコードが参照する無料版のクラスは、`docs/03-design-decisions.md` §10.0「Pro が使ってよい無料版の API」の一覧のものだけ
 * （R3-6c1。公開後に互換を保つ範囲。一覧に無いクラスを使うと、無料版の内部の変更で Pro が壊れる）。
 *
 * 確かめるのはクラス単位で、メソッドまでは見ない（`ColorMeAdapter` は `api()`・`is_premium_plan()`・`granted_scopes()`、`WooServices` は `mappings()`・
 * `product_resolver()`・`method_map()` だけを使う約束は、レビューで守る）。一覧を変えるときは docs/03 も一緒に直す。
 */
final class FreeApiSurfaceTest extends WP_UnitTestCase {

	/**
	 * Pro が参照してよい無料版のクラス（docs/03 §10.0 の一覧と同じ）。
	 *
	 * @var array<int,string>
	 */
	private const ALLOWED = [
		// 拡張点。
		'CartBridgeJP\Adapters\AdapterRegistry',
		'CartBridgeJP\Entities\EntityType',
		'CartBridgeJP\Entities\EntityTypeRegistry',
		'CartBridgeJP\Entities\LinkSource',
		'CartBridgeJP\Entities\MappingKind',
		'CartBridgeJP\Entities\WarningFlag',
		'CartBridgeJP\Entities\WarningText',
		'CartBridgeJP\Entities\WooServices',
		'CartBridgeJP\Woo\Tools\Link\PostLinkSource',
		'CartBridgeJP\Woo\Tools\Link\TermLinkSource',
		// 起動の判定（無料版が読み込まれているか。`cbjp_pro_bootstrap()`）。
		'CartBridgeJP\Core\Plugin',
		// 型・契約。
		'CartBridgeJP\Adapters\Capabilities',
		'CartBridgeJP\Adapters\Cursor',
		'CartBridgeJP\Adapters\Page',
		'CartBridgeJP\Adapters\PartialPushException',
		'CartBridgeJP\Adapters\PlatformAdapter',
		'CartBridgeJP\Adapters\PushResult',
		'CartBridgeJP\Adapters\UnsupportedOperationException',
		'CartBridgeJP\Canonical\CanonicalModel',
		'CartBridgeJP\Canonical\Concerns\ChecksumTrait',
		'CartBridgeJP\Canonical\Concerns\RemoteIdFromExtrasTrait',
		'CartBridgeJP\Sync\WriteResult',
		'CartBridgeJP\Woo\Reader\EntityReader',
		'CartBridgeJP\Woo\Reader\ReadItem',
		'CartBridgeJP\Woo\Reader\ReadPage',
		'CartBridgeJP\Woo\Writer\EntityWriter',
		'CartBridgeJP\Woo\Writer\ValidationResult',
		// 補助。
		'CartBridgeJP\Support\ApiException',
		'CartBridgeJP\Support\Logger',
		'CartBridgeJP\Support\Money',
		'CartBridgeJP\Support\RateLimitExhaustedException',
		'CartBridgeJP\Sync\MappingRepository',
		'CartBridgeJP\Woo\Support\EntityOrigin',
		'CartBridgeJP\Woo\Support\ExtrasMeta',
		'CartBridgeJP\Woo\Support\HtmlText',
		'CartBridgeJP\Woo\Support\MethodMap',
		'CartBridgeJP\Woo\Support\PlatformOwnership',
		'CartBridgeJP\Woo\Support\ProductResolver',
		'CartBridgeJP\Woo\Support\TaxClass',
		'CartBridgeJP\Woo\Support\Value',
		'CartBridgeJP\Woo\Support\VariationAxisResolver',
		'CartBridgeJP\Woo\Tools\LocalEntityLookup',
		'CartBridgeJP\Woo\WarningCatalog',
		'CartBridgeJP\Woo\WarningCode',
		// ColorMe。
		'CartBridgeJP\Adapters\ColorMe\ColorMeAdapter',
		'CartBridgeJP\Adapters\ColorMe\ColorMeApi',
		'CartBridgeJP\Adapters\ColorMe\ColorMeClient',
		'CartBridgeJP\Adapters\ColorMe\Transform\Cast',
	];

	public function test_pro_references_only_the_allowed_free_classes(): void {
		$this->assertSame( [], array_values( array_diff( self::referenced_free_classes(), self::ALLOWED ) ) );
	}

	/**
	 * 一覧のクラスは無料版に実在する（無料版の改名・削除で一覧が古くならないように）。
	 */
	public function test_every_allowed_class_exists_in_the_free_plugin(): void {
		foreach ( self::ALLOWED as $class ) {
			$this->assertTrue( class_exists( $class ) || interface_exists( $class ) || trait_exists( $class ), $class );
		}
	}

	/**
	 * Pro の PHP（`includes/` とメインファイル）が名前で参照する、`CartBridgeJP\Pro\` 以外の `CartBridgeJP\` のクラス。
	 * `use` の取込み・完全修飾名・クラス名だけの文字列（`class_exists( 'CartBridgeJP\\…' )` など）を数える（同じ名前空間の暗黙の参照は、
	 * Pro と無料版で名前空間が違うので起きない。`use` のグループ構文・別名は接頭辞の名前空間が一覧に無いので落ちる側に倒れる）。
	 *
	 * @return array<int,string>
	 */
	private static function referenced_free_classes(): array {
		$root  = dirname( __DIR__, 3 );
		$files = [ $root . '/cart-bridge-jp-pro.php' ];

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( str_ends_with( (string) $file, '.php' ) ) {
				$files[] = (string) $file;
			}
		}

		$names = [];

		foreach ( $files as $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- テストがリポジトリの PHP を字句解析する（WP の HTTP・JSON の読込みではない）。
			foreach ( PhpToken::tokenize( (string) file_get_contents( $file ) ) as $token ) {
				if ( $token->is( T_CONSTANT_ENCAPSED_STRING ) ) {
					// 引用符を外し、エスケープした `\\` を戻す。クラス名だけの文字列のときだけ数える（文中の言及は数えない）。
					$name = str_replace( '\\\\', '\\', substr( $token->text, 1, -1 ) );

					if ( 1 !== preg_match( '/\A\\\\?CartBridgeJP(\\\\[A-Za-z_][A-Za-z0-9_]*)+\z/', $name ) ) {
						continue;
					}
				} elseif ( $token->is( [ T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ] ) ) {
					$name = $token->text;
				} else {
					continue;
				}

				$name = ltrim( $name, '\\' );

				// `CartBridgeJP\\Pro` そのもの（メインファイルの名前空間の宣言）も Pro。
				if ( str_starts_with( $name, 'CartBridgeJP\\' ) && 'CartBridgeJP\\Pro' !== $name && ! str_starts_with( $name, 'CartBridgeJP\\Pro\\' ) ) {
					$names[ $name ] = true;
				}
			}
		}

		$names = array_keys( $names );
		sort( $names );

		return $names;
	}
}
