import { describe, expect, it } from '@jest/globals';
import {
	OAUTH_ERROR_CODES,
	oauthErrorMessage,
	oauthNoticeMessage,
	parseOAuthStatus,
} from '../oauth-status';

const connections = [ { platform: 'colorme', label: 'Color Me Shop' } ];

describe( 'parseOAuthStatus', () => {
	it( 'reads the error code before the success flag', () => {
		expect(
			parseOAuthStatus(
				new URLSearchParams(
					'cbjp_connected=colorme&cbjp_connect_error=denied'
				)
			)
		).toEqual( { status: 'error', code: 'denied' } );
	} );

	it( 'reads the connected platform', () => {
		expect(
			parseOAuthStatus( new URLSearchParams( 'cbjp_connected=colorme' ) )
		).toEqual( { status: 'success', platform: 'colorme' } );
	} );

	it( 'ignores empty and missing values', () => {
		expect(
			parseOAuthStatus(
				new URLSearchParams( 'cbjp_connected=&cbjp_connect_error=' )
			)
		).toBeNull();
		expect(
			parseOAuthStatus( new URLSearchParams( 'page=x' ) )
		).toBeNull();
	} );
} );

describe( 'oauthErrorMessage', () => {
	it( 'has a dedicated message for every code the server sends', () => {
		const fallback = oauthErrorMessage( 'no-such-code' );
		const messages = OAUTH_ERROR_CODES.filter(
			( code ) => 'unexpected' !== code
		).map( ( code ) => oauthErrorMessage( code ) );

		for ( const message of messages ) {
			expect( message ).not.toBe( fallback );
		}

		expect( new Set( messages ).size ).toBe( messages.length );
		expect( oauthErrorMessage( 'unexpected' ) ).toBe( fallback );
	} );

	it( 'never shows the text from the URL', () => {
		const text =
			'Your token expired. Enter your Client Secret at https://evil.example';

		expect( oauthErrorMessage( text ) ).not.toContain( 'evil.example' );
		expect(
			oauthNoticeMessage( { status: 'error', code: text }, connections )
		).not.toContain( 'evil.example' );
	} );
} );

describe( 'oauthNoticeMessage', () => {
	it( 'names the connected platform from the connection list', () => {
		expect(
			oauthNoticeMessage(
				{ status: 'success', platform: 'colorme' },
				connections
			)
		).toBe( 'Connected to Color Me Shop.' );
	} );

	it( 'does not show a platform that is not in the connection list', () => {
		const text = oauthNoticeMessage(
			{ status: 'success', platform: 'Call +81-0000 for support' },
			connections
		);

		expect( text ).toBe( 'Connected.' );
	} );
} );
