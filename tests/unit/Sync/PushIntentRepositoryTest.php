<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\PushIntentRepository;
use WP_UnitTestCase;

final class PushIntentRepositoryTest extends WP_UnitTestCase {

	private PushIntentRepository $intents;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$this->intents = new PushIntentRepository();
	}

	public function test_begin_inserts_a_new_row_and_returns_true(): void {
		$this->assertTrue( $this->intents->begin( 'mock', 'product', 1, 'run-1', 10 ) );
		$this->assertTrue( $this->intents->has_unresolved( 'mock', 'product', 1 ) );
	}

	public function test_begin_rejects_a_duplicate_and_returns_false(): void {
		$this->intents->begin( 'mock', 'product', 1, 'run-1', 10 );

		$this->assertFalse( $this->intents->begin( 'mock', 'product', 1, 'run-2', 11 ) );
	}

	public function test_begin_does_not_collide_across_platforms_or_entities(): void {
		$this->intents->begin( 'mock', 'product', 1, null, null );

		$this->assertTrue( $this->intents->begin( 'other', 'product', 1, null, null ) );
		$this->assertTrue( $this->intents->begin( 'mock', 'customer', 1, null, null ) );
		$this->assertTrue( $this->intents->begin( 'mock', 'product', 2, null, null ) );
	}

	public function test_has_unresolved_is_false_before_begin_and_after_delete(): void {
		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'product', 1 ) );

		$this->intents->begin( 'mock', 'product', 1, null, null );
		$this->assertTrue( $this->intents->has_unresolved( 'mock', 'product', 1 ) );

		$this->intents->delete( 'mock', 'product', 1 );
		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'product', 1 ) );
	}

	public function test_mark_ambiguous_keeps_the_row_unresolved_and_records_the_reason(): void {
		global $wpdb;

		$this->intents->begin( 'mock', 'product', 1, null, null );
		$this->intents->mark_ambiguous( 'mock', 'product', 1 );

		$this->assertTrue( $this->intents->has_unresolved( 'mock', 'product', 1 ) );

		$reason = $wpdb->get_var(
			"SELECT reason FROM {$wpdb->prefix}cbjp_push_intents WHERE platform = 'mock' AND entity_type = 'product' AND local_id = 1"
		);

		$this->assertSame( 'ambiguous_error', $reason );
	}

	public function test_delete_for_platform_removes_only_the_given_platform(): void {
		$this->intents->begin( 'mock', 'product', 1, null, null );
		$this->intents->begin( 'mock', 'customer', 2, null, null );
		$this->intents->begin( 'other', 'product', 1, null, null );

		$this->intents->delete_for_platform( 'mock' );

		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'product', 1 ) );
		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'customer', 2 ) );
		$this->assertTrue( $this->intents->has_unresolved( 'other', 'product', 1 ) );
	}

	public function test_count_matches_the_number_of_unresolved_rows_for_the_entity(): void {
		$this->assertSame( 0, $this->intents->count( 'mock', 'product' ) );

		$this->intents->begin( 'mock', 'product', 1, null, null );
		$this->intents->begin( 'mock', 'product', 2, null, null );
		$this->intents->begin( 'mock', 'customer', 3, null, null );

		$this->assertSame( 2, $this->intents->count( 'mock', 'product' ) );
		$this->assertSame( 1, $this->intents->count( 'mock', 'customer' ) );
	}

	public function test_find_unresolved_lists_entity_type_and_local_id_in_id_order(): void {
		$this->intents->begin( 'mock', 'product', 5, 'run-a', 1 );
		$this->intents->begin( 'mock', 'customer', 7, 'run-b', 2 );

		$rows = $this->intents->find_unresolved( 'mock' );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'product', $rows[0]['entity_type'] );
		$this->assertSame( 5, $rows[0]['local_id'] );
		$this->assertSame( 'run-a', $rows[0]['run_id'] );
		$this->assertSame( 1, $rows[0]['job_id'] );
		$this->assertNull( $rows[0]['reason'] );
		$this->assertSame( 'customer', $rows[1]['entity_type'] );
		$this->assertSame( 7, $rows[1]['local_id'] );
	}

	public function test_find_returns_null_for_a_mismatched_platform(): void {
		$this->intents->begin( 'mock', 'product', 1, null, null );
		$rows = $this->intents->find_unresolved( 'mock' );
		$id   = $rows[0]['id'];

		$this->assertNull( $this->intents->find( $id, 'other' ) );
		$this->assertNotNull( $this->intents->find( $id, 'mock' ) );
	}

	public function test_delete_by_id_removes_the_row(): void {
		$this->intents->begin( 'mock', 'product', 1, null, null );
		$id = $this->intents->find_unresolved( 'mock' )[0]['id'];

		$this->intents->delete_by_id( $id );

		$this->assertFalse( $this->intents->has_unresolved( 'mock', 'product', 1 ) );
	}
}
