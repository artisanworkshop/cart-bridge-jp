import { describe, expect, it } from '@jest/globals';
import { isCleanupBlocked } from '../cleanup-gate';

const clean = {
	run_in_progress: false,
	requires_delete_users: false,
	can_delete_users: true,
};

describe( 'isCleanupBlocked', () => {
	it( 'allows a cleanup from a preview taken while nothing ran', () => {
		expect( isCleanupBlocked( clean, false, false ) ).toBe( false );
	} );

	it( 'has nothing to block before a preview exists', () => {
		expect( isCleanupBlocked( null, true, true ) ).toBe( false );
	} );

	it( 'blocks a preview taken while a run was in progress, even after the run ended', () => {
		// run の途中の件数は過少で、件数 0 なら確認ダイアログも出ずに run が書いたデータを消してしまう（R1-1）。
		expect(
			isCleanupBlocked(
				{ ...clean, run_in_progress: true },
				false,
				false
			)
		).toBe( true );
	} );

	it( 'blocks while a run is in progress now', () => {
		expect( isCleanupBlocked( clean, true, false ) ).toBe( true );
	} );

	it( 'blocks a preview a run has made out of date', () => {
		expect( isCleanupBlocked( clean, false, true ) ).toBe( true );
	} );

	it( 'blocks when the user may not delete the imported accounts', () => {
		expect(
			isCleanupBlocked(
				{
					...clean,
					requires_delete_users: true,
					can_delete_users: false,
				},
				false,
				false
			)
		).toBe( true );
		expect(
			isCleanupBlocked(
				{
					...clean,
					requires_delete_users: true,
					can_delete_users: true,
				},
				false,
				false
			)
		).toBe( false );
	} );
} );
