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
			// variation側の在庫と競合するため、親はstock_statusのみ更新する
			// （Importer::run_sample_stock_page()はvariant_refを常にnullで作るため到達しうる）。
			$target->set_manage_stock( false );
			$target->set_stock_status( $item->in_stock ? 'instock' : 'outofstock' );
			$warnings[] = WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, (string) $target->get_id() );
		} elseif ( null === $item->quantity ) {
			$target->set_manage_stock( false );
			$target->set_stock_status( $item->in_stock ? 'instock' : 'outofstock' );
		} else {
			$target->set_manage_stock( true );
			$target->set_stock_quantity( $item->quantity );
			$target->set_stock_status( $item->quantity > 0 ? 'instock' : 'outofstock' );
		}

		$operation = null === $existing_local_id ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;
		$target_id = $target->save();

		if ( $target instanceof WC_Product_Variation ) {
			WC_Product_Variable::sync( $target->get_parent_id() );
		}

		return new WriteResult( $target_id, $operation, $warnings );
	}
}
