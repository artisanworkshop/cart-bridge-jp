<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\Woo\Writer;

use CartBridgeJP\Pro\Canonical\CanonicalCustomer;
use CartBridgeJP\Pro\Tests\Fixtures\CommerceFactory;
use CartBridgeJP\Pro\Woo\CommerceWarningCode;
use CartBridgeJP\Pro\Woo\Writer\CustomerWriter;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\WarningCode;
use WP_User;

final class CustomerWriterTest extends WooTestCase {

	private function make_writer(): CustomerWriter {
		return new CustomerWriter( 'colorme' );
	}

	/**
	 * 取込みが ASP の顧客 `$remote_id` のために作ったアカウントの印（`CustomerWriter::write()` が作成時に書く）。
	 */
	private function mark_created_by_import( int $user_id, string $remote_id ): void {
		update_user_meta( $user_id, CustomerWriter::CREATED_BY_IMPORT_META, 'colorme' );
		update_user_meta( $user_id, CustomerWriter::CREATED_REMOTE_ID_META, $remote_id );
		update_user_meta( $user_id, '_cbjp_platform', 'colorme' );
		update_user_meta( $user_id, '_cbjp_remote_id', $remote_id );
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

	public function test_email_match_links_existing_account_without_changing_it(): void {
		// R3-5b: ASP の会員登録がメールの持ち主を確かめないと、他人のメールで登録した顧客が既存のアカウントの住所を書き換えうる。
		// 取込みが作っていないアカウントは紐づけ（mapping と印）だけにし、プロフィール・住所に触れない。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'taro@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
				'first_name' => 'Own',
			]
		);
		update_user_meta( $existing_id, 'billing_address_1', 'Own address' );

		$customer = new CanonicalCustomer( 'taro@example.com', 'Taro Yamada', null, null, null, [ 'address1' => 'Other address' ], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, null );

