<?php
/**
 * @package CartBridgeJP\Pro
 */

declare( strict_types=1 );

namespace CartBridgeJP\Pro\Admin;

use CartBridgeJP\Pro\License\LicenseStore;
use CartBridgeJP\Pro\PasswordEmail\AccountLinks;
use CartBridgeJP\Pro\PasswordEmail\Campaign;
use CartBridgeJP\Pro\PasswordEmail\EmailRegistry;
use CartBridgeJP\Pro\PasswordEmail\LegacyAccountEstimator;
use CartBridgeJP\Pro\PasswordEmail\RecipientPolicy;
use CartBridgeJP\Pro\PasswordEmail\RecipientScan;
use CartBridgeJP\Pro\PasswordEmail\Sender;
use CartBridgeJP\Pro\PasswordEmail\SendRecord;
use CartBridgeJP\Pro\PasswordEmail\Verdict;
use Closure;
use WP_User;

/**
 * Pro の設定画面のタブ「パスワード設定メール」（R3-10。`docs/03` §10.5）。取込みで作った顧客へ、パスワードを設定するリンクを一斉に送る。
 *
 * 操作は `admin-post.php` へのフォームで、権限（`manage_woocommerce`）と nonce を確かめてから行う（`LicensePage` と同じ）。送る相手の件数・テスト送信・取消は
 * キーが無くても使え、一斉送信の開始・再開だけキーの有効化が要る（D30 の延長。2026-10-13 ユーザー決定）。断った要求は状態・予約・記録を変えず、メールも送らない。
 */
final class PasswordEmailPage {

	public const CAPABILITY = 'manage_woocommerce';

	public const ACTION_TEST          = 'cbjp_pro_password_email_test';
	public const ACTION_START         = 'cbjp_pro_password_email_start';
	public const ACTION_CANCEL        = 'cbjp_pro_password_email_cancel';
	public const ACTION_RESUME        = 'cbjp_pro_password_email_resume';
	public const ACTION_CLEAR_SENDING = 'cbjp_pro_password_email_clear_sending';

	public const FIELD_CONFIRM             = 'cbjp_pro_confirm_send';
	public const FIELD_CONFIRM_ENVIRONMENT = 'cbjp_pro_confirm_environment';
	public const FIELD_INCLUDE_LEGACY      = 'cbjp_pro_include_legacy';
	public const FIELD_RUN_ID              = 'cbjp_pro_run_id';
	public const FIELD_TOKEN               = 'cbjp_pro_token';

	private const NOTICE_TRANSIENT_PREFIX = 'cbjp_pro_password_email_notice_';

	/** 画面に一覧で出す 0.1.0 の推定の件数。 */
	private const LEGACY_LIST_LIMIT = 50;

	/** 転送の後にリクエストを終える処理（既定は `exit`）。 */
	private readonly Closure $terminate;

	private readonly AdminNotice $notice;

	/**
	 * @param Closure|null $terminate 転送の後にリクエストを終える処理。テストは `exit` の代わりを渡す（`LicensePage` と同じ理由）。
	 */
	public function __construct(
		private readonly LicenseStore $store,
		private readonly LegacyAccountEstimator $estimator,
		?Closure $terminate = null
	) {
		$this->terminate = $terminate ?? static function (): never {
			exit;
		};
		$this->notice    = new AdminNotice( self::NOTICE_TRANSIENT_PREFIX );
	}

	public static function create(): self {
		return new self( new LicenseStore(), LegacyAccountEstimator::create() );
	}

	/**
	 * @return array<int,string>
	 */
	public static function actions(): array {
		return [ self::ACTION_TEST, self::ACTION_START, self::ACTION_CANCEL, self::ACTION_RESUME, self::ACTION_CLEAR_SENDING ];
	}

	public static function page_url(): string {
		return LicensePage::tab_url( LicensePage::TAB_PASSWORD_EMAILS );
	}

