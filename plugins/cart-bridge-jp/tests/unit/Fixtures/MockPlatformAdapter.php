<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Tests\Fixtures;

use CartBridgeJP\Adapters\AbstractPlatformAdapter;
use CartBridgeJP\Adapters\Capabilities;
use CartBridgeJP\Adapters\ConnectionResult;
use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PushResult;
use CartBridgeJP\Adapters\UnsupportedOperationException;
use CartBridgeJP\Canonical\CanonicalCategory;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalStock;

/**
 * テスト用のモックアダプタ。固定フィクスチャをカーソル（offset方式）でページングして返す。
 * Sync層（JobManager/Importer/Exporter）とツールのテストに使う。顧客・受注・クーポンは `MockCommerceAdapter`（R3-6c1）。
 */
final class MockPlatformAdapter extends AbstractPlatformAdapter {

	private const PAGE_SIZE = 2;

	/**
	 * fetch_products()/fetch_categories() の呼び出し回数。
	 * paused時にJobManagerが空回りで再フェッチしていないことの検証に使う。
	 */
	public int $fetch_calls = 0;

	/**
	 * `mapping_candidates()` の呼び出し回数（REST が 1 要求で 1 回だけ呼ぶことの確認用。R3-6c1）。
	 */
	public int $mapping_candidates_calls = 0;

	/**
	 * `push_product()`に渡された`(CanonicalProduct, ?remote_id)`の記録
	 * （`Sync\Exporter`のテストで実際にpushされた内容を検証する用）。
	 *
	 * @var array<int,array{0:CanonicalProduct,1:?string}>
	 */
	public array $pushed_products = [];

	/**
	 * 次にpush_product()が発行するremote_idの採番カウンタ（新規作成時のみ使用）。
	 */
	private int $next_pushed_remote_id = 1;

	/**
	 * `push_stock()`に渡された在庫の記録（`JobManagerExportTest`でexport正常系を検証する用。`$pushed_products`と同じ役割）。
	 * 顧客・受注・クーポンの送信の記録は `MockCommerceAdapter`（R3-6c1）。
	 *
	 * @var array<int,CanonicalStock>
	 */
	public array $pushed_stocks = [];

	/**
	 * @param array<int,CanonicalProduct>  $products
	 * @param array<int,CanonicalCategory> $categories
	 * @param \Throwable|null              $fetch_failure 指定すると全fetch系メソッドがこの例外を投げる（障害シナリオのテスト用）。
	 * @param array<int,mixed>|null        $connection_fields_override 指定すると connection_fields() がこの値をそのまま返す
	 *   （ConnectionField以外の混入など、契約違反アダプタのシナリオのテスト用）。
	 * @param ?Capabilities                $capabilities_override 指定すると capabilities() がこの値をそのまま返す
	 *   （BASE等、特定capabilityがfalseのアダプタのシナリオのテスト用）。
	 * @param ?CanonicalProduct             $product_by_remote_id_override 指定すると
	 *   fetch_product_by_remote_id() が要求IDを無視してこの商品をそのまま返す
	 *   （要求IDと異なる商品を返す契約違反アダプタのシナリオのテスト用）。
	 * @param ?array<string,mixed>          $mapping_candidates_override 指定すると
	 *   mapping_candidates() がこの値をそのまま返す（`RestController`の候補集約ロジックの
	 *   テスト用）。
	 * @param bool                           $push_products_supported 指定するとpush_product()が
	 *   `UnsupportedOperationException`を投げず成功を返す（`Sync\Exporter`のテスト用。
	 *   ColorMe実装（E2-3）が無いPR-A時点でexportの正常系を検証するために必要）。
	 * @param bool                           $push_stocks_supported 指定するとpush_stock()が`UnsupportedOperationException`を
	 *   投げず成功を返す（`push_products_supported`の在庫版。顧客・受注・クーポンは `MockCommerceAdapter` の `push_supported`。R3-6c1）。
	 * @param \Throwable|null                $create_push_failure 指定すると`push_product()`の**作成経路**
	 *   （`$remote_id === null`）だけがこの例外を投げる
	 *   （D21-B。push intentが「作成結果不明」として残る/確定して消えるシナリオのテスト用。
	 *   更新経路〔`$remote_id`が非null〕には影響しない）。
	 * @param string                          $platform_id id() が返す値（既定 'mock'）。`Importer`/`Exporter`/`JobManager` は
	 *   mapping のキーを登録キーではなく `$adapter->id()` から決めるため、`cbjp/adapters/register` に
	 *   別のキー（例: `colorme`）で登録する手動検証（`verify-with-mock-adapter` スキル）では、そのキーと同じ値を渡す。
	 * @param \Throwable|null                $fetch_by_id_failure 指定すると`fetch_product_by_remote_id()`だけがこの例外を投げる
	 *   （`$fetch_failure`と違い一覧の取得へ影響しない。push intent の解除の障害シナリオのテスト用。R3-6c1 で顧客・受注から商品へ移した）。
	 */
	public function __construct(
		private readonly array $products = [],
		private readonly array $categories = [],
		private readonly ?\Throwable $fetch_failure = null,
		private readonly ?array $connection_fields_override = null,
		private readonly ?Capabilities $capabilities_override = null,
		private readonly ?CanonicalProduct $product_by_remote_id_override = null,
		private readonly ?array $mapping_candidates_override = null,
		private readonly bool $push_products_supported = false,
		private readonly bool $push_stocks_supported = false,
		private readonly ?\Throwable $create_push_failure = null,
		private readonly string $platform_id = 'mock',
		private readonly ?\Throwable $fetch_by_id_failure = null
	) {}

