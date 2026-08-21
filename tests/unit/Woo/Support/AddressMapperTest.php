<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Support;

use CartBridgeJP\Woo\Support\AddressMapper;
use WP_UnitTestCase;

final class AddressMapperTest extends WP_UnitTestCase {

	/**
	 * `pref_id`の1-47=都道府県／48=海外という採番はColorMe APIの取り決めであり、
	 * 全プラットフォーム共通のWoo層クラスが無条件に解釈してよいものではない
	 * （将来別ASPが同名キーを異なる意味で使う可能性がある）。ColorMe以外のplatform名を
	 * 渡した場合はこのスキームを適用せず、state/countryを空にフェイルクローズすることを確認する。
	 */
	public function test_pref_id_scheme_only_applies_to_colorme(): void {
		$address = [
			'pref_id'  => 13,
			'address1' => 'Chiyoda',
		];

		$colorme_result = AddressMapper::to_woo( 'colorme', $address, 'Taro', 'taro@example.com', null, null );
		$this->assertSame( 'JP13', $colorme_result['state'] );

		$other_result = AddressMapper::to_woo( 'makeshop', $address, 'Taro', 'taro@example.com', null, null );
		$this->assertSame( '', $other_result['state'] );
	}

	public function test_is_overseas_only_applies_to_colorme(): void {
		$address = [ 'pref_id' => 48 ];

		$this->assertTrue( AddressMapper::is_overseas( 'colorme', $address ) );
		$this->assertFalse( AddressMapper::is_overseas( 'makeshop', $address ) );
	}
}