	public function register(): void {
		foreach ( self::actions() as $action ) {
			add_action( 'admin_post_' . $action, [ $this, 'handle_request' ] );
		}
	}

	/**
	 * `admin-post.php` の入口。処理してタブへ戻す。
	 */
	public function handle_request(): void {
		$this->process( (string) preg_replace( '/^admin_post_/', '', (string) current_action() ) );

		wp_safe_redirect( self::page_url() );
		( $this->terminate )();
	}

	/**
	 * 権限と nonce を確かめてから操作し、結果の文を記録する（確かめられなければ `wp_die()`）。
	 *
	 * @return bool 操作が成功したか。
	 */
	public function process( string $action ): bool {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to send password setup emails.', 'cart-bridge-jp-pro' ), '', [ 'response' => 403 ] );
		}

		if ( ! in_array( $action, self::actions(), true ) ) {
			wp_die( esc_html__( 'Unknown password setup email action.', 'cart-bridge-jp-pro' ), '', [ 'response' => 400 ] );
		}

		check_admin_referer( $action );

		$error = match ( $action ) {
			self::ACTION_TEST   => $this->send_test(),
			self::ACTION_START  => $this->start(),
			self::ACTION_CANCEL => $this->cancel(),
			self::ACTION_RESUME => $this->resume(),
			default             => $this->clear_sending(),
		};

		if ( null !== $error ) {
			$this->notice->set( 'error', $error );

			return false;
		}

