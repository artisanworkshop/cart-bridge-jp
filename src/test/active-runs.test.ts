import { describe, expect, it } from '@jest/globals';
import {
	activeRunsFromError,
	decideAdoption,
	foreignRuns,
	parseActiveRuns,
	runTab,
	untrackedRuns,
	type RunSectionSnapshot,
} from '../active-runs';
import type { ActiveRun } from '../types';

function activeRun( overrides: Partial< ActiveRun > = {} ): ActiveRun {
	return {
		run_id: 'run-a',
		type: 'dry_run',
		status: 'running',
		entities: [ 'product' ],
		has_failed_job: false,
		created_at: '2026-10-02 06:00:00',
		updated_at: '2026-10-02 06:01:00',
		...overrides,
	};
}

describe( 'parseActiveRuns', () => {
	it( 'reads the runs the server returns', () => {
		expect(
			parseActiveRuns( [
				{
					run_id: '0b6f7c1e-1d2a-4c3b-9e8f-0a1b2c3d4e5f',
					type: 'import',
					status: 'paused',
					entities: [ 'product', 'customer' ],
					has_failed_job: false,
					created_at: '2026-10-02 06:00:00',
					updated_at: '2026-10-02 06:05:00',
				},
			] )
		).toEqual( [
			{
				run_id: '0b6f7c1e-1d2a-4c3b-9e8f-0a1b2c3d4e5f',
				type: 'import',
				status: 'paused',
				entities: [ 'product', 'customer' ],
				has_failed_job: false,
				created_at: '2026-10-02 06:00:00',
				updated_at: '2026-10-02 06:05:00',
			},
		] );
	} );

	it( 'returns nothing for a value that is not a list', () => {
		expect( parseActiveRuns( undefined ) ).toEqual( [] );
		expect( parseActiveRuns( null ) ).toEqual( [] );
		expect( parseActiveRuns( { run_id: 'run-a' } ) ).toEqual( [] );
	} );

	it( 'drops items without a usable run id', () => {
		expect(
			parseActiveRuns( [
				null,
				'run-a',
				[ 'run-a' ],
				{ type: 'import' },
				{ run_id: 42 },
				{ run_id: '' },
				// パスに埋め込むため、ルートの `[a-zA-Z0-9-]+` 以外の文字を含む ID は捨てる。
				{ run_id: '../logs' },
				{ run_id: 'run-a\n' },
			] )
		).toEqual( [] );
	} );

	it( 'keeps runs whose type or status it cannot interpret, marked as unknown', () => {
		const [ run ] = parseActiveRuns( [
			{ run_id: 'run-x', type: 'migrate', status: 'stuck' },
		] );

		expect( run.type ).toBeNull();
		expect( run.status ).toBe( 'unknown' );
		expect( run.entities ).toEqual( [] );
		expect( run.created_at ).toBe( '' );
	} );

	it( 'keeps only known entities and treats only true as a failed job', () => {
		const [ run ] = parseActiveRuns( [
			{
				run_id: 'run-a',
				type: 'dry_run',
				status: 'pending',
				entities: [ 'order', 'nope', 3, 'product' ],
				has_failed_job: 'true',
			},
		] );

		expect( run.entities ).toEqual( [ 'product', 'order' ] );
		expect( run.has_failed_job ).toBe( false );
	} );
} );

describe( 'activeRunsFromError', () => {
	it( 'reads active_runs from a run-in-progress error', () => {
		expect(
			activeRunsFromError( {
				code: 'cbjp_run_in_progress',
				message: 'A run is already in progress for this platform.',
				data: {
					status: 409,
					active_runs: [ { run_id: 'run-b', type: 'export' } ],
				},
			} )?.map( ( run ) => run.run_id )
		).toEqual( [ 'run-b' ] );
	} );

	it( 'returns an empty list when the error carries no usable list', () => {
		expect(
			activeRunsFromError( {
				code: 'cbjp_run_in_progress',
				data: { status: 409 },
			} )
		).toEqual( [] );
		expect(
			activeRunsFromError( { code: 'cbjp_run_in_progress' } )
		).toEqual( [] );
	} );

	it( 'ignores other errors', () => {
		expect(
			activeRunsFromError( {
				code: 'cbjp_invalid_run',
				data: { active_runs: [ { run_id: 'run-b' } ] },
			} )
		).toBeNull();
		expect( activeRunsFromError( new Error( 'network' ) ) ).toBeNull();
		expect( activeRunsFromError( null ) ).toBeNull();
		expect( activeRunsFromError( 'cbjp_run_in_progress' ) ).toBeNull();
	} );
} );

