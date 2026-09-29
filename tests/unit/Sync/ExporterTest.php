<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\PartialPushException;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Support\ApiException;
use CartBridgeJP\Support\ExportOptions;
use CartBridgeJP\Support\RateLimitExhaustedException;
use CartBridgeJP\Sync\Exporter;
use CartBridgeJP\Sync\DryRunItemRepository;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\LogRepository;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\PlatformWriter;
use CartBridgeJP\Sync\PushIntentRepository;
use CartBridgeJP\Tests\Fixtures\FixedWooReader;
use CartBridgeJP\Tests\Fixtures\InMemoryPlatformWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\Export\AdapterPlatformWriter;
use CartBridgeJP\Woo\Export\DryRunPlatformWriter;
use CartBridgeJP\Woo\Reader\ReadItem;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;
use WP_UnitTestCase;

final class ExporterTest extends WP_UnitTestCase {

	private MappingRepository $mappings;
	private PushIntentRepository $push_intents;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings     = new MappingRepository();
		$this->push_intents = new PushIntentRepository();
	}

	/**
	 * `PlatformWriter`で、指定した名前の商品に対してだけ渡された例外を投げる（作成経路のみ）。
	 * D21-B（push intent）の各分岐テスト用。
	 */
	private function writer_failing_on_create( string $failing_name, Throwable $exception ): PlatformWriter {
		return new class( $failing_name, $exception ) implements PlatformWriter {
			public function __construct(
				private readonly string $failing_name,
				private readonly Throwable $exception
			) {}

			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				if ( null === $existing_remote_id && $this->failing_name === $item->name ) {
					throw $this->exception;
				}

				return new PushResult( 'ok', PushResult::OPERATION_UPDATED );
			}
		};
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

		// D21-B: 上の呼び出しは契約違反として印を残した（`mark_ambiguous`）ため、このままでは
		// 次のexportがブロックされてwriterが一切呼ばれなくなる。この後の呼び出しは別の独立した
		// シナリオ（原因がレート制限の場合）を検証するためのものなので、印を消してリセットする。
		( new PushIntentRepository() )->delete( 'mock', 'product', 101 );

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

	// ---- D21-B（issue #73）: push intent ----------------------------------------------------

	public function test_successful_creation_clears_the_push_intent_it_started(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	public function test_an_unresolved_push_intent_blocks_the_item_without_calling_the_writer(): void {
		$this->push_intents->begin( 'mock', 'product', 101, null, null );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ), '解除するまで印は残り続ける' );
	}

	/**
	 * D21-B「以後のexport（dry-runを含む）」: dry-runもブロックする。ただし`has_unresolved()`は
	 * 読取専用のため、この経路自体はintentsテーブルへ何も書き込まない（F1-6不変条件）。
	 */
	public function test_an_unresolved_push_intent_blocks_dry_run_and_reports_the_warning_without_writing_to_the_table(): void {
		global $wpdb;

		$this->push_intents->begin( 'mock', 'product', 101, null, null );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$before_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_push_intents" );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), true, null, null, 9201, 'run-9201' );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertSame( $before_count, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cbjp_push_intents" ), 'dry-runはintentsテーブルへ何も書き込まない' );

		$rows = ( new DryRunItemRepository() )->list_after( 'run-9201', 0, 10 );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( WarningCode::PUSH_OUTCOME_UNCONFIRMED, $rows[0]['warnings_json'] );
	}

	public function test_the_update_path_with_an_existing_mapping_never_creates_a_push_intent(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, 'stale-checksum' );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Updated' ) ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 0, $this->push_intents->count( 'mock', 'product' ) );
	}

	/**
	 * `push_stock()`はシグネチャに`?string $remote_id`を取らない（既存実体への更新のみで冪等）ため、
	 * D21-Bの対象外（`Exporter::PUSH_INTENT_ENTITIES`に含めない）。
	 */
	public function test_stock_entity_never_creates_a_push_intent(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'stock', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 0, $this->push_intents->count( 'mock', 'stock' ) );
	}

	/**
	 * `MockPlatformAdapter::$create_push_failure`（issue #73 モックアダプタの5xxトグル）が
	 * 実際のディスパッチ経路（`Woo\Export\AdapterPlatformWriter`）を通して`Exporter`まで正しく
	 * 伝わり、印が残ることを確認する（wp-env実機確認で使う切替の単体テストでの裏取り）。
	 */
	public function test_mock_adapter_create_push_failure_toggle_keeps_the_intent_through_the_real_dispatch_path(): void {
		$adapter  = new MockPlatformAdapter( push_products_supported: true, create_push_failure: new ApiException( 'server error', 500 ) );
		$writer   = new AdapterPlatformWriter( $adapter );
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( $adapter, $writer, $reader, 'product', Cursor::start(), false );

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	public function test_a_4xx_api_exception_confirms_rejection_and_clears_the_intent(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new ApiException( 'rejected', 422 ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	/**
	 * 4xx境界（400・499）を確認する。`>= 400 && < 500`のいずれかの向きを`>`/`<=`に取り違えると
	 * これらの境界だけ判定が反転する。
	 *
	 * @dataProvider provide_confirmed_rejection_status_codes
	 */
	public function test_4xx_boundary_status_codes_confirm_rejection( int $status_code ): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new ApiException( 'rejected', $status_code ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ), "status {$status_code} should confirm rejection and clear the intent" );
	}

	/**
	 * @return array<string,array{0:int}>
	 */
	public function provide_confirmed_rejection_status_codes(): array {
		return [
			'400 (lower boundary)' => [ 400 ],
			'429 (rate limited by the platform, not our own RateLimiter)' => [ 429 ],
			'499 (upper boundary)' => [ 499 ],
		];
	}

	/**
	 * `status 0`は「未接続」「通信断」「JSON破損」のいずれでも使われ、それだけでは判別できない
	 * （`.claude/rules/adapters-colorme.md`）。アダプタが`context['not_connected'] === true`で
	 * 明示した場合（`ColorMeAdapter::client()`が送信前に投げる）だけは「未送信が確定」とみなし、
	 * 印を消す。
	 */
	public function test_an_explicitly_not_connected_api_exception_confirms_rejection_and_clears_the_intent(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new ApiException( 'not connected', 0, [ 'not_connected' => true ] ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	public function test_a_5xx_api_exception_keeps_the_intent_ambiguous_and_blocks_the_next_export(): void {
		global $wpdb;

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new ApiException( 'server error', 500 ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$reason = $wpdb->get_var( "SELECT reason FROM {$wpdb->prefix}cbjp_push_intents WHERE platform = 'mock' AND entity_type = 'product' AND local_id = 101" );
		$this->assertSame( 'ambiguous_error', $reason );

		// 次回exportはブロックされ、writerが呼ばれない。
		$next_writer = new InMemoryPlatformWriter();
		$exporter->run_page( new MockPlatformAdapter(), $next_writer, new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] ), 'product', Cursor::start(), false );
		$this->assertSame( [], $next_writer->writes );
	}

	/**
	 * status 0（HttpClientの通信断・タイムアウト）も5xxと同じく「結果不明」として印を残す。
	 */
	public function test_an_api_exception_with_status_zero_keeps_the_intent_ambiguous(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new ApiException( 'no response', 0 ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	public function test_a_generic_exception_keeps_the_intent_ambiguous(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new RuntimeException( 'id missing from response' ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	public function test_unsupported_operation_exception_confirms_rejection_and_clears_the_intent(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new UnsupportedOperationException( 'mock', 'push_product' ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	/**
	 * `RateLimiter::wait()`が送信前に投げた素の例外＝未送信が確定しているため、再スローの前に
	 * 印を消す（次回exportは改めて作成を試みる。JobManagerがジョブをpausedにするのは変わらない）。
	 */
	public function test_a_plain_rate_limit_exception_clears_the_intent_before_rethrowing(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->writer_failing_on_create( 'P', new RateLimitExhaustedException( 'mock' ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$this->expectException( RateLimitExhaustedException::class );

		try {
			$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );
		} finally {
			$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		}
	}

	/**
	 * D21-Aの「その他の例外」行として扱う契約違反経路（remote_idの無い`PartialPushException`）は、
	 * 原因が`RateLimitExhaustedException`であっても——素の場合（上のテスト）とは異なり——
	 * 「送信前が確定」とはみなさず、印を残す（`docs/03` §10.2「D21の記述からの差」2.参照）。
	 */
	public function test_a_contract_violating_partial_push_keeps_the_intent_even_when_the_cause_is_rate_limit(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->partial_push_writer( 'P', '', new RateLimitExhaustedException( 'mock' ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$this->expectException( RateLimitExhaustedException::class );

		try {
			$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );
		} finally {
			$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ), '契約違反は原因の例外型によらず必ず印を残す' );
		}
	}

	/**
	 * アダプタが「実際には送信しなかった」ことを明示的に返す場合（例:
	 * `CUSTOMER_REQUIRED_FIELD_MISSING`で送信前にフェイルクローズ）、未送信が確定しているため
	 * 印を消す。
	 */
	public function test_a_push_result_reporting_nothing_was_sent_clears_the_intent(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '', PushResult::OPERATION_SKIPPED, [ WarningCode::CUSTOMER_REQUIRED_FIELD_MISSING ] );
			}
		};
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	/**
	 * レビュー指摘: `PushResult`が`created`/`updated`を主張しつつ`remote_id`が空文字列を返す
	 * （2xxだがid欠損というD21-Bが最も警戒する状態そのもの。信頼境界の契約違反）場合、
	 * 「実際にpushされなかった」ことが確定していないため、明示的な`skipped`＋空remote_idの場合
	 * （上のテスト）と違って印を消してはならない（原則8・9: 肯定形でしか安全側に倒さない）。
	 */
	public function test_push_result_claiming_created_with_an_empty_remote_id_keeps_the_intent_ambiguous(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				return new PushResult( '', PushResult::OPERATION_CREATED );
			}
		};
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	/**
	 * `LimitPolicy::used()`は未解決intent自体を累積カウントに含める（D21-Bの意図どおり。上限2件の
	 * うち101の未解決intent1件分は既に消費済みで残り枠は1件）。ブロックされた101はこの1ページの
	 * 処理中に**追加で**枠を消費しない（quota判定より前に`continue`する）ため、残り1件の枠は
	 * 102の作成にそのまま使える。ブロック判定がquota判定より後になる退行が起きると、101が
	 * 残り1件の枠を消費してしまい102が作成されなくなる。
	 */
	public function test_a_blocked_intent_does_not_consume_an_additional_free_tier_quota_slot(): void {
		$this->push_intents->begin( 'mock', 'product', 101, null, null );

		$reader       = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Blocked' ) ), new ReadItem( 102, $this->product( 'New' ) ) ] );
		$writer       = new InMemoryPlatformWriter();
		$exporter     = new Exporter( $this->mappings, push_intents: $this->push_intents );
		$limit_policy = new LimitPolicy( $this->mappings, $this->push_intents );

		add_filter( 'cbjp/limits/product', static fn () => 2 );

		try {
			$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertNotNull( $this->mappings->find_remote_id( 'mock', 'product', 102 ), 'ブロックされた1件目が残り1件の枠を余分に消費しないため2件目が作成される' );
	}

	/**
	 * レビュー指摘: 5xx等で印を`mark_ambiguous`のまま残した（＝`used()`上は引き続き枠を占有する）
	 * アイテムの分まで`$consumed_quota_slot`を無条件に解放すると、同じページ内の後続アイテムが
	 * 同じ枠を二重に使い、ページ内に限って無料版上限を実質的に超過しうる（原則7）。
	 * 上限2件のうち1件は既存mapping（使用済み）、101が5xxで印を残す（2件目の使用扱い）ため
	 * 残り枠は0。この状態で102は作成されてはならない。
	 */
	public function test_an_intent_kept_ambiguous_does_not_free_up_its_quota_slot_within_the_same_page(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-existing', 999, null );

		$reader       = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Ambiguous' ) ), new ReadItem( 102, $this->product( 'New' ) ) ] );
		$writer       = $this->writer_failing_on_create( 'Ambiguous', new ApiException( 'server error', 500 ) );
		$exporter     = new Exporter( $this->mappings, push_intents: $this->push_intents );
		$limit_policy = new LimitPolicy( $this->mappings, $this->push_intents );

		add_filter( 'cbjp/limits/product', static fn () => 2 );

		try {
			$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertSame( 2, $result['totals']['skipped'], '101は印を残してskip、102は枠が無く追加でskip' );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 102 ), '101の印が枠を占有したままなので102は作成されない' );
	}

	/**
	 * 上と同じ理由だが、例外経路ではなく`PushResult`が`created`＋空remote_idを主張する契約違反
	 * 経路（`$did_push`の`else`節）でも同じ解放漏れが起きないことを確認する。
	 */
	public function test_a_push_result_kept_ambiguous_does_not_free_up_its_quota_slot_within_the_same_page(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-existing', 999, null );

		$reader       = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Ambiguous' ) ), new ReadItem( 102, $this->product( 'New' ) ) ] );
		$writer       = new class() implements PlatformWriter {
			public function write( string $entity, CanonicalModel $item, ?string $existing_remote_id ): PushResult {
				if ( null === $existing_remote_id && 'Ambiguous' === $item->name ) {
					return new PushResult( '', PushResult::OPERATION_CREATED );
				}

				return new PushResult( 'ok', PushResult::OPERATION_CREATED );
			}
		};
		$exporter     = new Exporter( $this->mappings, push_intents: $this->push_intents );
		$limit_policy = new LimitPolicy( $this->mappings, $this->push_intents );

		add_filter( 'cbjp/limits/product', static fn () => 2 );

		try {
			$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 102 ), '101の印が枠を占有したままなので102は作成されない' );
	}

	/**
	 * D21-A（`PartialPushException`、remote_idあり）の成功に近い経路でも、mapping書込み後に
	 * push intentが消えることを確認する（`$did_push`の通常経路だけでなく、この経路も
	 * `push_intent_pending`の解放を通ることの裏取り）。
	 */
	public function test_partial_push_with_a_remote_id_clears_the_push_intent_after_the_mapping_write(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product() ) ] );
		$writer   = $this->partial_push_writer( 'P', '501', new RuntimeException( 'boom' ) );
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( '501', $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	/**
	 * レビュー指摘（Copilot/Codex）: mapping書込み後・印削除前にプロセスが止まる、または
	 * REST側の解除（`Woo\Tools\PushIntentResolver`）がmappingを結んだ直後に印を消し損なうと、
	 * 以後この実体は`existing_remote_id`が非nullになり、印を検査・削除する経路（作成経路限定）が
	 * 二度と実行されず孤立した印が恒久的に残ってしまう。mappingが既にある実体を次に処理する
	 * 機会（更新経路）で自己修復されることを確認する。
	 */
	public function test_a_stale_intent_left_behind_after_a_mapping_already_exists_is_reconciled_on_the_next_export(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, 'stale-checksum' );
		$this->push_intents->begin( 'mock', 'product', 101, null, null );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Updated' ) ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertCount( 1, $writer->writes, '既存mappingへの通常の更新は引き続き行われる' );
	}

	/**
	 * 上と同じ自己修復は、dry-runでは行われない（`has_unresolved()`は読取専用の確認のみ）。
	 * F1-6「dry-runは何も永続化しない」不変条件を守る。
	 */
	public function test_the_stale_intent_reconciliation_does_not_happen_during_a_dry_run(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, 'stale-checksum' );
		$this->push_intents->begin( 'mock', 'product', 101, null, null );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Updated' ) ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), true );

		$this->assertTrue( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
	}

	/**
	 * レビュー指摘（Copilot）: 未解決intentのブロック判定が`indicates_export_blocking()`の
	 * 早期`continue`より後にあると、読出時点のブロック警告（例: 価格が不正）も同時に持つ
	 * アイテムはブロック警告側の経路だけを通り、dry-run行に`PUSH_OUTCOME_UNCONFIRMED`が
	 * 含まれなくなる。両方に該当する場合でも警告がマージされて残ることを確認する。
	 */
	public function test_an_unresolved_intent_and_a_blocking_reader_warning_both_surface_in_the_dry_run_row(): void {
		$this->push_intents->begin( 'mock', 'product', 101, null, null );

		$reader   = new FixedWooReader(
			[ new ReadItem( 101, $this->product(), [ WarningCode::ALL_VARIATIONS_EXCLUDED ] ) ]
		);
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings, push_intents: $this->push_intents );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), true, null, null, 9301, 'run-9301' );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );

		$rows = ( new DryRunItemRepository() )->list_after( 'run-9301', 0, 10 );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( WarningCode::ALL_VARIATIONS_EXCLUDED, $rows[0]['warnings_json'] );
		$this->assertStringContainsString( WarningCode::PUSH_OUTCOME_UNCONFIRMED, $rows[0]['warnings_json'] );
	}

	/**
	 * レビュー指摘（Codex/Copilot, G2）: ページ開始時に一度だけ計算する`$remaining`は、
	 * mapping+未解決intentの両方を持つ実体を二重に数えている（`LimitPolicy::used()`が両方を
	 * 加算するため）。自己修復（上のテスト）で印を消した分、このページの残り処理のために枠を
	 * 1つ戻さないと、本来pushしてよい別の新規アイテムが不要にskipされる。
	 */
	public function test_reconciling_a_stale_intent_restores_the_free_tier_quota_slot_it_was_holding(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, 'stale-checksum' );
		$this->push_intents->begin( 'mock', 'product', 101, null, null );

		$reader       = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Updated' ) ), new ReadItem( 102, $this->product( 'New' ) ) ] );
		$writer       = new InMemoryPlatformWriter();
		$exporter     = new Exporter( $this->mappings, push_intents: $this->push_intents );
		$limit_policy = new LimitPolicy( $this->mappings, $this->push_intents );

		// 上限2件のうち、101のmapping1件＋stale intent1件で「使用済み2件」に見えるため、
		// 修正前は残り枠0で102がskipされてしまう。
		add_filter( 'cbjp/limits/product', static fn () => 2 );

		try {
			$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertSame( 1, $result['totals']['created'], '101の自己修復で戻った枠を使い102が作成される' );
		$this->assertNotNull( $this->mappings->find_remote_id( 'mock', 'product', 102 ) );
	}

	/**
	 * レビュー指摘（Codex, G3）: `LimitPolicy::remaining()`は負の値を0へクランプするため、
	 * 複数のstale intent（mapping+未解決intentの組）が同時に存在し使用数が既に上限を超えている
	 * 場合、単純な`++$remaining`を繰り返すとクランプで隠れていた超過分まで枠として復活し、
	 * 上限を超えて新規アイテムが作成されうる。上限1件・101と102がそれぞれmapping+stale
	 * intentを持つ（実使用数2、既に上限超過）状態で、両方を自己修復した後も103（新規）は
	 * 作成されないことを確認する。
	 */
	public function test_reconciling_multiple_stale_intents_does_not_restore_quota_beyond_the_true_usage(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-101', 101, 'stale-checksum' );
		$this->push_intents->begin( 'mock', 'product', 101, null, null );
		$this->mappings->upsert( 'mock', 'product', 'remote-102', 102, 'stale-checksum' );
		$this->push_intents->begin( 'mock', 'product', 102, null, null );

		// 3件を1ページにまとめて処理させる（`FixedWooReader`の既定ページサイズ2件だと
		// 103が次ページへ回り、本テストが検証したい「同一ページ内での相互作用」を再現できない）。
		$reader       = new FixedWooReader(
			[
				new ReadItem( 101, $this->product( 'Updated101' ) ),
				new ReadItem( 102, $this->product( 'Updated102' ) ),
				new ReadItem( 103, $this->product( 'New' ) ),
			],
			3
		);
		$writer       = new InMemoryPlatformWriter();
		$exporter     = new Exporter( $this->mappings, push_intents: $this->push_intents );
		$limit_policy = new LimitPolicy( $this->mappings, $this->push_intents );

		add_filter( 'cbjp/limits/product', static fn () => 1 );

		try {
			$exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false, $limit_policy );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}

		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 101 ) );
		$this->assertFalse( $this->push_intents->has_unresolved( 'mock', 'product', 102 ) );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 103 ), '実使用数が既に上限を超えているため103は作成されない' );
	}

	/**
	 * D24: 画像アップロードをオフのままexport済みの商品は、そのままだとchecksumが一致して`push_product()`が
	 * 呼ばれず、後でオンにしても画像が永久に送られない。オンの間だけ商品のchecksumに印を混ぜ、次のexportで
	 * 更新として再送させる。再送後は印つきのchecksumがキャッシュされ、続けて回しても再送されない。
	 */
	public function test_enabling_image_upload_re_sends_a_product_that_was_exported_while_it_was_off(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $product ) );
		ExportOptions::save_push_images( 'mock', true );

		$writer = new InMemoryPlatformWriter();
		$result = ( new Exporter( $this->mappings ) )->run_page( new MockPlatformAdapter(), $writer, new FixedWooReader( [ new ReadItem( 101, $product ) ] ), 'product', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 1, $result['totals']['updated'] );
		$this->assertSame( Exporter::export_checksum( $product, Exporter::CHECKSUM_SALT_IMAGES ), $this->mappings->find_checksum( 'mock', 'product', 'remote-1' ) );
		$this->assertNotSame( Exporter::export_checksum( $product ), $this->mappings->find_checksum( 'mock', 'product', 'remote-1' ) );

		$second_writer = new InMemoryPlatformWriter();
		$second        = ( new Exporter( $this->mappings ) )->run_page( new MockPlatformAdapter(), $second_writer, new FixedWooReader( [ new ReadItem( 101, $product ) ] ), 'product', Cursor::start(), false );

		$this->assertSame( [], $second_writer->writes, '印つきのchecksumが一致するため再送しない' );
		$this->assertSame( 1, $second['totals']['skipped'] );
	}

	/**
	 * オフ（既定）のchecksumは従来と同一で、既存のmappingは影響を受けない（設定を一度も触っていない店舗が
	 * アップデート後に全商品を再送されない）。
	 */
	public function test_image_upload_off_keeps_the_existing_product_checksum(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $product ) );

		foreach ( [ null, false ] as $setting ) {
			if ( null !== $setting ) {
				ExportOptions::save_push_images( 'mock', $setting );
			}

			$writer = new InMemoryPlatformWriter();
			$result = ( new Exporter( $this->mappings ) )->run_page( new MockPlatformAdapter(), $writer, new FixedWooReader( [ new ReadItem( 101, $product ) ] ), 'product', Cursor::start(), false );

			$this->assertSame( [], $writer->writes );
			$this->assertSame( 1, $result['totals']['skipped'] );
		}
	}

	/**
	 * 画像アップロードの能力（`can_push_images`）が無いアダプタでは、設定が残っていてもchecksumを変えない
	 * （送らない機能のために全商品を再送しない）。
	 */
	public function test_image_upload_marker_is_not_applied_when_the_adapter_cannot_push_images(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $product ) );
		ExportOptions::save_push_images( 'mock', true );

		$adapter = new MockPlatformAdapter( capabilities_override: new Capabilities( true, true, true, true, false, true, true, true, true, true, 600 ) );
		$writer  = new InMemoryPlatformWriter();
		$result  = ( new Exporter( $this->mappings ) )->run_page( $adapter, $writer, new FixedWooReader( [ new ReadItem( 101, $product ) ] ), 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
	}

	/**
	 * 画像に関係するのは商品だけ。在庫等の他のエンティティのchecksumには印を混ぜない。
	 */
	public function test_image_upload_marker_is_not_applied_to_other_entities(): void {
		$stock = new CanonicalStock( 'p-1', 'v-1', 'SKU-1', 5, true );
		$this->mappings->upsert( 'mock', 'stock', 'remote-s1', 201, Exporter::export_checksum( $stock ) );
		ExportOptions::save_push_images( 'mock', true );

		$writer = new InMemoryPlatformWriter();
		$result = ( new Exporter( $this->mappings ) )->run_page( new MockPlatformAdapter(), $writer, new FixedWooReader( [ new ReadItem( 201, $stock ) ] ), 'stock', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
	}

	/**
	 * dry-run も同じ判定を使う（オンにした直後のプレビューが「変更なし」と偽らず、実際の export と同じく更新として出る）。
	 */
	public function test_dry_run_reports_a_product_as_an_update_once_image_upload_is_enabled(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $product ) );
		ExportOptions::save_push_images( 'mock', true );

		$writer = new InMemoryPlatformWriter();
		$result = ( new Exporter( $this->mappings ) )->run_page( new MockPlatformAdapter(), $writer, new FixedWooReader( [ new ReadItem( 101, $product ) ] ), 'product', Cursor::start(), true, null, null, 9401, 'run-9401' );

		$this->assertSame( 0, $result['totals']['skipped'], '「変更なし」とせず、実際のexportと同じく更新として扱う' );

		$rows = ( new DryRunItemRepository() )->list_after( 'run-9401', 0, 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( PushResult::OPERATION_UPDATED, $rows[0]['operation'] );
	}

	/**
	 * @param bool $supports_per_variant_stock `Capabilities::$supports_per_variant_stock_management`。
	 */
	private function adapter_with_per_variant_stock( bool $supports_per_variant_stock ): MockPlatformAdapter {
		return new MockPlatformAdapter( capabilities_override: new Capabilities( true, true, true, true, true, true, true, true, true, true, 600, $supports_per_variant_stock ) );
	}

	/**
	 * D22: 在庫管理が混在する商品（`VARIATION_STOCK_MANAGEMENT_MIXED`）は、アダプタが
	 * `supports_per_variant_stock_management`を宣言していない（既定）と、`indicates_export_blocking()`の
	 * 警告と同じ扱い（writerを呼ばずskipped＋warned・mapping無し）で止まる。
	 */
	public function test_mixed_stock_management_blocks_the_push_when_the_adapter_lacks_per_variant_stock_support(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [ WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED ] ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		// アダプタが能力を宣言しない既定でも止まる（宣言しない外部アダプタは安全側に倒す）。
		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertSame( 0, $result['totals']['created'] );
		$this->assertNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	/**
	 * バリエーション単位で在庫管理できるアダプタ（`supports_per_variant_stock_management=true`）では
	 * 混在した商品も正しく送れるため止めない（原則1: ColorMe固有の制約をReaderに書かない）。
	 */
	public function test_mixed_stock_management_is_pushed_when_the_adapter_supports_per_variant_stock(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [ WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED ] ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( $this->adapter_with_per_variant_stock( true ), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 0, $result['totals']['skipped'] );
		$this->assertNotNull( $this->mappings->find_remote_id( 'mock', 'product', 101 ) );
	}

	/**
	 * 在庫行（`stock`）も同じ判定で止まる（商品が混在になる前にエクスポート済みの場合、在庫pushが
	 * `stock_managed`を明示PUTし、管理外バリエーションが古い値のまま残るのを防ぐ）。
	 */
	public function test_mixed_stock_management_also_blocks_stock_rows(): void {
		$stock    = new CanonicalStock( 'p-1', 'v-1', 'SKU-1', 5, true );
		$reader   = new FixedWooReader( [ new ReadItem( 201, $stock, [ WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED ] ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'stock', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
	}

	/**
	 * エクスポート済み（mappingあり）で内容が変わった（checksum不一致）商品も、混在に変わった時点で止まる
	 * （更新経路でも`indicates_export_blocking()`と同じ位置で判定する）。
	 */
	public function test_mixed_stock_management_blocks_updates_of_an_already_exported_product(): void {
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, 'stale-checksum' );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product( 'Now mixed' ), [ WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED ] ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 0, $result['totals']['updated'] );
		$this->assertSame( 'stale-checksum', $this->mappings->find_checksum( 'mock', 'product', 'remote-1' ), 'checksumはキャッシュされない（揃えたら次回exportで再送される）' );
	}

	/**
	 * dry-runにも同じ判定で出す（skipped行に警告が残る。実行せずに理由が分かる）。
	 */
	public function test_mixed_stock_management_is_reported_in_the_dry_run_row(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [ WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED ] ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), $writer, $reader, 'product', Cursor::start(), true, null, null, 9301, 'run-9301' );

		$this->assertSame( [], $writer->writes );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );

		$rows = ( new DryRunItemRepository() )->list_after( 'run-9301', 0, 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( PushResult::OPERATION_SKIPPED, $rows[0]['operation'] );
		$this->assertStringContainsString( WarningCode::VARIATION_STOCK_MANAGEMENT_MIXED, $rows[0]['warnings_json'] );
	}

	/**
	 * 混在の警告が無いアイテムは、`supports_per_variant_stock_management=false`でも止めない
	 * （capabilityの条件が警告の有無と無関係に効いてしまうと、全ての商品が止まる）。
	 */
	public function test_items_without_the_mixed_warning_are_unaffected_by_the_per_variant_stock_capability(): void {
		$reader   = new FixedWooReader( [ new ReadItem( 101, $this->product(), [ WarningCode::with_detail( WarningCode::CATEGORY_MAP_UNRESOLVED, '5' ) ] ) ] );
		$writer   = new InMemoryPlatformWriter();
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( $this->adapter_with_per_variant_stock( false ), $writer, $reader, 'product', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes );
		$this->assertSame( 1, $result['totals']['created'] );
	}

	/**
	 * issue #55: `unchanged`（`skipped`の内訳）はchecksum一致スキップだけを数える。dry-runの
	 * `created + updated + unchanged` を「移行できる件数」とするため（Pro 案内）、止めた実体
	 * （export-blocking警告）まで数えると「どの版でも移行できない」件数が消える。
	 */
	public function test_unchanged_counts_only_checksum_matched_skips_in_a_dry_run(): void {
		$unchanged = $this->product( 'Unchanged' );
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $unchanged ) );

		$reader   = new FixedWooReader(
			[
				new ReadItem( 101, $unchanged ),
				new ReadItem( 102, $this->product( 'Blocked' ), [ WarningCode::ALL_VARIATIONS_EXCLUDED ] ),
				new ReadItem( 103, $this->product( 'New' ) ),
			],
			3
		);
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), new DryRunPlatformWriter(), $reader, 'product', Cursor::start(), true );

		$this->assertSame( 3, $result['totals']['processed'] );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 2, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['unchanged'] );
	}

	/**
	 * issue #55: 移行済みの実体が後から止まる状態になった（checksumは一致したまま）場合も、
	 * blocking判定がchecksum一致より先なので「変更なし（移行できる）」には数えない。
	 */
	public function test_a_blocked_item_is_not_counted_as_unchanged_even_when_its_checksum_matches(): void {
		$product = $this->product();
		$this->mappings->upsert( 'mock', 'product', 'remote-1', 101, Exporter::export_checksum( $product ) );

		$reader   = new FixedWooReader( [ new ReadItem( 101, $product, [ WarningCode::ALL_VARIATIONS_EXCLUDED ] ) ] );
		$exporter = new Exporter( $this->mappings );

		$result = $exporter->run_page( new MockPlatformAdapter(), new DryRunPlatformWriter(), $reader, 'product', Cursor::start(), true );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 0, $result['totals']['unchanged'] );
	}
}
