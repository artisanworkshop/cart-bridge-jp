<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\Exporter;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PlatformWriter;
use CartBridgeJP\Tests\Fixtures\FixedWooReader;
use CartBridgeJP\Tests\Fixtures\InMemoryPlatformWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WP_UnitTestCase;

final class ExporterTest extends WP_UnitTestCase {

	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings = new MappingRepository();
	}

	private function product( string $name = 'P' ): CanonicalProduct {
		return new CanonicalProduct( $name, 'SKU-1', '1000', null, null, [], [], [], [], 5, 'publish' );
	}

	public function test_new_item_is_pushed_as_created_and_mapping_upserted(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 'product', $writer->writes[0]['entity'] );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( '1', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	public function test_existing_mapping_with_changed_data_is_updated(): void {
		$product = $this->product( 'Updated Name' );
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, 'stale-checksum' );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $product ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 'remote-1', $writer->writes[0]['remote_id'] );
		// `Exporter`が書くchecksumは`export_checksum()`（H1参照。名前空間混ぜ込みで
		// importの生ハッシュとの衝突を避ける。クラスdocblock参照）が返す値になる。
		$this->assertSame( Exporter::export_checksum( $product ), $this->mappings->find_checksum( 'mock', 'product', 'remote-1' ) );
	}

	public function test_checksum_match_skips_the_writer(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $product ) );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $product ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
	}

	/**
	 * H1: `cbjp_mappings`はimport/exportで同じ行を共有する（entity_typeを分けない設計判断）。
	 * `Importer`が書く生ハッシュ（接頭辞なし）を`Exporter`がそのまま「変更なし」の根拠にすると、
	 * インポート直後に一度もWoo側を書き換えていないのに（≒本当に一致しうる状態でも）
	 * 誤って一致とみなしてしまう可能性がある。`export_checksum()`の名前空間混ぜ込みにより、
	 * import由来の生ハッシュはexportの比較対象と構造的に一致しないため、必ず「変更あり」判定に
	 * なりpushが呼ばれることを確認する（安全側＝再送に倒れる）。
	 */
	public function test_import_origin_checksum_never_matches_export_comparison(): void {
		$product = $this->product();
		// importが書く形式（接頭辞なしの生ハッシュ）をそのまま模する。
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, $product->checksum() );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $product ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 1, $result['totals']['updated'] );
		// 以後はexport形式のchecksumで上書きされ、次回以降の再エクスポートは正しくスキップされる。
		$this->assertSame( Exporter::export_checksum( $product ), $this->mappings->find_checksum( 'mock', 'product', 'remote-1' ) );
	}

	public function test_free_tier_quota_blocks_new_items_but_allows_updates(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-existing', 999, null );

		$new_item      = new ReadItem( 101, $this->product( 'New' ) );
		$existing_item = new ReadItem( 999, $this->product( 'Existing (changed)' ) );

		$reader       = new FixedWooReader( [ $new_item, $existing_item ] );
		$writer       = new InMemoryPlatformWriter();
		$exporter     = new Exporter( $this->mappings );
		$limit_policy = new LimitPolicy( $this->mappings );

		add_filter( 'cbjp/limits/product', static fn () => 1 );

		try {
			$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		// 残枠は0（上限1件 - 既存1件）のため新規（local_id=101）は作られず、既存（local_id=999）の
		// 更新のみ行われる。
		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 'remote-existing', $writer->writes[0]['remote_id'] );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 1, $result['totals']['skipped'] );
	}

	public function test_dry_run_never_persists_mappings_even_with_a_limit_policy(): void {
		$reader       = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer       = new InMemoryPlatformWriter();
		$exporter     = new Exporter( $this->mappings );
		$limit_policy = new LimitPolicy( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), true, $limit_policy );

		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
	}

	public function test_writer_exception_is_caught_and_does_not_fail_the_page(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ), new ReadItem( 102, $this->product( 'Second' ) ) ] );
		$writer   = new class() implements PlatformWriter {
			private int $next_remote_id = 1;

			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				if ( 'P' === $item->name ) {
					throw new RuntimeException( 'boom' );
				}

				return new PushResult( (string) $this->next_remote_id++, PushResult::OPERATION_CREATED );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNotNull( $this->mappings->find_remote_id( 'mock', 'product', 102 ) );
	}

	/**
	 * M2: `PlatformWriter`は`cbjp/adapters/register`が登録した外部アダプタの`push_*()`へ
	 * 直接ディスパッチするため、`PushResult::$operation`の型宣言はdocblock上の契約でしかない
	 * （アーキテクチャ原則8）。未知の文字列を返す契約違反アダプタがいても、`$totals`の集計
	 * （結果レポート・アップセル件数の元）が壊れないことを確認する。
	 */
	public function test_unknown_operation_from_writer_fails_closed_to_skipped(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				// `$totals`の初期キー（processed/created/updated/skipped/warned/remote_amount）の
				// いずれとも一致しない、完全に未知の値。修正前はこの値のままundefined array key
				// （PHP 8の警告）で`++$totals['bogus-operation']`が実行されていた。
				return new PushResult( '1', 'bogus-operation' );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 0, $result['totals']['created'] );
		$this->assertArrayNotHasKey( 'bogus-operation', $result['totals'] );
		// Copilot指摘（PR #40）: 正規化前のremote_id（非空）だけでmappingsをupsertすると、
		// totalsは「skipped」と報告しているのに実際には永続化されてしまう矛盾が起きていた。
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	/**
	 * Copilot指摘（PR #40）: `PlatformWriter::write()`が`RateLimitExhaustedException`を
	 * 投げた場合、`Importer`と違いexportは`push_*()`をアイテム毎に呼ぶため、これを
	 * 汎用`Throwable`catchで1件の異常として握り潰すと、レート制限に達した以降の全アイテムが
	 * 「1件ずつ失敗」として処理され、`JobManager`の一時停止・再開（`RateLimitExhaustedException`
	 * 専用catch）が機能しなくなる。`Exporter::run_page()`の外まで例外が伝播することを確認する。
	 */
	public function test_rate_limit_exhausted_exception_propagates_instead_of_being_swallowed(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				throw new RateLimitExhaustedException( 'mock' );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$this->expectException( RateLimitExhaustedException::class );
		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );
	}

	/**
	 * Copilot指摘（PR #40）: `PushResult::$warnings`は外部アダプタが返す配列で要素の型は
	 * docblock上の契約でしかない。非string要素が混じると`WarningCode::split()`（`explode()`）が
	 * `TypeError`を投げ、ページ全体が失敗しうる。非string要素を読み飛ばし、正常な文字列の警告は
	 * 引き続き反映されることを確認する。
	 */
	public function test_non_string_warning_elements_from_writer_are_filtered_out(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				// phpcs:ignore CartBridgeJP.Sniffs -- 契約違反アダプタを意図的に模した非string要素。
				return new PushResult( '1', PushResult::OPERATION_CREATED, [ [], WarningCode::CATEGORY_MAP_UNRESOLVED ] );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['warned'] );
	}

	/**
	 * Copilot指摘（PR #40、本文コメント）: checksum一致でスキップした場合も
	 * `$read_item->warnings`（`VARIATION_STOCK_SHARED_WITH_PARENT`等、checksum対象外のため
	 * データが変わらない限り毎回同じ内容になる警告）をwarnedカウントへ反映し続けることを確認する。
	 */
	public function test_checksum_match_skip_still_counts_persistent_reader_warnings(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $product ) );

		$reader   = new FixedWooReader(
			[ new ReadItem( 101, $product, [ WarningCode::VARIATION_STOCK_SHARED_WITH_PARENT ] ) ]
		);
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
	}

	public function test_unresolved_reference_from_reader_prevents_checksum_caching(): void {
		$product  = $this->product();
		$reader   = new FixedWooReader(
			[
				new ReadItem( 101, $product, [ WarningCode::with_detail( WarningCode::CATEGORY_MAP_UNRESOLVED, '5' ) ], false ),
			]
		);
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', '1' ) );
	}

	public function test_only_local_ids_restricts_the_reader_to_the_sample(): void {
		$reader   = new FixedWooReader(
			[
				new ReadItem( 101, $this->product( 'A' ) ),
				new ReadItem( 102, $this->product( 'B' ) ),
			]
		);
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, null, [ 102 ] );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 1, $result['totals']['processed'] );
	}
}
