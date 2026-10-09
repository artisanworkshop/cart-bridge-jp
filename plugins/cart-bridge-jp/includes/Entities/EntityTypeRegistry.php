<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Entities;

use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Entities\Core\CategoryType;
use CartBridgeJP\Entities\Core\ProductType;
use CartBridgeJP\Entities\Core\ReviewType;
use CartBridgeJP\Entities\Core\StockType;
use CartBridgeJP\Entities\Core\TagType;
use CartBridgeJP\Woo\WarningCatalog;
use Throwable;

/**
 * 登録済みの実体の種類（`EntityType`）の一覧（R3-6b1。`Adapters\AdapterRegistry` と同じ形）。
 *
 * 無料版の商品系（カテゴリ・タグ・商品・在庫・レビュー）はここで登録し、それ以外は `cbjp/entity_types/register` フィルターで足す
 * （Pro アドオンの拡張点。R3-6c まで無料版自身も顧客・受注・クーポンをこの口から登録する。`Core\Plugin::boot()`）。
 *
 * フィルターの戻り値は外部のコードが返す値なので検証する（原則 8）: 配列でない値・`EntityType` でない要素・キーの形が違うもの
 * （DB の varchar(20) に入る `^[a-z][a-z0-9_]{0,19}$`）・無料版の種類と予約したキー（`variant` は商品の mapping が使う）は外し、
 * キーは配列のキーではなく `key()` で付け直す（`AdapterRegistry` の「登録キーと id() のずれ」を再現しない）。同じキーは先の登録が勝つ。
 * 外したものは `_doing_it_wrong()` で知らせる。並びは `position()`、同じなら `key()`。
 *
 * `plugins_loaded` の途中（Pro は優先度 20 で登録する）に呼ばれた結果はキャッシュしない（その時点の一覧で固まらないように）。
 * 登録した側は `reset_cache()` を呼ぶ（`cbjp/adapters/register` と同じ）。
 */
final class EntityTypeRegistry {

	public const FILTER = 'cbjp/entity_types/register';

