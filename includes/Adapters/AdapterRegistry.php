<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Adapters;

/**
 * 登録済み `PlatformAdapter` の一覧を管理する。
 * `cbjp/adapters/register` フィルターで登録する（Pro版アドオンの拡張ポイントを兼ねる）。
 */
final class AdapterRegistry {

	/**
	 * @var array<string,PlatformAdapter>|null
	 */
	private static ?array $adapters = null;

	/**
	 * @return array<string,PlatformAdapter> id => adapter
	 */
	public static function all(): array {
		if ( null === self::$adapters ) {
			/**
			 * 登録済みアダプタ一覧。id => PlatformAdapter。
			 *
			 * @param array<string,PlatformAdapter> $adapters
			 */
			$adapters = apply_filters( 'cbjp/adapters/register', [] );
			// 外部フィルター（Pro拡張含む）が配列以外を返す可能性への防御。
			self::$adapters = is_array( $adapters ) ? $adapters : [];
		}

		return self::$adapters;
	}

	public static function get( string $id ): ?PlatformAdapter {
		return self::all()[ $id ] ?? null;
	}

	public static function has( string $id ): bool {
		return isset( self::all()[ $id ] );
	}

	/**
	 * フィルターの再評価を強制する（主にテスト用）。
	 */
	public static function reset_cache(): void {
		self::$adapters = null;
	}
}
