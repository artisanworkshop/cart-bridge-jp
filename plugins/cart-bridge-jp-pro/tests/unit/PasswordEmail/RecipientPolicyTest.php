<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Tests\PasswordEmail;

use CartBridgeJP\Pro\PasswordEmail\RecipientPolicy;
use CartBridgeJP\Pro\PasswordEmail\SendRecord;
use CartBridgeJP\Pro\PasswordEmail\Verdict;
use CartBridgeJP\Pro\Tests\Fixtures\CommerceFactory;
use CartBridgeJP\Pro\Tests\Fixtures\CreatesImportedCustomers;
use CartBridgeJP\Pro\Woo\Writer\CustomerWriter;
use CartBridgeJP\Tests\Woo\WooTestCase;

/**
 * パスワード設定メールを送る相手の判定（R3-10。`docs/03` §10.5 の 2）。
 */
final class RecipientPolicyTest extends WooTestCase {

	use CreatesImportedCustomers;

	private const RUN = '0123456789abcdef0123456789abcdef';

	public function test_sends_to_a_customer_the_import_created(): void {
		$user_id = $this->imported_customer( '1' );

		$verdict = ( new RecipientPolicy() )->evaluate( $user_id );

		$this->assertSame( Verdict::SEND, $verdict->outcome );
		$this->assertFalse( $verdict->password_unknown, '作成時の控えがあるので判定できる' );
		$this->assertFalse( $verdict->legacy );
	}

