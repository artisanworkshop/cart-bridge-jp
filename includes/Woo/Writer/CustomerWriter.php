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
		$resolved = $this->resolve_target( $item, $existing_local_id );

		if ( null !== $resolved->reuse_warning ) {
			$warnings[] = $resolved->reuse_warning;
		}

		$user_id = $resolved->user_id;

		if ( $resolved->is_new ) {
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
				// `validate()`では実際に作成を試みないため判定できない（dry-run除外・
				// `Woo\WarningCode`のdocblock参照）。
				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, array_merge( $warnings, [ WarningCode::with_detail( WarningCode::CUSTOMER_CREATE_FAILED, $created->get_error_code() ) ] ) );
			}

			$user_id = $created;
		}

		if ( null === $user_id ) {
			// 新規作成フラグがfalseの経路では resolve_target の契約上ユーザーIDは必ず非nullだが、
			// PHPの型システムはこの相関を表現できないため防御的に確認する。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		if ( self::has_protected_role( $user_id ) ) {
			// email突合で見つかった既存アカウント（または過去のインポートで紐付いた
			// アカウントが後から昇格したケース）が管理者・スタッフ権限を持つ場合、
			// プロフィール（氏名・住所・extras）を一切上書きしない。受注等からの
			// 顧客参照解決に必要なmappingの記録（呼び出し元Importerがlocal_idを見て行う）
			// のみは維持する。detailはOrderWriter側の同名警告・F1-6の結果レポート契約
			// （ASP側remote_idで問題箇所を特定する）と揃え、Woo内部のuser IDではなく
			// ASP側remote_idにする。
			$warnings[] = WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, $item->remote_id() ?? '' );

			return new WriteResult( $user_id, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		if ( WriteResult::OPERATION_UPDATED === $resolved->operation ) {
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

				// `wp_update_user()`はメール重複等のエラー時、渡した全フィールド（氏名・
				// display_name含む）を一切適用しない。それにも関わらずここから先を続行すると、
				// billing/shipping住所・extrasだけが新しい値に更新され、コアプロフィール
				// （氏名・メール）だけが古いまま残る内部不整合な部分更新になってしまう。
				// `local_id=0`＋`OPERATION_SKIPPED`を返すと`Importer`はmappingsのchecksumを
				// 更新しない（`WriteResult::$local_id === 0`の契約）ため、衝突が解消されるまで
				// 次回実行時も同じアイテムとして再試行される（他の同種の失敗パターンと同じ
				// フェイルクローズ方針）。
				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
			}
		}

		$this->apply_addresses( $user_id, $billing );
		$this->apply_extras_meta( $user_id, $item );

		if ( AddressMapper::is_overseas( $this->platform, $item->address ) ) {
			$warnings[] = WarningCode::ADDRESS_OVERSEAS;
		}

		update_user_meta( $user_id, '_cbjp_platform', $this->platform );
		update_user_meta( $user_id, '_cbjp_remote_id', $item->remote_id() ?? '' );

		return new WriteResult( $user_id, $resolved->operation, $warnings );
	}

	public function validate( CanonicalModel $item, ?int $existing_local_id ): ValidationResult {
		if ( ! $item instanceof CanonicalCustomer ) {
			throw new RuntimeException( 'CustomerWriter received an unsupported Canonical model.' );
		}

		$resolved = $this->resolve_target( $item, $existing_local_id );
		$warnings = [];

		if ( null !== $resolved->reuse_warning ) {
			$warnings[] = $resolved->reuse_warning;
		}

		if ( ! $resolved->is_new ) {
			// 新規作成フラグがfalseの経路では resolve_target の契約上ユーザーIDは必ず非null。
			// 保護ロール判定は新規作成パスには存在しない（新規WPユーザーが最初から
			// administrator等になることはない）ため、write()と同じくこの分岐内でのみ行う。
			$user_id = $resolved->user_id ?? 0;

			if ( self::has_protected_role( $user_id ) ) {
				$warnings[] = WarningCode::with_detail( WarningCode::CUSTOMER_ACCOUNT_PROTECTED, $item->remote_id() ?? '' );

				return new ValidationResult( WriteResult::OPERATION_SKIPPED, $warnings );
			}

			// `wp_update_user()`→`wp_insert_user()`のメール一意性チェック（wp-includes/user.php）を
			// 読取専用で再現する: 新メールが現在の値と（大文字小文字を区別せず）異なり、かつ
			// 他の誰かが既にそのメールを使っている場合にのみ`existing_user_email`エラーになる
			// （`email_exists()`はIDを除外しないため、まずメール自体の変更有無を先に見る必要がある）。
			// wp evalでの実測により、この判定はwrite()の`wp_update_user()`呼び出し結果と一致することを確認済み。
			$current_user  = get_userdata( $user_id );
			$current_email = $current_user instanceof WP_User ? $current_user->user_email : '';

			if ( 0 !== strcasecmp( $item->email, $current_email ) && false !== email_exists( $item->email ) ) {
				$warnings[] = WarningCode::with_detail( WarningCode::CUSTOMER_EMAIL_CONFLICT, $item->email );

				return new ValidationResult( WriteResult::OPERATION_SKIPPED, $warnings );
			}
		}

		// `AddressMapper::is_overseas()`はDB読取・永続化を伴わない純粋な判定で、write()は
		// 新規作成/更新どちらのパスでも（保護ロールでない場合）警告を積む。ここも同じ位置で
		// 呼ぶ: write()側でだけ判定すると、実際には移行後に付く警告がdry-runのCSVレポート
		// から欠落する。
		if ( AddressMapper::is_overseas( $this->platform, $item->address ) ) {
			$warnings[] = WarningCode::ADDRESS_OVERSEAS;
		}

		if ( $resolved->is_new ) {
			// `CUSTOMER_CREATE_FAILED`は実際に`wc_create_new_customer()`を呼ばないと判定できない
			// （パスワードポリシー系プラグインの拒否等）ため、dry-runでは出ない。
			return new ValidationResult( WriteResult::OPERATION_CREATED, $warnings );
		}

		return new ValidationResult( $resolved->operation, $warnings );
	}

	/**
	 * `OrderWriter::apply_customer()`が、mappings経由で解決した顧客参照先が管理者・スタッフ
	 * アカウントでないか再検証するために公開する（`write()`内の同種チェックと同じ判定）。
	 */
	public static function has_protected_role( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return [] !== array_intersect( $user->roles, self::PROTECTED_ROLES );
	}

	/**
	 * mappingsが指すユーザーが手動削除等で既に存在しない場合、`$existing_local_id`を信用せず
	 * 新規作成へフォールバックする（TermWriterの同種のstale-ID対応と同じ方針）。信用したまま
	 * 進むと、`has_protected_role()`は存在しないIDに対してfalseを返して保護をすり抜け、
	 * `wp_update_user()`の失敗（WP_Error）も無視されたまま「更新成功」として扱われ、
	 * 存在しないユーザーIDに孤立したusermetaだけが書き込まれてしまう。
	 *
	 * email突合（`get_user_by('email')`）はDB読取のみで完結するため、`write()`/`validate()`の
	 * 両方が同じ判定を共有できる。実際のユーザー作成（`wc_create_new_customer()`）は
	 * 永続化を伴うため`write()`側にのみ残す。
	 */
	private function resolve_target( CanonicalCustomer $item, ?int $existing_local_id ): CustomerResolution {
		if ( null !== $existing_local_id && ! get_userdata( $existing_local_id ) ) {
			$existing_local_id = null;
		}

		if ( null !== $existing_local_id ) {
			return new CustomerResolution( $existing_local_id, WriteResult::OPERATION_UPDATED, false, null );
		}

		$existing_user = get_user_by( 'email', $item->email );

		if ( $existing_user instanceof WP_User ) {
			return new CustomerResolution(
				$existing_user->ID,
				WriteResult::OPERATION_UPDATED,
				false,
				WarningCode::with_detail( WarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_user->ID )
			);
		}

		return new CustomerResolution( null, WriteResult::OPERATION_CREATED, true, null );
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
				// bool→'1'/'0'変換・null→削除は`ExtrasMeta::apply_via()`が既に汎用的に行うため、
				// ここで個別変換する必要は無い。
				'mailmag_opt_in' => $item->mailmag_opt_in,
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