	private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,19}\z/';

	/**
	 * 種類のキーに使えない値（mapping の `entity_type` として別の意味で使っている）。
	 */
	private const RESERVED_KEYS = [ 'variant' ];

	/**
	 * @var array<string,EntityType>|null
	 */
	private static ?array $types = null;

	/**
	 * 警告コード => 印 => true（登録された種類の分だけ）。
	 *
	 * @var array<string,array<string,true>>|null
	 */
	private static ?array $flag_index = null;

	private function __construct() {}

	/**
	 * @return array<string,EntityType> key => 種類（実行順）。
	 */
	public static function all(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}

		$types = self::build();

		if ( did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) ) {
			self::$types = $types;
		}

		return $types;
	}

	public static function get( string $key ): ?EntityType {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * @return array<int,string> 実行順のキー。
	 */
	public static function keys(): array {
		return array_keys( self::all() );
	}

	/**
	 * 一覧と、そこから作った索引を捨てる（フィルターの再評価。登録した直後とテストで呼ぶ）。
	 */
	public static function reset_cache(): void {
		self::$types      = null;
		self::$flag_index = null;
	}

	/**
	 * 要求された種類のうち、この接続先から取り込めるもの（実行順）。
	 *
	 * @param array<int,mixed> $requested
	 * @return array<int,string>
	 */
	public static function importable( PlatformAdapter $adapter, array $requested ): array {
		return self::filter_supported( $requested, static fn ( EntityType $type ): bool => $type->supports_import( $adapter ) );
	}

	/**
	 * 要求された種類のうち、この接続先へエクスポートできるもの（実行順）。
	 *
	 * @param array<int,mixed> $requested
	 * @return array<int,string>
	 */
	public static function exportable( PlatformAdapter $adapter, array $requested ): array {
		return self::filter_supported( $requested, static fn ( EntityType $type ): bool => $type->supports_export( $adapter ) );
	}

	/**
	 * 種類の判定を、外部の種類が例外を投げても「非対応」として扱う形で呼ぶ（一覧・ジョブの作成を落とさない）。
	 *
	 * @param callable(EntityType):bool $predicate
	 */
	public static function safely( EntityType $type, callable $predicate ): bool {
		try {
			return true === $predicate( $type );
		} catch ( Throwable ) {
			return false;
		}
	}

	/**
	 * 登録された種類が警告コードに付けた印（`WarningFlag`）。無料版のカタログが説明するコードへの印は無視する
	 * （外部の種類が無料版の警告の扱いを変えないように）。
	 *
	 * @return array<string,true>
	 */
	public static function warning_flags( string $code ): array {
		if ( null === self::$flag_index ) {
			self::$flag_index = self::build_flag_index();
		}

		return self::$flag_index[ $code ] ?? [];
	}

	/**
	 * 全種類のマッピング設定（種類の実行順 → 種類の中の `position()`）。キーが重なれば先の種類が勝つ。
	 *
	 * @return array<int,MappingKind>
	 */
	public static function mapping_kinds(): array {
		$kinds = [];

		foreach ( self::all() as $type ) {
			$own = [];

			try {
				foreach ( $type->mapping_kinds() as $kind ) {
					if ( ! $kind instanceof MappingKind ) {
						self::reject( "The mapping kinds of entity type \"{$type->key()}\" must be MappingKind instances." );
						continue;
					}

					$own[] = [ $kind->position(), $kind->key(), $kind ];
				}
			} catch ( Throwable ) {
				self::reject( "The mapping kinds of entity type \"{$type->key()}\" could not be read." );
				continue;
			}

			usort( $own, static fn ( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );

			foreach ( $own as [ , $key, $kind ] ) {
				if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,30}\z/', $key ) || isset( $kinds[ $key ] ) ) {
					self::reject( "The mapping kind \"{$key}\" is invalid or already registered." );
					continue;
				}

				$kinds[ $key ] = $kind;
			}
		}

		return array_values( $kinds );
	}

	/**
	 * リンク再構築が走査する全種類の実体（`LinkSource::position()` 順。キーが重なれば先の種類が勝つ）。
	 *
	 * @return array<int,LinkSource>
	 */
	public static function link_sources(): array {
		$sources = [];

		foreach ( self::all() as $type ) {
			try {
				foreach ( $type->link_sources() as $source ) {
					if ( ! $source instanceof LinkSource ) {
						self::reject( "The link sources of entity type \"{$type->key()}\" must be LinkSource instances." );
						continue;
					}

					$key = $source->key();

					if ( isset( $sources[ $key ] ) ) {
						self::reject( "The link source \"{$key}\" is already registered." );
						continue;
					}

					$sources[ $key ] = [ $source->position(), $key, $source ];
				}
			} catch ( Throwable ) {
				self::reject( "The link sources of entity type \"{$type->key()}\" could not be read." );
			}
		}

		usort( $sources, static fn ( array $a, array $b ): int => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );

		return array_map( static fn ( array $entry ): LinkSource => $entry[2], $sources );
	}

	/**
	 * @param array<int,mixed>          $requested
	 * @param callable(EntityType):bool $predicate
	 * @return array<int,string>
	 */
	private static function filter_supported( array $requested, callable $predicate ): array {
		$supported = [];

		foreach ( self::all() as $key => $type ) {
			if ( in_array( $key, $requested, true ) && self::safely( $type, $predicate ) ) {
				$supported[] = $key;
			}
		}

		return $supported;
	}

	/**
	 * @return array<string,EntityType>
	 */
	private static function build(): array {
		$types = [];

		foreach ( self::core_types() as $type ) {
			$types[ $type->key() ] = $type;
		}

		$core_keys = array_keys( $types );

		/**
		 * 無料版の商品系に加えて登録する実体の種類（Pro アドオンの拡張点）。`EntityType` を継承したインスタンスの配列を返す
		 * （配列のキーは使わず `key()` で付け直す）。
		 *
		 * @param array<int|string,EntityType> $types
		 */
		$registered = apply_filters( 'cbjp/entity_types/register', [] );

		if ( ! is_array( $registered ) ) {
			self::reject( 'The filter must return an array of EntityType instances.' );
			$registered = [];
		}

		$positions = [];

		foreach ( $types as $key => $type ) {
			$positions[ $key ] = $type->position();
		}

		foreach ( $registered as $type ) {
			if ( ! $type instanceof EntityType ) {
				self::reject( 'The filter must return EntityType instances.' );
				continue;
			}

			try {
				$key      = $type->key();
				$position = $type->position();
			} catch ( Throwable ) {
				self::reject( 'An entity type could not report its key or position.' );
				continue;
			}

			if ( 1 !== preg_match( self::KEY_PATTERN, $key ) || in_array( $key, self::RESERVED_KEYS, true ) ) {
				self::reject( "The entity type key \"{$key}\" is invalid or reserved." );
				continue;
			}

			if ( isset( $types[ $key ] ) ) {
				$reason = in_array( $key, $core_keys, true ) ? 'a built-in type' : 'already registered';
				self::reject( "The entity type \"{$key}\" is {$reason}." );
				continue;
			}

			$types[ $key ]     = $type;
			$positions[ $key ] = $position;
		}

		uksort(
			$types,
			static fn ( string $a, string $b ): int => [ $positions[ $a ], $a ] <=> [ $positions[ $b ], $b ]
		);

		return $types;
	}

	/**
	 * @return array<int,EntityType>
	 */
	private static function core_types(): array {
		return [
			new CategoryType(),
			new TagType(),
			new ProductType(),
			new StockType(),
			new ReviewType(),
		];
	}

	/**
	 * @return array<string,array<string,true>>
	 */
	private static function build_flag_index(): array {
		$index = [];

		foreach ( self::all() as $type ) {
			try {
				$flags = $type->warning_flags();
			} catch ( Throwable ) {
				self::reject( "The warning flags of entity type \"{$type->key()}\" could not be read." );
				continue;
			}

			foreach ( $flags as $code => $code_flags ) {
				if ( ! is_string( $code ) || ! is_array( $code_flags ) ) {
					continue;
				}

				if ( WarningCatalog::is_core_code( $code ) ) {
					self::reject( "The warning code \"{$code}\" belongs to the free plugin; flags from entity type \"{$type->key()}\" are ignored." );
					continue;
				}

				foreach ( $code_flags as $flag ) {
					if ( is_string( $flag ) ) {
						$index[ $code ][ $flag ] = true;
					}
				}
			}
		}

		return $index;
	}

	private static function reject( string $message ): void {
		_doing_it_wrong( esc_html( self::FILTER ), esc_html( $message ), esc_html( CBJP_VERSION ) );
	}
}