	/**
	 * 顧客がパスワード忘れから設定し直した（WP・WooCommerce の再設定は `reset_password()`・`wp_set_password()`）。
	 */
	public function test_leaves_out_a_customer_who_reset_the_password(): void {
		$user_id = $this->imported_customer( '1' );

		reset_password( get_userdata( $user_id ), 'a new password' );

		$this->assertSame( Verdict::PASSWORD_SET, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	/**
	 * 管理者がプロフィールで変えた（`default_password_nag` は書かれない。控えとの比較だけが見分ける）。
	 */
	public function test_leaves_out_a_customer_whose_password_was_changed_without_the_reset_flow(): void {
		$user_id = $this->imported_customer( '1' );

		wp_set_password( 'changed by the admin', $user_id );
		delete_user_meta( $user_id, 'default_password_nag' );

		$this->assertSame( Verdict::PASSWORD_SET, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	/**
	 * パスワードを変えない操作では外れない: 再設定キーを作る（`get_password_reset_key()` は `user_activation_key` だけを書く）・再取込み
	 * （`wp_update_user()` に `user_pass` を渡さない）。
	 */
	public function test_keeps_a_customer_after_operations_that_do_not_change_the_password(): void {
		$user_id = $this->imported_customer( '1' );

		$this->assertIsString( get_password_reset_key( get_userdata( $user_id ) ) );
		( new CustomerWriter( 'colorme' ) )->write( CommerceFactory::customer( '1', 'renamed@example.com' ), $user_id );

		$verdict = ( new RecipientPolicy() )->evaluate( $user_id );

		$this->assertSame( Verdict::SEND, $verdict->outcome );
		$this->assertSame( 'renamed@example.com', get_userdata( $user_id )->user_email, '再取込みが通った' );
	}

	/**
	 * 控えの無い顧客（この版より前の取込み）は判定できないので送る（ユーザー決定 2026-10-13）。ただし再設定が `default_password_nag` を書いていれば外す。
	 */
	public function test_sends_to_a_customer_without_a_record_of_the_created_password(): void {
		$user_id = $this->imported_customer( '1' );
		delete_user_meta( $user_id, CustomerWriter::CREATED_PASSWORD_META );

		$verdict = ( new RecipientPolicy() )->evaluate( $user_id );
		$this->assertSame( Verdict::SEND, $verdict->outcome );
		$this->assertTrue( $verdict->password_unknown );

		// 値が '1' は「生成されたパスワードのまま」の印（`wc_create_new_customer()` がパスワードを生成したとき）なので、設定し直したとは読まない（R1-L7）。
		update_user_meta( $user_id, 'default_password_nag', true );
		$this->assertSame( Verdict::SEND, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );

		update_user_meta( $user_id, 'default_password_nag', false );

		$this->assertSame( Verdict::PASSWORD_SET, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	/**
	 * 控えが読めない値なら「設定し直した」に倒す（送らない側）。
	 */
	public function test_treats_a_broken_record_as_a_changed_password(): void {
		$user_id = $this->imported_customer( '1' );
		update_user_meta( $user_id, CustomerWriter::CREATED_PASSWORD_META, [ 'broken' ] );

		$this->assertSame( Verdict::PASSWORD_SET, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	/**
	 * @dataProvider sent_states
	 */
	public function test_leaves_out_a_customer_already_sent_to( string $state ): void {
		$user_id = $this->imported_customer( '1' );
		update_user_meta( $user_id, SendRecord::META, $state . ':' . self::RUN . ':1700000000' );

		$this->assertSame( Verdict::ALREADY_SENT, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function sent_states(): array {
		return [
			'sent'    => [ 'sent' ],
			'sending' => [ 'sending' ],
		];
	}

	public function test_leaves_out_staff(): void {
		$user_id = $this->imported_customer( '1' );
		get_userdata( $user_id )->set_role( 'shop_manager' );

		$this->assertSame( Verdict::PROTECTED_ACCOUNT, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	/**
	 * メールの一致で採用した既存のアカウント（自分のパスワードがある。作成の印が無い）。
	 */
	public function test_leaves_out_an_existing_account_matched_by_email(): void {
		$existing = self::factory()->user->create(
			[
				'role'       => 'customer',
				'user_email' => 'taro@example.com',
			]
		);
		$result   = ( new CustomerWriter( 'colorme' ) )->write( CommerceFactory::customer( '1', 'taro@example.com' ), null );
		$this->assertSame( $existing, $result->local_id, '採用した' );

		$this->assertSame( Verdict::NOT_CREATED_BY_IMPORT, ( new RecipientPolicy() )->evaluate( $existing )->outcome );
	}

	/**
	 * 作ったアカウントが別の顧客に結び直された（作成時の顧客 ID と今の紐づけが違う）・別のプラットフォームの紐づけになった。
	 */
	public function test_leaves_out_an_account_now_linked_to_another_customer(): void {
		$relinked = $this->imported_customer( '1' );
		update_user_meta( $relinked, '_cbjp_remote_id', '2' );
		$other_platform = $this->imported_customer( '3' );
		update_user_meta( $other_platform, '_cbjp_platform', 'base' );

		$this->assertSame( Verdict::NOT_CREATED_BY_IMPORT, ( new RecipientPolicy() )->evaluate( $relinked )->outcome );
		$this->assertSame( Verdict::NOT_CREATED_BY_IMPORT, ( new RecipientPolicy() )->evaluate( $other_platform )->outcome );
	}

	/**
	 * 0.1.0 の推定は、管理者が含めたときだけ送る（判定できないので `password_unknown`）。推定に無い・作成の印があるアカウントは推定として扱わない。
	 */
	public function test_sends_to_legacy_accounts_only_when_included(): void {
		$legacy = $this->legacy_customer( '7', '2026-09-01 00:00:00' );

		$this->assertSame( Verdict::LEGACY_NOT_INCLUDED, ( new RecipientPolicy( [ $legacy ], false ) )->evaluate( $legacy )->outcome );
		$this->assertSame( Verdict::NOT_CREATED_BY_IMPORT, ( new RecipientPolicy( [], true ) )->evaluate( $legacy )->outcome );

		$verdict = ( new RecipientPolicy( [ $legacy ], true ) )->evaluate( $legacy );
		$this->assertSame( Verdict::SEND, $verdict->outcome );
		$this->assertTrue( $verdict->legacy );
		$this->assertTrue( $verdict->password_unknown );

		update_user_meta( $legacy, CustomerWriter::CREATED_BY_IMPORT_META, 'base' );
		$this->assertSame( Verdict::NOT_CREATED_BY_IMPORT, ( new RecipientPolicy( [ $legacy ], true ) )->evaluate( $legacy )->outcome );
	}

	/**
	 * 紐づけの顧客 ID が空のアカウントは、作成時の顧客 ID も空なら一致してしまうので、取込みで作ったとみなさない（どの顧客のものでもない）。
	 */
	public function test_leaves_out_an_account_without_a_linked_customer_id(): void {
		$user_id = $this->imported_customer( '1' );
		update_user_meta( $user_id, '_cbjp_remote_id', '' );
		update_user_meta( $user_id, CustomerWriter::CREATED_REMOTE_ID_META, '' );

		$this->assertSame( Verdict::NOT_CREATED_BY_IMPORT, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	public function test_leaves_out_an_invalid_email_address(): void {
		global $wpdb;

		$user_id = $this->imported_customer( '1' );
		$wpdb->update( $wpdb->users, [ 'user_email' => 'not-an-email' ], [ 'ID' => $user_id ] );
		clean_user_cache( $user_id );

		$this->assertSame( Verdict::INVALID_EMAIL, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	public function test_leaves_out_a_customer_who_may_not_reset_the_password(): void {
		$user_id = $this->imported_customer( '1' );
		add_filter( 'allow_password_reset', '__return_false' );

		$this->assertSame( Verdict::RESET_NOT_ALLOWED, ( new RecipientPolicy() )->evaluate( $user_id )->outcome );
	}

	public function test_reports_a_missing_user(): void {
		$this->assertSame( Verdict::NOT_FOUND, ( new RecipientPolicy() )->evaluate( 999999 )->outcome );
	}
}
