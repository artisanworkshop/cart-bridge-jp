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
	 * 接続先 => 組み立てた結果（無い接続先は false。`WeakMap` の `isset` は null の値を「無い」と読むため）。
	 *
	 * @var WeakMap<PlatformAdapter,CommerceAdapter|false>|null
	 */
	private static ?WeakMap $cache = null;

	private function __construct() {}

	public static function get( PlatformAdapter $adapter ): ?CommerceAdapter {
		self::$cache ??= new WeakMap();

		if ( ! isset( self::$cache[ $adapter ] ) ) {
			self::$cache[ $adapter ] = self::build( $adapter ) ?? false;
		}

		$commerce = self::$cache[ $adapter ];

		return $commerce instanceof CommerceAdapter ? $commerce : null;
	}

	/**
	 * `get()` と同じで、無ければ `UnsupportedOperationException`（取得・送信の入口。`supports_*()` が偽の種類はジョブにならないので通常は届かない）。
	 *
	 * @throws UnsupportedOperationException この接続先に顧客・受注・クーポンの実装が無い。
	 */
	public static function get_required( PlatformAdapter $adapter, string $operation ): CommerceAdapter {
		return self::get( $adapter ) ?? throw new UnsupportedOperationException( $adapter->id(), $operation );
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * 無料版の `cbjp/oauth/scopes` のコールバック（R3-6c2）: 同梱の接続先に、顧客・受注・クーポンに要るスコープを足す。先行するフィルターが
	 * 配列以外を返していても落ちないよう、型を宣言せず受ける（配列でなければ足さずにそのまま返し、無料版の検証に任せる）。
	 *
	 * @param mixed $scopes   要求するスコープ。
	 * @param mixed $platform 接続先の ID。
	 * @return mixed
	 */
	public static function add_oauth_scopes( mixed $scopes, mixed $platform ): mixed {
		$extra = self::bundled_oauth_scopes()[ is_string( $platform ) ? $platform : '' ] ?? [];

		if ( ! is_array( $scopes ) || [] === $extra ) {
			return $scopes;
		}

		return array_merge( $scopes, $extra );
	}

	/**
	 * 同梱の組み立て（ColorMe）。ColorMe はトークンに顧客・受注・クーポンのスコープが無ければ組み立てない（R3-6c2）。
	 *
	 * @return array<string,callable(PlatformAdapter):?CommerceAdapter>
	 */
	private static function bundled(): array {
		return [
			ColorMeAdapter::ID => static fn ( PlatformAdapter $adapter ): ?CommerceAdapter => $adapter instanceof ColorMeAdapter && ColorMeCommerceAdapter::has_required_scopes( $adapter )
				? new ColorMeCommerceAdapter( $adapter )
				: null,
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

	private static function build( PlatformAdapter $adapter ): ?CommerceAdapter {
		$platform = $adapter->id();

		try {
			$factories = apply_filters( 'cbjp/pro/commerce_adapters/register', self::bundled() );
			$factory   = is_array( $factories ) ? ( $factories[ $platform ] ?? null ) : null;

			if ( null === $factory ) {
				return null;
			}

			if ( ! is_callable( $factory ) ) {
				self::log_rejected( $platform, 'not_callable' );

				return null;
			}

			$commerce = $factory( $adapter );
		} catch ( Throwable $exception ) {
			self::log_rejected( $platform, $exception::class );

			return null;
		}

		if ( null === $commerce ) {
			return null;
		}

		if ( ! $commerce instanceof CommerceAdapter ) {
			self::log_rejected( $platform, 'not_a_commerce_adapter' );

			return null;
		}

		try {
			$id = $commerce->id();
		} catch ( Throwable $exception ) {
			self::log_rejected( $platform, $exception::class );

			return null;
		}

		if ( $platform !== $id ) {
			// mapping のキーは接続先の `id()` から決める（`Sync\Importer`）。違う id のアダプタで取得・送信すると、別の接続先の名前空間に書きうる。
			self::log_rejected( $platform, 'id_mismatch' );

			return null;
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
