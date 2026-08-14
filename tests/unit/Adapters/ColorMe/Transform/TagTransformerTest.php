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

	public function test_missing_id_throws_instead_of_yielding_empty_remote_id(): void {
		// CanonicalTag::remote_id()は空文字を素通しするため、空文字のまま
		// 通すとImporterが弾かず複数グループが同一remote_idに衝突する。
		$this->expectException( RuntimeException::class );

		( new TagTransformer() )->transform( [ 'name' => 'no id' ] );
	}
}
