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

	public function test_reuses_existing_term_on_name_conflict(): void {
		$existing_id = wp_insert_term( 'Apparel', 'product_cat' )['term_id'];

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

	public function test_tag_taxonomy_creates_product_tag_term(): void {
		$writer = $this->make_writer( 'product_tag' );
		$tag    = new \CartBridgeJP\Canonical\CanonicalTag( '55', 'Sale' );

		$result = $writer->write( $tag, null );

		$term = get_term( $result->local_id, 'product_tag' );
		$this->assertSame( 'Sale', $term->name );
	}
}
