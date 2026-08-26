<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\LimitPolicy;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\InMemoryWriter;
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
	 * ループ開始前に一括プリロードした既存mappingスナップショットは、ループ内で行われた
	 * upsert()を反映しない。同一ページ内（アダプタのページング境界バグ等）に同じremote_id
	 * のアイテムが複数含まれると、後続のアイテムが「未作成」と誤認して別の孤立エンティティを
	 * 作成してしまっていた（mappingは最後に処理した方だけを指す）。
	 */
	public function test_duplicate_remote_id_within_same_page_is_treated_as_an_update(): void {
		$adapter = new MockPlatformAdapter(
			products: [
				CanonicalFactory::product( 'p1', 'SKU-1', 5 ),
				CanonicalFactory::product( 'p1', 'SKU-1', 9 ),
			]
		);
		$writer  = new class() implements WooWriter {
			public array $existing_local_ids_seen = [];
			private int $next_id                  = 100;

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				$this->existing_local_ids_seen[] = $existing_local_id;

				if ( null !== $existing_local_id ) {
					return new WriteResult( $existing_local_id, WriteResult::OPERATION_UPDATED, [] );
				}

				return new WriteResult( $this->next_id++, WriteResult::OPERATION_CREATED, [] );
			}
		};

		$importer = new Importer( $this->mappings );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		// 2件目は1件目が作成したlocal_id(100)を「既存」として認識し、更新経路を通る
		// （nullのまま=未作成と誤認して別の孤立商品を作らない）。
		$this->assertSame( [ null, 100 ], $writer->existing_local_ids_seen );
		$this->assertSame( 100, $this->mappings->find_local_id( $adapter->id(), 'product', 'p1' ) );
	}

	/**
	 * `remote_id_of()`が例外を投げていた頃は、ページ内の1件がアダプタの契約違反
	 * （`extras['remote_id']`欠損）だけで`array_map()`がループに入る前に中断し、ページ全体が
	 * 失敗していた。「1件の異常データで移行全体を止めない」方針（writer例外時の処理と同じ）を
	 * remote_id解決自体にも適用し、その1件だけをskippedにして他のアイテムは処理を継続することを
	 * 確認する。
	 */
	public function test_item_missing_remote_id_is_skipped_without_aborting_the_page(): void {
		$missing_remote_id = new CanonicalProduct( 'No remote id', 'SKU-X', '1000', null, null, [], [], [], [], null, 'publish', [] );
		$adapter           = new MockPlatformAdapter(
			products: [
				$missing_remote_id,
				CanonicalFactory::product( 'p1', 'SKU-1' ),
			]
		);
		$writer            = new class() implements WooWriter {
			public array $seen = [];

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				$this->seen[] = $item->remote_id();

				return new WriteResult( 42, WriteResult::OPERATION_CREATED, [] );
			}
		};

		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		// remote_id欠損のアイテムはwriterに渡らず、正常な2件目は処理されている。
		$this->assertSame( [ 'p1' ], $writer->seen );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );
		$this->assertSame( 1, $result['totals']['created'] );
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
	 * ProductWriter/OrderWriter/TermWriterは、category/tag参照や顧客参照が未解決のまま
	 * 実体自体は保存できた場合、local_id!==0（`WriteResult::$fully_resolved`はfalse）を返す
	 * （注文履歴・商品自体の欠落を防ぐため）。この場合checksumをキャッシュすると、参照先が
	 * 後から解決可能になっても（category等が後で取り込まれても）二度と再試行されなくなるため、
	 * checksumが一致していても次回実行時に再度write()が呼ばれることを確認する。
	 */
	public function test_partially_resolved_item_is_retried_even_when_checksum_matches(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$writer  = new class() implements WooWriter {
			public int $calls = 0;

			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				++$this->calls;

				return new WriteResult( 42, WriteResult::OPERATION_CREATED, [ 'category_ref_unresolved:10' ], false );
			}
		};

		$importer = new Importer( $this->mappings );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		$this->assertSame( 2, $writer->calls );
		$this->assertSame( 42, $this->mappings->find_local_id( $adapter->id(), 'product', 'p1' ) );
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

	/**
	 * `Support\Logger`の個人情報禁止ルール（IDのみ許可）はcontextだけでなくmessage自体にも
	 * 及ぶ（`JobManager::process_job()`の同種のcatch節も固定文言のみを渡す方針と揃える）。
	 * `$exception->getMessage()`は自由文字列であり、writer経由で顧客のメール等の値を
	 * そのまま含みうるため、ログのmessageに例外メッセージをそのまま埋め込まないことを確認する。
	 */
	public function test_writer_exception_message_does_not_leak_raw_exception_text(): void {
		global $wpdb;

		$adapter = new MockPlatformAdapter(
			products: [
				CanonicalFactory::product( 'p1', 'SKU-1' ),
			]
		);
		$writer  = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				throw new \RuntimeException( 'taro@example.com must not leak into logs' );
			}
		};

		$importer = new Importer( $this->mappings );
		$importer->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		$logged_message = $wpdb->get_var( "SELECT message FROM {$wpdb->prefix}cbjp_logs ORDER BY id DESC LIMIT 1" );

		$this->assertNotNull( $logged_message );
		$this->assertStringNotContainsString( 'taro@example.com', $logged_message );
		$this->assertStringContainsString( 'product', $logged_message );
	}

	/**
	 * 無料版サンプル上限（`$remaining`）は新規作成の直前に1件消費するが、その後writerが
	 * 例外を投げて実体が何も作られなかった場合は枠を返却しないと、無効な1件が枠を
	 * 無駄に食い潰し、本来枠内に収まるはずの正常なアイテムがこのページで弾かれてしまう。
	 */
	public function test_quota_slot_is_restored_when_write_throws(): void {
		add_filter( 'cbjp/limits/product', static fn (): int => 1 );

		try {
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

			$importer     = new Importer( $this->mappings );
			$limit_policy = new LimitPolicy( $this->mappings );
			$result       = $importer->run_page( $adapter, $writer, 'product', Cursor::start(), false, $limit_policy );

			// 上限1件でも、p1の例外で消費した枠が返却されるためp2まで到達し作成できる。
			$this->assertSame( [ 'p1', 'p2' ], $writer->seen );
			$this->assertSame( 1, $result['totals']['created'] );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}
	}

	/**
	 * writerがlocal_id=0（実体を作成できなかった）を返した場合も、例外と同じ理由で
	 * 消費した枠を返却することを確認する。
	 */
	public function test_quota_slot_is_restored_when_write_returns_zero_local_id(): void {
		add_filter( 'cbjp/limits/product', static fn (): int => 1 );

		try {
			$adapter = new MockPlatformAdapter(
				products: [
					CanonicalFactory::product( 'p1', 'SKU-1' ),
					CanonicalFactory::product( 'p2', 'SKU-2' ),
				]
			);
			$writer  = new class() implements WooWriter {
				public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
					if ( 'p1' === $item->remote_id() ) {
						return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [] );
					}

					return new WriteResult( 42, WriteResult::OPERATION_CREATED );
				}
			};

			$importer     = new Importer( $this->mappings );
			$limit_policy = new LimitPolicy( $this->mappings );
			$result       = $importer->run_page( $adapter, $writer, 'product', Cursor::start(), false, $limit_policy );

			$this->assertSame( 1, $result['totals']['created'] );
			$this->assertSame( 42, $this->mappings->find_local_id( $adapter->id(), 'product', 'p2' ) );
		} finally {
			remove_all_filters( 'cbjp/limits/product' );
		}
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

	/**
	 * `run_sample_stock_page()`は無料版のサンプル在庫取込経路（§10.2 #4）。バリエーションを持つ
	 * 商品を親レベル1件のCanonicalStockに丸めると、`Woo\Writer\StockWriter`が書込対象を
	 * `WC_Product_Variable`（親）と判定して書込をスキップしてしまい、サンプル在庫が
	 * 実質書き込まれなくなる。バリエーション単位に展開されることを確認する。
	 */
	public function test_sample_stock_page_expands_variants_instead_of_targeting_the_parent(): void {
		$product = CanonicalFactory::product(
			'p1',
			'SKU-1',
			5,
			[
				[
					'remote_id' => 'v1',
					'sku'       => 'SKU-1-V1',
					'stock'     => 3,
				],
				[
					'remote_id' => 'v2',
					'sku'       => 'SKU-1-V2',
					'stock'     => null,
				],
			]
		);
		$adapter = new MockPlatformAdapter( products: [ $product ] );
		$writer  = new InMemoryWriter();

		$importer = new Importer( $this->mappings );
		$result   = $importer->run_sample_stock_page( $adapter, $writer, [ 'p1' ], false );

		$this->assertCount( 2, $writer->writes );
		// 進捗率の分母はサンプル商品数（1件）ではなく、バリエーション展開後の実処理件数（2件）。
		// 商品数のまま報告すると`JobManager`側でprocessedがtotalを超えてしまう。
		$this->assertSame( 2, $result['total'] );

		$stocks = array_map( static fn ( array $write ): CanonicalStock => $write['item'], $writer->writes );

		$this->assertSame( 'v1', $stocks[0]->variant_ref );
		$this->assertSame( 'SKU-1-V1', $stocks[0]->sku );
		$this->assertSame( 3, $stocks[0]->quantity );
		$this->assertTrue( $stocks[0]->in_stock );

		$this->assertSame( 'v2', $stocks[1]->variant_ref );
		$this->assertNull( $stocks[1]->quantity );
		$this->assertTrue( $stocks[1]->in_stock );
	}

	public function test_sample_stock_page_fails_closed_when_variant_stock_is_present_but_unparseable(): void {
		// `variants`はCanonicalModelコンストラクタ同様、外部アダプタ拡張点の信頼境界（ドキュメント上の
		// 契約のみで型は強制されない）。キー欠損/nullは「在庫管理外」という正当な契約だが、値が
		// 存在するのに配列等でパースできない場合にnullへ丸めると「在庫あり」に誤判定してしまうため、
		// 0（在庫切れ）にフェイルクローズすることを確認する。
		$product = CanonicalFactory::product(
			'p1',
			'SKU-1',
			5,
			[
				[
					'remote_id' => 'v1',
					'sku'       => 'SKU-1-V1',
					'stock'     => [ 'unexpected' => 'shape' ],
				],
			]
		);
		$adapter = new MockPlatformAdapter( products: [ $product ] );
		$writer  = new InMemoryWriter();

		$importer = new Importer( $this->mappings );
		$importer->run_sample_stock_page( $adapter, $writer, [ 'p1' ], false );

		$stock = $writer->writes[0]['item'];
		$this->assertSame( 0, $stock->quantity );
		$this->assertFalse( $stock->in_stock );
	}

	public function test_sample_stock_page_skips_a_product_whose_returned_id_does_not_match_the_requested_id(): void {
		// アダプタ拡張点の信頼境界（アーキテクチャ原則8）: `fetch_product_by_remote_id()`が
		// 要求IDと異なる商品を返した場合（契約違反アダプタのバグ）、要求ID（正しい商品への参照）と
		// 返却された別商品の在庫・SKUを組み合わせてしまうと、誤った商品の在庫データが
		// 正しい商品に書き込まれてしまう。IDが一致しない場合は取得失敗と同様にスキップする。
		$mismatched_product = CanonicalFactory::product( 'p2', 'SKU-2', 99 );
		$adapter            = new MockPlatformAdapter( product_by_remote_id_override: $mismatched_product );
		$writer             = new InMemoryWriter();

		$importer = new Importer( $this->mappings );
		$importer->run_sample_stock_page( $adapter, $writer, [ 'p1' ], false );

		$this->assertCount( 0, $writer->writes );
	}

	public function test_sample_stock_page_targets_the_product_when_it_has_no_variants(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1', 5 ) ] );
		$writer  = new InMemoryWriter();

		$importer = new Importer( $this->mappings );
		$importer->run_sample_stock_page( $adapter, $writer, [ 'p1' ], false );

		$this->assertCount( 1, $writer->writes );

		$stock = $writer->writes[0]['item'];
		$this->assertNull( $stock->variant_ref );
		$this->assertSame( 'p1', $stock->product_ref );
		$this->assertSame( 5, $stock->quantity );
	}
}
