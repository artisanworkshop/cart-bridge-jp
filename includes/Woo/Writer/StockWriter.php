<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalStock;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\ProductResolver;
use CartBridgeJP\Woo\Support\StockApplier;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * `CanonicalStock` をWooの商品/バリエーション在庫として書き込む。
 */
final class StockWriter implements EntityWriter {

	public function __construct( private readonly ProductResolver $resolver ) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalStock ) {
			throw new RuntimeException( 'StockWriter received an unsupported Canonical model.' );
		}

		// 対象商品がまだ未インポート等で解決できない場合・STOCK_PARENT_OF_VARIABLE警告の
		// detailに使う（F1-6のdry-run結果レポートはASP側remote_idで問題箇所を特定する契約の
		// ため、Woo内部のpost IDではなくこちらを使う）。
		$ref    = $item->variant_ref ?? $item->product_ref;
		$target = $this->resolver->resolve_stock_target( $item->variant_ref, $item->product_ref, $item->sku );

		if ( null === $target ) {
			// local_id 0 はImporterにmappingsを書かせない契約なので、次回実行時に再試行できる。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::STOCK_PRODUCT_UNRESOLVED, $ref ) ] );
		}

		$warnings = [];

		if ( $target instanceof WC_Product_Variable ) {
			// variable商品自体が対象になった場合、manage_stockをtrueにするとvariation側の在庫と
			// 競合するため、stock_statusのみ更新する（quantityは無視する）。variant_ref=nullで
			// 明示的に親レベル在庫が来た場合（Importer::run_sample_stock_page()）だけでなく、
			// variant_refが指定されていてもmapping未整備/stale時にresolve_stock_target()の
			// SKUフォールバックが親商品自身のSKU（SkuGuardで親にも設定され得る）にマッチして
			// 親が返ってくるケースでも、variant_refの有無に関わらずここで弾く必要がある。
			StockApplier::apply( $target, null, $item->in_stock );
			$warnings[] = WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, $ref );
		} else {
			StockApplier::apply( $target, $item->quantity, $item->in_stock );
		}

		// `resolve_stock_target()` は既存の商品/バリエーションをmappings/SKUで解決するのみで
		// 新規作成することは無いため、`$existing_local_id`（stockエンティティのmapping有無）に
		// 関わらず実体としては常に更新である。ここをexisting_local_idで判定すると、
		// stockのmapping行が初回（null）のケースで実際は更新なのにcreatedと報告され、
		// 結果レポートの件数が不正確になる。
		$target_id = $target->save();

		if ( $target instanceof WC_Product_Variation ) {
			WC_Product_Variable::sync( $target->get_parent_id() );
		}

		return new WriteResult( $target_id, WriteResult::OPERATION_UPDATED, $warnings );
	}
}
