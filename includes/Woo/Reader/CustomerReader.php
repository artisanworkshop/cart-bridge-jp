<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Reader;

use CartBridgeJP\Adapters\Cursor;
use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Woo\Writer\CustomerWriter;
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
			'role'         => 'customer',
			// `role => 'customer'`は「customerロールを持つ」の意味で、他ロールを併せ持つことを
			// 否定しない。店舗の管理者・スタッフアカウントに（WooCommerceが自動付与する等で）
			// `customer`ロールも付いている場合、それらのPII（氏名・住所）が無警告でASP側へ
			// exportされうる。importの`CustomerWriter::PROTECTED_ROLES`と同じ一覧を除外する。
			'role__not_in' => CustomerWriter::PROTECTED_ROLES,
			'orderby'      => 'ID',
			'order'        => 'ASC',
			'number'       => self::PAGE_SIZE,
			'paged'        => (int) $cursor->get( 'page', 1 ),
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
		$canonical = new CanonicalCustomer(
			$user->user_email,
			$this->name( $user ),
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
	 * ColorMeの氏名は「姓 名」の単一文字列で、`Woo\Support\AddressMapper::split_name()`が
	 * 最初のトークンを`last_name`（姓）、残りを`first_name`（名）としてWooへ保存する
	 * （`Woo\Writer\CustomerWriter::apply_extras_meta()`参照）。`first_name . ' ' . last_name`
	 * （Western順）で単純に組み直すと姓名が入れ替わって復元される（例:
	 * 「山田 太郎」→`first_name`=太郎/`last_name`=山田→組み直すと「太郎 山田」）ため、
	 * `CustomerWriter`が同時に書く`_cbjp_full_name`（元の文字列そのもの）を優先して使う。
	 * このメタが無い場合（Woo上でネイティブに作成された顧客等、ColorMe由来ではないデータ）は
	 * Western順のフォールバックにする。
	 */
	private function name( WP_User $user ): string {
		$full_name = $this->meta_string( $user->ID, '_cbjp_full_name' );

		if ( null !== $full_name ) {
			return $full_name;
		}

		$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );

		return '' !== $name ? $name : $user->display_name;
	}

	/**
	 * `CustomerWriter::apply_addresses()`が書くbilling_*メタをWooネイティブのキーのまま返す
	 * （クラスdocblock参照）。`company`は`CanonicalCustomer::$company`（コンストラクタ引数）として
	 * 別途運ぶため、ここには含めない（同じ値を2箇所に持たせない）。
	 *
	 * @return array<string,mixed>
	 */
	private function address( int $user_id ): array {
		return [
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
