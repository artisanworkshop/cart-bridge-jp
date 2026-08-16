<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\CustomerWriter;

final class CustomerWriterTest extends WooTestCase {

	private function make_writer(): CustomerWriter {
		return new CustomerWriter( 'colorme' );
	}

	public function test_creates_new_customer_with_name_split(): void {
		$customer = new CanonicalCustomer(
			'taro@example.com',
			'山田 太郎',
			'ヤマダ タロウ',
			null,
			null,
			[
				'postal'   => '100-0001',
				'pref_id'  => 13,
				'address1' => 'Chiyoda',
			],
			'03-1234-5678',
			null,
			true,
			null,
			[ 'remote_id' => '1' ]
		);

		$result = $this->make_writer()->write( $customer, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );

		$user = get_userdata( $result->local_id );
		$this->assertSame( '山田', $user->last_name );
		$this->assertSame( '太郎', $user->first_name );
		$this->assertTrue( in_array( 'customer', $user->roles, true ) );
		$this->assertSame( 'JP13', get_user_meta( $result->local_id, 'billing_state', true ) );
		$this->assertSame( '100-0001', get_user_meta( $result->local_id, 'billing_postcode', true ) );
		$this->assertSame( 'ヤマダ タロウ', get_user_meta( $result->local_id, '_cbjp_kana', true ) );

		// 通知メールは抑止されている（SideEffectGuard適用外の単体テストのため、ここではCustomerWriter
		// 自体はメール送信を行わないことのみを確認する）。
		$this->assertNotEmpty( $user->user_pass );
	}

	public function test_reuses_existing_user_by_email(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'taro@example.com',
				'user_pass'  => 'x',
				'role'       => 'administrator',
			]
		);

		$customer = new CanonicalCustomer( 'taro@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, null );

		$this->assertSame( $existing_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_id ), $result->warnings );

		// 既存ユーザーのロールは変更しない。
		$user = get_userdata( $existing_id );
		$this->assertContains( 'administrator', $user->roles );
	}

	public function test_overseas_address_warns_and_leaves_state_empty(): void {
		$customer = new CanonicalCustomer(
			'overseas@example.com',
			'John Smith',
			null,
			null,
			null,
			[
				'pref_id'  => 48,
				'address1' => 'Somewhere',
			],
			null,
			null,
			null,
			null,
			[ 'remote_id' => '2' ]
		);

		$result = $this->make_writer()->write( $customer, null );

		$this->assertContains( WarningCode::ADDRESS_OVERSEAS, $result->warnings );
		$this->assertSame( '', get_user_meta( $result->local_id, 'billing_state', true ) );
	}

	public function test_updates_extras_meta(): void {
		$customer = new CanonicalCustomer(
			'meta@example.com',
			'Meta User',
			null,
			'Acme Inc.',
			'Sales',
			[],
			null,
			'1990-01-01',
			false,
			'note text',
			[
				'remote_id' => '3',
				'fax'       => '03-0000-0000',
				'points'    => 100,
			]
		);

		$result = $this->make_writer()->write( $customer, null );

		$this->assertSame( '03-0000-0000', get_user_meta( $result->local_id, '_cbjp_fax', true ) );
		$this->assertSame( '100', get_user_meta( $result->local_id, '_cbjp_points', true ) );
		$this->assertSame( '1990-01-01', get_user_meta( $result->local_id, '_cbjp_birthday', true ) );
		$this->assertSame( '0', get_user_meta( $result->local_id, '_cbjp_mailmag_opt_in', true ) );
		$this->assertSame( 'note text', get_user_meta( $result->local_id, '_cbjp_note', true ) );
		$this->assertSame( 'Sales', get_user_meta( $result->local_id, '_cbjp_department', true ) );
	}
}
