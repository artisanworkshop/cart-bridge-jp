<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Reader\CouponReader;
use CartBridgeJP\Woo\WarningCode;
use WC_Coupon;

final class CouponReaderTest extends WooTestCase {

	public function set_up(): void {
		parent::set_up();
		// `CouponReader`がCURRENCY_MISMATCHをexport-blockingにするため、テスト環境の既定通貨
		// （USD）のままだと`minimum_amount`を設定するテストが無条件に警告を持つ
		// （`OrderReaderTest`と同じ理由）。
		update_option( 'woocommerce_currency', 'JPY' );
	}

	private function make_reader(): CouponReader {
		return new CouponReader();
	}

	/**
	 * `WC_Coupon_Data_Store_CPT::update()`は`code`/`description`/`date_created`/`date_modified`/
	 * `status`のいずれかが変更された場合のみ`post_status`を`$coupon->get_status('edit')`で
	 * 上書きする。多くの`*_marks_unsupported`系テストは本メソッドの1回目の保存後に
	 * `set_product_ids()`等（このいずれにも該当しない）を追加して2回目の保存を行うため実害は
	 * 無いが、`set_status('publish')`を1回目の保存前に明示しておけば、将来
	 * `set_description()`/`set_code()`等を追加するテストが増えても`post_status`が`null`扱いに
	 * ならず安全になる（実測: 状態を明示しないインスタンスへ2回目以降の保存でこれらのプロパティを
	 * 変更すると`post_status`が`draft`へ落ちる。CLAUDE.md参照）。
	 */
	private function create_coupon( string $code, string $discount_type = 'fixed_cart', string $amount = '500' ): WC_Coupon {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $discount_type );
		$coupon->set_amount( $amount );
		$coupon->set_status( 'publish' );
		$coupon->save();