describe( 'runTab', () => {
	it( 'maps each run type to the tab that shows it', () => {
		expect( runTab( 'dry_run' ) ).toBe( 'import' );
		expect( runTab( 'import' ) ).toBe( 'import' );
		expect( runTab( 'dry_run_export' ) ).toBe( 'export' );
		expect( runTab( 'export' ) ).toBe( 'export' );
		expect( runTab( null ) ).toBeNull();
	} );
} );

describe( 'untrackedRuns / foreignRuns', () => {
	const runs = [
		activeRun( { run_id: 'run-dry', type: 'dry_run' } ),
		activeRun( { run_id: 'run-export', type: 'export' } ),
		activeRun( { run_id: 'run-unknown', type: null } ),
	];

	it( 'leaves out the runs a section already shows', () => {
		expect(
			untrackedRuns( runs, [ 'run-dry', null ] ).map( ( r ) => r.run_id )
		).toEqual( [ 'run-export', 'run-unknown' ] );
	} );

	it( 'treats runs of another tab and of an unknown type as foreign', () => {
		expect(
			foreignRuns( runs, 'import' ).map( ( r ) => r.run_id )
		).toEqual( [ 'run-export', 'run-unknown' ] );
		expect(
			foreignRuns( runs, 'export' ).map( ( r ) => r.run_id )
		).toEqual( [ 'run-dry', 'run-unknown' ] );
		// Tools・Mappings タブは run を表示しないので、すべてが案内の対象。
		expect( foreignRuns( runs, null ) ).toHaveLength( 3 );
	} );
} );

describe( 'decideAdoption', () => {
	const idle: RunSectionSnapshot = {
		runId: null,
		busy: false,
		loaded: false,
		terminal: false,
		notFound: false,
	};
	const older = activeRun( { run_id: 'run-older' } );
	const newer = activeRun( { run_id: 'run-newer' } );

	it( 'adopts the oldest run into an empty section', () => {
		expect( decideAdoption( idle, [ older, newer ], false ) ).toEqual( {
			kind: 'adopt',
			run: older,
		} );
	} );

	it( 'does nothing while the list is being fetched again', () => {
		expect( decideAdoption( idle, [ older ], true ) ).toEqual( {
			kind: 'none',
		} );
	} );

	it( 'does nothing while the section waits for its own request', () => {
		expect(
			decideAdoption( { ...idle, busy: true }, [ older ], false )
		).toEqual( { kind: 'none' } );
	} );

	it( 'does nothing when there is no run of this type', () => {
		expect( decideAdoption( idle, [], false ) ).toEqual( {
			kind: 'none',
		} );
	} );

	it( 'replaces a finished run with the active one', () => {
		expect(
			decideAdoption(
				{ ...idle, runId: 'run-old', loaded: true, terminal: true },
				[ older ],
				false
			)
		).toEqual( { kind: 'adopt', run: older } );
	} );

	it( 'replaces a run the server no longer knows', () => {
		expect(
			decideAdoption(
				{ ...idle, runId: 'run-gone', notFound: true },
				[ older ],
				false
			)
		).toEqual( { kind: 'adopt', run: older } );
	} );

	it( 'waits until the shown run has loaded', () => {
		expect(
			decideAdoption( { ...idle, runId: 'run-old' }, [ older ], false )
		).toEqual( { kind: 'none' } );
		// 終了かどうかは読み込めた run についてだけ信じる（読み込み前の値で置き換えない）。
		expect(
			decideAdoption(
				{ ...idle, runId: 'run-old', loaded: false, terminal: true },
				[ older ],
				false
			)
		).toEqual( { kind: 'none' } );
	} );

	it( 'never replaces a run that is still active', () => {
		expect(
			decideAdoption(
				{ ...idle, runId: 'run-mine', loaded: true, terminal: false },
				[ older ],
				false
			)
		).toEqual( { kind: 'none' } );
	} );

	it( 'leaves a run it already shows alone', () => {
		expect(
			decideAdoption(
				{ ...idle, runId: 'run-older', loaded: true, terminal: false },
				[ older ],
				false
			)
		).toEqual( { kind: 'none' } );
		expect(
			decideAdoption( { ...idle, runId: 'run-older' }, [ older ], false )
		).toEqual( { kind: 'none' } );
	} );

	it( 'reconciles when the shown run looks finished but is listed as active', () => {
		expect(
			decideAdoption(
				{ ...idle, runId: 'run-older', loaded: true, terminal: true },
				[ older ],
				false
			)
		).toEqual( { kind: 'reconcile' } );
	} );
} );
