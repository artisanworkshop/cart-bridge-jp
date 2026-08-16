<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\TagTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use RuntimeException;
use WP_UnitTestCase;

final class TagTransformerTest extends WP_UnitTestCase {

	public function test_transforms_visible_group_into_tag(): void {
		$raw                  = FixtureLoader::load( 'colorme', 'groups' )['groups'][0];
		$raw['display_state'] = 'showing';

		$tag = ( new TagTransformer() )->transform( $raw );

		$this->assertNotNull( $tag );
		$this->assertSame( '3197760', $tag->id );
		$this->assertSame( 'なし', $tag->name );
	}

	public function test_hidden_group_is_excluded_to_avoid_leaking_internal_grouping_as_a_public_tag(): void {
		// 実フィクスチャの既定グループ「なし」はdisplay_state: hiddenで、内部管理用のため
		// 誰にでも見えるWooタグとして作成されるのを防ぐ必要がある。
		$raw = FixtureLoader::load( 'colorme', 'groups' )['groups'][0];

		$this->assertSame( 'hidden', $raw['display_state'] );
		$this->assertNull( ( new TagTransformer() )->transform( $raw ) );
	}

	public function test_members_only_group_is_excluded_from_public_tags(): void {
		// グループのdisplay_stateは showing/hidden/members_only の3値（商品のenumとは別物）。
		// members_only（会員にのみ掲載）は非公開のため、誰にでも見えるWooタグとして
		// 作成されるのを防ぐ必要がある。
		$raw                  = FixtureLoader::load( 'colorme', 'groups' )['groups'][0];
		$raw['display_state'] = 'members_only';

		$this->assertNull( ( new TagTransformer() )->transform( $raw ) );
	}

	public function test_description_is_preserved_in_extras(): void {
		$raw                  = FixtureLoader::load( 'colorme', 'groups' )['groups'][0];
		$raw['display_state'] = 'showing';
		$raw['expl']          = 'グループの説明文';

		$tag = ( new TagTransformer() )->transform( $raw );

		$this->assertNotNull( $tag );
		$this->assertSame( 'グループの説明文', $tag->extras['description'] );
	}

	public function test_image_sort_and_meta_tag_are_preserved_in_extras(): void {
		$raw                  = FixtureLoader::load( 'colorme', 'groups' )['groups'][0];
		$raw['display_state'] = 'showing';
		$raw['image_url']     = 'https://img.example.com/group-1.jpg';
		$raw['sort']          = 3;
		$raw['meta_tag']      = [
			'title'       => 'グループタイトル',
			'keywords'    => 'キーワード1,キーワード2',
			'description' => 'グループのページ概要',
		];

		$tag = ( new TagTransformer() )->transform( $raw );

		$this->assertNotNull( $tag );
		$this->assertSame( 'https://img.example.com/group-1.jpg', $tag->extras['image_url'] );
		$this->assertSame( 3, $tag->extras['sort'] );
		$this->assertSame(
			[
				'title'       => 'グループタイトル',
				'keywords'    => 'キーワード1,キーワード2',
				'description' => 'グループのページ概要',
			],
			$tag->extras['meta_tag']
		);
	}

	public function test_missing_id_throws_instead_of_yielding_empty_remote_id(): void {
		// CanonicalTag::remote_id()は空文字を素通しするため、空文字のまま
		// 通すとImporterが弾かず複数グループが同一remote_idに衝突する。
		$this->expectException( RuntimeException::class );

		( new TagTransformer() )->transform( [ 'name' => 'no id' ] );
	}
}