		// mapping（受注の顧客の解決）は残すので local_id はそのアカウント。何も書き換えていないので skipped。
		$this->assertSame( $existing_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_id ) ], $result->warnings );

		$user = get_userdata( $existing_id );
		$this->assertContains( 'customer', $user->roles );
		$this->assertSame( 'Own', $user->first_name );
		$this->assertSame( 'Own address', get_user_meta( $existing_id, 'billing_address_1', true ) );
		$this->assertSame( '', get_user_meta( $existing_id, '_cbjp_kana', true ) );
		// 紐づけの印（D25・リンク再構築）は書く。作成の印は付けない。
		$this->assertSame( 'colorme', get_user_meta( $existing_id, '_cbjp_platform', true ) );
		$this->assertSame( '1', get_user_meta( $existing_id, '_cbjp_remote_id', true ) );
		$this->assertSame( '', get_user_meta( $existing_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) );
		$this->assertSame( '', get_user_meta( $existing_id, CustomerWriter::CREATED_REMOTE_ID_META, true ) );
	}

	public function test_mapped_account_not_created_for_this_customer_never_takes_the_email(): void {
		// 乗っ取りの流れ: メールで紐づけた後、ASP 側でメールを攻撃者のものに変えると、mapping 経由の取込みが
		// ログイン用のメールを書き換えていた（パスワードの再設定で乗っ取れる）。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'victim',
				'user_email' => 'victim@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);
		update_user_meta( $existing_id, '_cbjp_platform', 'colorme' );
		update_user_meta( $existing_id, '_cbjp_remote_id', '1' );

		$customer = new CanonicalCustomer( 'attacker@example.com', 'Attacker', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( $existing_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		// mapping で結んだときも毎回理由を残す。
		$this->assertSame( [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_id ) ], $result->warnings );
		$this->assertSame( 'victim@example.com', get_userdata( $existing_id )->user_email );
		$this->assertNotSame( 'Attacker', get_userdata( $existing_id )->display_name );
	}

	public function test_account_created_for_another_customer_is_only_linked(): void {
		// 取込みが別の顧客（5）のために作ったアカウントに、同じメールの別の顧客（1）が当たった。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'hanako',
				'user_email' => 'hanako@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
				'first_name' => 'Hanako',
			]
		);
		$this->mark_created_by_import( $existing_id, '5' );

		$customer = new CanonicalCustomer( 'hanako@example.com', 'Someone Else', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$first    = $this->make_writer()->write( $customer, null );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $first->operation );
		$this->assertSame( 'Hanako', get_userdata( $existing_id )->first_name );
		// 作成時の顧客 ID は変わらない（紐づけの印は書き換わる）。
		$this->assertSame( '5', get_user_meta( $existing_id, CustomerWriter::CREATED_REMOTE_ID_META, true ) );
		$this->assertSame( '1', get_user_meta( $existing_id, '_cbjp_remote_id', true ) );

		// 次の取込み（mapping 経由）でも、顧客 1 のためのアカウントではないので書かない。
		$second = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $second->operation );
		$this->assertSame( 'Hanako', get_userdata( $existing_id )->first_name );
	}

	public function test_account_created_before_r3_5b_remembers_its_customer_before_relinking(): void {
		// R3-5b より前に作ったアカウントは作成時の顧客 ID を持たず、`_cbjp_remote_id` を作成時の値とみなす。紐づけだけの経路が
		// `_cbjp_remote_id` を書き換える前に控えないと、次の取込みが別の顧客を作成時の顧客と読み、プロフィールを書いてしまう。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'legacy',
				'user_email' => 'legacy@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
				'first_name' => 'Legacy',
			]
		);
		update_user_meta( $existing_id, CustomerWriter::CREATED_BY_IMPORT_META, 'colorme' );
		update_user_meta( $existing_id, '_cbjp_platform', 'colorme' );
		update_user_meta( $existing_id, '_cbjp_remote_id', '5' );

		$other = new CanonicalCustomer( 'legacy@example.com', 'Someone Else', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$this->make_writer()->write( $other, null );

		$this->assertSame( '5', get_user_meta( $existing_id, CustomerWriter::CREATED_REMOTE_ID_META, true ) );

		$again = $this->make_writer()->write( $other, $existing_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $again->operation );
		$this->assertSame( 'Legacy', get_userdata( $existing_id )->first_name );
	}

	public function test_account_created_before_r3_5b_is_still_updated_for_its_own_customer(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'legacy',
				'user_email' => 'legacy@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);
		update_user_meta( $existing_id, CustomerWriter::CREATED_BY_IMPORT_META, 'colorme' );
		update_user_meta( $existing_id, '_cbjp_remote_id', '1' );

		$customer = new CanonicalCustomer( 'legacy@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );
		$this->assertSame( 'Yamada', get_userdata( $existing_id )->first_name );
	}

	/**
	 * 取込みがこの顧客のために作ったアカウントを、mapping を失った後にメールで見つけ直したときは更新し、紐づけだけの理由を付けない
	 * （説明文は「この顧客のために作ったのではない…変更しない」。review-loop R1-1）。
	 */
	public function test_own_account_found_again_by_email_is_updated_without_the_link_only_reason(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'own',
				'user_email' => 'own@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);
		$this->mark_created_by_import( $existing_id, '1' );

		$customer   = new CanonicalCustomer( 'own@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$validation = $this->make_writer()->validate( $customer, null );
		$result     = $this->make_writer()->write( $customer, null );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $validation->operation );
		$this->assertSame( [], $validation->warnings );
		$this->assertSame( $existing_id, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );
		$this->assertSame( [], $result->warnings );
		$this->assertSame( 'Yamada', get_userdata( $existing_id )->first_name );
	}

	public function test_protected_account_is_not_reported_as_linked(): void {
		$admin_id = wp_insert_user(
			[
				'user_login' => 'admin-own',
				'user_email' => 'admin-own@example.com',
				'user_pass'  => 'x',
				'role'       => 'administrator',
			]
		);

		$customer = new CanonicalCustomer( 'admin-own@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );

		// 保護したアカウントには受注を結ばない（ゲスト）ので、「受注をそのアカウントに割り当てる」理由は付けない。
		$this->assertSame( [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ) ], $this->make_writer()->write( $customer, null )->warnings );
		$this->assertSame( [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ) ], $this->make_writer()->validate( $customer, null )->warnings );
		$this->assertSame( [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ) ], $this->make_writer()->validate( $customer, $admin_id )->warnings );
	}

	/**
	 * extras は `_cbjp_{key}` のメタになるので、印と同じ名前のキーで所有の判定に使う印を書き換えさせない（アダプタは信頼境界。review-loop R2）。
	 */
	public function test_extras_cannot_overwrite_the_link_and_creation_markers(): void {
		$customer = new CanonicalCustomer(
			'markers@example.com',
			'Taro Yamada',
			null,
			null,
			null,
			[],
			null,
			null,
			null,
			null,
			[
				'remote_id'         => '7',
				'platform'          => 'other',
				'created_by_import' => 'other',
				'created_remote_id' => '99',
				'fax'               => '03-0000-0000',
			]
		);

		$result = $this->make_writer()->write( $customer, null );

		$this->assertSame( 'colorme', get_user_meta( $result->local_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) );
		$this->assertSame( '7', get_user_meta( $result->local_id, CustomerWriter::CREATED_REMOTE_ID_META, true ) );
		$this->assertSame( 'colorme', get_user_meta( $result->local_id, '_cbjp_platform', true ) );
		$this->assertSame( '7', get_user_meta( $result->local_id, '_cbjp_remote_id', true ) );
		// ほかの extras は今どおり書く。
		$this->assertSame( '03-0000-0000', get_user_meta( $result->local_id, '_cbjp_fax', true ) );

		// 次の取込み（mapping 経由）でも、印を書き換えさせない（作ったアカウントのまま更新される）。
		$again = $this->make_writer()->write( $customer, $result->local_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $again->operation );
		$this->assertSame( 'colorme', get_user_meta( $result->local_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) );
		$this->assertSame( '7', get_user_meta( $result->local_id, CustomerWriter::CREATED_REMOTE_ID_META, true ) );
	}

	/**
	 * パスワード設定メール（R3-10）の記録・控えの名前（`_cbjp_pro_*`・`_cbjp_created_password_fingerprint`）と、大文字を含む鍵
	 * （meta_key の照合は大文字小文字を区別しないので、予約した鍵の行を書き換えうる）は extras から書かない。
	 */
	public function test_extras_cannot_write_the_password_email_records_or_case_variants(): void {
		$customer = new CanonicalCustomer(
			'records@example.com',
			'Taro Yamada',
			null,
			null,
			null,
			[],
			null,
			null,
			null,
			null,
			[
				'remote_id'                    => '7',
				'created_password_fingerprint' => 'forged',
				'pro_password_email'           => 'sent:0123456789abcdef0123456789abcdef:1',
				'pro_anything'                 => 'x',
				'Created_By_Import'            => 'other',
				' fax'                         => 'spaced',
				'fax'                          => '03-0000-0000',
			]
		);

		$user_id = $this->make_writer()->write( $customer, null )->local_id;

		$this->assertSame( CustomerWriter::password_fingerprint( $user_id ), get_user_meta( $user_id, CustomerWriter::CREATED_PASSWORD_META, true ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, '_cbjp_pro_password_email' ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, '_cbjp_pro_anything' ) );
		$this->assertSame( 'colorme', get_user_meta( $user_id, CustomerWriter::CREATED_BY_IMPORT_META, true ) );
		$this->assertFalse( metadata_exists( 'user', $user_id, '_cbjp_ fax' ) );
		$this->assertSame( '03-0000-0000', get_user_meta( $user_id, '_cbjp_fax', true ) );
	}

	/**
	 * 作成したときのパスワードのハッシュの控え（R3-10）。作ったときだけ書き、ハッシュそのものは複製しない。採用した既存のアカウントには書かない。
	 */
	public function test_records_the_created_password_only_when_creating(): void {
		$created = $this->make_writer()->write( CommerceFactory::customer( '1', 'new@example.com' ), null )->local_id;
		$hash    = get_userdata( $created )->user_pass;

		$this->assertSame( hash( 'sha256', $hash ), get_user_meta( $created, CustomerWriter::CREATED_PASSWORD_META, true ) );
		$this->assertNotSame( $hash, get_user_meta( $created, CustomerWriter::CREATED_PASSWORD_META, true ) );

		$existing = self::factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'own@example.com',
			]
		);
		$this->make_writer()->write( CommerceFactory::customer( '2', 'own@example.com' ), null );

		$this->assertFalse( metadata_exists( 'user', $existing, CustomerWriter::CREATED_PASSWORD_META ) );
	}

	public function test_account_created_before_r3_5b_without_a_remote_id_is_never_owned_after_relinking(): void {
		// 古いアカウントで `_cbjp_remote_id` が空（または無い）なら、作成時の顧客 ID は空のまま控える（空は誰のものでもない。R1-L2）。
		// 控えた値を「無い」と読むと、紐づけだけの経路が書いた別の顧客の ID を次の取込みが作成時の値と読んでしまう。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'legacy-empty',
				'user_email' => 'legacy-empty@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
				'first_name' => 'Legacy',
			]
		);
		update_user_meta( $existing_id, CustomerWriter::CREATED_BY_IMPORT_META, 'colorme' );

		$other = new CanonicalCustomer( 'legacy-empty@example.com', 'Someone Else', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $this->make_writer()->write( $other, null )->operation );
		$this->assertTrue( metadata_exists( 'user', $existing_id, CustomerWriter::CREATED_REMOTE_ID_META ) );

		$again = $this->make_writer()->write( $other, $existing_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $again->operation );
		$this->assertSame( 'Legacy', get_userdata( $existing_id )->first_name );
	}

	public function test_a_customer_without_a_remote_id_owns_no_account(): void {
		// 作成時の顧客 ID が空で ASP の顧客 ID が無いときに一致と読まない（`owns_profile()` の厳密な比較。R1-L11）。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'no-remote',
				'user_email' => 'no-remote@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
				'first_name' => 'Kept',
			]
		);
		update_user_meta( $existing_id, CustomerWriter::CREATED_BY_IMPORT_META, 'colorme' );
		update_user_meta( $existing_id, CustomerWriter::CREATED_REMOTE_ID_META, '' );

		$customer = new CanonicalCustomer( 'no-remote@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( 'Kept', get_userdata( $existing_id )->first_name );
	}

	public function test_account_created_by_another_platform_is_only_linked(): void {
		$existing_id = wp_insert_user(
			[
				'user_login' => 'base',
				'user_email' => 'base@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
				'first_name' => 'Base',
			]
		);
		update_user_meta( $existing_id, CustomerWriter::CREATED_BY_IMPORT_META, 'base' );
		update_user_meta( $existing_id, CustomerWriter::CREATED_REMOTE_ID_META, '1' );

		$customer = new CanonicalCustomer( 'base@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( 'Base', get_userdata( $existing_id )->first_name );
	}

	public function test_newly_created_customer_is_marked_as_created_by_import(): void {
		$customer = new CanonicalCustomer( 'hanako@example.com', 'Hanako Sato', null, null, null, [], null, null, null, null, [ 'remote_id' => '2' ] );
		$result   = $this->make_writer()->write( $customer, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertSame( 'colorme', get_user_meta( $result->local_id, CustomerWriter::CREATED_BY_IMPORT_META, true ), 'マーカーは作成したプラットフォームを保持する' );
		$this->assertSame( '2', get_user_meta( $result->local_id, CustomerWriter::CREATED_REMOTE_ID_META, true ), '作成時の顧客 ID を保持する' );
		$this->assertSame( 'colorme', get_user_meta( $result->local_id, '_cbjp_platform', true ) );

		// 作ったアカウントは、次の取込み（mapping 経由）で更新される。
		$renamed = new CanonicalCustomer( 'hanako@example.com', 'Hanako Suzuki', null, null, null, [], null, null, null, null, [ 'remote_id' => '2' ] );
		$update  = $this->make_writer()->write( $renamed, $result->local_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $update->operation );
		$this->assertSame( 'Suzuki', get_userdata( $result->local_id )->first_name );
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
		$this->assertContains( WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ), $result->warnings );

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
			WarningCode::with_detail( CommerceWarningCode::CUSTOMER_CREATE_FAILED, 'registration-error-invalid-email' ),
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

		$this->mark_created_by_import( $existing_id, '1' );

		$customer = new CanonicalCustomer( 'new@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );

		$user = get_userdata( $existing_id );
		$this->assertSame( 'new@example.com', $user->user_email );
	}

	public function test_update_does_not_sync_a_malformed_email(): void {
		// `wp_update_user()` は形の正しくないメールを `sanitize_email()` で空にして保存しうる。
		$existing_id = wp_insert_user(
			[
				'user_login' => 'taro',
				'user_email' => 'old@example.com',
				'user_pass'  => 'x',
				'role'       => 'customer',
			]
		);
		$this->mark_created_by_import( $existing_id, '1' );

		$customer = new CanonicalCustomer( 'not-an-email', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );
		$this->assertSame( 'old@example.com', get_userdata( $existing_id )->user_email );
		$this->assertSame( 'Yamada', get_userdata( $existing_id )->first_name );

		$validation = $this->make_writer()->validate( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $validation->operation );
		$this->assertSame( [], $validation->warnings );
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

		$this->mark_created_by_import( $existing_id, '1' );

		$customer = new CanonicalCustomer( 'taken@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$result   = $this->make_writer()->write( $customer, $existing_id );

		// detailはメールアドレス（PII）ではなくASP側remote_id（`CUSTOMER_ACCOUNT_PROTECTED`と同じ方針）。
		$this->assertContains( WarningCode::with_detail( CommerceWarningCode::CUSTOMER_EMAIL_CONFLICT, '1' ), $result->warnings );

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

		$this->assertContains( CommerceWarningCode::ADDRESS_OVERSEAS, $result->warnings );
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

		// write() と同じく紐づけだけ（R3-5b）。
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $validation->operation );
		$this->assertSame( [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_id ) ], $validation->warnings );

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
		$this->assertContains( WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ), $validation->warnings );
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

		$this->mark_created_by_import( $existing_id, '1' );

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

		$this->assertContains( CommerceWarningCode::ADDRESS_OVERSEAS, $validation->warnings );
		$this->assertFalse( get_user_by( 'email', 'overseas-dry-run@example.com' ) instanceof WP_User );
	}

	public function test_validate_detects_email_conflict_matching_write(): void {
		// PRレビュー指摘: `CUSTOMER_EMAIL_CONFLICT`は「実際にwp_update_user()を呼ばないと
		// 判定できない」という前提でvalidate()から除外されていたが、`wp_insert_user()`の
		// メール一意性チェック自体は`email_exists()`のみで完結する読取専用の判定であり、
		// write()を呼ばずに再現できる（wp evalでの実測により確認済み）。
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

		$this->mark_created_by_import( $existing_id, '1' );

		$customer   = new CanonicalCustomer( 'taken@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$validation = $this->make_writer()->validate( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $validation->operation );
		// detailはメールアドレス（PII）ではなくASP側remote_id（`CUSTOMER_ACCOUNT_PROTECTED`と同じ方針）。
		$this->assertContains( WarningCode::with_detail( CommerceWarningCode::CUSTOMER_EMAIL_CONFLICT, '1' ), $validation->warnings );

		// 何も永続化していない。
		$this->assertSame( 'old@example.com', get_userdata( $existing_id )->user_email );
	}

	public function test_validate_links_a_mapped_account_without_checking_its_email(): void {
		// 紐づけだけのアカウントはメールを書かないので、write() と同じく衝突を判定しない（dry-run と本番をそろえる）。
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

		$customer   = new CanonicalCustomer( 'taken@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
		$validation = $this->make_writer()->validate( $customer, $existing_id );
		$result     = $this->make_writer()->write( $customer, $existing_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $validation->operation );
		$this->assertSame( [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_id ) ], $validation->warnings );
		$this->assertSame( $validation->operation, $result->operation );
		$this->assertSame( $validation->warnings, $result->warnings );
	}

	/**
	 * @return array<string,array{0:array<int,string>,1:array<int,string>,2:bool}>
	 */
	public static function protection_cases(): array {
		return [
			'customer'                                  => [ [ 'customer' ], [], false ],
			'subscriber'                                => [ [ 'subscriber' ], [], false ],
			'customer and subscriber'                   => [ [ 'customer', 'subscriber' ], [], false ],
			'administrator'                             => [ [ 'administrator' ], [], true ],
			'shop manager with customer'                => [ [ 'customer', 'shop_manager' ], [], true ],
			'custom staff role'                         => [ [ 'cbjp_test_staff' ], [], true ],
			'customer with a custom forum role'         => [ [ 'customer', 'cbjp_test_member' ], [], true ],
			'no role on this site'                      => [ [], [], true ],
			'customer with a staff capability directly' => [ [ 'customer' ], [ 'manage_woocommerce' ], true ],
			'customer with edit_posts directly'         => [ [ 'customer' ], [ 'edit_posts' ], true ],
		];
	}

	/**
	 * R3-5b: 保護はロール名の拒否リストではなく許可リスト（顧客のロールだけ・スタッフの能力なし）。
	 *
	 * @dataProvider protection_cases
	 * @param array<int,string> $roles
	 * @param array<int,string> $caps
	 */
	public function test_has_protected_role_allows_only_plain_customer_accounts( array $roles, array $caps, bool $expected ): void {
		add_role(
			'cbjp_test_staff',
			'Test staff',
			[
				'read'               => true,
				'manage_woocommerce' => true,
			]
		);
		add_role( 'cbjp_test_member', 'Test member', [ 'read' => true ] );

		$user_id = wp_insert_user(
			[
				'user_login' => 'probe',
				'user_email' => 'probe@example.com',
				'user_pass'  => 'x',
				'role'       => '',
			]
		);
		$user    = new WP_User( $user_id );
		$user->set_role( '' );

		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}

		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}

		try {
			$this->assertSame( $expected, CustomerWriter::has_protected_role( $user_id ) );
		} finally {
			remove_role( 'cbjp_test_staff' );
			remove_role( 'cbjp_test_member' );
		}
	}

	public function test_custom_staff_account_is_not_overwritten(): void {
		add_role(
			'cbjp_test_staff',
			'Test staff',
			[
				'read'               => true,
				'manage_woocommerce' => true,
			]
		);

		$existing_id = wp_insert_user(
			[
				'user_login' => 'staff',
				'user_email' => 'staff@example.com',
				'user_pass'  => 'x',
				'role'       => 'cbjp_test_staff',
				'first_name' => 'Staff',
			]
		);

		try {
			$customer = new CanonicalCustomer( 'staff@example.com', 'Taro Yamada', null, null, null, [], null, null, null, null, [ 'remote_id' => '1' ] );
			$result   = $this->make_writer()->write( $customer, null );

			$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
			$this->assertContains( WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, '1' ), $result->warnings );
			$this->assertSame( 'Staff', get_userdata( $existing_id )->first_name );
			// 保護したアカウントには紐づけの印も書かない（D25: 取込みが印を書かない）。
			$this->assertSame( '', get_user_meta( $existing_id, '_cbjp_platform', true ) );
		} finally {
			remove_role( 'cbjp_test_staff' );
		}
	}
}
