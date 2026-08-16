<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Woo;

use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalReview;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\SideEffectGuard;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\WooRepository;
use CartBridgeJP\Woo\WooRepositoryFactory;
use CartBridgeJP\Woo\Writer\EntityWriter;

final class WooRepositoryTest extends WooTestCase {

	public function test_unsupported_entity_is_skipped_without_exception(): void {
		$repository = new WooRepository( new SideEffectGuard(), [] );
		$review     = new CanonicalReview( 'p1', 'Alice', 5, 'Title', 'Great', '2026-01-01' );

		$result = $repository->write( 'review', $review, null );

		$this->assertSame( 0, $result->local_id );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
		$this->assertContains( WarningCode::ENTITY_NOT_SUPPORTED, $result->warnings );
	}

	public function test_dispatches_to_registered_writer(): void {
		$writer = new class() implements EntityWriter {
			public bool $called = false;

			public function write( \CartBridgeJP\Canonical\CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				$this->called = true;

				return new WriteResult( 42, WriteResult::OPERATION_CREATED );
			}
		};

		$repository = new WooRepository( new SideEffectGuard(), [ 'category' => $writer ] );
		$result     = $repository->write( 'category', new CanonicalCategory( '1', 'Cat', null, null ), null );

		$this->assertTrue( $writer->called );
		$this->assertSame( 42, $result->local_id );
	}

	public function test_write_suppresses_mail_during_execution(): void {
		$writer = new class() implements EntityWriter {
			public ?bool $mail_result = null;

			public function write( \CartBridgeJP\Canonical\CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				$this->mail_result = wp_mail( 'someone@example.com', 'subject', 'body' );

				return new WriteResult( 1, WriteResult::OPERATION_CREATED );
			}
		};

		$repository = new WooRepository( new SideEffectGuard(), [ 'category' => $writer ] );
		$repository->write( 'category', new CanonicalCategory( '1', 'Cat', null, null ), null );

		$this->assertFalse( $writer->mail_result );

		// ガード解除後は通常どおりフィルターが効かない状態に戻っている。
		$this->assertFalse( has_filter( 'pre_wp_mail', '__return_false' ) );
	}

	public function test_factory_builds_writer_for_each_known_entity(): void {
		$writer = ( new WooRepositoryFactory() )->for_platform( 'colorme' );
		$this->assertInstanceOf( WooRepository::class, $writer );

		// review非対応のはずなのでskippedになる。
		$review = new CanonicalReview( 'p1', 'Alice', 5, 'Title', 'Great', '2026-01-01' );
		$result = $writer->write( 'review', $review, null );
		$this->assertSame( WriteResult::OPERATION_SKIPPED, $result->operation );
	}
}
