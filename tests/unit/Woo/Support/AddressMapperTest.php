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

	/**
	 * `state_code()`の逆変換。`JP01`〜`JP47`は対応する`pref_id`（1〜47）へ戻る。
	 */
	public function test_pref_id_from_state_resolves_domestic_prefecture(): void {
		$this->assertSame( 13, AddressMapper::pref_id_from_state( 'colorme', 'JP13', 'JP' ) );
		$this->assertSame( 1, AddressMapper::pref_id_from_state( 'colorme', 'JP01', 'JP' ) );
		$this->assertSame( 47, AddressMapper::pref_id_from_state( 'colorme', 'JP47', null ) );
	}

	/**
	 * `state`がColorMeのpref_idスキームに一致せず、`country`が非空かつ`JP`以外の場合は
	 * `48`（海外。swaggerの`pref_id`descriptionが明記する特別値）とみなす。
	 */
	public function test_pref_id_from_state_resolves_overseas_from_country(): void {
		$this->assertSame( 48, AddressMapper::pref_id_from_state( 'colorme', '', 'US' ) );
		$this->assertSame( 48, AddressMapper::pref_id_from_state( 'colorme', null, 'US' ) );
	}

	/**
	 * `state`が空・不明で`country`も`JP`または空の場合は変換不能（domestic無し／overseasとも
	 * 断定できない）としてnullを返す。
	 */
	public function test_pref_id_from_state_returns_null_when_unresolvable(): void {
		$this->assertNull( AddressMapper::pref_id_from_state( 'colorme', null, null ) );
		$this->assertNull( AddressMapper::pref_id_from_state( 'colorme', '', 'JP' ) );
		$this->assertNull( AddressMapper::pref_id_from_state( 'colorme', 'CA', 'JP' ) );
		$this->assertNull( AddressMapper::pref_id_from_state( 'colorme', 'JP00', 'JP' ) );
		$this->assertNull( AddressMapper::pref_id_from_state( 'colorme', 'JP48', 'JP' ) );
	}

	/**
	 * ColorMe以外のplatformにはこのスキーム自体を適用しない（クラスdocblock・
	 * `PREF_ID_SCHEME_PLATFORMS`と同じゲート）。
	 */
	public function test_pref_id_from_state_only_applies_to_colorme(): void {
		$this->assertNull( AddressMapper::pref_id_from_state( 'makeshop', 'JP13', 'US' ) );
	}
}
