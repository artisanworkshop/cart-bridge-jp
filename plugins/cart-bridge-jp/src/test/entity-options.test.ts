import { afterEach, describe, expect, it } from '@jest/globals';
import { entityLabel, entityLabels } from '../entity-labels';
import {
	defaultExportSelection,
	exportEntityOptions,
	exportOptionHelp,
	importEntityOptions,
} from '../entity-options';
import type { Connection } from '../types';

function connection( entities: unknown ): Connection {
	return { entities } as unknown as Connection;
}

describe( 'importEntityOptions', () => {
	it( 'keeps the server order and reads the notice flag', () => {
		expect(
			importEntityOptions(
				connection( {
					import: [
						{
							key: 'category',
							label: 'Categories',
							mapping_notice: false,
						},
						{ key: 'gizmo', label: 'Gizmos', mapping_notice: true },
						{ key: 'order', label: 'Orders', mapping_notice: true },
					],
					export: [],
				} )
			)
		).toEqual( [
			{ key: 'category', label: 'Categories', mapping_notice: false },
			{ key: 'gizmo', label: 'Gizmos', mapping_notice: true },
			{ key: 'order', label: 'Orders', mapping_notice: true },
		] );
	} );

	it( 'drops malformed and duplicate options and reads only true as true', () => {
		expect(
			importEntityOptions(
				connection( {
					import: [
						null,
						{ label: 'No key' },
						{ key: '' },
						{ key: 5 },
						{ key: 'tag', mapping_notice: 'true' },
						{ key: 'tag', label: 'Tags again' },
					],
				} )
			)
		).toEqual( [ { key: 'tag', label: 'tag', mapping_notice: false } ] );
	} );

	it( 'returns nothing for a missing connection or declaration', () => {
		expect( importEntityOptions( null ) ).toEqual( [] );
		expect( importEntityOptions( connection( undefined ) ) ).toEqual( [] );
		expect( importEntityOptions( connection( { import: 'x' } ) ) ).toEqual(
			[]
		);
	} );
} );

describe( 'exportEntityOptions', () => {
	it( 'reads beta and the description, and treats an unreadable beta as beta', () => {
		const options = exportEntityOptions(
			connection( {
				export: [
					{
						key: 'product',
						label: 'Products',
						beta: false,
						description: '',
					},
					{
						key: 'order',
						label: 'Orders',
						beta: true,
						description: 'Creates orders.',
					},
					// 読めない `beta` は既定で選ばない側に倒す（原則 9）。
					{ key: 'gizmo', label: 'Gizmos', description: 7 },
				],
			} )
		);

		expect( options ).toEqual( [
			{ key: 'product', label: 'Products', beta: false, description: '' },
			{
				key: 'order',
				label: 'Orders',
				beta: true,
				description: 'Creates orders.',
			},
			{ key: 'gizmo', label: 'Gizmos', beta: true, description: '' },
		] );
		expect( defaultExportSelection( options ) ).toEqual( [ 'product' ] );
	} );
} );

describe( 'exportOptionHelp', () => {
	const option = {
		key: 'order',
		label: 'Orders',
		beta: false,
		description: '',
	};

	it( 'shows the description, followed by the beta note for a beta option', () => {
		expect(
			exportOptionHelp(
				{ ...option, beta: true, description: 'Creates orders.' },
				'Beta note.'
			)
		).toBe( 'Creates orders. Beta note.' );
		expect(
			exportOptionHelp( { ...option, beta: true }, 'Beta note.' )
		).toBe( 'Beta note.' );
		// ベータでない種類も、説明があれば出す（受注のエクスポートをベータにしない接続先。R3-6b2 R1-L1）。
		expect(
			exportOptionHelp(
				{ ...option, description: 'Creates orders.' },
				'Beta note.'
			)
		).toBe( 'Creates orders.' );
	} );

	it( 'shows nothing for a non-beta option without a description', () => {
		expect( exportOptionHelp( option, 'Beta note.' ) ).toBeUndefined();
	} );
} );

describe( 'entityLabels', () => {
	afterEach( () => {
		delete window.cbjpAdmin;
	} );

	it( 'reads the labels the server passed and falls back to the key', () => {
		window.cbjpAdmin = {
			restUrl: '',
			restNonce: '',
			entityLabels: {
				product: 'Products',
				variant: 'Variations',
				bad: 3,
			},
		};

		expect( entityLabel( 'product' ) ).toBe( 'Products' );
		expect( entityLabel( 'variant' ) ).toBe( 'Variations' );
		expect( entityLabel( 'bad' ) ).toBe( 'bad' );
		expect( entityLabel( 'gizmo' ) ).toBe( 'gizmo' );
		// 継承プロパティを名前として拾わない。
		expect( entityLabel( 'constructor' ) ).toBe( 'constructor' );
	} );

	it( 'survives a missing or malformed value', () => {
		expect( entityLabels() ).toEqual( {} );

		window.cbjpAdmin = {
			restUrl: '',
			restNonce: '',
			entityLabels: [ 'x' ],
		};
		expect( entityLabel( '0' ) ).toBe( '0' );
	} );
} );
