<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Admin;

use CartBridgeJP\Pro\License\LicenseManager;
use CartBridgeJP\Pro\License\LicenseResult;
use Closure;

/**
 * Pro の設定画面（ライセンスキーの有効化・無効化・状態確認。R3-8a）。WooCommerce メニューの下に置く。
 *
 * Pro は JS を持たないので PHP で描く（`docs/03` §10.0「決め残し」2・§10.7「守ること」5）。操作は `admin-post.php` へのフォームで、
 * 権限（`manage_woocommerce`）と nonce を確かめてから行い、結果の文はユーザーごとの短い transient で画面へ渡す（サーバーの文を URL に載せない）。
 */
final class LicensePage {

	public const PAGE_SLUG  = 'cart-bridge-jp-pro';
	public const CAPABILITY = 'manage_woocommerce';

	public const ACTION_ACTIVATE   = 'cbjp_pro_license_activate';
	public const ACTION_DEACTIVATE = 'cbjp_pro_license_deactivate';
	public const ACTION_CHECK      = 'cbjp_pro_license_check';
	public const ACTION_SAVE_KEY   = 'cbjp_pro_license_save_key';

	public const KEY_FIELD = 'cbjp_pro_license_key';

	private const NOTICE_TRANSIENT_PREFIX = 'cbjp_pro_license_notice_';
	private const NOTICE_TTL              = 300;

	/** 転送の後にリクエストを終える処理（既定は `exit`）。 */
	private readonly Closure $terminate;

	/**
	 * @param Closure|null $terminate 転送の後にリクエストを終える処理。テストは `exit` の代わりを渡す（`exit` に届くと PHPUnit が
	 *                                終了コード 0 のまま止まり、残りのテストが黙って走らなくなる。review-loop R2-1）。
	 */
	public function __construct( private readonly LicenseManager $manager, ?Closure $terminate = null ) {
		$this->terminate = $terminate ?? static function (): never {
			exit;
		};
	}

	public static function create(): self {
		return new self( LicenseManager::create() );
	}

	/**
	 * @return array<int,string>
	 */
	public static function actions(): array {
		return [ self::ACTION_ACTIVATE, self::ACTION_DEACTIVATE, self::ACTION_CHECK, self::ACTION_SAVE_KEY ];
	}

