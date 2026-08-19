<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\CouponWriter;

final class CouponWriterTest extends WooTestCase {

	private function make_writer(): CouponWriter {
		return new CouponWriter( 'colorme' );
	}

	public function test_fixed_type_maps_to_fixed_cart(): void {
		$coupon = new CanonicalCoupon( 'SAVE500', 'fixed', '500', null, '2026-12-31', 10, [ 'remote_id' => '1' ] );

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );

		$wc_coupon = new \WC_Coupon( $result->local_id );
		$this->assertSame( 'fixed_cart', $wc_coupon->get_discount_type() );
		$this->assertSame( '500', $wc_coupon->get_amount() );
		$this->assertSame( 10, $wc_coupon->get_usage_limit() );
	}

	public function test_percent_type_maps_to_percent(): void {
		$coupon = new CanonicalCoupon( 'TENOFF', 'percent', '10', null, null, null, [ 'remote_id' => '2' ] );

		$result    = $this->make_writer()->write( $coupon, null );
		$wc_coupon = new \WC_Coupon( $result->local_id );

		$this->assertSame( 'percent', $wc_coupon->get_discount_type() );
	}

	public function test_free_shipping_flag_is_applied(): void {
		$coupon = new CanonicalCoupon( 'FREESHIP', 'fixed', '0', null, null, null, [ 'remote_id' => '3' ], true );

		$result    = $this->make_writer()->write( $coupon, null );
		$wc_coupon = new \WC_Coupon( $result->local_id );

		$this->assertTrue( $wc_coupon->get_free_shipping() );
	}

	public function test_reuses_existing_coupon_with_same_code_and_matching_platform(): void {
		$existing = new \WC_Coupon();
		$existing->set_code( 'DUPLICATE' );
		$existing->update_meta_data( '_cbjp_platform', 'colorme' );
		$existing_id = $existing->save();

		$coupon = new CanonicalCoupon( 'DUPLICATE', 'fixed', '100', null, null, null, [ 'remote_id' => '4' ] );
		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( $existing_id, $result->local_id );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_REUSED_EXISTING, (string) $existing_id ), $result->warnings );
	}

	public function test_code_conflict_with_another_platform_is_skipped_not_overwritten(): void {
		$existing = new \WC_Coupon();
		$existing->set_code( 'DUPLICATE' );
		$existing->set_amount( '9999' );
		$existing->update_meta_data( '_cbjp_platform', 'makeshop' );
		$existing_id = $existing->save();

		$coupon = new CanonicalCoupon( 'DUPLICATE', 'fixed', '100', null, null, null, [ 'remote_id' => '4' ] );
		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_CODE_CONFLICT, (string) $existing_id ), $result->warnings );

		// 他プラットフォーム由来のクーポンは一切上書きされていない。
		$this->assertSame( '9999', ( new \WC_Coupon( $existing_id ) )->get_amount() );
	}

	public function test_code_conflict_with_unmanaged_coupon_is_skipped_not_overwritten(): void {
		$existing = new \WC_Coupon();
		$existing->set_code( 'DUPLICATE' );
		$existing->set_amount( '9999' );
		$existing_id = $existing->save();

		$coupon = new CanonicalCoupon( 'DUPLICATE', 'fixed', '100', null, null, null, [ 'remote_id' => '4' ] );
		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( '9999', ( new \WC_Coupon( $existing_id ) )->get_amount() );
	}

	public function test_group_limited_coupon_is_skipped_not_saved_unrestricted(): void {
		$coupon = new CanonicalCoupon(
			'MEMBERS-ONLY',
			'fixed',
			'500',
			null,
			null,
			null,
			[
				'remote_id'        => '6',
				'group_limit_type' => 'specified',
			]
		);

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::COUPON_GROUP_LIMIT_UNSUPPORTED, $result->warnings );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'MEMBERS-ONLY' ) );
	}

	public function test_update_path_code_rename_conflict_is_skipped_not_overwritten(): void {
		// 新規作成時のコード衝突チェック（56-74行目）は`get_id()===0`のときしか走らない。
		// 既存の有効なクーポン（mapping先が実在）のコードがASP側でリネームされた場合も、
		// コードの一意性が崩れると決済時にどちらが適用されるか不定になる同じ金銭的リスクが
		// あるため、更新パスでも衝突チェックが必要であることを確認する。
		$other = new \WC_Coupon();
		$other->set_code( 'TAKEN' );
		$other->set_amount( '9999' );
		$other->save();

		$existing = new \WC_Coupon();
		$existing->set_code( 'ORIGINAL' );
		$existing->set_amount( '100' );
		$existing->update_meta_data( '_cbjp_platform', 'colorme' );
		$existing_id = $existing->save();

		$coupon = new CanonicalCoupon( 'TAKEN', 'fixed', '200', null, null, null, [ 'remote_id' => '8' ] );
		$result = $this->make_writer()->write( $coupon, $existing_id );

		// local_id 0を返す: `Importer`はlocal_id!==0であればoperationに関わらずchecksumを
		// mappingsへupsertするため、ここで既存クーポンのIDを返すとリネームが実際には
		// 適用されなかったにも関わらず次回以降checksum一致でこの衝突チェック自体が
		// スキップされ、衝突が解消された後も永久に再試行されなくなる（既存の有効な
		// mappingはImporter側でupsert自体が発生しないため変更されず残る）。
		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_CODE_CONFLICT, (string) $other->get_id() ), $result->warnings );

		// 元のクーポンのコード・金額は変更されていない（WC_Coupon側の仕様でコードは小文字化される）。
		$reloaded = new \WC_Coupon( $existing_id );
		$this->assertSame( 'original', $reloaded->get_code() );
		$this->assertSame( '100', $reloaded->get_amount() );
	}

	public function test_unknown_type_is_skipped_and_warns(): void {
		// `CanonicalCoupon::$type`はdocblock上'fixed'|'percent'だが実行時にはstringでしかなく、
		// `cbjp/adapters/register`経由の外部アダプタが未知の値を渡しうる。deny-list判定だと
		// 未知の値が黙って`fixed_cart`として保存され金銭的リスクになるため、allow-listで
		// 保存自体を見送ることを確認する。
		$coupon = new CanonicalCoupon( 'WEIRD', 'buy_one_get_one', '100', null, null, null, [ 'remote_id' => '7' ] );

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_TYPE_UNKNOWN, 'buy_one_get_one' ), $result->warnings );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'WEIRD' ) );
	}

	public function test_negative_amount_is_skipped_and_warns(): void {
		// `WC_Coupon::set_amount()`自身が負値を拒否して例外を投げるが、これまでは他writerと
		// 異なり事前検証が無く、その例外が`Importer`の汎用catch-allに落ちて専用の警告コードが
		// 残らなかった。ここで保存自体を見送りフェイルクローズすることを確認する。
		$coupon = new CanonicalCoupon( 'NEGATIVE', 'fixed', '-100', null, null, null, [ 'remote_id' => '8' ] );

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_AMOUNT_INVALID, '-100' ), $result->warnings );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'NEGATIVE' ) );
	}

	public function test_percent_amount_over_100_is_skipped_and_warns(): void {
		$coupon = new CanonicalCoupon( 'TOOMUCH', 'percent', '150', null, null, null, [ 'remote_id' => '9' ] );

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_AMOUNT_INVALID, '150' ), $result->warnings );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'TOOMUCH' ) );
	}

	public function test_unparseable_expires_at_is_skipped_and_warns(): void {
		// `null`（無期限）は正当だが、値が設定されているのに`strtotime()`で解釈できない
		// 文字列（外部アダプタ側の不具合等）を`WC_Coupon::set_date_expires()`にそのまま
		// 渡すと`Importer`の汎用catch-allに落ちて専用の警告が残らない。事前検証で
		// フェイルクローズすることを確認する。
		$coupon = new CanonicalCoupon( 'BADDATE', 'fixed', '100', null, 'not-a-date', null, [ 'remote_id' => '10' ] );

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_EXPIRES_AT_INVALID, 'not-a-date' ), $result->warnings );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'BADDATE' ) );
	}

	public function test_null_expires_at_is_accepted_as_no_expiry(): void {
		$coupon = new CanonicalCoupon( 'FOREVER', 'fixed', '100', null, null, null, [ 'remote_id' => '11' ] );

		$result    = $this->make_writer()->write( $coupon, null );
		$wc_coupon = new \WC_Coupon( $result->local_id );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNull( $wc_coupon->get_date_expires() );
	}

	public function test_negative_min_amount_is_skipped_and_warns(): void {
		// `WC_Coupon::set_minimum_amount()`は`wc_format_decimal()`を通すのみで符号を検証
		// しないため、負値がそのまま「最低購入金額」として保存されると意図せずクーポン
		// 適用条件が緩む金銭的リスクがある。
		$coupon = new CanonicalCoupon( 'BADMIN', 'fixed', '100', '-500', null, null, [ 'remote_id' => '12' ] );

		$result = $this->make_writer()->write( $coupon, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::COUPON_MIN_AMOUNT_INVALID, '-500' ), $result->warnings );
		$this->assertSame( 0, wc_get_coupon_id_by_code( 'BADMIN' ) );
	}

	public function test_usage_limit_per_user_is_applied(): void {
		$coupon = new CanonicalCoupon( 'ONEUSE', 'fixed', '100', null, null, null, [ 'remote_id' => '5' ], false, 1 );

		$result    = $this->make_writer()->write( $coupon, null );
		$wc_coupon = new \WC_Coupon( $result->local_id );

		$this->assertSame( 1, $wc_coupon->get_usage_limit_per_user() );
	}
}
