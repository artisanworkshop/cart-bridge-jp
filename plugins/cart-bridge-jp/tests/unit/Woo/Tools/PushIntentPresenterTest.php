<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Tools;

use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Tests\Fixtures\RegistersEntityTypes;
use CartBridgeJP\Woo\Tools\PushIntentPresenter;
use WP_UnitTestCase;

/**
 * 外部の種類が返す `edit_url`（画面の `href` になる）は、http(s) で host のある URL だけを通す（R3-5b。原則 8）。
 */
final class PushIntentPresenterTest extends WP_UnitTestCase {

	use RegistersEntityTypes;

	public function tear_down(): void {
		$this->forget_entity_types();
		parent::tear_down();
		$this->forget_entity_types();
	}

	/**
	 * @return array<string,array{0:mixed,1:?string}>
	 */
	public static function edit_url_cases(): array {
		return [
			'https'                => [ 'https://shop.example.com/wp-admin/post.php?post=7&action=edit', 'https://shop.example.com/wp-admin/post.php?post=7&action=edit' ],
			'http'                 => [ 'http://shop.example.com/wp-admin/post.php?post=7', 'http://shop.example.com/wp-admin/post.php?post=7' ],
			'javascript'           => [ 'javascript:alert(document.cookie)', null ],
			'javascript with case' => [ 'JaVaScRiPt:alert(1)', null ],
			'data'                 => [ 'data:text/html,<script>alert(1)</script>', null ],
			'no scheme'            => [ 'shop.example.com/wp-admin/', null ],
			'no host'              => [ 'https:shop.example.com', null ],
			'protocol relative'    => [ '//evil.example/x', null ],
			'relative path'        => [ '/wp-admin/post.php?post=7', null ],
			'empty'                => [ '', null ],
			'not a string'         => [ [ 'https://shop.example.com/' ], null ],
			'null'                 => [ null, null ],
		];
	}

	/**
	 * @dataProvider edit_url_cases
	 */
	public function test_only_web_urls_become_the_edit_link( mixed $edit_url, ?string $expected ): void {
		$this->register_entity_types( [ self::type_returning( $edit_url ) ] );

		$description = ( new PushIntentPresenter() )->describe( 'linkish', 7 );

		$this->assertTrue( $description['exists'] );
		$this->assertSame( $expected, $description['edit_url'] );
	}

	private static function type_returning( mixed $edit_url ): EntityType {
		return new class( $edit_url ) extends EntityType {

			public function __construct( private readonly mixed $edit_url ) {}

			public function key(): string {
				return 'linkish';
			}

			public function label(): string {
				return 'Linkish';
			}

			public function position(): int {
				return 90;
			}

			public function describe_local( int $local_id ): array {
				// 外部の種類は型の契約を守らないことがある（原則 8）。
				// @phpstan-ignore-next-line
				return [
					'exists'   => true,
					'edit_url' => $this->edit_url,
					'summary'  => 'Linkish #' . $local_id,
					'details'  => [],
				];
			}
		};
	}
}