	public function id(): string {
		return $this->platform_id;
	}

	private function maybe_fail(): void {
		if ( null !== $this->fetch_failure ) {
			throw $this->fetch_failure;
		}
	}

	public function label(): string {
		return 'Mock Platform';
	}

	public function capabilities(): Capabilities {
		return $this->capabilities_override ?? new Capabilities(
			can_create_category: true,
			can_push_images: true,
			has_tags: true,
			has_reviews: true,
			has_variants: true,
			rate_limit_per_minute: 600
		);
	}

	public function test_connection(): ConnectionResult {
		return ConnectionResult::success( 'Mock Shop' );
	}

	public function connection_fields(): array {
		return $this->connection_fields_override ?? [];
	}

	public function mapping_candidates(): array {
		++$this->mapping_candidates_calls;

		return $this->mapping_candidates_override ?? [];
	}

	public function fetch_products( Cursor $cursor ): Page {
		++$this->fetch_calls;
		$this->maybe_fail();

		return $this->paginate( $this->products, $cursor );
	}

	public function fetch_categories(): array {
		++$this->fetch_calls;
		$this->maybe_fail();

		return $this->categories;
	}

	public function fetch_tags(): array {
		return [];
	}

	public function fetch_stocks( Cursor $cursor ): Page {
		$stocks = array_map(
			static fn( CanonicalProduct $product ): CanonicalStock => new CanonicalStock(
				(string) $product->extras['remote_id'],
				null,
				$product->sku,
				$product->stock,
				true
			),
			$this->products
		);

		return $this->paginate( $stocks, $cursor );
	}

	public function fetch_reviews( Cursor $cursor ): Page {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function fetch_product_by_remote_id( string $remote_id ): ?CanonicalProduct {
		if ( null !== $this->fetch_by_id_failure ) {
			throw $this->fetch_by_id_failure;
		}

		if ( null !== $this->product_by_remote_id_override ) {
			return $this->product_by_remote_id_override;
		}

		foreach ( $this->products as $product ) {
			if ( (string) $product->extras['remote_id'] === $remote_id ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * D21-B: `$create_push_failure`が指定されている場合、作成経路（`$remote_id === null`）だけ
	 * この例外を投げる。更新経路には影響しない。
	 */
	private function maybe_fail_create( ?string $remote_id ): void {
		if ( null !== $this->create_push_failure && null === $remote_id ) {
			throw $this->create_push_failure;
		}
	}

	public function push_product( CanonicalProduct $product, ?string $remote_id ): PushResult {
		if ( ! $this->push_products_supported ) {
			throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
		}

		$this->maybe_fail_create( $remote_id );

		$this->pushed_products[] = [ $product, $remote_id ];

		if ( null !== $remote_id ) {
			return new PushResult( $remote_id, PushResult::OPERATION_UPDATED );
		}

		return new PushResult( (string) $this->next_pushed_remote_id++, PushResult::OPERATION_CREATED );
	}

	public function push_category( CanonicalCategory $category ): PushResult {
		throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
	}

	public function push_stock( CanonicalStock $stock ): PushResult {
		if ( ! $this->push_stocks_supported ) {
			throw new UnsupportedOperationException( $this->id(), __FUNCTION__ );
		}

		$this->pushed_stocks[] = $stock;

		return new PushResult( $stock->remote_id(), PushResult::OPERATION_UPDATED );
	}

	/**
	 * @param array<int,mixed> $items
	 */
	private function paginate( array $items, Cursor $cursor ): Page {
		$offset = (int) $cursor->get( 'offset', 0 );
		$slice  = array_slice( $items, $offset, self::PAGE_SIZE );
		$next   = ( $offset + self::PAGE_SIZE ) < count( $items ) ? new Cursor( [ 'offset' => $offset + self::PAGE_SIZE ] ) : null;

		return new Page( $slice, $next, count( $items ) );
	}
}
