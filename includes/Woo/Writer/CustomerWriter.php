<?php
/**
 * @package CartBridgeJP
 */

declare( strict_types=1 );

namespace CartBridgeJP\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalCustomer;
use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\AddressMapper;
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\WarningCode;
use RuntimeException;
use WP_Error;
use WP_User;

/**
 * `CanonicalCustomer` をWPユーザー（`customer`ロール）として書き込む。email突合で既存ユーザーが
 * 見つかった場合は再利用する（ユーザー方針: 顧客のみ積極的な既存データ突合を行う）。
 */
final class CustomerWriter implements EntityWriter {

	/**
	 * これらのロールを1つでも持つ既存WPユーザーはプロフィール上書きの対象外とする
	 * （店舗の管理者・スタッフアカウントとASP側顧客のメールアドレスが偶然一致した場合に、
	 * 業務アカウントの氏名・住所を破壊しないため）。
	 *
	 * @var array<int,string>
	 */
	private const PROTECTED_ROLES = [ 'administrator', 'shop_manager', 'editor', 'author', 'contributor' ];

	public function __construct( private readonly string $platform ) {}

	public function write( CanonicalModel $item, ?int $existing_local_id ): WriteResult {
		if ( ! $item instanceof CanonicalCustomer ) {
			throw new RuntimeException( 'CustomerWriter received an unsupported Canonical model.' );
		}

		$warnings = [];
		$billing  = AddressMapper::to_woo( $this->platform, $item->address, $item->name, $item->email, $item->phone, $item->company );

		// mappingsが指すユーザーが手動削除等で既に存在しない場合、existing_local_idを
		// 信用せず新規作成へフォールバックする（TermWriterの同種のstale-ID対応と同じ方針）。
		// 信用したまま進むと、has_protected_role()は存在しないIDに対してfalseを返して
		// 保護をすり抜け、wp_update_user()の失敗（WP_Error）も無視されたまま「更新成功」
		// として扱われ、存在しないユーザーIDに孤立したusermetaだけが書き込まれてしまう。
		if ( null !== $existing_local_id && ! get_userdata( $existing_local_id ) ) {
			$existing_local_id = null;
		}

		[ $user_id, $operation, $reuse_warning ] = null !== $existing_local_id
			? [ $existing_local_id, WriteResult::OPERATION_UPDATED, null ]
			: $this->find_or_create( $item, $billing );

		if ( null !== $reuse_warning ) {
			$warnings[] = $reuse_warning;
		}

		if ( null === $user_id ) {
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		if ( self::has_protected_role( $user_id ) ) {
			// email突合で見つかった既存アカウント（または過去のインポートで紐付いた
			// アカウントが後から昇格したケース）が管理者・スタッフ権限を持つ場合、
			// プロフィール（氏名・住所・extras）を一切上書きしない。受注等からの
			// 顧客参照解決に必要なmappingの記録（呼び出し元Importerがlocal_idを見て行う）
			// のみは維持する。
			$warnings[] = WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, (string) $user_id );

			return new WriteResult( $user_id, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		if ( WriteResult::OPERATION_UPDATED === $operation ) {
			// 既存ユーザーのロールは変更しない（管理者と同じメールアドレスの場合に権限を壊さないため）。
			// user_emailも同期する: mappings経由の再利用（existing_local_id指定）では、ASP側で
			// 前回インポート後にメールアドレスが変更されている可能性があり、同期しないとWPアカウント
			// のログイン用メールが古いまま残り続ける（email突合で解決した場合は既に一致しているため
			// 実質no-op）。他ユーザーが既に使用中のメールと衝突した場合、`wp_update_user()`は
			// メール重複チェックをDB書込前に行うため氏名・display_name含む更新全体が失敗する
			// （メールだけでなくこの呼び出しに渡した全フィールドが未反映のまま）。無警告で
			// 握りつぶすと古い氏名等が残り続ける理由が分からなくなるため警告を積んで可視化する。
			$update_result = wp_update_user(
				[
					'ID'           => $user_id,
					'user_email'   => $item->email,
					'first_name'   => $billing['first_name'],
					'last_name'    => $billing['last_name'],
					'display_name' => $item->name,
				]
			);

			if ( $update_result instanceof WP_Error ) {
				$warnings[] = WarningCode::with_detail( WarningCode::CUSTOMER_EMAIL_CONFLICT, $item->email );
			}
		}

		$this->apply_addresses( $user_id, $billing );
		$this->apply_extras_meta( $user_id, $item );

		if ( AddressMapper::is_overseas( $this->platform, $item->address ) ) {
			$warnings[] = WarningCode::ADDRESS_OVERSEAS;
		}

		update_user_meta( $user_id, '_cbjp_platform', $this->platform );
		update_user_meta( $user_id, '_cbjp_remote_id', $item->remote_id() ?? '' );

		return new WriteResult( $user_id, $operation, $warnings );
	}

	private static function has_protected_role( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return [] !== array_intersect( $user->roles, self::PROTECTED_ROLES );
	}

	/**
	 * @param array<string,string> $billing
	 * @return array{0:?int,1:string,2:?string}
	 */
	private function find_or_create( CanonicalCustomer $item, array $billing ): array {
		$existing_user = get_user_by( 'email', $item->email );

		if ( $existing_user instanceof WP_User ) {
			return [ $existing_user->ID, WriteResult::OPERATION_UPDATED, WarningCode::with_detail( WarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_user->ID ) ];
		}

		$created = wc_create_new_customer(
			$item->email,
			'',
			wp_generate_password( 24, true, true ),
			[
				'first_name'   => $billing['first_name'],
				'last_name'    => $billing['last_name'],
				'display_name' => $item->name,
			]
		);

		if ( $created instanceof WP_Error ) {
			// パスワードポリシー系プラグインの拒否・DB制約違反等で作成に失敗。無警告で
			// 握りつぶすと結果レポートから欠落理由が分からなくなるため警告を積む。
			return [ null, WriteResult::OPERATION_SKIPPED, WarningCode::with_detail( WarningCode::CUSTOMER_CREATE_FAILED, $created->get_error_code() ) ];
		}

		return [ $created, WriteResult::OPERATION_CREATED, null ];
	}

	/**
	 * @param array<string,string> $billing
	 */
	private function apply_addresses( int $user_id, array $billing ): void {
		foreach ( $billing as $key => $value ) {
			update_user_meta( $user_id, "billing_{$key}", $value );
		}

		// WCの配送先メタに`shipping_email`は存在しないため除外するが、`shipping_phone`は
		// 実在する項目（配送ラベル・チェックアウト自動入力等が参照する）のため、
		// 請求先と同じ電話番号をそのまま反映する。
		unset( $billing['email'] );

		foreach ( $billing as $key => $value ) {
			update_user_meta( $user_id, "shipping_{$key}", $value );
		}
	}

	private function apply_extras_meta( int $user_id, CanonicalCustomer $item ): void {
		$extras = $item->extras;
		unset( $extras['remote_id'] );

		$extras = array_merge(
			$extras,
			[
				'kana'           => $item->kana,
				'department'     => $item->department,
				'birthday'       => $item->birthday,
				'mailmag_opt_in' => null === $item->mailmag_opt_in ? null : ( $item->mailmag_opt_in ? '1' : '0' ),
				'note'           => $item->note,
				'full_name'      => $item->name,
			]
		);

		ExtrasMeta::apply_via(
			static fn ( string $meta_key, mixed $value ) => update_user_meta( $user_id, $meta_key, $value ),
			static fn ( string $meta_key ) => delete_user_meta( $user_id, $meta_key ),
			$extras
		);
	}
}
