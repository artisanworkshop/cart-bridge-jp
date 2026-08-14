<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\CategoryTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

final class CategoryTransformerTest extends WP_UnitTestCase {

	private CategoryTransformer $transformer;

	public function set_up(): void {
		parent::set_up();
		$this->transformer = new CategoryTransformer();
	}

	public function test_transforms_top_level_category_without_children(): void {
		$raw = FixtureLoader::load( 'colorme', 'categories' )['categories'][1];

		$categories = $this->transformer->transform( $raw );

		$this->assertCount( 1, $categories );
		$this->assertSame( '2993032', $categories[0]->id );
		$this->assertSame( 'テストカテゴリ', $categories[0]->name );
		$this->assertNull( $categories[0]->parent_id );
	}

	/**
	 * `categories.json` の実フィクスチャは `children: []` のみのため、階層構造は
	 * swaggerスキーマに基づく合成データで検証する。
	 */
	public function test_flattens_nested_children_with_parent_id(): void {
		$raw = [
			'id_big'   => 100,
			'name'     => '親カテゴリー',
			'children' => [
				[
					'id_big'   => 100,
					'id_small' => 1,
					'name'     => '子カテゴリーA',
				],
				[
					'id_big'   => 100,
					'id_small' => 2,
					'name'     => '子カテゴリーB',
				],
			],
		];

		$categories = $this->transformer->transform( $raw );

		$this->assertCount( 3, $categories );
		$this->assertSame( '100', $categories[0]->id );
		$this->assertNull( $categories[0]->parent_id );

		$this->assertSame( '100-1', $categories[1]->id );
		$this->assertSame( '100', $categories[1]->parent_id );
		$this->assertSame( '子カテゴリーA', $categories[1]->name );

		$this->assertSame( '100-2', $categories[2]->id );
		$this->assertSame( '100', $categories[2]->parent_id );
	}

	public function test_missing_id_big_yields_no_categories(): void {
		$this->assertSame( [], $this->transformer->transform( [ 'name' => 'no id' ] ) );
	}

	public function test_hidden_top_level_category_is_excluded(): void {
		// Wooの商品カテゴリーは常に公開タクソノミーであり、可視性を保ったまま作成する手段が
		// 無いため、hidden/members_onlyは除外する。
		$raw                  = FixtureLoader::load( 'colorme', 'categories' )['categories'][1];
		$raw['display_state'] = 'hidden';

		$this->assertSame( [], $this->transformer->transform( $raw ) );
	}

	public function test_members_only_top_level_category_is_excluded(): void {
		$raw                  = FixtureLoader::load( 'colorme', 'categories' )['categories'][1];
		$raw['display_state'] = 'members_only';

		$this->assertSame( [], $this->transformer->transform( $raw ) );
	}

	public function test_hidden_child_category_is_excluded_but_visible_siblings_remain(): void {
		$raw = [
			'id_big'   => 100,
			'name'     => '親カテゴリー',
			'children' => [
				[
					'id_big'        => 100,
					'id_small'      => 1,
					'name'          => '非公開の子カテゴリー',
					'display_state' => 'hidden',
				],
				[
					'id_big'        => 100,
					'id_small'      => 2,
					'name'          => '公開の子カテゴリー',
					'display_state' => 'showing',
				],
			],
		];

		$categories = $this->transformer->transform( $raw );

		$this->assertCount( 2, $categories ); // 親 + 公開の子のみ。
		$this->assertSame( '100', $categories[0]->id );
		$this->assertSame( '100-2', $categories[1]->id );
	}
}