		return true;
	}

	private function send_test(): ?string {
		$email   = EmailRegistry::email();
		$problem = EmailRegistry::problem( $email );

		if ( null !== $problem || null === $email ) {
			return self::problem_text( $problem ?? Campaign::STOP_EMAIL_UNAVAILABLE );
		}

		$user = wp_get_current_user();

		if ( ! is_email( $user->user_email ) ) {
			return __( 'Your account has no valid email address to send the test email to.', 'cart-bridge-jp-pro' );
		}

		if ( ! $email->send_test( $user->user_email ) ) {
			return __( 'The test email could not be sent. Check the email settings of this site, such as an SMTP plugin.', 'cart-bridge-jp-pro' );
		}

		/* translators: %s: the email address the test email was sent to. */
		$this->notice->set( 'success', sprintf( __( 'A test email was sent to %s. It uses a sample name and a sample link.', 'cart-bridge-jp-pro' ), $user->user_email ) );

		return null;
	}

	private function start(): ?string {
		if ( ! $this->store->is_activated() ) {
			return self::license_text();
		}

		if ( ! self::posted_flag( self::FIELD_CONFIRM ) ) {
			return __( 'Confirm that the store has moved to this site before sending the emails.', 'cart-bridge-jp-pro' );
		}

		if ( ! self::is_production() && ! self::posted_flag( self::FIELD_CONFIRM_ENVIRONMENT ) ) {
			return __( 'This site is not marked as a production site. Confirm that you want to send the emails from this site.', 'cart-bridge-jp-pro' );
		}

		$problem = EmailRegistry::problem( EmailRegistry::email() );

		if ( null !== $problem ) {
			return self::problem_text( $problem );
		}

		$include_legacy = self::posted_flag( self::FIELD_INCLUDE_LEGACY );
		$legacy_ids     = $include_legacy ? $this->estimator->estimate()['ids'] : [];
		$started        = Campaign::start( get_current_user_id(), $include_legacy, $legacy_ids );

		if ( null === $started ) {
			return __( 'Password setup emails are already being sent.', 'cart-bridge-jp-pro' );
		}

		if ( ! Sender::schedule( $started['run_id'], $started['token'], 0 ) ) {
			Campaign::stop( $started['run_id'], $started['token'], Campaign::STOP_SCHEDULE_FAILED );

			return self::problem_text( Campaign::STOP_SCHEDULE_FAILED );
		}

		$this->notice->set( 'success', __( 'Sending password setup emails started. They are sent in small batches in the background.', 'cart-bridge-jp-pro' ) );

		return null;
	}

	private function cancel(): ?string {
		if ( ! Campaign::cancel( self::posted_id( self::FIELD_RUN_ID ) ) ) {
			return __( 'The sending could not be canceled. It may have finished or changed. Check the status below.', 'cart-bridge-jp-pro' );
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Sender::HOOK );
		}

		$this->notice->set( 'success', __( 'Sending password setup emails was canceled. Emails already sent cannot be recalled.', 'cart-bridge-jp-pro' ) );

		return null;
	}

	private function resume(): ?string {
		if ( ! $this->store->is_activated() ) {
			return self::license_text();
		}

		$control = Campaign::control();
		$run_id  = self::posted_id( self::FIELD_RUN_ID );

		if ( null === $control || $control['run_id'] !== $run_id || ! self::can_resume( $control ) ) {
			return __( 'The sending could not be resumed. It may be running, finished, or changed. Check the status below.', 'cart-bridge-jp-pro' );
		}

		$problem = EmailRegistry::problem( EmailRegistry::email() );

		if ( null !== $problem ) {
			return self::problem_text( $problem );
		}

		$token = Campaign::resume( $run_id, self::posted_id( self::FIELD_TOKEN ) );

		if ( null === $token ) {
			return __( 'The sending could not be resumed. It may be running, finished, or changed. Check the status below.', 'cart-bridge-jp-pro' );
		}

		if ( ! Sender::schedule( $run_id, $token, 0 ) ) {
			Campaign::stop( $run_id, $token, Campaign::STOP_SCHEDULE_FAILED );

			return self::problem_text( Campaign::STOP_SCHEDULE_FAILED );
		}

		$this->notice->set( 'success', __( 'Sending password setup emails resumed.', 'cart-bridge-jp-pro' ) );

		return null;
	}

	/**
	 * `sending` のまま残った記録を消して、次の送信の相手に戻す。送信中（生きたバッチがある）は断る（送っている最中の顧客へ 2 通目を送らない）。
	 */
	private function clear_sending(): ?string {
		$control = Campaign::control();

		if ( null !== $control && Campaign::STATUS_RUNNING === $control['status'] && ! Sender::is_interrupted( $control, Campaign::progress() ) ) {
			return __( 'Wait until the current sending finishes or cancel it first.', 'cart-bridge-jp-pro' );
		}

		$cleared = 0;
		$after   = 0;

		do {
			$ids = RecipientScan::ids_after( $after, 500 );

			foreach ( $ids as $user_id ) {
				$cleared += SendRecord::clear_sending( $user_id ) ? 1 : 0;
			}

			$after = [] === $ids ? $after : (int) end( $ids );
			$read  = count( $ids );
		} while ( 500 === $read );

		/* translators: %d: the number of customers. */
		$this->notice->set( 'success', sprintf( _n( '%d customer will receive the email in the next sending.', '%d customers will receive the email in the next sending.', $cleared, 'cart-bridge-jp-pro' ), $cleared ) );

		return null;
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->notice->render();

		$control   = Campaign::control();
		$progress  = Campaign::progress();
		$running   = null !== $control && Campaign::STATUS_RUNNING === $control['status'];
		$activated = $this->store->is_activated();
		$email     = EmailRegistry::email();
		$problem   = EmailRegistry::problem( $email );

		printf( '<p>%s</p>', esc_html__( 'Customers imported from another shop have accounts on this store, but their passwords could not be moved. After the store has moved to this site, send them an email with a link to set a password. Importing never sends emails by itself.', 'cart-bridge-jp-pro' ) );

		if ( ! $activated ) {
			printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html__( 'Until a license key is activated on this site, you can check the recipients and send a test email to yourself. Sending to customers requires an activated license key.', 'cart-bridge-jp-pro' ) );
		}

		if ( null !== $problem ) {
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( self::problem_text( $problem ) ) );
		}

		// 送る相手。0.1.0 の推定は含めた前提で判定し、送る相手になるものだけを一覧に出す（含めるかは開始のときに選ぶ）。進行中の run は、その run が控えた推定
		// （含めなかった run は空）で数える。
		$estimate   = $running ? null : $this->estimator->estimate();
		$legacy_ids = null === $estimate ? Campaign::legacy_ids( $control['run_id'] ?? '' ) : $estimate['ids'];
		$summary    = RecipientScan::summarize( new RecipientPolicy( $legacy_ids, true ) );

		$this->render_status( $control, $progress );
		$this->render_summary( $summary, $estimate, $running );
		$this->render_forms( $control, $progress, $summary, $activated, null === $problem );
		$this->render_guidance();
	}

	/**
	 * @param array{run_id:string,status:string,reason:string,token:string,include_legacy:bool,started_at:int,started_by:int,updated_at:int,rev:int}|null $control
	 * @param array{run_id:string,cursor:int,sent:int,failed:int,unknown:int,legacy:int,consecutive_failures:int,last_batch_at:int,rev:int}               $progress
	 */
	private function render_status( ?array $control, array $progress ): void {
		if ( null === $control || '' === $control['run_id'] ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Last sending', 'cart-bridge-jp-pro' ) );

		$status = match ( true ) {
			Sender::is_interrupted( $control, $progress )             => __( 'Interrupted. The background task stopped, for example because the plugin was deactivated. Resume to continue from where it stopped.', 'cart-bridge-jp-pro' ),
			Campaign::STATUS_RUNNING === $control['status']           => __( 'Sending in the background.', 'cart-bridge-jp-pro' ),
			Campaign::STATUS_COMPLETED === $control['status']         => __( 'Finished.', 'cart-bridge-jp-pro' ),
			Campaign::STATUS_STOPPED === $control['status']           => self::join_sentences( __( 'Stopped.', 'cart-bridge-jp-pro' ), self::problem_text( $control['reason'] ) ),
			default                                                   => __( 'Canceled.', 'cart-bridge-jp-pro' ),
		};

		echo '<table class="form-table" role="presentation"><tbody>';
		self::render_row( __( 'Status', 'cart-bridge-jp-pro' ), $status );
		self::render_row( __( 'Started', 'cart-bridge-jp-pro' ), self::format_time( $control['started_at'] ) );

		if ( $progress['run_id'] === $control['run_id'] ) {
			self::render_row( __( 'Sent', 'cart-bridge-jp-pro' ), number_format_i18n( $progress['sent'] ) );
			self::render_row( __( 'Could not be sent', 'cart-bridge-jp-pro' ), number_format_i18n( $progress['failed'] ) );
		}

		echo '</tbody></table>';
	}

	/**
	 * @param array{send:int,unknown:int,legacy_send:int,excluded:array<string,int>,legacy_ids:array<int,int>,sending_ids:array<int,int>,failed_ids:array<int,int>} $summary
	 * @param array{ids:array<int,int>,discarded:bool}|null                                                                                                      $estimate
	 */
	private function render_summary( array $summary, ?array $estimate, bool $running ): void {
		printf( '<h2>%s</h2>', esc_html__( 'Recipients', 'cart-bridge-jp-pro' ) );

		echo '<table class="form-table" role="presentation"><tbody>';
		// 始める前は、0.1.0 の推定を除いた件数（推定は下の一覧。含めるかを開始のときに選ぶ）。
		self::render_row( __( 'Will receive the email', 'cart-bridge-jp-pro' ), number_format_i18n( $running ? $summary['send'] : $summary['send'] - $summary['legacy_send'] ) );

		// 0.1.0 の推定は控えが無いので、すべて「判定できない」。始める前は推定を除いて数える。
		$unknown = $running ? $summary['unknown'] : $summary['unknown'] - $summary['legacy_send'];

		if ( $unknown > 0 ) {
			self::render_row(
				__( 'Password status unknown', 'cart-bridge-jp-pro' ),
				sprintf(
					/* translators: %s: the number of customers. */
					_n(
						'%s of them was imported by an earlier version, so whether they have already set a password cannot be told. They receive the email.',
						'%s of them were imported by an earlier version, so whether they have already set a password cannot be told. They receive the email.',
						$unknown,
						'cart-bridge-jp-pro'
					),
					number_format_i18n( $unknown )
				)
			);
		}

		if ( [] !== $summary['failed_ids'] ) {
			/* translators: %s: the number of customers. */
			self::render_row( __( 'Could not be sent last time', 'cart-bridge-jp-pro' ), sprintf( __( '%s (included again)', 'cart-bridge-jp-pro' ), number_format_i18n( count( $summary['failed_ids'] ) ) ) );
		}

		$excluded = [
			Verdict::PASSWORD_SET          => __( 'Already set a password', 'cart-bridge-jp-pro' ),
			Verdict::ALREADY_SENT          => __( 'Already received the email', 'cart-bridge-jp-pro' ),
			Verdict::PROTECTED_ACCOUNT     => __( 'Staff or other non-customer accounts', 'cart-bridge-jp-pro' ),
			Verdict::NOT_CREATED_BY_IMPORT => __( 'Accounts the import did not create', 'cart-bridge-jp-pro' ),
			Verdict::INVALID_EMAIL         => __( 'No valid email address', 'cart-bridge-jp-pro' ),
			Verdict::RESET_NOT_ALLOWED     => __( 'Password reset not allowed', 'cart-bridge-jp-pro' ),
		];

		foreach ( $excluded as $outcome => $label ) {
			$count = $summary['excluded'][ $outcome ] ?? 0;

			if ( $count > 0 ) {
				/* translators: %s: the reason the customers are left out. */
				self::render_row( sprintf( __( 'Left out: %s', 'cart-bridge-jp-pro' ), $label ), number_format_i18n( $count ) );
			}
		}

		echo '</tbody></table>';

		if ( [] !== $summary['sending_ids'] ) {
			printf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: the number of customers. */
						_n(
							'Sending to %s customer was interrupted, so it is unknown whether the email reached them. They are left out until you mark them as not sent.',
							'Sending to %s customers was interrupted, so it is unknown whether the email reached them. They are left out until you mark them as not sent.',
							count( $summary['sending_ids'] ),
							'cart-bridge-jp-pro'
						),
						number_format_i18n( count( $summary['sending_ids'] ) )
					)
				)
			);
		}

		$this->render_legacy( $summary, $estimate );
	}

	/**
	 * 0.1.0 で作ったと推定したアカウントの一覧（管理者が開始のときに含めるかを選ぶ）。
	 *
	 * @param array{legacy_ids:array<int,int>} $summary
	 * @param array{ids:array<int,int>,discarded:bool}|null $estimate
	 */
	private function render_legacy( array $summary, ?array $estimate ): void {
		if ( null !== $estimate && $estimate['discarded'] ) {
			printf( '<p>%s</p>', esc_html__( 'Accounts imported by version 0.1.0 could not be identified reliably, so they are left out.', 'cart-bridge-jp-pro' ) );
			return;
		}

		if ( [] === $summary['legacy_ids'] ) {
			return;
		}

		printf( '<h3>%s</h3>', esc_html__( 'Accounts likely imported by version 0.1.0', 'cart-bridge-jp-pro' ) );
		printf( '<p>%s</p>', esc_html__( 'Version 0.1.0 did not mark the accounts it created. These accounts were registered while a customer import of version 0.1.0 was running, so they were most likely created by it. They receive the email only if you include them when you start sending. Whether they have already set a password cannot be told.', 'cart-bridge-jp-pro' ) );

		echo '<table class="widefat striped"><thead><tr>';
		printf( '<th scope="col">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th>', esc_html__( 'Name', 'cart-bridge-jp-pro' ), esc_html__( 'Email', 'cart-bridge-jp-pro' ), esc_html__( 'Registered', 'cart-bridge-jp-pro' ) );
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $summary['legacy_ids'], 0, self::LEGACY_LIST_LIMIT ) as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user instanceof WP_User ) {
				continue;
			}

			printf(
				'<tr><td><a href="%1$s">%2$s</a></td><td>%3$s</td><td>%4$s</td></tr>',
				esc_url( get_edit_user_link( $user_id ) ),
				esc_html( '' !== $user->display_name ? $user->display_name : $user->user_login ),
				esc_html( $user->user_email ),
				esc_html( $user->user_registered )
			);
		}

		echo '</tbody></table>';

		if ( count( $summary['legacy_ids'] ) > self::LEGACY_LIST_LIMIT ) {
			/* translators: %s: the number of accounts. */
			printf( '<p>%s</p>', esc_html( sprintf( __( 'And %s more.', 'cart-bridge-jp-pro' ), number_format_i18n( count( $summary['legacy_ids'] ) - self::LEGACY_LIST_LIMIT ) ) ) );
		}
	}

	/**
	 * @param array{run_id:string,status:string,reason:string,token:string,include_legacy:bool,started_at:int,started_by:int,updated_at:int,rev:int}|null $control
	 * @param array{run_id:string,cursor:int,sent:int,failed:int,unknown:int,legacy:int,consecutive_failures:int,last_batch_at:int,rev:int}               $progress
	 * @param array{send:int,legacy_send:int,legacy_ids:array<int,int>,sending_ids:array<int,int>}                                                        $summary
	 */
	private function render_forms( ?array $control, array $progress, array $summary, bool $activated, bool $ready ): void {
		$running     = null !== $control && Campaign::STATUS_RUNNING === $control['status'];
		$interrupted = null !== $control && Sender::is_interrupted( $control, $progress );

		printf( '<h2>%s</h2>', esc_html__( 'Send a test email', 'cart-bridge-jp-pro' ) );
		printf( '<p>%s</p>', esc_html__( 'Sends the email to your own address with a sample name and a sample link, so that you can check the text and the design.', 'cart-bridge-jp-pro' ) );
		$this->open_form( self::ACTION_TEST );
		submit_button( __( 'Send a test email', 'cart-bridge-jp-pro' ), 'secondary', 'submit', true, [ 'id' => self::ACTION_TEST . '_submit' ] );
		echo '</form>';

		if ( null !== $control && ( $running || Campaign::STATUS_STOPPED === $control['status'] ) ) {
			if ( $activated && $ready && ( $interrupted || Campaign::STATUS_STOPPED === $control['status'] ) ) {
				printf( '<h2>%s</h2>', esc_html__( 'Resume sending', 'cart-bridge-jp-pro' ) );
				$this->open_form( self::ACTION_RESUME, $control );
				submit_button( __( 'Resume', 'cart-bridge-jp-pro' ), 'primary', 'submit', true, [ 'id' => self::ACTION_RESUME . '_submit' ] );
				echo '</form>';
			}

			printf( '<h2>%s</h2>', esc_html__( 'Cancel sending', 'cart-bridge-jp-pro' ) );
			printf( '<p>%s</p>', esc_html__( 'Stops sending to the remaining customers. Emails already sent cannot be recalled.', 'cart-bridge-jp-pro' ) );
			$this->open_form( self::ACTION_CANCEL, $control );
			submit_button( __( 'Cancel sending', 'cart-bridge-jp-pro' ), 'secondary', 'submit', true, [ 'id' => self::ACTION_CANCEL . '_submit' ] );
			echo '</form>';
		}

		if ( [] !== $summary['sending_ids'] && ( ! $running || $interrupted ) ) {
			printf( '<h2>%s</h2>', esc_html__( 'Mark interrupted emails as not sent', 'cart-bridge-jp-pro' ) );
			printf( '<p>%s</p>', esc_html__( 'These customers receive the email in the next sending. Some of them may receive it twice.', 'cart-bridge-jp-pro' ) );
			$this->open_form( self::ACTION_CLEAR_SENDING );
			submit_button( __( 'Mark as not sent', 'cart-bridge-jp-pro' ), 'secondary', 'submit', true, [ 'id' => self::ACTION_CLEAR_SENDING . '_submit' ] );
			echo '</form>';
		}

		if ( $running || ! $activated || ! $ready ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html__( 'Send to customers', 'cart-bridge-jp-pro' ) );
		$count = $summary['send'] - $summary['legacy_send'];

		$this->open_form( self::ACTION_START );
		printf(
			'<p><label><input type="checkbox" name="%1$s" value="1" required /> %2$s</label></p>',
			esc_attr( self::FIELD_CONFIRM ),
			esc_html(
				sprintf(
					/* translators: 1: the address of this site, 2: the number of customers. */
					_n( 'The store has moved to this site (%1$s). Send the email to %2$s customer.', 'The store has moved to this site (%1$s). Send the email to %2$s customers.', $count, 'cart-bridge-jp-pro' ),
					home_url( '/' ),
					number_format_i18n( $count )
				)
			)
		);

		if ( ! self::is_production() ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" required /> %2$s</label></p>',
				esc_attr( self::FIELD_CONFIRM_ENVIRONMENT ),
				esc_html__( 'This site is not marked as a production site (for example, a staging copy). Send the emails from this site anyway. Customers will receive links to this site.', 'cart-bridge-jp-pro' )
			);
		}

		if ( [] !== $summary['legacy_ids'] ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
				esc_attr( self::FIELD_INCLUDE_LEGACY ),
				esc_html(
					sprintf(
						/* translators: %s: the number of accounts. */
						_n( 'Also send to the %s account likely imported by version 0.1.0 (listed above).', 'Also send to the %s accounts likely imported by version 0.1.0 (listed above).', count( $summary['legacy_ids'] ), 'cart-bridge-jp-pro' ),
						number_format_i18n( count( $summary['legacy_ids'] ) )
					)
				)
			);
		}

		submit_button( __( 'Start sending', 'cart-bridge-jp-pro' ), 'primary', 'submit', true, [ 'id' => self::ACTION_START . '_submit' ] );
		echo '</form>';
	}

	private function render_guidance(): void {
		printf( '<h2>%s</h2>', esc_html__( 'Before sending', 'cart-bridge-jp-pro' ) );
		echo '<ul class="ul-disc">';

		$items = [
			__( 'Many hosting services limit how many emails a site can send per hour. Use an SMTP plugin or an email delivery service, and send a test email first. The emails are sent in small batches, and sending stops if several emails in a row cannot be sent.', 'cart-bridge-jp-pro' ),
			__( 'The subject, heading, and additional text can be changed in the WooCommerce email settings. Do not add advertisements to this email.', 'cart-bridge-jp-pro' ),
			/* translators: %s: how long the link works, such as "1 day". */
			sprintf( __( 'The link in the email works for %s. Customers can request a new link from the lost password page, which the email also shows.', 'cart-bridge-jp-pro' ), human_time_diff( 0, AccountLinks::expiration_seconds() ) ),
			__( 'Creating the link updates each customer account, so other plugins that watch account changes may react to it.', 'cart-bridge-jp-pro' ),
			__( 'The email goes to the address stored in each account. If the previous shop did not verify email addresses, an address may belong to someone else.', 'cart-bridge-jp-pro' ),
			__( 'Only accounts created by the import receive the email. Accounts that already existed and were matched by email, and staff accounts, are left out.', 'cart-bridge-jp-pro' ),
		];

		foreach ( $items as $item ) {
			printf( '<li>%s</li>', esc_html( $item ) );
		}

		echo '</ul>';
		printf(
			'<p><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=wc-settings&tab=email&section=' . strtolower( EmailRegistry::EMAIL_KEY ) ) ),
			esc_html__( 'Edit the email in the WooCommerce settings', 'cart-bridge-jp-pro' )
		);
	}

	/**
	 * @param array{run_id:string,token:string}|null $control 取消・再開のフォームに、画面が見た run と トークンを持たせる（古い画面から別の run を操作しない）。
	 */
	private function open_form( string $action, ?array $control = null ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		wp_nonce_field( $action );

		if ( null !== $control ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( self::FIELD_RUN_ID ), esc_attr( $control['run_id'] ) );
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( self::FIELD_TOKEN ), esc_attr( $control['token'] ) );
		}
	}

	/**
	 * 再開できるか: 停止したか、進行中なのに中断した。
	 *
	 * @param array{run_id:string,status:string,reason:string,token:string,include_legacy:bool,started_at:int,started_by:int,updated_at:int,rev:int} $control
	 */
	private static function can_resume( array $control ): bool {
		return Campaign::STATUS_STOPPED === $control['status'] || Sender::is_interrupted( $control, Campaign::progress() );
	}

	private static function problem_text( string $problem ): string {
		return match ( $problem ) {
			Campaign::STOP_EMAIL_UNAVAILABLE => __( 'The password setup email is disabled. Enable it in the WooCommerce email settings.', 'cart-bridge-jp-pro' ),
			Campaign::STOP_ACCOUNT_PAGE      => __( 'The link in the email needs the My account page and its lost password endpoint. Publish the My account page and set the endpoint in the WooCommerce settings.', 'cart-bridge-jp-pro' ),
			Campaign::STOP_MAIL_FAILING      => __( 'Several emails in a row could not be sent. Check the email settings of this site, such as an SMTP plugin or the sending limit of your hosting, and then resume.', 'cart-bridge-jp-pro' ),
			Campaign::STOP_SCHEDULE_FAILED   => __( 'The background task could not be scheduled. Try again.', 'cart-bridge-jp-pro' ),
			default                          => __( 'The sending state changed unexpectedly. Check the status and start again if needed.', 'cart-bridge-jp-pro' ),
		};
	}

	private static function license_text(): string {
		return __( 'Sending to customers requires an activated license key. Activate it on the License tab.', 'cart-bridge-jp-pro' );
	}

	private static function is_production(): bool {
		return 'production' === wp_get_environment_type();
	}

	/**
	 * チェックボックス（`process()` が `check_admin_referer()` で nonce を確かめてから呼ぶ）。
	 */
	private static function posted_flag( string $field ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process() が check_admin_referer() で確かめてから呼ぶ。
		return isset( $_POST[ $field ] ) && '1' === $_POST[ $field ];
	}

	/**
	 * run の ID・トークン（16 進数 32 文字。違う形は空にして、どの run とも一致させない）。
	 */
	private static function posted_id( string $field ): string {
		// process() が check_admin_referer() で nonce を確かめてから呼ぶ。値は下の正規表現で形を確かめる。
		$raw = isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return is_string( $raw ) && 1 === preg_match( '/^[0-9a-f]{32}\z/', $raw ) ? $raw : '';
	}

	private static function render_row( string $label, string $value ): void {
		printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * 訳した文を 2 つつなぐ（`LicensePage::join_sentences()` と同じ書式。日本語は空白なし）。
	 */
	private static function join_sentences( string $first, string $second ): string {
		/* translators: 1: a sentence, 2: the sentence that follows it. Languages that do not separate sentences with a space can drop the space. */
		return sprintf( __( '%1$s %2$s', 'cart-bridge-jp-pro' ), $first, $second );
	}

	private static function format_time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		$date   = get_option( 'date_format' );
		$time   = get_option( 'time_format' );
		$format = trim( ( is_string( $date ) ? $date : '' ) . ' ' . ( is_string( $time ) ? $time : '' ) );
		$text   = wp_date( '' === $format ? 'Y-m-d H:i' : $format, $timestamp );

		return is_string( $text ) ? $text : '';
	}
}
