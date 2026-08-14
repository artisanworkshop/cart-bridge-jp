<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\TagTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

final class TagTransformerTest extends WP_UnitTestCase {

	public function test_transforms_group_into_tag(): void {
		$raw = FixtureLoader::load( 'colorme', 'groups' )['groups'][0];

		$tag = ( new TagTransformer() )->transform( $raw );

		$this->assertSame( '3197760', $tag->id );
		$this->assertSame( 'なし', $tag->name );
	}
}
