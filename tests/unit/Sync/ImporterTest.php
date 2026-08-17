<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use WP_UnitTestCase;

final class ImporterTest extends WP_UnitTestCase {

	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings = new MappingRepository();
	}

	/**
	 * `WriteResult::$local_id === 0` は「ローカル実体を作成/更新できなかった」ことを表す契約
	 * （例: stockの対象商品が未インポート）。mappingsを書いてしまうとchecksum一致で次回以降
	 * 永久にスキップされ再試行できなくなるため、この場合はmappingsを書かないことを確認する。
	 */
	public function test_zero_local_id_does_not_persist_mapping(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$writer  = new class() implements WooWriter {
			public int $calls = 0;

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				++$this->calls;

				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ 'unresolved' ] );
			}
		};

		$importer = new Importer( $this->mappings );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		$this->assertSame( 1, $writer->calls );
		$this->assertNull( $this->mappings->find_local_id( $adapter->id(), 'product', 'p1' ) );
	}

	/**
	 * local_id 0 でmappingsが書かれないため、再実行時に同じアイテムが再度write()に渡され
	 * （checksum一致スキップに掛からず）再試行できることを確認する。
	 */
	public function test_zero_local_id_item_is_retried_on_next_run(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$writer  = new class() implements WooWriter {
			public int $calls = 0;

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				++$this->calls;

				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [] );
			}
		};

		$importer = new Importer( $this->mappings );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		$this->assertSame( 2, $writer->calls );
	}

	/**
	 * `local_id === 0` なのに `operation` が created/updated を返す（writer実装側の契約違反）
	 * 場合でも、totals集計上は実態どおりskipped扱いになることを確認する
	 * （writer/EntityWriterインターフェースでは型として強制できない契約を、Importer側で
	 * 防御的に正規化している）。
	 */
	public function test_totals_treat_zero_local_id_as_skipped_even_if_writer_claims_created(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$writer  = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				// 契約違反: local_id=0なのにcreatedを主張する不正なwriter実装を模擬する。
				return new WriteResult( 0, WriteResult::OPERATION_CREATED, [] );
			}
		};

		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		$this->assertSame( 0, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['skipped'] );
	}

	/**
	 * `DryRunReporter`は仕様として常にlocal_id=0でcreated/updatedを返す（何も永続化しない
	 * ため）。dry-run結果レポートの新規/更新件数を正しく表示するには、この正規化を
	 * dry-runの対象外にする必要がある（対象にすると常に0件表示になってしまう）。
	 */
	public function test_dry_run_preserves_created_and_updated_totals_despite_zero_local_id(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$writer  = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				// DryRunReporterと同じ契約: 何も永続化しないためlocal_idは常に0。
				return new WriteResult( 0, WriteResult::OPERATION_CREATED, [] );
			}
		};

		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, $writer, 'product', Cursor::start(), true );

		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 0, $result['totals']['skipped'] );
	}

	/**
	 * 1件のアイテムでwriterが例外を投げても、`process_job()`がジョブ全体をfailedへ
	 * 遷移させて他の正常なアイテムまで巻き添えにしないよう、そのアイテムのみskipped
	 * 扱いにして処理を継続することを確認する。
	 */
	public function test_writer_exception_on_one_item_does_not_abort_the_page(): void {
		$adapter = new MockPlatformAdapter(
			products: [
				CanonicalFactory::product( 'p1', 'SKU-1' ),
				CanonicalFactory::product( 'p2', 'SKU-2' ),
			]
		);
		$writer  = new class() implements WooWriter {
			public array $seen = [];

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				$this->seen[] = $item->remote_id();

				if ( 'p1' === $item->remote_id() ) {
					throw new \RuntimeException( 'simulated failure' );
				}

				return new WriteResult( 42, WriteResult::OPERATION_CREATED );
			}
		};

		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		// 例外を投げたp1の後もp2の処理まで到達している（ページ全体が中断していない）。
		$this->assertSame( [ 'p1', 'p2' ], $writer->seen );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['warned'] );

		// 例外を投げたp1にはmappingが書かれていない（次回実行時に再試行できる）。
		$this->assertNull( $this->mappings->find_local_id( $adapter->id(), 'product', 'p1' ) );
		$this->assertSame( 42, $this->mappings->find_local_id( $adapter->id(), 'product', 'p2' ) );
	}

	public function test_nonzero_local_id_persists_mapping(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$writer  = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				return new WriteResult( 99, WriteResult::OPERATION_CREATED );
			}
		};

		$importer = new Importer( $this->mappings );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		$this->assertSame( 99, $this->mappings->find_local_id( $adapter->id(), 'product', 'p1' ) );
	}
}
