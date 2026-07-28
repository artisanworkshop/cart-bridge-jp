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
		} catch ( err ) {
			setNotice( { status: 'error', message: errorMessage( err ) } );
		} finally {
			setBusy( null );
		}
	}

	async function connect( mode: 'redirect' | 'oob' ) {
		setBusy( mode );
		setNotice( null );
		try {
			const response = await apiFetch< AuthorizeUrlResponse >( {
				path: `/cbjp/v1/connections/${
					connection.platform
				}/authorize-url${ 'oob' === mode ? '?mode=oob' : '' }`,
			} );
			window.open( response.url, '_blank', 'noopener,noreferrer' );

			if ( 'oob' === mode ) {
				setShowManualEntry( true );
			}
		} catch ( err ) {
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
		try {
			await apiFetch( {
				path: `/cbjp/v1/connections/${ connection.platform }`,
				method: 'DELETE',
			} );
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
					{ connection.connected && (
						<Button
							variant="tertiary"
							isDestructive
							isBusy={ 'disconnect' === busy }
							disabled={ null !== busy }
							onClick={ disconnect }
						>
							{ __( 'Disconnect', 'cart-bridge-jp' ) }
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
