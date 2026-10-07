import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import apiFetch from '../api';
import ConnectionCard from '../components/ConnectionCard';
import type { Connection } from '../types';

type OAuthStatus =
	| { status: 'success'; platform: string }
	| { status: 'error'; message: string };

/**
 * OAuthコールバック（`/connect/{platform}/callback`）は完了後、このタブへ
 * `?cbjp_connected=` / `?cbjp_connect_error=` を付けてリダイレクトしてくる。
 * `cbjp_connected` はプラットフォームの ID（`colorme`）なので、表示名は接続一覧を読んでから引く（`oauthNoticeMessage()`）。
 */
function readAndClearOAuthStatus(): OAuthStatus | null {
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

	return { status: 'success', platform: connected as string };
}

function oauthNoticeMessage(
	notice: OAuthStatus,
	connections: Connection[]
): string {
	if ( 'error' === notice.status ) {
		return notice.message;
	}

	const label =
		connections.find(
			( connection ) => connection.platform === notice.platform
		)?.label ?? notice.platform;

	return sprintf(
		/* translators: %s: the name of the shop, or of the platform (e.g. "Color Me Shop") */
		__( 'Connected to %s.', 'cart-bridge-jp' ),
		label
	);
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
