<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Adapters\ColorMe\Transform;

use CartBridgeJP\Adapters\ColorMe\Transform\CustomerTransformer;
use CartBridgeJP\Tests\Fixtures\FixtureLoader;
use WP_UnitTestCase;

final class CustomerTransformerTest extends WP_UnitTestCase {

	private CustomerTransformer $transformer;

	public function set_up(): void {
		parent::set_up();
		$this->transformer = new CustomerTransformer();
	}

	public function test_guest_customer_without_member_registration_is_excluded(): void {
		// フィクスチャの顧客は実APIレスポンスをそのまま保持しており、いずれも `member: false`
		// （会員登録前のゲスト購入スナップショット）。ログイン用アカウントを持たないため
		// Woo顧客アカウントとして作成する対象ではない。
		$raw = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];

		$this->assertFalse( $raw['member'] );
		$this->assertNull( $this->transformer->transform( $raw ) );
	}

	public function test_transforms_individual_customer(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member'] = true;

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( 'taro@example.com', $customer->email );
		$this->assertSame( '山田 太郎', $customer->name );
		$this->assertSame( 'ヤマダ タロウ', $customer->kana );
		$this->assertNull( $customer->company );
		$this->assertTrue( $customer->mailmag_opt_in );
		$this->assertSame( '175271257', $customer->extras['remote_id'] );
		$this->assertSame(
			[
				'postal'    => '1000001',
				'pref_id'   => 13,
				'pref_name' => '東京都',
				'address1'  => '千代田区千代田1-1-1',
				'address2'  => '株式会社サンプル サンプルマンション101',
				'country'   => 'JP',
			],
			$customer->address
		);
	}

	public function test_null_mailmag_opt_in_is_preserved_as_null_not_false(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][2];
		$raw['member'] = true;

		$this->assertNull( $raw['receive_mail_magazine'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->mailmag_opt_in );
	}

	public function test_corporate_fields_are_mapped_even_when_absent_from_the_standard_form(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customer_corporate_detail' )['customer'];
		$raw['member'] = true;

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( '株式会社サンプル', $customer->company );
		$this->assertSame( '営業部', $customer->department );
	}

	public function test_null_hojin_and_busho_are_not_treated_as_an_error(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member'] = true;

		$this->assertNull( $raw['hojin'] );
		$this->assertNull( $raw['busho'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->company );
		$this->assertNull( $customer->department );
	}

	public function test_other_field_maps_to_note(): void {
		$raw           = FixtureLoader::load( 'colorme', 'customers' )['customers'][2];
		$raw['member'] = true;

		$this->assertSame( 'テスト備考', $raw['other'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( 'テスト備考', $customer->note );
	}

	public function test_phone_falls_back_to_mobile_when_tel_is_absent(): void {
		$raw               = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member']     = true;
		$raw['tel']        = null;
		$raw['tel_mobile'] = '09000000002';

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( '09000000002', $customer->phone );
	}

	public function test_phone_prefers_tel_over_mobile_when_both_present(): void {
		$raw               = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member']     = true;
		$raw['tel_mobile'] = '09000000002';

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( '0300000001', $customer->phone );
	}

	public function test_overseas_pref_id_leaves_country_null_instead_of_forcing_jp(): void {
		// swagger customerスキーマ: pref_id=48は「海外」を表す特別値。
		$raw            = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];
		$raw['member']  = true;
		$raw['pref_id'] = 48;

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->address['country'] );
		$this->assertSame( 48, $customer->address['pref_id'] );
	}
}