	public static function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );

		foreach ( self::actions() as $action ) {
			add_action( 'admin_post_' . $action, [ $this, 'handle_request' ] );
		}

		add_filter( 'plugin_action_links_' . plugin_basename( CBJP_PRO_FILE ), [ $this, 'add_action_link' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Cart Bridge JP Pro', 'cart-bridge-jp-pro' ),
			__( 'Cart Bridge JP Pro', 'cart-bridge-jp-pro' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * プラグイン一覧の行に設定画面へのリンクを足す。
	 *
	 * @param array<int|string,string> $links
	 * @return array<int|string,string>
	 */
	public function add_action_link( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( self::page_url() ), esc_html__( 'License', 'cart-bridge-jp-pro' ) )
		);

		return $links;
	}

	/**
	 * `admin-post.php` の入口。処理して設定画面へ戻す。
	 */
	public function handle_request(): void {
		$this->process( (string) preg_replace( '/^admin_post_/', '', (string) current_action() ) );

		wp_safe_redirect( self::page_url() );
		( $this->terminate )();
	}

	/**
	 * 権限と nonce を確かめてから操作し、結果の文を記録する（確かめられなければ `wp_die()`）。
	 */
	public function process( string $action ): LicenseResult {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage the Cart Bridge JP Pro license.', 'cart-bridge-jp-pro' ), '', [ 'response' => 403 ] );
		}

		if ( ! in_array( $action, self::actions(), true ) ) {
			wp_die( esc_html__( 'Unknown license action.', 'cart-bridge-jp-pro' ), '', [ 'response' => 400 ] );
		}

		check_admin_referer( $action );

		// 有効化済みのサイトでのキーの交換かどうか（サーバーに断られたときの案内に使う）。操作の前に読む。
		$was_activated = $this->manager->is_activated();

		$result = match ( $action ) {
			self::ACTION_ACTIVATE   => $this->manager->activate( $this->posted_key() ),
			self::ACTION_DEACTIVATE => $this->manager->deactivate(),
			self::ACTION_CHECK      => $this->manager->check_status(),
			default                 => $this->manager->replace_unreadable_key( $this->posted_key() ),
		};

		$this->set_notice(
			$result->is_success() ? 'success' : 'error',
			$result->is_success() ? self::success_message( $action ) : self::failure_message( $action, $result, $was_activated )
		);

		return $result;
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$store      = $this->manager->store();
		$activated  = $store->is_activated();
		$configured = $this->manager->is_configured();
		$unreadable = $activated && $store->key_unreadable();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Cart Bridge JP Pro', 'cart-bridge-jp-pro' ) );

		$this->render_notice();

		printf( '<p>%s</p>', esc_html__( 'Enter the license key you received with your purchase to activate Cart Bridge JP Pro on this site.', 'cart-bridge-jp-pro' ) );

		// キーが無い間にできること（D30・R3-8b。判定は `License\LicenseGate`）。有効化の状態はローカルの記録だけで決まるので、同じ値で出し分ける。
		printf(
			'<p>%s</p>',
			$activated
				? esc_html__( 'With the activated key, customers, orders, and coupons can be imported and exported on this site, as far as the connected shop supports each operation.', 'cart-bridge-jp-pro' )
				: esc_html__( 'Until a license key is activated on this site, customers, orders, and coupons can only be previewed with a dry run. Importing and exporting them requires an activated key.', 'cart-bridge-jp-pro' )
		);

		if ( ! $configured ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__( 'The license server is not configured in this build of Cart Bridge JP Pro.', 'cart-bridge-jp-pro' )
			);
		}

		// 販売サイトへ送るもの（backlog `r3-8a-license-client/R1-X2`。無料版の readme の External services と同じ考え方）。更新の確認は R3-8c。
		$server_host = $this->manager->server_host();

		if ( $configured && null !== $server_host ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: the host name of the license server, such as example.com. */
						__( 'To activate the license key, check its status, and check for updates, Cart Bridge JP Pro sends the license key, the address of this site, and the version of Cart Bridge JP Pro to the license server (%s). Updates are checked only while a license key is activated on this site.', 'cart-bridge-jp-pro' ),
						$server_host
					)
				)
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_row( __( 'Status', 'cart-bridge-jp-pro' ), $this->status_text() );

		if ( $activated ) {
			$this->render_row(
				__( 'License key', 'cart-bridge-jp-pro' ),
				$unreadable
					? __( 'The saved license key cannot be read, for example because the security keys of this site have changed.', 'cart-bridge-jp-pro' )
					/* translators: %s: the last four characters of the license key. */
					: sprintf( __( 'Ending in %s', 'cart-bridge-jp-pro' ), $store->key_suffix() )
			);
		}

		$check = $store->last_check();

		if ( null !== $check ) {
			$this->render_row( __( 'Last status check', 'cart-bridge-jp-pro' ), $this->check_text( $check ) );
		}

		echo '</tbody></table>';

		if ( $activated && null !== $check && ! $check['activated'] ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'The license server no longer lists this site, for example after the update period ends. Cart Bridge JP Pro keeps working on this site.', 'cart-bridge-jp-pro' )
			);
		}

		if ( ! $configured ) {
			echo '</div>';
			return;
		}

		if ( ! $activated ) {
			$this->render_key_form(
				self::ACTION_ACTIVATE,
				__( 'Activate license', 'cart-bridge-jp-pro' ),
				'',
				__( 'Activate', 'cart-bridge-jp-pro' )
			);
		} elseif ( $unreadable ) {
			$this->render_key_form(
				self::ACTION_SAVE_KEY,
				__( 'Enter the license key again', 'cart-bridge-jp-pro' ),
				__( 'This saves the license key on this site without contacting the license server.', 'cart-bridge-jp-pro' ),
				__( 'Save license key', 'cart-bridge-jp-pro' )
			);
		} else {
			$this->render_button_form(
				self::ACTION_CHECK,
				__( 'Check status', 'cart-bridge-jp-pro' ),
				__( 'Ask the license server about this license.', 'cart-bridge-jp-pro' ),
				__( 'Check status', 'cart-bridge-jp-pro' )
			);
			$this->render_button_form(
				self::ACTION_DEACTIVATE,
				__( 'Deactivate license', 'cart-bridge-jp-pro' ),
				__( 'This removes the license from this site. After the update period ends, a license cannot be activated again. If the database of this site was copied from another site, such as a staging copy, deactivating here also removes the original site from the license, and the original site stops receiving updates.', 'cart-bridge-jp-pro' ),
				__( 'Deactivate', 'cart-bridge-jp-pro' )
			);
			$this->render_key_form(
				self::ACTION_ACTIVATE,
				__( 'Use a different license key', 'cart-bridge-jp-pro' ),
				__( 'For example, after buying a new license. While the current license is in its update period, deactivate it first. After the update period ends, check the status first instead; this site stays activated.', 'cart-bridge-jp-pro' ),
				__( 'Activate this key', 'cart-bridge-jp-pro' )
			);
		}

		echo '</div>';
	}

	private function status_text(): string {
		$activated_at = $this->manager->store()->activated_at();

		if ( ! $this->manager->is_activated() ) {
			return __( 'Not activated', 'cart-bridge-jp-pro' );
		}

		if ( null === $activated_at ) {
			return __( 'Activated', 'cart-bridge-jp-pro' );
		}

		/* translators: %s: the date and time the license was activated. */
		return sprintf( __( 'Activated on %s', 'cart-bridge-jp-pro' ), self::format_time( $activated_at ) );
	}

	/**
	 * @param array{checked_at:int,activated:bool,expires:?string} $check
	 */
	private function check_text( array $check ): string {
		$text = self::join_sentences(
			/* translators: %s: the date and time of the status check. */
			sprintf( __( 'Checked on %s.', 'cart-bridge-jp-pro' ), self::format_time( $check['checked_at'] ) ),
			$check['activated']
				? __( 'The license server lists this site as activated.', 'cart-bridge-jp-pro' )
				: __( 'The license server does not list this site as activated.', 'cart-bridge-jp-pro' )
		);

		if ( null !== $check['expires'] ) {
			/* translators: %s: the end of the update period as reported by the license server (for example, a date or "Expired"). */
			$text = self::join_sentences( $text, sprintf( __( 'Update period: %s', 'cart-bridge-jp-pro' ), $check['expires'] ) );
		}

		return $text;
	}

	private function render_row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	private function render_key_form( string $action, string $heading, string $description, string $button ): void {
		$field_id = $action . '_key';

		printf( '<h2>%s</h2>', esc_html( $heading ) );

		if ( '' !== $description ) {
			printf( '<p>%s</p>', esc_html( $description ) );
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		wp_nonce_field( $action );
		printf(
			'<p><label for="%1$s">%2$s</label><br /><input type="text" id="%1$s" name="%3$s" class="regular-text" autocomplete="off" spellcheck="false" required /></p>',
			esc_attr( $field_id ),
			esc_html__( 'License key', 'cart-bridge-jp-pro' ),
			esc_attr( self::KEY_FIELD )
		);
		submit_button( $button, 'primary', 'submit', true, [ 'id' => $action . '_submit' ] );
		echo '</form>';
	}

	private function render_button_form( string $action, string $heading, string $description, string $button ): void {
		printf( '<h2>%s</h2>', esc_html( $heading ) );
		printf( '<p>%s</p>', esc_html( $description ) );
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		wp_nonce_field( $action );
		submit_button( $button, 'secondary', 'submit', true, [ 'id' => $action . '_submit' ] );
		echo '</form>';
	}

	private function render_notice(): void {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );

		if ( false === $notice ) {
			return;
		}

		delete_transient( $key );

		if ( ! is_array( $notice ) || ! in_array( $notice['type'] ?? null, [ 'success', 'error' ], true ) || ! is_string( $notice['message'] ?? null ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	private function set_notice( string $type, string $message ): void {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			[
				'type'    => $type,
				'message' => $message,
			],
			self::NOTICE_TTL
		);
	}

	/**
	 * フォームのキー（`process()` が `check_admin_referer()` で nonce を確かめてから呼ぶ）。
	 */
	private function posted_key(): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() が check_admin_referer() で確かめてから呼ぶ。
		$raw = $_POST[ self::KEY_FIELD ] ?? null;

		if ( ! is_string( $raw ) ) {
			return null;
		}

		// `sanitize_text_field()` は `%XX` などを黙って取り除き、形の違う入力を別のキーに変えて送りうる。形は `LicenseManager::normalize_key()` が
		// 許可リストの正規表現で確かめ、違えば拒否する（review-loop R1-6）。
		$key = wp_unslash( $raw ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalize_key() が ^[A-Za-z0-9_-]{1,190}\z で検証する。

		return is_string( $key ) ? $key : null;
	}

	private static function success_message( string $action ): string {
		return match ( $action ) {
			self::ACTION_ACTIVATE   => __( 'The license key was activated on this site.', 'cart-bridge-jp-pro' ),
			self::ACTION_DEACTIVATE => __( 'The license key was deactivated on this site.', 'cart-bridge-jp-pro' ),
			self::ACTION_CHECK      => __( 'The license status was checked.', 'cart-bridge-jp-pro' ),
			default                 => __( 'The license key was saved.', 'cart-bridge-jp-pro' ),
		};
	}

	private static function failure_message( string $action, LicenseResult $result, bool $was_activated ): string {
		$lead = match ( $action ) {
			self::ACTION_ACTIVATE   => __( 'The license key could not be activated.', 'cart-bridge-jp-pro' ),
			self::ACTION_DEACTIVATE => __( 'The license key could not be deactivated.', 'cart-bridge-jp-pro' ),
			self::ACTION_CHECK      => __( 'The license status could not be checked.', 'cart-bridge-jp-pro' ),
			default                 => __( 'The license key could not be saved.', 'cart-bridge-jp-pro' ),
		};

		$reason = match ( $result->code ) {
			LicenseResult::INVALID_KEY      => __( 'Enter a valid license key.', 'cart-bridge-jp-pro' ),
			LicenseResult::NOT_CONFIGURED   => __( 'The license server is not configured in this build of Cart Bridge JP Pro.', 'cart-bridge-jp-pro' ),
			LicenseResult::NOT_ALLOWED      => __( 'This action is not available for the current license.', 'cart-bridge-jp-pro' ),
			LicenseResult::STORAGE_ERROR    => __( 'The license data could not be saved on this site. Try again.', 'cart-bridge-jp-pro' ),
			LicenseResult::NOT_RECORDED     => self::not_recorded_reason( $action, $was_activated ),
			LicenseResult::INVALID_RESPONSE => __( 'The response from the license server could not be understood.', 'cart-bridge-jp-pro' ),
			LicenseResult::HTTP_ERROR       => '' === $result->message
				? __( 'Could not connect to the license server.', 'cart-bridge-jp-pro' )
				/* translators: %s: the error message from the connection. */
				: sprintf( __( 'Could not connect to the license server: %s', 'cart-bridge-jp-pro' ), $result->message ),
			default                         => '' === $result->message
				? __( 'The license server returned an error.', 'cart-bridge-jp-pro' )
				/* translators: %s: the error message from the license server. */
				: sprintf( __( 'The license server returned an error: %s', 'cart-bridge-jp-pro' ), $result->message ),
		};

		$message = self::join_sentences( $lead, $reason );

		if ( LicenseResult::SERVER_ERROR !== $result->code || ! $was_activated ) {
			return $message;
		}

		// サーバーが断った理由はキー・期限・設定のどれもありうるので、案内は「もし〜なら」の形にする（review-loop R1-5）。
		// 期限内はサーバーにこのサイトの有効化が残っていて、同じ instance での有効化を断る。期限後は状態確認でサーバーから外れ、ローカルの有効化は残る。
		$hint = match ( $action ) {
			self::ACTION_ACTIVATE   => __( 'If the license server still lists this site under the current license, deactivate it first, or check the status first if its update period has ended.', 'cart-bridge-jp-pro' ),
			// 期限後はサーバーがこのサイトの有効化を消しているので無効化を断り、猶予期間の後は状態確認もエラーになる（実測・ソース。review-loop R1-2）。
			self::ACTION_DEACTIVATE => __( 'If the update period has ended, the license server no longer accepts deactivation. Cart Bridge JP Pro keeps working on this site.', 'cart-bridge-jp-pro' ),
			self::ACTION_CHECK      => __( 'If the update period has ended, the license server may no longer know this license. Cart Bridge JP Pro keeps working on this site.', 'cart-bridge-jp-pro' ),
			default                 => '',
		};

		return '' === $hint ? $message : self::join_sentences( $message, $hint );
	}

	/**
	 * サーバーは受け付けたが、このサイトに記録できなかったときの文（PR #123 の最終確認）。やり直し方は操作で違う:
	 * 初めての有効化はもう一度有効化すれば突き合わせで記録する。無効化はサーバーからこのサイトが外れているので、もう一度無効化してもサーバーが断る
	 * （同じキーで有効化し直してから無効化する）。キーの交換は同じ instance での有効化をサーバーが断るので、やり直しを勧めない。
	 */
	private static function not_recorded_reason( string $action, bool $was_activated ): string {
		if ( self::ACTION_DEACTIVATE === $action ) {
			return __( 'The license server deactivated this site, but this site could not record it. Activate the same license key again, then deactivate it.', 'cart-bridge-jp-pro' );
		}

		return $was_activated
			? __( 'The license server activated the key, but this site could not record it.', 'cart-bridge-jp-pro' )
			: __( 'The license server activated the key, but this site could not record it. Try again.', 'cart-bridge-jp-pro' );
	}

	/**
	 * 訳した文を 2 つつなぐ。`' '` を直に挟むと日本語で「。 」が残るので、区切りも翻訳する（無料版の画面の `joinSentences()` と同じ書式。R3-8d）。
	 */
	private static function join_sentences( string $first, string $second ): string {
		/* translators: 1: a sentence, 2: the sentence that follows it. Languages that do not separate sentences with a space can drop the space. */
		return sprintf( __( '%1$s %2$s', 'cart-bridge-jp-pro' ), $first, $second );
	}

	private static function format_time( int $timestamp ): string {
		$date   = get_option( 'date_format' );
		$time   = get_option( 'time_format' );
		$format = trim( ( is_string( $date ) ? $date : '' ) . ' ' . ( is_string( $time ) ? $time : '' ) );
		$text   = wp_date( '' === $format ? 'Y-m-d H:i' : $format, $timestamp );

		return is_string( $text ) ? $text : '';
	}
}
