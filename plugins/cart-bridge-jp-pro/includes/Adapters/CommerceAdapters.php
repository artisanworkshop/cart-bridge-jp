<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Adapters;

use CartBridgeJP\Adapters\ColorMe\ColorMeAdapter;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Pro\Adapters\ColorMe\ColorMeCommerceAdapter;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\Logger;
use Throwable;
use WeakMap;

/**
 * 接続先（無料版の `PlatformAdapter`）ごとの `CommerceAdapter`（R3-6c1）。実体の種類はここから引く。
 *
 * 組み立ては `cbjp/pro/commerce_adapters/register` フィルターの `platform => callable( PlatformAdapter ): ?CommerceAdapter`。同梱は ColorMe
 * （型が `ColorMeAdapter` のときだけ。同じ `colorme` のキーで登録した別のアダプタ〈検証用の mock など〉には作らない。接続済みのトークンに
 * 顧客・受注・クーポンのスコープが無いときも作らない。R3-6c2）。フィルターは
 * テストと検証用の mock（`verify-with-mock-adapter`）が使う口で、戻り値は信用しない（原則 8）: 配列でない・呼べない・`CommerceAdapter` で
 * ない・`id()` が接続先と違う・例外を投げる、はどれも「この接続先には無い」（null）に倒して記録する。
 *
 * 無料版のアダプタのインスタンスごとに保持する（`WeakMap`）。変換器のメモ化（ColorMe の決済・配送の名前など）の寿命を、
 * プロセスの中でアダプタを使い回す `AdapterRegistry` と揃える。テストでフィルターを変えたら `reset_cache()` を呼ぶ。
 */
final class CommerceAdapters {

	public const FILTER = 'cbjp/pro/commerce_adapters/register';

	/**
	 * 組み立てなかった理由（キャッシュの値）: この接続先には無い（登録が無い・外部の登録が外した・失敗した）。
	 */
	private const NONE = 'none';

	/**
	 * 組み立てなかった理由: 同梱の接続先で、接続済みのトークンにスコープが無い（接続し直せば扱える。G2-B1）。
	 */
	private const MISSING_SCOPES = 'missing_scopes';

	/**
	 * 接続先 => 組み立てた結果か、組み立てなかった理由（`NONE`・`MISSING_SCOPES`。`WeakMap` の `isset` は null の値を「無い」と読むため文字列）。
	 *
	 * @var WeakMap<PlatformAdapter,CommerceAdapter|string>|null
	 */
	private static ?WeakMap $cache = null;

	private function __construct() {}

	public static function get( PlatformAdapter $adapter ): ?CommerceAdapter {
		self::$cache ??= new WeakMap();

		if ( ! isset( self::$cache[ $adapter ] ) ) {
			self::$cache[ $adapter ] = self::build( $adapter );
		}

		$commerce = self::$cache[ $adapter ];

		return $commerce instanceof CommerceAdapter ? $commerce : null;
	}

