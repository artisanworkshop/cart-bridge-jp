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

	/**
	 * ColorMeのAPIドキュメント（swagger `info.description`に埋め込まれた「都道府県コード一覧」表）は
	 * JIS X 0401標準・WooCommerceの並びと一致しない箇所が多数ある（例: ColorMeのpref_id=4は秋田県だが
	 * WooCommerceの`JP04`は宮城県）。番号をそのまま同一視せず、都道府県名で突き合わせた対応表
	 * （`PREF_ID_TO_JIS_NUMBER`）経由で変換することを確認する（G3ゲートで判明, Codex/Copilot, P1）。
	 */
	public function test_state_code_translates_through_the_colorme_prefecture_table_not_numeric_identity(): void {
		// ColorMe pref_id=4は秋田県。秋田県のWooCommerce/JIS番号は5（`JP05`）であり、
		// 数値をそのまま使うと宮城県（`JP04`）に誤変換される。
		$akita = AddressMapper::to_woo( 'colorme', [ 'pref_id' => 4 ], 'Taro', 'taro@example.com', null, null );
		$this->assertSame( 'JP05', $akita['state'] );

		// ColorMe pref_id=5は宮城県。宮城県のWooCommerce/JIS番号は4（`JP04`）。
		$miyagi = AddressMapper::to_woo( 'colorme', [ 'pref_id' => 5 ], 'Taro', 'taro@example.com', null, null );
		$this->assertSame( 'JP04', $miyagi['state'] );

		// ColorMe pref_id=16は福井県。福井県のWooCommerce/JIS番号は18（`JP18`）。
		$fukui = AddressMapper::to_woo( 'colorme', [ 'pref_id' => 16 ], 'Taro', 'taro@example.com', null, null );
		$this->assertSame( 'JP18', $fukui['state'] );
	}

	/**
	 * `pref_id_from_state()`は`state_code()`の対称の逆変換であるべきなので、同じ非自明な
	 * （数値がそのまま一致しない）都道府県で往復が保たれることを確認する。
	 */
	public function test_pref_id_from_state_translates_through_the_colorme_prefecture_table_not_numeric_identity(): void {
		// WooCommerceの`JP04`は宮城県。宮城県のColorMe pref_idは5。
		$this->assertSame( 5, AddressMapper::pref_id_from_state( 'colorme', 'JP04', 'JP' ) );

		// WooCommerceの`JP05`は秋田県。秋田県のColorMe pref_idは4。
		$this->assertSame( 4, AddressMapper::pref_id_from_state( 'colorme', 'JP05', 'JP' ) );

		// WooCommerceの`JP18`は福井県。福井県のColorMe pref_idは16。
		$this->assertSame( 16, AddressMapper::pref_id_from_state( 'colorme', 'JP18', 'JP' ) );
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

	/**
	 * PCREの`$`は末尾改行の直前にもマッチするため、`\z`ではなく`$`のままだと`JP13\n`のような
	 * 破損値を正常な`JP13`として誤解釈しうる（`Adapters\ColorMe\Transform\Cast::normalize_tel()`と
	 * 同じ境界データ問題。G1ゲートで判明, Copilot）。
	 */
	public function test_pref_id_from_state_rejects_a_trailing_newline(): void {
		$this->assertNull( AddressMapper::pref_id_from_state( 'colorme', "JP13\n", 'JP' ) );
	}

	/**
	 * `state`/`country`はWoo側で互いに独立して更新されうる境界データ。`country`が非JPを
	 * 明示しているのに古い`JPxx`形式の`state`が残っている場合、`state`を信用して国内住所と
	 * 誤判定すると、実際は海外の顧客が無警告で東京都等の国内住所としてexportされてしまう
	 * （G1ゲートで判明, Codex）。`country`を優先し海外(48)として扱うことを確認する。
	 */
	public function test_pref_id_from_state_prefers_country_over_a_stale_domestic_state(): void {
		$this->assertSame( 48, AddressMapper::pref_id_from_state( 'colorme', 'JP13', 'US' ) );
	}

	/**
	 * `CustomerTransformer::address_payload()`（E2-3 PR-B）から移設した本体。city+address_1を
	 * 区切り無しで連結し、postal/pref_id/address1が3点とも揃った場合のみそれらを含めることを確認する。
	 */
	public function test_to_asp_address_payload_builds_domestic_address(): void {
		$payload = AddressMapper::to_asp_address_payload(
			'colorme',
			[
				'city'      => '千代田区',
				'address_1' => '1-1',
				'address_2' => 'ビル202',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			]
		);

		$this->assertSame(
			[
				'postal'   => '1000001',
				'address1' => '千代田区1-1',
				'pref_id'  => 13,
				'address2' => 'ビル202',
			],
			$payload
		);
	}

	/**
	 * 海外住所（`pref_id=48`）はcity/address_1を半角スペースで連結し、末尾にstate/countryを
	 * カンマ区切りで付記する（ColorMeの顧客/受注スキームに国・地域の専用フィールドが無いため）。
	 */
	public function test_to_asp_address_payload_appends_region_for_overseas_address(): void {
		$payload = AddressMapper::to_asp_address_payload(
			'colorme',
			[
				'city'      => 'Los Angeles',
				'address_1' => '123 Main St',
				'state'     => 'CA',
				'postcode'  => '90001',
				'country'   => 'US',
			]
		);

		$this->assertSame( 'Los Angeles 123 Main St, CA, US', $payload['address1'] );
		$this->assertSame( 48, $payload['pref_id'] );
	}

	/**
	 * postal/pref_id/address1のうち1つでも解決できなければ、この3点セットはまとめて省略される
	 * （新規作成の必須項目を一部欠けたまま送ると内部矛盾した住所になりうるため。呼び出し元が
	 * フェイルクローズするか、更新の省略可能項目として扱うかを判断する）。
	 */
	public function test_to_asp_address_payload_omits_the_three_field_set_when_incomplete(): void {
		$payload = AddressMapper::to_asp_address_payload(
			'colorme',
			[
				'address_2' => 'ビル202',
			]
		);

		$this->assertSame( [ 'address2' => 'ビル202' ], $payload );
	}

	/**
	 * ColorMe以外のplatformにはこのスキーム自体を適用しない（`pref_id_from_state()`と同じゲート）。
	 */
	public function test_to_asp_address_payload_only_applies_to_colorme(): void {
		$payload = AddressMapper::to_asp_address_payload(
			'makeshop',
			[
				'city'      => '千代田区',
				'address_1' => '1-1',
				'state'     => 'JP13',
				'postcode'  => '1000001',
				'country'   => 'JP',
			]
		);

		$this->assertSame( [], $payload );
	}
}
