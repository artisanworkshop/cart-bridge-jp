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

		if ( $target instanceof WC_Product_Variable ) {
			// variable商品自体が対象になった場合は在庫を一切書き込まず、実体に触れないまま
			// 警告だけを積んでskippedを返す。variant_ref=nullで明示的に親レベル在庫が来た場合
			// （`Importer::run_sample_stock_page()`）だけでなく、variant_refが指定されていても
			// mapping未整備/stale時に`resolve_stock_target()`のSKUフォールバックが親商品自身の
			// SKU（`SkuGuard`で親にも設定され得る）にマッチして親が返ってくるケースがあるため、
			// variant_refの有無に関わらずここで弾く必要がある。
			//
			// 親の`stock_status`自体はWooCommerceが子variationから導出し
			// （`WC_Product_Variable::sync()`→`sync_stock_status()`。`VariationWriter::sync()`が
			// 商品同期のたびに呼ぶ）、親へ直接`set_stock_status()`しても保存時に子由来の値へ
			// 戻るため、書いても在庫状態は変わらない（WC実体で確認済み）。それでも書き込みを
			// 行うと実害があるため止める:
			//
			// 1. `StockApplier::apply()`が`set_manage_stock(false)`するため、店舗が親レベルで
			//    在庫管理していた場合（WooCommerceはvariable商品の親レベル在庫管理を許容する）
			//    その設定と数量が失われる（`WC_Product::validate_props()`が
			//    `manage_stock=false`のとき`stock_quantity`を空へ落とす）。
			// 2. 在庫行ごとに無意味な`save()`が走り、商品transient破棄・属性ルックアップ
			//    テーブル更新のコストだけが積み上がる。
			// 3. ASP側の在庫が何も反映されていないのに結果レポートへ`updated`として計上され、
			//    移行結果の件数が実態とずれる。
			//
			// local_idは解決できた親のIDを返す（`STOCK_PRODUCT_UNRESOLVED`と異なり対象自体は
			// 特定できているため）。この状況は再実行しても解消し得ない終端状態であり、
			// `Importer`にmappingとchecksumを記録させることで毎回同じ警告を積み直さない。
			return new WriteResult( $target->get_id(), WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::STOCK_PARENT_OF_VARIABLE, $ref ) ] );
		}

		StockApplier::apply( $target, $item->quantity, $item->in_stock );

		// `resolve_stock_target()` は既存の商品/バリエーションをmappings/SKUで解決するのみで
		// 新規作成することは無いため、`$existing_local_id`（stockエンティティのmapping有無）に
		// 関わらず実体としては常に更新である。ここをexisting_local_idで判定すると、
		// stockのmapping行が初回（null）のケースで実際は更新なのにcreatedと報告され、
		// 結果レポートの件数が不正確になる。
		$target_id = $target->save();

		if ( $target instanceof WC_Product_Variation ) {
			// variationの在庫を変えたら親の価格レンジ・stock_statusを子から再計算し直す。
			WC_Product_Variable::sync( $target->get_parent_id() );
		}

		return new WriteResult( $target_id, WriteResult::OPERATION_UPDATED );
	}
}
