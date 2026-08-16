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

	private function __construct() {}

	/**
	 * @param array<string,mixed> $address
	 * @return array{first_name:string,last_name:string,company:string,address_1:string,address_2:string,city:string,state:string,postcode:string,country:string,email:string,phone:string}
	 */
	public static function to_woo( array $address, string $full_name, string $email, ?string $phone, ?string $company ): array {
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
			'state'      => self::state_code( $address ),
			'postcode'   => Value::string( $address['postal'] ?? null ) ?? '',
			'country'    => self::is_overseas( $address ) ? '' : ( Value::string( $address['country'] ?? null ) ?? 'JP' ),
			'email'      => $email,
			'phone'      => $phone ?? '',
		];
	}

	/**
	 * @param array<string,mixed> $address
	 */
	public static function is_overseas( array $address ): bool {
		return 48 === Value::int( $address['pref_id'] ?? null );
	}

	/**
	 * @param array<string,mixed> $address
	 */
	private static function state_code( array $address ): string {
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
