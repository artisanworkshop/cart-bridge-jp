import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Spinner } from '@wordpress/components';
import apiFetch from '../api';
import type { Connection } from '../types';

export default function ConnectionsTab() {
	const [ connections, setConnections ] = useState< Connection[] | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		let cancelled = false;

		apiFetch< Connection[] >( { path: '/cbjp/v1/connections' } )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setConnections( data );
				}
			} )
			.catch( ( err: { message?: string } ) => {
				if ( ! cancelled ) {
					setError( err?.message ?? String( err ) );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

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

	if ( 0 === connections.length ) {
		return (
			<p>
				{ __(
					'No platform adapters are registered yet. Adapters ship starting in Phase 1.',
					'cart-bridge-jp'
				) }
			</p>
		);
	}

	return (
		<ul className="cbjp-connections">
			{ connections.map( ( connection ) => (
				<li key={ connection.platform }>
					<strong>{ connection.label }</strong>{ ' ' }
					{ connection.connected
						? __( 'Connected', 'cart-bridge-jp' )
						: __( 'Not connected', 'cart-bridge-jp' ) }
				</li>
			) ) }
		</ul>
	);
}
