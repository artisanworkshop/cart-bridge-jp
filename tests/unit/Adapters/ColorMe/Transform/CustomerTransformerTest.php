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

	public function test_transforms_individual_customer(): void {
		$raw = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];

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
		$raw = FixtureLoader::load( 'colorme', 'customers' )['customers'][2];

		$this->assertNull( $raw['receive_mail_magazine'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->mailmag_opt_in );
	}

	public function test_corporate_fields_are_mapped_even_when_absent_from_the_standard_form(): void {
		$raw = FixtureLoader::load( 'colorme', 'customer_corporate_detail' )['customer'];

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( '株式会社サンプル', $customer->company );
		$this->assertSame( '営業部', $customer->department );
	}

	public function test_null_hojin_and_busho_are_not_treated_as_an_error(): void {
		$raw = FixtureLoader::load( 'colorme', 'customers' )['customers'][0];

		$this->assertNull( $raw['hojin'] );
		$this->assertNull( $raw['busho'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertNull( $customer->company );
		$this->assertNull( $customer->department );
	}

	public function test_other_field_maps_to_note(): void {
		$raw = FixtureLoader::load( 'colorme', 'customers' )['customers'][2];

		$this->assertSame( 'テスト備考', $raw['other'] );

		$customer = $this->transformer->transform( $raw );

		$this->assertSame( 'テスト備考', $customer->note );
	}
}
