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
 * （Pro アドオンの拡張点。顧客・受注・クーポンは Pro がこの口から登録する。R3-6c1 より前は無料版自身も登録していた）。
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

	/**
	 * 一覧を組み立てている間（フィルターのコールバックが `has()` などを呼んでも再帰しないように）。
	 */
	private static bool $building = false;

	private function __construct() {}

	/**
	 * @return array<string,EntityType> key => 種類（実行順）。
	 */
	public static function all(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}

		// 組み立て中に呼ばれた（登録のコールバックが一覧を引いた）ら、無料版の種類だけを返す（再帰で fatal にしない）。
		if ( self::$building ) {
			return self::core_map();
		}

		self::$building = true;

		try {
			$types = self::build();
		} finally {
			self::$building = false;
		}

		if ( did_action( 'plugins_loaded' ) && ! doing_action( 'plugins_loaded' ) ) {
			self::$types = $types;
		}

		return $types;
	}

	public static function get( string $key ): ?EntityType {
		return self::all()[ $key ] ?? null;
	}

	public static function has( string $key ): bool {
		return isset( self::all()[ $key ] );
	}

	/**
	 * 種類の表示名。外部の種類が例外を投げる・空を返すときはキーを使う（一覧を落とさない）。
	 */
	public static function label( EntityType $type ): string {
		try {
			$label = $type->label();
		} catch ( Throwable ) {
			$label = '';
		}

		return '' !== $label ? $label : $type->key();
	}

	/**
	 * 画面に出す名前（種類と、リンク再構築の対象〔`variant` など〕）。キー => 名前。
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$labels = [];

		foreach ( self::all() as $key => $type ) {
			$labels[ $key ] = self::label( $type );
		}

		foreach ( self::link_sources() as $source ) {
			$key = $source->key();

			if ( isset( $labels[ $key ] ) ) {
				continue;
			}

			try {
				$label = $source->label();
			} catch ( Throwable ) {
				$label = '';
			}

			$labels[ $key ] = '' !== $label ? $label : $key;
		}

		return $labels;
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
		if ( null !== self::$flag_index ) {
			return self::$flag_index[ $code ] ?? [];
		}

		$index = self::build_flag_index();

		// 種類の一覧と同じく、一覧をキャッシュできる時点（`plugins_loaded` の後）のものだけを保持する。途中で作った索引を保持すると、
		// 後から登録された種類の印（受注の blocking など）が `reset_cache()` まで無いままになる（fail-open）。
		if ( null !== self::$types ) {
			self::$flag_index = $index;
		}

		return $index[ $code ] ?? [];
	}

	/**
	 * エクスポートがベータ扱いか（D24。既定で選ばない）。外部の種類が例外を投げたらベータとして扱う（既定で選ばない側に倒す。原則 9）。
	 */
	public static function is_export_beta( EntityType $type, PlatformAdapter $adapter ): bool {
		try {
			return false !== $type->is_export_beta( $adapter );
		} catch ( Throwable ) {
			return true;
		}
	}

	/**
	 * 全種類のマッピング設定（種類の実行順 → 種類の中の `position()`）。キーは無料版の種類のものを先に確定し、外部の種類の同じキー・
	 * 形の違うキーは外す（外部の種類が無料版のマッピングを置き換えないように）。
	 *
	 * @return array<int,MappingKind>
	 */
	public static function mapping_kinds(): array {
		return array_map( static fn ( array $entry ): MappingKind => $entry['kind'], self::mapping_kind_entries() );
	}

	/**
	 * `mapping_kinds()` と同じ一覧を、確定したキーと、それを持つ実体の種類のキーと組にしたもの（R3-6b2。Import タブが、選んだ種類の持つ
	 * マッピングだけを案内する）。`key` は組み立てたときに読んだ値（呼び出し側が `key()` を呼び直さずに済む）。
	 *
	 * @return array<int,array{entity:string,key:string,kind:MappingKind}>
	 */
	public static function mapping_kind_entries(): array {
		$entries = [];

		foreach ( self::all() as $key => $type ) {
			try {
				foreach ( $type->mapping_kinds() as $kind ) {
					if ( ! $kind instanceof MappingKind ) {
						self::reject( "The mapping kinds of entity type \"{$key}\" must be MappingKind instances." );
						continue;
					}

					$entries[] = [ $type->position(), $kind->position(), $kind->key(), $kind, self::is_core( $type ), $key ];
				}
			} catch ( Throwable ) {
				self::reject( "The mapping kinds of entity type \"{$key}\" could not be read." );
			}
		}

		return array_map(
			static fn ( array $entry ): array => [
				'entity' => $entry[5] ?? '',
				'key'    => $entry[2],
				'kind'   => $entry[3],
			],
			self::claim_keys( $entries, '/^[a-z][a-z0-9_]{0,30}\z/', 'mapping kind' )
		);
	}

	/**
	 * リンク再構築が走査する全種類の実体（`LinkSource::position()` 順）。キーは無料版の種類のものを先に確定し、外部の種類の同じキー・
	 * 形の違うキー（`cbjp_mappings.entity_type` の varchar(20) に入らないもの）は外す。
	 *
	 * @return array<int,LinkSource>
	 */
	public static function link_sources(): array {
		$entries = [];

		foreach ( self::all() as $key => $type ) {
			try {
				foreach ( $type->link_sources() as $source ) {
					if ( ! $source instanceof LinkSource ) {
						self::reject( "The link sources of entity type \"{$key}\" must be LinkSource instances." );
						continue;
					}

					$entries[] = [ 0, $source->position(), $source->key(), $source, self::is_core( $type ) ];
				}
			} catch ( Throwable ) {
				self::reject( "The link sources of entity type \"{$key}\" could not be read." );
			}
		}

		return array_map(
			static fn ( array $entry ): LinkSource => $entry[3],
			self::claim_keys( $entries, self::KEY_PATTERN, 'link source' )
		);
	}

	/**
	 * キーの重複を除き（無料版の種類のものを先に確定し、残りは先勝ち）、[種類の位置, 項目の位置, キー] の順に並べる。
	 *
	 * @param array<int,array{0:int,1:int,2:string,3:object,4:bool,5?:string}> $entries
	 * @return array<int,array{0:int,1:int,2:string,3:object,4:bool,5?:string}>
	 */
	private static function claim_keys( array $entries, string $pattern, string $what ): array {
		$claimed = [];

		foreach ( [ true, false ] as $core_pass ) {
			foreach ( $entries as $entry ) {
				if ( $entry[4] !== $core_pass ) {
					continue;
				}

				$key = $entry[2];

				if ( 1 !== preg_match( $pattern, $key ) || isset( $claimed[ $key ] ) ) {
					self::reject( "The {$what} \"{$key}\" is invalid or already registered." );
					continue;
				}

				$claimed[ $key ] = $entry;
			}
		}

		usort( $claimed, static fn ( array $a, array $b ): int => [ $a[0], $a[1], $a[2] ] <=> [ $b[0], $b[1], $b[2] ] );

		return $claimed;
	}

	/**
	 * 無料版の種類（`core_types()`）か。外部の種類は無料版のキーを使えないので、キーで見分けられる。
	 */
	private static function is_core( EntityType $type ): bool {
		return isset( self::core_map()[ $type->key() ] );
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
		$types = self::core_map();

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
				$reason = isset( self::core_map()[ $key ] ) ? 'a built-in type' : 'already registered';
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
	 * 無料版の種類だけの一覧（実行順。組み立て中に呼ばれたとき）。
	 *
	 * @return array<string,EntityType>
	 */
	private static function core_map(): array {
		$types = [];

		foreach ( self::core_types() as $type ) {
			$types[ $type->key() ] = $type;
		}

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

		foreach ( self::all() as $key => $type ) {
			try {
				$flags = $type->warning_flags();
			} catch ( Throwable ) {
				self::reject( "The warning flags of entity type \"{$key}\" could not be read." );
				continue;
			}

			foreach ( $flags as $code => $code_flags ) {
				if ( ! is_string( $code ) || ! is_array( $code_flags ) ) {
					continue;
				}

				if ( WarningCatalog::is_core_code( $code ) ) {
					self::reject( "The warning code \"{$code}\" belongs to the free plugin; flags from entity type \"{$key}\" are ignored." );
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
