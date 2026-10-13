<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Woo\Writer;

use CartBridgeJP\Canonical\CanonicalModel;
use CartBridgeJP\Pro\Canonical\CanonicalCustomer;
use CartBridgeJP\Pro\Woo\CommerceWarningCode;
use CartBridgeJP\Pro\Woo\Support\AddressMapper;
use CartBridgeJP\Sync\WriteResult;
use CartBridgeJP\Woo\Support\ExtrasMeta;
use CartBridgeJP\Woo\WarningCode;
use CartBridgeJP\Woo\Writer\EntityWriter;
use CartBridgeJP\Woo\Writer\ValidationResult;
use RuntimeException;
use WP_Error;
use WP_User;

/**
 * `CanonicalCustomer` をWPユーザー（`customer`ロール）として書き込む。email突合で既存ユーザーが
 * 見つかった場合は再利用する（ユーザー方針: 顧客のみ積極的な既存データ突合を行う）。
 *
 * プロフィール（氏名・住所・extras・ログイン用のメール）を書くのは、取込みがこの顧客のために作ったアカウントだけ
 * （{@see self::owns_profile()}。R3-5b、2026-10-13 ユーザー決定）。メールの一致・mapping で結んだそれ以外のアカウントは
 * 紐づけ（mapping と `_cbjp_platform`・`_cbjp_remote_id`）だけにする: ASP の会員登録がメールの持ち主を確かめないと、
 * 他人のメールで登録した ASP の顧客が既存のアカウントの住所を書き換え（チェックアウトの初期値になる）、ASP 側でメールを
 * 変えて以後の取込みでログイン用のメールまで変えさせ、パスワードの再設定でアカウントを乗っ取れるため。
 */
final class CustomerWriter implements EntityWriter {

	/**
	 * 顧客として書いてよいアカウントのロール（このサイトのロールがこれだけ。{@see self::has_protected_role()}）。
	 *
	 * @var array<int,string>
	 */
	public const CUSTOMER_ROLES = [ 'customer', 'subscriber' ];

	/**
	 * どれか 1 つでも持てば保護する能力（店舗のスタッフ。WooCommerce 自身は `edit_posts`・`manage_woocommerce`・
	 * `view_admin_dashboard` でスタッフを見分ける）。ロールにも、ユーザーに直接付いた能力にも効く。
	 *
	 * @var array<int,string>
	 */
	public const STAFF_CAPABILITIES = [
		'manage_options',
		'manage_woocommerce',
		'edit_posts',
		'view_admin_dashboard',
		'edit_shop_orders',
		'list_users',
		'edit_users',
	];

	/**
	 * このユーザーを新規作成したプラットフォームIDを保持するユーザーメタ。email突合で採用した既存
	 * アカウントには付けない（別プラットフォームが作成したアカウントを採用した場合は、そのプラットフォームの
	 * 値が残る）。「このプラグインが作ったアカウントか」の判定に使う不変の印（`_cbjp_platform` は email 突合で
	 * 書き換わる可変の印。`Woo\Support\EntityOrigin`）。
	 */
	public const CREATED_BY_IMPORT_META = '_cbjp_created_by_import';

