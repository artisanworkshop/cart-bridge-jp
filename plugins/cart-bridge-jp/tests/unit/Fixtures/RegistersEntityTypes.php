<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Entities\EntityType;
use CartBridgeJP\Entities\EntityTypeRegistry;

/**
 * テストで外部の実体の種類を `cbjp/entity_types/register` に登録する（R3-6b1）。レジストリは静的なキャッシュで、WP のテスト基盤は
 * フックを戻しても静的変数を戻さないので、登録・解除のたびにキャッシュを捨てる。`tear_down()` は `parent::tear_down()` の前に
 * `forget_entity_types()` を呼ぶ（フックの復元の後に残ったキャッシュが次のテストへ漏れないよう、復元後にもう一度捨てる）。
 */
trait RegistersEntityTypes {

	/**
	 * @param array<int,EntityType> $types
	 */
	private function register_entity_types( array $types ): void {
		add_filter(
			EntityTypeRegistry::FILTER,
			static function ( $registered ) use ( $types ) {
				return array_merge( is_array( $registered ) ? $registered : [], $types );
			},
			20
		);
		EntityTypeRegistry::reset_cache();
	}

	private function forget_entity_types(): void {
		EntityTypeRegistry::reset_cache();
	}
}