	/**
	 * `get()` と同じで、無ければ `UnsupportedOperationException`（取得・送信の入口。`supports_*()` が偽の種類はジョブにならないので通常は届かない）。
	 *
	 * 同梱の組み立てが、接続済みのトークンに顧客・受注・クーポンのスコープが無いために組み立てなかったときは（外部の登録が外した・失敗したときは除く。
	 * PR #118 G2-B1）、「この接続先では扱えない」ではなく「未接続」（`context['not_connected']`）の
	 * `ApiException` にする（R3-6c2 review-loop R1-1）: 届くのは残った push intent の紐づけ・既存のジョブの Retry で、扱えないと答えると push intent は
	 * 「未作成」での解除へ案内され、送信済みだった実体が再接続の後に重複して作られうる。未接続の扱いなら「接続し直して」と案内し、送信前に止まったことも確定する。
	 *
	 * @throws ApiException                  接続し直せば扱える（トークンにスコープが無い）。
	 * @throws UnsupportedOperationException この接続先に顧客・受注・クーポンの実装が無い。
	 */
	public static function get_required( PlatformAdapter $adapter, string $operation ): CommerceAdapter {
		$commerce = self::get( $adapter );

		if ( null !== $commerce ) {
			return $commerce;
		}

		// 同梱の組み立てがスコープの不足で組み立てなかったときだけ。外部の登録が外した・失敗した「無い」は、接続し直しても戻らないので
		// 「扱えない」のまま（G2-B1）。
		if ( self::MISSING_SCOPES === ( self::$cache[ $adapter ] ?? null ) ) {
			throw new ApiException(
				'The connection does not have the permissions that customers, orders and coupons need. Reconnect it.',
				0,
				[ 'not_connected' => true ]
			);
		}

		throw new UnsupportedOperationException( $adapter->id(), $operation );
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * 無料版の `cbjp/oauth/scopes` のコールバック（R3-6c2）: 同梱の接続先に、顧客・受注・クーポンに要るスコープを足す。`Core\Plugin::boot()` が
	 * 最後の優先度で登録する。先行するフィルターが配列以外を返していても落ちないよう、型を宣言せず受け、同梱の接続先なら自分の分だけを返す
	 * （無料版が商品のスコープを足し直す。先行する拡張が壊した分は戻らない）。Pro の判定（`ColorMeCommerceAdapter::has_required_scopes()`）と認可の要求をずらさないため（G1-3）。
	 *
	 * @param mixed $scopes   要求するスコープ。
	 * @param mixed $platform 接続先の ID。
	 * @return mixed
	 */
	public static function add_oauth_scopes( mixed $scopes, mixed $platform ): mixed {
		$extra = self::bundled_oauth_scopes()[ is_string( $platform ) ? $platform : '' ] ?? [];

		if ( [] === $extra ) {
			return $scopes;
		}

		return array_merge( is_array( $scopes ) ? $scopes : [], $extra );
	}

	/**
	 * 同梱の組み立て（ColorMe）。ColorMe はトークンに顧客・受注・クーポンのスコープが無ければ組み立てない（R3-6c2）。
	 *
	 * @return array<string,callable(PlatformAdapter):?CommerceAdapter>
	 */
	private static function bundled(): array {
		return [
			ColorMeAdapter::ID => static function ( PlatformAdapter $adapter ): ?CommerceAdapter {
				if ( ! $adapter instanceof ColorMeAdapter ) {
					return null;
				}

				if ( ! ColorMeCommerceAdapter::has_required_scopes( $adapter ) ) {
					throw new MissingScopesException();
				}

				return new ColorMeCommerceAdapter( $adapter );
			},
		];
	}


	/**
	 * 同梱の接続先 => 足す OAuth のスコープ。
	 *
	 * @return array<string,array<int,string>>
	 */
	private static function bundled_oauth_scopes(): array {
		return [
			ColorMeAdapter::ID => ColorMeCommerceAdapter::OAUTH_SCOPES,
		];
	}

	/**
	 * @return CommerceAdapter|string 組み立てた結果か、組み立てなかった理由（`NONE`・`MISSING_SCOPES`）。
	 */
	private static function build( PlatformAdapter $adapter ): CommerceAdapter|string {
		$platform = $adapter->id();

		try {
			$factories = apply_filters( 'cbjp/pro/commerce_adapters/register', self::bundled() );
			$factory   = is_array( $factories ) ? ( $factories[ $platform ] ?? null ) : null;

			if ( null === $factory ) {
				return self::NONE;
			}

			if ( ! is_callable( $factory ) ) {
				self::log_rejected( $platform, 'not_callable' );

				return self::NONE;
			}

			$commerce = $factory( $adapter );
		} catch ( MissingScopesException ) {
			// 同梱の組み立てがスコープの不足で組み立てなかった（記録しない。画面が再接続を促す）。
			return self::MISSING_SCOPES;
		} catch ( Throwable $exception ) {
			self::log_rejected( $platform, $exception::class );

			return self::NONE;
		}

		if ( null === $commerce ) {
			return self::NONE;
		}

		if ( ! $commerce instanceof CommerceAdapter ) {
			self::log_rejected( $platform, 'not_a_commerce_adapter' );

			return self::NONE;
		}

		try {
			$id = $commerce->id();
		} catch ( Throwable $exception ) {
			self::log_rejected( $platform, $exception::class );

			return self::NONE;
		}

		if ( $platform !== $id ) {
			// mapping のキーは接続先の `id()` から決める（`Sync\Importer`）。違う id のアダプタで取得・送信すると、別の接続先の名前空間に書きうる。
			self::log_rejected( $platform, 'id_mismatch' );

			return self::NONE;
		}

		return $commerce;
	}

	private static function log_rejected( string $platform, string $reason ): void {
		( new Logger() )->warning(
			'Ignored a commerce adapter that could not be built.',
			[
				'platform' => $platform,
				'reason'   => $reason,
			]
		);
	}
}