		return $coupon;
	}

	/**
	 * `WC_Coupon::save()`は新規作成（ID未確定）時のみ、状態プロパティが未設定（`null`）なら
	 * `post_status`を`'publish'`へフォールバックする（`WC_Coupon_Data_Store_CPT::create()`）。
	 * 2回目以降の`save()`（更新）は`$coupon->get_status('edit')`（インメモリの値）をそのまま
	 * `wp_update_post()`へ渡すため、`set_status()`を一度も呼んでいないインスタンスへ追加の
	 * setterを重ねて2回目の`save()`をすると`post_status`が`null`扱いになり、WordPress core側の
	 * 既定（`draft`）へ静かに落ちる（実測確認済み）。全フィールドを1回の`save()`で確定させ、
	 * この罠を踏まないようにする。
	 */
	public function test_reads_core_fields_with_no_restrictions(): void {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'SAVE10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( '10' );
		$coupon->set_free_shipping( true );
		$coupon->set_usage_limit( 5 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_minimum_amount( '1000' );
		$coupon->set_description( 'Ten percent off' );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertCount( 1, $page->items );

		$item      = $page->items[0];
		$canonical = $item->item;

		$this->assertSame( $coupon->get_id(), $item->local_id );
		$this->assertSame( [], $item->warnings );
		// WC標準の挙動として、保存されたクーポンコードは小文字へ正規化される。
		$this->assertSame( 'save10', $canonical->code );
		$this->assertSame( 'percent', $canonical->type );
		$this->assertSame( 10.0, (float) $canonical->amount );
		$this->assertTrue( $canonical->free_shipping );
		$this->assertSame( 5, $canonical->usage_limit );
		$this->assertSame( 1, $canonical->usage_limit_per_user );
		$this->assertSame( 1000.0, (float) $canonical->min_amount );
		$this->assertSame( 'Ten percent off', $canonical->extras['name'] );
		$this->assertFalse( $canonical->has_unsupported_restrictions );
	}

	public function test_fixed_cart_maps_to_fixed_type(): void {
		$coupon = $this->create_coupon( 'FIXED5', 'fixed_cart', '500' );

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertSame( 'fixed', $page->items[0]->item->type );
		$this->assertFalse( $page->items[0]->item->has_unsupported_restrictions );
	}

	/**
	 * `fixed_cart`型の`amount`は店舗通貨での金額。店舗通貨がJPY以外だと、対応ASPが数値をJPYと
	 * 解釈するため無変換でpushすると金額が実質的に変わってしまう（Codex指摘, PR #41 #14）。
	 */
	public function test_fixed_coupon_blocks_export_when_store_currency_is_not_jpy(): void {
		update_option( 'woocommerce_currency', 'USD' );

		try {
			$coupon    = $this->create_coupon( 'USDFIXED', 'fixed_cart', '10' );
			$page      = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
			$read_item = $page->items[0];
		} finally {
			update_option( 'woocommerce_currency', 'JPY' );
		}

		$this->assertContains( WarningCode::with_detail( WarningCode::CURRENCY_MISMATCH, 'USD' ), $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	/**
	 * `percent`型自体は通貨非依存だが、`minimum_amount`（最低購入金額）は店舗通貨での金額のため
	 * 同じ理由でブロックする。
	 */
	public function test_percent_coupon_with_minimum_amount_blocks_export_when_store_currency_is_not_jpy(): void {
		update_option( 'woocommerce_currency', 'USD' );

		try {
			$coupon = $this->create_coupon( 'USDPERCENTMIN', 'percent', '10' );
			$coupon->set_minimum_amount( '50' );
			$coupon->save();
			$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		} finally {
			update_option( 'woocommerce_currency', 'JPY' );
		}

		$this->assertTrue( WarningCode::indicates_export_blocking( $page->items[0]->warnings ) );
	}

	/**
	 * `percent`型で`minimum_amount`が無ければ通貨非依存のため、店舗通貨がJPY以外でもブロックしない。
	 */
	public function test_percent_coupon_without_minimum_amount_does_not_block_on_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );

		try {
			$coupon = $this->create_coupon( 'USDPERCENT', 'percent', '10' );
			$page   = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		} finally {
			update_option( 'woocommerce_currency', 'JPY' );
		}

		$this->assertSame( [], $page->items[0]->warnings );
	}

	/**
	 * `fixed_product`（商品単位の定額値引き）は`CanonicalCoupon::$type`の`fixed`（カート単位）へ
	 * 無警告で丸めると割引の効き方が変わる金銭的リスクがあるため、`has_unsupported_restrictions`で
	 * 保存見送りに倒す（実装計画D参照）。
	 */
	public function test_fixed_product_type_marks_unsupported_restrictions_and_blocks_export(): void {
		$coupon = $this->create_coupon( 'PRODFIX', 'fixed_product', '500' );

		$page      = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$read_item = $page->items[0];

		$this->assertTrue( $read_item->item->has_unsupported_restrictions );
		$this->assertContains( WarningCode::COUPON_RESTRICTIONS_UNSUPPORTED, $read_item->warnings );
		$this->assertTrue( WarningCode::indicates_export_blocking( $read_item->warnings ) );
	}

	public function test_native_product_restriction_marks_unsupported(): void {
		$product_id = self::factory()->post->create( [ 'post_type' => 'product' ] );
		$coupon     = $this->create_coupon( 'PRODONLY' );
		$coupon->set_product_ids( [ $product_id ] );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_native_category_restriction_marks_unsupported(): void {
		$term   = wp_insert_term( 'Shirts', 'product_cat' );
		$coupon = $this->create_coupon( 'CATONLY' );
		$coupon->set_product_categories( [ (int) $term['term_id'] ] );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_native_email_restriction_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'EMAILONLY' );
		$coupon->set_email_restrictions( [ 'vip@example.com' ] );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_maximum_amount_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'MAXAMOUNT' );
		$coupon->set_maximum_amount( '5000' );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_limit_usage_to_x_items_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'LIMITXITEMS' );
		$coupon->set_limit_usage_to_x_items( 1 );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_exclude_sale_items_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'EXCLSALE' );
		$coupon->set_exclude_sale_items( true );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	public function test_individual_use_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'SOLOUSE' );
		$coupon->set_individual_use( true );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
	}

	/**
	 * `CanonicalCoupon`は利用済み回数・利用者を運ぶフィールドを持たないため、既に一部利用済み
	 * （`get_usage_count() > 0`）のクーポンを無警告でpushすると、ASP側に「未使用の元の上限を持つ」
	 * クーポンが新規作成されてしまう（Codex指摘 #5。既存Woo顧客が既に上限まで使い切った制限が
	 * ASP側では働かない金銭的リスク）。
	 */
	public function test_already_used_coupon_marks_unsupported(): void {
		$coupon = $this->create_coupon( 'USEDCODE' );
		$coupon->set_usage_count( 1 );
		$coupon->save();

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );
		$this->assertTrue( $page->items[0]->item->has_unsupported_restrictions );
		$this->assertContains( WarningCode::COUPON_RESTRICTIONS_UNSUPPORTED, $page->items[0]->warnings );
	}

	/**
	 * レビューで「直接のpostmeta編集で負値/percent型で100超に壊れた`coupon_amount`が
	 * `CouponReader`まで無検証で届きうる」という指摘があったが、実測すると`WC_Coupon::
	 * set_amount()`が投げる`WC_Data_Exception`は`WC_Data::set_props()`（`read()`が内部で呼ぶ）が
	 * プロパティ毎にcatchするため、`amount`プロパティはクラス既定値`'0'`のまま未設定になる。
	 * つまり`new WC_Coupon($id)`で読み直した時点で既に安全な値（`'0'`）に丸められており、
	 * `CouponReader`側での追加検証は不要（かつ到達不能）である。この実測結果を固定化する
	 * ピン留めテスト（CLAUDE.md「レビュー指摘に対してテストを書いたら修正なしで通った場合は、
	 * 指摘自体が誤りである可能性をまず疑うこと」の実例）。
	 */
	public function test_corrupted_negative_amount_reads_back_as_zero_not_negative(): void {
		$coupon = $this->create_coupon( 'NEGATIVEAMOUNT', 'fixed_cart', '500' );
		update_post_meta( $coupon->get_id(), 'coupon_amount', '-50' );

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );

		$this->assertSame( 0.0, (float) $page->items[0]->item->amount );
		$this->assertFalse( $page->items[0]->item->has_unsupported_restrictions );
	}

	/**
	 * レビューで「直接のpostmeta編集で解釈不能な`date_expires`に壊れた場合、`get_date_expires()`
	 * が`null`を返し『無期限クーポン』と区別が付かなくなる」という指摘があったが、実測すると
	 * `WC_Data::set_date_prop()`（`set_amount()`とは異なりプロパティ内で自前のtry/catchを持つ）は
	 * 解釈不能な文字列を`wc_string_to_timestamp()`の失敗フォールバック経由でUNIXエポック
	 * （1970-01-01T00:00:00Z＝既に期限切れの過去日）へ解決し、`null`にはならない。つまり
	 * 壊れた期限は「無期限」ではなく「常に期限切れ」として安全側に転ぶ。この実測結果を固定化する
	 * ピン留めテスト（`test_corrupted_negative_amount_reads_back_as_zero_not_negative`と同じ、
	 * CLAUDE.md「指摘自体が誤りである可能性をまず疑うこと」の実例。Codex指摘, PR #41 #15）。
	 */
	public function test_corrupted_expiry_metadata_resolves_to_past_epoch_not_unlimited(): void {
		$coupon = $this->create_coupon( 'CORRUPTEXPIRY', 'fixed_cart', '500' );
		update_post_meta( $coupon->get_id(), 'date_expires', 'not-a-real-date-at-all' );

		$page = $this->make_reader()->query( Cursor::start(), [ $coupon->get_id() ] );

		$this->assertSame( '1970-01-01T00:00:00+00:00', $page->items[0]->item->expires_at );
	}

	/**
	 * D15 §10.2「クーポン: 最新10件」＝新しい順。'ID'昇順（作成日昇順）のままだと無料版の
	 * `LimitPolicy`上限が古いクーポンだけを消費してしまう（レビュー指摘）。
	 */
	public function test_query_orders_newest_first(): void {
		$older = $this->create_coupon( 'OLDER' );
		$newer = $this->create_coupon( 'NEWER' );

		wp_update_post(
			[
				'ID'            => $older->get_id(),
				'post_date'     => '2020-01-01 00:00:00',
				'post_date_gmt' => '2020-01-01 00:00:00',
			]
		);
		wp_update_post(
			[
				'ID'            => $newer->get_id(),
				'post_date'     => '2024-01-01 00:00:00',
				'post_date_gmt' => '2024-01-01 00:00:00',
			]
		);

		$page = $this->make_reader()->query( Cursor::start(), null );
		$ids  = array_map( static fn ( $item ) => $item->local_id, $page->items );

		$this->assertSame( $newer->get_id(), $ids[0] );
		$this->assertSame( $older->get_id(), $ids[1] );
	}

	/**
	 * `'date' => 'DESC'`単独だと`post_date`が同一のクーポン間の順序がMySQL実装依存になり、
	 * ページ跨ぎで重複/欠落しうる。`ID`を副ソートキーにして決定的にする（R2レビュー指摘）。
	 */
	public function test_query_orders_deterministically_when_dates_are_identical(): void {
		$ids = [];

		for ( $i = 0; $i < 22; $i++ ) {
			$coupon = $this->create_coupon( "SAMEDATE{$i}" );
			wp_update_post(
				[
					'ID'            => $coupon->get_id(),
					'post_date'     => '2024-01-01 00:00:00',
					'post_date_gmt' => '2024-01-01 00:00:00',
				]
			);
			$ids[] = $coupon->get_id();
		}

		$reader     = $this->make_reader();
		$first_page = $reader->query( Cursor::start(), null );

		$this->assertCount( 20, $first_page->items );
		$this->assertNotNull( $first_page->next_cursor );

		$second_page = $reader->query( $first_page->next_cursor, null );

		$this->assertCount( 2, $second_page->items );

		$all_ids = array_merge(
			array_map( static fn ( $item ) => $item->local_id, $first_page->items ),
			array_map( static fn ( $item ) => $item->local_id, $second_page->items )
		);
		sort( $all_ids );
		sort( $ids );
		// 重複・欠落なく全件が過不足なく1回ずつ現れることを確認する（同一日時での決定的な順序）。
		$this->assertSame( $ids, $all_ids );
	}

	public function test_only_local_ids_empty_returns_empty_page(): void {
		$page = $this->make_reader()->query( Cursor::start(), [] );
		$this->assertSame( [], $page->items );
		$this->assertNull( $page->next_cursor );
	}

	public function test_query_walks_multiple_pages(): void {
		$ids = [];

		for ( $i = 0; $i < 22; $i++ ) {
			$ids[] = $this->create_coupon( "CODE{$i}" )->get_id();
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