	/**
	 * 取込みがこのアカウントを作ったときの ASP の顧客 ID（R3-5b）。作成時にだけ書く不変の印。`_cbjp_remote_id` は紐づけのたびに
	 * 書き換わるので、「この顧客のために作ったアカウントか」（{@see self::owns_profile()}）の判定には使えない。
	 */
	public const CREATED_REMOTE_ID_META = '_cbjp_created_remote_id';

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
				return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, array_merge( $warnings, [ WarningCode::with_detail( CommerceWarningCode::CUSTOMER_CREATE_FAILED, $created->get_error_code() ) ] ) );
			}

			$user_id = $created;

			// 作成の印は最初に書く（この後の書込みが失敗しても、次の取込みがこのアカウントを「この顧客のために作った」と判定できる）。
			update_user_meta( $user_id, self::CREATED_BY_IMPORT_META, $this->platform );
			update_user_meta( $user_id, self::CREATED_REMOTE_ID_META, $item->remote_id() ?? '' );
		}

		if ( null === $user_id ) {
			// 新規作成フラグがfalseの経路では resolve_target の契約上ユーザーIDは必ず非nullだが、
			// PHPの型システムはこの相関を表現できないため防御的に確認する。
			return new WriteResult( 0, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		// 判定の順は 保護 → 紐づけだけ → 作ったアカウントの更新（`validate()` も同じ）。
		if ( self::has_protected_role( $user_id ) ) {
			// email突合で見つかった既存アカウント（または過去のインポートで紐付いた
			// アカウントが後から昇格したケース）が管理者・スタッフ権限を持つ場合、
			// プロフィール（氏名・住所・extras）を一切上書きせず、紐づけの印も書かない。受注等からの
			// 顧客参照解決に必要なmappingの記録（呼び出し元Importerがlocal_idを見て行う）
			// のみは維持する。detailはOrderWriter側の同名警告・F1-6の結果レポート契約
			// （ASP側remote_idで問題箇所を特定する）と揃え、Woo内部のuser IDではなく
			// ASP側remote_idにする。
			$warnings[] = WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, $item->remote_id() ?? '' );

			return new WriteResult( $user_id, WriteResult::OPERATION_SKIPPED, $warnings );
		}

		if ( ! $resolved->is_new && ! $this->owns_profile( $user_id, $item ) ) {
			return $this->link_only( $user_id, $item, $warnings );
		}

		if ( WriteResult::OPERATION_UPDATED === $resolved->operation ) {
			// 既存ユーザーのロールは変更しない。user_emailも同期する: ASP側で前回インポート後にメールアドレスが
			// 変更されている可能性があり、同期しないとWPアカウントのログイン用メールが古いまま残り続ける。
			// ここに来るのは取込みがこの顧客のために作ったアカウントだけ（`owns_profile()`）。形の正しくないメールは
			// 同期しない（`wp_update_user()` は `sanitize_email()` で空にして保存しうる）。他ユーザーが既に使用中のメールと
			// 衝突した場合、`wp_update_user()`はメール重複チェックをDB書込前に行うため氏名・display_name含む更新全体が失敗する
			// （メールだけでなくこの呼び出しに渡した全フィールドが未反映のまま）。無警告で
			// 握りつぶすと古い氏名等が残り続ける理由が分からなくなるため警告を積んで可視化する。
			$profile = [
				'ID'           => $user_id,
				'first_name'   => $billing['first_name'],
				'last_name'    => $billing['last_name'],
				'display_name' => $item->name,
			];

			if ( is_email( $item->email ) ) {
				$profile['user_email'] = $item->email;
			}

			$update_result = wp_update_user( $profile );

			if ( $update_result instanceof WP_Error ) {
				// detailはメールアドレス自体（PII）ではなくASP側remote_idにする。dry-runの
				// 結果は`cbjp_dry_run_items`に最大30日間永続化されCSVにも出力されるため
				// （`CUSTOMER_ACCOUNT_PROTECTED`と同じ方針）。
				$warnings[] = WarningCode::with_detail( CommerceWarningCode::CUSTOMER_EMAIL_CONFLICT, $item->remote_id() ?? '' );

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
			$warnings[] = CommerceWarningCode::ADDRESS_OVERSEAS;
		}

		$this->write_link_markers( $user_id, $item );

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
			// administrator等になることはない）ため、write()と同じくこの分岐内でのみ行う。判定の順も write() と同じ。
			$user_id = $resolved->user_id ?? 0;

			if ( self::has_protected_role( $user_id ) ) {
				$warnings[] = WarningCode::with_detail( CommerceWarningCode::CUSTOMER_ACCOUNT_PROTECTED, $item->remote_id() ?? '' );

				return new ValidationResult( WriteResult::OPERATION_SKIPPED, $warnings );
			}

			// 紐づけだけのアカウントはメールを書かないので、メールの衝突も判定しない（dry-run と本番をそろえる）。
			if ( ! $this->owns_profile( $user_id, $item ) ) {
				return new ValidationResult( WriteResult::OPERATION_SKIPPED, $this->with_reuse_warning( $warnings, $user_id ) );
			}

			// `wp_update_user()`→`wp_insert_user()`のメール一意性チェック（wp-includes/user.php）を
			// 読取専用で再現する: 新メールが現在の値と（大文字小文字を区別せず）異なり、かつ
			// 他の誰かが既にそのメールを使っている場合にのみ`existing_user_email`エラーになる
			// （`email_exists()`はIDを除外しないため、まずメール自体の変更有無を先に見る必要がある）。
			// wp evalでの実測により、この判定はwrite()の`wp_update_user()`呼び出し結果と一致することを確認済み。
			// 形の正しくないメールは write() が同期しないが、そのメールを持つユーザーはいないので、ここでも衝突にならない。
			$current_user  = get_userdata( $user_id );
			$current_email = $current_user instanceof WP_User ? $current_user->user_email : '';

			if ( 0 !== strcasecmp( $item->email, $current_email ) && false !== email_exists( $item->email ) ) {
				// write()と同じくdetailはメールアドレス自体ではなくASP側remote_idにする
				// （PII保護。上のwrite()側コメント参照）。
				$warnings[] = WarningCode::with_detail( CommerceWarningCode::CUSTOMER_EMAIL_CONFLICT, $item->remote_id() ?? '' );

				return new ValidationResult( WriteResult::OPERATION_SKIPPED, $warnings );
			}
		}

		// `AddressMapper::is_overseas()`はDB読取・永続化を伴わない純粋な判定で、write()は
		// 新規作成/更新どちらのパスでも（保護ロール・紐づけだけでない場合）警告を積む。ここも同じ位置で
		// 呼ぶ: write()側でだけ判定すると、実際には移行後に付く警告がdry-runのCSVレポート
		// から欠落する。
		if ( AddressMapper::is_overseas( $this->platform, $item->address ) ) {
			$warnings[] = CommerceWarningCode::ADDRESS_OVERSEAS;
		}

		if ( $resolved->is_new ) {
			// `CUSTOMER_CREATE_FAILED`は実際に`wc_create_new_customer()`を呼ばないと判定できない
			// （パスワードポリシー系プラグインの拒否等）ため、dry-runでは出ない。
			return new ValidationResult( WriteResult::OPERATION_CREATED, $warnings );
		}

		return new ValidationResult( $resolved->operation, $warnings );
	}

	/**
	 * 顧客として書いてはいけないアカウントか（R3-5b で許可リストにした）。次のどれかなら保護する（フェイルクローズ）:
	 * このサイトのロールが無い（マルチサイトの別のサイトだけのユーザーを含む）、{@see self::CUSTOMER_ROLES} 以外のロールを持つ、
	 * 特権管理者、{@see self::STAFF_CAPABILITIES} のどれかを持つ（ロール・ユーザーに直接付いた能力のどちらでも）。
	 * 存在しないユーザーは false（呼び出し側が存在を確かめる。`resolve_target()`）。
	 *
	 * 名前どおり保護の判定で、`OrderWriter::apply_customer()`（受注をスタッフのアカウントに結ばない）・`CustomerType`（D25）・
	 * `CustomerReader`（エクスポートしない。クエリは同じ条件）も使う。フォーラムなどのロールを併せ持つ顧客も保護になる
	 * （受注はゲスト、エクスポートの対象外）。
	 */
	public static function has_protected_role( int $user_id ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		$roles = array_values( array_filter( (array) $user->roles, 'is_string' ) );

		if ( [] === $roles || [] !== array_diff( $roles, self::CUSTOMER_ROLES ) ) {
			return true;
		}

		if ( is_multisite() && is_super_admin( $user_id ) ) {
			return true;
		}

		foreach ( self::STAFF_CAPABILITIES as $capability ) {
			if ( user_can( $user, $capability ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 取込みがこの ASP の顧客のために作ったアカウントか（プロフィールを書いてよいか。R3-5b）。作成の印
	 * （{@see self::CREATED_BY_IMPORT_META}）がこのプラットフォームで、作成時の顧客 ID（{@see self::CREATED_REMOTE_ID_META}）が
	 * この顧客のときだけ真。R3-5b より前に作ったアカウント（作成時の顧客 ID が無い）は、`_cbjp_remote_id` を作成時の値とみなす
	 * （紐づけだけの経路は書き換える前に作成時の顧客 ID を控える。{@see self::link_only()}）。
	 */
	private function owns_profile( int $user_id, CanonicalCustomer $item ): bool {
		$remote_id = $item->remote_id();

		if ( null === $remote_id || '' === $remote_id || get_user_meta( $user_id, self::CREATED_BY_IMPORT_META, true ) !== $this->platform ) {
			return false;
		}

		return $this->created_remote_id( $user_id ) === $remote_id;
	}

	private function created_remote_id( int $user_id ): string {
		$created = get_user_meta( $user_id, self::CREATED_REMOTE_ID_META, true );

		if ( is_string( $created ) && '' !== $created ) {
			return $created;
		}

		$linked = get_user_meta( $user_id, '_cbjp_remote_id', true );

		return is_string( $linked ) ? $linked : '';
	}

	/**
	 * 紐づけだけ（R3-5b）: プロフィール・住所・extras・メールに触れず、mapping（呼び出し元の Importer が local_id から書く）と
	 * 紐づけの印（D25・リンク再構築が読む）だけを残す。何も書き換えていないので `OPERATION_SKIPPED` で、理由を毎回積む。
	 *
	 * @param array<int,string> $warnings
	 */
	private function link_only( int $user_id, CanonicalCustomer $item, array $warnings ): WriteResult {
		// R3-5b より前に作ったアカウントは作成時の顧客 ID を `_cbjp_remote_id` から読むので、書き換える前に控える
		// （控えないと、ここで書く別の顧客の ID を次の取込みが作成時の値と読み、プロフィールを書いてしまう）。
		if ( '' !== get_user_meta( $user_id, self::CREATED_BY_IMPORT_META, true ) && '' === get_user_meta( $user_id, self::CREATED_REMOTE_ID_META, true ) ) {
			update_user_meta( $user_id, self::CREATED_REMOTE_ID_META, $this->created_remote_id( $user_id ) );
		}

		$this->write_link_markers( $user_id, $item );

		return new WriteResult( $user_id, WriteResult::OPERATION_SKIPPED, $this->with_reuse_warning( $warnings, $user_id ) );
	}

	/**
	 * 紐づけだけの理由（`CUSTOMER_REUSED_EXISTING`。detail はユーザー ID）を 1 つだけ積む。メールで採用したときは
	 * `resolve_target()` が既に積んでいる。mapping で結んだときは積まれていないので、毎回の結果に理由が残るよう足す。
	 *
	 * @param array<int,string> $warnings
	 * @return array<int,string>
	 */
	private function with_reuse_warning( array $warnings, int $user_id ): array {
		$warning = WarningCode::with_detail( CommerceWarningCode::CUSTOMER_REUSED_EXISTING, (string) $user_id );

		if ( in_array( $warning, $warnings, true ) ) {
			return $warnings;
		}

		$warnings[] = $warning;

		return $warnings;
	}

	/**
	 * 取込みで結んだ印（`_cbjp_platform`・`_cbjp_remote_id`。D25 の向きとリンク再構築が読む）。
	 */
	private function write_link_markers( int $user_id, CanonicalCustomer $item ): void {
		update_user_meta( $user_id, '_cbjp_platform', $this->platform );
		update_user_meta( $user_id, '_cbjp_remote_id', $item->remote_id() ?? '' );
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
				WarningCode::with_detail( CommerceWarningCode::CUSTOMER_REUSED_EXISTING, (string) $existing_user->ID )
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
