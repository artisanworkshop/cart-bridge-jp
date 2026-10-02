import { describe, expect, it } from '@jest/globals';
import { runStorageKey } from '../run-storage';

describe( 'runStorageKey', () => {
	it( 'keeps the key format the Import and Export tabs already stored run ids under', () => {
		expect( runStorageKey( 'colorme', 'dry_run' ) ).toBe(
			'cbjp_run_dry_run_colorme'
		);
		expect( runStorageKey( 'colorme', 'import' ) ).toBe(
			'cbjp_run_import_colorme'
		);
		expect( runStorageKey( 'colorme', 'dry_run_export' ) ).toBe(
			'cbjp_run_dry_run_export_colorme'
		);
		expect( runStorageKey( 'colorme', 'export' ) ).toBe(
			'cbjp_run_export_colorme'
		);
	} );
} );
