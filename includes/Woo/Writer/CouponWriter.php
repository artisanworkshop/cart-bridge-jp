<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCoupon;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\Support\PlatformOwnership;
use CartBridgeJP\Woo\Support\Value;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WC_Coupon;

/**
 * `CanonicalCoupon` をWooのクーポン（`WC_Coupon`）として書き込む。
 */
final class CouponWriter implements EntityWriter {

	public function __construct( private readonly string $platform ) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalCoupon ) {
			throw new RuntimeException( 'CouponWriter received an unsupported Canonical model.' );
		}

		$group_limit_type = Value::string( $item->extras['group_limit_type'] ?? null );

		if ( null !== $group_limit_type && 'none' !== $group_limit_type ) {
			// 特定会員グループ限定のクーポンはWooに対応機能が無い。制限を無視して保存すると
			// 実質「全顧客が使える無制限クーポン」として機能してしまい金銭的リスクに直結するため
			// （ColorMeの`CouponTransformer`はこのケースを既に除外しているが、`extras`経由で
			// 直接構築されうる外部アダプタは信頼境界のため、ここでも警告だけでなく保存自体を
			// 見送るフェイルクローズにする）。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::COUPON_GROUP_LIMIT_UNSUPPORTED ] );
		}

		if ( ! in_array( $item->type, [ 'fixed', 'percent' ], true ) ) {
			// `CanonicalCoupon::$type`はdocblock上`'fixed'|'percent'`だがPHPの型としては
			// 単なるstringで実行時に強制されない。`cbjp/adapters/register`経由の外部アダプタは
			// 信頼境界（CLAUDE.md参照）のため、未知の値を`fixed_cart`へ黙って倒す（deny-list）
			// と、想定外のtype文字列がそのまま実在の値引きクーポンとして公開されてしまう
			// 金銭的リスクがある。既知の2値のみを許可するallow-listにし、それ以外は保存を
			// 見送りフェイルクローズする。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::COUPON_TYPE_UNKNOWN, $item->type ) ] );
		}

		$warnings = [];
		$coupon   = null !== $existing_local_id ? new WC_Coupon( $existing_local_id ) : new WC_Coupon();

		if ( 0 === $coupon->get_id() ) {
			// クーポンコードはWooCommerce内で一意である必要があるため（他商品と違いSKUのような
			// 「奪わない」選択肢が無い）、既存の同コードクーポンがあれば再利用する。
			// ただし、`_cbjp_platform`が自分自身と一致する場合のみ再利用する
			// （VariationWriterの他プラットフォーム保護と同じ理由）。一致しない場合は
			// 店舗独自クーポン・別プラットフォーム由来のクーポンを誤って上書きしてしまう
			// リスクがあり、かつコード重複のまま新規作成するとWoo側でどちらが適用されるか
			// 不定になる別の金銭的リスクを生むため、保存自体を見送る。
			$conflict_id = wc_get_coupon_id_by_code( $item->code );

			if ( 0 !== $conflict_id ) {
				if ( PlatformOwnership::owns_post( $conflict_id, $this->platform ) ) {
					$coupon     = new WC_Coupon( $conflict_id );
					$warnings[] = WarningCode::with_detail( WarningCode::COUPON_REUSED_EXISTING, (string) $conflict_id );
				} else {
					return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::COUPON_CODE_CONFLICT, (string) $conflict_id ) ] );
				}
			}
		} elseif ( $coupon->get_code() !== $item->code ) {
			// 上のブロックは新規作成（またはmapping先が既に存在しない）のときしか走らない。
			// 既存の有効なクーポンのコードがASP側でリネームされた場合も、コードの一意性が
			// 崩れると決済時にどちらが適用されるか不定になる同じ金銭的リスクがあるため
			// 衝突チェックが必要。ただし更新パスでは新規作成時と異なり他クーポンへの
			// 「乗り換え」は行わず、衝突があれば保存自体を見送り元のクーポンをそのまま残す。
			$conflict_id = wc_get_coupon_id_by_code( $item->code, $coupon->get_id() );

			if ( 0 !== $conflict_id ) {
				// `Importer`はlocal_id!==0であればoperationに関わらずchecksumをmappingsへ
				// upsertする。ここで既存クーポンのIDをそのまま返すと、リネームが実際には
				// 適用されなかったにも関わらず新しいitemのchecksumがキャッシュされ、
				// 次回以降はchecksum一致でこの衝突チェック自体がスキップされてしまい、
				// 衝突先が削除される等で解消された後も永久にリネームが再試行されなくなる。
				// local_id 0を返してupsert自体を発生させず、既存の有効なmapping（旧コードの
				// クーポンを指す）を変更せずに残し、次回実行時に再試行できるようにする。
				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, [ WarningCode::with_detail( WarningCode::COUPON_CODE_CONFLICT, (string) $conflict_id ) ] );
			}
		}

		$operation = 0 === $coupon->get_id() ? WriteResult::OPERATION_CREATED : WriteResult::OPERATION_UPDATED;

		$coupon->set_code( $item->code );
		// Wooのdiscount_typeスラッグは fixed_cart/fixed_product/percent（Canonicalの'fixed'は
		// そのままでは不正値になるため fixed_cart に対応付ける。カート全体への定額割引が
		// ColorMeの割引クーポンの意味に最も近い）。
		$coupon->set_discount_type( 'percent' === $item->type ? 'percent' : 'fixed_cart' );
		$coupon->set_amount( $item->amount );
		$coupon->set_minimum_amount( $item->min_amount ?? '' );
		$coupon->set_date_expires( $item->expires_at );
		$coupon->set_usage_limit( $item->usage_limit );
		$coupon->set_usage_limit_per_user( $item->usage_limit_per_user );
		$coupon->set_free_shipping( $item->free_shipping );
		$coupon->set_description( Value::string( $item->extras['name'] ?? null ) ?? '' );

		ExtrasMeta::apply( $coupon, $this->meta_extras( $item->extras ) );
		$coupon->update_meta_data( '_cbjp_platform', $this->platform );
		$coupon->update_meta_data( '_cbjp_remote_id', $item->remote_id() ?? '' );

		$coupon_id = $coupon->save();

		return new WriteResult( $coupon_id, $operation, $warnings );
	}

	/**
	 * @param array<string,mixed> $extras
	 * @return array<string,mixed>
	 */
	private function meta_extras( array $extras ): array {
		unset( $extras['remote_id'], $extras['name'] );

		return $extras;
	}
}
