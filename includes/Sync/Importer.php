<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Sync;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Adapters\Page;
use CartBridgeJP\Adapters\PlatformAdapter;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalProduct;
use CartBridgeJP\Canonical\CanonicalReview;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Support\Logger;
use CartBridgeJP\Woo\Support\Value;
use RuntimeException;
use Throwable;

/**
 * fetch → 書込 → mappings upsert のパイプライン。
 *
 * リモートIDは `CanonicalModel::remote_id()` から取得する（Product/Customer/Coupon/Review は
 * アダプタが `extras['remote_id']` に格納する契約。null の場合はアダプタ実装バグとして例外）。
 *
 * 無料版サンプル選定（D15）: product/customer はID指定取得（`run_sample_page`）、
 * stock はサンプル商品のID指定取得結果から導出（`run_sample_stock_page`、§10.2 #4）、
 * review はカーソル走査＋サンプル商品メンバーシップで絞り込む（`run_page` の $sample）。
 */
final class Importer {

	public function __construct(
		private readonly MappingRepository $mappings,
		private readonly Logger $logger = new Logger()
	) {}

	/**
	 * カーソル走査エンティティを1ページ処理する。$sample が渡された場合は
	 * サンプル商品に紐づくアイテムのみを取り込む（呼び出し側=JobManagerが対象エンティティを判断する）。
	 *
	 * @return array{next_cursor:?Cursor,total:?int,totals:array<string,int>}
	 */
	public function run_page(
		PlatformAdapter $adapter,
		WooWriter $writer,
		string $entity,
		Cursor $cursor,
		bool $is_dry_run,
		?LimitPolicy $limit_policy = null,
		?SampleSet $sample = null,
		?int $job_id = null
	): array {
		[ $items, $next_cursor, $total ] = $this->fetch_page( $adapter, $entity, $cursor );

		$totals = $this->process_items( $adapter, $writer, $entity, $items, $is_dry_run, $limit_policy, $sample, $job_id );

		return [
			'next_cursor' => $next_cursor,
			'total'       => $total,
			'totals'      => $totals,
		];
	}

