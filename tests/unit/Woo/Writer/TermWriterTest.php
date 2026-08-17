<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Woo\WooTestCase;
use CartBridgeJP\Woo\Support\MediaImporter;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\TermWriter;

final class TermWriterTest extends WooTestCase {

	private function make_writer( string $taxonomy = 'product_cat' ): TermWriter {
		return new TermWriter( $taxonomy, 'colorme', $this->mappings, new MediaImporter() );
	}

	public function test_creates_new_category_term(): void {
		$category = new CanonicalCategory(
			'100',
			'Apparel',
			null,
			null,
			[
				'description' => 'desc',
				'sort'        => 3,
			]
		);

		$result = $this->make_writer()->write( $category, null );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertGreaterThan( 0, $result->local_id );

		$term = get_term( $result->local_id, 'product_cat' );
		$this->assertSame( 'Apparel', $term->name );
		$this->assertSame( 'desc', $term->description );
		$this->assertSame( '3', get_term_meta( $result->local_id, 'order', true ) );
	}

	public function test_updates_existing_term_by_mapping(): void {
		$term_id  = wp_insert_term( 'Old name', 'product_cat' )['term_id'];
		$category = new CanonicalCategory( '100', 'New name', null, null );

		$result = $this->make_writer()->write( $category, $term_id );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );
		$this->assertSame( $term_id, $result->local_id );

		$term = get_term( $term_id, 'product_cat' );
		$this->assertSame( 'New name', $term->name );
	}

	public function test_resolves_parent_via_mapping(): void {
		$parent_term_id = wp_insert_term( 'Parent', 'product_cat' )['term_id'];
		$this->seed_mapping( 'colorme', 'category', '100', $parent_term_id );

		$child  = new CanonicalCategory( '100-1', 'Child', '100', null );
		$result = $this->make_writer()->write( $child, null );

		$term = get_term( $result->local_id, 'product_cat' );
		$this->assertSame( $parent_term_id, $term->parent );
	}

	public function test_warns_when_parent_unresolved(): void {
		$child  = new CanonicalCategory( '100-1', 'Child', '999', null );
		$result = $this->make_writer()->write( $child, null );

		$this->assertContains( WarningCode::with_detail( WarningCode::CATEGORY_PARENT_UNRESOLVED, '999' ), $result->warnings );

		$term = get_term( $result->local_id, 'product_cat' );
		$this->assertSame( 0, $term->parent );
	}

	public function test_reuses_existing_term_on_name_conflict_when_platform_matches(): void {
		$existing_id = wp_insert_term( 'Apparel', 'product_cat' )['term_id'];
		update_term_meta( $existing_id, '_cbjp_platform', 'colorme' );

		$category = new CanonicalCategory( '100', 'Apparel', null, null );
		$result   = $this->make_writer()->write( $category, null );

		$this->assertSame( WriteResult::OPERATION_UPDATED, $result->operation );
		$this->assertSame( $existing_id, $result->local_id );
		$this->assertTrue(
			(bool) array_filter(
				$result->warnings,
				static fn ( string $w ): bool => str_starts_with( $w, WarningCode::TERM_REUSED_EXISTING )
			)
		);
	}

	public function test_name_conflict_with_another_platform_or_manual_term_is_skipped(): void {
		// `_cbjp_platform`メタが無い（店舗が手動作成した）または別プラットフォーム由来の
		// タームと名前が衝突した場合、上書きせず保存を見送る。
		$existing_id = wp_insert_term( 'Apparel', 'product_cat' )['term_id'];

		$category = new CanonicalCategory( '100', 'Apparel', null, null );
		$result   = $this->make_writer()->write( $category, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::with_detail( WarningCode::TERM_NAME_CONFLICT, (string) $existing_id ), $result->warnings );

		// 手動作成タームの名前は上書きされていない。
		$term = get_term( $existing_id, 'product_cat' );
		$this->assertSame( 'Apparel', $term->name );
	}

	public function test_update_validation_error_is_not_treated_as_deleted_term(): void {
		// `wp_update_term()`は対象タームが削除済みのとき以外にも、名前バリデーション
		// （空の名前・親不在等）でWP_Errorを返しうる。これらを「削除済みなので新規作成」と
		// 誤認してcreate_or_reuse()に回すと、無関係な同名タームを誤って再利用しかねない
		// （term_existsのdata経由で衝突先を再利用してしまう）。
		$term_id  = wp_insert_term( 'Original', 'product_cat' )['term_id'];
		$category = new CanonicalCategory( '100', '', null, null );

		$result = $this->make_writer()->write( $category, $term_id );

		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertSame( $term_id, $result->local_id );
		$this->assertContains( WarningCode::with_detail( WarningCode::TERM_UPDATE_FAILED, 'empty_term_name' ), $result->warnings );

		// 元のタームの名前は変更されていない（新規タームも作られていない）。
		$term = get_term( $term_id, 'product_cat' );
		$this->assertSame( 'Original', $term->name );
	}

	public function test_create_failure_other_than_term_exists_is_surfaced_as_a_warning(): void {
		// `wp_insert_term()`は空の名前だと`term_exists`とは別の`empty_term_name`エラーを
		// 返す。無警告のまま握りつぶすと結果レポートから欠落理由が分からなくなるため、
		// 警告として可視化されることを確認する。
		$category = new CanonicalCategory( '100', '', null, null );
		$result   = $this->make_writer()->write( $category, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains(
			WarningCode::with_detail( WarningCode::TERM_CREATE_FAILED, 'empty_term_name' ),
			$result->warnings
		);
	}

	public function test_tag_taxonomy_creates_product_tag_term(): void {
		$writer = $this->make_writer( 'product_tag' );
		$tag    = new \CartBridgeJP\Canonical\CanonicalTag( '55', 'Sale' );

		$result = $writer->write( $tag, null );

		$term = get_term( $result->local_id, 'product_tag' );
		$this->assertSame( 'Sale', $term->name );
	}
}
