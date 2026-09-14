<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalCustomer;
use WP_User;
use WP_User_Query;

/**
 * `WP_User`（`customer`ロール）を `CanonicalCustomer` へ変換する（`Woo\Writer\CustomerWriter` の
 * 読出側対称形）。住所はColorMeの`pref_id`スキーム（`Woo\Support\AddressMapper`がインポート方向
 * 専用に解釈する）へ変換せず、Wooネイティブのbilling_*キーのまま運ぶ。ASP固有スキームへの変換は
 * E2-3の`push_customer()`（ColorMeアダプタ）の責務にする（アーキテクチャ原則1。
 * `docs/03-design-decisions.md` §10.2「受注（D19の申し送り）」と同じ判断を住所にも適用）。
 */
final class CustomerReader implements EntityReader {

	private const PAGE_SIZE = 20;

	public function query( Cursor $cursor, ?array $only_local_ids ): ReadPage {
		$args = [
			'role'    => 'customer',
			'orderby' => 'ID',
			'order'   => 'ASC',
			'number'  => self::PAGE_SIZE,
			'paged'   => (int) $cursor->get( 'page', 1 ),
		];

		if ( null !== $only_local_ids ) {
			if ( [] === $only_local_ids ) {
				return new ReadPage( [], null, 0 );
			}

			$args['include']     = $only_local_ids;
			$args['count_total'] = false;
			unset( $args['paged'] );
			$args['number'] = count( $only_local_ids );
		} else {
			$args['count_total'] = true;
		}

		$query = new WP_User_Query( $args );
		$items = array_values( array_map( fn ( WP_User $user ): ReadItem => $this->to_read_item( $user ), $query->get_results() ) );

		$page          = (int) ( $args['paged'] ?? 1 );
		$total_pages   = null !== $only_local_ids ? 1 : (int) ceil( $query->get_total() / self::PAGE_SIZE );
		$has_next_page = null === $only_local_ids && $page < $total_pages;

		return new ReadPage( $items, $has_next_page ? new Cursor( [ 'page' => $page + 1 ] ) : null, null !== $only_local_ids ? null : (int) $query->get_total() );
	}

	private function to_read_item( WP_User $user ): ReadItem {
		$name = trim( trim( (string) $user->first_name . ' ' . (string) $user->last_name ) );

		if ( '' === $name ) {
			$name = $user->display_name;
		}

		$canonical = new CanonicalCustomer(
			$user->user_email,
			$name,
			$this->meta_string( $user->ID, '_cbjp_kana' ),
			$this->meta_string( $user->ID, 'billing_company' ),
			$this->meta_string( $user->ID, '_cbjp_department' ),
			$this->address( $user->ID ),
			$this->meta_string( $user->ID, 'billing_phone' ),
			$this->meta_string( $user->ID, '_cbjp_birthday' ),
			$this->meta_bool( $user->ID, '_cbjp_mailmag_opt_in' ),
			$this->meta_string( $user->ID, '_cbjp_note' ),
			[]
		);

		return new ReadItem( $user->ID, $canonical );
	}

	/**
	 * `CustomerWriter::apply_addresses()`が書くbilling_*メタをWooネイティブのキーのまま返す
	 * （クラスdocblock参照）。
	 *
	 * @return array<string,mixed>
	 */
	private function address( int $user_id ): array {
		return [
			'company'   => $this->meta_string( $user_id, 'billing_company' ),
			'address_1' => $this->meta_string( $user_id, 'billing_address_1' ),
			'address_2' => $this->meta_string( $user_id, 'billing_address_2' ),
			'city'      => $this->meta_string( $user_id, 'billing_city' ),
			'state'     => $this->meta_string( $user_id, 'billing_state' ),
			'postcode'  => $this->meta_string( $user_id, 'billing_postcode' ),
			'country'   => $this->meta_string( $user_id, 'billing_country' ),
		];
	}

	private function meta_string( int $user_id, string $meta_key ): ?string {
		$value = get_user_meta( $user_id, $meta_key, true );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * `ExtrasMeta::apply_via()`がbool値を`'1'`/`'0'`文字列として保存する規約
	 * （`Woo\Writer\CustomerWriter::apply_extras_meta()`参照）の読み戻し。未設定/それ以外の値は
	 * 「不明」としてnullへ倒す（`(bool)`キャストは使わない。CLAUDE.md: `(bool) '0'`が`false`になる
	 * 一方`(bool) 'false'`は`true`になる罠を避ける）。
	 */
	private function meta_bool( int $user_id, string $meta_key ): ?bool {
		$value = get_user_meta( $user_id, $meta_key, true );

		return match ( $value ) {
			'1' => true,
			'0' => false,
			default => null,
		};
	}
}
