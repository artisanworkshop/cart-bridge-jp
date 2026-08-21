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
		return new TermWriter( $taxonomy, 'colorme', $this->mappings, new MediaImporter( 'colorme' ) );
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

	public function test_extras_not_on_the_hardcoded_whitelist_are_still_persisted(): void {
		// sort/meta_tag/image_url以外のextrasキーを個別ホワイトリスト管理せず、
		// ProductWriter/OrderWriter等と同じ`ExtrasMeta`経由で汎用的に保存されることを確認する
		// （将来別ASPが異なるextras構成を持つ場合のデータ欠損を防ぐ）。
		$category = new CanonicalCategory(
			'100',
			'Apparel',
			null,
			null,
			[
				'note' => '店舗独自メモ',
			]
		);

		$result = $this->make_writer()->write( $category, null );

		$this->assertSame( '店舗独自メモ', get_term_meta( $result->local_id, '_cbjp_note', true ) );
	}

	public function test_boolean_false_extras_are_persisted_as_zero_not_empty_string(): void {
		// bool `false`をそのまま`update_term_meta()`等に渡すとSQLへ渡す際に空文字列へ変換され、
		// キー未設定と区別できなくなる（`ExtrasMeta::apply_via()`の正規化で防ぐ）。
		$category = new CanonicalCategory(
			'100',
			'Apparel',
			null,
			null,
			[
				'featured' => false,
			]
		);

		$result = $this->make_writer()->write( $category, null );

		$this->assertSame( '0', get_term_meta( $result->local_id, '_cbjp_featured', true ) );
	}

	public function test_thumbnail_download_failure_is_surfaced_as_a_warning(): void {
		// `MediaImporter::import()`のnull返却契約: 呼び出し側が警告を積む必要がある
		// （`ProductWriter::apply_images()`の同種の失敗処理と同じ方針）。
		add_filter(
			'pre_http_request',
			static fn () => new \WP_Error( 'http_request_failed', 'boom' ),
			10,
			3
		);

		$category = new CanonicalCategory(
			'100',
			'Apparel',
			null,
			null,
			[
				'image_url' => 'https://example.test/thumb.png',
			]
		);

		$result = $this->make_writer()->write( $category, null );

		$this->assertSame( '', get_term_meta( $result->local_id, 'thumbnail_id', true ) );
		$this->assertContains(
			WarningCode::with_detail( WarningCode::IMAGE_DOWNLOAD_FAILED, 'https://example.test/thumb.png' ),
			$result->warnings
		);
	}

	public function test_manually_set_thumbnail_is_not_cleared_when_image_url_is_absent(): void {
		// `thumbnail_id`はWoo標準のカテゴリ画像で、店舗がwp-adminから手動設定していることも
		// ある。ASP側でimage_urlが無い（未設定）だけで無条件に削除すると、店舗が手動設定した
		// 画像を消してしまう（`ProductWriter::apply_images()`がユーザー追加画像を保護する
		// のと同じ理由）。
		$term_id       = wp_insert_term( 'Apparel', 'product_cat' )['term_id'];
		$attachment_id = self::factory()->attachment->create();
		update_term_meta( $term_id, 'thumbnail_id', $attachment_id );

		$category = new CanonicalCategory( '100', 'Apparel', null, null );
		$this->make_writer()->write( $category, $term_id );

		$this->assertSame( (string) $attachment_id, get_term_meta( $term_id, 'thumbnail_id', true ) );
	}

	public function test_own_previously_imported_thumbnail_is_cleared_when_image_url_is_removed(): void {
		// 逆に、以前このwriter自身（同一プラットフォーム）が取り込んだ添付
		// （`_cbjp_source_url`かつ`_cbjp_platform`が自分自身）であれば、ASP側でimage_urlが
		// 削除された場合に古い画像を残さない。
		$term_id       = wp_insert_term( 'Apparel', 'product_cat' )['term_id'];
		$attachment_id = self::factory()->attachment->create();
		update_post_meta( $attachment_id, '_cbjp_source_url', 'https://example.test/old-thumb.png' );
		update_post_meta( $attachment_id, '_cbjp_platform', 'colorme' );
		update_term_meta( $term_id, 'thumbnail_id', $attachment_id );

		$category = new CanonicalCategory( '100', 'Apparel', null, null );
		$this->make_writer()->write( $category, $term_id );

		$this->assertSame( '', get_term_meta( $term_id, 'thumbnail_id', true ) );
	}

	public function test_other_platforms_thumbnail_is_not_cleared_when_image_url_is_removed(): void {
		// D16のリンク再構築ツール等で複数プラットフォームが同一タームを共有しうるため、
		// `_cbjp_source_url`の有無だけで判定すると、他プラットフォームが取り込んだサムネイルを
		// 誤って削除してしまう。`_cbjp_platform`が自分自身と一致しない場合は残すことを確認する。
		$term_id       = wp_insert_term( 'Apparel', 'product_cat' )['term_id'];
		$attachment_id = self::factory()->attachment->create();
		update_post_meta( $attachment_id, '_cbjp_source_url', 'https://example.test/other-thumb.png' );
		update_post_meta( $attachment_id, '_cbjp_platform', 'makeshop' );
		update_term_meta( $term_id, 'thumbnail_id', $attachment_id );

		$category = new CanonicalCategory( '100', 'Apparel', null, null );
		$this->make_writer()->write( $category, $term_id );

		$this->assertSame( (string) $attachment_id, get_term_meta( $term_id, 'thumbnail_id', true ) );
	}

	public function test_manually_set_sort_order_is_not_cleared_when_sort_is_absent(): void {
		// `order`はWoo標準の並び順メタで、`_cbjp_*`と異なりこのプラグインが書いたものか
		// 店舗が手動設定したものかを判別できない。sortが欠損しても既存値を消してはいけない。
		$term_id = wp_insert_term( 'Apparel', 'product_cat' )['term_id'];
		update_term_meta( $term_id, 'order', 7 );

		$category = new CanonicalCategory( '100', 'Apparel', null, null );
		$this->make_writer()->write( $category, $term_id );

		$this->assertSame( '7', get_term_meta( $term_id, 'order', true ) );
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

	public function test_stale_parent_mapping_is_treated_as_unresolved(): void {
		// mappingsが指す親タームが手動削除等で既に存在しない場合を模擬する
		// （`ProductWriter::resolve_refs()`の同種のstale-mapping対応と同じ方針）。
		$this->seed_mapping( 'colorme', 'category', '999', 424242 );

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

	public function test_stale_term_id_falls_back_to_create_when_term_was_manually_deleted(): void {
		// `wp_update_term()`は対象タームが既に存在しない場合、`invalid_term_id`ではなく
		// `invalid_term`を返す（`wp-includes/taxonomy.php`の`get_term()`が空を返した分岐）。
		// これを誤検知しないと、手動削除されたタームへのmappingは永久に再作成されなくなる。
		$term_id = wp_insert_term( 'Old', 'product_cat' )['term_id'];
		wp_delete_term( $term_id, 'product_cat' );

		$category = new CanonicalCategory( '100', 'New', null, null );
		$result   = $this->make_writer()->write( $category, $term_id );

		$this->assertSame( WriteResult::OPERATION_CREATED, $result->operation );
		$this->assertNotSame( 0, $result->local_id );
		$term = get_term( $result->local_id, 'product_cat' );
		$this->assertSame( 'New', $term->name );
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
		// local_id 0を返す: `Importer`はlocal_id!==0であればoperationに関わらずchecksumを
		// mappingsへupsertするため、ここで既存term_idを返すとリネームが実際には
		// 適用されなかったにも関わらず次回以降checksum一致でこの検証自体がスキップされ、
		// バリデーション失敗が解消された後も永久に再試行されなくなる（既存の有効な
		// mappingはImporter側でupsert自体が発生しないため変更されず残る）。
		$this->assertSame( 0, $result->local_id );
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
