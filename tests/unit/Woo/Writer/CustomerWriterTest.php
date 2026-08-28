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
use WP_User;

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

	public function test_reuses_existing_customer_user_by_email(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'taro@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);

		$customer = new CanonicalCustomer( 'taro@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, null );

		$this->assertSame( $existing_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_id ), $result->warnings );

		$user = get_userdata( $existing_id );
		$this->assertContains( 'customer', $user->roles );
		$this->assertSame( 'Yamada', $user->first_name );
	}

	public function test_reusing_administrator_account_does_not_overwrite_profile(): void {
		$existing_id = wp_insert_user(
			[
				'user_login'   => 'admin-taro',
				'user_email'   => 'taro@example.com',
				'user_pass'    => 'x',
				'role'         => 'administrator',
				'first_name'   => 'Original',
				'display_name' => 'Original Admin',
			]
		);

		$customer = new CanonicalCustomer( 'taro@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, null );

		// mappingを解決できるようlocal_idは既存アカウントを指すが、実体としては何も
		// 変更されなかった（skipped）ことを示す。
		$this->assertSame( $existing_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		// detailはWoo内部のuser IDではなくASP側remote_id（OrderWriter側の同名警告・
		// F1-6の結果レポート契約と揃える）。
		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ), $result->warnings );

		// 既存ユーザーのロール・氏名・住所は一切変更されていない。
		$user = get_userdata( $existing_id );
		$this->assertContains( 'administrator', $user->roles );
		$this->assertSame( 'Original', $user->first_name );
		$this->assertSame( '', get_user_meta( $existing_id, 'billing_address_1', true ) );
	}

	public function test_stale_existing_local_id_falls_back_to_create(): void {
		// mappingsが指すユーザーIDが手動削除等で既に存在しない場合を模擬する
		// （実在しないユーザーIDを直接existing_local_idとして渡す）。
		$customer = new CanonicalCustomer( 'new@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '9' ] );

		$result = $this->make_writer()->write( $customer, 999999 );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( 999999, $result->local_id );

		$user = get_userdata( $result->local_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( 'new@example.com', $user->user_email );
	}

	public function test_create_failure_is_surfaced_as_a_warning(): void {
		// `wc_create_new_customer()`はメール形式が不正だと`WP_Error`を返す。無警告のまま
		// skippedにすると結果レポートから顧客が丸ごと欠落した理由が分からなくなるため、
		// 警告として可視化されることを確認する。
		$customer = new CanonicalCustomer( 'not-an-email', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '10' ] );

		$result = $this->make_writer()->write( $customer, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains(
			WarningCode::with_detail( WarningCode::CUSTOMER_CREATE_FAILED, 'registration-error-invalid-email' ),
			$result->warnings
		);
	}

	public function test_update_syncs_changed_email_from_asp(): void {
		// mappings経由の再利用（existing_local_id指定）では、ASP側で前回インポート後に
		// メールアドレスが変更されている可能性がある。同期しないとWPアカウントのログイン用
		// メールが古いまま残り続けるため、更新時にuser_emailも同期されることを確認する。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'old@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);

		$customer = new CanonicalCustomer( 'new@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );

		$user = get_userdata( $existing_id );
		$this->assertSame( 'new@example.com', $user->user_email );
	}

	public function test_update_email_conflict_with_another_user_warns_without_overwriting(): void {
		wp_insert_user(
			[
				'user_login' => 'other',
				'user_email' => 'taken@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);

		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'old@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);

		$customer = new CanonicalCustomer( 'taken@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_EMAIL_CONFLICT, 'taken@example.com' ), $result->warnings );

		// 衝突のためメールは更新されず、既存アカウントの元のメールのまま残る。
		$user = get_userdata( $existing_id );
		$this->assertSame( 'old@example.com', $user->user_email );
	}

	public function test_shipping_phone_is_written_from_billing_phone(): void {
		// WCの配送先メタには`shipping_phone`が実在するフィールドとして存在する
		// （配送ラベル・チェックアウト自動入力等が参照する）。email除外のついでに
		// phoneまで除外していた不具合の回帰テスト。
		$customer = new CanonicalCustomer(
			'phone@example.com',
			'Taro Yamada',
			null,
			null,
			null,
			[],
			'03-1234-5678',
			null,
			null,
			null,
			[ 'remote_id' => '20' ]
		);

		$result = $this->make_writer()->write( $customer, null );

		$this->assertSame( '03-1234-5678', get_user_meta( $result->local_id, 'billing_phone', true ) );
		$this->assertSame( '03-1234-5678', get_user_meta( $result->local_id, 'shipping_phone', true ) );
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

	// --- validate()（F1-6 dry-run）

	public function test_validate_matches_write_for_new_customer(): void {
		$customer   = new CanonicalCustomer( 'taro@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$validation = $this->make_writer()->validate( $customer, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $validation->operation );
		$this->assertSame( [], $validation->warnings );
		$this->assertFalse( get_user_by( 'email', 'taro@example.com' ) );
	}

	public function test_validate_reuses_existing_customer_by_email_without_persisting(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'taro@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);

		$customer   = new CanonicalCustomer( 'taro@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$validation = $this->make_writer()->validate( $customer, null );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $validation->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_id ), $validation->warnings );

		// 何も永続化していない（氏名は未反映のまま）。
		$user = get_userdata( $existing_id );
		$this->assertSame( '', $user->first_name );
	}

	public function test_validate_detects_protected_account_without_overwriting(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'admin-taro',
				'user_email' => 'taro@example.com',
				'user_pass'  => 'x',
				'role'       => 'administrator',
			]
		);

		$customer   = new CanonicalCustomer( 'taro@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$validation = $this->make_writer()->validate( $customer, null );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $validation->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ), $validation->warnings );
	}

	public function test_validate_updated_existing_mapping_without_persisting(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'old@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);

		$customer   = new CanonicalCustomer( 'new@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$validation = $this->make_writer()->validate( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $validation->operation );
		$this->assertSame( [], $validation->warnings );

		// 何も永続化していない（メールは同期されない）。
		$this->assertSame( 'old@example.com', get_userdata( $existing_id )->user_email );
	}

	public function test_validate_warns_on_overseas_address_matching_write(): void {
		// `AddressMapper::is_overseas()`はDB読取・永続化を伴わない純粋な判定のため、write()と
		// 同じ警告がvalidate()でも出ることを確認する（PRレビュー指摘: validate()は元々この
		// チェックを一切呼んでおらず、実際に移行後に付く警告がdry-run CSVから欠落していた）。
		$customer = new CanonicalCustomer(
			'overseas-dry-run@example.com',
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

		$validation = $this->make_writer()->validate( $customer, null );

		$this->assertContains( WarningCode::ADDRESS_OVERSEAS, $validation->warnings );
		$this->assertFalse( get_user_by( 'email', 'overseas-dry-run@example.com' ) instanceof WP_User );
	}
}