	/**
	 * サンプルID指定取得エンティティ（product/customer）をまとめて処理する（D15 #4）。
	 * ページングは不要（サンプル件数は上限で有界）。
	 *
	 * `$limit_policy`は`SampleSelector`のサンプル件数上限（50/10件）とは別に必要: サンプル上限は
	 * 「1回の選定で作られるサンプルセットのサイズ」しか制限せず、クリーンアップ→再選定
	 * （§10.2 #7）を経て複数回サンプルが入れ替わった場合の**累積**作成数までは制限しない。
	 * `cbjp_mappings`の累積件数を正とする`LimitPolicy`を渡すことで、`run_page()`の
	 * カーソル走査と同じ累積上限をこの経路にも適用する。
	 *
	 * @param array<int,string> $remote_ids
	 * @return array{totals:array<string,int>}
	 */
	public function run_sample_page( PlatformAdapter $adapter, WooWriter $writer, string $entity, array $remote_ids, bool $is_dry_run, ?LimitPolicy $limit_policy = null, ?int $job_id = null ): array {
		$items = [];

		foreach ( $remote_ids as $remote_id ) {
			$item = match ( $entity ) {
				'product'  => $adapter->fetch_product_by_remote_id( (string) $remote_id ),
				'customer' => $adapter->fetch_customer_by_remote_id( (string) $remote_id ),
				default    => throw new RuntimeException( "Entity \"{$entity}\" does not support sample ID fetch." ),
			};

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return [ 'totals' => $this->process_items( $adapter, $writer, $entity, $items, $is_dry_run, $limit_policy, null, $job_id ) ];
	}

	/**
	 * 無料版の在庫取込（§10.2 #4）: `fetchStocks` の全量走査はレート制限を浪費するため使わず、
	 * サンプル商品のID指定取得結果（CanonicalProduct.stock/variants）から在庫を導出して書き込む。
	 *
	 * @param array<int,string> $product_remote_ids
	 * @return array{totals:array<string,int>,total:int}
	 */
	public function run_sample_stock_page( PlatformAdapter $adapter, WooWriter $writer, array $product_remote_ids, bool $is_dry_run, ?int $job_id = null ): array {
		$items = [];

		foreach ( $product_remote_ids as $remote_id ) {
			$remote_id = (string) $remote_id;
			$product   = $adapter->fetch_product_by_remote_id( $remote_id );

			if ( null === $product ) {
				continue;
			}

			// アダプタ拡張点の信頼境界（アーキテクチャ原則8）: `fetch_product_by_remote_id()`が
			// 要求したIDと異なる商品を返した場合（契約違反アダプタのバグ等）、下で要求ID
			// （`ProductResolver`が解決する対象＝正しい商品）と`$product`の在庫・SKU（別の商品の
			// データ）を組み合わせてしまうと、誤った商品の在庫が正しい商品に書き込まれ、
			// 本来在庫切れの商品が購入可能になりかねない。IDが一致しない場合は取得失敗と
			// 同様に扱いスキップする。
			if ( $product->remote_id() !== $remote_id ) {
				continue;
			}

			array_push( $items, ...$this->stocks_for_sample_product( $remote_id, $product ) );
		}

		// バリエーションを持つ商品は複数件のCanonicalStockに展開されるため、進捗率の分母は
		// `$product_remote_ids`の商品数ではなく実際に処理する`$items`件数を報告する
		// （呼び出し側=JobManagerが商品数をそのままtotalにすると、バリエーション展開分だけ
		// processedがtotalを超えてしまう）。
		return [
			'totals' => $this->process_items( $adapter, $writer, 'stock', $items, $is_dry_run, null, null, $job_id ),
			'total'  => count( $items ),
		];
	}

	/**
	 * バリエーションを持つ商品は、親レベルではなくバリエーション単位（`CanonicalProduct::$variants`。
	 * ASP非依存の `remote_id`/`sku`/`stock` キー規約は `Woo\Writer\VariationWriter` 参照）で
	 * `CanonicalStock` を作る。variable商品の親には在庫を書かない
	 * （`WC_Product_Variable::sync()` が子から導出するため親への直接書込は無効。CLAUDE.md）ので、
	 * 親レベルの1件だけを返すと `Woo\Writer\StockWriter` が対象を
	 * `WC_Product_Variable` と判定してスキップし、無料版のサンプル在庫が実質書き込まれない。
	 * バリエーションが無い商品は従来どおり商品レベル1件を返す。
	 *
	 * @return array<int,CanonicalStock>
	 */
	private function stocks_for_sample_product( string $remote_id, CanonicalProduct $product ): array {
		$variants = $product->variants;

		if ( [] === $variants ) {
			return [
				new CanonicalStock(
					$remote_id,
					null,
					$product->sku,
					$product->stock,
					CanonicalStock::is_in_stock( $product->stock )
				),
			];
		}

		$items = [];

		foreach ( $variants as $variant ) {
			// `$variant`はアダプタが返す`CanonicalProduct::$variants`（配列<string,mixed>）で、
			// 型宣言はドキュメント上の契約でしかない（アーキテクチャ原則8）。要素自体が配列でない
			// 場合（オブジェクト等）にオフセットアクセスするとTypeError/Errorになりジョブ全体が
			// 失敗してしまうため、`ProductTransformer::variants()`/`StockTransformer`と同じく
			// この1件だけをスキップする。
			if ( ! is_array( $variant ) ) {
				continue;
			}

			// `VariationWriter`が同じremote_id/sku/stock契約に使う`Value`ヘルパーで防御的に取り出し、
			// 非スカラー値が来ても在庫管理商品を無条件に「在庫あり」扱いしないようフェイルクローズする。
			$variant_remote_id = Value::string( $variant['remote_id'] ?? null );

			if ( null === $variant_remote_id ) {
				continue;
			}

			// キー欠損/明示的nullは「在庫管理外」という正当な契約（CLAUDE.md）だが、値が存在するのに
			// `Value::int()` でパースできない（配列等の不正値）場合は同じnullに丸められてしまい、
			// `is_in_stock(null)`が「在庫あり」と誤判定する。両者を区別し、後者は0にフェイルクローズする。
			$raw_stock = $variant['stock'] ?? null;
			$quantity  = null === $raw_stock ? null : ( Value::int( $raw_stock ) ?? 0 );

			$items[] = new CanonicalStock(
				$remote_id,
				$variant_remote_id,
				Value::string( $variant['sku'] ?? null ),
				$quantity,
				CanonicalStock::is_in_stock( $quantity )
			);
		}

		return $items;
	}

	/**
	 * @param array<int,CanonicalModel> $items
	 * @return array<string,int>
	 */
	private function process_items(
		PlatformAdapter $adapter,
		WooWriter $writer,
		string $entity,
		array $items,
		bool $is_dry_run,
		?LimitPolicy $limit_policy,
		?SampleSet $sample,
		?int $job_id = null
	): array {
		$totals = [
			'processed' => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'warned'    => 0,
		];

		$platform = $adapter->id();

		// ページ内アイテムの既存mapping（local_id/checksum）を一括プリロードし、
		// アイテム毎のSELECTを避ける。dry-runでも読み取り専用で使い、新規/更新/スキップを分類する（D16）。
		// `remote_id_of()`はアダプタの契約違反（remote_id欠損）をnullで表す（例外を投げない）。
		// ここで例外を投げると、ページ内の1件だけが契約違反でも`array_map()`がループに入る前に
		// 中断し、下のforeachで確立している「1件の異常データで移行全体を止めない」方針
		// （186行目以降）が、このremote_id解決自体には適用されずページ全体が失敗してしまう。
		$remote_ids = array_map(
			fn( CanonicalModel $item ): ?string => $this->remote_id_of( $item ),
			$items
		);
		$existing   = $this->mappings->find_many( $platform, $entity, array_filter( $remote_ids, static fn ( ?string $id ): bool => null !== $id ) );

		// 残枠はページ開始時に一度だけ解決する（アイテム毎のCOUNT(*)を避ける）。
		// 上限は新規作成のみを対象とし、既存mappingの更新は阻まない（D16の上書きポリシー前提）。
		$remaining = ( null !== $limit_policy ) ? $limit_policy->remaining( $platform, $entity ) : null;

		foreach ( $items as $index => $item ) {
			++$totals['processed'];

			if ( null !== $sample && ! in_array( $this->sample_match_key( $item ), $sample->product_remote_ids, true ) ) {
				++$totals['skipped'];
				continue;
			}

			$remote_id = $remote_ids[ $index ];

			if ( null === $remote_id ) {
				// アダプタの契約違反（`extras['remote_id']`欠損）。この1件はmappingを解決できず
				// 永続化もできないため、ページ全体を止めずこのアイテムだけをskipped扱いにする
				// （下の`catch`節と同じ「1件の異常データで移行全体を止めない」方針）。
				++$totals['skipped'];
				++$totals['warned'];
				$this->logger->error( "Adapter returned a \"{$entity}\" item without a remote id.", [], $job_id );
				continue;
			}

			$row               = $existing[ $remote_id ] ?? null;
			$existing_local_id = $row['local_id'] ?? null;

			// checksum一致＝変更なしはスキップする（03 §5 冪等性）。
			if ( null !== $row && null !== $row['checksum'] && $row['checksum'] === $item->checksum() ) {
				++$totals['skipped'];
				continue;
			}

			$consumed_quota_slot = false;

			if ( ! $is_dry_run && null === $existing_local_id && null !== $remaining ) {
				if ( $remaining <= 0 ) {
					++$totals['skipped'];
					continue;
				}

				--$remaining;
				$consumed_quota_slot = true;
			}

			try {
				$result = $writer->write( $entity, $item, $existing_local_id );
			} catch ( Throwable $exception ) {
				// 1件のアイテムでの例外がページ全体を失敗させると、`JobManager::process_job()`が
				// ジョブを恒久的にfailedへ遷移させ、このページ内の他の正常なアイテムの処理まで
				// 巻き添えになる（このページ手前までの進捗も、ページ自体が例外で完了しなかった
				// ため`update_progress()`に到達せず永続化されない）。1件の異常データで移行全体が
				// 止まらないよう、このアイテムのみskipped扱いにして処理を継続する
				// （local_id 0と同様mappingsは書かないため、次回実行時に再試行される）。
				//
				// 上で確保した無料版サンプル上限の枠は、この例外で実体が何も作られなかった
				// ため消費されたことにしない（返却しないと、無効な1件が枠を1つ無駄に食い潰し、
				// 本来枠内に収まるはずの正常なアイテムがこのページで弾かれてしまう）。
				if ( $consumed_quota_slot ) {
					++$remaining;
				}

				++$totals['skipped'];
				++$totals['warned'];
				// `Support\Logger`の契約（個人情報禁止ルール。ID以外を含めない）はcontextだけでなく
				// message自体にも及ぶ（`JobManager::process_job()`の同種のcatch節も固定文言のみを
				// 渡し、例外メッセージはログに含めない）。`$exception->getMessage()`は
				// `WC_Data_Exception`等が投げる自由文字列でありワークライター経由で顧客の
				// メールアドレス等の値をそのまま含みうるため、ここでも固定文言＋
				// 例外クラス名（`exception`キー）にとどめる。
				$this->logger->error(
					"Writer threw while processing a {$entity} item.",
					[
						'remote_id' => $remote_id,
						'exception' => $exception::class,
					],
					$job_id
				);

				continue;
			}

			// local_id 0 は「ローカル実体を作成/更新できなかった」ことを表す契約
			// （例: stockの対象商品がまだ未インポート）。checksumを保存すると次回実行時の
			// checksum一致スキップに掛かり永久に再試行できなくなるため、mappingsを書かない。
			// dry-runは`DryRunReporter`が仕様として常にlocal_id=0でcreated/updatedを返す
			// （何も永続化しないため）ため、この判定の対象外にする。
			$did_persist = ! $is_dry_run && 0 !== $result->local_id;

			if ( $did_persist ) {
				// `$result->fully_resolved`がfalse（category/tag参照・customer_ref等の一部が
				// 未解決のまま実体だけ保存された）の場合はchecksumをキャッシュしない
				// （`MappingRepository::upsert()`はnullをNULLIF経由でSQLのNULLへ変換する）。
				// checksumをキャッシュすると、参照先が後から解決可能になった場合でも
				// 182行目のchecksum一致スキップに永久に掛かり、二度と再試行されなくなる。
				$checksum = $result->fully_resolved ? $item->checksum() : null;

				$this->mappings->upsert( $platform, $entity, $remote_id, $result->local_id, $checksum );

				// ループ開始前に一括プリロードした`$existing`はこのループ内で行われた更新を
				// 反映しない。同一ページ内（アダプタのページング境界バグ等）に同じremote_idの
				// アイテムが複数含まれると、後続のアイテムがこの古いスナップショットを見て
				// 「未作成」と誤認し、別の孤立エンティティを新規作成してしまう
				// （`upsert()`はremote_id単位でON DUPLICATE KEY UPDATEするため、mappingは
				// 最後に処理したエンティティだけを指し、先行するエンティティは孤立して残る）。
				// 直前に確定したlocal_idでこの場で更新し、以後の同一remote_idの再利用に備える。
				$existing[ $remote_id ] = [
					'local_id' => $result->local_id,
					'checksum' => $checksum,
				];
			} elseif ( $consumed_quota_slot ) {
				// 例外と同じ理由: 実体を作成/更新できなかった（local_id 0）場合も枠を消費した
				// ことにしない。
				++$remaining;
			}

			// この契約は `WooWriter`/`EntityWriter` インターフェース上で型として強制できない
			// （PHPの型システムでは「local_idが0ならoperationはskippedでなければならない」を
			// 表現できない）ため、ここで防御的に正規化する。将来のwriter実装や
			// `cbjp/adapters/register`経由の外部アダプタがlocal_id=0のままcreated/updatedを
			// 返す契約違反を犯しても、totals集計（結果レポート）上は実態どおりskipped扱いになる。
			// dry-runでは`$did_persist`が常にfalseになるため、`! $is_dry_run`を別途チェックして
			// dry-run結果レポートの新規/更新件数が常に0件になることを防ぐ
			// （対象にすると常に0件表示になってしまう）。
			$operation = ( ! $is_dry_run && ! $did_persist ) ? WriteResult::OPERATION_SKIPPED : $result->operation;

			++$totals[ $operation ];

			if ( [] !== $result->warnings ) {
				++$totals['warned'];
			}
		}

		return $totals;
	}

	/**
	 * @return array{0:array<int,CanonicalModel>,1:?Cursor,2:?int}
	 */
	private function fetch_page( PlatformAdapter $adapter, string $entity, Cursor $cursor ): array {
		return match ( $entity ) {
			'category' => [ $adapter->fetch_categories(), null, null ],
			'tag'      => [ $adapter->fetch_tags(), null, null ],
			// product/customer は無料版ではサンプルID指定取得（run_sample_page）を使うが、
			// Pro版（上限解除時）は全量カーソル走査でここを通る。
			'product'  => $this->unwrap_page( $adapter->fetch_products( $cursor ) ),
			'customer' => $this->unwrap_page( $adapter->fetch_customers( $cursor ) ),
			'order'    => $this->unwrap_page( $adapter->fetch_orders( $cursor ) ),
			'stock'    => $this->unwrap_page( $adapter->fetch_stocks( $cursor ) ),
			'coupon'   => $this->unwrap_page( $adapter->fetch_coupons( $cursor ) ),
			'review'   => $this->unwrap_page( $adapter->fetch_reviews( $cursor ) ),
			default    => throw new RuntimeException( "Entity \"{$entity}\" is not a cursor-walk entity." ),
		};
	}

	/**
	 * @return array{0:array<int,CanonicalModel>,1:?Cursor,2:?int}
	 */
	private function unwrap_page( Page $page ): array {
		return [ $page->items, $page->next_cursor, $page->total ];
	}

	/**
	 * サンプル商品メンバーシップの照合キー（stock/review用）。
	 */
	private function sample_match_key( CanonicalModel $item ): string {
		if ( $item instanceof CanonicalStock || $item instanceof CanonicalReview ) {
			return $item->product_ref;
		}

		return '';
	}

	private function remote_id_of( CanonicalModel $item ): ?string {
		return $item->remote_id();
	}
}
