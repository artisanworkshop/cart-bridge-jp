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

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly PushIntentRepository $push_intents = new PushIntentRepository()
	) {}

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

	/**
	 * Pro 版の案内先 URL（`docs/03-design-decisions.md` §10.3「アップセル表示」、issue #55）。
	 * 空文字列なら Pro 版に触れない（管理画面の `LimitsUpsellNotice` は「無料版はサンプルを移行します」とだけ示す）。
	 *
	 * フィルターの戻り値は外部コード由来の信頼境界（アーキテクチャ原則8）のため、文字列かつ scheme が
	 * `http`/`https` で host のある URL だけを返し、それ以外は `''` に倒す。scheme は `esc_url_raw()` の前に
	 * 自分で確かめる（`esc_url_raw()` は scheme の無い `example.com/pro` に `http://` を補って通してしまう）。
	 */
	public function pro_url(): string {
		/**
		 * Pro 版の案内先 URL。既定は `''`（Pro 版に触れない）。Pro 版の販売開始時に設定する。
		 *
		 * @param string $url
		 */
		$url = apply_filters( 'cbjp/limits/pro_url', '' );

		if ( ! is_string( $url ) ) {
			return '';
		}

		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $scheme ) || ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) || ! is_string( $host ) || '' === $host ) {
			return '';
		}

		return esc_url_raw( $url, [ 'http', 'https' ] );
	}

	public function is_exceeded( string $platform, string $entity ): bool {
		$limit = $this->limit_for( $entity );

		if ( null === $limit ) {
			return false;
		}

		return $this->used( $platform, $entity ) >= $limit;
	}

	/**
	 * 残り実行可能件数。無制限の場合は null。
	 */
	public function remaining( string $platform, string $entity ): ?int {
		$limit = $this->limit_for( $entity );

		if ( null === $limit ) {
			return null;
		}

		return max( 0, $limit - $this->used( $platform, $entity ) );
	}

	/**
	 * 無料版上限に対する累積使用件数。D21-B（`docs/03-design-decisions.md` §10.2「無料版の上限」）:
	 * 未解決の`cbjp_push_intents`（作成済みかもしれない実体）を`cbjp_mappings`の累積カウントに
	 * 含める（枠を空けない）。`push_stock`等intentsが存在しないentityは常に0が加算されるだけなので
	 * entity名で分岐する必要は無い。
	 */
	public function used( string $platform, string $entity ): int {
		return $this->mappings->count( $platform, $entity ) + $this->push_intents->count( $platform, $entity );
	}
}
