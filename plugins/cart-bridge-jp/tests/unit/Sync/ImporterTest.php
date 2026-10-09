<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Core\Activator;
use CartBridgeJP\Sync\Importer;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WooWriter;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Tests\Fixtures\CanonicalFactory;
use CartBridgeJP\Tests\Fixtures\InMemoryWriter;
use CartBridgeJP\Tests\Fixtures\MockPlatformAdapter;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\WooRepositoryFactory;
use WP_UnitTestCase;

final class ImporterTest extends WP_UnitTestCase {

	private MappingRepository $mappings;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->mappings = new MappingRepository();
	}

	/**
	 * 移行後検証レポート（D17）用の `remote_amount` は、書込の成否・checksum一致スキップに
	 * 関わらず、この run で ASP から取得した全受注の合計になる（Woo側の「リンク済み受注の合計」と
	 * 並べて、警告・例外で取り込めなかった分も金額で見せるため）。
	 */
	public function test_remote_amount_accumulates_the_total_of_every_processed_order(): void {
		$skipped = new CanonicalOrder( '1001', 'processing', null, [], [], [], [ 'total' => '1500.5' ], '2026-07-01 00:00:00', null );
		$written = new CanonicalOrder( '1002', 'processing', null, [], [], [], [ 'total' => '2000' ], '2026-07-01 00:00:00', null );
		$adapter = new MockPlatformAdapter( orders: [ $skipped, $written ] );

		// 1件目は checksum 一致でスキップされる経路に乗せる。
		$this->mappings->upsert( $adapter->id(), 'order', '1001', 501, $skipped->checksum() );

		$writer   = new InMemoryWriter();
		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, $writer, 'order', Cursor::start(), false );

		$this->assertCount( 1, $writer->writes, 'checksum一致の1件は書き込まれない' );
		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 350050, $result['totals']['remote_amount'], '1500.50 + 2000.00 を 1/100 単位で累積' );
	}

	/**
	 * R3-0m: 決済方法が未マッピングのまま本取込みした受注は checksum を保存しない。そのため Mappings タブで設定した後の
	 * dry-run は checksum 一致で検証を飛ばさずに検証し直し（警告だけが消えて直ったように見える、を防ぐ）、次回の本取込みで
	 * 決済方法が付き直る。実 Writer（`WooRepositoryFactory`）で通して確かめる。
	 */
	public function test_order_imported_with_an_unmapped_payment_method_is_fixed_by_the_next_import(): void {
		$order    = new CanonicalOrder(
			'2001',
			'processing',
			null,
			[],
			[],
			[
				'method_id'   => 'pay-1',
				'method_name' => 'Bank transfer',
			],
			[
				'total'        => '1000',
				'tax'          => '0',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			'2026-07-01T00:00:00+00:00',
			null
		);
		$adapter  = new MockPlatformAdapter( orders: [ $order ] );
		$factory  = new WooRepositoryFactory();
		$importer = new Importer( $this->mappings );

		$first = $importer->run_page( $adapter, $factory->for_platform( $adapter->id() ), 'order', Cursor::start(), false );

		$this->assertSame( 1, $first['totals']['created'] );
		$this->assertNull( $this->mappings->find_checksum( $adapter->id(), 'order', '2001' ), '未マッピングの受注は checksum を保存しない' );
		$local_id = $this->mappings->find_local_id( $adapter->id(), 'order', '2001' );
		$this->assertIsInt( $local_id );
		$this->assertSame( '', wc_get_order( $local_id )->get_payment_method() );

		update_option( 'cbjp_settings_' . $adapter->id(), [ 'payment_map' => [ 'pay-1' => 'bacs' ] ] );

		$dry_run = $importer->run_page( $adapter, $factory->for_dry_run( $adapter->id() ), 'order', Cursor::start(), true );

		$this->assertSame( 0, $dry_run['totals']['skipped'], '設定後の dry-run は checksum 一致で飛ばさず検証し直す' );
		$this->assertSame( 1, $dry_run['totals']['updated'] );

		$second = $importer->run_page( $adapter, $factory->for_platform( $adapter->id() ), 'order', Cursor::start(), false );

		$this->assertSame( 1, $second['totals']['updated'] );
		$this->assertSame( 'bacs', wc_get_order( $local_id )->get_payment_method() );
		$this->assertNotNull( $this->mappings->find_checksum( $adapter->id(), 'order', '2001' ), '設定後は checksum を保存する' );

		delete_option( 'cbjp_settings_' . $adapter->id() );
	}

	/**
	 * D26（issue #102）: 軽減税率の商品を入れる税区分が無いまま本取込みした商品は、標準に倒して checksum を保存しない。
	 * そのため WooCommerce の税の設定で軽減税率の税区分（日本語でインストールした店舗の「軽減税」）と JP の 8% を作ってから
	 * 取り込み直すと、商品はその税区分へ移る。実 Writer（`WooRepositoryFactory`）で通して確かめる。
	 */
	public function test_reduced_rate_product_imported_without_a_reduced_class_is_fixed_by_the_next_import(): void {
		update_option( 'woocommerce_default_country', 'JP:JP13' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		\WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		$product  = new CanonicalProduct( 'Rice', 'SKU-RICE', '1080', null, null, [], [], [], [], 5, 'publish', [ 'remote_id' => 'p-rice' ], true, [], null, CanonicalProduct::TAX_CLASS_REDUCED );
		$adapter  = new MockPlatformAdapter( products: [ $product ] );
		$factory  = new WooRepositoryFactory();
		$importer = new Importer( $this->mappings );

		$first = $importer->run_page( $adapter, $factory->for_platform( $adapter->id() ), 'product', Cursor::start(), false );

		$this->assertSame( 1, $first['totals']['created'] );
		$local_id = $this->mappings->find_local_id( $adapter->id(), 'product', 'p-rice' );
		$this->assertIsInt( $local_id );
		$this->assertSame( '', wc_get_product( $local_id )->get_tax_class(), '入れる税区分が無いので標準に倒す' );
		$this->assertNull( $this->mappings->find_checksum( $adapter->id(), 'product', 'p-rice' ), 'checksum を保存しない' );

		$japanese = \WC_Tax::create_tax_class( '軽減税' );
		$this->assertIsArray( $japanese );
		\WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => 'JP',
				'tax_rate'          => '8.0000',
				'tax_rate_name'     => 'JP reduced',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 0,
				'tax_rate_class'    => $japanese['slug'],
			]
		);

		$second = $importer->run_page( $adapter, $factory->for_platform( $adapter->id() ), 'product', Cursor::start(), false );

		$this->assertSame( 1, $second['totals']['updated'] );
		$this->assertSame( $japanese['slug'], wc_get_product( $local_id )->get_tax_class() );
		$this->assertNotNull( $this->mappings->find_checksum( $adapter->id(), 'product', 'p-rice' ), '税区分に入れば checksum を保存する' );
	}

	/**
	 * D26: 受注は、軽減税率の明細を入れる税区分が無く標準に倒しても checksum を保存する（商品と違い、取込みのたびに明細を作り直さない。
	 * 受注の金額は ASP の値を明示しており、変わるのは税区分の名前だけ。review-loop R1-4）。
	 */
	public function test_order_imported_without_a_reduced_class_keeps_its_checksum(): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'yes' );
		\WC_Tax::delete_tax_class_by( 'slug', 'reduced-rate' );

		$wc_product = new \WC_Product_Simple();
		$wc_product->set_name( 'Rice' );
		$wc_product->set_regular_price( '1080' );
		$product_id = $wc_product->save();

		$order   = new CanonicalOrder(
			'2101',
			'processing',
			null,
			[
				[
					'sku'                 => null,
					'remote_product_id'   => 'p-rice',
					'name'                => 'Rice',
					'price'               => '1080',
					'unit_price_excl_tax' => '1000',
					'subtotal'            => '1080',
					'quantity'            => 1,
					'tax_reduced'         => true,
				],
			],
			[],
			[],
			[
				'total'        => '1080',
				'tax'          => '80',
				'shipping_fee' => '0',
				'discount'     => '0',
			],
			'2026-07-01T00:00:00+00:00',
			null
		);
		$adapter = new MockPlatformAdapter( orders: [ $order ] );
		$this->mappings->upsert( $adapter->id(), 'product', 'p-rice', $product_id, null );

		$result = ( new Importer( $this->mappings ) )->run_page( $adapter, ( new WooRepositoryFactory() )->for_platform( $adapter->id() ), 'order', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['created'] );
		$local_id = $this->mappings->find_local_id( $adapter->id(), 'order', '2101' );
		$this->assertIsInt( $local_id );
		$items = array_values( wc_get_order( $local_id )->get_items() );
		$this->assertSame( '', $items[0]->get_tax_class(), '入れる税区分が無いので標準に倒す' );
		$this->assertNotNull( $this->mappings->find_checksum( $adapter->id(), 'order', '2101' ), '受注は checksum を保存する' );
	}

	public function test_remote_amount_stays_zero_for_non_order_entities(): void {
		$adapter  = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, new InMemoryWriter(), 'product', Cursor::start(), false );

		$this->assertSame( 0, $result['totals']['remote_amount'] );
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

	public function test_dry_run_records_one_row_per_item_with_its_warnings(): void {
		$adapter = new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] );
		$writer  = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				return new WriteResult( 0, WriteResult::OPERATION_CREATED, [ 'category_parent_unresolved:999' ] );
			}
		};

		$importer = new Importer( $this->mappings );
		$importer->run_page( $adapter, $writer, 'category', Cursor::start(), true, 1, 'run-dry-1' );

		$rows = ( new \CartBridgeJP\Sync\DryRunItemRepository() )->list_after( 'run-dry-1', 0, 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'category', $rows[0]['entity'] );
		$this->assertSame( 'c1', $rows[0]['remote_id'] );
		$this->assertSame( 'Category 1', $rows[0]['label'] );
		$this->assertSame( [ 'category_parent_unresolved:999' ], json_decode( (string) $rows[0]['warnings_json'], true ) );
	}

	public function test_real_import_does_not_record_dry_run_rows(): void {
		$adapter  = new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] );
		$writer   = new InMemoryWriter();
		$importer = new Importer( $this->mappings );

		$importer->run_page( $adapter, $writer, 'category', Cursor::start(), false, 1, 'run-real-1' );

		$rows = ( new \CartBridgeJP\Sync\DryRunItemRepository() )->list_after( 'run-real-1', 0, 10 );
		$this->assertSame( [], $rows );
	}

	/**
	 * PRレビュー指摘: `EntityWriter::validate()`が例外を投げた場合、catch節はtotals（skipped/
	 * warned）を加算するだけで`continue`しており、成功パスにしかない`$dry_run_rows[]`追加を
	 * 通らないため、CSVレポートには当該アイテムの行が一切現れなかった（warned件数とCSVの
	 * 行数が食い違う）。catch節でも1行記録されることを確認する。
	 */
	public function test_dry_run_records_a_row_when_the_writer_throws(): void {
		$adapter = new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] );
		$writer  = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				throw new \RuntimeException( 'simulated validate() failure' );
			}
		};

		$importer = new Importer( $this->mappings );
		$result   = $importer->run_page( $adapter, $writer, 'category', Cursor::start(), true, 1, 'run-dry-throw' );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['warned'] );

		$rows = ( new \CartBridgeJP\Sync\DryRunItemRepository() )->list_after( 'run-dry-throw', 0, 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'c1', $rows[0]['remote_id'] );
		$this->assertSame( 'skipped', $rows[0]['operation'] );
		$this->assertSame( [ 'validation_exception' ], json_decode( (string) $rows[0]['warnings_json'], true ) );
	}

	/**
	 * PRレビュー指摘: checksum一致スキップ（差分なし）は`continue`するだけで`$dry_run_rows[]`に
	 * 一切追加されないため、既にインポート済みの店舗で再度dry-runを実行すると、CSVレポートが
	 * ほぼ空になっていた（03 §10.4の「全量出力」契約に反する）。checksum一致の場合もwriterは
	 * 一切呼ばず（既存の冪等性最適化を保つ）、warningsなしのskipped行として記録されることを
	 * 確認する。
	 */
	public function test_dry_run_records_a_skipped_row_when_checksum_is_unchanged(): void {
		$item    = CanonicalFactory::category( 'c1', 'Category 1' );
		$adapter = new MockPlatformAdapter( categories: [ $item ] );

		// 事前に実インポートを走らせ、mappingsにchecksumをキャッシュさせる。
		$real_writer = new InMemoryWriter();
		$importer    = new Importer( $this->mappings );
		$importer->run_page( $adapter, $real_writer, 'category', Cursor::start(), false );

		$existing_local_id = $this->mappings->find_local_id( $adapter->id(), 'category', 'c1' );
		$this->assertNotNull( $existing_local_id );

		$dry_writer = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				throw new \RuntimeException( 'checksum-matched items must not be re-validated' );
			}
		};

		$result = $importer->run_page( $adapter, $dry_writer, 'category', Cursor::start(), true, 1, 'run-dry-checksum' );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 0, $result['totals']['warned'] );

		$rows = ( new \CartBridgeJP\Sync\DryRunItemRepository() )->list_after( 'run-dry-checksum', 0, 10 );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'c1', $rows[0]['remote_id'] );
		$this->assertSame( 'skipped', $rows[0]['operation'] );
		$this->assertSame( $existing_local_id, (int) $rows[0]['existing_local_id'] );
		$this->assertSame( [], json_decode( (string) $rows[0]['warnings_json'], true ) );
	}

	/**
	 * issue #55: `unchanged`（`skipped`の内訳）はchecksum一致スキップだけを数える。dry-runの
	 * `created + updated + unchanged` を「移行できる件数」とするため（Pro 案内）、検証で見送った
	 * （writerがskippedを返した）アイテムまで数えると「どの版でも移行できない」件数が消える。
	 */
	public function test_unchanged_counts_only_checksum_matched_skips(): void {
		$importer = new Importer( $this->mappings );

		// c1だけを先に実インポートし、checksumをキャッシュさせる。
		$importer->run_page( new MockPlatformAdapter( categories: [ CanonicalFactory::category( 'c1', 'Category 1' ) ] ), new InMemoryWriter(), 'category', Cursor::start(), false );

		$adapter = new MockPlatformAdapter(
			categories: [
				CanonicalFactory::category( 'c1', 'Category 1' ),
				CanonicalFactory::category( 'c2', 'Category 2' ),
				CanonicalFactory::category( 'c3', 'Category 3' ),
			]
		);
		$writer  = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				// `Woo\DryRunRepository`と同じ契約（dry-runはlocal_id=0）。c2は検証で見送る。
				return 'c2' === $item->remote_id()
					? new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ 'product_price_invalid' ] )
					: new WriteResult( 0, WriteResult::OPERATION_CREATED, [] );
			}
		};

		$result = $importer->run_page( $adapter, $writer, 'category', Cursor::start(), true );

		$this->assertSame( 3, $result['totals']['processed'] );
		$this->assertSame( 1, $result['totals']['created'] );
		$this->assertSame( 2, $result['totals']['skipped'] );
		$this->assertSame( 1, $result['totals']['unchanged'] );
	}

	/**
	 * D25（issue #98）: エクスポートで結ばれた実体を上書きしなかった結果（`LINKED_BY_EXPORT_NOT_IMPORTED`）は、mapping がある実体なら
	 * 既に結ばれているので`unchanged`にも数え、mapping（エクスポートの checksum）には触れない。
	 * mapping が無い結果（在庫は商品の mapping で届く）は`unchanged`に数えない。実書込み・dry-run の両方。
	 */
	public function test_a_result_kept_by_link_direction_counts_as_unchanged_only_with_a_mapping(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ), CanonicalFactory::product( 'p2', 'SKU-2' ) ] );
		$this->mappings->upsert( $adapter->id(), 'product', 'p1', 501, 'export-checksum' );

		$writer = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::LINKED_BY_EXPORT_NOT_IMPORTED ] );
			}
		};

		foreach ( [ false, true ] as $is_dry_run ) {
			$result = ( new Importer( $this->mappings ) )->run_page( $adapter, $writer, 'product', Cursor::start(), $is_dry_run );

			$this->assertSame( 2, $result['totals']['skipped'] );
			$this->assertSame( 1, $result['totals']['unchanged'] );
			$this->assertSame( 2, $result['totals']['warned'] );
			$this->assertSame( 'export-checksum', $this->mappings->find_checksum( $adapter->id(), 'product', 'p1' ) );
			$this->assertNull( $this->mappings->find_local_id( $adapter->id(), 'product', 'p2' ) );
		}
	}

	/**
	 * D25: 他の理由でスキップした結果（mapping あり）は従来どおり`unchanged`に数えない（「移行できない」側）。
	 */
	public function test_other_skips_with_a_mapping_are_not_counted_as_unchanged(): void {
		$adapter = new MockPlatformAdapter( products: [ CanonicalFactory::product( 'p1', 'SKU-1' ) ] );
		$this->mappings->upsert( $adapter->id(), 'product', 'p1', 501, 'stale' );

		$writer = new class() implements WooWriter {
			public function write( string $entity, CanonicalModel $item, ?int $existing_local_id ): WriteResult {
				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::PRODUCT_SAVE_FAILED ] );
			}
		};

		$result = ( new Importer( $this->mappings ) )->run_page( $adapter, $writer, 'product', Cursor::start(), false );

		$this->assertSame( 1, $result['totals']['skipped'] );
		$this->assertSame( 0, $result['totals']['unchanged'] );
	}
}
