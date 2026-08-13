import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	TextControl,
} from '@wordpress/components';
import apiFetch from '../api';
import type {
	AuthorizeUrlResponse,
	Connection,
	TestConnectionResult,
} from '../types';

interface Props {
	connection: Connection;
	onChange: () => void;
}

type Busy =
	| 'save'
	| 'redirect'
	| 'oob'
	| 'manual'
	| 'test'
	| 'disconnect'
	| null;

function errorMessage( err: unknown ): string {
	return ( err as { message?: string } )?.message ?? String( err );
}

function testResultMessage( result: TestConnectionResult ): string {
	if ( ! result.ok ) {
		return (
			result.message ?? __( 'Connection test failed.', 'cart-bridge-jp' )
		);
	}

	if ( result.shop_name ) {
		return sprintf(
			/* translators: %s: shop name returned by the platform */
			__( 'Connected to %s.', 'cart-bridge-jp' ),
			result.shop_name
		);
	}

	return __( 'Connection OK.', 'cart-bridge-jp' );
}

export default function ConnectionCard( { connection, onChange }: Props ) {
	const textFields = connection.connection_fields.filter(
		( field ) => field.type !== 'oauth_button'
	);
	const hasOAuth = connection.connection_fields.some(
		( field ) => field.type === 'oauth_button'
	);

	const [ values, setValues ] = useState< Record< string, string > >( {} );
	const [ manualCode, setManualCode ] = useState( '' );
	const [ showManualEntry, setShowManualEntry ] = useState( false );
	const [ busy, setBusy ] = useState< Busy >( null );
	const [ notice, setNotice ] = useState< {
		status: 'success' | 'error';
		message: string;
	} | null >( null );
	const [ testResult, setTestResult ] =
		useState< TestConnectionResult | null >( null );

	async function saveSettings() {
		setBusy( 'save' );
		setNotice( null );
		try {
			await apiFetch( {
				path: `/cbjp/v1/connections/${ connection.platform }`,
				method: 'PUT',
				data: values,
			} );
			setNotice( {
				status: 'success',
				message: __( 'Settings saved.', 'cart-bridge-jp' ),
			} );
			// has_settings等のメタデータを再取得する。これを怠ると、初回保存後も
			// 親から渡るpropsが古いままで、OAuthを完了せず中断した場合に
			// 「Clear saved credentials」ボタンが出ず資格情報を削除できない。
			onChange();
		} catch ( err ) {
			setNotice( { status: 'error', message: errorMessage( err ) } );
		} finally {
			setBusy( null );
		}
	}

	async function connect( mode: 'redirect' | 'oob' ) {
		setBusy( mode );
		setNotice( null );
		// ポップアップブロッカー対策: window.open()はクリックのユーザー操作から
		// 同期的に呼ぶ必要がある。await後に呼ぶとブラウザによってはブロックされ、
		// window.open()がnullを返してもUIエラーが出ない。空ウィンドウを先に開いておき、
		// URL取得後にそこへ遷移させる。
		const authWindow = window.open( '', '_blank' );

		// reverse tabnabbing対策: authWindowはwindow.openerでこのタブ（wp-admin）への
		// 参照を保持したまま外部の認可ページへ遷移する。遷移先やそのリダイレクト先が
		// 侵害されていた場合、window.opener.location操作でこのタブをフィッシングページへ
		// 誘導されうる。about:blankのうちにopenerを切り離す（ポーリング用のハンドル自体は
		// 維持されるので、遷移先を信頼する前提を外しても閉鎖検知には影響しない）。
		if ( authWindow ) {
			authWindow.opener = null;
		}

		try {
			const response = await apiFetch< AuthorizeUrlResponse >( {
				path: `/cbjp/v1/connections/${
					connection.platform
				}/authorize-url${ 'oob' === mode ? '?mode=oob' : '' }`,
			} );

			if ( authWindow ) {
				authWindow.location.href = response.url;

				// 自動リダイレクトモードでは、認可完了後のコールバックは開いたポップアップ側で
				// 遷移するため、元のタブ（このコンポーネント）はポップアップが閉じるまで
				// 接続状態の変化を知る手段がない。ポーリングして閉じたら一覧を再取得する。
				if ( 'redirect' === mode ) {
					const poll = window.setInterval( () => {
						if ( authWindow.closed ) {
							window.clearInterval( poll );
							onChange();
						}
					}, 1000 );
				}
			} else {
				// 最初の空ポップアップも既にブロックされていた場合、await後のこの呼び出しは
				// クリックのユーザー操作から時間が経っておりブロックされる可能性が高い。
				// 戻り値を無視すると、ブロックされた場合にUIが何も反応せず止まって見える。
				const fallbackWindow = window.open(
					response.url,
					'_blank',
					'noopener,noreferrer'
				);

				if ( ! fallbackWindow ) {
					setNotice( {
						status: 'error',
						message: __(
							'Your browser blocked the authorization popup. Please allow popups for this site and try again.',
							'cart-bridge-jp'
						),
					} );
				}
			}

			if ( 'oob' === mode ) {
				setShowManualEntry( true );
			}
		} catch ( err ) {
			authWindow?.close();
			setNotice( { status: 'error', message: errorMessage( err ) } );
		} finally {
			setBusy( null );
		}
	}

	async function submitManualCode() {
		setBusy( 'manual' );
		setNotice( null );
		try {
			await apiFetch( {
				path: `/cbjp/v1/connections/${ connection.platform }/exchange-code`,
				method: 'POST',
				data: { code: manualCode },
			} );
			setManualCode( '' );
			setNotice( {
				status: 'success',
				message: __( 'Connected.', 'cart-bridge-jp' ),
			} );
			onChange();
		} catch ( err ) {
			setNotice( { status: 'error', message: errorMessage( err ) } );
		} finally {
			setBusy( null );
		}
	}

	async function testConnection() {
		setBusy( 'test' );
		setTestResult( null );
		try {
			const result = await apiFetch< TestConnectionResult >( {
				path: `/cbjp/v1/connections/${ connection.platform }/test`,
				method: 'POST',
			} );
			setTestResult( result );
		} catch ( err ) {
			setNotice( { status: 'error', message: errorMessage( err ) } );
		} finally {
			setBusy( null );
		}
	}

	async function disconnect() {
		setBusy( 'disconnect' );
		// 切断後に「Connected.」等の古い成功表示がヘッダーの「Not connected」と
		// 矛盾したまま残らないよう、ローカルのステータス表示を先にクリアする。
		setNotice( null );
		setTestResult( null );
		try {
			await apiFetch( {
				path: `/cbjp/v1/connections/${ connection.platform }`,
				method: 'DELETE',
			} );
			// このマウント中に入力したclient_id/secretがローカルstateに残っていると、
			// 削除後の次のSaveでそのまま再保存されてしまうため、入力値も破棄する。
			setValues( {} );
			setManualCode( '' );
			onChange();
		} catch ( err ) {
			setNotice( { status: 'error', message: errorMessage( err ) } );
		} finally {
			setBusy( null );
		}
	}

	return (
		<Card className="cbjp-connection-card">
			<CardHeader>
				<strong>{ connection.label }</strong>
				&nbsp;
				{ connection.connected
					? __( 'Connected', 'cart-bridge-jp' )
					: __( 'Not connected', 'cart-bridge-jp' ) }
			</CardHeader>
			<CardBody>
				{ connection.needs_reconnect && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Stored credentials could not be decrypted. Please reconnect.',
							'cart-bridge-jp'
						) }
					</Notice>
				) }

				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }

				{ connection.callback_url && (
					<TextControl
						label={ __( 'Callback URL', 'cart-bridge-jp' ) }
						help={ __(
							"Register this exact URL as the app's redirect URI in the platform's developer console before connecting.",
							'cart-bridge-jp'
						) }
						value={ connection.callback_url }
						readOnly
						onFocus={ ( event ) => event.target.select() }
						onChange={ () => {} }
					/>
				) }

				{ textFields.map( ( field ) => (
					<TextControl
						key={ field.key }
						label={ field.label }
						help={ field.help ?? undefined }
						type={ 'password' === field.type ? 'password' : 'text' }
						value={ values[ field.key ] ?? '' }
						onChange={ ( value ) =>
							setValues( ( prev ) => ( {
								...prev,
								[ field.key ]: value,
							} ) )
						}
					/>
				) ) }

				{ textFields.length > 0 && (
					<Button
						variant="secondary"
						isBusy={ 'save' === busy }
						disabled={ null !== busy }
						onClick={ saveSettings }
					>
						{ __( 'Save settings', 'cart-bridge-jp' ) }
					</Button>
				) }

				{ hasOAuth && (
					<div className="cbjp-connection-card__oauth">
						<Button
							variant="primary"
							isBusy={ 'redirect' === busy }
							disabled={ null !== busy }
							onClick={ () => connect( 'redirect' ) }
						>
							{ sprintf(
								/* translators: %s: platform label, e.g. "Color Me Shop" */
								__( 'Connect to %s', 'cart-bridge-jp' ),
								connection.label
							) }
						</Button>{ ' ' }
						<Button
							variant="tertiary"
							disabled={ null !== busy }
							onClick={ () =>
								setShowManualEntry( ( prev ) => ! prev )
							}
						>
							{ __(
								'Trouble connecting locally?',
								'cart-bridge-jp'
							) }
						</Button>
						{ showManualEntry && (
							<div className="cbjp-connection-card__manual">
								<p>
									{ __(
										'For local development environments where the platform cannot redirect back to this site, authorize manually and paste the resulting code (or the full authorization URL) below.',
										'cart-bridge-jp'
									) }
								</p>
								<p>
									{ __(
										"The platform requires the authorization request's redirect URI to match the one registered for the app. Before using this manual link, change the app's registered redirect URI from the callback URL above to urn:ietf:wg:oauth:2.0:oob in the platform's developer console.",
										'cart-bridge-jp'
									) }
								</p>
								<Button
									variant="secondary"
									isBusy={ 'oob' === busy }
									disabled={ null !== busy }
									onClick={ () => connect( 'oob' ) }
								>
									{ __(
										'Get manual authorization link',
										'cart-bridge-jp'
									) }
								</Button>
								<TextControl
									label={ __(
										'Authorization code (or URL)',
										'cart-bridge-jp'
									) }
									value={ manualCode }
									onChange={ setManualCode }
								/>
								<Button
									variant="secondary"
									isBusy={ 'manual' === busy }
									disabled={
										null !== busy || '' === manualCode
									}
									onClick={ submitManualCode }
								>
									{ __( 'Submit code', 'cart-bridge-jp' ) }
								</Button>
							</div>
						) }
					</div>
				) }

				<div className="cbjp-connection-card__actions">
					<Button
						variant="secondary"
						isBusy={ 'test' === busy }
						disabled={ null !== busy }
						onClick={ testConnection }
					>
						{ __( 'Test connection', 'cart-bridge-jp' ) }
					</Button>{ ' ' }
					{ /* 接続済みだけでなく、OAuth完了前にclient_id/secretだけを保存した
					     状態（has_settings）や復号不能なトークンが残る状態
					     （needs_reconnect）でも、保存済み資格情報を削除できるようにする。 */ }
					{ ( connection.connected ||
						connection.needs_reconnect ||
						connection.has_settings ) && (
						<Button
							variant="tertiary"
							isDestructive
							isBusy={ 'disconnect' === busy }
							disabled={ null !== busy }
							onClick={ disconnect }
						>
							{ connection.connected
								? __( 'Disconnect', 'cart-bridge-jp' )
								: __(
										'Clear saved credentials',
										'cart-bridge-jp'
								  ) }
						</Button>
					) }
				</div>

				{ testResult && (
					<Notice
						status={ testResult.ok ? 'success' : 'error' }
						onRemove={ () => setTestResult( null ) }
					>
						{ testResultMessage( testResult ) }
					</Notice>
				) }
			</CardBody>
		</Card>
	);
}
