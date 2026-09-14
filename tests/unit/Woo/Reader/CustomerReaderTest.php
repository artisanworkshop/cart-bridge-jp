<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\CustomerReader;

final class CustomerReaderTest extends WooTestCase {

	private function make_reader(): CustomerReader {
		return new CustomerReader();
	}

	private function create_customer( string $email, array $meta = [] ): int {
		$user_id = wp_insert_user(
			[
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password(),
				'role'         => 'customer',
				'first_name'   => $meta['first_name'] ?? 'Taro',
				'last_name'    => $meta['last_name'] ?? 'Yamada',
				'display_name' => $meta['display_name'] ?? 'Taro Yamada',
			]
		);

		foreach ( $meta['user_meta'] ?? [] as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}

		return $user_id;
	}

	public function test_reads_core_fields_and_address(): void {
		$user_id = $this->create_customer(
			'taro@example.com',
			[
				'user_meta' => [
					'billing_company'   => 'Acme Inc',
					'billing_address_1' => '1-2-3 Marunouchi',
					'billing_address_2' => 'Suite 4',
					'billing_city'      => 'Chiyoda',
					'billing_state'     => 'JP13',
					'billing_postcode'  => '100-0001',
					'billing_country'   => 'JP',
					'billing_phone'     => '0312345678',
					'_cbjp_kana'        => 'ヤマダ タロウ',
					'_cbjp_department'  => 'Sales',
					'_cbjp_birthday'    => '1990-01-01',
					'_cbjp_note'        => 'VIP customer',
				],
			]
		);

		$page = $this->make_reader()->query( Cursor::start(), [ $user_id ] );
		$this->assertCount( 1, $page->items );

		$item      = $page->items[0];
		$canonical = $item->item;

		$this->assertSame( $user_id, $item->local_id );
		$this->assertSame( [], $item->warnings );
		$this->assertTrue( $item->fully_resolved );

		$this->assertSame( 'taro@example.com', $canonical->email );
		$this->assertSame( 'Taro Yamada', $canonical->name );
		$this->assertSame( 'ヤマダ タロウ', $canonical->kana );
		$this->assertSame( 'Acme Inc', $canonical->company );
		$this->assertSame( 'Sales', $canonical->department );
		$this->assertSame( '0312345678', $canonical->phone );
		$this->assertSame( '1990-01-01', $canonical->birthday );
		$this->assertSame( 'VIP customer', $canonical->note );
		$this->assertSame( '1-2-3 Marunouchi', $canonical->address['address_1'] );
		$this->assertSame( 'Suite 4', $canonical->address['address_2'] );
		$this->assertSame( 'Chiyoda', $canonical->address['city'] );
		$this->assertSame( 'JP13', $canonical->address['state'] );
		$this->assertSame( '100-0001', $canonical->address['postcode'] );
		$this->assertSame( 'JP', $canonical->address['country'] );
	}

	public function test_mailmag_opt_in_reads_back_from_stored_bool_string(): void {
		$opted_in  = $this->create_customer( 'opted-in@example.com', [ 'user_meta' => [ '_cbjp_mailmag_opt_in' => '1' ] ] );
		$opted_out = $this->create_customer( 'opted-out@example.com', [ 'user_meta' => [ '_cbjp_mailmag_opt_in' => '0' ] ] );
		$unset     = $this->create_customer( 'unset@example.com' );

		$page  = $this->make_reader()->query( Cursor::start(), [ $opted_in, $opted_out, $unset ] );
		$by_id = [];

		foreach ( $page->items as $item ) {
			$by_id[ $item->local_id ] = $item->item;
		}

		$this->assertTrue( $by_id[ $opted_in ]->mailmag_opt_in );
		$this->assertFalse( $by_id[ $opted_out ]->mailmag_opt_in );
		$this->assertNull( $by_id[ $unset ]->mailmag_opt_in );
	}

	public function test_name_falls_back_to_display_name_when_first_and_last_name_are_empty(): void {
		$user_id = wp_insert_user(
			[
				'user_login'   => 'noname@example.com',
				'user_email'   => 'noname@example.com',
				'user_pass'    => wp_generate_password(),
				'role'         => 'customer',
				'display_name' => 'Anonymous Shopper',
			]
		);

		$page = $this->make_reader()->query( Cursor::start(), [ $user_id ] );
		$this->assertSame( 'Anonymous Shopper', $page->items[0]->item->name );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}

