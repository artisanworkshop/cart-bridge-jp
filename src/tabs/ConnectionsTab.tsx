import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import apiFetch from '../api';
import ConnectionCard from '../components/ConnectionCard';
import type { Connection } from '../types';

/**
 * OAuthコールバック（`/connect/{platform}/callback`）は完了後、このタブへ
 * `?cbjp_connected=` / `?cbjp_connect_error=` を付けてリダイレクトしてくる。
 */
function readAndClearOAuthStatus(): {
	status: 'success' | 'error';
	message: string;
} | null {
	const params = new URLSearchParams( window.location.search );
	const connected = params.get( 'cbjp_connected' );
	const error = params.get( 'cbjp_connect_error' );

	if ( ! connected && ! error ) {
		return null;
	}

	params.delete( 'cbjp_connected' );
	params.delete( 'cbjp_connect_error' );
	const query = params.toString();
	const newUrl =
		window.location.pathname +
		( query ? `?${ query }` : '' ) +
		window.location.hash;
	window.history.replaceState( {}, '', newUrl );

	if ( error ) {
		return { status: 'error', message: error };
	}

	return {
		status: 'success',
		message: sprintf(
			/* translators: %s: platform id, e.g. "colorme" */
			__( 'Connected to %s.', 'cart-bridge-jp' ),
			connected as string
		),
	};
}

export default function ConnectionsTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );
	const [ oauthNotice, setOauthNotice ] = useState( () =>
		readAndClearOAuthStatus()
	);

	const loadConnections = useCallback( () => {
		return apiFetch< Connection[] >( { path: '/cbjp/v1/connections' } )
			.then( ( data ) => {
				setConnections( data );
				setError( null );
			} )
			.catch( ( err: { message?: string } ) => {
				setError( err?.message ?? String( err ) );
			} );
	}, [] );

	useEffect( () => {
		void loadConnections();
	}, [ loadConnections ] );

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}

	if ( null === connections ) {
		return <Spinner />;
	}

	return (
		<div className="cbjp-connections">
			{ oauthNotice && (
				<Notice
					status={ oauthNotice.status }
					onRemove={ () => setOauthNotice( null ) }
				>
					{ oauthNotice.message }
				</Notice>
			) }

			{ 0 === connections.length && (
				<p>
					{ __(
						'No platform adapters are registered yet. Adapters ship starting in Phase 1.',
						'cart-bridge-jp'
					) }
				</p>
			) }

			{ connections.map( ( connection ) => (
				<ConnectionCard
					key={ connection.platform }
					connection={ connection }
					onChange={ loadConnections }
				/>
			) ) }
		</div>
	);
}
