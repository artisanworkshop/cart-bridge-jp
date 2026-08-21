<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Canonical\CanonicalOrder;
use CartBridgeJP\Sync\MappingRepository;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\AddressMapper;
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\Support\MethodMap;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use Throwable;
use WC_Order;
use WC_Order_Item;
use WC_Order_Item_Product;
use WP_Error;

/**
 * `CanonicalOrder` をWooの受注として書き込む（`docs/03-design-decisions.md` §5 D10）。
 * HPOS対応のためWC_Order CRUD（`wc_create_order()`/`wc_get_order()`）のみを使い、
 * `wp_posts`直接操作は行わない。合計はASP側の値をそのまま設定し、`calculate_totals()`等の
 * Woo再計算は一切呼ばない。
 */
final class OrderWriter implements EntityWriter {

	public function __construct(
		private readonly string $platform,
		private readonly MappingRepository $mappings,
		private readonly OrderItemBuilder $items,
		private readonly MethodMap $methods
	) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalOrder ) {
			throw new RuntimeException( 'OrderWriter received an unsupported Canonical model.' );
		}

		$totals_warning = $this->validate_totals( $item->totals );

		if ( null !== $totals_warning ) {
			// 明細レベル（数量・tax_class）と異なり、注文合計の欠陥は注文全体の信頼性に関わる
			// ため、WC_Orderに一切触れる前にここで注文全体を見送る（CouponWriterの
			// type/group_limit判定と同じく、書込を始める前に検証する方針）。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ $totals_warning ] );
		}

		$order = null !== $existing_local_id ? wc_get_order( $existing_local_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			// mappingsが指す注文が手動削除等で既に存在しない場合、既存IDを信用せず新規作成へ
			// フォールバックする（TermWriter/CustomerWriter/ProductWriterの同種のstale-ID対応と
			// 同じ方針）。フォールバックしないと`WriteResult`のlocal_id=0はmappingsへ永続化
			// されない契約（`Importer`参照）のため、この注文は毎回skippedのまま永久に復旧しない。
			$existing_local_id = null;
			$order             = wc_create_order( [ 'status' => 'pending' ] );
		}

		if ( ! $order instanceof WC_Order ) {
			// wc_create_order()がWP_Error（DB障害・データストア誤設定等）を返した場合。無警告で
			// 握りつぶすと結果レポートから受注が丸ごと欠落した理由が分からなくなるため警告を積む。
			$detail = $order instanceof WP_Error ? $order->get_error_code() : '';

			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::ORDER_CREATE_FAILED, $detail ) ] );
		}

		$operation = null === $existing_local_id ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		try {
			// 明細（line item/shipping/fee）の組み立てはこの時点では`WC_Order_Item`
			// オブジェクトを作るだけで、`$order`へは何も反映しない（`remove_order_items()`を
			// 呼ぶ前に、失敗しうる処理を全て済ませておくため。理由は下のコメント参照）。
			$built_line_items     = $this->build_line_items( $item->line_items );
			$built_shipping_items = $this->build_shipping_and_fees( $item );
			$warnings             = array_merge( $built_line_items['warnings'], $built_shipping_items['warnings'] );

			$this->apply_totals( $order, $item->totals );
			$this->apply_currency_and_tax_settings( $order, $warnings );
			$warnings = array_merge( $warnings, $this->apply_status( $order, $item->status ) );
			$warnings = array_merge( $warnings, $this->apply_customer( $order, $item->customer_ref ) );
			$this->apply_addresses( $order, $item );
			$warnings = array_merge( $warnings, $this->apply_payment_method( $order, $item->payment ) );
			$this->apply_dates( $order, $item, null === $existing_local_id );
			$warnings = array_merge( $warnings, $this->totals_warnings( $item->totals ) );

			$this->apply_meta( $order, $item, $warnings );

			// 再実行時は明細を作り直す（冪等）。ここまでで組み立て・各種設定が全て成功した後、
			// 最後にまとめて削除→追加する。`remove_order_items()`はWooCommerce 11.0.0で
			// 追加された遅延削除機構（`$item_types_to_bulk_delete`、実削除は`save()`まで
			// 遅延）が無いバージョンでは即座にDBから明細を削除する。本プラグインの最低要件は
			// `WC requires at least: 10.0`のため、削除より前で例外が起きても（WC 10.x環境でも）
			// 既存の明細が失われないよう、失敗しうる処理を全て終えてから削除・追加・保存を行う。
			$order->remove_order_items();

			foreach ( array_merge( $built_line_items['items'], $built_shipping_items['items'] ) as $order_item ) {
				$order->add_item( $order_item );
			}

			$order_id = $order->save();

			if ( 0 === $order_id ) {
				// `wc_create_order()`は内部で`$order->save()`を呼ぶが戻り値そのものは検証しない。
				// HPOS（本プラグインが要求する構成）の`OrdersTableDataStore::persist_order_to_db()`は
				// create失敗時に必ず`\Exception`を投げるため実測上この分岐は基本的に到達しないが、
				// 将来のWC実装変更で「例外を投げず0を返す」経路に変わった場合の保険として、
				// ProductWriter/CouponWriter/TermWriterと同じ0チェックの慣習をここにも揃え、
				// 下のcatchへ合流させて孤立注文（新規作成時）の削除を確実に共通化する
				// （以前はこのチェックがtry/catchの外にあり、新規作成でここに到達すると
				// 孤立した`WC_Order`がDBに残ったまま`WriteResult(0, SKIPPED, ...)`を返していた）。
				throw new RuntimeException( 'OrderWriter: $order->save() returned 0.' );
			}
		} catch ( Throwable $exception ) {
			// `wc_create_order()`は呼び出し直後にDBへ永続化するため、ここで例外が伝播すると
			// 呼び出し元Importerはmappingsを書けない（書込成功時にしかupsertしないため）。
			// 再試行時は`existing_local_id`が依然nullのまま`wc_create_order()`が再度呼ばれ、
			// 同一ASP受注に対して重複した孤立注文を作ってしまう。新規作成だった場合はここで
			// 削除してから例外を再送出し、次回は クリーンな状態からやり直せるようにする。
			if ( null === $existing_local_id ) {
				$order->delete( true );
			}

			throw $exception;
		}

		return new WriteResult( $order_id, $operation, $warnings, ! WarningCode::indicates_unresolved_reference( $warnings ) );
	}

	/**
	 * `$order->add_item()`は呼ばず、組み立てた`WC_Order_Item_Product`だけを返す
	 * （`write()`が`remove_order_items()`より前に全ての失敗しうる組み立てを終わらせるため）。
	 *
	 * @param array<int,array<string,mixed>> $line_items
	 * @return array{items:array<int,WC_Order_Item_Product>,warnings:array<int,string>}
	 */
	private function build_line_items( array $line_items ): array {
		$items    = [];
		$warnings = [];

		foreach ( $line_items as $line_item ) {
			$built    = $this->items->build_line_item( $line_item );
			$items[]  = $built['item'];
			$warnings = array_merge( $warnings, $built['warnings'] );
		}

		return [
			'items'    => $items,
			'warnings' => $warnings,
		];
	}

	/**
	 * `build_line_items()`と同じ理由で`$order->add_item()`は呼ばない。
	 *
	 * @return array{items:array<int,WC_Order_Item>,warnings:array<int,string>}
	 */
	private function build_shipping_and_fees( CanonicalOrder $item ): array {
		$items              = [];
		$warnings           = [];
		$shipping_method_id = Value::string( $item->shipping['method_id'] ?? null );
		$mapped_method_id   = null !== $shipping_method_id ? $this->methods->mapped_shipping_method_id( $shipping_method_id ) : null;
		$mapped_title       = null !== $mapped_method_id ? $this->methods->shipping_method_title( $mapped_method_id ) : null;
		$shipping_built     = $this->items->build_shipping_item( $item->shipping, $mapped_method_id, $mapped_title );

		$items[]  = $shipping_built['item'];
		$warnings = array_merge( $warnings, $shipping_built['warnings'] );

		if ( null !== $shipping_method_id && null === $mapped_method_id ) {
			$warnings[] = WarningCode::with_detail( WarningCode::SHIPPING_METHOD_UNMAPPED, $shipping_method_id );
		}

		$fee_built = $this->items->build_fee_items( $item->payment, $item->totals );
		$items     = array_merge( $items, $fee_built['items'] );
		$warnings  = array_merge( $warnings, $fee_built['warnings'] );

		return [
			'items'    => $items,
			'warnings' => $warnings,
		];
	}

	/**
	 * `apply_totals()`は`Value::string(...) ?? '0'`で欠損のみガードし、非数値・負の値は
	 * そのまま`wc_format_decimal()`経由でWC_Orderに書き込まれてしまう（符号は検証されない）。
	 * 注文合計はASP側の値をそのまま信頼する契約（`calculate_totals()`を呼ばない）上、
	 * この値自体が壊れていると実際に決済された金額と一致しない注文になる金銭的リスクが
	 * あるため、`WC_Order`に一切触れる前に検証する。
	 *
	 * @param array<string,mixed> $totals
	 */
	private function validate_totals( array $totals ): ?string {
		// `total`のみ必須（`apply_totals()`が`Value::string(...) ?? '0'`で欠損を無警告のまま
		// 0円へフォールバックしてしまうため）。discount/shipping_fee/taxは実際の注文で
		// 正当に欠損しうる（`apply_totals()`の既存フォールバックどおり0扱いでよい）ため、
		// 存在しないこと自体はエラーにしない。
		if ( ! isset( $totals['total'] ) ) {
			return WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, 'total' );
		}

		foreach ( [ 'total', 'discount', 'shipping_fee', 'tax' ] as $key ) {
			$value = $totals[ $key ] ?? null;

			if ( null === $value ) {
				continue;
			}

			if ( ! is_numeric( $value ) || (float) $value < 0 ) {
				return WarningCode::with_detail( WarningCode::ORDER_TOTALS_INVALID, $key );
			}
		}

		return null;
	}

	/**
	 * 割引はWC_Order_Item_Couponを作らずorder-levelのdiscount_totalとして設定する。
	 * ColorMeの割引はポイント利用・GMOポイント・その他が混在しクーポンではなく、
	 * クーポン行を作るとクーポン利用回数集計が壊れるため（`extras['shop_coupon']`はメタへ退避）。
	 *
	 * @param array<string,mixed> $totals
	 */
	private function apply_totals( WC_Order $order, array $totals ): void {
		$order->set_discount_total( Value::string( $totals['discount'] ?? null ) ?? '0' );
		$order->set_discount_tax( '0' );
		$order->set_shipping_total( Value::string( $totals['shipping_fee'] ?? null ) ?? '0' );

		// `set_total_tax()`はprotectedのため、public な `set_cart_tax()`/`set_shipping_tax()` 経由で
		// 入れる。ASP側は送料・商品の税を分けて持たないケースがあるため、注文全体の税額
		// （totals.tax）を丸ごとcart_taxに寄せる（get_total_tax()の合計値が一致すればよく、
		// cart/shipping間の内訳はWoo管理画面の表示上のバケット分けでしかないため）。
		$order->set_cart_tax( Value::string( $totals['tax'] ?? null ) ?? '0' );
		$order->set_shipping_tax( '0' );

		$order->set_total( Value::string( $totals['total'] ?? null ) ?? '0' );
	}

	/**
	 * @param array<int,string> $warnings
	 */
	private function apply_currency_and_tax_settings( WC_Order $order, array &$warnings ): void {
		$currency = get_woocommerce_currency();
		$order->set_currency( $currency );

		if ( 'JPY' !== $currency ) {
			$warnings[] = WarningCode::CURRENCY_MISMATCH;
		}

		$order->set_prices_include_tax( wc_prices_include_tax() );
	}

	/**
	 * @return array<int,string>
	 */
	private function apply_status( WC_Order $order, string $canonical_status ): array {
		$warnings = [];
		$mapped   = $this->methods->order_status( $canonical_status );
		// `ltrim($status, 'wc-')` は文字クラスとして扱われ 'completed' の先頭 'c' まで
		// 削ってしまう罠があるため、リテラルプレフィックスとして厳密に判定する。
		$status = str_starts_with( $mapped, 'wc-' ) ? substr( $mapped, 3 ) : $mapped;
		$known  = wc_get_order_statuses();

		if ( ! array_key_exists( "wc-{$status}", $known ) ) {
			// 未知のステータス文字列をそのまま書き込むと、`wc_get_order_statuses()`を前提にした
			// Woo標準の管理画面フィルタ・受注処理ワークフローから見えなくなる（境界データは
			// フェイルクローズで検証する。CLAUDE.md参照）。Wooが「要確認」の意味で標準提供する
			// `on-hold`へ倒し、元の値は警告のdetailとして残す。
			$warnings[] = WarningCode::with_detail( WarningCode::ORDER_STATUS_UNKNOWN, $status );
			$status     = 'on-hold';
		}

		$order->set_status( $status );

		// D10 #6: ASP側で既に確定済みの受注に対してWoo標準の在庫増減・売上集計・ダウンロード
		// 権限付与を再度走らせない（SideEffectGuardのフィルターに加え、履歴として正しい
		// フラグを明示する）。completed/processingはASP側で既に処理済みという履歴として true
		// にする。pending/on-hold等の未処理注文にまで無条件でtrueを立てると、この注文が
		// 後に本当にprocessing/completedへ遷移した際、WooCommerce標準フック
		// （`wc_update_total_sales_counts()`/`wc_update_coupon_usage_counts()`/
		// ダウンロード権限付与）が「既に処理済み」と誤認して発火せず、集計が過少になったり
		// 購入者がダウンロード可能商品を一切受け取れなくなったりする。
		$already_finalized = in_array( $status, [ 'completed', 'processing' ], true );

		$order->set_order_stock_reduced( $already_finalized );
		$order->set_new_order_email_sent( true );
		$order->set_recorded_sales( $already_finalized );
		$order->set_recorded_coupon_usage_counts( $already_finalized );
		$order->set_download_permissions_granted( $already_finalized );

		return $warnings;
	}

	/**
	 * @return array<int,string>
	 */
	private function apply_customer( WC_Order $order, ?string $customer_ref ): array {
		if ( null === $customer_ref ) {
			$order->set_customer_id( 0 );

			return [];
		}

		$local_id = $this->mappings->find_local_id( $this->platform, 'customer', $customer_ref );

		// mappingsが指すユーザーが手動削除等で既に存在しない場合を含め、解決できないものは
		// 未解決として扱う（存在しないIDを`set_customer_id()`にそのまま渡すと、注文が
		// 「顧客に紐付いているように見えるが実体が無い」壊れた参照を持つことになる）。
		if ( null === $local_id || ! get_userdata( $local_id ) ) {
			$order->set_customer_id( 0 );

			return [ WarningCode::with_detail( WarningCode::ORDER_CUSTOMER_UNRESOLVED, $customer_ref ) ];
		}

		// ASP側顧客のメールが店舗の管理者・スタッフアカウントと偶然一致した場合、
		// `CustomerWriter::write()`はプロフィールを上書きしないままmappingだけを維持する
		// （顧客参照解決のため）。しかしこのmappingを無条件に信用してここで
		// `set_customer_id()`すると、見ず知らずのASP顧客の注文が管理者・スタッフの
		// WooCommerceアカウント（マイアカウント）に紐付いてしまう。存在しない参照と同様に
		// 未解決として扱い、注文はゲスト（customer_id=0）のまま作成する。
		if ( CustomerWriter::has_protected_role( $local_id ) ) {
			$order->set_customer_id( 0 );

			return [ WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, $customer_ref ) ];
		}

		$order->set_customer_id( $local_id );

		return [];
	}

	private function apply_addresses( WC_Order $order, CanonicalOrder $item ): void {
		// 請求先は`customer_snapshot`（注文時の値）を使う。ゲスト購入・退会済み顧客では
		// これが唯一の情報源（D10。現在の会員プロフィールを参照すると注文時の情報とずれる）。
		$snapshot     = Value::array_or_null( $item->extras['customer_snapshot'] ?? null ) ?? [];
		$billing_name = Value::string( $snapshot['name'] ?? null ) ?? '';

		$order->set_billing_address(
			AddressMapper::to_woo(
				$this->platform,
				$snapshot,
				$billing_name,
				Value::string( $snapshot['email'] ?? null ) ?? '',
				Value::string( $snapshot['phone'] ?? null ),
				Value::string( $snapshot['company'] ?? null )
			)
		);

		$shipping_name = Value::string( $item->shipping['name'] ?? null ) ?? $billing_name;

		$order->set_shipping_address(
			AddressMapper::to_woo(
				$this->platform,
				$item->shipping,
				$shipping_name,
				'',
				Value::string( $item->shipping['tel'] ?? null ),
				null
			)
		);
	}

	/**
	 * @param array<string,mixed> $payment
	 * @return array<int,string>
	 */
	private function apply_payment_method( WC_Order $order, array $payment ): array {
		$method_id   = Value::string( $payment['method_id'] ?? null );
		$method_name = Value::string( $payment['method_name'] ?? null );
		$mapped_id   = null !== $method_id ? $this->methods->mapped_payment_gateway_id( $method_id ) : null;

		if ( null !== $mapped_id ) {
			$order->set_payment_method( $mapped_id );
			$order->set_payment_method_title( $this->methods->payment_gateway_title( $mapped_id ) );

			return [];
		}

		// 未マッピング: Wooの決済ゲートウェイとして実在しないASP側の生ID/名称を
		// `payment_method`（ゲートウェイID）へ設定すると、決済連携プラグイン等の
		// ゲートウェイ判定処理が誤動作しうるため空にし、元の名称はタイトルにのみ保持する。
		$order->set_payment_method( '' );
		$order->set_payment_method_title( $method_name ?? '' );

		if ( null !== $method_id ) {
			return [ WarningCode::with_detail( WarningCode::PAYMENT_METHOD_UNMAPPED, $method_id ) ];
		}

		return [];
	}

	private function apply_dates( WC_Order $order, CanonicalOrder $item, bool $is_new_order ): void {
		$order->set_date_created( $item->placed_at );

		// `apply_status()`（この直前に呼ばれる）の`set_status()`は、ステータス遷移時に
		// `WC_Order::maybe_set_date_paid()`/`maybe_set_date_completed()`を発火させ、
		// `date_paid`/`date_completed`へ移行実行時刻（`time()`）を自動的に焼き込む
		// （WooCommerce本体の仕様）。ASP側の実際の日時（`placed_at`）で明示的に上書きしないと、
		// 過去に確定済みの注文が軒並み「移行実行日」に支払い・完了したことになってしまう。
		$paid = Value::bool( $item->extras['paid'] ?? null );

		if ( null === $paid ) {
			// `paid`キー自体が欠損/nullの場合（未対応ASP、またはColorMeの`Cast::to_bool_or_null()`が
			// 値を解釈できなかった場合）は「未払いに変わった」という明示的なシグナルではないため、
			// 既存の`date_paid`には触れない。ここで無条件にnullへ倒すと、再同期のたびに
			// 支払い済み注文の`date_paid`が消え、会計・エクスポートで未払い扱いに戻ってしまう。
			if ( $is_new_order ) {
				// ただし新規作成の場合は「既存の正しい値」がそもそも存在せず、直前の
				// `set_status()`が焼き込んだ移行実行時刻だけが残っている状態のため、
				// 保持すべき値が無い。誤った日時が残り続けないよう明示的にクリアする。
				$order->set_date_paid( null );
			}
		} else {
			// falseに反転した場合はdate_paidを消す（更新のみで削除しないと、再実行時に
			// 返金・注文取消等でASP側のpaidフラグが取り消されても、古いdate_paidが残り続け
			// WooCommerce側の会計・エクスポートで支払済みのまま扱われてしまう。discount_point等の
			// 他フィールドで既に適用している「nullで削除」と同じ方針）。
			$order->set_date_paid( $paid ? $item->placed_at : null );
		}

		// `date_completed`はCanonicalOrderに専用の完了日時フィールドが無いため、受注日時
		// （`placed_at`）を代用する。上記コメントの理由により、ステータスがcompletedでなければ
		// 明示的にクリアする（`set_status()`はcompleted→他ステータスへの遷移時に
		// `date_completed`を自動クリアしないため、放置すると古い完了日時が残り続ける）。
		$order->set_date_completed( $order->has_status( 'completed' ) ? $item->placed_at : null );
	}

	/**
	 * @param array<string,mixed> $totals
	 * @return array<int,string>
	 */
	private function totals_warnings( array $totals ): array {
		$warnings = [];

		if ( isset( $totals['residual'] ) ) {
			$warnings[] = WarningCode::with_detail( WarningCode::ORDER_TOTAL_RESIDUAL, (string) $totals['residual'] );
		}

		$tax_source = $totals['tax_source'] ?? null;

		if ( 'unavailable_for_split_order' === $tax_source ) {
			$warnings[] = WarningCode::ORDER_SPLIT_TAX_UNKNOWN;
		} elseif ( 'sale.tax_incomplete_excludes_shipping_tax' === $tax_source ) {
			// `sale.totals`が欠損しColorMe側の`sale.tax`（商品分のみ、送料分の税を含まない）へ
			// フォールバックした場合。`apply_totals()`の`totals.tax`はこの不完全な値をそのまま
			// 使うため、税額が実際より低い可能性があることをレポートで確認できるようにする。
			$warnings[] = WarningCode::ORDER_TAX_TOTAL_INCOMPLETE;
		}

		return $warnings;
	}

	/**
	 * @param array<int,string> $warnings
	 */
	private function apply_meta( WC_Order $order, CanonicalOrder $item, array $warnings ): void {
		$this->set_or_delete_meta( $order, '_cbjp_memo', $item->note );

		$this->set_or_delete_meta( $order, '_cbjp_slip_number', Value::string( $item->shipping['slip_number'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_tracking_url', Value::string( $item->shipping['tracking_url'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_preferred_date', Value::string( $item->shipping['preferred_date'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_preferred_period', Value::string( $item->shipping['preferred_period'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_noshi_text', Value::string( $item->shipping['noshi_text'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_card_name', Value::string( $item->shipping['card_name'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_card_text', Value::string( $item->shipping['card_text'] ?? null ) );
		$this->set_or_delete_meta( $order, '_cbjp_wrapping_name', Value::string( $item->shipping['wrapping_name'] ?? null ) );

		// Woo側の別フィールドとして既に反映済み、または請求先住所の構築にのみ使う一時データ
		// （customer_snapshot）を除いた残りのextrasは、`ProductWriter`/`CouponWriter`と同じ
		// 汎用機構`ExtrasMeta::apply()`へ委ねる。ASP固有キーをここで個別にホワイトリスト
		// 管理しないことで、他ASPが異なるextras構成を持つ場合のデータ欠損を防ぐ
		// （アーキテクチャ原則1: Woo層はプラットフォーム固有キーを知らなくてよい）。
		ExtrasMeta::apply( $order, $this->meta_extras( $item->extras ) );

		// 値がnullになった場合も`ExtrasMeta::apply()`経由でメタを削除する（更新のみで
		// 削除しないと、再実行時にポイント利用が取り消された等でtotalsから値が消えても
		// 古い金額のメタが残り、実際の割引内容と食い違ったまま残り続けてしまう）。
		ExtrasMeta::apply(
			$order,
			[
				'discount_point' => Value::string( $item->totals['discount_point'] ?? null ),
				'discount_gmo'   => Value::string( $item->totals['discount_gmo'] ?? null ),
				'discount_other' => Value::string( $item->totals['discount_other'] ?? null ),
			]
		);

		// `ExtrasMeta::apply()`より後に設定する: 他writer（ProductWriter/TermWriter/
		// CouponWriter）と同じ順序規約で、extras内に同名キー（'platform'等）が
		// 混入していても、この明示フィールドが常に優先されるようにする
		// （汎用extrasを先に設定する順序だと、外部アダプタ由来のextrasにたまたま
		// 同名キーがあった場合に正しい値を上書きしてしまう）。
		$order->update_meta_data( '_cbjp_platform', $this->platform );
		$order->update_meta_data( '_cbjp_remote_order_number', $item->number );
		$order->update_meta_data( '_cbjp_remote_order_id', Value::string( $item->extras['remote_id'] ?? null ) ?? $item->number );

		$order->update_meta_data( '_cbjp_import_warnings', wp_json_encode( array_values( array_unique( $warnings ) ) ) );
	}

	/**
	 * @param array<string,mixed> $extras
	 * @return array<string,mixed>
	 */
	private function meta_extras( array $extras ): array {
		// remote_idは`_cbjp_remote_order_id`として既に反映済み。customer_snapshotは
		// 請求先住所の構築にのみ使う一時データで、メタとしての永続化対象ではない。paidは
		// `apply_dates()`が`date_paid`として既に反映済みで、ここを素通りさせると
		// `ExtrasMeta::apply()`（Woo標準の`update_meta_data()`）経由でbool `false`が
		// 空文字列として書き込まれ、未設定と区別できなくなる。
		unset( $extras['remote_id'], $extras['customer_snapshot'], $extras['paid'] );

		return $extras;
	}

	private function set_or_delete_meta( WC_Order $order, string $meta_key, ?string $value ): void {
		if ( null === $value ) {
			$order->delete_meta_data( $meta_key );

			return;
		}

		$order->update_meta_data( $meta_key, $value );
	}
}