	public function test_query_only_returns_customer_role_users(): void {
		$customer_id = $this->create_customer( 'shopper@example.com' );

		$admin_id = wp_insert_user(
			[
				'user_login' => 'staff@example.com',
				'user_email' => 'staff@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			]
		);

		$page = $this->make_reader()->query( Cursor::start(), null );
		$ids  = array_map( static fn ( $item ) => $item->local_id, $page->items );

		$this->assertContains( $customer_id, $ids );
		// 管理者アカウントがPII付きでexportされてはならない（`role => 'customer'`絞り込みの
		// ピン留め。この絞り込みが外れても本テストが検知できるよう明示的に確認する）。
		$this->assertNotContains( $admin_id, $ids );
	}

	/**
	 * `role => 'customer'`は「customerロールを持つ」の意味で、他ロールを併せ持つことを
	 * 否定しない。店舗の管理者・スタッフアカウントに`customer`ロールも付与されている場合
	 * （WP上では複数ロールの併存が可能）、`role`絞り込みだけではPII付きでexportされてしまう
	 * （`Woo\Writer\CustomerWriter::PROTECTED_ROLES`と同じ一覧で除外する必要がある。
	 * レビュー指摘）。
	 */
	public function test_query_excludes_users_who_also_have_a_protected_role(): void {
		$plain_customer_id = $this->create_customer( 'plain@example.com' );

		$shop_manager_customer_id = wp_insert_user(
			[
				'user_login' => 'manager@example.com',
				'user_email' => 'manager@example.com',
				'user_pass'  => wp_generate_password(),
				'role'       => 'shop_manager',
			]
		);
		( new \WP_User( $shop_manager_customer_id ) )->add_role( 'customer' );

		$page = $this->make_reader()->query( Cursor::start(), null );
		$ids  = array_map( static fn ( $item ) => $item->local_id, $page->items );

		$this->assertContains( $plain_customer_id, $ids );
		$this->assertNotContains( $shop_manager_customer_id, $ids );
	}

	/**
	 * ColorMeの氏名は「姓 名」の単一文字列で、`Woo\Support\AddressMapper::split_name()`が
	 * 最初のトークンを`last_name`、残りを`first_name`としてWooへ保存する
	 * （`Woo\Writer\CustomerWriter`）。`first_name . ' ' . last_name`で単純に組み直すと
	 * 姓名が入れ替わって復元されるため、`_cbjp_full_name`メタ（元の文字列そのもの）を優先して
	 * 使うことを確認する（レビュー指摘）。
	 */
	public function test_name_prefers_full_name_meta_over_recomposed_first_last(): void {
		$user_id = $this->create_customer(
			'yamada@example.com',
			[
				// 「山田 太郎」を分割すると最初のトークン「山田」がlast_name、残りの「太郎」が
				// first_nameになる（`Woo\Support\AddressMapper::split_name()`の変換結果）。
				'first_name' => '太郎',
				'last_name'  => '山田',
				'user_meta'  => [ '_cbjp_full_name' => '山田 太郎' ],
			]
		);

		$page = $this->make_reader()->query( Cursor::start(), [ $user_id ] );
		// `first_name . ' ' . last_name`（Western順）で組み直すと「太郎 山田」になってしまう。
		$this->assertSame( '山田 太郎', $page->items[0]->item->name );
	}

	public function test_name_falls_back_to_first_last_when_full_name_meta_is_absent(): void {
		$user_id = $this->create_customer( 'no-meta@example.com' );

		$page = $this->make_reader()->query( Cursor::start(), [ $user_id ] );
		$this->assertSame( 'Taro Yamada', $page->items[0]->item->name );
	}

	public function test_query_walks_multiple_pages(): void {
		$ids = [];

		for ( $i = 0; $i < 22; $i++ ) {
			$ids[] = $this->create_customer( "customer{$i}@example.com" );
		}

		$reader     = $this->make_reader();
		$first_page = $reader->query( Cursor::start(), null );

		$this->assertCount( 20, $first_page->items );
		$this->assertNotNull( $first_page->next_cursor );

		$second_page = $reader->query( $first_page->next_cursor, null );

		$this->assertCount( 2, $second_page->items );
		$this->assertNull( $second_page->next_cursor );

		$all_ids = array_merge(
			array_map( static fn ( $item ) => $item->local_id, $first_page->items ),
			array_map( static fn ( $item ) => $item->local_id, $second_page->items )
		);
		sort( $all_ids );
		sort( $ids );
		$this->assertSame( $ids, $all_ids );
	}
}
