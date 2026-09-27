<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PartialPushException;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\Exporter;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PlatformWriter;
use CartBridgeJP\Tests\Fixtures\FixedWooReader;
use CartBridgeJP\Tests\Fixtures\InMemoryPlatformWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;
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

	/**
	 * Copilot指摘（PR #40、G3）: `ALL_VARIATIONS_EXCLUDED`のようなexport-blocking警告は
	 * `ReadItem`に積むだけでは実際のpush自体を止めない。`$writer->write()`が一切呼ばれず、
	 * skipped/warnedとして扱われ、mappingsも永続化されないことを確認する。
	 */
	public function test_export_blocking_warning_prevents_push(): void {
		$reader   = new FixedWooReader(
			[ new ReadItem( 101, $this->product(), [ WarningCode::ALL_VARIATIONS_EXCLUDED ] ) ]
		);
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	/**
	 * Codex指摘（PR #40、G3）: アダプタがupdate時に既存remote_idと異なる新しいremote_id
	 * （例: リモート側で削除された実体を再作成した場合）を返すと、`upsert()`のユニークキー
	 * （platform, entity_type, remote_id）は別行をINSERTするだけで旧remote_idの行が孤児として
	 * 残ってしまっていた。新しいremote_idをupsertする前に旧行を削除し、local_id当たり1行だけが
	 * 残ることを確認する。
	 */
	public function test_stale_mapping_row_is_replaced_when_writer_returns_a_new_remote_id(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-old', 101, 'stale-checksum' );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( 'remote-new', PushResult::OPERATION_UPDATED );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 'remote-new', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_local_id( 'mock', 'product', 'remote-old' ), '旧remote_idの行が孤児として残っていないこと' );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ), 'local_id当たり1行だけが残ること' );
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

	/**
	 * E2-3への申し送り（`docs/03-design-decisions.md` §10.2）: `PushResult`は商品1件につき
	 * remote_id1つしか運べないため、`ColorMeAdapter::push_product()`が返す
	 * `variant_remote_ids`（`ReadItem::$variant_local_ids`と同じ順序・要素数）を
	 * `Exporter`がzipして`cbjp_mappings`（'variant'）へ書き戻すことを確認する。
	 */
	public function test_product_push_writes_back_variant_mappings_from_variant_remote_ids(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [], true, [ 201, 202 ] ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '501', PushResult::OPERATION_CREATED, [], [ '9001', '9002' ] );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( '501', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertSame( 201, $this->mappings->find_local_id( 'mock', 'variant', '9001' ) );
		$this->assertSame( 202, $this->mappings->find_local_id( 'mock', 'variant', '9002' ) );
	}

	/**
	 * `variant_remote_ids`の空文字列要素（`ColorMeAdapter::sync_variants()`が未確定/失敗を
	 * 表す番兵値）はmapping行を作らない。
	 */
	public function test_empty_variant_remote_id_is_not_upserted(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [], true, [ 201, 202 ] ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '501', PushResult::OPERATION_CREATED, [], [ '9001', '' ] );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 201, $this->mappings->find_local_id( 'mock', 'variant', '9001' ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'variant', 202 ) );
	}

	/**
	 * R1レビュー指摘: 親商品側にはPR #40 G3で「remote_idが変わったら旧行をdelete_one()する」
	 * 修正が入っているが、当初のvariant書き戻しには同じ処理が無かった。Woo側の属性値リネーム等で
	 * ColorMeが同じバリエーションに対し新しいremote_idを自動生成した場合、旧remote_idの行が
	 * 孤児として残らないことを確認する。
	 */
	public function test_stale_variant_mapping_row_is_replaced_when_writer_returns_a_new_variant_remote_id(): void {
		$this->mappings->upsert( 'mock', 'variant', 'old-variant-remote-id', 201, null );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [], true, [ 201 ] ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '501', PushResult::OPERATION_CREATED, [], [ 'new-variant-remote-id' ] );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 'new-variant-remote-id', $this->mappings->find_remote_id( 'mock', 'variant', 201 ) );
		$this->assertNull( $this->mappings->find_local_id( 'mock', 'variant', 'old-variant-remote-id' ), '旧remote_idの行が孤児として残っていないこと' );
	}

	/**
	 * `$result`は`cbjp/adapters/register`経由の外部アダプタが直接返す信頼境界の外側
	 * （アーキテクチャ原則8）。`variant_remote_ids`の要素数が`ReadItem::$variant_local_ids`と
	 * 一致しない契約違反は、誤った対応付けでmappingを書き込まないよう無視する（zipしない）。
	 */
	public function test_variant_remote_ids_count_mismatch_is_ignored(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [], true, [ 201, 202 ] ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '501', PushResult::OPERATION_CREATED, [], [ '9001' ] );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( '501', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'variant', 201 ) );
		$this->assertNull( $this->mappings->find_local_id( 'mock', 'variant', '9001' ) );
		// R3レビュー指摘（Copilot）: 契約違反でバリエーションが1件も書き戻せなかった場合、
		// 親商品のchecksumもキャッシュしてはならない。キャッシュすると以後この商品では
		// 二度と`push_product()`が呼ばれず、バリエーション同期を恒久的に再試行できなくなる。
		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', '501' ) );
	}

	/**
	 * `variant_remote_ids`は`product`entity以外では意味を持たない（`ColorMeAdapter`以外の
	 * push_*()が将来同名のプロパティを空でなく返した場合でも、product以外ではzipしない）。
	 */
	public function test_variant_remote_ids_are_ignored_for_non_product_entities(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [], true, [ 201 ] ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '501', PushResult::OPERATION_CREATED, [], [ '9001' ] );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'coupon', Cursor::start(), false );

		$this->assertSame( '501', $this->mappings->find_remote_id( 'mock', 'coupon', 101 ) );
		$this->assertNull( $this->mappings->find_local_id( 'mock', 'variant', '9001' ) );
	}

	/**
	 * G3レビュー指摘（Copilot Suppressed comments）: `variant_local_ids`が空（simple商品）の
	 * 場合を除外していたため、simple商品にアダプタが非空の`variant_remote_ids`を返す
	 * （信頼境界の契約違反）ケースを見落としていた。0対0の一致（simple商品の正常系）だけを
	 * 許可する単純な件数比較に統一し、親のchecksumがキャッシュされないことを確認する。
	 */
	public function test_simple_product_with_unexpected_variant_remote_ids_is_treated_as_contract_violation(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '501', PushResult::OPERATION_CREATED, [], [ '9001' ] );
			}
		};
		$exporter = new Exporter( $this->mappings );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( '501', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', '501' ) );
	}

	/**
	 * `$interrupted_name`の商品だけ`PartialPushException`（作成確定後の中断）を投げ、それ以外は
	 * 通常どおり作成/更新として成功するwriter。`$calls`に`existing_remote_id`を記録する。
	 */
	private function partial_push_writer( string $interrupted_name, string $remote_id, Throwable $cause ): PlatformWriter {
		return new class( $interrupted_name, $remote_id, $cause ) implements PlatformWriter {
			/** @var array<int,?string> */
			public array $calls = [];

			private int $next_remote_id = 1;

			public function __construct(
				private readonly string $interrupted_name,
				private readonly string $remote_id,
				private readonly Throwable $cause
			) {}

			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				$this->calls[] = $existing_remote_id;

				if ( $this->interrupted_name === $item->name ) {
					throw new PartialPushException( $this->remote_id, $this->cause );
				}

				return new PushResult(
					'ok-' . $this->next_remote_id++,
					null === $existing_remote_id ? PushResult::OPERATION_CREATED : PushResult::OPERATION_UPDATED
				);
			}
		};
	}

	/**
	 * D21-A（issue #72）: 作成が確定した後にレート制限で中断した場合、`PartialPushException`が
	 * 運んだremote_idをchecksum=nullでmappingへ書いてから、レート制限を再スローする
	 * （`JobManager`はジョブを`paused`にし、再開時は同じ商品への更新になって重複しない）。
	 * remote_idが失われる（mappingを書かずに投げる）と再開時に同じ商品がもう一度作成される。
	 */
	public function test_partial_push_caused_by_rate_limit_writes_the_mapping_before_rethrowing(): void {
		$cause    = new RateLimitExhaustedException( 'mock' );
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->partial_push_writer( 'P', '501', $cause );
		$exporter = new Exporter( $this->mappings );

		try {
			$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );
			$this->fail( 'RateLimitExhaustedException should propagate so that JobManager pauses the job.' );
		} catch ( RateLimitExhaustedException $caught ) {
			$this->assertSame( $cause, $caught );
		}

		$this->assertSame( '501', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', '501' ), '次回exportで後続処理をやり直すためchecksumをキャッシュしない' );
	}

	/**
	 * 再開時の挙動: 上のテストで書かれたmappingにより、同じ商品は作成ではなく更新（既存remote_id
	 * への送信）として処理され、重複作成されない。
	 */
	public function test_export_resumed_after_an_interrupted_create_updates_instead_of_creating_again(): void {
		$item     = new ReadItem( 101, $this->product() );
		$exporter = new Exporter( $this->mappings );

		try {
			$exporter->run_page( new MockPlatformAdapter(), $this->partial_push_writer( 'P', '501', new RateLimitExhaustedException( 'mock' ) ), new FixedWooReader( [ $item ] ), 'product', Cursor::start(), false );
			$this->fail( 'RateLimitExhaustedException should propagate.' );
		} catch ( RateLimitExhaustedException $caught ) {
			$this->assertSame( 'mock', $caught->platform() );
		}

		$resumed_writer = new InMemoryPlatformWriter();
		$result         = $exporter->run_page( new MockPlatformAdapter(), $resumed_writer, new FixedWooReader( [ $item ] ), 'product', Cursor::start(), false );

		$this->assertCount( 1, $resumed_writer->writes );
		$this->assertSame( '501', $resumed_writer->writes[0]['remote_id'], '再開時は既存remote_idへの更新になる（作成し直さない）' );
		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 0, $result['totals']['created'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ) );
	}

	/**
	 * レート制限以外の原因なら1件の部分失敗として先へ進む: `created`＋`warned`に数え、mappingは
	 * checksum=nullで書き、同じページの次のアイテムも処理される。
	 */
	public function test_partial_push_with_another_cause_is_counted_as_created_and_warned_and_the_page_continues(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ), new ReadItem( 102, $this->product( 'Second' ) ) ] );
		$writer   = $this->partial_push_writer( 'P', '501', new RuntimeException( 'boom' ) );
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, null, null, 9102 );

		$this->assertSame( 2, $result['totals']['created'] );
		$this->assertSame( 0, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		// 中断は「作成済みだが後続処理が未完了」の警告として残る（原因の例外は握り潰さず記録される）。
		$logs = ( new LogRepository() )->list( 9102 );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'warning', $logs[0]['level'] );
		$this->assertSame( '501', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', '501' ) );
		$this->assertSame( 'ok-1', $this->mappings->find_remote_id( 'mock', 'product', 102 ) );
		$this->assertSame( Exporter::export_checksum( $this->product( 'Second' ) ), $this->mappings->find_checksum( 'mock', 'product', 'ok-1' ) );
	}

	/**
	 * 既存mappingのある商品の更新中に`PartialPushException`が来た場合（外部アダプタが更新経路でも
	 * 包む場合）は`updated`＋`warned`に数える。remote_idが変わっていれば旧行を消す
	 * （PR #40 G3と同じ理由）。
	 */
	public function test_partial_push_on_an_existing_mapping_is_counted_as_updated_and_replaces_a_changed_remote_id(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-old', 101, 'stale-checksum' );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->partial_push_writer( 'P', 'remote-new', new RuntimeException( 'boom' ) );
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( 0, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertSame( 'remote-new', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_local_id( 'mock', 'product', 'remote-old' ) );
		$this->assertNull( $this->mappings->find_checksum( 'mock', 'product', 'remote-new' ) );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ) );
	}

	/**
	 * アーキテクチャ原則8: remote_idの無い`PartialPushException`は契約違反で何も書き留められない。
	 * 包まれていなかった場合と同じに扱う: レート制限が原因ならそのまま伝播（ジョブを一時停止）、
	 * それ以外は1件の失敗として`skipped`＋`warned`（mappingは書かない）。
	 */
	public function test_partial_push_with_an_empty_remote_id_is_treated_like_the_unwrapped_cause(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $this->partial_push_writer( 'P', '', new RuntimeException( 'boom' ) ), $reader, 'product', Cursor::start(), false, null, null, 9101 );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertSame( 0, $result['totals']['created'] );
		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
		// 集計は「中断を記録した場合」と同じになるため、汎用の1件失敗として扱われた（remote_idを
		// 書き留められない契約違反）ことはerrorレベルのログで区別する。
		$logs = ( new LogRepository() )->list( 9101 );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'error', $logs[0]['level'] );

		$cause = new RateLimitExhaustedException( 'mock' );

		try {
			$exporter->run_page( new MockPlatformAdapter(), $this->partial_push_writer( 'P', '', $cause ), $reader, 'product', Cursor::start(), false );
			$this->fail( 'RateLimitExhaustedException should propagate.' );
		} catch ( RateLimitExhaustedException $caught ) {
			$this->assertSame( $cause, $caught );
		}

		$this->assertSame( 0, $this->mappings->count( 'mock', 'product' ) );
	}

	/**
	 * 中断した作成もリモートに実体を作っているため、無料版の枠は戻さない（mappingが
	 * `LimitPolicy`の累計に数えられる）。枠が1件のとき、中断した1件目で枠を使い切り、2件目は
	 * 送信されずskippedになる。
	 */
	public function test_interrupted_create_keeps_its_free_tier_quota_slot(): void {
		$reader       = new FixedWooReader( [ new ReadItem( 101, $this->product() ), new ReadItem( 102, $this->product( 'Second' ) ) ] );
		$writer       = $this->partial_push_writer( 'P', '501', new RuntimeException( 'boom' ) );
		$exporter     = new Exporter( $this->mappings );
		$limit_policy = new LimitPolicy( $this->mappings );

		add_filter( 'cbjp/limits/product', static fn () => 1 );

		try {
			$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		$this->assertSame( [ null ], $writer->calls );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $this->mappings->count( 'mock', 'product' ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 102 ) );
	}
}
