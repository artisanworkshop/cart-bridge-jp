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

		$target = $this->resolver->resolve_stock_target( $item->variant_ref, $item->product_ref, $item->sku );

		if ( null === $target ) {
			// 対象商品がまだ未インポート等で解決できない。local_id 0 はImporterに
			// mappingsを書かせない契約なので、次回実行時に再試行できる。
			$ref = $item->variant_ref ?? $item->product_ref;

			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::STOCK_PRODUCT_UNRESOLVED, $ref ) ] );
		}

		$warnings = [];

		if ( null === $item->variant_ref && $target instanceof WC_Product_Variable ) {
			// variable商品に親レベルの在庫（variant_ref=null）が来た場合、manage_stockをtrueにすると
			// variation側の在庫と競合するため、親はstock_statusのみ更新する（quantityは無視する。
			// Importer::run_sample_stock_page()はvariant_refを常にnullで作るため到達しうる）。
			StockApplier::apply( $target, null, $item->in_stock );
			$warnings[] = WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, (string) $target->get_id() );
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
