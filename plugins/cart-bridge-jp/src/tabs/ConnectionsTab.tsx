import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import apiFetch from '../api';
import ConnectionCard from '../components/ConnectionCard';
import { oauthNoticeMessage, parseOAuthStatus } from '../oauth-status';
import type { OAuthStatus } from '../oauth-status';
import type { Connection } from '../types';

/**
 * OAuthコールバック（`/connect/{platform}/callback`）は完了後、このタブへ
 * `?cbjp_connected=` / `?cbjp_connect_error=` を付けてリダイレクトしてくる。読んだら URL から消す
 * （再読込で同じ通知を出さない）。文にするのは `oauth-status.ts`（URL の文字は出さない。R3-5b）。
 */
function readAndClearOAuthStatus(): OAuthStatus | null {
	const params = new URLSearchParams( window.location.search );
	const status = parseOAuthStatus( params );

	if ( null === status ) {
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

	return status;
}

export default function ConnectionsTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );
	const [ oauthNotice, setOauthNotice ] = useState( () =>
		readAndClearOAuthStatus()
	);

	// 保存直後の再読込と切断直後の再読込が重なると、後から届いた古い方の
	// レスポンスが新しい状態を上書きしてしまう。最後に発行したリクエストの
	// 結果だけを反映するよう、リクエストごとに世代番号を振って比較する。
	const latestRequestId = useRef( 0 );

	const loadConnections = useCallback( () => {
		const requestId = ++latestRequestId.current;

		return apiFetch< Connection[] >( { path: '/cbjp/v1/connections' } )
			.then( ( data ) => {
				if ( requestId !== latestRequestId.current ) {
					return;
				}

				setConnections( data );
				setError( null );
			} )
			.catch( ( err: { message?: string } ) => {
				if ( requestId !== latestRequestId.current ) {
					return;
				}

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
					{ oauthNoticeMessage( oauthNotice, connections ) }
				</Notice>
			) }

			{ 0 === connections.length && (
				<p>{ __( 'No platforms are available.', 'cart-bridge-jp' ) }</p>
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
