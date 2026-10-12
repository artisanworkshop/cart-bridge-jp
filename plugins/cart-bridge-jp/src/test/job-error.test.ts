import { describe, expect, it } from '@jest/globals';
import { ERROR_ACTION_LOST, ERROR_FATAL, jobErrorMessage } from '../job-error';

describe( 'jobErrorMessage', () => {
	it( 'explains a job whose background action was lost instead of the stored English text', () => {
		const text = jobErrorMessage( {
			code: ERROR_ACTION_LOST,
			message: 'stored text',
		} );

		expect( text ).toContain( 'background task' );
		expect( text ).toContain( 'Retry' );
		expect( text ).not.toContain( 'stored text' );
	} );

	it( 'puts the PHP error of a fatal error into the explanation', () => {
		const text = jobErrorMessage( {
			code: ERROR_FATAL,
			message: 'Allowed memory size of 134217728 bytes exhausted',
		} );

		expect( text ).toContain( 'fatal error' );
		expect( text ).toContain(
			'Allowed memory size of 134217728 bytes exhausted'
		);
	} );

	it( 'explains a fatal error without a readable PHP error', () => {
		for ( const message of [ '', 42, null ] ) {
			const text = jobErrorMessage( { code: ERROR_FATAL, message } );

			expect( text ).toContain( 'fatal error' );
			expect( text ).not.toContain( 'PHP error:' );
		}
	} );

	it( 'shows the message of other codes as it is', () => {
		expect(
			jobErrorMessage( { code: 'exception', message: 'HTTP 500' } )
		).toBe( 'HTTP 500' );
	} );

	it( 'falls back to a generic sentence for values it cannot read', () => {
		for ( const error of [
			null,
			undefined,
			'boom',
			[ 'action_lost' ],
			{ code: 'exception' },
			{ code: 1, message: 2 },
		] ) {
			expect( jobErrorMessage( error ) ).toBe( 'This job failed.' );
		}
	} );
} );
