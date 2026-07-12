<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

/**
 * 無料版の実行上限（D15/`docs/03-design-decisions.md` §10.2）。
 * `cbjp_mappings` の累積カウントを正として、`cbjp/limits/{entity}` フィルターで
 * Proプラグインが上限を解除できる。
 */
final class LimitPolicy {

	/**
	 * §10.2 のエンティティ別上限表。null は数値上限なし。
	 * stock/review はサンプル商品へのメンバーシップで制限される（数値上限なし）ため null。
	 *
	 * @var array<string,int|null>
	 */
	private const DEFAULT_LIMITS = [
		'category' => null,
		'tag'      => null,
		'product'  => 50,
		'customer' => 10,
		'order'    => 10,
		'stock'    => null,
		'coupon'   => 10,
		'review'   => null,
	];

	public function __construct( private readonly MappingRepository $mappings ) {}

	/**
	 * null は無制限。
	 */
	public function limit_for( string $entity ): ?int {
		$default = self::DEFAULT_LIMITS[ $entity ] ?? null;

		/**
		 * 無料版のエンティティ別実行上限。nullは無制限（Proプラグインがこのフィルターで解除する）。
		 *
		 * @param int|null $limit
		 */
		$limit = apply_filters( "cbjp/limits/{$entity}", $default );

		return is_int( $limit ) ? $limit : null;
	}

	public function is_exceeded( string $platform, string $entity ): bool {
		$limit = $this->limit_for( $entity );

		if ( null === $limit ) {
			return false;
		}

		return $this->mappings->count( $platform, $entity ) >= $limit;
	}

	/**
	 * 残り実行可能件数。無制限の場合は null。
	 */
	public function remaining( string $platform, string $entity ): ?int {
		$limit = $this->limit_for( $entity );

		if ( null === $limit ) {
			return null;
		}

		return max( 0, $limit - $this->mappings->count( $platform, $entity ) );
	}
}
