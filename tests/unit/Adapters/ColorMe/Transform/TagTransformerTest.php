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

	public function test_transforms_group_into_tag(): void {
		$raw = FixtureLoader::load( 'colorme', 'groups' )['groups'][0];

		$tag = ( new TagTransformer() )->transform( $raw );

		$this->assertSame( '3197760', $tag->id );
		$this->assertSame( 'なし', $tag->name );
	}

	public function test_missing_id_throws_instead_of_yielding_empty_remote_id(): void {
		// CanonicalTag::remote_id()は空文字を素通しするため、空文字のまま
		// 通すとImporterが弾かず複数グループが同一remote_idに衝突する。
		$this->expectException( RuntimeException::class );

		( new TagTransformer() )->transform( [ 'name' => 'no id' ] );
	}
}
