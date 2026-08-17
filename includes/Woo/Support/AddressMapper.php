<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Support;

/**
 * Canonicalの住所（`postal`/`pref_id`/`pref_name`/`address1`/`address2`/`country`）を
 * WooCommerceのbilling/shipping配列へ変換する。`Writer\CustomerWriter` / `Writer\OrderWriter` で共用。
 */
final class AddressMapper {

	/**
	 * `pref_id`（1-47=都道府県、48=海外を表す特別値）というエンコーディング自体はColorMe
	 * APIの取り決めであり、Wooの都道府県コード（`JP01`等）との対応関係もColorMe固有の解釈
	 * である。このクラスはWooRepositoryFactory経由で全プラットフォーム共通に使われるため、
	 * `pref_id`スキームを解釈してよい対応済みプラットフォームをここで明示的に限定する
	 * （アーキテクチャ原則1）。将来他ASPの`address`が同名キー`pref_id`を異なる意味で
	 * 使う可能性があり、無条件に解釈すると住所を誤って変換しかねない。
	 *
	 * @var array<int,string>
	 */
	private const PREF_ID_SCHEME_PLATFORMS = [ 'colorme' ];

	private function __construct() {}

	/**
	 * @param array<string,mixed> $address
	 * @return array{first_name:string,last_name:string,company:string,address_1:string,address_2:string,city:string,state:string,postcode:string,country:string,email:string,phone:string}
	 */
	public static function to_woo( string $platform, array $address, string $full_name, string $email, ?string $phone, ?string $company ): array {
		[ $last_name, $first_name ] = self::split_name( $full_name );

		return [
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'company'    => $company ?? '',
			// ColorMeに市区町村の独立フィールドが無く、address1からの推測分割は誤りを生むため
			// cityは常に空にし、番地含む住所全体をaddress_1へ入れる。
			'address_1'  => Value::string( $address['address1'] ?? null ) ?? '',
			'address_2'  => Value::string( $address['address2'] ?? null ) ?? '',
			'city'       => '',
			'state'      => self::state_code( $platform, $address ),
			'postcode'   => Value::string( $address['postal'] ?? null ) ?? '',
			'country'    => self::is_overseas( $platform, $address ) ? '' : ( Value::string( $address['country'] ?? null ) ?? 'JP' ),
			'email'      => $email,
			'phone'      => $phone ?? '',
		];
	}

	/**
	 * @param array<string,mixed> $address
	 */
	public static function is_overseas( string $platform, array $address ): bool {
		if ( ! in_array( $platform, self::PREF_ID_SCHEME_PLATFORMS, true ) ) {
			return false;
		}

		return 48 === Value::int( $address['pref_id'] ?? null );
	}

	/**
	 * @param array<string,mixed> $address
	 */
	private static function state_code( string $platform, array $address ): string {
		if ( ! in_array( $platform, self::PREF_ID_SCHEME_PLATFORMS, true ) ) {
			return '';
		}

		$pref_id = Value::int( $address['pref_id'] ?? null );

		if ( null === $pref_id || $pref_id < 1 || $pref_id > 47 ) {
			return '';
		}

		return sprintf( 'JP%02d', $pref_id );
	}

	/**
	 * ColorMeの氏名は「姓 名」の単一文字列。半角/全角スペースで最初の1回だけ分割し、
	 * 先頭を姓（last_name）、残りを名（first_name）とする。区切りが無ければ全体を姓に入れる。
	 *
	 * @return array{0:string,1:string} [last_name, first_name]
	 */
	private static function split_name( string $full_name ): array {
		$parts = preg_split( '/[\s\x{3000}]+/u', trim( $full_name ), 2 );

		if ( false === $parts || ! isset( $parts[0] ) ) {
			return [ $full_name, '' ];
		}

		return [ $parts[0], $parts[1] ?? '' ];
	}
}
